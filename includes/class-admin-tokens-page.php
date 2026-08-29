<?php

defined( 'ABSPATH' ) || exit;

class Pethelp_PayU_Admin_Tokens_Page {

	const MENU_SLUG        = 'pethelp-payu-tokens';
	const SETTINGS_SLUG    = 'pethelp-payu-tokens-settings';
	const ROLE_OPTION       = 'pethelp_payu_tokens_manager_role';
	const INVALIDATE_ACTION = 'pethelp_payu_invalidate_token';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'maybe_handle_invalidate' ] );
	}

	public static function current_user_can_manage_tokens(): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$role = get_option( self::ROLE_OPTION, '' );

		if ( ! $role ) {
			return false;
		}

		return in_array( $role, (array) wp_get_current_user()->roles, true );
	}

	public static function register_menu(): void {
		add_submenu_page(
			null,
			__( 'Ustawienia dostępu – tokeny PayU', 'pethelp-payu-cards' ),
			__( 'Ustawienia dostępu', 'pethelp-payu-cards' ),
			'manage_options',
			self::SETTINGS_SLUG,
			[ __CLASS__, 'render_settings_page' ]
		);

		if ( ! self::current_user_can_manage_tokens() ) {
			return;
		}

		add_menu_page(
			__( 'Tokeny PayU', 'pethelp-payu-cards' ),
			__( 'Tokeny PayU', 'pethelp-payu-cards' ),
			'read',
			self::MENU_SLUG,
			[ __CLASS__, 'render_list_page' ],
			'dashicons-id-alt',
			58
		);
	}

	public static function maybe_handle_invalidate(): void {
		if ( ( $_GET['action'] ?? '' ) !== 'invalidate' || empty( $_GET['token_id'] ) || ( $_GET['page'] ?? '' ) !== self::MENU_SLUG ) {
			return;
		}

		$token_id = absint( $_GET['token_id'] );

		check_admin_referer( 'pethelp_payu_invalidate_token_' . $token_id );

		if ( ! self::current_user_can_manage_tokens() ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'pethelp-payu-cards' ), '', [ 'response' => 403 ] );
		}

		$ok = Pethelp_PayU_Token_Repository::invalidate( $token_id, 'admin_manual' );

		wp_safe_redirect( add_query_arg(
			'pethelp_payu_notice',
			$ok ? 'invalidated' : 'invalidate_failed',
			remove_query_arg( [ 'action', 'token_id', '_wpnonce' ] )
		) );
		exit;
	}

	public static function render_list_page(): void {
		if ( ! self::current_user_can_manage_tokens() ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'pethelp-payu-cards' ), '', [ 'response' => 403 ] );
		}

		require_once PETHELP_PAYU_CARDS_PATH . 'includes/class-admin-tokens-list-table.php';

		$table = new Pethelp_PayU_Admin_Tokens_List_Table();
		$table->prepare_items();

		$notice = sanitize_text_field( wp_unslash( $_GET['pethelp_payu_notice'] ?? '' ) );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Tokeny PayU', 'pethelp-payu-cards' ); ?></h1>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>" class="page-title-action">
					<?php esc_html_e( 'Ustawienia dostępu', 'pethelp-payu-cards' ); ?>
				</a>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php if ( $notice === 'invalidated' ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Token został unieważniony.', 'pethelp-payu-cards' ); ?></p></div>
			<?php elseif ( $notice === 'invalidate_failed' ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Nie udało się unieważnić tokenu (być może już był unieważniony).', 'pethelp-payu-cards' ); ?></p></div>
			<?php endif; ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
				<?php
				$table->views();
				$table->search_box( __( 'Szukaj', 'pethelp-payu-cards' ), 'pethelp-payu-token' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'pethelp-payu-cards' ), '', [ 'response' => 403 ] );
		}

		if ( ! empty( $_POST['pethelp_payu_tokens_settings_nonce'] ) ) {
			check_admin_referer( 'pethelp_payu_tokens_settings', 'pethelp_payu_tokens_settings_nonce' );

			$role = sanitize_text_field( wp_unslash( $_POST['pethelp_payu_tokens_role'] ?? '' ) );
			update_option( self::ROLE_OPTION, $role );

			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Zapisano.', 'pethelp-payu-cards' ) . '</p></div>';
		}

		$current_role = get_option( self::ROLE_OPTION, '' );
		$roles        = wp_roles()->get_names();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Ustawienia dostępu – tokeny PayU', 'pethelp-payu-cards' ); ?></h1>
			<p><?php esc_html_e( 'Administratorzy (manage_options) zawsze mają dostęp do listy tokenów. Poniżej możesz dodatkowo wskazać jedną rolę, której użytkownicy również będą mieli dostęp.', 'pethelp-payu-cards' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'pethelp_payu_tokens_settings', 'pethelp_payu_tokens_settings_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="pethelp_payu_tokens_role"><?php esc_html_e( 'Rola z dostępem', 'pethelp-payu-cards' ); ?></label>
						</th>
						<td>
							<select name="pethelp_payu_tokens_role" id="pethelp_payu_tokens_role">
								<option value=""><?php esc_html_e( '— brak (tylko administratorzy) —', 'pethelp-payu-cards' ); ?></option>
								<?php foreach ( $roles as $role_slug => $role_label ) : ?>
									<option value="<?php echo esc_attr( $role_slug ); ?>" <?php selected( $current_role, $role_slug ); ?>>
										<?php echo esc_html( translate_user_role( $role_label ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
