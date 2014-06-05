<?php

/**
 * Class Coinzone
 */
class Coinzone
{
    /**
     * Coinzone API URL
     */
    const API_URL = 'http://api.coinzone.web/v1/';

    /**
     * @var string
     */
    private $clientCode;

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var array
     */
    private $headers;

    /**
     * @param $clientCode
     * @param $apiKey
     */
    public function __construct($clientCode, $apiKey)
    {
        $this->clientCode = $clientCode;
        $this->apiKey = $apiKey;
    }

    /**
     * @param $path
     * @param array $payload
     */
    private function prepareRequest($path, array $payload)
    {
        $timestamp = time();
        if (!empty($payload)) {
            $payload = json_encode($payload);
        }
        $stringToSign = $payload . self::API_URL.$path . $timestamp;
        $signature = hash_hmac('sha256', $stringToSign, $this->apiKey);

        $this->headers = array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
            'clientCode: ' . $this->clientCode,
            'timestamp: ' . $timestamp,
            'signature: ' . $signature
        );
    }

    /**
     * @param $path
     * @param $payload
     * @return mixed|string
     */
    public function callApi($path, $payload = '')
    {
        $this->prepareRequest($path, $payload);

        $url = self::API_URL . $path;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);

        if (!empty($payload)) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $result = curl_exec($ch);
        if ($result === false) {
            return false;
        }
        $response = json_decode($result);
        curl_close($ch);

        return $response;
    }
}
