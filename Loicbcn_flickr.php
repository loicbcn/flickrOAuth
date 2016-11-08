<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Loicbcn_flickr {

    // flickr api key
    const KEY    = 'flickrApi_Key';
    // flickr api secret
    const SECRET = 'Flickr_Api_Secret';
    // Si vous avez un proxy: 'mon.proxy.domaine:port',
    const PROXY = NULL;

    private $method = 'GET';
    private $ci;
    private $request_token_url = "https://www.flickr.com/services/oauth/request_token";
    private $authorize_url     = "https://www.flickr.com/services/oauth/authorize";
    private $access_token_url  = "https://www.flickr.com/services/oauth/access_token";
    private $api_call          = "https://api.flickr.com/services/rest";

    private $access_token_file_path  = "cache/flickr_access_token.php";
    private $request_token_file_path = "cache/flickr_request_token.php";

    private $fullname;
    private $oauth_token;
    private $oauth_token_secret;
    private $user_nsid;
    private $username;
    private $callback_url;

    public function __construct()
    {
        $this->ci = get_instance();
        $this->callback_url = site_url($this->ci->router->fetch_class() .'/'. $this->ci->router->fetch_method());

        if ($this->ci->input->get('oauth_token') && $this->ci->input->get('oauth_verifier')) {
            $this->getAccessToken(
                $this->ci->input->get('oauth_token', TRUE),
                $this->ci->input->get('oauth_verifier', TRUE)
            );
        }

        $this->getAccessTokenFromCache();
    }


    /**
     * Try to get tokens from cache
     *
     */
    private function getAccessTokenFromCache(){
        if (file_exists(APPPATH . $this->access_token_file_path)) {
            require_once APPPATH . $this->access_token_file_path;
            if(isset($p) && is_array($p) && key_exists("fullname", $p)) {
                $this->fullname = $p['fullname'];
                $this->oauth_token = $p['oauth_token'];
                $this->oauth_token_secret = $p['oauth_token_secret'];
                $this->user_nsid = $p['user_nsid'];
                $this->username = $p['username'];
            } else {
                $this->authme();
            }
        } else {
            $this->authme();
        }
    }

    /**
     * start of the authnmtification process
     *
     */
    public function authme()
    {
        $parameters = array(
            "oauth_nonce" => $this->generate_nonce(),
            "oauth_timestamp" => time(),
            "oauth_consumer_key" => self::KEY,
            "oauth_signature_method" => "HMAC-SHA1",
            "oauth_version" => "1.0",
            "oauth_callback" => $this->callback_url
        );

        $signature = $this->createSignature($this->request_token_url, $parameters);

        $parameters["oauth_signature"] = $signature;

        $jeton_str = $this->httpRequest($this->request_token_url, $parameters);
        parse_str($jeton_str, $jeton);

        $this->savparams($this->request_token_file_path, $jeton);

        $jeton['perms'] = 'delete';

        redirect($this->authorize_url .'?oauth_token='. $jeton['oauth_token'] .'&perms='. $jeton['perms']);
    }

    /**
     * get access_token from the user authorisation
     * save the result as an array in cache/flickr.php
     *
     * @param string $oauth_token
     * @param string $oauth_verifier
     *
     */
    public function getAccessToken($oauth_token, $oauth_verifier){
        if (file_exists(APPPATH . $this->request_token_file_path)) {
            require_once APPPATH . $this->request_token_file_path;
        } else {
            throw new Exception("flickr_request_token.php does not exist in cache directory.");
        }

        if (!isset($p) || !is_array($p) || !isset($p['oauth_token_secret'])) {
            throw new Exception("Request token does not exist");
        }

        $parameters = array(
            "oauth_nonce" => $this->generate_nonce(),
            "oauth_timestamp" => time(),
            "oauth_verifier"  => $oauth_verifier,
            "oauth_consumer_key" => self::KEY,
            "oauth_signature_method" => "HMAC-SHA1",
            "oauth_version" => "1.0",
            "oauth_token" => $oauth_token
        );

        $signature = $this->createSignature($this->access_token_url, $parameters, $p['oauth_token_secret']);
        $parameters["oauth_signature"] = $signature;

        $access_params_str = $this->httpRequest($this->access_token_url, $parameters);
        parse_str($access_params_str, $access_params);

        $this->savparams($this->access_token_file_path, $access_params);
    }


    /**
     * Create a signature
     *
     * @param string $url
     * @param array $parameters
     * @param string $oauth_token_secret
     * @return string $oauth_signature
     *
     */
    private function createSignature($url, $parameters, $oauth_token_secret = ''){
        $str_params = "GET&". urlencode($url) .'&'. urlencode($this->joinParameters($parameters));
        $hashkey = self::SECRET .'&'. $oauth_token_secret;
        $oauth_signature = base64_encode(hash_hmac('sha1', $str_params, $hashkey, true));
        return $oauth_signature;
    }


    /**
     * Prepare to call "flickr.people.getPhotos"
     *
     * @param array $params (see flickr api doc for all the accepted parameters)
     * @return flickr result in a php array
     */
    public function getPhotos($params = array()){
        $base_params = array(
            "method" => "flickr.people.getPhotos",
            "user_id" => $this->user_nsid
        );

        return $this->call(array_merge($base_params, $params));
    }


    /**
     * Send a signed request to the flickr api
     * @param array $parameters (with the method)
     * @return flickr result in a php array
     */
    public function call($parameters){
        $parameters["oauth_nonce"] = $this->generate_nonce();
        $parameters["format"] = "json";
        $parameters["nojsoncallback"] = 1;
        $parameters["oauth_consumer_key"] = self::KEY;
        $parameters["oauth_timestamp"] = time();
        $parameters["oauth_signature_method"] = "HMAC-SHA1";
        $parameters["oauth_version"] = "1.0";
        $parameters["oauth_token"] = $this->oauth_token;

        $parameters["oauth_signature"] = $this->createSignature($this->access_token_url, $parameters, $this->oauth_token_secret);
        $result = json_decode($this->httpRequest($this->api_call, $parameters), true);

        if ($result && is_array($result) && isset($result["stat"]) && $result["stat"] == "fail") {
            throw new Exception($result["code"] ." : ". $result["message"]);
        }

        return $result;
    }


    /**
     * Make an HTTP request
     * From https://github.com/hiddentao/wp-flickr-embed/blob/master/include/class.flickr.php
     * @param string $url
     * @param array $parameters
     * @return mixed
     */
    private function httpRequest($url, $parameters)
    {
        $curl = curl_init();

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);

        if ($_SERVER['SERVER_NAME'] == '127.0.0.1' && self::PROXY) {
            curl_setopt($curl, CURLOPT_HTTPPROXYTUNNEL, true);
            curl_setopt($curl, CURLOPT_PROXY, self::PROXY);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        }

        if ($this->method == 'POST')
        {
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_POST, TRUE);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $parameters);
        }
        else
        {
            // Assume GET
            curl_setopt($curl, CURLOPT_URL, "$url?" . $this->joinParameters($parameters));
        }

        $response = curl_exec($curl);
        $headers = curl_getinfo($curl);

        curl_close($curl);

       // $this->lastHttpResponseCode = $headers['http_code'];

        return $response;
    }


    /**
     * Join an array of parameters together into a URL-encoded string
     * From https://github.com/hiddentao/wp-flickr-embed/blob/master/include/class.flickr.php
     * @param array $parameters
     * @return string
     */
    private function joinParameters($parameters)
    {
        $keys = array_keys($parameters);
        sort($keys, SORT_STRING);
        $keyValuePairs = array();
        foreach ($keys as $k)
        {
            array_push($keyValuePairs, rawurlencode($k) . "=" . rawurlencode($parameters[$k]));
        }

        return implode("&", $keyValuePairs);
    }

    /**
     * Create a nonce
     * from http://api.drupal.psu.edu/api/drupal/modules%21contrib%21oauth%21lib%21OAuth.php/function/OAuthRequest%3A%3Agenerate_nonce/cis7
     * @return string
     */
    private static function generate_nonce() {
        $mt = microtime();
        $rand = mt_rand();

        return md5($mt . $rand); // md5s look nicer than numbers
    }

    /**
     * Save a file in filepath with a php array inside
     *
     * @param filepath: path in application folder ex: cache/flickr_request_token.php
     * @param array $params: key => value or parameters
     * @write a file
     */
    public function savparams($filepath, $params = array()){
        $this->ci->load->helper('file');

        @unlink(APPPATH . $filepath);

        $data = array();
        $data[] = '<?php';
        foreach ($params as $kp=>$p) {
            $data[] = '$p[\'' . $kp . '\'] = \'' . $p . '\';';
        }
        $output = implode("\n", $data);
        write_file(APPPATH . $filepath, $output);
    }

}