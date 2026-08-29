<?php

defined( 'ABSPATH' ) || exit;

class Pethelp_PayU_Card_Change_Page {

	const QUERY_VAR           = 'pethelp-payu-change-card';
	const NONCE_ACTION_PREFIX = 'pethelp_payu_change_card_';
	const GATEWAY_ID          = 'payu_card_recurring';

	public static function init(): void {
		add_action( 'template_redirect', [ __CLASS__, 'maybe_handle' ] );
	}

	/**
	 * @param string|null $redirect Optional URL to send the customer back to
	 *                              once the card change is done/cancelled –
	 *                              validated against open-redirect on the way
	 *                              in (see get_safe_redirect()).
	 */
	public static function get_url( int $subscription_id, ?string $redirect = null ): string {
		$args = [
			self::QUERY_VAR    => 1,
			'subscription_id'  => $subscription_id,
			'_wpnonce'         => wp_create_nonce( self::NONCE_ACTION_PREFIX . $subscription_id ),
		];

		if ( $redirect ) {
			$args['redirect'] = $redirect;
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Reads & validates the "redirect" request param – wp_validate_redirect()
	 * keeps it to this site only, since it arrives via an untrusted query string.
	 */
	private static function get_safe_redirect(): ?string {
		$redirect = isset( $_REQUEST['redirect'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect'] ) ) : '';

		if ( ! $redirect ) {
			return null;
		}

		$validated = wp_validate_redirect( $redirect, '' );

		return $validated ?: null;
	}

	public static function maybe_handle(): void {
		if ( empty( $_REQUEST[ self::QUERY_VAR ] ) ) {
			return;
		}

		$subscription_id = absint( $_REQUEST['subscription_id'] ?? 0 );
		$nonce            = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ?? '' ) );

		if ( ! $subscription_id || ! wp_verify_nonce( $nonce, self::NONCE_ACTION_PREFIX . $subscription_id ) ) {
			wp_die( esc_html__( 'Nieprawidłowy lub wygasły link. Wygeneruj nowy link zmiany karty.', 'pethelp-payu-cards' ), '', [ 'response' => 403 ] );
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		$subscription = self::get_valid_subscription_for_current_user( $subscription_id );

		if ( ! $subscription ) {
			wp_die( esc_html__( 'Nie znaleziono subskrypcji, brak dostępu, lub zmiana karty jest dla niej niedostępna.', 'pethelp-payu-cards' ), '', [ 'response' => 403 ] );
		}

		$redirect = self::get_safe_redirect();

		// Customer returning from PayU's 3DS/CVV challenge page.
		if ( ! empty( $_GET['tokenize_pending'] ) ) {
			self::handle_continue( $subscription, $redirect );
			return;
		}

		if ( ! empty( $_POST['pethelp_payu_change_card_action'] ) ) {
			self::handle_submit( $subscription, $redirect );
			return;
		}

		self::render( $subscription, '', $redirect );
		exit;
	}

	private static function get_valid_subscription_for_current_user( int $subscription_id ): ?\WC_Subscription {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return null;
		}

		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! $subscription instanceof \WC_Subscription ) {
			return null;
		}

		if ( (int) $subscription->get_customer_id() !== get_current_user_id() ) {
			return null;
		}

		if ( $subscription->get_payment_method() !== self::GATEWAY_ID ) {
			return null;
		}

		if ( ! $subscription->has_status( [ 'active', 'on-hold' ] ) ) {
			return null;
		}

		return $subscription;
	}

	// -------------------------------------------------------------------------
	// Submit handling
	// -------------------------------------------------------------------------

	private static function handle_submit( \WC_Subscription $subscription, ?string $redirect ): void {
		$action = sanitize_text_field( wp_unslash( $_POST['pethelp_payu_change_card_action'] ) );
		$user_id = get_current_user_id();

		if ( $action === 'existing' ) {
			$token_id = absint( $_POST['token_id'] ?? 0 );
			$token    = $token_id ? Pethelp_PayU_Token_Repository::get( $token_id ) : null;

			if ( ! $token || (int) $token['user_id'] !== $user_id || $token['status'] !== Pethelp_PayU_Token_Repository::STATUS_ACTIVE ) {
				wp_die( esc_html__( 'Wybrana karta jest niedostępna.', 'pethelp-payu-cards' ), '', [ 'response' => 400 ] );
			}

			Pethelp_PayU_Token_Repository::assign_to_subscription( $token_id, $subscription );

		} elseif ( $action === 'new' ) {
			$single_use_token = sanitize_text_field( wp_unslash( $_POST['pethelp_payu_token_value'] ?? '' ) );

			if ( empty( $single_use_token ) ) {
				self::render( $subscription, __( 'Nie udało się zweryfikować nowej karty. Spróbuj ponownie.', 'pethelp-payu-cards' ), $redirect );
				exit;
			}

			$gateway = self::get_gateway();

			if ( ! $gateway ) {
				wp_die( esc_html__( 'Bramka PayU jest niedostępna.', 'pethelp-payu-cards' ), '', [ 'response' => 500 ] );
			}

			$user = wp_get_current_user();
			$continue_url = add_query_arg( 'tokenize_pending', 1, self::get_url( $subscription->get_id(), $redirect ) );

			try {
				$order_response = $gateway->create_multi_use_token_order(
					$single_use_token,
					$user_id,
					$user->user_email,
					$user->first_name,
					$user->last_name,
					$continue_url
				);

				$token_id = Pethelp_PayU_Token_Repository::create_from_order_response( $user_id, $order_response );
				Pethelp_PayU_Token_Repository::assign_to_subscription( $token_id, $subscription );

				$token = Pethelp_PayU_Token_Repository::get( $token_id );
				$card_token = $gateway->fetch_card_token( $token['payu_token'], $user_id, $user->user_email );

				if ( $card_token ) {
					Pethelp_PayU_Token_Repository::update_card_brand( $token_id, $card_token['cardBrand'] ?? '');
				}

			} catch ( Exception $e ) {
				self::render( $subscription, $e->getMessage(), $redirect );
				exit;
			}

			if ( ! empty( $order_response['redirectUri'] ) ) {
				wp_redirect( $order_response['redirectUri'] );
				exit;
			}

		} else {
			wp_die( esc_html__( 'Nieprawidłowe żądanie.', 'pethelp-payu-cards' ), '', [ 'response' => 400 ] );
		}

		wp_safe_redirect( add_query_arg( 'done', '1', self::get_url( $subscription->get_id(), $redirect ) ) );
		exit;
	}

	private static function handle_continue( \WC_Subscription $subscription, ?string $redirect ): void {
		wp_safe_redirect( add_query_arg( 'done', '1', self::get_url( $subscription->get_id(), $redirect ) ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	private static function render( \WC_Subscription $subscription, string $error = '', ?string $redirect = null ): void {
		$user_id          = get_current_user_id();
		$current_token_id = (int) $subscription->get_meta( '_pethelp_payu_token_id' );
		$current_token    = $current_token_id ? Pethelp_PayU_Token_Repository::get( $current_token_id ) : null;
		$tokens           = array_filter(
			Pethelp_PayU_Token_Repository::get_active_for_user( $user_id ),
			function ( $token ) use ( $current_token_id, $subscription ) {
				return (int) $token['id'] !== $current_token_id && $subscription->get_id() === (int)  $token['current_subscription_id'];
			}
		);
		$done       = ! empty( $_GET['done'] );
		$cancel_url = $redirect ?: home_url('/');

		$gateway = self::get_gateway();
		$widget  = $gateway
			? $gateway->get_widget_config( 0.0, (string) wp_get_current_user()->user_email )
			: null;

		$logo_url  = esc_url( get_site_icon_url( 64 ) );
		$shop_name = esc_html( get_bloginfo( 'name' ) );

		$theme_template = locate_template( 'woocommerce/payu-cards/card-change.php' );
		include $theme_template ?: ( PETHELP_PAYU_CARDS_PATH . 'template/card-change.php' );
	}

	private static function get_gateway(): ?\Pethelp_Gateway_PayU_Card_Recurring {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}

		$gateways = WC()->payment_gateways()->payment_gateways();

		return $gateways[ self::GATEWAY_ID ] ?? null;
	}
}
