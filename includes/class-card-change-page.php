<?php

defined( 'ABSPATH' ) || exit;

class Pethelp_PayU_Card_Change_Page {

	const QUERY_VAR           = 'pethelp-payu-change-card';
	const NONCE_ACTION_PREFIX = 'pethelp_payu_change_card_';
	const GATEWAY_ID          = 'payu_card_recurring';

	/**
	 * Subscription meta holding a new card that waits for PayU's 3DS result:
	 * [ 'token_id' => int, 'payu_order_id' => string ].
	 */
	const PENDING_CARD_META = '_pethelp_payu_pending_card_change';

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
		// No nonce in the link: it is sent in notifications and copied from the admin panel,
		// so it must work for the customer and must not expire. Access is guarded by login
		// and subscription checks; the change itself (POST) carries its own nonce.
		$args = [
			self::QUERY_VAR    => 1,
			'subscription_id'  => $subscription_id,
		];

		if ( $redirect ) {
			$args['redirect'] = $redirect;
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Login page that brings the customer back to the card change afterwards: the
	 * theme's customer login page (return URL in "r") or, without it, wp-login.php.
	 */
	private static function get_login_url(): string {
		$current = set_url_scheme( 'http://' . ( $_SERVER['HTTP_HOST'] ?? '' ) . wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );

		if ( function_exists( 'pethelp_customer_login_page_url' ) ) {
			return add_query_arg( 'r', rawurlencode( $current ), pethelp_customer_login_page_url() );
		}

		return wp_login_url( $current );
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

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( self::get_login_url() );
			exit;
		}

		$subscription_id = absint( $_REQUEST['subscription_id'] ?? 0 );
		$subscription    = $subscription_id ? self::get_valid_subscription_for_current_user( $subscription_id ) : null;

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
			// The form's nonce belongs to the logged-in customer – protects the change against CSRF.
			$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) );

			if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION_PREFIX . $subscription->get_id() ) ) {
				wp_die( esc_html__( 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.', 'pethelp-payu-cards' ), '', [ 'response' => 403 ] );
			}

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

			if (
				! $token
				|| (int) $token['user_id'] !== $user_id
				|| $token['status'] !== Pethelp_PayU_Token_Repository::STATUS_ACTIVE
				|| ! self::is_selectable_token( $token )
			) {
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

				$token = Pethelp_PayU_Token_Repository::get( $token_id );
				$card_token = $gateway->fetch_card_token( $token['payu_token'], $user_id, $user->user_email );

				if ( $card_token ) {
					Pethelp_PayU_Token_Repository::update_card_brand( $token_id, $card_token['cardBrand'] ?? '');
				}

			} catch ( Exception $e ) {
				// The exception may carry a raw PayU API response – the customer gets a plain message.
				wc_get_logger()->error(
					sprintf( 'Zmiana karty [subskrypcja #%d]: błąd rejestracji karty – %s', $subscription->get_id(), Pethelp_Gateway_PayU_Abstract::redact( $e->getMessage() ) ),
					[ 'source' => 'pethelp-payu-cards' ]
				);
				self::render( $subscription, __( 'Nie udało się dodać nowej karty. Spróbuj ponownie za chwilę.', 'pethelp-payu-cards' ), $redirect );
				exit;
			}

			// 3DS still ahead – the card is assigned only once PayU confirms the
			// registration (see handle_continue()), never before.
			if ( ! empty( $order_response['redirectUri'] ) ) {
				self::discard_pending_card( $subscription, 'card_change_abandoned' );

				$subscription->update_meta_data( self::PENDING_CARD_META, [
					'token_id'      => $token_id,
					'payu_order_id' => (string) ( $order_response['orderId'] ?? '' ),
				] );
				$subscription->save();

				wp_redirect( $order_response['redirectUri'] );
				exit;
			}

			Pethelp_PayU_Token_Repository::assign_to_subscription( $token_id, $subscription );

		} else {
			wp_die( esc_html__( 'Nieprawidłowe żądanie.', 'pethelp-payu-cards' ), '', [ 'response' => 400 ] );
		}

		wp_safe_redirect( add_query_arg( 'done', '1', self::get_url( $subscription->get_id(), $redirect ) ) );
		exit;
	}

	/**
	 * Customer is back from PayU's 3DS page. The return itself says nothing about
	 * the outcome, so the registration order status is read from PayU before the
	 * new card replaces the current one.
	 */
	private static function handle_continue( \WC_Subscription $subscription, ?string $redirect ): void {
		$pending = $subscription->get_meta( self::PENDING_CARD_META );

		if ( ! is_array( $pending ) || empty( $pending['token_id'] ) ) {
			wp_safe_redirect( self::get_url( $subscription->get_id(), $redirect ) );
			exit;
		}

		$status = self::fetch_registration_status( (string) $pending['payu_order_id'] );

		if ( $status === 'COMPLETED' ) {
			$subscription->delete_meta_data( self::PENDING_CARD_META );
			$subscription->save();

			Pethelp_PayU_Token_Repository::assign_to_subscription( (int) $pending['token_id'], $subscription );

			wp_safe_redirect( add_query_arg( 'done', '1', self::get_url( $subscription->get_id(), $redirect ) ) );
			exit;
		}

		if ( in_array( $status, [ 'PENDING', 'NEW', 'WAITING_FOR_CONFIRMATION' ], true ) ) {
			self::render( $subscription, __( 'Bank jeszcze weryfikuje nową kartę. Odśwież stronę za chwilę – do tego czasu płatności będą pobierane z dotychczasowej karty.', 'pethelp-payu-cards' ), $redirect );
			exit;
		}

		self::discard_pending_card( $subscription, 'card_change_' . strtolower( $status ?: 'error' ) );

		self::render( $subscription, __( 'Nie udało się dodać nowej karty – bank nie potwierdził weryfikacji. Płatności będą pobierane z dotychczasowej karty.', 'pethelp-payu-cards' ), $redirect );
		exit;
	}

	/**
	 * Status of the zero-amount card registration order in PayU, or '' when it
	 * cannot be read. Retried briefly – right after 3DS PayU may still report it
	 * as PENDING.
	 */
	private static function fetch_registration_status( string $payu_order_id ): string {
		$gateway = self::get_gateway();

		if ( ! $gateway || $payu_order_id === '' ) {
			return '';
		}

		$status = '';

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			try {
				$response = $gateway->fetch_order( $payu_order_id );
				$status   = (string) ( $response['orders'][0]['status'] ?? '' );
			} catch ( Exception $e ) {
				$status = '';
			}

			if ( ! in_array( $status, [ 'PENDING', 'NEW', '' ], true ) ) {
				break;
			}

			sleep( 1 );
		}

		return $status;
	}

	/**
	 * Whether an active saved card can be offered. A card never assigned to any
	 * plan is still waiting for (or abandoned) its 3DS confirmation – not offered.
	 */
	private static function is_selectable_token( array $token ): bool {
		return (int) $token['current_subscription_id'] > 0;
	}

	/**
	 * Drops a card change that never got PayU's confirmation; its token is
	 * invalidated so it can't be charged or offered as a saved card.
	 */
	private static function discard_pending_card( \WC_Subscription $subscription, string $reason ): void {
		$pending = $subscription->get_meta( self::PENDING_CARD_META );

		if ( ! is_array( $pending ) || empty( $pending['token_id'] ) ) {
			return;
		}

		Pethelp_PayU_Token_Repository::invalidate( (int) $pending['token_id'], $reason );

		$subscription->delete_meta_data( self::PENDING_CARD_META );
		$subscription->save();
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
			function ( $token ) use ( $current_token_id ) {
				return (int) $token['id'] !== $current_token_id && self::is_selectable_token( $token );
			}
		);
		$done       = ! empty( $_GET['done'] );
		$cancel_url = $redirect ?: home_url('/');

		$gateway = self::get_gateway();
		$widget  = $gateway ? $gateway->get_widget_config( 0.0, (string) wp_get_current_user()->user_email ) : null;

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
