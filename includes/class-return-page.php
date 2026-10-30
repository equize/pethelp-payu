<?php

defined( 'ABSPATH' ) || exit;

/**
 * Customer return from PayU to the order-received page (continueUrl).
 *
 * PayU appends `error=501` to continueUrl when the payment failed or was
 * cancelled. The parameter is not signed, so it only drives the message shown
 * to the customer – the order status is changed solely by a verified PayU
 * notification.
 */
class Pethelp_PayU_Return_Page {

	private const GATEWAYS = [ 'payu_pbl', 'payu_card_recurring' ];

	public static function init(): void {
		add_filter( 'pethelp_order_payment_not_completed_on_return', [ __CLASS__, 'payment_not_completed' ], 10, 2 );

		foreach ( self::GATEWAYS as $gateway ) {
			add_action( 'woocommerce_thankyou_' . $gateway, [ __CLASS__, 'expose_payu_order_id' ] );
		}
	}

	/**
	 * Exposes the current PayU order id as `window.pethelpPayuOrderId`. For card
	 * payments it never reaches the browser otherwise (the order is created
	 * server-to-server), and E2E tests need it to simulate PayU notifications.
	 * The page is only available to the order owner (order key), and the id alone
	 * cannot be used to forge a notification without the signature key.
	 *
	 * @param int $order_id
	 */
	public static function expose_payu_order_id( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$payu_order_id = (string) $order->get_meta( '_pethelp_payu_order_id' );

		printf(
			'<script>window.pethelpPayuOrderId = %s;</script>',
			wp_json_encode( $payu_order_id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT )
		);
	}

	/**
	 * @param bool|mixed $not_completed
	 * @param \WC_Order  $order
	 */
	public static function payment_not_completed( $not_completed, $order ): bool {
		if ( $not_completed ) {
			return true;
		}

		if ( ! $order instanceof \WC_Order || ! in_array( $order->get_payment_method(), self::GATEWAYS, true ) ) {
			return false;
		}

		return self::has_return_error();
	}

	/**
	 * "error" is a reserved WordPress query var that WP::parse_request()
	 * removes from $_GET, so it is read from the original request URI.
	 */
	private static function has_return_error(): bool {
		$query = [];
		wp_parse_str( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_QUERY ), $query );

		$error = $query['error'] ?? null;

		return is_string( $error ) && '' !== sanitize_key( $error );
	}
}
