<?php
/**
 * Plugin Name: InID — Inicio de sesión sin contraseña
 * Description: Inicia sesión con InID en cualquier WordPress (roles y sesiones incluidos), mapa de calor y visitantes en vivo. Con WooCommerce, además, tus clientes se registran y llenan el checkout en un toque.
 * Version: 0.7.0
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Text Domain: inid
 */

defined('ABSPATH') || exit;

define('INID_VERSION', '0.7.0');
define('INID_DIR', __DIR__);
define('INID_URL', plugin_dir_url(__FILE__));

require_once INID_DIR . '/includes/class-inid-settings.php';
require_once INID_DIR . '/includes/class-inid-client.php';
require_once INID_DIR . '/includes/class-inid-customers.php';
require_once INID_DIR . '/includes/class-inid-pixel.php';
require_once INID_DIR . '/includes/class-inid-rest.php';
require_once INID_DIR . '/includes/class-inid-admin.php';
require_once INID_DIR . '/includes/class-inid-notices.php';
require_once INID_DIR . '/includes/class-inid-domain.php';
require_once INID_DIR . '/includes/class-inid-consent.php';
require_once INID_DIR . '/includes/class-inid-site.php';
require_once INID_DIR . '/includes/class-inid-connect.php';
require_once INID_DIR . '/includes/class-inid-signin.php';
require_once INID_DIR . '/includes/class-inid-access.php';
require_once INID_DIR . '/includes/class-inid-migrate.php';

register_activation_hook(__FILE__, ['InID_Customers', 'install']);

add_action('plugins_loaded', function () {
    InID_Consent::register();
    $settings = new InID_Settings();
    $client = new InID_Client($settings);
    // Either way the site proves its domain and connects itself to InID
    $domain = new InID_Domain($settings, $client);
    $domain->register();
    $connect = new InID_Connect($settings, $client);
    $connect->register();
    // Any WordPress: its people sign in with InID
    // Who sees each page: anyone, signed in, a role or a permission of the app
    $access = new InID_Access($settings, $client);
    $access->register();
    $signin = new InID_SignIn($settings, $client, $access);
    $signin->register();
    // The site's existing users, brought to InID
    (new InID_Migrate($settings, $client))->register();
    // Any other site: that, the heatmap and the live view
    if (!class_exists('WooCommerce')) {
        (new InID_Site($settings, $connect))->register();
        (new InID_Rest($settings, $client, null, $signin))->register();
        return;
    }
    $customers = new InID_Customers();
    (new InID_Pixel($settings, $client))->register();
    (new InID_Rest($settings, $client, $customers, $signin))->register();
    (new InID_Admin($settings, $client, $customers, $domain, $connect))->register();
    (new InID_Notices($settings, $client))->register();
});
