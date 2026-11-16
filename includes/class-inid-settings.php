<?php
defined('ABSPATH') || exit;

/**
 * The site's InID keys and URLs, kept in one WordPress option.
 */
class InID_Settings
{
    const OPTION = 'inid_settings';
    const PANEL = 'https://panel.inid.co';

    const DEFAULTS = [
        // Where the buyer's browser loads InID from
        'public_url' => 'https://id.inid.co',
        // Where this server reaches InID (differs only in local Docker setups)
        'api_url' => '',
        // Where this server reaches InID's analytics (the store's funnel)
        'analytics_url' => '',
        'organization_url' => '',
        'publishable_key' => '',
        'secret_key' => '',
        'webhook_secret' => '',
        // "Iniciar sesión con InID" above the My Account login form
        'signin_on_account' => 'yes',
        // "Iniciar sesión con InID" on wp-login.php (any WordPress)
        'signin_on_login' => 'yes',
        // InID roles → WordPress roles, one "admin=editor" per line
        'role_map' => '',
        // The InID entrance wp-login.php uses ("team"; empty: the general one)
        'entrance_login' => '',
        // Pages only some roles see, one "/intranet = author, editor (team)" per line
        'protected_routes' => '',
        // Accounts connected to InID can't use their password any more (administrators can)
        'password_off_linked' => 'no',
    ];

    public function all(): array
    {
        $all = array_merge(self::DEFAULTS, (array) get_option(self::OPTION, []));
        // Saved before it was called organization_url (plugin 0.7 and older)
        if ($all['organization_url'] === '' && !empty($all['merchant_url'])) {
            $all['organization_url'] = (string) $all['merchant_url'];
        }
        return $all;
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
        if ($this->get('analytics_url') !== '') {
            return rtrim($this->get('analytics_url'), '/');
        }
        // InID itself serves the store's numbers next to its panel
        return rtrim($this->get('public_url'), '/') === self::DEFAULTS['public_url'] ? self::PANEL : $this->api_url();
    }

    /** Where the organization's own settings live in InID (its account, domains, offer; connecting) */
    public function organization_url(): string
    {
        if ($this->get('organization_url') !== '') {
            return rtrim($this->get('organization_url'), '/');
        }
        // InID itself serves them next to its panel; another InID (local), at its own address
        return rtrim($this->get('public_url'), '/') === self::DEFAULTS['public_url'] ? self::PANEL : $this->api_url();
    }

    /** Where the people who run the organization manage it */
    public function panel_url(): string
    {
        return rtrim((string) apply_filters('inid_panel_url', self::PANEL), '/');
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
            if (in_array($key, ['public_url', 'api_url', 'analytics_url', 'organization_url'], true)) {
                $clean[$key] = esc_url_raw($value);
            } elseif (in_array($key, ['role_map', 'protected_routes'], true)) {
                $clean[$key] = sanitize_textarea_field($value);
            } else {
                $clean[$key] = sanitize_text_field($value);
            }
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
