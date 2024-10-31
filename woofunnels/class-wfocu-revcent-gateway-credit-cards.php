<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WFOCU_Revcent_Gateway_Credit_Cards
 */
class WFOCU_Revcent_Gateway_Credit_Cards extends WFOCU_Gateway {

	protected static $ins = null;
	public $key = 'revcent_payments';
	public $token = false;

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();
		$this->refund_supported = true;
		add_filter( 'revcent_payload_request_args', array( $this, 'wfocu_set_pending_payment' ) );
		add_action( 'wfocu_front_primary_order_cancelled', array( $this, 'maybe_cancel_primary_order' ) );
		add_action( 'wfocu_after_normalize_order_status', array( $this, 'maybe_normalize_order' ) );

	}

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self;
		}

		return self::$ins;
	}

	/**
	 * Try and get the payment token saved by the gateway
	 *
	 * @param WC_Order $order
	 *
	 * @return true on success false otherwise
	 */
	public function has_token( $order ) { //phpcs:ignore
		return true;

	}

	public function process_charge( $order ) {
		$is_successful  = false;
		$payload_rq     = array();
		$products       = array();
		$tax            = array();
		$shipping       = array();
		$tax_type       = 'skip';
		$ship_type      = 'skip';
		$get_package    = WFOCU_Core()->data->get( '_upsell_package' );
		$get_offer_id   = WFOCU_Core()->data->get_current_offer();
		$methods        = $order->get_shipping_methods();
		$offer_shipping = ( isset( $get_package['shipping'] ) && isset( $get_package['shipping']['diff'] ) ) ? $get_package['shipping']['diff']['cost'] : 0;
		
		if ( isset( $get_package['products'] ) && is_array( $get_package['products'] ) && count( $get_package['products'] ) > 0 ) {
			foreach ( $get_package['products'] as $product_data ) {
				$product_id    = $product_data['data']->get_id();
				$product_price = $product_data['price'];
				$obj           = new stdClass();
				$obj->id       = "$product_id";
				$obj->price    = floatval( $product_price );
				$obj->quantity = intval( wc_stock_amount( $product_data['qty'] ) );
				array_push( $products, $obj );
			}
		}

		if ( isset( $get_package['taxes'] ) && $get_package['taxes'] > 0 ) {
			$tax_type   = 'replace';
			$tax_amount = $get_package['taxes'];
			if ( $this->maybe_create_new_order() === false && $order->get_total_tax() > 0 ) {
				$tax_amount += $order->get_total_tax();
			}
			$obj         = new stdClass();
			$obj->amount = floatval( $tax_amount );
			array_push( $tax, $obj );
		}

		if ( $methods && is_array( $methods ) && count( $methods ) ) {
			$ship_type = 'replace';
			foreach ( $methods as $method ) {
				$shipping_amount = $method->get_total();
				if ( $this->maybe_create_new_order() ) {
					$shipping_amount = $offer_shipping;
				}

				$shipping_provider        = $method->get_instance_id();
				$shipping_provider_method = $method->get_instance_id();
				$obj                      = new stdClass();
				$obj->amount              = floatval( $shipping_amount );
				$obj->cost                = 0;
				$obj->provider            = $shipping_provider;
				$obj->provider_method     = $shipping_provider_method;
				array_push( $shipping, $obj );
			}
		}
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
		$payload_rq['request'] = array(
			"type"              => "sale",
			"method"            => "create",
			'campaign'          => $this->get_wc_gateway()->revcent_campaign,
			'is_pending'        => true,
			"unique_request_id" => "tps_" . $this->get_wc_gateway()->revcent_third_party_shop . "_" . strval( $order->get_id() ),
			"product"           => $products,
			'pending_options'   => array(
				"exists_options" => [
					"product"  => 'merge_combine',
					"tax"      => $tax_type,
					"shipping" => $ship_type,
					"discount" => "skip",
				]
			),
			'shipping'          => $shipping,
			'tax'               => $tax,
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
			]
		);

		if ( $this->maybe_create_new_order() ) {
			$metadata    = array();
			$customer_id = $order->get_meta( '_revcent_customer_id', true );

			if (isset($_COOKIE["revcent_google_click_id"]) && $_COOKIE['revcent_google_click_id'] !== '') { //phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
				$obj = new stdClass();
				$obj->name = "adwords_click";
				$obj->value = (string) htmlspecialchars(filter_var($_COOKIE['revcent_google_click_id'], FILTER_SANITIZE_STRING)); //phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
				array_push($metadata, $obj);
			}
			if (isset($_COOKIE["revcent_kount_session_id"]) && $_COOKIE['revcent_kount_session_id'] !== '') {//phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
				$obj = new stdClass();
				$obj->name = "kount_session_id";
				$obj->value = (string) htmlspecialchars(filter_var($_COOKIE['revcent_kount_session_id'], FILTER_SANITIZE_STRING));//phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
				array_push($metadata, $obj);
			}
			if (isset($_COOKIE["revcent_track_id"]) && $_COOKIE['revcent_track_id'] !== '') {//phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
				$obj = new stdClass();
				$obj->name = "revcent_track_id";
				$obj->value = (string) htmlspecialchars(filter_var($_COOKIE['revcent_track_id'], FILTER_SANITIZE_STRING));//phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
				array_push($metadata, $obj);
			}
			if (isset($_COOKIE["revcent_entry_id"]) && $_COOKIE['revcent_entry_id'] !== '') {//phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
				$obj = new stdClass();
				$obj->name = "revcent_entry_id";
				$obj->value = (string) htmlspecialchars(filter_var($_COOKIE['revcent_entry_id'], FILTER_SANITIZE_STRING));//phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
				array_push($metadata, $obj);
			}
			$obj = new stdClass();
			$obj->name = "customer_ip";
			$obj->value = WFOCU_WC_Compatibility::get_customer_ip_address( $order );
			array_push($metadata, $obj);

			$obj = new stdClass();
			$obj->name = "is_upsell";
			$obj->value = "true";
			array_push($metadata, $obj);

			$payload_rq['request']['is_pending']        = false;
			$payload_rq['request']['unique_request_id'] = "tps_" . $this->get_wc_gateway()->revcent_third_party_shop . "_" . strval( $this->generate_hash_key() );
			$payload_rq['request']['customer_id']       = $customer_id;
			$payload_rq['request']['metadata']          = $metadata;
			

			unset( $payload_rq['request']['pending_options'] );

		}

		$response = $this->response_request( $payload_rq );
		if ( isset( $response['error'] ) ) {
			WFOCU_Core()->log->log( "WFOCU Revcent was an issue processing the payment." );

			return $this->handle_result( $is_successful, '' );
		}
		if ( $response['status'] === false ) {
			$order->add_order_note( __( ' Upsell RevCent transaction failure ' . $response['msg'] . ' ' . $response['verbage'] . $response['response_id'], 'woofunnels-upstroke-one-click-upsell' ) );
			WFOCU_Core()->log->log( 'Upsell Offer RevCent transaction failure. ' . $response['msg'] . ' ' . $response['verbage'] . $response['response_id'] );

			return $this->handle_result( $is_successful, '' );
		}
		$is_successful = true;
		$order->add_order_note( __( 'Upsell revCent payment complete Offer ID ' . $get_offer_id . ' | ' . $response['verbage'] . ' ID: ' . $response['response_id'], 'woofunnels-upstroke-one-click-upsell' ) );

		return $this->handle_result( $is_successful, '' );
	}

	/**
	 * Cover if primary order cancelled
	 *
	 * @param WC_Order $order
	 */
	public function maybe_cancel_primary_order( $order ) {
		$payment_method = $order->get_payment_method();
		$payload_rq     = array();

		if ( $payment_method === 'revcent_payments' ) {
			$payload_rq['request'] = array(
				"type"              => "sale",
				"method"            => "void",
				"unique_request_id" => "tps_" . $this->get_wc_gateway()->revcent_third_party_shop . "_" . strval( $order->get_id() ),
			);

			$response = $this->response_request( $payload_rq );

			if ( isset( $response['error'] ) ) {
				WFOCU_Core()->log->log( 'WFOCU Revcent refund was an issue processing the payment. ' );
			}

			if ( $response['status'] === false ) {
				$order->add_order_note( __( 'Upsell RevCent refund transaction failure ' . $response['msg'] . ' ' . $response['verbage'] . $response['response_id'], 'woofunnels-upstroke-one-click-upsell' ) );
				WFOCU_Core()->log->log( 'Upsell Offer RevCent refund transaction failure ' . $response['msg'] . ' ' . $response['verbage'] . $response['response_id'] );
			}

			$order->add_order_note( __( 'Upsell refund payment successfully ' . $response['verbage'] . $response['response_id'], 'woofunnels-upstroke-one-click-upsell' ) );
		}

	}

	public function maybe_normalize_order( $order_id ) {

		$order          = wc_get_order( $order_id );
		$payload_rq     = array();
		$payment_method = $order->get_payment_method();

		if ( $payment_method === 'revcent_payments' ) {
			$metadata = array();
			$obj = new stdClass();
			$obj->name = "customer_ip";
			$obj->value = WFOCU_WC_Compatibility::get_customer_ip_address( $order );
			array_push($metadata, $obj);
			if (isset($_COOKIE["revcent_google_click_id"]) && $_COOKIE['revcent_google_click_id'] !== '') { //phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
				$obj = new stdClass();
				$obj->name = "adwords_click";
				$obj->value = (string) htmlspecialchars(filter_var($_COOKIE['revcent_google_click_id'], FILTER_SANITIZE_STRING)); //phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
				array_push($metadata, $obj);
			}
			if (isset($_COOKIE["revcent_kount_session_id"]) && $_COOKIE['revcent_kount_session_id'] !== '') {//phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
				$obj = new stdClass();
				$obj->name = "kount_session_id";
				$obj->value = (string) htmlspecialchars(filter_var($_COOKIE['revcent_kount_session_id'], FILTER_SANITIZE_STRING));//phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
				array_push($metadata, $obj);
			}
			if (isset($_COOKIE["revcent_track_id"]) && $_COOKIE['revcent_track_id'] !== '') {//phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
				$obj = new stdClass();
				$obj->name = "revcent_track_id";
				$obj->value = (string) htmlspecialchars(filter_var($_COOKIE['revcent_track_id'], FILTER_SANITIZE_STRING));//phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
				array_push($metadata, $obj);
			}
			if (isset($_COOKIE["revcent_entry_id"]) && $_COOKIE['revcent_entry_id'] !== '') {//phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
				$obj = new stdClass();
				$obj->name = "revcent_entry_id";
				$obj->value = (string) htmlspecialchars(filter_var($_COOKIE['revcent_entry_id'], FILTER_SANITIZE_STRING));//phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
				array_push($metadata, $obj);
			}
			$obj = new stdClass();
			$obj->name = "wc_order_id";
			$obj->value = strval($order->get_id());
			array_push($metadata, $obj);
			

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

			$payload_rq['request'] = array(
				"type"              => "sale",
				"method"            => "create",
				"campaign"          => $this->get_wc_gateway()->revcent_campaign,
				"unique_request_id" => "tps_" . $this->get_wc_gateway()->revcent_third_party_shop . "_" . strval( $order->get_id() ),
				"metadata"          => $metadata,
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
				]
			);

			$response = $this->response_request( $payload_rq );

			if ( isset( $response['error'] ) ) {
				WFOCU_Core()->log->log( 'Final WFOCU Revcent was an issue processing the payment. ' );
			}

			if ( $response['status'] === false ) {
				$order->add_order_note( __( 'Final Upsell RevCent transaction failure ' . $response['msg'] . ' ' . $response['verbage'] . $response['response_id'], 'woofunnels-upstroke-one-click-upsell' ) );
				WFOCU_Core()->log->log( 'Final Upsell Offer RevCent transaction failure ' . $response['msg'] . ' ' . $response['verbage'] . $response['response_id'] );
			}

			$order->add_order_note( __( 'Upsell payment successfully ' . $response['verbage'] . $response['response_id'], 'woofunnels-upstroke-one-click-upsell' ) );
			$order->update_meta_data( '_transaction_id', $response['response_id'] );
			$order->save();

		}

	}

	public function response_request( $args ) {
		$environment_url = 'https://api.revcent.com/v1';

		$result   = [
			'status'      => false,
			'msg'         => '',
			'verbage'     => '',
			'response_id' => '',
		];
		$response = wp_remote_post( $environment_url, array(
			'method'    => 'POST',
			'headers'   => array(
				'x-api-key' => $this->get_wc_gateway()->revcent_api_key,
			),
			'body'      => json_encode( $args ),
			'timeout'   => 90,
			'sslverify' => false,
		) );

		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( is_wp_error( $response ) ) {
			$result['error'] = true;

			return $result;
		}
		$revcent_response_verbage = '';
		$revcent_response_id      = '';
		if ( isset( $response_body['transaction_id'] ) ) {
			$revcent_response_verbage = 'Transaction ID: ';
			$revcent_response_id      = $response_body['transaction_id'];
		} else if ( isset( $response_body['api_call_id'] ) ) {
			$revcent_response_verbage = 'API Call: ';
			$revcent_response_id      = $response_body['api_call_id'];
		}

		if ( $response_body['code'] !== 1 ) {
			$revcent_message_append = '';
			if ( isset( $response_body['message'] ) ) {
				$revcent_message_append = ' ' . $response_body['message'] . '';
			} else if ( isset( $response_body['result'] ) ) {
				$revcent_message_append = ' ' . $response_body['result'] . '';
			}
			if ( $response_body['code'] === 2 ) {
				$revcent_error_message = 'Transaction declined.' . $revcent_message_append;
			} else if ( $response_body['code'] === 3 ) {
				$revcent_error_message = 'Transaction declined.' . $revcent_message_append;
			} else if ( $response_body['code'] === 4 ) {
				$revcent_error_message = 'Transaction held.' . $revcent_message_append;
			} else if ( $response_body['code'] === 5 ) {
				$revcent_error_message = 'Transaction rejected.' . $revcent_message_append;
			} else if ( $response_body['code'] === 0 ) {
				$revcent_error_message = 'Transaction declined.' . $revcent_message_append;
			} else {
				$revcent_error_message = 'Transaction failed.' . $revcent_message_append;
			}
			$result = [
				'msg'         => $revcent_error_message,
				'verbage'     => $revcent_response_verbage,
				'response_id' => $revcent_response_id,
			];

			return $result;
		}
		$result = [
			'status'      => true,
			'verbage'     => $revcent_response_verbage,
			'response_id' => $revcent_response_id,
		];

		return $result;
	}

	public function generate_hash_key() {
		require_once ABSPATH . 'wp-includes/class-phpass.php';
		$hasher = new PasswordHash( 2, false );

		return bin2hex( $hasher->get_random_bytes( 4 ) );
	}

	public function maybe_create_new_order() {
		$funnel_id = WFOCU_Core()->data->get( 'funnel_id' );
		if ( ! empty( $funnel_id ) ) {
			$funnel_settings = get_post_meta( $funnel_id, '_wfocu_settings', true );
			if ( is_array( $funnel_settings ) && count( $funnel_settings ) > 0 ) {
				if ( isset( $funnel_settings['order_behavior'] ) && 'create_order' === $funnel_settings['order_behavior'] ) {
					return true;
				}
			}
		}

		return false;
	}

	public function wfocu_set_pending_payment( $args ) {
		$funnel_id          = WFOCU_Core()->data->get( 'funnel_id' );
		$args['is_pending'] = ( ! empty( $funnel_id ) ) ? true : false;
		if ( $this->maybe_create_new_order() ) {
			$args['is_pending'] = false;
		}

		return $args;
	}

}
