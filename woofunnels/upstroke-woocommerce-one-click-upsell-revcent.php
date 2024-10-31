<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'WFOCU_VERSION' ) && ! class_exists( 'WFOCU_Revcent_Compatibility' ) ) {

	class WFOCU_Revcent_Compatibility {

		/**
		 * @var $instance
		 */
		public static $instance;
		public $class_prefix = 'WFOCU_Revcent_Gateway_';

		/**
		 * WFOCU_Revcent_Compatibility constructor.
		 */
		public function __construct() {
			$this->init_constants();

			//Including gateways integration files
			spl_autoload_register( array( $this, 'revcent_integration_autoload' ) );
			$this->init_hooks();
		}

		/**
		 * Initializing constants
		 */
		public function init_constants() {
			define( 'WFOCU_REVCENT_PLUGIN_DIR', __DIR__ );
		}

		/**
		 * Auto-loading the payment classes as they called.
		 *
		 * @param $class_name
		 */
		public function revcent_integration_autoload( $class_name ) {

			if ( false !== strpos( $class_name, $this->class_prefix ) ) {
				require_once WFOCU_REVCENT_PLUGIN_DIR . '/class-' . WFOCU_Common::slugify_classname( $class_name ) . '.php';
			}

		}

		/**
		 * @return WFOCU_Revcent_Compatibility
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Adding functions on hooks
		 */
		public function init_hooks() {
			//Adding revcent gateway on global settings on upstroke admin page
			add_filter( 'wfocu_wc_get_supported_gateways', array( $this, 'wfocu_revcent_gateways_integration' ), 10, 1 );

		}

		/**
		 * Adding gateways name for choosing on UpStroke global settings page
		 */
		public function wfocu_revcent_gateways_integration( $gateways ) {
			$gateways['revcent_payments'] = 'WFOCU_Revcent_Gateway_Credit_Cards';

			return $gateways;
		}

	}

	WFOCU_Revcent_Compatibility::get_instance();
}
