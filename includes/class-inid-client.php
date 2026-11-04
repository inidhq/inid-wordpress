<?php
defined('ABSPATH') || exit;

/**
 * Talks to InID from this server: exchanges one-time tokens for a buyer's data, invites
 * customers the store already has, reads the funnel and verifies webhooks.
 */
class InID_Client
{
    const SIGNATURE_TOLERANCE = 300;

    /** @var InID_Settings */
    private $settings;

    public function __construct(InID_Settings $settings)
    {
        $this->settings = $settings;
    }

    /** A token from the pixel or a webhook, for the buyer's data. Each token works once. */
    public function exchange(string $token)
    {
        return $this->post('/v1/tokens/exchange', ['token' => $token]);
    }

    /** Invite a logged-in customer to save what the store already has in InID. */
    public function invite(array $customer)
    {
        $result = $this->post('/v1/enrollments', $customer);
        if (is_wp_error($result)) {
            return $result;
        }
        $parts = explode('/e/', (string) ($result['url'] ?? ''));
        return end($parts);
    }

    /** The store's funnel for the last days: counts only. */
    public function funnel(int $days = 7)
    {
        $response = wp_remote_get($this->settings->analytics_url() . '/v1/funnel?days=' . $days, [
            'headers' => ['Authorization' => 'Bearer ' . $this->settings->get('secret_key')],
            'timeout' => 8,
        ]);
        return $this->decode($response);
    }

    /** The store's audience: totals and shares of the buyers with InID who visited it */
    public function audience(int $days = 30)
    {
        $response = wp_remote_get($this->settings->analytics_url() . '/v1/audience?days=' . $days, [
            'headers' => ['Authorization' => 'Bearer ' . $this->settings->get('secret_key')],
            'timeout' => 8,
        ]);
        return $this->decode($response);
    }

    /** InID-Signature: t=<seconds>,v1=<HMAC-SHA256 of "t.body"> with the webhook secret. */
    public function verify_webhook(string $raw_body, string $header): bool
    {
        $secret = $this->settings->get('webhook_secret');
        if ($secret === '' || $header === '') {
            return false;
        }
        $parts = [];
        foreach (explode(',', $header) as $piece) {
            $pair = explode('=', $piece, 2);
            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }
        $at = isset($parts['t']) ? (int) $parts['t'] : 0;
        if (!$at || empty($parts['v1']) || abs(time() - $at) > self::SIGNATURE_TOLERANCE) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $at . '.' . $raw_body, $secret), $parts['v1']);
    }

    private function post(string $path, array $body)
    {
        $response = wp_remote_post($this->settings->api_url() . $path, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->settings->get('secret_key'),
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
            'timeout' => 8,
        ]);
        return $this->decode($response);
    }

    private function decode($response)
    {
        if (is_wp_error($response)) {
            return $response;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('inid_' . ($data['error'] ?? 'request_failed'), 'InID: ' . ($data['error'] ?? 'request failed'), ['status' => $code]);
        }
        return is_array($data) ? $data : [];
    }
}
