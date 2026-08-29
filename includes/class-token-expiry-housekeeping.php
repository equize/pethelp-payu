<?php

defined( 'ABSPATH' ) || exit;

class Pethelp_PayU_Token_Expiry_Housekeeping {

	const HOOK  = 'pethelp_payu_daily_token_check';
	const GROUP = 'pethelp_payu_cards';

	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
		add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );
	}

	public static function maybe_schedule(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( as_next_scheduled_action( self::HOOK, [], self::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action( strtotime( 'tomorrow 06:00' ), DAY_IN_SECONDS, self::HOOK, [], self::GROUP );
	}

	public static function run(): void {
		foreach ( Pethelp_PayU_Token_Repository::find_past_expiry() as $token ) {
			Pethelp_PayU_Token_Repository::mark_expired( (int) $token['id'] );
		}
	}
}
