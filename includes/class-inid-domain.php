<?php
defined('ABSPATH') || exit;

/**
 * Proves to InID that this site's domain is the organization's, so its live key works here:
 * publishes the organization's token at /.well-known/inid-verify.txt and asks InID to look.
 * Done when the settings are saved, and again from the InID page while it's pending.
 */
class InID_Domain
{
    const TOKEN = 'inid_verify_token';
    const PATH = '/.well-known/inid-verify.txt';
    const RETRY = 'inid_domain_checked';

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
        add_action('init', [$this, 'serve'], 0);
    }

    /** The token, as plain text, at the address InID reads */
    public function serve(): void
    {
        $path = (string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $token = (string) get_option(self::TOKEN, '');
        if ($path !== self::PATH || $token === '') {
            return;
        }
        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo 'inid-verify=' . esc_html($token);
        exit;
    }

    /**
     * Keeps the token InID gave the organization and asks InID to verify. Returns the domains as
     * InID sees them, or null when it couldn't ask.
     */
    public function prove(?array $account = null): ?array
    {
        if (!$this->settings->ready()) {
            return null;
        }
        $account = $account ?: $this->client->account();
        if (is_wp_error($account) || empty($account['verifyToken'])) {
            return null;
        }
        update_option(self::TOKEN, sanitize_text_field($account['verifyToken']), true);
        set_transient(self::RETRY, 1, 10 * MINUTE_IN_SECONDS);
        $result = $this->client->verify_domains();
        return is_wp_error($result) ? null : (array) ($result['domains'] ?? []);
    }

    /** While a domain is pending, the InID page asks again at most every ten minutes */
    public function pending(?array $account): array
    {
        if (!$account || is_wp_error($account)) {
            return [];
        }
        $domains = (array) ($account['domains'] ?? []);
        $waiting = array_values(array_filter($domains, function ($d) { return empty($d['verified']); }));
        if ($waiting && !get_transient(self::RETRY)) {
            $domains = $this->prove($account) ?? $domains;
            $waiting = array_values(array_filter($domains, function ($d) { return empty($d['verified']); }));
        }
        return array_map(function ($d) { return (string) $d['host']; }, $waiting);
    }
}
