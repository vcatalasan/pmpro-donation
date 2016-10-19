<?php
/*
Plugin Name: PMPro Donation
Plugin URI: http://www.bscmanage.com/my-plugin/pmpro-donation
Description: Add donation as an option on membership level checkout process
Version: 1.0.0
License: MPL
Author: Val Catalasan
*/
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class PMPro_Donation
{
    private static $instance = null;

	// required plugins to used in this application
	var $required_plugins = array(
		'Paid Memberships Pro' => 'paid-memberships-pro/paid-memberships-pro.php'
	);

    /**
     * Return an instance of this class.
     *
     * @since     1.0.0
     *
     * @return    object    A single instance of this class.
     */
    public static function get_instance()
    {
        // If the single instance hasn't been set, set it now.
        if (null == self::$instance) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    function __construct()
    {
	    if (!$this->required_plugins_active()) return;

        global $table_prefix, $wpdb;
        $wpdb->pmpro_membership_orders_meta = $table_prefix . 'pmpro_membership_orders_meta';

        add_action("pmpro_membership_level_after_other_settings", array($this, "pmpro_membership_level_after_other_settings"));
        add_action("pmpro_save_membership_level", array($this, "pmpro_save_donation_option"));
        add_filter("pmpro_level_cost_text", array($this, "pmpro_level_cost_text"), 10, 2);
        add_action('pmpro_checkout_after_level_cost', array($this, 'pmpro_checkout_after_level_cost'));
        add_filter("pmpro_checkout_level", array($this, "pmpro_checkout_level"));
        add_filter("pmpro_registration_checks", array($this, "pmpro_registration_checks"));
        add_filter('pmpro_checkout_order', array($this, 'pmpro_checkout_order'));
        add_action('pmpro_added_order', array($this, 'pmpro_added_order'));
        add_action('pmpro_invoice_bullets_bottom', array($this, 'pmpro_invoice_bullets_bottom'));
    }

	function required_plugins_active()
	{
		$status = true;
		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		foreach ($this->required_plugins as $name => $plugin) {
			if (is_plugin_active($plugin)) continue;
			?>
			<div class="error">
				<p>PMPro Donation plugin requires <strong><?php echo $name ?></strong> plugin to be installed and activated</p>
			</div>
			<?php
			$status = false;
		}
		return $status;
	}

	/*
		Min Amount and Max Amount Fields on the edit levels page
	*/
    //fields on edit page
    function pmpro_membership_level_after_other_settings()
    {
        global $pmpro_currency_symbol;
        $level_id = intval($_REQUEST['edit']);
        $fields = self::pmpro_get_donation_option($level_id);
        $donation = $fields['donation'];
        $min_amount = $fields['min_amount'];
        $max_amount = $fields['max_amount'];
        ?>
        <h3 class="topborder">Donation</h3>
        <p>If donation is enabled, the donation amount will be added to the billing amount values you set on this level.</p>
        <table>
            <tbody class="form-table">
            <tr>
                <th scope="row" valign="top"><label for="level_cost_text">Enable Donation:</label></th>
                <td>
                    <input type="checkbox" name="donation[new]" value="1" <?php checked($donation['new'], "1");?> /> New Membership<br/>
                    <input type="checkbox" name="donation[renewal]" value="1" <?php checked($donation['renewal'], "1");?> /> Membership Renewal<br />
                    <input type="checkbox" name="donation[checked]" value="1" <?php checked($donation['checked'], "1");?> /> Checked By Default (Opt-In)
                </td>
            </tr>
            <tr>
                <th scope="row" valign="top"><label for="level_cost_text">Min Amount:</label></th>
                <td>
                    <?php echo $pmpro_currency_symbol?><input type="text" name="min_amount"
                                                              value="<?php echo esc_attr($min_amount); ?>"/>
                </td>
            </tr>
            <tr>
                <th scope="row" valign="top"><label for="level_cost_text">Max Amount:</label></th>
                <td>
                    <?php echo $pmpro_currency_symbol?><input type="text" name="max_amount"
                                                              value="<?php echo esc_attr($max_amount); ?>"/>
                </td>
            </tr>
            </tbody>
        </table>
    <?php
    }

    //save level cost text when the level is saved/added
    function pmpro_save_donation_option($level_id)
    {
        $donation = (array) $_REQUEST['donation'];
        $min_amount = preg_replace("[^0-9\.]", "", $_REQUEST['min_amount']);
        $max_amount = preg_replace("[^0-9\.]", "", $_REQUEST['max_amount']);

        update_option("pmpro_donation_${level_id}", array('donation' => $donation, 'min_amount' => $min_amount, 'max_amount' => $max_amount));
    }

    function pmpro_get_donation_option($level_id)
    {
        $fields = get_option("pmpro_donation_${level_id}", array('donation' => array(), 'min_amount' => '', 'max_amount' => ''));
        return $fields;
    }

    /*
        Show form at checkout.
    */
    //override level cost text on checkout page
    function pmpro_level_cost_text($text, $level)
    {
        global $pmpro_pages;
        if (is_page($pmpro_pages['checkout'])) {
            $fields = self::pmpro_get_donation_option($level->id);
            if (!empty($fields['donation'])) {
                $text = "";
            }
        }

        return $text;
    }

    //show form
    function pmpro_checkout_after_level_cost()
    {
        global $current_user, $pmpro_currency_symbol, $pmpro_level, $gateway, $pmpro_msgt;

        $membership_amount = $pmpro_level->initial_payment;

        //get variable pricing info
        $fields = self::pmpro_get_donation_option($pmpro_level->id);

        $donation = $fields['donation'];

        //no donation? just return
        if (empty($donation))
            return;

        //check if donation is enabled on new membership or renewal
        $new_membership = $this->is_new_membership($current_user->ID);
        $enable = $new_membership && $donation['new'] ? true : (!$new_membership && $donation['renewal'] ? true : false);

        //not enable, just return
        if (!$enable)
            return;

        $donation_amount = floatval($_REQUEST['donation']);
        isset($_REQUEST['donation']) and $donation['checked'] = $donation_amount > 0;

        //okay, now we're showing the form
        $min_amount = $fields['min_amount'];
        $max_amount = $fields['max_amount'];
        $max_amount < $min_amount and $max_amount = $min_amount;
        $donation_amount >= $min_amount and $max_amount = $donation_amount;

        $variable_amount = $min_amount != $max_amount;

        $amount = $min_amount;
        ?>
        <div class="product-addon">
            <p><input type="checkbox" id="donation-optin" <?php echo $donation['checked'] ? 'checked="checked"' : '' ?>/> I would like to make a one time <strong><?php echo $pmpro_currency_symbol . $min_amount ?></strong> donation
            <?php if ($variable_amount) {
                $add_amount = $max_amount - $min_amount;
                $amount += $add_amount; ?>
                plus an additional <?php echo $pmpro_currency_symbol ?> <input type="text" id="add-amount" size="10" value="<?php echo $add_amount;?>" />
            <?php }
            $donation_amount = $donation['checked'] ? $amount : 0;
            ?>
            <input type="hidden" id="donation-amount" name="donation_amount" size="10" value="<?php echo $amount;?>" /></p>
            <p>Total Amount: <strong><?php echo $pmpro_currency_symbol ?><span id="total-amount"><?php echo $membership_amount + $donation_amount ?></span></strong></p>
        </div>
        <script>
            //some vars for keeping track of whether or not we show billing
            var pmpro_gateway_billing = <?php if(in_array($gateway, array("paypalexpress", "twocheckout")) !== false) echo "false"; else echo "true";?>;
            var pmpro_pricing_billing = <?php if(!pmpro_isLevelFree($pmpro_level)) echo "true"; else echo "false";?>;

            //this script will hide show billing fields based on the price set
            jQuery(document).ready(function ($) {
                //bind check to price field
                var pmprovp_price_timer;
                $('#add-amount').bind('keyup change', function() {
                    var add_amount = isNaN(parseFloat(this.value)) ? 0 : parseFloat(this.value);
                    donation_amount = <?php echo $min_amount ?> + add_amount;
                    $('#donation-amount').attr('value', donation_amount);
                    pmprovp_price_timer = setTimeout(pmprovp_checkForFree, 000);
                });

                $('#donation-optin').click( pmprovp_checkForFree );

                if ($('input[name=gateway]')) {
                    $('input[name=gateway]').bind('click', function () {
                        pmprovp_price_timer = setTimeout(pmprovp_checkForFree, 000);
                    });
                }

                $('#pmpro_checkout_boxes a').click( function() {
                    // add donation amount to query string
                    var href = $(this).attr('href') + "&donation=";
                    if ($('#donation-optin').is(':checked')) {
                         href += $('#donation-amount').val();
                    } else {
                        // no donation
                        href += "0";
                    }
                    $(this).attr('href', href);
                });

                function update_total() {
                    var membership_amount = <?php echo $membership_amount ?>;
                    var donation_amount = $('#donation-optin').is(':checked') ? parseFloat($('#donation-amount').val()) : 0;
                    $('#total-amount').html( membership_amount + donation_amount );
                }

                function pmprovp_checkForFree() {
                    update_total();

                    var amount = parseFloat($('#total-amount').html());

                    //does the gateway require billing?
                    if ($('input[name=gateway]').length) {
                        var no_billing_gateways = ['paypalexpress', 'twocheckout'];
                        var gateway = $('input[name=gateway]:checked').val();
                        if (no_billing_gateways.indexOf(gateway) > -1)
                            pmpro_gateway_billing = false;
                        else
                            pmpro_gateway_billing = true;
                    }

                    //is there a donation amount?
                    if (amount)
                        pmpro_pricing_billing = true;
                    else
                        pmpro_pricing_billing = false;

                    //figure out if we should show the billing fields
                    if (pmpro_gateway_billing && pmpro_pricing_billing) {
                        $('#pmpro_billing_address_fields').show();
                        $('#pmpro_payment_information_fields').show();
                        pmpro_require_billing = true;
                    }
                    else {
                        $('#pmpro_billing_address_fields').hide();
                        $('#pmpro_payment_information_fields').hide();
                        pmpro_require_billing = false;
                    }
                }

                //check when page loads too
                pmprovp_checkForFree();
            });

        </script>
    <?php
    }

    //set amount
    function pmpro_checkout_level($level)
    {
        if (isset($_REQUEST['donation_amount']))
            $amount = preg_replace("[^0-9\.]", "", $_REQUEST['donation_amount']);

        // check if a donation amount is passed and amount is valid then add to cart
        if ($amount && $this->pmpro_registration_checks(true)) {
            $level->cart['donation'] = number_format((float)$amount, 2, '.', '');
        }

        return $level;
    }

    //check price is between min and max
    function pmpro_registration_checks($continue)
    {
        //only bother if we are continuing already
        if ($continue) {
            global $pmpro_currency_symbol, $pmpro_msg, $pmpro_msgt;

            $donation_amount = $_REQUEST['donation_amount'];

            //was an amount passed in?
            if ($donation_amount) {
                //get values
                $level_id = intval($_REQUEST['level']);
                $fields = self::pmpro_get_donation_option($level_id);

                $donation = $fields['donation'];
                $min_amount = $fields['min_amount'];
                $max_amount = $fields['max_amount'];
                $max_amount < $min_amount and $max_amount = $min_amount;

                //make sure this level has donation
                if (empty($donation)) {
                    $pmpro_msg = "Error: You tried to set the amount on a level that doesn't have donation. Please try again.";
                    $pmpro_msgt = "pmmpro_error";
                }

                //get amount
                $amount = preg_replace("[^0-9\.]", "", $donation_amount);

                //check that the price falls between the min and max
                if ((double)$amount < (double)$min_amount) {
                    $pmpro_msg = "The lowest accepted donation amount is " . $pmpro_currency_symbol . $min_amount . ". Please enter a new amount.";
                    $pmpro_msgt = "pmmpro_error";
                    $continue = false;
                } elseif ((double)$amount > (double)$max_amount) {
                    $pmpro_msg = "The highest accepted donation amount is " . $pmpro_currency_symbol . $max_amount . ". Please enter a new amount.";
                    $pmpro_msgt = "pmmpro_error";
                    $continue = false;
                }

                //all good!
            }
        }

        return $continue;
    }

    // process additional products from shopping cart to get the total initial payment
    function pmpro_checkout_order($order)
    {
        //check if the cart has items, otherwise do nothing
        if (empty($order->membership_level->cart))
            return $order;

        //add membership amount to cart and recalculate initial payment, subtotal and tax
        $order->membership_level->cart['membership'] = $order->InitialPayment;

        $order->InitialPayment = 0;
        foreach ($order->membership_level->cart as $item => $amount) {
            $order->InitialPayment += $amount;
        }
        //tax
        $order->subtotal = $order->InitialPayment;
        $order->getTax(true);
        $order->notes .= "[ITEMS]" . json_encode($order->membership_level->cart) . "[/ITEMS]";
        return $order;
    }

    // save itemized order from shopping cart
    function pmpro_added_order($order)
    {
        //check if the cart has items, otherwise do nothing
        if (empty($order->membership_level->cart))
            return;

        // use $order->id for reference
        foreach ($order->membership_level->cart as $item => $amount) {
            $this->add_order_item($order->id, array('name' => $item, 'amount' => $amount));
        }
    }

    function pmpro_invoice_bullets_bottom($invoice)
    {
        //get saved itemized order from notes
        preg_match('/\[ITEMS]([^\[]*)\[\/ITEMS]/i', $invoice->notes, $items);

        is_array($items) and $items = (array) json_decode($items[1]);

        //$items should contain the array of items now
        echo '<ul>';
        foreach ($items as $item => $amount) {
            echo "<li>$item = $amount</li>";
        }
        echo '</ul>';
    }

    //create tables to store product addons and order items
    function create_tables()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'pmpro_membership_orders_meta';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
             $query = "CREATE TABLE $table_name (
                  meta_id bigint(20) NOT NULL AUTO_INCREMENT,
                  order_id bigint(20) NOT NULL,
                  meta_key varchar(255) DEFAULT NULL,
                  meta_value longtext,
                  PRIMARY KEY (meta_id),
                  KEY order_id (order_id),
                  KEY meta_key (meta_key)
                )";
             $wpdb->query($query);
        }
    }

    // check if user has no prior membership
    function is_new_membership($user_id)
    {
        global $wpdb;
        $table = $wpdb->pmpro_memberships_users;
        $query = "SELECT user_id FROM ${table} WHERE user_id = ${user_id}";
        return $wpdb->get_var($query) ? false : true;
    }

    private function add_order_item($order_id, array $item)
    {
        global $wpdb;
        $sql = "INSERT INTO $wpdb->pmpro_membership_orders_meta (order_id, meta_key, meta_value) VALUES(" . $order_id . ", '" . $item['name'] . "', " . $item['amount'] . ")";
        return $wpdb->query($sql);
    }
}

//load pmpro donation plugin
add_action('init', array('PMPro_Donation', 'get_instance'));
