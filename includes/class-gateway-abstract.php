<?php

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce abstract payment gateway shared by the PayU Pay by Link and
 * PayU card-recurring gateways in this plugin.
 */
abstract class Pethelp_Gateway_PayU_Abstract extends WC_Payment_Gateway {

	/** @var \WC_Logger */
	protected $logger;

	/** @var array */
	protected $log_ctx = [ 'source' => 'pethelp-payu-cards' ];

	public function __construct() {
		$this->logger = wc_get_logger();

		add_action( 'admin_footer', [ $this, 'admin_footer_js' ] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	protected function get_credential( string $key ): string {
		$prefix = $this->get_option( 'sandbox' ) === 'yes' ? 'sandbox_' : '';
		return (string) $this->get_option( $prefix . $key, '' );
	}

	protected function build_api(): Pethelp_PayU_Cards_API {
		return new Pethelp_PayU_Cards_API(
			$this->get_credential( 'client_id' ),
			$this->get_credential( 'client_secret' ),
			$this->get_credential( 'pos_id' ),
			$this->get_option( 'sandbox' ) === 'yes'
		);
	}

	public function admin_footer_js(): void {
		$screen = get_current_screen();

		if ( ! $screen || $screen->id !== 'woocommerce_page_wc-settings' ) {
			return;
		}

		$gw_id = esc_js( $this->id );

		?>
		<script>
			(function ($) {
				var id       = '<?php echo $gw_id; ?>';
				var $cb      = $('#woocommerce_' + id + '_sandbox');
				var $sandbox = $('#woocommerce_' + id + '_sandbox_pos_id,' +
								'#woocommerce_' + id + '_sandbox_client_id,' +
								'#woocommerce_' + id + '_sandbox_client_secret,' +
								'#woocommerce_' + id + '_sandbox_second_key').closest('tr');
				var $prod    = $('#woocommerce_' + id + '_pos_id,' +
								'#woocommerce_' + id + '_client_id,' +
								'#woocommerce_' + id + '_client_secret,' +
								'#woocommerce_' + id + '_second_key').closest('tr');

				function toggle() {
					var isSandbox = $cb.is(':checked');
					$sandbox.toggle(isSandbox);
					$prod.toggle(!isSandbox);
				}

				$cb.on('change', toggle);
				toggle();
			}(jQuery));
		</script>
		<?php
	}

	/**
	 * Whether a verified notification (signature and PayU order id already match) must
	 * be ignored, so a delayed, duplicated or inconsistent one never changes the order:
	 * - COMPLETED with an amount or currency other than the PayU order was created with;
	 * - COMPLETED for an order that is already paid (duplicate);
	 * - CANCELED / REJECTED for an order that is already paid.
	 */
	protected function is_stale_notification( \WC_Order $order, string $payu_status, array $notification ): bool {
		$payu_order_id = (string) ( $notification['order']['orderId'] ?? '' );

		if ( $payu_status === 'COMPLETED' ) {
			$expected = (string) $order->get_meta( '_pethelp_payu_total_amount' );
			if ( $expected === '' ) {
				$expected = Pethelp_PayU_Cards_API::to_payu_amount( (float) $order->get_total() );
			}

			$amount   = (string) ( $notification['order']['totalAmount'] ?? '' );
			$currency = (string) ( $notification['order']['currencyCode'] ?? '' );

			if ( $amount !== $expected || ( $currency !== '' && $currency !== $order->get_currency() ) ) {
				$this->log( sprintf(
					'[order #%d]: notyfikacja COMPLETED odrzucona – kwota %s %s, oczekiwano %s %s (PayU Order ID %s).',
					$order->get_id(), $amount, $currency, $expected, $order->get_currency(), $payu_order_id
				), [], 'error' );
				$order->add_order_note( sprintf(
					__( 'PayU: zignorowano notyfikację COMPLETED – kwota %1$s nie zgadza się ze zleceniem (%2$s, w groszach). Zamówienie nie zostało oznaczone jako opłacone.', 'pethelp-payu-cards' ),
					$amount,
					$expected
				) );
				return true;
			}

			if ( $order->is_paid() ) {
				$this->log( sprintf( '[order #%d]: zduplikowana notyfikacja COMPLETED (PayU Order ID %s) – zamówienie jest już opłacone.', $order->get_id(), $payu_order_id ) );
				return true;
			}
		}

		if ( in_array( $payu_status, [ 'CANCELED', 'REJECTED' ], true ) && $order->is_paid() ) {
			$this->log( sprintf( '[order #%d]: zignorowano notyfikację %s (PayU Order ID %s) – zamówienie jest już opłacone.', $order->get_id(), $payu_status, $payu_order_id ), [], 'error' );
			$order->add_order_note( sprintf(
				__( 'PayU: zignorowano notyfikację %s dla opłaconego zamówienia.', 'pethelp-payu-cards' ),
				$payu_status
			) );
			return true;
		}

		return false;
	}

	protected function get_payu_products( \WC_Order $order ): array {
		$products = [];

		foreach ( $order->get_items() as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$qty        = max( 1, $item->get_quantity() );
			$unit_price = Pethelp_PayU_Cards_API::to_payu_amount( (float) $item->get_total() / $qty );

			$products[] = [
				'name'      => $item->get_name(),
				'unitPrice' => $unit_price,
				'quantity'  => (string) $qty,
			];
		}

		$shipping = (float) $order->get_shipping_total();
		if ( $shipping > 0 ) {
			$products[] = [
				'name'      => __( 'Wysyłka', 'pethelp-payu-cards' ),
				'unitPrice' => Pethelp_PayU_Cards_API::to_payu_amount( $shipping ),
				'quantity'  => '1',
			];
		}

		return $products;
	}

	/**
	 * Standard credential fields shared by every gateway in this plugin.
	 * Merge into a gateway's init_form_fields() form_fields array.
	 */
	protected function credential_form_fields(): array {
		return [
			'sandbox' => [
				'title'   => __( 'Tryb testowy (sandbox)', 'pethelp-payu-cards' ),
				'type'    => 'checkbox',
				'label'   => __( 'Włącz środowisko testowe PayU', 'pethelp-payu-cards' ),
				'default' => 'yes',
			],
			// --- Produkcja ---
			'pos_id'                => [
				'title'       => __( 'POS ID (produkcja)', 'pethelp-payu-cards' ),
				'type'        => 'text',
				'description' => __( 'merchantPosId z panelu PayU dla środowiska produkcyjnego.', 'pethelp-payu-cards' ),
			],
			'second_key'            => [
				'title'       => __( 'Drugi klucz MD5 (produkcja)', 'pethelp-payu-cards' ),
				'type'        => 'text',
				'description' => __( 'Klucz do weryfikacji podpisu notyfikacji PayU.', 'pethelp-payu-cards' ),
			],
			'client_id'             => [
				'title' => __( 'Client ID OAuth (produkcja)', 'pethelp-payu-cards' ),
				'type'  => 'text',
			],
			'client_secret'         => [
				'title' => __( 'Client Secret OAuth (produkcja)', 'pethelp-payu-cards' ),
				'type'  => 'text',
			],
			// --- Sandbox ---
			'sandbox_pos_id'        => [
				'title'       => __( 'POS ID (sandbox)', 'pethelp-payu-cards' ),
				'type'        => 'text',
				'description' => __( 'merchantPosId z panelu PayU dla środowiska testowego.', 'pethelp-payu-cards' ),
			],
			'sandbox_second_key'    => [
				'title'       => __( 'Drugi klucz MD5 (sandbox)', 'pethelp-payu-cards' ),
				'type'        => 'text',
				'description' => __( 'Klucz do weryfikacji podpisu notyfikacji PayU dla środowiska testowego.', 'pethelp-payu-cards' ),
			],
			'sandbox_client_id'     => [
				'title' => __( 'Client ID OAuth (sandbox)', 'pethelp-payu-cards' ),
				'type'  => 'text',
			],
			'sandbox_client_secret' => [
				'title' => __( 'Client Secret OAuth (sandbox)', 'pethelp-payu-cards' ),
				'type'  => 'text',
			],
		];
	}

	/**
	 * Message shown to the customer when PayU could not start a payment. The exception
	 * itself (it may carry a raw PayU API response) goes only to the log and the order note.
	 */
	public static function customer_payment_error(): string {
		return __( 'Nie udało się rozpocząć płatności w PayU. Spróbuj ponownie za chwilę lub wybierz inną metodę płatności.', 'pethelp-payu-cards' );
	}

	/**
	 * Log Levels
	 *
	 * Description of levels:
	 *     'emergency': System is unusable.
	 *     'alert': Action must be taken immediately.
	 *     'critical': Critical conditions.
	 *     'error': Error conditions.
	 *     'warning': Warning conditions.
	 *     'notice': Normal but significant condition.
	 *     'info': Informational messages.
	 *     'debug': Debug-level messages.
	 *
	 * @see @link {https://tools.ietf.org/html/rfc5424}
	 */
	protected function log( $message, $context = [], $level = 'debug' ) {
		$context = array_map( [ __CLASS__, 'redact' ], is_array( $context ) ? $context : [] );

		$this->logger->log( $level, self::redact( (string) $message ), array_merge( $this->log_ctx, $context ) );
	}

	/**
	 * Masks card tokens and buyer data before anything reaches the log (payloads, API
	 * responses and raw notifications are logged as JSON). Order ids, statuses, amounts,
	 * decline codes and masked card numbers stay – they are needed for diagnostics.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function redact( $value ) {
		if ( is_array( $value ) ) {
			return array_map( [ __CLASS__, 'redact' ], $value );
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		// PayU tokens (TOK_ single-use, TOKC_ reusable): prefix and last 4 characters only.
		$value = preg_replace_callback(
			'/\b(TOKC?_)([A-Za-z0-9_-]+)/',
			function ( $match ) {
				return $match[1] . '***' . substr( $match[2], -4 );
			},
			$value
		);

		// Buyer data in JSON payloads and notifications (also with escaped quotes and
		// \uXXXX escapes, which json_encode uses for Polish characters).
		$value = preg_replace(
			'/(\\\\?"(?:email|phone|firstName|lastName|customerIp)\\\\?"\s*:\s*\\\\?")(?:[^"\\\\]|\\\\{1,2}u[0-9a-fA-F]{4})*(\\\\?")/',
			'$1***$2',
			$value
		);

		// Any other e-mail address.
		return preg_replace( '/[A-Za-z0-9._%+-]+@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/', '***@$1', $value );
	}
}
