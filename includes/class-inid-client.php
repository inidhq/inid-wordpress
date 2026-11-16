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

    /** Sign-in with InID: the authorization code traded for tokens (the site's keys, Basic) */
    public function oidc_token(string $code, string $verifier, string $redirect_uri)
    {
        $response = wp_remote_post($this->settings->api_url() . '/oauth/token', [
            'headers' => ['Authorization' => $this->basic(), 'Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect_uri, 'code_verifier' => $verifier],
            'timeout' => 8,
        ]);
        return $this->decode($response);
    }

    /** Ends a sign-in at InID (its refresh token); the person's InID stays signed in */
    public function revoke(string $refresh_token): void
    {
        wp_remote_post($this->settings->api_url() . '/oauth/revoke', [
            'headers' => ['Authorization' => $this->basic(), 'Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => ['token' => $refresh_token, 'token_type_hint' => 'refresh_token'],
            'timeout' => 3,
        ]);
    }

    /** One person of this site's app, as InID has them now */
    public function app_user(string $sub)
    {
        $response = wp_remote_get($this->settings->api_url() . '/v1/users/' . rawurlencode($sub), [
            'headers' => ['Authorization' => 'Bearer ' . $this->settings->get('secret_key')],
            'timeout' => 5,
        ]);
        return $this->decode($response);
    }

    /** Invitations, up to 200 at once: [{ email, role? }] → { invited, failed } */
    public function invite_batch(array $invitations)
    {
        return $this->post('/v1/invitations', ['invitations' => array_values($invitations)]);
    }

    /** The app's roles with their permissions, its default role and its entrances */
    public function app_roles()
    {
        $response = wp_remote_get($this->settings->api_url() . '/v1/roles', [
            'headers' => ['Authorization' => 'Bearer ' . $this->settings->get('secret_key')],
            'timeout' => 5,
        ]);
        return $this->decode($response);
    }

    private function basic(): string
    {
        return 'Basic ' . base64_encode(rawurlencode($this->settings->get('publishable_key')) . ':' . rawurlencode($this->settings->get('secret_key')));
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

    /** The store's week in words, written by InID from its totals (?fresh=1 writes it again) */
    public function insights(bool $fresh = false)
    {
        $response = wp_remote_get($this->settings->analytics_url() . '/v1/insights' . ($fresh ? '?fresh=1' : ''), [
            'headers' => ['Authorization' => 'Bearer ' . $this->settings->get('secret_key')],
            'timeout' => 60,
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

    /** The store's offer for buyers who left at its checkout (null turns it off) */
    public function account()
    {
        $response = wp_remote_get($this->settings->merchant_url() . '/v1/account', [
            'headers' => ['Authorization' => 'Bearer ' . $this->settings->get('secret_key')],
            'timeout' => 8,
        ]);
        return $this->decode($response);
    }

    public function set_offer(?array $offer)
    {
        $response = wp_remote_request($this->settings->merchant_url() . '/v1/offer', [
            'method' => $offer ? 'PUT' : 'DELETE',
            'headers' => array_merge(['Authorization' => 'Bearer ' . $this->settings->get('secret_key')], $offer ? ['Content-Type' => 'application/json'] : []),
            'body' => $offer ? wp_json_encode($offer) : null,
            'timeout' => 8,
        ]);
        return $this->decode($response);
    }

    /** This site proves its domain and gets its organization's keys, with no key yet (see InID_Connect) */
    public function connect(array $body)
    {
        $response = wp_remote_post($this->settings->merchant_url() . '/v1/connect', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($body),
            // InID reads this site's proof before it answers
            'timeout' => 20,
        ]);
        return $this->decode($response);
    }

    /** A link that signs the person in to the panel, at this organization */
    public function panel_link()
    {
        $response = wp_remote_post($this->settings->merchant_url() . '/v1/panel-link', [
            'headers' => ['Authorization' => 'Bearer ' . $this->settings->get('secret_key'), 'Content-Type' => 'application/json'],
            'body' => '{}',
            'timeout' => 8,
        ]);
        return $this->decode($response);
    }

    /** Asks InID to look for the organization's proof on its domains (see InID_Domain) */
    public function verify_domains()
    {
        $response = wp_remote_post($this->settings->merchant_url() . '/v1/domains/verify', [
            'headers' => ['Authorization' => 'Bearer ' . $this->settings->get('secret_key'), 'Content-Type' => 'application/json'],
            'body' => '{}',
            'timeout' => 15,
        ]);
        return $this->decode($response);
    }

    /** InID-Signature: t=<seconds>,v1=<HMAC-SHA256 of "t.body"> with the webhook secret. */
    // "Avísame": new prices of the store's products; InID answers how many watched carts got cheaper
    public function prices(array $items)
    {
        return $this->post('/v1/catalog/prices', ['items' => $items]);
    }

    // "Avísame": what a cart had (products, quantities, the offer's code), to rebuild it
    public function cart(string $watch_id)
    {
        $response = wp_remote_get($this->settings->api_url() . '/v1/carts/' . rawurlencode($watch_id), [
            'headers' => ['Authorization' => 'Bearer ' . $this->settings->get('secret_key')],
            'timeout' => 5,
        ]);
        return $this->decode($response);
    }

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
