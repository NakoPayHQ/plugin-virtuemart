<?php
/**
 * NakoPay API client for VirtueMart
 *
 * @package  VirtueMart
 * @license  MIT
 * @version  1.0.0
 */

defined('_JEXEC') or die('Restricted access');

class NakoPayApi
{
    /**
     * @var string API base URL (branded, primary)
     */
    private static $apiBase = 'https://api.nakopay.com/v1';

    /**
     * @var string Fallback API base URL (origin Supabase edge functions)
     */
    private static $apiBaseFallback = 'https://daslrxpkbkqrbnjwouiq.supabase.co/functions/v1';

    /**
     * @var string Secret API key
     */
    private $apiKey;

    /**
     * @var bool Sandbox mode
     */
    private $sandbox;

    /**
     * @param string $apiKey  Secret key (sk_test_* or sk_live_*)
     * @param bool   $sandbox Whether to use sandbox mode
     */
    public function __construct(string $apiKey, bool $sandbox = false)
    {
        $this->apiKey  = $apiKey;
        $this->sandbox = $sandbox;
    }

    /**
     * Create a NakoPay invoice
     *
     * @param  array $params  Invoice parameters
     * @return array|null     Parsed response or null on failure
     */
    public function createInvoice(array $params): ?array
    {
        $url = self::$apiBase . '/invoices-create';

        $payload = json_encode($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'User-Agent: NakoPay-VirtueMart/1.0.0',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            JLog::add('NakoPay API curl error: ' . $error, JLog::ERROR, 'nakopay');
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            JLog::add('NakoPay API HTTP ' . $httpCode . ': ' . $response, JLog::ERROR, 'nakopay');
            return null;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            JLog::add('NakoPay API JSON decode error: ' . json_last_error_msg(), JLog::ERROR, 'nakopay');
            return null;
        }

        return $data;
    }
}
