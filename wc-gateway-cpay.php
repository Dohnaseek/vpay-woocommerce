<?php

/*
* Plugin Name: WooCommerce Virtual Payments Payment Gateway
* Plugin URI: https://virtual-payments.com
* Description: Pay with VPAY
* Author: Virutal Payments
* Author URI: https://virtual-payments.com
* Version: 1.0
*/

if (!defined('ABSPATH')) {
	exit;
}

add_filter('woocommerce_payment_gateways', 'vpay_add_gateway_class');
add_filter( 'https_ssl_verify', '__return_false' );

function vpay_add_gateway_class($gateways) {
	$gateways[] = 'WC_Gateway_VPay';
	return $gateways;
}
 
add_action('plugins_loaded', 'vpay_init_gateway_class');

function vpay_init_gateway_class() {
	class WC_Gateway_VPay extends WC_Payment_Gateway {
		public function __construct() {
			$this->id                 = 'vpay';
			$this->has_fields         = false;
			$this->order_button_text  = __('Pay with VPAY', 'woocommerce');
			$this->method_title       = __('VPAY', 'woocommerce');
			$this->method_description = __('Pay with VPAY', 'woocommerce');
			$this->supports           = array(
				'products',
			);
			$this->init_form_fields();
			$this->title       = $this->get_option('title');
			$this->description = $this->get_option('description');
			$this->apiKey      = $this->get_option('apiKey');
			$this->secretKey      = $this->get_option('secretKey');

			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			add_action('woocommerce_api_wc_gateway_vpay', array($this, 'webhook'));
		}

		public function needs_setup() {
			return $this->apiKey == '';
		}

		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'               => array(
					'title'   => __('Enable/Disable', 'woocommerce'),
					'type'    => 'checkbox',
					'label'   => __('Enable VPAY', 'woocommerce'),
					'default' => 'no',
				),
				'title'                 => array(
					'title'       => __('Title', 'woocommerce'),
					'type'        => 'text',
					'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
					'default'     => __('Virtual Payments', 'woocommerce'),
					'desc_tip'    => true,
				),
				'description'           => array(
					'title'       => __('Description', 'woocommerce'),
					'type'        => 'text',
					'desc_tip'    => true,
					'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
					'default'     => __('Pay with VPAY', 'woocommerce'),
				),
				'apiKey'          => array(
					'title'       => __('Public Key', 'woocommerce'),
					'type'        => 'text',
					'description' => __('Please enter your VPAY API key', 'woocommerce'),
					'default'     => '',
					'desc_tip'    => true,
					'placeholder' => 'XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX',
				),
				'secretKey'          => array(
					'title'       => __('Secret Key', 'woocommerce'),
					'type'        => 'text',
					'description' => __('Please enter your VPAY Secret API key', 'woocommerce'),
					'default'     => '',
					'desc_tip'    => true,
					'placeholder' => 'XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX',
				),
			);
		}

		public function process_payment($order_id) {
			$order = wc_get_order($order_id);
			$amount = $order->get_total();
			$currency = $order->get_currency();
			$total = $this->calculateUSD($amount, $currency);

			$raw = wp_remote_post(
				"https://app.virtual-payments.com/api/v1/pay/create",
				array(
					"method"      		=> "POST",
					"body"        		=>  json_encode(array(
						"key"		=>	$this->secretKey,
						"customer"			=>	$order->get_billing_email(),
						"amount"		=>	$total,
						"webhook_url"	=>	WC()->api_request_url("wc_gateway_vpay"),
						"return_url"		=>	esc_url_raw($this->get_return_url($order)),
						"internalTransactionId"		=>	$order->get_id(),
						"receivingCurrency"	=> 'BTC',
						
					)),
					"timeout"     		=> 70,
					"user-agent" 		=> "WooCommerce/" . WC()->version,
					"httpversion" 		=> "1.1",
					"headers"   	  	=> array(
						"Content-Type" 	=> "application/json; charset=utf-8"
					),
				)
			);
			
			$response = json_decode($raw['body']);

			if (!isset($response->data->code) || $response->status != "success") {
				return false;
			}

			return array(
				'result'   => 'success',
				'redirect' => 'https://app.virtual-payments.com/checkout/' . $response->data->code,
			);
		}

		public function webhook() {
			global $woocommerce;

			$response = json_decode(file_get_contents("php://input"));

			if (!isset($response->data->internalTransactionId)) {
				return false;
			}

			$order = wc_get_order($response->data->internalTransactionId);

			if (!$order || $order === null) {
				return false;
			}

			if ($response->data->secretKey !== $this->secretKey) {
				return false;
			}

			if ($response->data->status !== "completed") {
				return false;
			}

			$order->payment_complete();
			$order->reduce_order_stock();
			$woocommerce->cart->empty_cart();
		}

		private function calculateUSD($amount, $currency){
	        $amount = (float)$amount;
	        $currency = strtoupper($currency);

	        if ($amount <= 0) {
	        	return $amount;
	        }

	        if (!$currency || $currency === null || $currency === "") {
	        	return $amount;
	        }

	        $url = "https://app.virtual-payments.com/api/rates/".$currency."USD";

	        $req = wp_remote_get($url);
	        $raw = wp_remote_retrieve_body($req);
	        $response = json_decode($raw);

	        if (!$raw || $raw === null) {
	        	return $amount;
	        }

	        if (!isset($response->status) || $response->status !== "success") {
	        	return $amount;
	        }

	        $multiplier = (float)$response->rate;

	        if ($multiplier <= 0) {
	        	return $amount;
	        }

	        return $amount * $multiplier;
		}
	}
}

?>
