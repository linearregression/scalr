<?php

use Scalr\System\Http\Client\Request;

class Scalr_Service_Cloud_GoGrid_Connection
{
        protected	$apiKey;
        protected	$secretKey;
        protected	$signature;
        private		$LastResponseHeaders	= array();
        private		$xSessionUrl			= null;		// session id which returned as X-Server-Management-Url  from header
        private		$httpRequest			= null;

        const		FORMAT_JSON				= "json";	// default format
        const		API_VERSION				= "1.0";
        const		URL						= "https://api.gogrid.com/api";

        public function __construct($key, $secret)
        {
            $this->apiKey		= $key;
            $this->secretKey	= $secret;
            $this->signature	= $this->getSignature($key, $secret);
            $this->httpRequest	= new Request();
        }


        private function getSignature($key, $secret)
        {
            $timestamp = $this->GetTimestamp("GMT");
            $sig = md5($key . $secret . $timestamp);
            return $sig;
        }

        /**
        * return a timestamp in GMT zone
        *
        * @name  GetTimestamp
        * @param  string $zoneName
        * return
        *
        */
          private function GetTimestamp($zoneName)
        {
            $tz = @date_default_timezone_get();
            date_default_timezone_set($zoneName);
            $timeInZone = time();
            date_default_timezone_set($tz);
            return $timeInZone;
        }


        /**
        * Makes request itself to the set or default url
        *
        * @name  sendRequest
        * @param mixed $url
        * @return array  $data
        */
        private function sendRequest()
        {
            try
            {
                $response = \Scalr::getContainer()->http->sendRequest($this->httpRequest);

                $data = $response->getBody()->toString();
                $this->LastResponseHeaders = $response->getHeaders();

                if($response->getResponseCode() >= 400)
                {

                    $errMsg = json_decode($data);
                    $errMsg = $errMsg->list[0]->message;

                    throw new Exception("Request to GoGrid failed (Error code: {$response->getResponseCode()}): {$errMsg}");
                }
            }
            catch (Exception $e)
            {
                if ($e->innerException)
                    $message = $e->innerException->getMessage();
                else
                    $message = $e->getMessage();

                throw new Exception($message);
            }

            return $data;
        }


        /**
        * makes a request to the cloud server
        *
        * @name request
        * @param mixed $method
        * @param mixed $uri
        * @param mixed $args
        * @param mixed $url
        * @return	mixed $response
        */
        protected function request($method, $uri = "", $args = null)
        {
            try
            {
                $url =  self::URL."/{$uri}";
                $this->setRequestOptions($url, $args);
                $response = $this->sendRequest();
            }
            catch (Exception $e)
            {
               throw $e;
            }

            return json_decode($response['body']);
        }


        /**
         * Set request headers and options
         *
         * @param mixed $url
         * @param mixed $args
         */
        private function setRequestOptions($url, $args = null)
        {

            $this->httpRequest->setRequestUrl($url);

            $this->httpRequest->setOptions([
                "redirect" => 10,
            ]);

            $this->httpRequest->setRequestMethod("GET");

            $args['api_key'] 	= $this->apiKey;
            $args['v']			= self::API_VERSION;
            $args['sig']		= $this->signature;

            // set GET body's args
            ksort($args);

            $c11dQueryString = http_build_query($args, null, '&', PHP_QUERY_RFC3986);
            $this->httpRequest->setQuery($c11dQueryString);

        }
}
