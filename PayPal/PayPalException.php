<?php
/**
 * PayPal Integration Library
 * Access PayPal for payments integration
 *
 * @author SnowRunescape
 *
 */

class PayPalException extends Exception {
	public function __construct($message, $code = 500, Exception $previous = null){
		// Default code 500
		parent::__construct($message, $code, $previous);
	}
}