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
		$this->logger->log( $level, $message, array_merge( $this->log_ctx, is_array( $context ) ? $context : [] ) );
	}
}
