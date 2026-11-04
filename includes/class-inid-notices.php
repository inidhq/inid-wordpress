<?php
defined('ABSPATH') || exit;

/**
 * "Avísame": on the cart and checkout, the cart goes in the page (product ids, names,
 * prices) so InID's greeting can offer to watch it; when a product's price is saved, InID
 * hears it and writes once to the buyers whose watched cart got cheaper. The store never
 * learns who asked until they buy.
 */
class InID_Notices
{
    /** @var InID_Settings */
    private $settings;
    /** @var InID_Client */
    private $client;
    /** @var array<int, int> product id => price, sent once per request */
    private $changed = [];

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
        add_action('wp_footer', [$this, 'cart']);
        add_action('woocommerce_update_product', [$this, 'price_saved']);
        add_action('woocommerce_update_product_variation', [$this, 'price_saved']);
        add_action('shutdown', [$this, 'send']);
        add_action('admin_notices', [$this, 'news']);
    }

    // After saving a cheaper price: how many watched carts InID will write to
    public function news(): void
    {
        $carts = (int) get_transient('inid_price_news');
        if (!$carts) {
            return;
        }
        delete_transient('inid_price_news');
        $text = $carts === 1 ? 'InID: 1 carrito vigilado bajó de precio; le escribimos una vez, en horas permitidas.' : sprintf('InID: %d carritos vigilados bajaron de precio; les escribimos una vez, en horas permitidas.', $carts);
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($text) . '</p></div>';
    }

    public function cart(): void
    {
        if (!(is_cart() || is_checkout()) || is_order_received_page() || !WC()->cart || WC()->cart->is_empty()) {
            return;
        }
        $items = [];
        foreach (WC()->cart->get_cart() as $line) {
            $product = $line['data'];
            if (!$product instanceof WC_Product) {
                continue;
            }
            $items[] = [
                'id' => (string) $product->get_id(),
                'name' => wp_strip_all_tags($product->get_name()),
                'price' => (int) round((float) wc_get_price_to_display($product)),
                'quantity' => (int) $line['quantity'],
            ];
        }
        $cart = ['items' => array_slice($items, 0, 30), 'url' => wc_get_cart_url()];
        echo '<script type="application/json" data-inid-cart>' . wp_json_encode($cart, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) . '</script>';
    }

    public function price_saved(int $product_id): void
    {
        $product = wc_get_product($product_id);
        if ($product && $product->get_price() !== '') {
            $this->changed[$product_id] = (int) round((float) wc_get_price_to_display($product));
        }
    }

    // Once, after WooCommerce saved everything (a product and its variations)
    public function send(): void
    {
        if (!$this->changed) {
            return;
        }
        $items = [];
        foreach ($this->changed as $id => $price) {
            $items[] = ['id' => (string) $id, 'price' => $price];
        }
        $this->changed = [];
        $result = $this->client->prices($items);
        if (!is_wp_error($result) && !empty($result['carts'])) {
            set_transient('inid_price_news', (int) $result['carts'], 120);
        }
    }
}
