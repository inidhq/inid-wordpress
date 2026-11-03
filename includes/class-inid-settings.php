<?php
defined('ABSPATH') || exit;

/**
 * The store's InID keys and URLs, kept in one WordPress option.
 */
class InID_Settings
{
    const OPTION = 'inid_settings';

    const DEFAULTS = [
        // Where the buyer's browser loads InID from
        'public_url' => 'https://inid.co',
        // Where this server reaches InID (differs only in local Docker setups)
        'api_url' => '',
        // Where this server reaches InID's analytics (the store's funnel)
        'analytics_url' => '',
        'publishable_key' => '',
        'secret_key' => '',
        'webhook_secret' => '',
        // "Iniciar sesión con InID" above the My Account login form
        'signin_on_account' => 'yes',
    ];

    public function all(): array
    {
        return array_merge(self::DEFAULTS, (array) get_option(self::OPTION, []));
    }

    public function get(string $key): string
    {
        return (string) ($this->all()[$key] ?? '');
    }

    public function api_url(): string
    {
        return rtrim($this->get('api_url') ?: $this->get('public_url'), '/');
    }

    public function analytics_url(): string
    {
        return rtrim($this->get('analytics_url') ?: $this->api_url(), '/');
    }

    public function ready(): bool
    {
        return $this->get('publishable_key') !== '' && $this->get('secret_key') !== '';
    }

    public function save(array $values): void
    {
        $clean = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = isset($values[$key]) ? trim(wp_unslash((string) $values[$key])) : $default;
            $clean[$key] = in_array($key, ['public_url', 'api_url', 'analytics_url'], true) ? esc_url_raw($value) : sanitize_text_field($value);
        }
        // An empty secret field keeps the saved one (the form never shows secrets)
        foreach (['secret_key', 'webhook_secret'] as $secret) {
            if ($clean[$secret] === '') {
                $clean[$secret] = $this->get($secret);
            }
        }
        update_option(self::OPTION, $clean, false);
    }
}
