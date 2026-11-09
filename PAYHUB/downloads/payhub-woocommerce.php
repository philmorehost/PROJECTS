<?php
/**
 * Plugin Name: Payhub Payment Gateway for WooCommerce
 * Description: Accept payments on your WooCommerce store using Payhub.
 * Version: 1.0.0
 * Author: Payhub
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'woocommerce_payment_gateways', 'add_payhub_gateway_class' );
function add_payhub_gateway_class( $methods ) {
    $methods[] = 'WC_Payhub_Gateway';
    return $methods;
}

add_action( 'plugins_loaded', 'init_payhub_gateway_class' );
function init_payhub_gateway_class() {
    class WC_Payhub_Gateway extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'payhub';
            $this->icon = '';
            $this->has_fields = false;
            $this->method_title = 'Payhub';
            $this->method_description = 'Accept payments globally via Payhub.';
            $this->init_form_fields();
            $this->init_settings();
            $this->title = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description' );
            $this->enabled = $this->get_option( 'enabled' );
            $this->test_mode = 'yes' === $this->get_option( 'test_mode' );
            $this->private_key = $this->test_mode ? $this->get_option( 'test_private_key' ) : $this->get_option( 'live_private_key' );
            $this->public_key = $this->test_mode ? $this->get_option( 'test_public_key' ) : $this->get_option( 'live_public_key' );

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
        }

        public function init_form_fields(){
            $this->form_fields = array(
                'enabled' => array('title' => 'Enable/Disable', 'type' => 'checkbox', 'label' => 'Enable Payhub Payment', 'default' => 'no'),
                'title' => array('title' => 'Title', 'type' => 'text', 'description' => 'This controls the title which the user sees during checkout.', 'default' => 'Payhub', 'desc_tip' => true),
                'description' => array('title' => 'Description', 'type' => 'textarea', 'description' => 'Payment method description that the customer will see on your checkout.', 'default' => 'Pay securely with your card or bank account via Payhub.'),
                'test_mode' => array('title' => 'Test Mode', 'type' => 'checkbox', 'label' => 'Enable Test Mode', 'default' => 'yes'),
                'live_public_key' => array('title' => 'Live Public Key', 'type' => 'text'),
                'live_private_key' => array('title' => 'Live Secret Key', 'type' => 'password'),
                'test_public_key' => array('title' => 'Test Public Key', 'type' => 'text'),
                'test_private_key' => array('title' => 'Test Secret Key', 'type' => 'password')
            );
        }

        public function payment_scripts() {
            if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) return;
            wp_enqueue_script( 'payhub_inline', '<?php echo BASE_URL; ?>inline.js', array(), '1.0.0', true );
        }

        public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );
            return array(
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url( true )
            );
        }
    }
}
