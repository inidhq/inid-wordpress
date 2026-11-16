<?php
defined('ABSPATH') || exit;

/**
 * A site without WooCommerce: the heatmap and the live view only. Settings → InID connects
 * the site (or takes the public key by hand); the pixel leaves forms alone and isn't loaded
 * for logged-in users.
 */
class InID_Site
{
    /** @var InID_Settings */
    private $settings;
    /** @var InID_Connect */
    private $connect;

    public function __construct(InID_Settings $settings, InID_Connect $connect)
    {
        $this->settings = $settings;
        $this->connect = $connect;
    }

    public function register(): void
    {
        add_action('admin_menu', function () {
            add_options_page('InID', 'InID', 'manage_options', 'inid', [$this, 'page']);
        });
        add_action('admin_post_inid_site_save', [$this, 'save']);
        add_filter('plugin_action_links_' . plugin_basename(INID_DIR . '/inid.php'), function ($links) {
            array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=inid')) . '">Ajustes</a>');
            return $links;
        });
        if ($this->settings->get('publishable_key') === '') {
            return;
        }
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_filter('script_loader_tag', [$this, 'tag'], 10, 2);
    }

    public function enqueue(): void
    {
        if (is_admin() || is_user_logged_in()) {
            return;
        }
        wp_enqueue_script('inid-pixel', rtrim($this->settings->get('public_url'), '/') . '/p.js', [], null, ['in_footer' => true, 'strategy' => 'async']);
        InID_Consent::enqueue();
    }

    public function tag(string $tag, string $handle): string
    {
        if ($handle !== 'inid-pixel') {
            return $tag;
        }
        $attrs = ' data-key="' . esc_attr($this->settings->get('publishable_key')) . '" data-forms="off"' . InID_Consent::attrs();
        return str_replace(' src=', $attrs . ' src=', $tag);
    }

    public function save(): void
    {
        if (!current_user_can('manage_options') || !check_admin_referer('inid_site_save')) {
            wp_die('No autorizado');
        }
        $this->settings->save(array_merge($this->settings->all(), (array) ($_POST['inid'] ?? [])));
        wp_safe_redirect(admin_url('options-general.php?page=inid&saved=1'));
        exit;
    }

    public function page(): void
    {
        $s = $this->settings->all();
        ?>
        <div class="wrap">
            <h1><svg width="28" height="28" viewBox="0 0 100 100" aria-hidden="true" style="vertical-align:-7px;margin-right:8px"><rect width="100" height="100" rx="23" fill="#111113"/><rect x="24" y="50" width="20" height="20" rx="3" fill="#fff"/><rect x="56" y="30" width="20" height="20" rx="3" fill="#c6ff3d"/></svg>InID</h1>
            <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success"><p>Guardado.</p></div><?php endif; ?>
            <?php $this->connect->card(); ?>
            <p>Tus usuarios inician sesión con InID en la página de inicio de sesión de WordPress, sin contraseñas. Además, mapa de calor y visitantes en vivo de este sitio. Se ven en el panel de InID. El pixel no toca tus formularios ni le muestra nada al visitante, y no se carga cuando tienes la sesión de WordPress iniciada.</p>

            <h2>Aviso de cookies</h2>
            <?php if (InID_Consent::available()) : ?>
                <div class="notice notice-success inline"><p><strong>Conectado con tu aviso de cookies.</strong> InID cuenta y sigue visitas solo cuando el visitante acepta las estadísticas.</p></div>
            <?php else : ?>
                <div class="notice notice-warning inline"><p><strong>No encontramos un aviso de cookies compatible.</strong> El mapa de calor, En vivo y el seguimiento de visitas necesitan que el visitante acepte las cookies de estadísticas, y pedirlo es responsabilidad del sitio. Mientras no haya aviso, InID cuenta a todos. Instala un aviso compatible con la WP Consent API (Complianz, CookieYes, Cookiebot y otros) y InID esperará la respuesta del visitante sin que tengas que configurar nada.</p></div>
            <?php endif; ?>

            <h2>Ajustes</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="inid_site_save">
                <?php wp_nonce_field('inid_site_save'); ?>
                <table class="form-table">
                    <tr><th>Llave pública</th><td><input class="regular-text" name="inid[publishable_key]" value="<?php echo esc_attr($s['publishable_key']); ?>" placeholder="pk_live_..."><p class="description">Con "Conectar con InID", arriba, se llena sola. A mano: está en el panel de InID, en Llaves.</p></td></tr>
                    <tr><th>URL de InID</th><td><input class="regular-text" name="inid[public_url]" value="<?php echo esc_attr($s['public_url']); ?>"></td></tr>
                    <tr><th>Iniciar sesión con InID</th><td><input type="hidden" name="inid[signin_on_login]" value="no"><label><input type="checkbox" name="inid[signin_on_login]" value="yes" <?php checked($s['signin_on_login'], 'yes'); ?>> En la página de inicio de sesión de WordPress</label>
                        <p class="description">También puedes ponerlo en cualquier página con el shortcode <code>[inid_signin]</code>. Quién puede registrarse (dominios, lista de espera, invitaciones) se decide en el panel de InID, en Inicio de sesión.</p></td></tr>
                    <tr><th>Roles</th><td><textarea class="large-text code" rows="3" name="inid[role_map]" placeholder="admin=editor&#10;member=author"><?php echo esc_textarea($s['role_map']); ?></textarea>
                        <p class="description">Un rol de InID = un rol de WordPress, por línea. Nunca se da un rol que administre el sitio. Sin rol, cada persona entra con el rol por defecto de WordPress.</p></td></tr>
                    <tr><th>Entrada en el inicio de sesión</th><td><input class="regular-text code" name="inid[entrance_login]" value="<?php echo esc_attr($s['entrance_login']); ?>" placeholder="team">
                        <p class="description">La entrada de InID que usa <code>wp-login.php</code> (por ejemplo, la de tu equipo, solo con tu dominio). Vacío: la general. Las entradas se crean en el panel de InID, en Inicio de sesión. Mi cuenta y el checkout de WooCommerce siempre usan la general, la de tus clientes.</p></td></tr>
                    <tr><th>Páginas protegidas</th><td><textarea class="large-text code" rows="3" name="inid[protected_routes]" placeholder="/intranet = author, editor (team)&#10;/miembros ="><?php echo esc_textarea($s['protected_routes']); ?></textarea>
                        <p class="description">Una ruta por línea, con los roles de WordPress que pueden verla y, entre paréntesis, la entrada de InID por la que se inicia sesión. Sin roles: basta con iniciar sesión. Quien no tiene sesión va a iniciarla; quien no tiene el rol ve que esa página no es para su cuenta.</p></td></tr>
                </table>
                <?php submit_button('Guardar'); ?>
            </form>
            <p class="description">¿Tienes WooCommerce? Actívalo y este plugin suma el registro en un toque en tu tienda, en WooCommerce → InID.</p>
        </div>
        <?php
    }
}
