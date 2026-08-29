<?php
/**
 * Plugin Name:  Pethelp PayU – karty i Pay by Link
 * Plugin URI:   https://pethelp.pl
 * Description:  Dedykowana integracja PayU dla Pethelp – jednorazowe płatności Pay by Link oraz płatności cykliczne kartą (tokeny wielokrotnego użytku) dla WooCommerce i WooCommerce Subscriptions. Zastępuje woocommerce-gateway-payu-pl dla płatności obsługiwanych przez Pethelp.
 * Version:      1.0.0
 * Author:       Pethelp
 * Text Domain:  pethelp-payu-cards
 * Domain Path:  /languages
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 */

defined( 'ABSPATH' ) || exit;

define( 'PETHELP_PAYU_CARDS_VERSION', '1.0.0' );
define( 'PETHELP_PAYU_CARDS_FILE', __FILE__ );
define( 'PETHELP_PAYU_CARDS_PATH', plugin_dir_path( __FILE__ ) );
define( 'PETHELP_PAYU_CARDS_URL', plugin_dir_url( __FILE__ ) );

register_activation_hook( __FILE__, 'pethelp_payu_cards_activate' );

function pethelp_payu_cards_activate() {
	require_once PETHELP_PAYU_CARDS_PATH . 'includes/class-token-repository.php';
	require_once PETHELP_PAYU_CARDS_PATH . 'includes/class-audit-log.php';
	Pethelp_PayU_Token_Repository::install_table();
}

add_action( 'plugins_loaded', 'pethelp_payu_cards_init', 11 );

function pethelp_payu_cards_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Pethelp PayU wymaga aktywnego WooCommerce.', 'pethelp-payu-cards' ) . '</p></div>';
		} );
		return;
	}

	require_once PETHELP_PAYU_CARDS_PATH . 'includes/class-payu-exception.php';
	require_once PETHELP_PAYU_CARDS_PATH . 'includes/class-payu-api.php';
	require_once PETHELP_PAYU_CARDS_PATH . 'includes/class-audit-log.php';
	require_once PETHELP_PAYU_CARDS_PATH . 'includes/class-token-repository.php';
	require_once PETHELP_PAYU_CARDS_PATH . 'includes/class-widget-config.php';
	require_once PETHELP_PAYU_CARDS_PATH . 'includes/class-gateway-abstract.php';
	require_once PETHELP_PAYU_CARDS_PATH . 'includes/class-gateway-pbl.php';
	require_once PETHELP_PAYU_CARDS_PATH . 'includes/class-gateway-card-recurring.php';
	require_once PETHELP_PAYU_CARDS_PATH . 'includes/class-card-change-page.php';
	require_once PETHELP_PAYU_CARDS_PATH . 'includes/class-admin-subscription-fields.php';
	require_once PETHELP_PAYU_CARDS_PATH . 'includes/class-admin-tokens-page.php';
	require_once PETHELP_PAYU_CARDS_PATH . 'includes/class-token-expiry-housekeeping.php';

	if ( get_option( 'pethelp_payu_cards_db_version' ) !== PETHELP_PAYU_CARDS_VERSION ) {
		Pethelp_PayU_Token_Repository::install_table();
		update_option( 'pethelp_payu_cards_db_version', PETHELP_PAYU_CARDS_VERSION );
	}

	Pethelp_PayU_Card_Change_Page::init();
	Pethelp_PayU_Admin_Subscription_Fields::init();
	Pethelp_PayU_Admin_Tokens_Page::init();
	Pethelp_PayU_Token_Expiry_Housekeeping::init();

	add_filter( 'woocommerce_payment_gateways', 'pethelp_payu_cards_register_gateways' );
}

function pethelp_payu_cards_register_gateways( array $gateways ): array {
	$gateways[] = 'Pethelp_Gateway_PayU_PBL';
	$gateways[] = 'Pethelp_Gateway_PayU_Card_Recurring';
	return $gateways;
}

// Declare HPOS compatibility.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
