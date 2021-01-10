<?php
/**
 * PayPal Integration Library
 * Access PayPal for payments integration
 *
 * @author SnowRunescape
 *
 */

require_once 'PayPalException.php';
require_once 'PayPalRestClient.php';

class PayPal {
    const version = '0.0.1';

    private $email;
    private $ll_access_token;
	
    private $sandbox = FALSE;

	private $allowedCurrencies = [
		'AUD', 'BRL', 'CAD', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS',
		'INR', 'JPY', 'MYR', 'MXN', 'NOK', 'NZD', 'PHP', 'PLN', 'GBP',
		'SGD', 'SEK', 'CHF', 'TWD', 'THB', 'USD', 'RUB'
	];

    function __construct(){
        $i = func_num_args();
        
        if($i == 1){
            $this->email = func_get_arg(0);
        } else {
			throw new PayPalException('Invalid arguments. Use only EMAIL.');
		}
    }

    public function sandbox_mode($enable = NULL){
        if(!is_null($enable)){
            $this->sandbox = $enable === TRUE;
        }

        return $this->sandbox;
    }

    /**
     * Get Access Token for API use
	 *
	 * @return String
     */
    private function get_access_token(){
		if((isset($this->ll_access_token)) && (!is_null($this->ll_access_token))){
			return $this->ll_access_token;
		}
		
		$access_data = PayPalRestClient::post([
			'uri' => '/v1/oauth2/token',
			'data' => 'grant_type=client_credentials',
			'USERPWD' => "{$this->client_id}:{$this->client_secret}"
		]);
		
		if($access_data["status"] != 200){
			throw new PayPalException($access_data['response']['message'], $access_data['status']);
		}
		
		$this->ll_access_token = $access_data['response'];
		
        return $this->ll_access_token;
    }
	
    /**
     * Create a checkout preference
	 *
     * @param array $preference
	 *
     * @return String
     */
    public function create_preference($preference){
		if(!isset($preference['items'], $preference['currency'])){
			throw new PayPalException('Invalid arguments.');
		}
		
		if(!in_array($preference['currency'], $this->allowedCurrencies)){
			throw new PayPalException('Currency is not supported by PayPal.');
		}
		
		$preferenceObj = [
			'cmd' => '_cart',
			'upload' => 1,
			'business' => $this->email,
			'currency_code' => $preference['currency'],
		];
		
		foreach($preference['items'] as $id => $item){
			$id = $id + 1;
			
			if(isset($item['name'], $item['amount'], $item['quantity'])){
				$preferenceObj["item_name_{$id}"] = $item['name'];
				$preferenceObj["amount_{$id}"] = $item['amount'];
				$preferenceObj["quantity_{$id}"] = $item['quantity'];
			} else {
				throw new PayPalException('Items format invalid.');
			}
		}
		
		if(isset($preference['custom'])) $preferenceObj['custom'] = $preference['custom'];
		
		if(isset($preference['redirect_urls']['return_url'])) $preferenceObj['return'] = $preference['redirect_urls']['return_url'];
		if(isset($preference['redirect_urls']['cancel_url'])) $preferenceObj['cancel_return'] = $preference['redirect_urls']['cancel_url'];
		
		if(isset($preference['notify_url'])){
			$preferenceObj['notify_url'] = $preference['notify_url'];
		}
		
		$query_string = http_build_query($preferenceObj);
		
		$headers = @get_headers("https://" . ($this->sandbox_mode() ? 'sandbox' : 'www') . ".paypal.com/cgi-bin/webscr?{$query_string}");
		
		foreach($headers as $h){
			if(substr($h, 0, 10) == 'Location: '){
				return trim(substr($h, 10));
			}
		}
		
		throw new PayPalException('Error generating payment link.');
    }
	
    /**
     * Process IPN
	 *
     * @return boolean
     */
	public function ValidIPN(){
		if(empty($_POST)){
			throw new PayPalException('No POST data found.');
		}
		
		$curl = curl_init();

		curl_setopt($curl, CURLOPT_URL, 'https://' . ($this->sandbox_mode() ? 'sandbox' : 'www') . '.paypal.com/cgi-bin/webscr?cmd=_notify-validate');
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($_POST));

		$response = curl_exec($curl);
		$error = curl_error($curl);
		$errno = curl_errno($curl);

		curl_close($curl);
		
		if($response == 'VERIFIED'){
			return true;
		} else if($response == 'INVALID'){
			return false;
		} else {
			throw new PayPalException('Unexpected response from PayPal.');
		}
	}
}