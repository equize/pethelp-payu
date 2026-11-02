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

			// A retry: the previous PayU order's link must stop working, so the customer
			// can't pay one order twice (or pay an order the shop no longer waits for).
			$this->cancel_payu_order( $api, $order, (string) $order->get_meta( '_pethelp_payu_order_id' ) );

			$this->log( sprintf( 'PBL [order #%d]: wysyłam payload=%s', $order_id, wp_json_encode( $payload ) ) );

			$result = $api->create_order( $payload );

			$this->log( sprintf( 'PBL [order #%d]: odpowiedź PayU – %s', $order_id, wp_json_encode( $result ) ) );

			if ( empty( $result['orderId'] ) || empty( $result['redirectUri'] ) ) {
				throw new Exception( __( 'PayU nie zwróciło identyfikatora zamówienia lub adresu przekierowania.', 'pethelp-payu-cards' ) );
			}

			$order->update_meta_data( '_pethelp_payu_order_ids', array_merge( $this->get_payu_order_ids( $order ), [ $result['orderId'] ] ) );
			$order->update_meta_data( '_pethelp_payu_order_id', $result['orderId'] );
			$order->update_meta_data( '_pethelp_payu_total_amount', $payload['totalAmount'] );
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
			wc_add_notice( self::customer_payment_error(), 'error' );
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
			if ( $payu_status === 'COMPLETED' && in_array( $payu_order_id, $this->get_payu_order_ids( $order ), true ) ) {
				$this->handle_previous_order_completed( $order, $notification );
				status_header( 200 );
				exit( 'OK' );
			}

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

		if ( ! $this->is_stale_notification( $order, $payu_status, $notification ) ) {
			$this->apply_payu_status( $order, $payu_status, $notification );
		}

		status_header( 200 );
		exit( 'OK' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Every PayU order created for this WC order, oldest first (the current one included).
	 * Orders from before this list existed only have the current id.
	 *
	 * @return string[]
	 */
	private function get_payu_order_ids( \WC_Order $order ): array {
		$ids = (array) $order->get_meta( '_pethelp_payu_order_ids' );
		$ids[] = (string) $order->get_meta( '_pethelp_payu_order_id' );

		return array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );
	}

	/**
	 * Cancels an unpaid PayU order. A failure (e.g. the customer has just paid it) never
	 * blocks the payment flow – a later COMPLETED for it is handled by
	 * handle_previous_order_completed().
	 */
	private function cancel_payu_order( Pethelp_PayU_Cards_API $api, \WC_Order $order, string $payu_order_id ): bool {
		if ( $payu_order_id === '' || $order->is_paid() ) {
			return false;
		}

		try {
			$api->cancel_order( $payu_order_id );
			$this->log( sprintf( 'PBL [order #%d]: anulowano zlecenie PayU %s.', $order->get_id(), $payu_order_id ) );
			return true;
		} catch ( Exception $e ) {
			$this->log( sprintf( 'PBL [order #%d]: nie udało się anulować zlecenia PayU %s – %s', $order->get_id(), $payu_order_id, $e->getMessage() ), [], 'error' );
			return false;
		}
	}

	/**
	 * COMPLETED for another PayU order of this WC order than the current one – the customer
	 * paid through an old payment link, or paid twice. The money is real, so it is never just
	 * ignored: an unpaid order gets paid by it, a paid one gets a note that the payment has to
	 * be refunded.
	 */
	private function handle_previous_order_completed( \WC_Order $order, array $notification ): void {
		$payu_order_id    = (string) $notification['order']['orderId'];
		$current_order_id = (string) $order->get_meta( '_pethelp_payu_order_id' );
		$amount           = (string) ( $notification['order']['totalAmount'] ?? '' );

		if ( $order->is_paid() ) {
			$this->log( sprintf( 'PBL [order #%d]: podwójna wpłata – opłacono też inne zlecenie PayU %s tego zamówienia (kwota %s).', $order->get_id(), $payu_order_id, $amount ), [], 'error' );
			$order->add_order_note( sprintf(
				__( 'PayU: klient opłacił także inne zlecenie tego zamówienia – %1$s (kwota %2$s w groszach), a zamówienie było już opłacone. Tę wpłatę trzeba zwrócić w panelu PayU.', 'pethelp-payu-cards' ),
				$payu_order_id,
				$amount
			) );
			return;
		}

		if ( $this->is_stale_notification( $order, 'COMPLETED', $notification ) ) {
			return;
		}

		$this->log( sprintf( 'PBL [order #%d]: zamówienie opłacone wcześniejszym zleceniem PayU %s (bieżące: %s).', $order->get_id(), $payu_order_id, $current_order_id ) );

		// The order is paid now – the newer PayU order must not take the money a second time.
		$canceled = $this->cancel_payu_order( $this->build_api(), $order, $current_order_id );

		// Refunds go to the PayU order that actually holds the money.
		$order->update_meta_data( '_pethelp_payu_order_id', $payu_order_id );
		$order->save();
		$order->add_order_note( sprintf(
			$canceled
				? __( 'PayU: zamówienie opłacone wcześniejszym zleceniem %1$s (link do płatności sprzed ponowienia). Nowsze zlecenie %2$s anulowano.', 'pethelp-payu-cards' )
				: __( 'PayU: zamówienie opłacone wcześniejszym zleceniem %1$s (link do płatności sprzed ponowienia). Nie udało się anulować nowszego zlecenia %2$s – sprawdź je w panelu PayU.', 'pethelp-payu-cards' ),
			$payu_order_id,
			$current_order_id
		) );

		$this->apply_payu_status( $order, 'COMPLETED', $notification );
	}

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
