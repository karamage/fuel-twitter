<?php
/**
 * Fuel Twitter Package
 *
 * This is a port of Elliot Haughin's CodeIgniter Twitter library.
 * You can find his library here http://www.haughin.com/code/twitter/
 *
 * @copyright  2011 Dan Horrigan
 * @license    MIT License
 */
 
namespace Twitter;

class Twitter_Connection {

	/**
	 * Multi Curl
	 */	
	protected $_mch = null;
	
	/**
	 * Curl
	 */	
	protected $_ch = null;
	
	/**
	 * Propperties
	 */
	protected $_properties = array();
	
	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->_mch = curl_multi_init();
		
		$this->_properties = array(
			'code' 		=> CURLINFO_HTTP_CODE,
			'time' 		=> CURLINFO_TOTAL_TIME,
			'length'	=> CURLINFO_CONTENT_LENGTH_DOWNLOAD,
			'type' 		=> CURLINFO_CONTENT_TYPE
		);
	}
	
	/**
	 * Initiates a Curl connection
	 *
	 * @param	string		$url	url to connect to
	 */
	protected function init_connection($url)
	{
		$this->_ch = curl_init($url);
		curl_setopt($this->_ch, CURLOPT_RETURNTRANSFER, true);
	}
	
	/**
	 * Executes a curl request using get
	 *
	 * @param	string	$url		url to connect to
	 * @param	array	$params		connection/request parameters
	 *
	 */
	public function get($url, $params)
	{
		$get = "";
		if(!empty($params['request'])){
			$get = http_build_query($params['request'], '', '&');
		}

		$this->init_connection($url."?".$get);
		$response = $this->add_curl($url, $params);

	    return $response;
	}
	
	/**
	 * Executes a curl request using get
	 *
	 * @param	string	$url		url to connect to
	 * @param	array	$params		connection/request parameters
	 *
	 */
	public function post($url, $params)
	{
        $post = "";
        if(!empty($params['request'])) {
            $post = http_build_query($params['request'], '', '&');
        }
		
		$this->init_connection($url);
		curl_setopt($this->_ch, CURLOPT_POST, 1);
		curl_setopt($this->_ch, CURLOPT_POSTFIELDS, $post);
		
		$response = $this->add_curl($url, $params);

	    return $response;
	}
	
	/**
	 * Adds OAuth headers
	 *
	 * @param	resource	curl resource
	 * @param	string		the url
	 * @param	array		the headers
	 */
	protected function add_oauth_headers(&$ch, $url, $oauth_headers)
	{
		$_h = array('Expect:');
        //$_h = array();
		$url_parts = parse_url($url);
		$oauth = 'Authorization: OAuth realm="' . $url_parts['path'] . '",';
        //$oauth = 'Authorization: OAuth ';

		foreach ( $oauth_headers as $name => $value )
		{
            /*
            if ($name == 'oauth_token') {
                continue;
            } else if ($name == 'oauth_callback') {
                $value = urlencode($value);
            }
            */
			$oauth .= "{$name}=\"{$value}\",";
		}
				
		$_h[] = substr($oauth, 0, -1);
        //\Log::warning('header=' . var_export($_h, true));

		curl_setopt($ch, CURLOPT_HTTPHEADER, $_h);
        //\Log::warning('ch=' . var_export($ch, true));
	}
	
	/**
	 * Adds a curl resource to the multi curl pile
	 *
	 * @param	string		the url
	 * @param	array		parameters
	 * @return	object		the curl response / Twitter_Oauth_Response
	 */
	protected function add_curl($url, $params = array())
	{
		if ( ! empty($params['oauth']) )
		{
			$this->add_oauth_headers($this->_ch, $url, $params['oauth']);
		}

		$ch = $this->_ch;
        $info = curl_getinfo($ch);
        //\Log::warning('url=' . $url);
        //\Log::warning('request info=' . var_export($info, true));

		$key = (string) $ch;
        //\Log::warning('key=' . $key);
		$this->_requests[$key] = $ch;

        //\Log::warning('request=' . var_export($this->_requests[$key], true));
		$response = curl_multi_add_handle($this->_mch, $ch);
        //\Log::warning('response=' . var_export($response, true));

		if ( $response === CURLM_OK or $response === CURLM_CALL_MULTI_PERFORM )
		{
			do
			{
				$mch = curl_multi_exec($this->_mch, $active);
			} 
			while($mch === CURLM_CALL_MULTI_PERFORM);
			
			return $this->get_response($key);
		}
		else
		{
			return $response;
		}
	}
	
	/**
	 * Returns a OAuth response.
	 *
	 * @param	string		the reponses key
	 * @return	object		the curl response / Twitter_Oauth_Response
	 */
	protected function get_response($key = null)
	{
		if (empty($key)) return false;
		
		if ( isset($this->_responses[$key]) )
		{
			return $this->_responses[$key];
		}
		
		$running = null;
		
		do
		{
			$response = curl_multi_exec($this->_mch, $running_curl);
			
			if ( $running !== null and $running_curl != $running )
			{
				$this->set_response($key);
				
				if (isset($this->_responses[$key]))
				{
					$response = new \Twitter_Oauth_Response( (object) $this->_responses[$key] );
					
					if ($response->__resp->code !== 200)
					{
                        \Log::warning('response err code' . $response->__resp->code);
                        \Log::warning('response err ' . var_export($response->__resp, true));
						throw new \TwitterException(isset($response->__resp->data->error) ? $response->__resp->data->error : $response->__resp->data->errors[0]->message, $response->__resp->code);
					}
					
					return $response;
				}
			}
			
			$running = $running_curl;
			
		} 
		while ($running_curl > 0);
		
	}
	
	/**
	 * Stores the curl response.
	 *
	 * @param	string		the reponses key
	 */
	protected function set_response($key)
	{
		while($done = curl_multi_info_read($this->_mch))
		{
			$key = (string) $done['handle'];
			$this->_responses[$key]['data'] = curl_multi_getcontent($done['handle']);
			
			foreach ( $this->_properties as $curl_key => $value )
			{
				$this->_responses[$key][$curl_key] = curl_getinfo($done['handle'], $value);
				curl_multi_remove_handle($this->_mch, $done['handle']);
			}
		}
	}
}
