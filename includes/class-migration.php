<?php

defined( 'ABSPATH' ) || exit;

class Pethelp_PayU_Migration {

	const LEGACY_GATEWAY_ID = 'payu_recurring';
	const NEW_GATEWAY_ID    = 'payu_card_recurring';

	public static function init(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'pethelp-payu migrate', [ __CLASS__, 'cli_migrate' ] );
		}

		add_filter( 'woocommerce_debug_tools', [ __CLASS__, 'register_debug_tool' ] );
	}

	// -------------------------------------------------------------------------
	// WP-CLI:  wp pethelp-payu migrate [--dry-run]
	// -------------------------------------------------------------------------

	/**
	 * @param array $args
	 * @param array $assoc_args
	 */
	public static function cli_migrate( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );

		$result = self::run( $dry_run );

		foreach ( $result['log'] as $line ) {
			WP_CLI::log( $line );
		}

		WP_CLI::success( sprintf(
			'%s: %d zmigrowanych, %d pominiętych, %d błędów.',
			$dry_run ? 'Dry-run' : 'Migracja',
			$result['migrated'],
			$result['skipped'],
			$result['errors']
		) );
	}

	// -------------------------------------------------------------------------
	// WooCommerce → Status → Tools button
	// -------------------------------------------------------------------------

	public static function register_debug_tool( array $tools ): array {
		$tools['pethelp_payu_migrate'] = [
			'name'     => __( 'Migracja PayU: kartowe subskrypcje na pethelp-payu-cards', 'pethelp-payu-cards' ),
			'button'   => __( 'Uruchom migrację', 'pethelp-payu-cards' ),
			'desc'     => __( 'Przenosi aktywne subskrypcje z woocommerce-gateway-payu-pl (payu_recurring) na lokalny katalog tokenów i bramkę payu_card_recurring. Operacja jest bezpieczna do wielokrotnego uruchomienia – już zmigrowane subskrypcje są pomijane, a stare dane meta pozostają nienaruszone.', 'pethelp-payu-cards' ),
			'callback' => [ __CLASS__, 'run_from_admin' ],
		];

		return $tools;
	}

	public static function run_from_admin(): string {
		$result = self::run( false );

		return sprintf(
			'Migracja PayU zakończona: %d zmigrowanych, %d pominiętych, %d błędów.',
			$result['migrated'],
			$result['skipped'],
			$result['errors']
		);
	}

	// -------------------------------------------------------------------------
	// Core
	// -------------------------------------------------------------------------

	/**
	 * @return array{migrated:int,skipped:int,errors:int,log:array<int,string>}
	 */
	public static function run( bool $dry_run ): array {
		$migrated = 0;
		$skipped  = 0;
		$errors   = 0;
		$log      = [];

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [ 'migrated' => 0, 'skipped' => 0, 'errors' => 1, 'log' => [ 'WooCommerce nie jest aktywne.' ] ];
		}

		$subscriptions = wc_get_orders( [
			'type'           => 'shop_subscription',
			'payment_method' => self::LEGACY_GATEWAY_ID,
			'limit'           => -1,
			'return'          => 'objects',
		] );

		foreach ( $subscriptions as $subscription ) {
			if ( ! $subscription instanceof \WC_Subscription ) {
				continue;
			}

			$sub_id = $subscription->get_id();

			if ( $subscription->get_meta( '_pethelp_payu_token_id' ) ) {
				$skipped++;
				$log[] = sprintf( '#%d: pominięto – już zmigrowana.', $sub_id );
				continue;
			}

			$card_data = $subscription->get_meta( '_payu_card_data' );

			if ( empty( $card_data ) || ! is_array( $card_data ) || empty( $card_data['value'] ) ) {
				$errors++;
				$log[] = sprintf( '#%d: błąd – brak lub niepełne _payu_card_data, pominięto.', $sub_id );
				continue;
			}

			if ( $dry_run ) {
				$migrated++;
				$log[] = sprintf(
					'#%d: [dry-run] zostałaby zmigrowana – token=%s maska=%s',
					$sub_id,
					substr( (string) $card_data['value'], 0, 12 ) . '…',
					$card_data['masked_card'] ?? '?'
				);
				continue;
			}

			try {
				$token_id = Pethelp_PayU_Token_Repository::create( [
					'user_id'     => (int) $subscription->get_customer_id(),
					'payu_token'  => (string) $card_data['value'],
					'token_type'  => (string) ( $card_data['token_type'] ?? '' ),
					'masked_card' => (string) ( $card_data['masked_card'] ?? '' ),
					// Legacy _payu_card_data never captured brand/expiry –
					// left null; only the expiry-reminder feature is affected,
					// charging is unaffected (it only needs the token value).
					'card_brand'  => '',
					'exp_month'   => null,
					'exp_year'    => null,
					'meta'        => [ 'migrated_from' => self::LEGACY_GATEWAY_ID ],
				] );

				if ( ! $token_id ) {
					throw new Exception( 'nie udało się utworzyć rekordu tokenu' );
				}

				Pethelp_PayU_Token_Repository::assign_to_subscription( $token_id, $subscription );

				$subscription->set_payment_method( self::NEW_GATEWAY_ID );
				$subscription->save();

				$subscription->add_order_note( sprintf(
					__( 'Zmigrowano z payu_recurring na PayU – płatność cykliczna kartą (token #%d).', 'pethelp-payu-cards' ),
					$token_id
				) );

				$migrated++;
				$log[] = sprintf( '#%d: zmigrowano – nowy token #%d.', $sub_id, $token_id );

			} catch ( Exception $e ) {
				$errors++;
				$log[] = sprintf( '#%d: błąd – %s', $sub_id, $e->getMessage() );
			}
		}

		return [ 'migrated' => $migrated, 'skipped' => $skipped, 'errors' => $errors, 'log' => $log ];
	}
}
