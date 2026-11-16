<?php
defined('ABSPATH') || exit;

/**
 * Connects this site to InID with one button and nothing to copy. The site proves its
 * domain is its own (a secret made here, its SHA-256 shown at /.well-known/inid-verify.txt)
 * and InID answers with the organization's keys, already tied to this address and its webhook.
 * Afterwards, "Abrir el panel" asks InID for a link that signs the person in to the panel.
 */
class InID_Connect
{
    const NOTICE = 'inid_connect_notice';
    // The secret behind the proof this site shows while someone says yes in the panel
    const SECRET = 'inid_connect_secret';

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
        add_action('admin_post_inid_connect', [$this, 'connect']);
        add_action('admin_post_inid_link', [$this, 'link']);
        add_action('admin_post_inid_linked', [$this, 'linked']);
        add_action('admin_post_inid_panel', [$this, 'panel']);
    }

    private static function woo(): bool
    {
        return class_exists('WooCommerce');
    }

    private static function allowed(): bool
    {
        return current_user_can(self::woo() ? 'manage_woocommerce' : 'manage_options');
    }

    private static function page(): string
    {
        return admin_url(self::woo() ? 'admin.php?page=inid' : 'options-general.php?page=inid');
    }

    public function connected(): bool
    {
        return $this->settings->get('publishable_key') !== '' && $this->settings->get('secret_key') !== '';
    }

    /** InID reads the proof from the internet: the site must be public, over HTTPS */
    public function possible(): bool
    {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        return wp_parse_url(home_url(), PHP_URL_SCHEME) === 'https'
            && strpos($host, '.') !== false
            && !filter_var($host, FILTER_VALIDATE_IP)
            && !preg_match('/\.(local|localhost|internal|test)$/', $host);
    }

    public function connect(): void
    {
        if (!self::allowed() || !check_admin_referer('inid_connect')) {
            wp_die('No autorizado');
        }
        $result = $this->run();
        set_transient(self::NOTICE, is_wp_error($result) ? ['error' => $this->explain($result)] : ['ok' => 1], 60);
        wp_safe_redirect(self::page());
        exit;
    }

    /**
     * "Ya tengo una tienda en InID": to the panel, where the person picks which of their
     * organizations this site belongs to. The site shows its proof meanwhile; the panel sends the
     * person back with a grant (linked) that this server trades for the keys.
     */
    public function link(): void
    {
        if (!self::allowed() || !check_admin_referer('inid_link')) {
            wp_die('No autorizado');
        }
        $secret = bin2hex(random_bytes(32));
        update_option(self::SECRET, ['secret' => $secret, 'before' => (string) get_option(InID_Domain::TOKEN, ''), 'at' => time()], false);
        update_option(InID_Domain::TOKEN, hash('sha256', $secret), true);
        $back = add_query_arg(['action' => 'inid_linked', '_wpnonce' => wp_create_nonce('inid_linked')], admin_url('admin-post.php'));
        wp_redirect($this->settings->panel_url() . '/connect?' . http_build_query(['site' => home_url('/'), 'proof' => hash('sha256', $secret), 'return' => $back]));
        exit;
    }

    public function linked(): void
    {
        if (!self::allowed() || !check_admin_referer('inid_linked')) {
            wp_die('No autorizado');
        }
        $kept = get_option(self::SECRET);
        delete_option(self::SECRET);
        $grant = isset($_GET['inid_grant']) ? sanitize_text_field(wp_unslash($_GET['inid_grant'])) : '';
        if (!is_array($kept) || empty($kept['secret']) || $grant === '' || time() - (int) ($kept['at'] ?? 0) > 15 * MINUTE_IN_SECONDS) {
            if (is_array($kept)) {
                update_option(InID_Domain::TOKEN, (string) ($kept['before'] ?? ''), true);
            }
            $result = new WP_Error('inid_invalid_grant', 'InID: invalid grant');
        } else {
            $result = $this->request((string) $kept['secret'], (string) ($kept['before'] ?? ''), $grant);
        }
        set_transient(self::NOTICE, is_wp_error($result) ? ['error' => $this->explain($result)] : ['ok' => 1], 60);
        wp_safe_redirect(self::page());
        exit;
    }

    /** One click: a new secret, its proof on the site, and InID answers with the keys */
    public function run()
    {
        $before = (string) get_option(InID_Domain::TOKEN, '');
        $secret = bin2hex(random_bytes(32));
        update_option(InID_Domain::TOKEN, hash('sha256', $secret), true);
        return $this->request($secret, $before);
    }

    /**
     * Asks InID for this site's organization, with the secret behind the proof the site shows now
     * (and, for an organization that already has people, the grant one of them gave in the panel).
     *
     * @return true|WP_Error
     */
    private function request(string $secret, string $before, string $grant = '')
    {
        $woo = self::woo();
        $body = [
            'site' => home_url('/'),
            'secret' => $secret,
            'name' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            'email' => (string) get_option('admin_email'),
            'timeZone' => wp_timezone_string(),
            'platform' => $woo ? 'woocommerce' : 'wordpress',
        ];
        if ($grant !== '') {
            $body['grant'] = $grant;
        }
        $body['webhookUrl'] = rest_url('inid/v1/webhook');
        if ($woo) {
            $body['country'] = (string) (wc_get_base_location()['country'] ?? '');
        } else {
            // Without a shop, the heatmap and the live view are all this site uses
            $body['heatmap'] = true;
        }
        $keys = $this->client->connect($body);
        if (is_wp_error($keys) || empty($keys['publishableKey']) || empty($keys['secretKey'])) {
            // What this site showed before (an organization connected by hand keeps its proof)
            update_option(InID_Domain::TOKEN, $before, true);
            return is_wp_error($keys) ? $keys : new WP_Error('inid_request_failed', 'InID: request failed');
        }
        $this->settings->save(array_merge($this->settings->all(), [
            'publishable_key' => $keys['publishableKey'],
            'secret_key' => $keys['secretKey'],
            'webhook_secret' => (string) ($keys['webhookSecret'] ?? ''),
        ]));
        // From now on the site shows the organization's own proof, which InID checks again over time
        update_option(InID_Domain::TOKEN, sanitize_text_field((string) ($keys['verifyToken'] ?? '')), true);
        return true;
    }

    /** To the panel, signed in to this organization */
    public function panel(): void
    {
        if (!self::allowed() || !check_admin_referer('inid_panel')) {
            wp_die('No autorizado');
        }
        $link = $this->connected() ? $this->client->panel_link() : null;
        $url = (is_array($link) && !empty($link['url'])) ? (string) $link['url'] : $this->settings->panel_url() . '/';
        wp_redirect(esc_url_raw($url));
        exit;
    }

    private function explain(WP_Error $error): string
    {
        switch ($error->get_error_code()) {
            case 'inid_proof_not_found':
                return 'InID no pudo leer la prueba en ' . home_url(InID_Domain::PATH) . '. Tu servidor, un firewall o un plugin de caché no dejan llegar esa dirección a WordPress. Permítela y vuelve a intentar, o pega las llaves del panel de InID.';
            case 'inid_domain_taken':
                return 'Este dominio ya tiene una organización en InID con quien la administra. Usa "Ya tengo una organización en InID", abajo, y entra con esa cuenta.';
            case 'inid_invalid_grant':
                return 'El permiso del panel venció o no era para este sitio. Presiona "Ya tengo una organización en InID" otra vez.';
            case 'inid_invalid_site':
                return 'Para conectar en un clic el sitio debe ser público y usar HTTPS. Mientras tanto, pega las llaves del panel de InID.';
            case 'inid_too_many_attempts':
                return 'Demasiados intentos seguidos. Espera diez minutos y vuelve a intentar.';
            default:
                return 'No pudimos hablar con InID. Vuelve a intentar en un momento.';
        }
    }

    /** The card at the top of the plugin's page: connect, or open the panel */
    public function card(): void
    {
        $notice = get_transient(self::NOTICE);
        if ($notice) {
            delete_transient(self::NOTICE);
            if (!empty($notice['error'])) {
                echo '<div class="notice notice-error"><p><strong>No se pudo conectar.</strong> ' . esc_html($notice['error']) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p><strong>Conectado con InID.</strong> No hay nada más que configurar.</p></div>';
            }
        }
        $action = esc_url(admin_url('admin-post.php'));
        if ($this->connected()) {
            ?>
            <form method="post" action="<?php echo $action; ?>" target="_blank" style="margin:12px 0">
                <input type="hidden" name="action" value="inid_panel">
                <?php wp_nonce_field('inid_panel'); ?>
                <button class="button button-primary">Abrir el panel de InID</button>
                <span class="description" style="margin-left:8px">Este sitio está conectado. La primera vez, entras con tu celular y quedas a cargo de la organización en InID.</span>
            </form>
            <?php
            return;
        }
        // A public key pasted by hand: the site already has its organization, made in the panel
        if ($this->settings->get('publishable_key') !== '') {
            echo '<p><a class="button" href="' . esc_url($this->settings->panel_url() . '/') . '" target="_blank" rel="noopener">Abrir el panel de InID</a></p>';
            return;
        }
        ?>
        <div class="card" style="max-width:720px">
            <h2 style="margin-top:0">Conecta este sitio con InID</h2>
            <?php if ($this->possible()) : ?>
                <p>Un clic y listo: InID comprueba que <strong><?php echo esc_html((string) wp_parse_url(home_url(), PHP_URL_HOST)); ?></strong> es tuyo, crea tu organización y este plugin guarda las llaves. No hay nada que copiar ni pegar.</p>
                <form method="post" action="<?php echo $action; ?>">
                    <input type="hidden" name="action" value="inid_connect">
                    <?php wp_nonce_field('inid_connect'); ?>
                    <p><button class="button button-primary button-hero">Conectar con InID</button></p>
                </form>
                <p class="description">Al conectar, este sitio le envía a InID su nombre, su dirección y el correo del administrador (<?php echo esc_html((string) get_option('admin_email')); ?>).</p>
                <form method="post" action="<?php echo $action; ?>" style="margin-top:14px">
                    <input type="hidden" name="action" value="inid_link">
                    <?php wp_nonce_field('inid_link'); ?>
                    <button class="button-link">Ya tengo una organización en InID</button>
                    <span class="description"> · eliges cuál en el panel y este sitio queda conectado a ella.</span>
                </form>
            <?php else : ?>
                <p>Conectar en un clic necesita un sitio público con HTTPS, porque InID comprueba el dominio desde internet. En este sitio, pega las llaves del <a href="<?php echo esc_url($this->settings->panel_url() . '/'); ?>" target="_blank" rel="noopener">panel de InID</a> abajo.</p>
            <?php endif; ?>
        </div>
        <?php
    }
}
