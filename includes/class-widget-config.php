<?php

defined( 'ABSPATH' ) || exit;

/**
 * Builds the config (incl. signature) for PayU's hosted client-side
 * tokenization widget (`payu-bootstrap.js`).
 */
class Pethelp_PayU_Widget_Helper {

	public static function widget_url( bool $sandbox ): string {
		return $sandbox
			? 'https://secure.snd.payu.com/front/widget/js/payu-bootstrap.js'
			: 'https://secure.payu.com/front/widget/js/payu-bootstrap.js';
	}

	/**
	 * @param array{
	 *     pos_id:string,
	 *     second_key:string,
	 *     sandbox:bool,
	 *     amount:float,
	 *     currency:string,
	 *     email:string,
	 * } $args
	 * 
	 * @return array{
	 * 		widget_url:string,
	 * 		merchant_pos_id:string,
	 * 		total_amount:int,
	 * 		currency_code:string,
	 * 		customer_language:string,
	 * 		store_card:string,
	 * 		recurring_payment:string,
	 * 		customer_email:string,
	 * 		shop_name:string,
	 * 		sig:string
	 * }
	 */
	public static function build( array $args ): array {
		$merchant_pos_id   = (string) $args['pos_id'];
		$currency_code     = (string) $args['currency'];
		$customer_language = 'pl';
		$store_card        = 'true';
		$recurring_payment = 'true';
		$customer_email    = (string) $args['email'];
		$shop_name         = self::get_blog_alnum_name();
		// PayU's widget expects the amount already in grosze (integer minor units).
		$total_amount = (int) round( max( 0.0, (float) $args['amount'] ) * 100 );

		$sig_parameters = $currency_code . $customer_email . $customer_language . $merchant_pos_id
			. $recurring_payment . $shop_name . $store_card . $total_amount;

		$sig = hash( 'sha256', $sig_parameters . (string) $args['second_key'] );

		return [
			'widget_url'        => self::widget_url( (bool) $args['sandbox'] ),
			'merchant_pos_id'   => $merchant_pos_id,
			'total_amount'      => $total_amount,
			'currency_code'     => $currency_code,
			'customer_language' => $customer_language,
			'store_card'        => $store_card,
			'recurring_payment' => $recurring_payment,
			'customer_email'    => $customer_email,
			'shop_name'         => $shop_name,
			'sig'               => $sig,
		];
	}

	private static function get_blog_alnum_name(): string {
		$blog_name = (string) get_option( 'blogname', '' );
		$blog_name = (string) preg_replace( '/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $blog_name );
		return trim( $blog_name );
	}
}
