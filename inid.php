<?php
/**
 * Plugin Name: InID — Registro en un toque
 * Description: Tus clientes se registran y llenan el checkout con InID: un toque y su huella o un código por WhatsApp. Sin formularios.
 * Version: 0.1.4
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: inid
 */

defined('ABSPATH') || exit;

define('INID_VERSION', '0.1.4');
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

register_activation_hook(__FILE__, ['InID_Customers', 'install']);

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        return;
    }
    $settings = new InID_Settings();
    $client = new InID_Client($settings);
    $customers = new InID_Customers();
    (new InID_Pixel($settings, $client))->register();
    (new InID_Rest($settings, $client, $customers))->register();
    $domain = new InID_Domain($settings, $client);
    $domain->register();
    (new InID_Admin($settings, $client, $customers, $domain))->register();
    (new InID_Notices($settings, $client))->register();
});
