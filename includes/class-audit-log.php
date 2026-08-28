<?php

defined( 'ABSPATH' ) || exit;

class Pethelp_PayU_Audit_Log {

	public static function log( string $event, array $payload ): void {
		do_action( 'pethelp_crm_audit_log_token_event', $event, $payload );
	}
}
