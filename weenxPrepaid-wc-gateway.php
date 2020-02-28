<?php

/**
 * WeenxPrepaid WooCommerce gateway plugin
 *
 * this plugin will add weenxPrepaid specific gateway support
 * for wooCommerce plugin
 *
 * @class       WC_Gateway_WeenxPrepaid
 * @extends     WC_Payment_Gateway
 * @version     1.0.0
 * @package     WooCommerce/Classes/Payment
 * @author      Zoha Banam
 */
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) return;
add_action('plugins_loaded', 'wc_weenxPrepaid_gateway_init', 11);

function wc_weenxPrepaid_gateway_init()
{
    // load soap wrapper
    require_once(WEENX_PREPAID_WC_GATEWAY_PLUGIN_DIR . "soap_wrapper.php");


    class Weenx_Prepaid_Gateway extends WC_Payment_Gateway
    {

        protected $wrapper = null;

        protected $sender_id;
        protected $plain_id;
        protected $encrypt_id;

        public function __construct()
        {
            $this->id = 'Weenx_Prepaid_Gateway';
            $this->has_fields = false;
            $this->method_title = "Weenx Prepaid";
            $this->method_description = "Weenx Prepaid Gateway";
            $this->icon = apply_filters('Weenx_Prepaid_Gateway_logo', WP_PLUGIN_URL . '/' . plugin_basename(__DIR__) . '/assets/logo.png');


            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];


            $this->sender_id = $this->settings['sender_id'];
            $this->plain_id = $this->settings['plain_id'];
            $this->encrypt_id = $this->settings['encrypt_id'];
            $this->gateway_url = $this->settings['gateway_url'];

            $this->injectWrapper();

            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            } else {
                add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));
            }

            add_action('woocommerce_api_weenx_prepaid_gateway', array($this, 'process_callback_return'));
        }

        protected function injectWrapper()
        {
            // soap wrapper 
            $this->wrapper = new Weenx_Prepaid_Soap_Wrapper($this->sender_id, $this->plain_id, $this->encrypt_id);
        }

        public function init_form_fields()
        {
            $this->form_fields =  apply_filters('Weenx_Prepaid_Gateway_config', array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable WeenxPrepaid Gateway Payment', 'woocommerce'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                    'default' => __('Weenx Prepaid', 'woocommerce'),
                    'desc_tip'      => true,
                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce'),
                    'type' => 'textarea',
                    'default' => ''
                ),
                'sender_id' => array(
                    'title' => __('Sender Id', 'woocommerce'),
                    'description' => __('sender id of gateway that you get from weenx prepaid', 'woocommerce'),
                    'type' => 'text',
                    'desc_tip'      => true,
                ),
                'plain_id' => array(
                    'title' => __('Plain Id', 'woocommerce'),
                    'description' => __('plain id of gateway that you get from weenx prepaid', 'woocommerce'),
                    'type' => 'text',
                    'desc_tip'      => true,
                ),
                'encrypt_id' => array(
                    'title' => __('Encrypt Id', 'woocommerce'),
                    'description' => __('encrypt id of gateway that you get from weenx prepaid', 'woocommerce'),
                    'type' => 'text',
                    'desc_tip'      => true,
                ),
                'gateway_url' => array(
                    'title' => __('Gateway Url Endpoint', 'woocommerce'),
                    'description' => __('endpoint for api calls', 'woocommerce'),
                    'default' => "https://services.weenx.net/webservices/gateway.asmx?WSDL",
                    'type' => 'url',
                    'desc_tip'      => true,
                )
            ));
        }

        function process_payment($order_id)
        {
            global $woocommerce;
            $order = new WC_Order($order_id);
            $woocommerce->session->order_id_weenx_prepaid = $order_id;

            $callback = WC()->api_request_url('Weenx_Prepaid_Gateway');
            $currencyId = $this->getCurrencyId($order->get_currency()) ?: 3;
            $language = $currencyId == 0 ? 'farsi' : 'english';
            $result = $this->wrapper->payment($order_id, $order->get_total(), $callback, $language, $currencyId);

            if (!$result->Status || $result->Status !== 1) {
                throw new Exception($result->Message);
            }

            $order->update_status('on-hold', __('Awaiting weenxPrepaid payment', 'woocommerce'));

            return array(
                'result' => 'success',
                'redirect' => $result->URL
            );
        }

        protected function getCurrencyId($currency = 'USD')
        {
            return [
                'IRT' => 1,
                'EUR' => 2,
                'USD' => 3,
            ][$currency];
        }

        function process_callback_return()
        {

            global $woocommerce;

            // get result token
            $resultToken = $_GET['result'] ?: $_POST['result'];
            if (!$resultToken) {
                wc_add_notice(__('No token has specified', 'woocommerce'), 'error');
                wp_redirect($woocommerce->cart->get_checkout_url());
                exit;
            }

            // get order id 
            if (isset($_GET['wc_order'])) {
                $order_id = $_GET['wc_order'];
            } else {
                $order_id = $woocommerce->session->order_id_weenx_prepaid;
                unset($woocommerce->session->order_id_weenx_prepaid);
            }
            if ($order_id) {
                try {
                    $order = new WC_Order($order_id);
                    $result = $this->wrapper->verify($resultToken);

                    // compare result fields with order fields 
                    if ($result->Status !== 1) {
                        throw new Exception($result->Message);
                    }
                    if ($order_id != $result->ReservationNumber) {
                        throw new Exception("Order id is not equal to reservation number");
                    }
                    if ($order->get_total() != $result->Amount) {
                        throw new Exception("Amount of products is not equal to paid amount");
                    }

                    $orderCurrency = $this->getCurrencyId($order->get_currency()) ?: 3;
                    if ($orderCurrency != $result->CurrencyId) {
                        throw new Exception("Currencies are not equal");
                    }

                    update_post_meta($order_id, '_transaction_id',  $result->ReferenceNumber);
                    $order->payment_complete($result->ReferenceNumber);
                    $woocommerce->cart->empty_cart();
                    wc_add_notice("Payment Was Successful, Reference Id : " . $result->ReferenceNumber, 'success');
                    wp_redirect(add_query_arg('wc_status', 'success', $this->get_return_url($order)));
                } catch (Exception $e) {
                    wc_add_notice(__($e->getMessage(), 'woocommerce'), 'error');
                    wp_redirect($woocommerce->cart->get_checkout_url());
                    exit;
                }
            } else {
                wc_add_notice(__('Order id not exists', 'woocommerce'), 'error');
                wp_redirect($woocommerce->cart->get_checkout_url());
                exit;
            }
        }
    }
}




function add_weenxPrepaid_gateway_class($methods)
{
    $methods[] = 'Weenx_Prepaid_Gateway';
    return $methods;
}

add_filter('woocommerce_payment_gateways', 'add_weenxPrepaid_gateway_class');
