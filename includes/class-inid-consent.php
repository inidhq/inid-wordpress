<?php
defined('ABSPATH') || exit;

/**
 * The site's own cookie notice, through the WP Consent API: when one is there, the pixel
 * waits for it (data-analytics="off") and a small script passes the visitor's answer on.
 */
class InID_Consent
{
    public static function available(): bool
    {
        return function_exists('wp_has_consent');
    }

    public static function register(): void
    {
        // InID asks the WP Consent API before counting anyone
        add_filter('wp_consent_api_registered_' . plugin_basename(INID_DIR . '/inid.php'), '__return_true');
    }

    public static function attrs(): string
    {
        return self::available() ? ' data-analytics="off"' : '';
    }

    public static function enqueue(): void
    {
        if (self::available()) {
            wp_enqueue_script('inid-consent', INID_URL . 'assets/inid-consent.js', [], INID_VERSION, ['in_footer' => true]);
        }
    }
}
