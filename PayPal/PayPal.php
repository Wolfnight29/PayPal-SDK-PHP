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
			'no_shipping' => '1',
			'address_override' => '0'
		];
		
		foreach($preference['items'] as $id => $item){
			$id = $id + 1;
			
			if(isset($item['name'], $item['amount'], $item['quantity'])){
				if(isset($item['id'])) $preferenceObj["item_number_{$id}"] = $item['id'];
				
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
	public function validIPN($raw_post_data){
		$raw_post_array = explode('&', $raw_post_data);
		
		$myPost = [];
		
		foreach($raw_post_array as $keyval){
			$keyval = explode('=', $keyval);
			
			if(count($keyval) == 2){
				$myPost[$keyval[0]] = urldecode($keyval[1]);
			}
		}
		
		$req = 'cmd=_notify-validate';
		
		foreach($myPost as $key => $value){
			$value = urlencode($value);
			
			$req .= "&{$key}={$value}";
		}
		
		$ch = curl_init('https://ipnpb.paypal.com/cgi-bin/webscr');
		
		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Connection: Close'));
		
		$response = curl_exec($ch);
		
		curl_close($ch);
		
		if($response == 'VERIFIED'){
			return true;
		} else if($response == 'INVALID'){
			return false;
		}
		
		throw new PayPalException('Unexpected response from PayPal.');
	}
	
	    /**
     * Process IPN
	 *
     * @return boolean
     */
	public function getArrayIPN($raw_post){
		$array = [];
		
		if(!empty($raw_post)){
			if(substr($raw_post, -1) == '"' && substr($raw_post, 0, 1) == '"'){
				$raw_post = substr($raw_post, 1, -1);
			}
			
			$pairs = explode('&', $raw_post);
			
			foreach($pairs as $pair){
				list($key, $value) = explode('=', $pair, 2);
				
				$key   = utf8_encode(urldecode($key));
				$value = utf8_encode(urldecode($value));
				
				//preg_match('/(\w+)(?:\[(\d+)\])?(?:\.(\w+))?/', $key, $key_parts);
				preg_match('/(\w+)(?:(?:\[|\()(\d+)(?:\]|\)))?(?:\.(\w+))?/', $key, $key_parts);
				
				switch(count($key_parts)){
					case 4:
						// Original key format: somekey[x].property
						// Converting to $array[somekey][x][property]
						$array[$key_parts[1]][$key_parts[2]][$key_parts[3]] = $value;
						break;
					case 3:
						// Original key format: somekey[x] Converting to $array[somkey][x]
						$array[$key_parts[1]][$key_parts[2]] = $value;
						break;
					default:
						// No special format
						$array[$key] = str_replace('^^^', '&', $value);
						break;
				}
			}
		}
		
		return $array;
	}
}
