<?php

defined( 'ABSPATH' ) || exit;

/**
 * CRUD layer over the local PayU card-token catalog (custom table, not post meta).
 */
class Pethelp_PayU_Token_Repository {

	public const STATUS_ACTIVE      = 'active';
	public const STATUS_EXPIRED     = 'expired';
	public const STATUS_INVALIDATED = 'invalidated';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'payu_tokens';
	}

	public static function install_table(): void {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id bigint(20) UNSIGNED NOT NULL,
			payu_token varchar(190) NOT NULL,
			token_type varchar(50) NOT NULL DEFAULT '',
			masked_card varchar(50) NOT NULL DEFAULT '',
			card_brand varchar(50) NOT NULL DEFAULT '',
			exp_month tinyint(2) UNSIGNED NULL,
			exp_year smallint(4) UNSIGNED NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			current_subscription_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			meta longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * @param array{
	 *     user_id:int,
	 *     payu_token:string,
	 *     token_type?:string,
	 *     masked_card?:string,
	 *     card_brand?:string,
	 *     exp_month?:int|null,
	 *     exp_year?:int|null,
	 *     meta?:array,
	 * } $data
	 * @return int New token row id, 0 on failure.
	 */
	public static function create( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql' );

		$row = [
			'user_id'     => (int) $data['user_id'],
			'payu_token'  => (string) $data['payu_token'],
			'token_type'  => (string) ( $data['token_type'] ?? '' ),
			'masked_card' => (string) ( $data['masked_card'] ?? '' ),
			'card_brand'  => (string) ( $data['card_brand'] ?? '' ),
			'exp_month'   => isset( $data['exp_month'] ) ? (int) $data['exp_month'] : null,
			'exp_year'    => isset( $data['exp_year'] ) ? (int) $data['exp_year'] : null,
			'status'      => self::STATUS_ACTIVE,
			'meta'        => wp_json_encode( $data['meta'] ?? [] ),
			'created_at'  => $now,
			'updated_at'  => $now,
		];

		$formats = [ '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ];

		$inserted = $wpdb->insert( self::table_name(), $row, $formats );

		if ( ! $inserted ) {
			return 0;
		}

		$token_id = (int) $wpdb->insert_id;

		Pethelp_PayU_Audit_Log::log( 'token_created', [
			'token_id'    => $token_id,
			'user_id'     => $row['user_id'],
			'masked_card' => $row['masked_card'],
			'card_brand'  => $row['card_brand'],
		] );

		return $token_id;
	}

	/**
	 * @param array{type:string,value:string,masked_card:string} $widget_token
	 */
	public static function create_from_widget_response( int $user_id, array $widget_token ): int {
		return self::create( [
			'user_id'     => $user_id,
			'payu_token'  => $widget_token['value'],
			'token_type'  => $widget_token['type'],
			'masked_card' => $widget_token['masked_card']
		] );
	}

	/**
	 * @param int $user_id
	 * @param array $raw_response Full decoded widget callback JSON.
	 */
	public static function create_from_order_response( int $user_id, array $raw_response ): int {
		$payMethod = $raw_response['payMethods']['payMethod'] ?? [];
		$card = $payMethod['card'] ?? [];
		$token = $payMethod['value'] ?? null;

		return self::create( [
			'user_id'     => $user_id,
			'payu_token'  => $token,
			'token_type'  => 'MULTI',
			'masked_card' => $card['number'] ?? '',
			'exp_month'   => $card['expirationMonth'] ?? null,
			'exp_year'    => $card['expirationYear'] ?? null
		] );
	}

	public static function get( int $token_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $token_id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * @return array<int,array>
	 */
	public static function get_active_for_user( int $user_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE user_id = %d AND status = %s ORDER BY created_at DESC',
				$user_id,
				self::STATUS_ACTIVE
			),
			ARRAY_A
		);

		return $rows ?: [];
	}

	/**
	 * Paginated, filterable listing for the admin token-management screen.
	 *
	 * @param array{
	 *     status?: string,
	 *     search?: string,
	 *     per_page?: int,
	 *     paged?: int,
	 *     orderby?: string,
	 *     order?: string,
	 * } $args
	 * @return array<int,array>
	 */
	public static function query( array $args = [] ): array {
		global $wpdb;

		[ $where, $params ] = self::build_query_where( $args );

		$allowed_orderby = [ 'id', 'user_id', 'status', 'exp_year', 'created_at', 'updated_at' ];
		$orderby          = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order            = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$offset   = ( $paged - 1 ) * $per_page;

		$sql = 'SELECT * FROM ' . self::table_name() . " {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, [ $per_page, $offset ] ) ), ARRAY_A );

		return $rows ?: [];
	}

	/**
	 * @param array{status?: string, search?: string} $args Same filters as query().
	 */
	public static function count( array $args = [] ): int {
		global $wpdb;

		[ $where, $params ] = self::build_query_where( $args );

		$sql = 'SELECT COUNT(*) FROM ' . self::table_name() . " {$where}";

		if ( empty( $params ) ) {
			return (int) $wpdb->get_var( $sql );
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * @return array{0:string,1:array<int,mixed>} [$whereSql, $params]
	 */
	private static function build_query_where( array $args ): array {
		global $wpdb;

		$where  = [];
		$params = [];

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '( masked_card LIKE %s OR payu_token LIKE %s OR card_brand LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

		return [ $sql, $params ];
	}

	public static function update_status( int $token_id, string $status ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			self::table_name(),
			[
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			],
			[ 'id' => $token_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		
		if ( $updated !== false ) {
			do_action( 'payu_token_status_changed', $token_id, $status );
		}

		return $updated !== false;
	}

	/**
	 * Backfills/corrects the card brand on an existing token row (e.g. when
	 * it wasn't available at creation time and is learned afterwards).
	 */
	public static function update_card_brand( int $token_id, string $card_brand ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			self::table_name(),
			[
				'card_brand' => $card_brand,
				'updated_at' => current_time( 'mysql' ),
			],
			[ 'id' => $token_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		return $updated !== false;
	}

	/**
	 * Permanently blocks a token from further charges (permanent decline,
	 * or the customer replacing it) but keeps the row for audit/history.
	 */
	public static function invalidate( int $token_id, string $reason = '' ): bool {
		$token = self::get( $token_id );

		if ( ! $token || $token['status'] === self::STATUS_INVALIDATED ) {
			return false;
		}

		$ok = self::update_status( $token_id, self::STATUS_INVALIDATED );

		if ( $ok ) {
			Pethelp_PayU_Audit_Log::log( 'token_invalidated', [
				'token_id' => $token_id,
				'user_id'  => (int) $token['user_id'],
				'reason'   => $reason,
			] );
		}

		return $ok;
	}

	public static function mark_expired( int $token_id ): bool {
		$token = self::get( $token_id );

		if ( ! $token || $token['status'] !== self::STATUS_ACTIVE ) {
			return false;
		}

		$ok = self::update_status( $token_id, self::STATUS_EXPIRED );

		if ( $ok ) {
			Pethelp_PayU_Audit_Log::log( 'token_expired', [
				'token_id' => $token_id,
				'user_id'  => (int) $token['user_id'],
			] );
		}

		return $ok;
	}

	public static function assign_to_subscription( int $token_id, \WC_Subscription $subscription ): void {
		global $wpdb;

		$subscription_id = $subscription->get_id();
		$token            = self::get( $token_id );
		$previous_sub_id  = $token ? (int) $token['current_subscription_id'] : 0;

		$subscription->update_meta_data( '_pethelp_payu_token_id', $token_id );
		$subscription->save();

		self::refresh_scheduled_renewal_orders( $token_id, $subscription );

		// Lets the scheduled-reminders framework (see wp-content/themes/petmedica/includes/reminders)
		// (re)compute the card-expiry reminder schedule for this subscription –
		// fired unconditionally (even on a no-op reassignment) so a schedule
		// always exists/gets corrected, not just on an actual change.
		do_action( 'payu_token_assigned_to_subscription', $subscription_id, $token_id );

		if ( $previous_sub_id === $subscription_id ) {
			return; // No actual change – avoid a no-op audit entry.
		}

		$wpdb->update(
			self::table_name(),
			[
				'current_subscription_id' => $subscription_id,
				'updated_at'               => current_time( 'mysql' ),
			],
			[ 'id' => $token_id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);

		Pethelp_PayU_Audit_Log::log( 'token_assignment_changed', [
			'token_id'                 => $token_id,
			'subscription_id'          => $subscription_id,
			'previous_subscription_id' => $previous_sub_id,
			'new_subscription_id'      => $subscription_id,
		] );
	}

	private static function refresh_scheduled_renewal_orders( int $token_id, \WC_Subscription $subscription ): void {
		if ( ! method_exists( $subscription, 'get_related_orders' ) ) {
			return;
		}

		foreach ( $subscription->get_related_orders( 'all', 'renewal' ) as $renewal_order ) {
			if ( ! $renewal_order instanceof \WC_Order || !$renewal_order->has_status(['scheduled', 'pending', 'processing', 'on-hold', 'failed']) ) {
				continue;
			}

			$renewal_order->update_meta_data( '_pethelp_payu_token_id', $token_id );
			$renewal_order->save();
		}
	}

	/**
	 * Active tokens whose expiry (last day of exp_month/exp_year) falls on
	 * exactly $days_before from today – used to fire the "7 days before"
	 * and "on expiry day" (days_before = 0) reminders once each.
	 *
	 * @return array<int,array>
	 */
	public static function find_expiring_in_days( int $days_before ): array {
		global $wpdb;

		$target = new DateTime( "+{$days_before} days", wp_timezone() );
		$month  = (int) $target->format( 'n' );
		$year   = (int) $target->format( 'Y' );

		// Only tokens whose card expiry is the LAST day of that month match
		// "today is exactly N days before/at the expiry date".
		$last_day_of_month = (int) $target->format( 't' );
		if ( (int) $target->format( 'j' ) !== $last_day_of_month ) {
			return [];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE status = %s AND exp_month = %d AND exp_year = %d',
				self::STATUS_ACTIVE,
				$month,
				$year
			),
			ARRAY_A
		);

		return $rows ?: [];
	}

	/**
	 * Active tokens whose expiry date has already passed.
	 *
	 * @return array<int,array>
	 */
	public static function find_past_expiry(): array {
		global $wpdb;

		$now   = new DateTime( 'now', wp_timezone() );
		$month = (int) $now->format( 'n' );
		$year  = (int) $now->format( 'Y' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE status = %s AND ( exp_year < %d OR ( exp_year = %d AND exp_month < %d ) )',
				self::STATUS_ACTIVE,
				$year,
				$year,
				$month
			),
			ARRAY_A
		);

		return $rows ?: [];
	}
}
