<?php

defined( 'ABSPATH' ) || exit;

class Pethelp_PayU_Admin_Subscription_Fields {

	const GATEWAY_ID = 'payu_card_recurring';
	const NONCE_NAME = 'pethelp_payu_admin_token_nonce';

	public static function init(): void {
		add_action( 'woocommerce_admin_order_data_after_billing_address', [ __CLASS__, 'render_fields' ] );
		add_action( 'woocommerce_process_shop_subscription_meta', [ __CLASS__, 'save_fields' ], 10, 2 );
		add_action( 'admin_footer', [ __CLASS__, 'admin_footer_js' ] );
	}

	public static function render_fields( $subscription ): void {
		if ( ! $subscription instanceof \WC_Subscription || $subscription->get_payment_method() !== self::GATEWAY_ID ) {
			return;
		}

		$current_token_id = (int) $subscription->get_meta( '_pethelp_payu_token_id' );
		$tokens           = array_filter(
			Pethelp_PayU_Token_Repository::get_active_for_user( (int) $subscription->get_customer_id() ),
			function ( $token ) use ( $current_token_id, $subscription ) {
				return $subscription->get_id() === (int)  $token['current_subscription_id'];
			}
		);

		$change_card_url = Pethelp_PayU_Card_Change_Page::get_url( $subscription->get_id() );

		wp_nonce_field( 'pethelp_payu_admin_token_' . $subscription->get_id(), self::NONCE_NAME );
		?>
		<div class="pethelp-payu-admin-card-panel" style="clear:both;padding-top:12px;margin-top:12px;border-top:1px dashed #ccc;">
			<h4><?php esc_html_e( 'PayU – karta płatnicza', 'pethelp-payu-cards' ); ?></h4>

			<p class="form-field" style="width:100%;">
				<label for="pethelp_payu_admin_token_id"><?php esc_html_e( 'Przypisana karta', 'pethelp-payu-cards' ); ?></label>
				<select id="pethelp_payu_admin_token_id" name="pethelp_payu_admin_token_id" style="width:100%;">
					<option value="0"><?php esc_html_e( '— brak —', 'pethelp-payu-cards' ); ?></option>
					<?php foreach ( $tokens as $token ) : ?>
						<option value="<?php echo (int) $token['id']; ?>" <?php selected( (int) $token['id'], $current_token_id ); ?>>
							<?php
							echo esc_html( sprintf(
								'%s %s%s%s',
								$token['masked_card'] ?: '••••',
								$token['card_brand'] ? '(' . $token['card_brand'] . ') ' : '',
								( $token['exp_month'] && $token['exp_year'] ) ? sprintf( '– %02d/%d ', (int) $token['exp_month'], (int) $token['exp_year'] ) : '',
								$token['status'] !== Pethelp_PayU_Token_Repository::STATUS_ACTIVE ? '– ' . $token['status'] : ''
							) );
							?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="form-field" style="width:100%;margin-bottom:12px;">
				<button type="button" class="button pethelp-payu-copy-link" data-link="<?php echo esc_url( $change_card_url ); ?>">
					<?php esc_html_e( 'Kopiuj link zmiany karty', 'pethelp-payu-cards' ); ?>
				</button>
				<span class="pethelp-payu-copy-status" style="margin-left:6px;color:#1a9c53;display:none;"><?php esc_html_e( 'Skopiowano!', 'pethelp-payu-cards' ); ?></span>
			</p>
		</div>
		<?php
	}

	public static function save_fields( int $subscription_id, $post ): void {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), 'pethelp_payu_admin_token_' . $subscription_id ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( ! isset( $_POST['pethelp_payu_admin_token_id'] ) ) {
			return;
		}

		$subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription_id ) : null;

		if ( ! $subscription instanceof \WC_Subscription || $subscription->get_payment_method() !== self::GATEWAY_ID ) {
			return;
		}

		$token_id = absint( $_POST['pethelp_payu_admin_token_id'] );

		if ( ! $token_id ) {
			return;
		}

		$token = Pethelp_PayU_Token_Repository::get( $token_id );

		if ( ! $token || (int) $token['user_id'] !== (int) $subscription->get_customer_id() ) {
			return;
		}

		Pethelp_PayU_Token_Repository::assign_to_subscription( $token_id, $subscription );
	}

	public static function admin_footer_js(): void {
		$screen = get_current_screen();

		if ( ! $screen || $screen->id !== 'shop_subscription' ) {
			return;
		}
		?>
		<script>
		(function () {
			document.addEventListener('click', function (e) {
				var btn = e.target.closest && e.target.closest('.pethelp-payu-copy-link');
				if (!btn) { return; }
				e.preventDefault();

				var link = btn.getAttribute('data-link');
				var status = btn.parentElement.querySelector('.pethelp-payu-copy-status');

				function shown() {
					if (!status) { return; }
					status.style.display = 'inline';
					setTimeout(function () { status.style.display = 'none'; }, 2000);
				}

				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(link).then(shown);
				} else {
					var tmp = document.createElement('input');
					tmp.value = link;
					document.body.appendChild(tmp);
					tmp.select();
					document.execCommand('copy');
					document.body.removeChild(tmp);
					shown();
				}
			});
		}());
		</script>
		<?php
	}
}
