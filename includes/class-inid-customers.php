<?php
defined('ABSPATH') || exit;

/**
 * WooCommerce customers created from InID, and checkouts left unsent.
 *
 * Accounts are matched only by InID's per-store id (sub). Never by email: InID emails are
 * declared, not verified, and matching on them would let anyone typing someone else's
 * email into InID log into that person's account here.
 */
class InID_Customers
{
    const SUB_META = '_inid_sub';

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'inid_abandoned';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta('CREATE TABLE ' . self::table() . ' (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sub VARCHAR(64) NOT NULL,
            name VARCHAR(200) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            email VARCHAR(200) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY sub (sub)
        ) ' . $wpdb->get_charset_collate() . ';');
    }

    public function find(string $sub): int
    {
        $users = get_users(['meta_key' => self::SUB_META, 'meta_value' => $sub, 'number' => 1, 'fields' => 'ID']);
        return $users ? (int) $users[0] : 0;
    }

    /** The customer for this InID buyer, created on first sight. Returns the user id. */
    public function upsert(array $c)
    {
        $sub = sanitize_text_field($c['sub'] ?? '');
        if ($sub === '') {
            return new WP_Error('inid_no_sub', 'InID: missing sub');
        }
        $user_id = $this->find($sub);
        if (!$user_id) {
            $email = sanitize_email($c['email'] ?? '');
            // An email already used by another account here is left out, not merged
            if ($email && email_exists($email)) {
                $email = '';
            }
            $base = sanitize_user(strtolower(remove_accents(($c['given_name'] ?? 'cliente') . '.' . ($c['family_name'] ?? ''))), true);
            $username = trim($base, '.') ?: 'cliente';
            while (username_exists($username)) {
                $username = trim($base, '.') . '.' . wp_rand(100, 9999);
            }
            $user_id = wp_insert_user([
                'user_login' => $username,
                'user_pass' => wp_generate_password(32, true, true), // never used: they sign in with InID
                'user_email' => $email,
                'first_name' => sanitize_text_field($c['given_name'] ?? ''),
                'last_name' => sanitize_text_field($c['family_name'] ?? ''),
                'display_name' => trim(($c['given_name'] ?? '') . ' ' . ($c['family_name'] ?? '')),
                'role' => 'customer',
            ]);
            if (is_wp_error($user_id)) {
                return $user_id;
            }
            update_user_meta($user_id, self::SUB_META, $sub);
            update_user_meta($user_id, '_inid_created', current_time('mysql'));
        }
        $this->fill_addresses((int) $user_id, $c);
        return (int) $user_id;
    }

    private function fill_addresses(int $user_id, array $c): void
    {
        $customer = new WC_Customer($user_id);
        $this->apply($customer, $c);
        $customer->save();
        if (!empty($c['national_id']['number'])) {
            update_user_meta($user_id, 'billing_document_type', sanitize_text_field($c['national_id']['type'] ?? 'CC'));
            update_user_meta($user_id, 'billing_document', sanitize_text_field($c['national_id']['number']));
        }
        // Facts InID checked (never a score): shown in the users list
        if (!empty($c['inid_signals']) && is_array($c['inid_signals'])) {
            update_user_meta($user_id, '_inid_signals', [
                'phone_verified_since' => sanitize_text_field((string) ($c['inid_signals']['phone_verified_since'] ?? '')),
                'completed_purchases' => (int) ($c['inid_signals']['completed_purchases'] ?? 0),
                'strong_login' => !empty($c['inid_signals']['strong_login']),
            ]);
        }
        // Only when the store asks for them in InID
        if (!empty($c['birthdate'])) {
            update_user_meta($user_id, 'billing_birthdate', sanitize_text_field($c['birthdate']));
        }
        if (!empty($c['nationality'])) {
            update_user_meta($user_id, 'billing_nationality', sanitize_text_field($c['nationality']));
        }
    }

    /**
     * The checkout in progress gets the buyer's data straight into WooCommerce's session:
     * every step of the checkout (and a reload) shows it, whatever the theme's fields are.
     * Called right after the buyer is logged in from a checkout InID filled.
     */
    public function prefill_checkout(int $user_id, array $c): void
    {
        if (!function_exists('WC')) {
            return;
        }
        // REST requests don't start WooCommerce's session; this one moves the guest's
        // cart to the account that just logged in
        if (null === WC()->session) {
            WC()->initialize_session();
        }
        WC()->customer = new WC_Customer($user_id, true);
        $this->apply(WC()->customer, $c);
        WC()->customer->save();
        // The store's own offer for who came back: its coupon, if it exists in WooCommerce
        $code = sanitize_text_field((string) ($c['offer_code'] ?? ''));
        if ($code !== '' && function_exists('wc_load_cart')) {
            wc_load_cart();
            if (WC()->cart && !WC()->cart->has_discount($code) && wc_get_coupon_id_by_code($code)) {
                WC()->cart->apply_coupon($code);
            }
        }
    }

    /** The InID claims on a WooCommerce customer, billing and shipping, not saved yet */
    private function apply(WC_Customer $customer, array $c): void
    {
        // As people write it in the store's country when it's from there, else with its code
        $phone = (string) ($c['phone_local'] ?? $c['phone_number'] ?? '');
        $address = (array) ($c['address'] ?? []);
        // Addresses from before InID said their country are Colombian
        $country = strtoupper((string) ($address['country'] ?? ($address ? 'CO' : '')));
        foreach (['billing', 'shipping'] as $type) {
            $set = function ($field, $value) use ($customer, $type) {
                $method = "set_{$type}_{$field}";
                if ($value !== '' && method_exists($customer, $method)) {
                    $customer->$method(sanitize_text_field((string) $value));
                }
            };
            $set('first_name', $c['given_name'] ?? '');
            $set('last_name', $c['family_name'] ?? '');
            $set('phone', $phone);
            $set('address_1', $address['line'] ?? '');
            $set('address_2', trim(($address['details'] ?? '') . (empty($address['neighborhood']) ? '' : ', ' . $address['neighborhood']), ', '));
            $set('city', $address['city'] ?? '');
            $set('country', $country);
            $set('state', self::state_code($country, (string) ($address['region'] ?? $address['department'] ?? '')));
        }
        if (!empty($c['email']) && !$customer->get_billing_email() && !email_exists($c['email'])) {
            $customer->set_billing_email(sanitize_email($c['email']));
        }
    }

    /**
     * WooCommerce keeps a country's states by its own code ("CO-DC" for Bogotá, "JA" for
     * Jalisco): the one whose name or code is the region InID shares, else the name itself.
     */
    public static function state_code(string $country, string $region): string
    {
        if ($region === '' || $country === '' || !function_exists('WC')) {
            return $region;
        }
        $wanted = strtolower(remove_accents($region));
        $states = (array) WC()->countries->get_states($country);
        // Colombia's departments go by other names too
        if ($country === 'CO') {
            if (preg_match('/bogota|capital district|distrito capital/', $wanted)) {
                $wanted = 'co-dc';
            } elseif (strpos($wanted, 'san andres') !== false) {
                $wanted = 'co-sap';
            }
        }
        foreach ($states as $code => $name) {
            $key = strtolower((string) $code);
            if (strtolower(remove_accents((string) $name)) === $wanted || $key === $wanted || $key === strtolower($country) . '-' . $wanted) {
                return (string) $code;
            }
        }
        return $region;
    }

    /** The other way: a WooCommerce state code as its name ("CO-DC" → "Bogotá D.C."), for InID */
    public static function state_name(string $country, string $state): string
    {
        if ($state === '' || $country === '' || !function_exists('WC')) {
            return $state;
        }
        $states = (array) WC()->countries->get_states($country);
        return isset($states[$state]) ? html_entity_decode((string) $states[$state]) : $state;
    }

    public function record_abandoned(array $c): void
    {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'sub' => sanitize_text_field($c['sub'] ?? ''),
            'name' => sanitize_text_field(trim(($c['given_name'] ?? '') . ' ' . ($c['family_name'] ?? ''))),
            'phone' => sanitize_text_field($c['phone_number'] ?? ''),
            'email' => sanitize_email($c['email'] ?? ''),
            'created_at' => current_time('mysql'),
        ]);
    }

    public function abandoned(int $limit = 50): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit), ARRAY_A);
    }

    public function created_count(): int
    {
        return count(get_users(['meta_key' => '_inid_created', 'fields' => 'ID']));
    }
}
