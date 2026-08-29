<?php

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around PayU REST API v2.1 scoped to Pay by Link and
 * card-token (recurring) operations.
 */
class Pethelp_PayU_Cards_API {

	private string $client_id;
	private string $client_secret;
	private string $pos_id;
	private string $api_url;

	/** @var string|null */
	private ?string $bearer = null;

	public function __construct(
		string $client_id,
		string $client_secret,
		string $pos_id,
		bool $sandbox = false
	) {
		$this->client_id     = $client_id;
		$this->client_secret = $client_secret;
		$this->pos_id        = $pos_id;
		$this->api_url       = $sandbox
			? 'https://secure.snd.payu.com'
			: 'https://secure.payu.com';
	}

	/**
	 * Returns a valid OAuth2 bearer token, fetching a new one when expired.
	 */
	private function get_bearer(): string {
		if ( $this->bearer !== null ) {
			return $this->bearer;
		}

		$transient_key = 'pethelp_payu_cards_bearer_' . md5( $this->client_id . $this->client_secret );
		$cached        = get_transient( $transient_key );

		if ( $cached !== false ) {
			$this->bearer = $cached;
			return $this->bearer;
		}

		$response = wp_remote_post(
			$this->api_url . '/pl/standard/user/oauth/authorize',
			[
				'sslverify' => false,
				'timeout'   => 30,
				'body'      => [
					'grant_type'    => 'client_credentials',
					'client_id'     => $this->client_id,
					'client_secret' => $this->client_secret,
				],
			]
		);

		$this->assert_no_wp_error( $response, 'OAuth' );

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			throw new Exception( 'PayU OAuth: brak tokenu dostępu. ' . wp_remote_retrieve_body( $response ) );
		}

		$this->bearer = $body['access_token'];
		set_transient( $transient_key, $this->bearer, max( 60, intval( $body['expires_in'] ?? 3600 ) - 60 ) );

		return $this->bearer;
	}

	/**
	 * Returns a valid OAuth2 bearer token for merchant.
	 */
	private function get_trusted_merchant_bearer( string $extCustomerId, string $email ): string {
		$response = wp_remote_post(
			$this->api_url . '/pl/standard/user/oauth/authorize',
			[
				'sslverify' => false,
				'timeout'   => 30,
				'body'      => [
					'grant_type'      => 'trusted_merchant',
					'client_id'       => $this->client_id,
					'client_secret'   => $this->client_secret,
					'ext_customer_id' => $extCustomerId,
					'email' 		  => $email
				],
			]
		);
	
		$this->assert_no_wp_error( $response, 'OAuth' );

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			throw new Exception( 'PayU OAuth: brak tokenu dostępu. ' . wp_remote_retrieve_body( $response ) );
		}

		return $body['access_token'];
	}

	// -------------------------------------------------------------------------
	// Orders
	// -------------------------------------------------------------------------
	public function create_order( array $data ): array {
		$bearer = $this->get_bearer();

		$response = wp_remote_post(
			$this->api_url . '/api/v2_1/orders',
			[
				'redirection' => 0,
				'sslverify'   => false,
				'timeout'     => 45,
				'headers'     => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $bearer,
				],
				'body'        => wp_json_encode( $data ),
			]
		);

		$this->assert_no_wp_error( $response, 'create_order' );

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 302 && $code !== 201 && $code !== 200 ) {
			$code_literal = $body['status']['codeLiteral'] ?? '';
			$msg          = $body['status']['statusDesc'] ?? ( $code_literal ?: 'Nieznany błąd PayU' );
			throw new Pethelp_PayU_Cards_Exception( sprintf( 'PayU (HTTP %d): %s', $code, $msg ), $code_literal );
		}

		return $body;
	}

	/**
	 * Fetches the current state of a PayU order.
	 */
	public function get_order( string $payu_order_id ): array {
		$bearer   = $this->get_bearer();
		$response = wp_remote_get(
			$this->api_url . '/api/v2_1/orders/' . rawurlencode( $payu_order_id ),
			[
				'redirection' => 0,
				'sslverify'   => false,
				'timeout'     => 30,
				'headers'     => [
					'Authorization' => 'Bearer ' . $bearer,
				],
			]
		);

		$this->assert_no_wp_error( $response, 'get_order' );

		return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
	}

	/**
	 * Fetches info the card token.
	 * 
	 * @param string $token
	 * @param string $extCustomerId
	 * @param string $email
	 * @return null|array{
	 *     value:string,
	 *     brandImageUrl:string,
	 *     preferred:string,
	 *     status:string,
	 *     cardExpirationYear:string,
	 *     cardExpirationMonth:string,
	 *     cardNumberMasked:string,
	 *     cardScheme:string,
	 *     cardBrand:string
	 * 	}
	 */
	public function get_card_token( string $token, string $extCustomerId, string $email ) {
		$bearer   = $this->get_trusted_merchant_bearer( $extCustomerId, $email );
		$response = wp_remote_get(
			$this->api_url . '/api/v2_1/paymethods',
			[
				'redirection' => 0,
				'sslverify'   => false,
				'timeout'     => 30,
				'headers'     => [
					'Authorization' => 'Bearer ' . $bearer,
				],
			]
		);

		$this->assert_no_wp_error( $response, 'get_card_token' );
		
		$body = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
		$cardTokens = $body['cardTokens'] ?? [];

		if ( is_array( $cardTokens ) ) {
			foreach ( $cardTokens as $cardToken ) {
				$value = $cardToken['value'] ?? null;

				if ( $value == $token ) {
					return $cardToken;
				}
			}
		}

		return null;
	}

	/**
	 * Refund
	 * 
	 * @param string $payu_order_id
	 * @param mixed $amount
	 * @param string $description
	 * @throws Exception
	 * @return array
	 */
	public function refund_order( string $payu_order_id, ?float $amount, string $description ): array {
		$bearer = $this->get_bearer();

		$payload = [ 'refund' => [ 'description' => $description ] ];

		if ( $amount !== null && $amount > 0 ) {
			$payload['refund']['amount'] = (int) round( $amount * 100 );
		}

		$response = wp_remote_post(
			$this->api_url . '/api/v2_1/orders/' . rawurlencode( $payu_order_id ) . '/refunds',
			[
				'redirection' => 0,
				'sslverify'   => false,
				'timeout'     => 30,
				'headers'     => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $bearer,
				],
				'body'        => wp_json_encode( $payload ),
			]
		);

		$this->assert_no_wp_error( $response, 'refund_order' );

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$msg = $body['status']['statusDesc'] ?? 'Błąd zwrotu PayU';
			throw new Exception( sprintf( 'PayU refund (HTTP %d): %s', $code, $msg ) );
		}

		return $body ?? [];
	}

	/**
	 * Notification signature verification
	 * 
	 * @param string $raw_body
	 * @param string $signature_header
	 * @param string $second_key
	 * @return bool
	 */
	public static function verify_notification( string $raw_body, string $signature_header, string $second_key ): bool {
		if ( empty( $signature_header ) || empty( $second_key ) ) {
			return false;
		}

		$parts = [];
		foreach ( explode( ';', $signature_header ) as $part ) {
			$pair = explode( '=', $part, 2 );
			if ( count( $pair ) === 2 ) {
				$parts[ $pair[0] ] = $pair[1];
			}
		}

		if ( empty( $parts['signature'] ) ) {
			return false;
		}

		$expected = md5( $raw_body . $second_key );

		return hash_equals( $expected, strtolower( $parts['signature'] ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	public static function to_payu_amount( float $amount ): string {
		return (string) (int) round( $amount * 100 );
	}

	private function assert_no_wp_error( $response, string $context ): void {
		if ( is_wp_error( $response ) ) {
			throw new Exception( sprintf( 'PayU %s: %s', $context, $response->get_error_message() ) );
		}
	}
}
