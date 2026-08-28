<?php

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce payment gateway – PayU recurring card payments via
 * multi-use tokens stored in the local token catalog.
 */
class Pethelp_Gateway_PayU_Card_Recurring extends Pethelp_Gateway_PayU_Abstract {

	public const DEFAULT_PERMANENT_DECLINE_CODES = [ 'S132' ];

	public function __construct() {
		$this->id                 = 'payu_card_recurring';
		$this->method_title       = __( 'PayU – płatność cykliczna kartą', 'pethelp-payu-cards' );
		$this->method_description = __( 'Automatyczne płatności cykliczne kartą przez PayU (tokeny wielokrotnego użytku). Wymaga WooCommerce Subscriptions.', 'pethelp-payu-cards' );
		$this->has_fields         = true;

		$this->supports = [
			'products',
			'refunds',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'multiple_subscriptions',
		];

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', 'Karta płatnicza' );
		$this->description = $this->get_option( 'description', '' );
		$this->enabled      = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'woocommerce_api_' . $this->id, [ $this, 'handle_notification' ] );
		add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, [ $this, 'scheduled_subscription_payment' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

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
					'label'   => __( 'Włącz płatność cykliczną kartą PayU', 'pethelp-payu-cards' ),
					'default' => 'no',
				],
				'title'       => [
					'title'   => __( 'Tytuł', 'pethelp-payu-cards' ),
					'type'    => 'text',
					'default' => 'Karta płatnicza',
				],
				'description' => [
					'title'   => __( 'Opis', 'pethelp-payu-cards' ),
					'type'    => 'textarea',
					'default' => __( 'Płatność kartą. Aktywujesz usługę jednorazowo – kolejne opłaty pobierane są automatycznie z zapisanej karty.', 'pethelp-payu-cards' ),
				],
				'permanent_decline_codes' => [
					'title'       => __( 'Kody trwałej odmowy', 'pethelp-payu-cards' ),
					'type'        => 'text',
					'description' => __( 'Lista kodów odmowy PayU (po przecinku), przy których token karty jest unieważniany i automatyczne ponowienia są blokowane, np. S132.', 'pethelp-payu-cards' ),
					'default'     => implode( ',', self::DEFAULT_PERMANENT_DECLINE_CODES ),
				],
			],
			$this->credential_form_fields()
		);
	}

	/**
	 * @return array<int,string>
	 */
	protected function get_permanent_decline_codes(): array {
		$raw = (string) $this->get_option( 'permanent_decline_codes', implode( ',', self::DEFAULT_PERMANENT_DECLINE_CODES ) );

		$codes = array_filter( array_map( 'trim', explode( ',', $raw ) ) );

		return $codes ?: self::DEFAULT_PERMANENT_DECLINE_CODES;
	}

	/**
	 * Public wrapper around the widget config builder so other parts of the
	 * plugin (the dedicated card-change page's "add new card" option, which
	 * needs a zero-value tokenization widget) can reuse this gateway's
	 * credentials without needing access to the protected get_credential().
	 */
	public function get_widget_config( float $amount, string $email ): array {
		return Pethelp_PayU_Widget_Helper::build( [
			'pos_id'     => $this->get_credential( 'pos_id' ),
			'second_key' => $this->get_credential( 'second_key' ),
			'sandbox'    => $this->get_option( 'sandbox' ) === 'yes',
			'amount'     => $amount,
			'currency'   => get_woocommerce_currency(),
			'email'      => $email,
		] );
	}

	/**
	 * Upgrades a single-use widget token (`TOK_...`) into a genuine
	 * multi-use card token (`TOKC_...`) by creating a zero-amount,
	 * no-purchase order, per PayU's docs:
	 * https://developers.payu.com/europe/pl/docs/payment-solutions/cards/tokenization/create-token/#token-create-no-purchase-multi-use
	 *
	 * The widget's own success-callback value is single-use and must NOT
	 * be stored directly as a reusable token – only the `payMethods.payMethod.value`
	 * in THIS call's response (type CARD_TOKEN, prefixed TOKC_) is reusable.
	 *
	 * Returns the raw decoded order response; the caller passes it to
	 * Pethelp_PayU_Token_Repository::create_from_order_response().
	 *
	 * @throws Exception On API error, or if PayU asks for an additional
	 *                    3DS/CVV step (status WARNING_CONTINUE_3DS /
	 *                    WARNING_CONTINUE_CVV) – not expected here since the
	 *                    widget already completes 3DS itself before calling
	 *                    back, but surfaced rather than silently mishandled
	 *                    if it ever does occur.
	 */
	/**
	 * @param string $continue_url Where PayU sends the customer back after
	 *                             a 3DS/CVV challenge (see handle 3DS below).
	 * @return array Raw decoded order response. Callers must check
	 *               `status.statusCode`: WARNING_CONTINUE_3DS / WARNING_CONTINUE_CVV
	 *               means the token in `payMethods.payMethod.value` is
	 *               provisional – the customer must complete the challenge
	 *               at `redirectUri` before the order (and token) is final.
	 *               Confirmed live against this merchant's sandbox: the
	 *               "no purchase" zero-amount tokenization order still
	 *               triggers a real 3DS2 challenge (WARNING_CONTINUE_3DS +
	 *               redirectUri), it is NOT synchronous/immediate.
	 * @throws Exception On API error, or a response with neither a token
	 *                    nor a redirect to follow.
	 */
	public function create_multi_use_token_order( string $single_use_token, int $user_id, string $email, string $first_name, string $last_name, string $continue_url ): array {
		$api = $this->build_api();

		$payload = [
			'notifyUrl'     => home_url( '/' ),
			'continueUrl'   => $continue_url,
			'customerIp'    => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' ),
			'merchantPosId' => $this->get_credential( 'pos_id' ),
			'description'   => sprintf(
				/* translators: shop name */
				__( 'Rejestracja karty – %s', 'pethelp-payu-cards' ),
				get_bloginfo( 'name' )
			),
			'currencyCode'  => get_woocommerce_currency(),
			'totalAmount'   => '0001',
			// Required for a no-purchase order to mint a reusable token
			// instead of just processing a (non-existent) payment.
			'cardOnFile'    => 'FIRST',
			'extOrderId'    => 'pethelp_tokenize_' . $user_id . '_' . time(),
			'buyer'         => [
				'extCustomerId' => $this->get_ext_customer_id( $user_id ),
				'email'         => $email,
				'firstName'     => $first_name,
				'lastName'      => $last_name,
				'language'      => 'pl',
			],
			'payMethods'    => [
				'payMethod' => [
					'type'  => 'CARD_TOKEN',
					'value' => $single_use_token,
				],
			],
		];

		$this->log( sprintf( 'Karta cykliczna: rejestracja tokenu MULTI – payload=%s', wp_json_encode( $payload ) ) );

		$result = $api->create_order( $payload );

		$this->log( sprintf( 'Karta cykliczna: odpowiedź PayU (rejestracja tokenu) – %s', wp_json_encode( $result ) ) );

		$status_code = $result['status']['statusCode'] ?? '';
		$has_token   = ! empty( $result['payMethods']['payMethod']['value'] );
		$has_redirect = ! empty( $result['redirectUri'] );

		if ( in_array( $status_code, [ 'WARNING_CONTINUE_3DS', 'WARNING_CONTINUE_CVV' ], true ) && $has_redirect ) {
			return $result; // Caller must redirect to $result['redirectUri'].
		}

		if ( ! $has_token ) {
			throw new Exception( __( 'PayU nie zwróciło tokenu karty.', 'pethelp-payu-cards' ) );
		}

		return $result;
	}

	/**
	 * Fetches the current state of a PayU order – used after the customer
	 * returns from a 3DS/CVV redirect to confirm the tokenization order's
	 * final outcome.
	 *
	 * @throws Exception On API error.
	 */
	public function fetch_order( string $payu_order_id ): array {
		return $this->build_api()->get_order( $payu_order_id );
	}

	// -------------------------------------------------------------------------
	// Checkout form – PayU tokenization widget
	// -------------------------------------------------------------------------

	public function enqueue_scripts(): void {
		if ( ! is_checkout() ) {
			return;
		}

		wp_enqueue_script(
			'pethelp-payu-tokenize-widget',
			PETHELP_PAYU_CARDS_URL . 'assets/js/payu-tokenize-widget.js',
			[ 'jquery' ],
			PETHELP_PAYU_CARDS_VERSION,
			true
		);
	}

	public function payment_fields(): void {
		if ( $this->description ) {
			echo '<p class="pethelp-payu-desc">' . esc_html( $this->description ) . '</p>';
		}

		$order_id = absint( get_query_var( 'order-pay' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		$amount   = $order ? (float) $order->get_total() : (float) ( WC()->cart ? WC()->cart->get_total( 'edit' ) : 0 );
		$email    = $order ? $order->get_billing_email() : ( WC()->checkout() ? WC()->checkout()->get_value( 'billing_email' ) : '' );

		$widget = $this->get_widget_config( $amount, (string) $email );

		?>
		<div class="pethelp-payu-card-fields" data-gateway-id="<?php echo esc_attr( $this->id ); ?>">
			<input type="hidden" name="pethelp_payu_token_type" class="pethelp-payu-token-type" value="" />
			<input type="hidden" name="pethelp_payu_token_value" class="pethelp-payu-token-value" value="" />
			<input type="hidden" name="pethelp_payu_masked_card" class="pethelp-payu-masked-card" value="" />
			<input type="hidden" name="pethelp_payu_widget_response" class="pethelp-payu-widget-response" value="" />
			<button type="button" style="display:none;" id="pethelp_payu_widget_trigger" class="pethelp-payu-widget-trigger"></button>
			<script
				src="<?php echo esc_url( $widget['widget_url'] ); ?>"
				pay-button="#pethelp_payu_widget_trigger"
				merchant-pos-id="<?php echo esc_attr( $widget['merchant_pos_id'] ); ?>"
				shop-name="<?php echo esc_attr( $widget['shop_name'] ); ?>"
				total-amount="<?php echo esc_attr( $widget['total_amount'] ); ?>"
				currency-code="<?php echo esc_attr( $widget['currency_code'] ); ?>"
				customer-language="<?php echo esc_attr( $widget['customer_language'] ); ?>"
				store-card="<?php echo esc_attr( $widget['store_card'] ); ?>"
				recurring-payment="<?php echo esc_attr( $widget['recurring_payment'] ); ?>"
				customer-email="<?php echo esc_attr( $widget['customer_email'] ); ?>"
				sig="<?php echo esc_attr( $widget['sig'] ); ?>"
				success-callback="pethelpPayuWidgetCallback"
			></script>
		</div>
		<?php
	}

	public function validate_fields(): bool {
		if ( empty( $_POST['pethelp_payu_token_value'] ) ) {
			wc_add_notice( __( 'Nie udało się zweryfikować karty płatniczej. Spróbuj ponownie.', 'pethelp-payu-cards' ), 'error' );
			return false;
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Initial (FIRST) payment processing
	// -------------------------------------------------------------------------

	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );

		$widget_token = [
			'type'        => sanitize_text_field( wp_unslash( $_POST['pethelp_payu_token_type'] ?? '' ) ),
			'value'       => sanitize_text_field( wp_unslash( $_POST['pethelp_payu_token_value'] ?? '' ) ),
			'masked_card' => sanitize_text_field( wp_unslash( $_POST['pethelp_payu_masked_card'] ?? '' ) ),
		];
		$raw_response = json_decode( wp_unslash( $_POST['pethelp_payu_widget_response'] ?? '' ), true ) ?: [];

		try {
			if ( empty( $widget_token['value'] ) ) {
				throw new Exception( __( 'Brak danych tokenizacji karty. Spróbuj ponownie.', 'pethelp-payu-cards' ) );
			}

			$api     = $this->build_api();
			$payload = $this->build_charge_payload( $order, $widget_token['value'], 'FIRST' );

			$this->log( sprintf( 'Karta cykliczna [order #%d]: wysyłam FIRST payload=%s', $order_id, wp_json_encode( $payload ) ) );

			$result = $api->create_order( $payload );

			$this->log( sprintf( 'Karta cykliczna [order #%d]: odpowiedź PayU FIRST – %s', $order_id, wp_json_encode( $result ) ) );

			if ( empty( $result['orderId'] ) ) {
				throw new Exception( __( 'PayU nie zwróciło identyfikatora zamówienia.', 'pethelp-payu-cards' ) );
			}

			$payu_order_id = $result['orderId'];

			$token_id = Pethelp_PayU_Token_Repository::create_from_order_response(
				(int) $order->get_customer_id(),
				$result
			);

			$order->update_meta_data( '_pethelp_payu_order_id', $payu_order_id );
			$order->update_meta_data( '_pethelp_payu_status', $result['status']['statusCode'] ?? 'PENDING' );
			$order->update_meta_data( '_pethelp_payu_used_token_id', $token_id );
			$order->save();

			if ( $token_id && function_exists( 'wcs_get_subscriptions_for_order' ) ) {
				foreach ( wcs_get_subscriptions_for_order( $order_id, [ 'order_type' => 'parent' ] ) as $sub ) {
					Pethelp_PayU_Token_Repository::assign_to_subscription( $token_id, $sub );
				}
			}

			$order->update_status(
				'pending',
				sprintf( __( 'Oczekiwanie na potwierdzenie płatności kartą PayU. PayU Order ID: %s', 'pethelp-payu-cards' ), $payu_order_id )
			);
			
			if ( WC()->cart ) {
				WC()->cart->empty_cart();
			}

			// PayU may still require an extra redirect step (e.g. additional
			// issuer challenge) even after widget tokenization; follow it when present.
			$redirect = ! empty( $result['redirectUri'] ) ? $result['redirectUri'] : $order->get_checkout_order_received_url();

			return [
				'result'   => 'success',
				'redirect' => $redirect,
			];

		} catch ( Exception $e ) {
			$this->log( sprintf( 'Karta cykliczna [order #%d]: błąd pierwszej płatności – %s', $order_id, $e->getMessage() ), [], 'error' );
			wc_add_notice( $e->getMessage(), 'error' );
			$order->add_order_note( 'Błąd PayU – płatność cykliczna kartą (pierwsza płatność): ' . $e->getMessage() );
			return [ 'result' => 'failure' ];
		}
	}

	// -------------------------------------------------------------------------
	// Automated renewal payments (STANDARD) – also replayed by the site's
	// generic gateway-agnostic retry engine (Pethelp\PaymentsRetry), which
	// simply re-fires this same `woocommerce_scheduled_subscription_payment_*`
	// hook, so no bespoke retry handling is needed here.
	// -------------------------------------------------------------------------
	public function scheduled_subscription_payment( float $amount_to_charge, \WC_Order $renewal_order ): void {
		$subscriptions = function_exists( 'wcs_get_subscriptions_for_renewal_order' )
			? wcs_get_subscriptions_for_renewal_order( $renewal_order )
			: [];

		/** @var \WC_Subscription|null $subscription */
		$subscription = ! empty( $subscriptions ) ? reset( $subscriptions ) : null;

		if ( ! $subscription ) {
			$renewal_order->update_status( 'failed', __( 'PayU: nie znaleziono subskrypcji dla odnowienia.', 'pethelp-payu-cards' ) );
			return;
		}

		$token_id = (int) $subscription->get_meta( '_pethelp_payu_token_id' );
		$token    = $token_id ? Pethelp_PayU_Token_Repository::get( $token_id ) : null;

		if ( ! $token || $token['status'] !== Pethelp_PayU_Token_Repository::STATUS_ACTIVE ) {
			$this->log( sprintf( 'Karta cykliczna [order #%d]: brak aktywnego tokenu (token_id=%d) dla subskrypcji #%d.', $renewal_order->get_id(), $token_id, $subscription->get_id() ), [], 'error' );

			$renewal_order->update_status( 'failed', __( 'PayU: brak aktywnej, zapisanej karty. Klient musi przypisać nową kartę do subskrypcji.', 'pethelp-payu-cards' ) );

			if ( method_exists( $subscription, 'payment_failed' ) ) {
				$subscription->payment_failed();
			}
			return;
		}

		try {
			$api     = $this->build_api();
			$payload = $this->build_charge_payload( $renewal_order, $token['payu_token'], 'STANDARD', $amount_to_charge );

			$this->log( sprintf( 'Karta cykliczna [order #%d]: wysyłam STANDARD (token_id=%d) payload=%s', $renewal_order->get_id(), $token_id, wp_json_encode( $payload ) ) );

			$result = $api->create_order( $payload );

			$this->log( sprintf( 'Karta cykliczna [order #%d]: odpowiedź PayU – %s', $renewal_order->get_id(), wp_json_encode( $result ) ) );

			if ( empty( $result['orderId'] ) ) {
				throw new Exception( 'Brak orderId w odpowiedzi PayU. Response: ' . wp_json_encode( $result ) );
			}

			$renewal_order->update_meta_data( '_pethelp_payu_order_id', $result['orderId'] );
			$renewal_order->update_meta_data( '_pethelp_payu_status', $result['status']['statusCode'] ?? 'PENDING' );
			$renewal_order->update_meta_data( '_pethelp_payu_used_token_id', $token_id );
			$renewal_order->save();

			$renewal_order->add_order_note(
				sprintf( __( 'PayU: zainicjowano odnowienie kartą (token #%1$d). PayU Order ID: %2$s', 'pethelp-payu-cards' ), $token_id, $result['orderId'] )
			);

		} catch ( Exception $e ) {
			$this->handle_charge_failure( $renewal_order, $subscription, $token_id, $e );
		}
	}

	/**
	 * Handles a failed renewal/first-payment charge: logs it, and – only
	 * when the decline code is on the configured "permanent" list –
	 * invalidates the token and blocks further automatic retries.
	 * Transient declines are left alone: the site's generic retry engine
	 * (hooked on `woocommerce_order_status_failed`) already re-attempts via
	 * scheduled_subscription_payment().
	 */
	private function handle_charge_failure( \WC_Order $renewal_order, \WC_Subscription $subscription, int $token_id, Exception $e ): void {
		$decline_code = $e instanceof Pethelp_PayU_Cards_Exception ? $e->getCodeLiteral() : '';

		$this->log( sprintf(
			'Karta cykliczna [order #%d]: wyjątek – %s (kod=%s)',
			$renewal_order->get_id(),
			$e->getMessage(),
			$decline_code
		), [], 'error' );

		$is_permanent = $token_id && $decline_code && in_array( $decline_code, $this->get_permanent_decline_codes(), true );

		$renewal_order->update_status(
			'failed',
			sprintf( __( 'PayU: błąd odnowienia – %s', 'pethelp-payu-cards' ), $e->getMessage() )
		);

		if ( $is_permanent ) {
			Pethelp_PayU_Token_Repository::invalidate( $token_id, $decline_code );

			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( 'pethelp_retry_payment_attempt', [ 'order_id' => $renewal_order->get_id() ] );
			}

			$note = sprintf(
				__( 'PayU: trwała odmowa płatności (kod %1$s). Token #%2$d unieważniony, automatyczne ponowienia zablokowane. Klient musi przypisać nową kartę.', 'pethelp-payu-cards' ),
				$decline_code,
				$token_id
			);

			$renewal_order->add_order_note( $note );
			$subscription->add_order_note( $note );
		}

		if ( method_exists( $subscription, 'payment_failed' ) ) {
			$subscription->payment_failed();
		}
	}

	// -------------------------------------------------------------------------
	// Refunds
	// -------------------------------------------------------------------------

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
				sprintf( __( 'Zwrot PayU (karta cykliczna): %.2f %s', 'pethelp-payu-cards' ), $amount, $order->get_currency() )
			);

			return true;

		} catch ( Exception $e ) {
			return new \WP_Error( 'payu_refund', $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// PayU notification webhook  (?wc-api=payu_card_recurring&order_id=123)
	// -------------------------------------------------------------------------

	public function handle_notification(): void {
		$raw_body     = file_get_contents( 'php://input' );
		$notification = json_decode( $raw_body, true );

		if ( empty( $notification ) ) {
			$this->log( 'Karta cykliczna: notyfikacja – puste body.', [], 'error' );
			status_header( 400 );
			exit( 'Bad Request' );
		}

		$sig_header = $_SERVER['HTTP_OPENPAYU_SIGNATURE'] ?? '';
		if ( ! Pethelp_PayU_Cards_API::verify_notification( $raw_body, $sig_header, $this->get_credential( 'second_key' ) ) ) {
			$this->log( sprintf( 'Karta cykliczna: nieprawidłowy podpis notyfikacji. Signature: %s', $sig_header ), [], 'error' );
			status_header( 401 );
			exit( 'Invalid signature' );
		}

		$order_id = absint( $_GET['order_id'] ?? 0 );
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			$this->log( sprintf( 'Karta cykliczna: notyfikacja dla nieznanego zamówienia #%d.', $order_id ), [], 'error' );
			status_header( 404 );
			exit( 'Order not found' );
		}

		$payu_status   = $notification['order']['status'] ?? '';
		$payu_order_id = $notification['order']['orderId'] ?? '—';

		if ( $order->get_meta( '_pethelp_payu_order_id' ) != $payu_order_id ) {
			$this->log( sprintf(
				'Karta cykliczna [order #%d]: notyfikacja PayU zawiera błędne payu_order_id – status=%s payu_order_id=%s raw_body=%s',
				$order_id,
				$payu_status,
				$payu_order_id,
				$raw_body
			), [ 'raw_body' => $raw_body ], 'error' );

			status_header( 200 );
			exit( 'OK' );
		}

		$this->log( sprintf(
			'Karta cykliczna [order #%d]: notyfikacja PayU – status=%s payu_order_id=%s raw_body=%s',
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

	private function build_charge_payload( \WC_Order $order, string $token_value, string $recurring, ?float $amount_override = null ): array {
		$notify_url = add_query_arg(
			[ 'wc-api' => $this->id, 'order_id' => $order->get_id() ],
			home_url( '/' )
		);

		$amount = $amount_override !== null ? $amount_override : (float) $order->get_total();

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
			'totalAmount'   => Pethelp_PayU_Cards_API::to_payu_amount( $amount ),
			'extOrderId'    => 'wc_card_' . $order->get_id() . '_' . time(),
			'recurring'     => $recurring,
			'buyer'         => [
				'email'     	=> $order->get_billing_email(),
				'phone'     	=> $order->get_billing_phone(),
				'firstName' 	=> $order->get_billing_first_name(),
				'lastName'  	=> $order->get_billing_last_name(),
				'language'  	=> 'pl',
				'extCustomerId' => $this->get_ext_customer_id( $order->get_customer_id() ),
			],
			'products'      => $this->get_payu_products( $order ),
			'payMethods'    => [
				'payMethod' => [
					'type'  => 'CARD_TOKEN',
					'value' => $token_value,
				],
			],
		];
	}

	private function apply_payu_status( \WC_Order $order, string $payu_status, array $notification ): void {
		$is_renewal    = function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order );
		$payu_order_id = $notification['order']['orderId'] ?? '';

		switch ( $payu_status ) {
			case 'COMPLETED':
				$order->payment_complete( $payu_order_id );
				$order->add_order_note( __( 'Płatność kartą PayU zakończona pomyślnie.', 'pethelp-payu-cards' ) );

				if ( $is_renewal && function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
					foreach ( wcs_get_subscriptions_for_renewal_order( $order ) as $sub ) {
						if ( method_exists( $sub, 'payment_complete' ) ) {
							$sub->payment_complete();
						}
					}
				}
				break;

			case 'CANCELED':
			case 'REJECTED':
				if ( ! $order->has_status( [ 'failed', 'cancelled', 'refunded' ] ) ) {
					$order->update_status( 'failed', __( 'Płatność kartą PayU odrzucona.', 'pethelp-payu-cards' ) );
				}

				if ( $is_renewal && function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
					foreach ( wcs_get_subscriptions_for_renewal_order( $order ) as $sub ) {
						if ( method_exists( $sub, 'payment_failed' ) ) {
							$sub->payment_failed();
						}
					}
				}
				break;
		}
	}

	private function get_ext_customer_id( int $user_id ): string {
		return 'pethelp-customer-' . $user_id;
	}
}
