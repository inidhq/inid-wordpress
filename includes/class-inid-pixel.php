<?php
defined('ABSPATH') || exit;

/**
 * The InID pixel on every storefront page, "Iniciar sesión con InID" on My Account, and
 * the invitation for logged-in customers who aren't in InID yet.
 */
class InID_Pixel
{
    /** @var InID_Settings */
    private $settings;
    /** @var InID_Client */
    private $client;

    public function __construct(InID_Settings $settings, InID_Client $client)
    {
        $this->settings = $settings;
        $this->client = $client;
    }

    public function register(): void
    {
        if (!$this->settings->ready()) {
            return;
        }
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_filter('script_loader_tag', [$this, 'tag'], 10, 2);
        if ($this->settings->get('signin_on_account') === 'yes') {
            add_action('woocommerce_login_form_start', [$this, 'signin_button']);
        }
    }

    public function enqueue(): void
    {
        if (is_admin()) {
            return;
        }
        wp_enqueue_script('inid-pixel', rtrim($this->settings->get('public_url'), '/') . '/p.js', [], null, ['in_footer' => true, 'strategy' => 'async']);
        wp_enqueue_script('inid-wc', INID_URL . 'assets/inid-wc.js', [], INID_VERSION, ['in_footer' => true]);
        wp_localize_script('inid-wc', 'inidWc', [
            'login' => esc_url_raw(rest_url('inid/v1/login')),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    // The pixel reads its key (and an invitation, if any) from its own tag
    public function tag(string $tag, string $handle): string
    {
        if ($handle !== 'inid-pixel') {
            return $tag;
        }
        $attrs = ' data-key="' . esc_attr($this->settings->get('publishable_key')) . '"';
        // One-click sign in / sign up: the pixel sends the token here and follows the redirect
        $attrs .= ' data-signin="' . esc_url(rest_url('inid/v1/login')) . '"';
        $invite = $this->invitation();
        if ($invite) {
            $attrs .= ' data-invite="' . esc_attr($invite) . '"';
        }
        return str_replace(' src=', $attrs . ' src=', $tag);
    }

    public function signin_button(): void
    {
        echo '<div class="inid-signin" data-inid-signin></div>';
    }

    /**
     * A logged-in customer not linked to InID yet: invite them once a day with what the
     * store already has. InID stores nothing until they confirm the code and say yes.
     */
    private function invitation(): string
    {
        if (!is_user_logged_in() || !is_account_page()) {
            return '';
        }
        $user_id = get_current_user_id();
        if (get_user_meta($user_id, InID_Customers::SUB_META, true)) {
            return '';
        }
        $cached = get_transient('inid_invite_' . $user_id);
        if ($cached) {
            return (string) $cached;
        }
        $customer = new WC_Customer($user_id);
        if (!$customer->get_billing_phone()) {
            return '';
        }
        $token = $this->client->invite([
            'phone' => $customer->get_billing_phone(),
            'profile' => array_filter([
                'firstName' => $customer->get_billing_first_name() ?: $customer->get_first_name(),
                'lastName' => $customer->get_billing_last_name() ?: $customer->get_last_name(),
                'email' => $customer->get_billing_email() ?: $customer->get_email(),
                'address' => array_filter([
                    'line' => $customer->get_billing_address_1(),
                    'details' => $customer->get_billing_address_2(),
                    'city' => $customer->get_billing_city(),
                    'region' => InID_Customers::state_name($customer->get_billing_country(), $customer->get_billing_state()),
                    'country' => $customer->get_billing_country(),
                    'postalCode' => $customer->get_billing_postcode(),
                ]),
            ]),
        ]);
        if (is_wp_error($token) || !$token) {
            return '';
        }
        set_transient('inid_invite_' . $user_id, $token, DAY_IN_SECONDS);
        return (string) $token;
    }
}
