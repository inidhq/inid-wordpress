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
        $phone = preg_replace('/^\+57/', '', (string) ($c['phone_number'] ?? ''));
        $address = (array) ($c['address'] ?? []);
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
            $set('state', self::state_code((string) ($address['department'] ?? '')));
            $set('country', 'CO');
        }
        if (!empty($c['email']) && !$customer->get_billing_email() && !email_exists($c['email'])) {
            $customer->set_billing_email(sanitize_email($c['email']));
        }
        $customer->save();
        if (!empty($c['national_id']['number'])) {
            update_user_meta($user_id, 'billing_document_type', sanitize_text_field($c['national_id']['type'] ?? 'CC'));
            update_user_meta($user_id, 'billing_document', sanitize_text_field($c['national_id']['number']));
        }
    }

    /** WooCommerce keeps Colombian departments by their ISO code ("CO-DC" for Bogotá). */
    public static function state_code(string $department): string
    {
        if ($department === '') {
            return '';
        }
        $wanted = strtolower(remove_accents($department));
        if (preg_match('/bogota|capital district|distrito capital/', $wanted)) {
            return 'CO-DC';
        }
        if (strpos($wanted, 'san andres') !== false) {
            return 'CO-SAP';
        }
        foreach ((array) WC()->countries->get_states('CO') as $code => $name) {
            if (strtolower(remove_accents($name)) === $wanted || strtolower($code) === $wanted) {
                return $code;
            }
        }
        return $department;
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
