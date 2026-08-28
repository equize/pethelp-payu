<?php

defined( 'ABSPATH' ) || exit;

/**
 * Thrown for non-2xx/302 PayU API responses.
 */
class Pethelp_PayU_Cards_Exception extends Exception {

	/** @var string */
	protected $codeLiteral;

	public function __construct( string $message, string $codeLiteral = '', int $code = 0, ?Throwable $previous = null ) {
		parent::__construct( $message, $code, $previous );
		$this->codeLiteral = $codeLiteral;
	}

	public function getCodeLiteral(): string {
		return $this->codeLiteral;
	}
}
