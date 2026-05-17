<?php
/**
 * NakoPay Webhook Verifier for VirtueMart
 *
 * @package  VirtueMart
 * @license  MIT
 * @version  1.0.0
 */

defined('_JEXEC') or die('Restricted access');

class NakoPayWebhook
{
    /**
     * @var int Maximum age of a webhook signature in seconds
     */
    private const MAX_AGE = 300; // 5 minutes

    /**
     * @var string Webhook secret (whsec_*)
     */
    private $secret;

    /**
     * @param string $secret Webhook secret from NakoPay dashboard
     */
    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }

    /**
     * Verify and parse a webhook payload
     *
     * @param  string $body       Raw request body
     * @param  string $signature  Value of the Webhook-Signature header
     * @return array|null         Parsed event on success, null on failure
     */
    public function verify(string $body, string $signature): ?array
    {
        if (empty($signature) || empty($body)) {
            return null;
        }

        // Parse signature header: t=<timestamp>,v1=<hash>
        $parts = [];
        foreach (explode(',', $signature) as $segment) {
            $kv = explode('=', $segment, 2);
            if (count($kv) === 2) {
                $parts[$kv[0]] = $kv[1];
            }
        }

        $timestamp = $parts['t'] ?? null;
        $hash      = $parts['v1'] ?? null;

        if (!$timestamp || !$hash) {
            JLog::add('NakoPay webhook: malformed signature header', JLog::WARNING, 'nakopay');
            return null;
        }

        // Replay protection
        $age = abs(time() - (int) $timestamp);
        if ($age > self::MAX_AGE) {
            JLog::add('NakoPay webhook: signature expired (' . $age . 's old)', JLog::WARNING, 'nakopay');
            return null;
        }

        // Compute expected signature
        $signedPayload = $timestamp . '.' . $body;
        $expected      = hash_hmac('sha256', $signedPayload, $this->secret);

        if (!hash_equals($expected, $hash)) {
            JLog::add('NakoPay webhook: signature mismatch', JLog::WARNING, 'nakopay');
            return null;
        }

        $event = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            JLog::add('NakoPay webhook: JSON decode error', JLog::WARNING, 'nakopay');
            return null;
        }

        return $event;
    }
}
