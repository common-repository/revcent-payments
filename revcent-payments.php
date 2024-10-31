<?php

class revcent_payments extends WC_Payment_Gateway
{
	public function __construct()
	{
		$this->id = "revcent_payments";
		$this->method_title = __("RevCent Payments", 'revcent-payments');
		$this->method_description = __("RevCent payment integration plugin for WordPress and WooCommerce.", 'revcent-payments');
		$this->title = __("RevCent", 'revcent-payments');
		$this->icon = null;
		$this->has_fields = true;
		$this->supports = array('default_credit_card_form');
		$this->init_form_fields();
		$this->init_settings();
		foreach ($this->settings as $setting_key => $value) {
			$this->$setting_key = $value;
		}
		if (is_admin()) {
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		}
	}

	public function init_form_fields()
	{
		$this->form_fields = array(
			'enabled' => array(
				'title' => __('Enable / Disable', 'revcent-payments'),
				'label' => __('Enable this payment gateway', 'revcent-payments'),
				'type' => 'checkbox',
				'default' => 'no',
			),
			'title' => array(
				'title' => __('Title', 'revcent-payments'),
				'type' => 'text',
				'desc_tip' => __('Payment title of checkout process.', 'revcent-payments'),
				'default' => __('Credit card', 'revcent-payments'),
			),
			'description' => array(
				'title' => __('Description', 'revcent-payments'),
				'type' => 'textarea',
				'desc_tip' => __('Payment description of checkout process.', 'revcent-payments'),
				'default' => __('Pay using your credit card.', 'revcent-payments'),
				'css' => 'max-width:450px;',
			),
			'revcent_api_key' => array(
				'title' => __('RevCent API Key', 'revcent-payments'),
				'type' => 'text',
				'desc_tip' => __('This is the API key in your RevCent account for the third party shop API account.', 'revcent-payments'),
				'default' => __('', 'revcent-payments'),
			),
			'revcent_third_party_shop' => array(
				'title' => __('RevCent Third Party Shop', 'revcent-payments'),
				'type' => 'text',
				'desc_tip' => __('This is the RevCent Third Party Shop ID for this WooCommerce store.', 'revcent-payments'),
				'default' => __('', 'revcent-payments'),
			),
			'revcent_campaign' => array(
				'title' => __('RevCent Campaign', 'revcent-payments'),
				'type' => 'text',
				'desc_tip' => __('This is the RevCent campaign ID you are using for this WooCommerce store.', 'revcent-payments'),
				'default' => __('', 'revcent-payments'),
			),
			'revcent_dns_tracking' => array(
				'title' => __('DNS Tracking', 'revcent-payments'),
				'label' => __('Enable DNS tracking', 'revcent-payments'),
				'type' => 'checkbox',
				'desc_tip' => __('Whether to enable the DNS tracking snippet. Important: Tracking domain DNS must have already been set up correctly in RevCent for this domain.', 'revcent-payments'),
				'default' => 'no',
			)
		);
	}

	public function process_payment($order_id)
	{
		global $woocommerce;
		

		$order = wc_get_order($order_id);

		$order_shipping_total = $order->get_total_shipping();
		$tax_total = $order->get_total_tax();

		$products = array();
		$shipping = array();
		$metadata = array();
		$tax = array();
		$discount = array();

		if (count($order->get_taxes()) > 0) {
			foreach ( $order->get_taxes() as $item_tax ) {
				$obj = new stdClass();
				$obj->name = strval($item_tax->get_label());
				$obj->description = strval($item_tax->get_rate_code());
				if(floatval($item_tax->get_shipping_tax_total()) > 0){
					$combined_tax = (floatval($item_tax->get_tax_total()) + floatval($item_tax->get_shipping_tax_total()));
					$obj->amount = round($combined_tax, 2);
				}else{
					$obj->amount = floatval($item_tax->get_tax_total());
				}
				array_push($tax, $obj);
			}
		}


		if ($order->get_total_discount() > 0) {
			$discount_amount = $order->get_total_discount();
			$coupons  = $order->get_used_coupons();
			$coupon_code = 'WooCommerce Discount';
			if(count($coupons) > 0 && isset($coupons[0])){
				$coupon_code = $coupons[0];
			}
			$obj = new stdClass();
			$obj->discount_value = floatval($discount_amount);
			$obj->discount_type = 'amount';
			$obj->name = $coupon_code;
			$obj->description = '';
			array_push($discount, $obj);
		}


		if (count($order->get_coupon_codes()) > 0) {
			foreach( $order->get_coupon_codes() as $coupon_code ) {
				$coupon = new WC_Coupon($coupon_code);
				$metadata_obj = new stdClass();
				$metadata_obj->name = "coupon_code";
				$metadata_obj->value = $coupon_code;
				array_push($metadata, $metadata_obj);
			}
		}


		if (count($order->get_fees()) > 0) {
			foreach ( $order->get_fees() as $item_fee ) {
				if(floatval($item_fee->get_total()) < 0){
					$obj = new stdClass();
					$obj->discount_value = abs(floatval($item_fee->get_total()));
					$obj->discount_type = 'amount';
					$obj->name = $item_fee->get_name();
					$obj->description = '';
					array_push($discount, $obj);
				}
				if(floatval($item_fee->get_total()) > 0){
					$obj = new stdClass();
					$obj->name = $item_fee->get_name();
					$obj->description = 'Fee Converted To Tax Item';
					$obj->amount = abs(floatval($item_fee->get_total()));
					array_push($tax, $obj);
				}
			}
		}

		foreach ($order->get_items() as $item_id => $item) {
			$product = $item->get_product();
			$product_id = null;
			$product_price = null;

			if (is_object($product)) {
				$product_id = $product->get_id();
				$product_price = $order->get_item_subtotal( $item, false );
				$obj = new stdClass();
				$obj->id = "$product_id";
				$obj->price = floatval($product_price);
				$obj->quantity = intval(wc_stock_amount($item['qty']));
				array_push($products, $obj);
			}
		}


		foreach ($order->get_items('shipping') as $item_id => $shipping_item_obj) {
			$shipping_amount = $shipping_item_obj->get_total();
			$shipping_provider = $shipping_item_obj->get_instance_id();
			$shipping_provider_method = $shipping_item_obj->get_instance_id();
			$obj = new stdClass();
			$obj->amount = floatval($shipping_amount);
			$obj->cost = 0;
			$obj->provider = $shipping_provider;
			$obj->provider_method = $shipping_provider_method;
			array_push($shipping, $obj);
		}

		$obj = new stdClass();
		$obj->name = "wc_order_id";
		$obj->value = strval($order->get_id());
		array_push($metadata, $obj);

		if ($order->get_customer_note() !== '') {
			$note_obj = new stdClass();
			$note_obj->name = "customer_note";
			$note_obj->value = strval($order->get_customer_note());
			array_push($metadata, $note_obj);
		}

		$environment_url = 'https://api.revcent.com/v1';

		if ($order-> get_shipping_first_name() !== null && $order-> get_shipping_first_name() !== '' && $order-> get_shipping_last_name() !== null && $order-> get_shipping_last_name() !== '' && $order-> get_shipping_address_1() !== null && $order-> get_shipping_address_1() !== '') {
			$customer_shipping_first_name = $order-> get_shipping_first_name();
			$customer_shipping_last_name = $order-> get_shipping_last_name();
			$customer_shipping_address_1 = $order-> get_shipping_address_1();
			$customer_shipping_address_2 = $order-> get_shipping_address_2();
			$customer_shipping_city = $order-> get_shipping_city();
			$customer_shipping_state = $order-> get_shipping_state();
			$customer_shipping_postcode = $order-> get_shipping_postcode();
			$customer_shipping_country = $order-> get_shipping_country();
			$customer_shipping_email = $order-> get_billing_email();
			$customer_shipping_phone = $order-> get_shipping_phone();
		} else {
			$customer_shipping_first_name = $order-> get_billing_first_name();
			$customer_shipping_last_name = $order-> get_billing_last_name();
			$customer_shipping_address_1 = $order-> get_billing_address_1();
			$customer_shipping_address_2 = $order-> get_billing_address_2();
			$customer_shipping_city = $order-> get_billing_city();
			$customer_shipping_state = $order-> get_billing_state();
			$customer_shipping_postcode = $order-> get_billing_postcode();
			$customer_shipping_country = $order-> get_billing_country();
			$customer_shipping_email = $order-> get_billing_email();
			$customer_shipping_phone = $order-> get_billing_phone();
		}

		$iso_currency = $order->get_currency();


		$card_number = '';
		if (isset($_POST['revcent_payments-card-number'])){
			$card_post = (string) htmlspecialchars(filter_var($_POST['revcent_payments-card-number'], FILTER_SANITIZE_STRING));
			$card_number = str_replace(array(' ', ''), '', $card_post);
		}

		$card_exp = '';
		$card_exp_month = '';
		$card_exp_year = '';
		if (isset($_POST['revcent_payments-card-expiry'])){
			$card_exp_post = (string) htmlspecialchars(filter_var($_POST['revcent_payments-card-expiry'], FILTER_SANITIZE_STRING));
			$card_exp = explode('/', $card_exp_post);
			$card_exp_month = $card_exp[0];
			$card_exp_year = $card_exp[1];
		}

		$card_code = '';
		if (isset($_POST['revcent_payments-card-cvc']) && $_POST['revcent_payments-card-cvc'] !== ''){
			$card_code = (string) htmlspecialchars(filter_var($_POST['revcent_payments-card-cvc'], FILTER_SANITIZE_STRING));
		}
		if (isset($_COOKIE["revcent_google_click_id"]) && $_COOKIE['revcent_google_click_id'] !== '') {
			$obj = new stdClass();
			$obj->name = "adwords_click";
			$obj->value = (string) htmlspecialchars(filter_var($_COOKIE['revcent_google_click_id'], FILTER_SANITIZE_STRING));
			array_push($metadata, $obj);
		}
		if (isset($_COOKIE["revcent_kount_session_id"]) && $_COOKIE['revcent_kount_session_id'] !== '') {
			$obj = new stdClass();
			$obj->name = "kount_session_id";
			$obj->value = (string) htmlspecialchars(filter_var($_COOKIE['revcent_kount_session_id'], FILTER_SANITIZE_STRING));
			array_push($metadata, $obj);
		}
		if (isset($_COOKIE["revcent_track_id"]) && $_COOKIE['revcent_track_id'] !== '') {
			$obj = new stdClass();
			$obj->name = "revcent_track_id";
			$obj->value = (string) htmlspecialchars(filter_var($_COOKIE['revcent_track_id'], FILTER_SANITIZE_STRING));
			array_push($metadata, $obj);
		}
		if (isset($_COOKIE["revcent_entry_id"]) && $_COOKIE['revcent_entry_id'] !== '') {
			$obj = new stdClass();
			$obj->name = "revcent_entry_id";
			$obj->value = (string) htmlspecialchars(filter_var($_COOKIE['revcent_entry_id'], FILTER_SANITIZE_STRING));
			array_push($metadata, $obj);
		}
		$obj = new stdClass();
		$obj->name = "customer_ip";
		$obj->value = $order->get_customer_ip_address();
		array_push($metadata, $obj);

		$three_ds_enabled = false;
		$three_ds_eci = '';
		$three_ds_cavv = '';
		$three_ds_directory_server_id = '';
		$three_ds_version = '';
		$three_ds_acs_transaction_id = '';

		$three_ds_ctr = 0;

		if(isset($_POST['three_ds_eci']) && is_string($_POST['three_ds_eci']) && $_POST['three_ds_eci'] !== ''){
			$three_ds_eci = (string) htmlspecialchars(filter_var($_POST['three_ds_eci'], FILTER_SANITIZE_STRING));
			$three_ds_ctr++;
		}

		if(isset($_POST['three_ds_cavv']) && is_string($_POST['three_ds_cavv']) && $_POST['three_ds_cavv'] !== ''){
			$three_ds_cavv = (string) htmlspecialchars(filter_var($_POST['three_ds_cavv'], FILTER_SANITIZE_STRING));
			$three_ds_ctr++;
		}

		if(isset($_POST['three_ds_directory_server_id']) && is_string($_POST['three_ds_directory_server_id']) && $_POST['three_ds_directory_server_id'] !== ''){
			$three_ds_directory_server_id = (string) htmlspecialchars(filter_var($_POST['three_ds_directory_server_id'], FILTER_SANITIZE_STRING));
			$three_ds_ctr++;
		}

		if(isset($_POST['three_ds_version']) && is_string($_POST['three_ds_version']) && $_POST['three_ds_version'] !== ''){
			$three_ds_version = (string) htmlspecialchars(filter_var($_POST['three_ds_version'], FILTER_SANITIZE_STRING));
		}

		if(isset($_POST['three_ds_acs_transaction_id']) && is_string($_POST['three_ds_acs_transaction_id']) && $_POST['three_ds_acs_transaction_id'] !== ''){
			$three_ds_acs_transaction_id = (string) htmlspecialchars(filter_var($_POST['three_ds_acs_transaction_id'], FILTER_SANITIZE_STRING));
		}

		if($three_ds_ctr >= 3){
			$three_ds_enabled = true;
		}

		$payload_rq['request'] = apply_filters( 'revcent_payload_request_args', array(
			"type" => "sale",
			"method" => "create",
			"customer" => [
				"first_name" => $order->get_billing_first_name(),
				"last_name" => $order->get_billing_last_name(),
				"address_line_1" => $order->get_billing_address_1(),
				"address_line_2" => $order->get_billing_address_2(),
				"city" => $order->get_billing_city(),
				"state" => $order->get_billing_state(),
				"zip" => $order->get_billing_postcode(),
				"country" => $order->get_billing_country(),
				"company" => "",
				"email" => $order->get_billing_email(),
				"phone" => $order->get_billing_phone(),
			],
			"bill_to" => [
				"first_name" => $order->get_billing_first_name(),
				"last_name" => $order->get_billing_last_name(),
				"address_line_1" => $order->get_billing_address_1(),
				"address_line_2" => $order->get_billing_address_2(),
				"city" => $order->get_billing_city(),
				"state" => $order->get_billing_state(),
				"zip" => $order->get_billing_postcode(),
				"country" => $order->get_billing_country(),
				"company" => "",
				"email" => $order->get_billing_email(),
				"phone" => $order->get_billing_phone(),
			],
			"ship_to" => [
				"first_name" => $customer_shipping_first_name,
				"last_name" => $customer_shipping_last_name,
				"address_line_1" => $customer_shipping_address_1,
				"address_line_2" => $customer_shipping_address_2,
				"city" => $customer_shipping_city,
				"state" => $customer_shipping_state,
				"zip" => $customer_shipping_postcode,
				"country" => $customer_shipping_country,
				"company" => "",
				"email" => $customer_shipping_email,
				"phone" => $customer_shipping_phone,
			],
			"campaign" => $this->revcent_campaign,
			"payment" => [
				"credit_card" => [
					"card_number" => $card_number,
					"exp_month" => (int) $card_exp_month,
					"exp_year" => (int) $card_exp_year,
					"card_code" => $card_code,
					"set_as_default" => true
				],
			],
			"product" => $products,
			"shipping" => $shipping,
			"iso_currency" => strtoupper($iso_currency),
			"ip_address" => $order->get_customer_ip_address(),
			"tax" => $tax,
			"discount" => $discount,
			"initial_credit_card_required" => true,
			"third_party_shop" => $this->revcent_third_party_shop,
			"unique_request_id" => "tps_".$this->revcent_third_party_shop."_".strval($order->get_id()),
			"metadata" => $metadata,
			"three_ds" => [
				"enabled" => $three_ds_enabled,
				"eci" => $three_ds_eci,
				"cavv" => $three_ds_cavv,
				"directory_server_id" => $three_ds_directory_server_id,
				"version" => $three_ds_version,
				"acs_transaction_id" => $three_ds_acs_transaction_id,
			],
		));



		$response = wp_remote_post($environment_url, array(
			'method' => 'POST',
			'headers' => array(
				'x-api-key' => $this->revcent_api_key,
			),
			'body' => json_encode($payload_rq),
			'timeout' => 90,
			'sslverify' => false,
		));



		$response_body = json_decode(wp_remote_retrieve_body($response), true);
		if (is_wp_error($response)) {
			throw new Exception(__('There was an issue processing the payment. Please contact support.', 'revcent-payments'));
		}
		$revcent_response_verbage = '';
		$revcent_response_id = '';
		$revcent_response_api_call_id = '';
		if (isset($response_body['transaction_id'])) {
			$revcent_response_verbage = 'Transaction';
			$revcent_response_id = $response_body['transaction_id'];
		} else if (isset($response_body['api_call_id'])) {
			$revcent_response_verbage = 'API Call';
			$revcent_response_id = $response_body['api_call_id'];
			$revcent_response_api_call_id = $response_body['api_call_id'];
		}
		$revcent_error_message = '';
		if ($response_body['code'] !== 1) {
			$revcent_message_append = '';
			if (isset($response_body['message'])) {
				$revcent_message_append = ' ' . $response_body['message'] . '';
			}else if (isset($response_body['result'])) {
				$revcent_message_append = ' ' . $response_body['result'] . '';
			}
			if ($response_body['code'] === 2) {
				$revcent_error_message = 'Transaction declined.' . $revcent_message_append;
			} else if ($response_body['code'] === 3) {
				$revcent_error_message = 'Transaction declined.' . $revcent_message_append;
			} else if ($response_body['code'] === 4) {
				$revcent_error_message = 'Transaction held.' . $revcent_message_append;
			} else if ($response_body['code'] === 5) {
				$revcent_error_message = 'Transaction rejected.' . $revcent_message_append;
			} else if ($response_body['code'] === 0) {
				$revcent_error_message = 'Transaction declined.' . $revcent_message_append;
			} else {
				$revcent_error_message = 'Transaction failed.' . $revcent_message_append;
			}
			$order->add_order_note(__('RevCent transaction failure. ' . $revcent_error_message . ' ' . $revcent_response_verbage . ' ID: ' . $revcent_response_id, 'revcent-payments'));
			throw new Exception(__($revcent_error_message, 'revcent-payments'));
		}

		if( isset( $response_body['customer_id'] )) {
			$order->update_meta_data( '_revcent_customer_id', $response_body['customer_id'] );
			$order->save();
		}

		$order->add_order_note(__('RevCent payment complete. ' . $revcent_response_verbage . ' ID: ' . $revcent_response_id, 'revcent-payments'));
		$order->payment_complete();
		$woocommerce->cart->empty_cart();
		return array(
			'result' => 'success',
			'redirect' => $this->get_return_url($order),
		);
	}
}