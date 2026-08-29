<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Pethelp_PayU_Admin_Tokens_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( [
			'singular' => 'token',
			'plural'   => 'tokens',
			'ajax'     => false,
		] );
	}

	public function get_columns(): array {
		return [
			'id'           => __( 'ID', 'pethelp-payu-cards' ),
			'user'         => __( 'Klient', 'pethelp-payu-cards' ),
			'masked'       => __( 'Karta', 'pethelp-payu-cards' ),
			'expiry'       => __( 'Ważność', 'pethelp-payu-cards' ),
			'subscription' => __( 'Subskrypcja', 'pethelp-payu-cards' ),
			'status'       => __( 'Status', 'pethelp-payu-cards' ),
			'created_at'   => __( 'Utworzono', 'pethelp-payu-cards' ),
		];
	}

	protected function get_sortable_columns(): array {
		return [
			'id'         => [ 'id', false ],
			'status'     => [ 'status', false ],
			'expiry'     => [ 'exp_year', false ],
			'created_at' => [ 'created_at', true ],
		];
	}

	protected function get_views(): array {
		$current = sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) );
		$base    = remove_query_arg( 'status' );

		$statuses = [
			''                                        		  => __( 'Wszystkie', 'pethelp-payu-cards' ),
			Pethelp_PayU_Token_Repository::STATUS_ACTIVE      => __( 'Aktywne', 'pethelp-payu-cards' ),
			Pethelp_PayU_Token_Repository::STATUS_EXPIRED     => __( 'Wygasłe', 'pethelp-payu-cards' ),
			Pethelp_PayU_Token_Repository::STATUS_INVALIDATED => __( 'Unieważnione', 'pethelp-payu-cards' ),
		];

		$views = [];

		foreach ( $statuses as $status => $label ) {
			$count = Pethelp_PayU_Token_Repository::count( $status ? [ 'status' => $status ] : [] );
			$url   = $status ? add_query_arg( 'status', $status, $base ) : $base;
			$class = $current === $status ? 'current' : '';

			$views[ $status ?: 'all' ] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				esc_attr( $class ),
				esc_html( $label ),
				$count
			);
		}

		return $views;
	}

	public function prepare_items(): void {
		$per_page = 20;
		$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

		$args = [
			'per_page' => $per_page,
			'paged'    => $paged,
			'status'   => sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) ),
			'search'   => sanitize_text_field( wp_unslash( $_REQUEST['s'] ?? '' ) ),
			'orderby'  => sanitize_text_field( wp_unslash( $_GET['orderby'] ?? 'created_at' ) ),
			'order'    => sanitize_text_field( wp_unslash( $_GET['order'] ?? 'desc' ) ),
		];

		$this->items = Pethelp_PayU_Token_Repository::query( $args );

		$total_items = Pethelp_PayU_Token_Repository::count( [
			'status' => $args['status'],
			'search' => $args['search'],
		] );

		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];

		$this->set_pagination_args( [
			'total_items' => $total_items,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total_items / $per_page ),
		] );
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'id':
				return '#' . (int) $item['id'];

			case 'masked':
				$card = esc_html( $item['masked_card'] ?: '••••' );
				if ( ! empty( $item['card_brand'] ) ) {
					$card .= ' <span class="description">(' . esc_html( $item['card_brand'] ) . ')</span>';
				}
				return $card;

			case 'expiry':
				return ( $item['exp_month'] && $item['exp_year'] )
					? esc_html( sprintf( '%02d/%d', (int) $item['exp_month'], (int) $item['exp_year'] ) )
					: '—';

			case 'status':
				return $this->render_status_badge( $item['status'] );

			case 'subscription':
				$sub_id = (int) $item['current_subscription_id'];
				if ( ! $sub_id ) {
					return '—';
				}
				return sprintf(
					'<a href="%s">#%d</a>',
					esc_url( admin_url( 'post.php?post=' . $sub_id . '&action=edit' ) ),
					$sub_id
				);

			case 'created_at':
				return esc_html( mysql2date( 'Y-m-d H:i', $item['created_at'] ) );

			default:
				return '';
		}
	}

	protected function column_user( array $item ): string {
		$user = get_userdata( (int) $item['user_id'] );

		if ( ! $user ) {
			return sprintf( __( 'Użytkownik #%d (usunięty)', 'pethelp-payu-cards' ), (int) $item['user_id'] );
		}

		return sprintf(
			'<a href="%s">#%d - %s</a><br><span class="description">%s</span>',
			esc_url( admin_url( 'user-edit.php?user_id=' . $user->ID ) ),
			$user->ID,
			esc_html( $user->display_name ),
			esc_html( $user->user_email )
		);
	}

	protected function column_id( array $item ): string {
		$actions = [];

		if ( $item['status'] === Pethelp_PayU_Token_Repository::STATUS_ACTIVE ) {
			$url = wp_nonce_url(
				add_query_arg( [ 'action' => 'invalidate', 'token_id' => $item['id'] ] ),
				'pethelp_payu_invalidate_token_' . $item['id']
			);

			$actions['invalidate'] = sprintf(
				'<a href="%s" onclick="return confirm(%s)">%s</a>',
				esc_url( $url ),
				esc_attr( wp_json_encode( __( 'Unieważnić ten token? Tej operacji nie można cofnąć.', 'pethelp-payu-cards' ) ) ),
				esc_html__( 'Unieważnij', 'pethelp-payu-cards' )
			);
		}

		return '#' . (int) $item['id'] . $this->row_actions( $actions );
	}

	private function render_status_badge( string $status ): string {
		$labels = [
			Pethelp_PayU_Token_Repository::STATUS_ACTIVE      => [ __( 'Aktywny', 'pethelp-payu-cards' ), '#1a9c53' ],
			Pethelp_PayU_Token_Repository::STATUS_EXPIRED     => [ __( 'Wygasły', 'pethelp-payu-cards' ), '#996f00' ],
			Pethelp_PayU_Token_Repository::STATUS_INVALIDATED => [ __( 'Unieważniony', 'pethelp-payu-cards' ), '#c00' ],
		];

		[ $label, $color ] = $labels[ $status ] ?? [ $status, '#666' ];

		return sprintf( '<strong style="color:%s;">%s</strong>', esc_attr( $color ), esc_html( $label ) );
	}
}
