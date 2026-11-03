<?php
defined('ABSPATH') || exit;

/**
 * /wp-json/inid/v1/webhook  InID → store: create the customer, record abandoned checkouts
 * /wp-json/inid/v1/login    page → store: sign the buyer in with a token from the pixel
 */
class InID_Rest
{
    /** @var InID_Settings */
    private $settings;
    /** @var InID_Client */
    private $client;
    /** @var InID_Customers */
    private $customers;

    public function __construct(InID_Settings $settings, InID_Client $client, InID_Customers $customers)
    {
        $this->settings = $settings;
        $this->client = $client;
        $this->customers = $customers;
    }

    public function register(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route('inid/v1', '/webhook', [
                'methods' => 'POST',
                'callback' => [$this, 'webhook'],
                'permission_callback' => '__return_true', // authenticated by its signature
            ]);
            register_rest_route('inid/v1', '/login', [
                'methods' => 'POST',
                'callback' => [$this, 'login'],
                'permission_callback' => '__return_true', // authenticated by the one-time token
            ]);
        });
    }

    public function webhook(WP_REST_Request $request)
    {
        if (!$this->client->verify_webhook($request->get_body(), (string) $request->get_header('inid_signature'))) {
            return new WP_REST_Response(['error' => 'bad_signature'], 401);
        }
        $event = json_decode($request->get_body(), true);
        $customer = $this->client->exchange((string) ($event['token'] ?? ''));
        if (is_wp_error($customer)) {
            return new WP_REST_Response(['error' => $customer->get_error_code()], 400);
        }
        if (($event['type'] ?? '') === 'checkout.abandoned') {
            $this->customers->record_abandoned($customer);
            return ['ok' => true];
        }
        $user_id = $this->customers->upsert($customer);
        if (is_wp_error($user_id)) {
            return new WP_REST_Response(['error' => $user_id->get_error_code()], 500);
        }
        return ['ok' => true, 'customer' => $user_id];
    }

    // The buyer proved who they are with InID: the account is theirs (found or created
    // by sub) and this browser gets WordPress's session cookie
    public function login(WP_REST_Request $request)
    {
        $customer = $this->client->exchange((string) $request->get_param('token'));
        if (is_wp_error($customer)) {
            return new WP_REST_Response(['error' => $customer->get_error_code()], 401);
        }
        $user_id = $this->customers->upsert($customer);
        if (is_wp_error($user_id)) {
            return new WP_REST_Response(['error' => $user_id->get_error_code()], 500);
        }
        wp_set_current_user($user_id);
        // Nonces made in this same request must belong to the new session (see "stay")
        add_action('set_logged_in_cookie', function ($cookie) {
            $_COOKIE[LOGGED_IN_COOKIE] = $cookie;
        });
        wp_set_auth_cookie($user_id, true, is_ssl());
        do_action('wp_login', get_userdata($user_id)->user_login, get_userdata($user_id));
        // From a checkout InID just filled ("stay"): logged in, the checkout in progress
        // holds the data, and the buyer stays there
        if ($request->get_param('stay')) {
            $this->customers->prefill_checkout($user_id, $customer);
            // The block checkout's nonce was the guest's: it gets the logged-in one
            return [
                'ok' => true,
                'given_name' => (string) ($customer['given_name'] ?? ''),
                'store_api_nonce' => wp_create_nonce('wc_store_api'),
            ];
        }
        return ['ok' => true, 'redirect' => wc_get_page_permalink('myaccount')];
    }
}
