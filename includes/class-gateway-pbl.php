<?php

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce payment gateway – one-time PayU Pay by Link.
 *
 * Standard PayU redirect flow: create order, redirect the customer to
 * PayU's hosted `redirectUri`, settle on a verified notification.
 */
class Pethelp_Gateway_PayU_PBL extends Pethelp_Gateway_PayU_Abstract {

	public function __construct() {
		$this->id                 = 'payu_pbl';
		$this->method_title       = __( 'PayU – Pay by Link', 'pethelp-payu-cards' );
		$this->method_description = __( 'Jednorazowa płatność PayU – klient jest przekierowywany do hostowanej strony płatności PayU.', 'pethelp-payu-cards' );
		$this->has_fields         = false;
		$this->supports           = [ 'products', 'refunds' ];

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', 'PayU' );
		$this->description = $this->get_option( 'description', '' );
		$this->enabled      = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'woocommerce_api_' . $this->id, [ $this, 'handle_notification' ] );

		parent::__construct();
	}

	// -------------------------------------------------------------------------
	// Admin settings
	// -------------------------------------------------------------------------

	public function init_form_fields(): void {
		$this->form_fields = array_merge(
			[
				'enabled'     => [
					'title'   => __( 'Włącz/Wyłącz', 'pethelp-payu-cards' ),
					'type'    => 'checkbox',
					'label'   => __( 'Włącz płatność PayU – Pay by Link', 'pethelp-payu-cards' ),
					'default' => 'no',
				],
				'title'       => [
					'title'   => __( 'Tytuł', 'pethelp-payu-cards' ),
					'type'    => 'text',
					'default' => 'PayU',
				],
				'description' => [
					'title'   => __( 'Opis', 'pethelp-payu-cards' ),
					'type'    => 'textarea',
					'default' => __( 'Zapłać przelewem, kartą lub BLIK-iem przez PayU – zostaniesz przekierowany do bezpiecznej strony płatności.', 'pethelp-payu-cards' ),
				],
			],
			$this->credential_form_fields()
		);
	}

	// -------------------------------------------------------------------------
	// Payment processing
	// -------------------------------------------------------------------------

	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );

		try {
			$api     = $this->build_api();
			$payload = $this->build_order_payload( $order );

			$this->log( sprintf( 'PBL [order #%d]: wysyłam payload=%s', $order_id, wp_json_encode( $payload ) ) );

			$result = $api->create_order( $payload );

			$this->log( sprintf( 'PBL [order #%d]: odpowiedź PayU – %s', $order_id, wp_json_encode( $result ) ) );

			if ( empty( $result['orderId'] ) || empty( $result['redirectUri'] ) ) {
				throw new Exception( __( 'PayU nie zwróciło identyfikatora zamówienia lub adresu przekierowania.', 'pethelp-payu-cards' ) );
			}

			$order->update_meta_data( '_pethelp_payu_order_id', $result['orderId'] );
			$order->update_meta_data( '_pethelp_payu_status', $result['status']['statusCode'] ?? 'PENDING' );
			$order->save();

			$order->update_status(
				'pending',
				sprintf( __( 'Oczekiwanie na płatność PayU. PayU Order ID: %s', 'pethelp-payu-cards' ), $result['orderId'] )
			);

			if ( WC()->cart ) {
				WC()->cart->empty_cart();
			}

			return [
				'result'   => 'success',
				'redirect' => $result['redirectUri'],
			];

		} catch ( Exception $e ) {
			$this->log( sprintf( 'PBL [order #%d]: błąd – %s', $order_id, $e->getMessage() ), [], 'error' );
			wc_add_notice( $e->getMessage(), 'error' );
			$order->add_order_note( 'Błąd PayU Pay by Link: ' . $e->getMessage() );
			return [ 'result' => 'failure' ];
		}
	}

	/**
	 * @return bool|\WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order         = wc_get_order( $order_id );
		$payu_order_id = $order->get_meta( '_pethelp_payu_order_id' );

		if ( ! $payu_order_id ) {
			return new \WP_Error( 'payu_refund', __( 'Brak ID zamówienia PayU – zwrot niemożliwy.', 'pethelp-payu-cards' ) );
		}

		try {
			$this->build_api()->refund_order(
				$payu_order_id,
				(float) $amount,
				$reason ?: __( 'Zwrot', 'pethelp-payu-cards' )
			);

			$order->add_order_note(
				sprintf( __( 'Zwrot PayU Pay by Link: %.2f %s', 'pethelp-payu-cards' ), $amount, $order->get_currency() )
			);

			return true;

		} catch ( Exception $e ) {
			return new \WP_Error( 'payu_refund', $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// PayU notification webhook  (?wc-api=payu_pbl&order_id=123)
	// -------------------------------------------------------------------------

	public function handle_notification(): void {
		$raw_body     = file_get_contents( 'php://input' );
		$notification = json_decode( $raw_body, true );

		if ( empty( $notification ) ) {
			$this->log( 'PBL: notyfikacja – puste body.', [], 'error' );
			status_header( 400 );
			exit( 'Bad Request' );
		}

		$sig_header = $_SERVER['HTTP_OPENPAYU_SIGNATURE'] ?? '';
		if ( ! Pethelp_PayU_Cards_API::verify_notification( $raw_body, $sig_header, $this->get_credential( 'second_key' ) ) ) {
			$this->log( sprintf( 'PBL: nieprawidłowy podpis notyfikacji. Signature: %s', $sig_header ), [], 'error' );
			status_header( 401 );
			exit( 'Invalid signature' );
		}

		$order_id = absint( $_GET['order_id'] ?? 0 );
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			$this->log( sprintf( 'PBL: notyfikacja dla nieznanego zamówienia #%d.', $order_id ), [], 'error' );
			status_header( 404 );
			exit( 'Order not found' );
		}

		$payu_status    = $notification['order']['status'] ?? '';
		$payu_order_id  = $notification['order']['orderId'] ?? '—';

		if ( $order->get_meta( '_pethelp_payu_order_id' ) != $payu_order_id ) {
			$this->log( sprintf(
				'PBL [order #%d]: notyfikacja PayU zawiera błędne payu_order_id – status=%s payu_order_id=%s raw_body=%s',
				$order_id,
				$payu_status,
				$payu_order_id,
				$raw_body
			), [ 'raw_body' => $raw_body ], 'error' );

			status_header( 200 );
			exit( 'OK' );
		}

		$this->log( sprintf(
			'PBL [order #%d]: notyfikacja PayU – status=%s payu_order_id=%s raw_body=%s',
			$order_id,
			$payu_status,
			$payu_order_id,
			$raw_body
		), [ 'raw_body' => $raw_body ] );

		$this->apply_payu_status( $order, $payu_status, $notification );

		status_header( 200 );
		exit( 'OK' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function build_order_payload( \WC_Order $order ): array {
		$notify_url = add_query_arg(
			[ 'wc-api' => $this->id, 'order_id' => $order->get_id() ],
			home_url( '/' )
		);

		return [
			'notifyUrl'     => $notify_url,
			'continueUrl'   => $order->get_checkout_order_received_url(),
			'customerIp'    => $order->get_customer_ip_address() ?: ( $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' ),
			'merchantPosId' => $this->get_credential( 'pos_id' ),
			'description'   => sprintf(
				/* translators: 1: order number, 2: shop name */
				__( 'Zamówienie #%1$s – %2$s', 'pethelp-payu-cards' ),
				$order->get_order_number(),
				get_bloginfo( 'name' )
			),
			'currencyCode'  => $order->get_currency(),
			'totalAmount'   => Pethelp_PayU_Cards_API::to_payu_amount( (float) $order->get_total() ),
			'extOrderId'    => 'wc_pbl_' . $order->get_id() . '_' . time(),
			'buyer'         => [
				'email'     => $order->get_billing_email(),
				'phone'     => $order->get_billing_phone(),
				'firstName' => $order->get_billing_first_name(),
				'lastName'  => $order->get_billing_last_name(),
				'language'  => 'pl',
			],
			'products'      => $this->get_payu_products( $order ),
			// PBL: full hosted payment-method selection page.
			'payMethods'    => [
				'payMethod' => [
					'type'  => 'PBL',
					'value' => 'ai',
				],
			],
		];
	}

	private function apply_payu_status( \WC_Order $order, string $payu_status, array $notification ): void {
		switch ( $payu_status ) {
			case 'COMPLETED':
				$order->payment_complete( $notification['order']['orderId'] ?? '' );
				$order->add_order_note( __( 'Płatność PayU zakończona pomyślnie.', 'pethelp-payu-cards' ) );
				break;

			case 'CANCELED':
			case 'REJECTED':
				if ( ! $order->has_status( [ 'failed', 'cancelled', 'refunded' ] ) ) {
					$order->update_status( 'failed', __( 'Płatność PayU odrzucona.', 'pethelp-payu-cards' ) );
				}
				break;

			case 'PENDING':
			case 'WAITING_FOR_CONFIRMATION':
				// No action needed – order already in pending.
				break;
		}
	}
}
