<?php
defined('ABSPATH') || exit;

/**
 * WooCommerce → InID: keys, the store's funnel (counts only) and checkouts left unsent.
 */
class InID_Admin
{
    const STEPS = [
        'greeted' => 'Saludados por InID',
        'suggested' => 'Vieron "Llenar con InID"',
        'proven' => 'Confirmaron quién son',
        'authorized' => 'Autorizaron la tienda',
        'filled' => 'Formulario llenado por InID',
        'submitted' => 'Enviaron el formulario',
        'abandoned' => 'Lo dejaron sin enviar',
        'saved' => 'Guardaron sus datos aquí',
        'dismissed' => 'Cerraron el saludo',
    ];

    /** @var InID_Settings */
    private $settings;
    /** @var InID_Client */
    private $client;
    /** @var InID_Customers */
    private $customers;

    public function __construct(InID_Settings $settings, InID_Client $client, InID_Customers $customers)
    {
        $this->settings = $settings;
        $this->client = $client;
        $this->customers = $customers;
    }

    public function register(): void
    {
        add_action('admin_menu', function () {
            add_submenu_page('woocommerce', 'InID', 'InID', 'manage_woocommerce', 'inid', [$this, 'page']);
        });
        add_action('admin_post_inid_save', [$this, 'save']);
    }

    public function save(): void
    {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('inid_save')) {
            wp_die('No autorizado');
        }
        $this->settings->save($_POST['inid'] ?? []);
        wp_safe_redirect(admin_url('admin.php?page=inid&saved=1'));
        exit;
    }

    public function page(): void
    {
        $s = $this->settings->all();
        $funnel = $this->settings->ready() ? $this->client->funnel(7) : null;
        $abandoned = $this->customers->abandoned();
        $webhook = rest_url('inid/v1/webhook');
        ?>
        <div class="wrap">
            <h1>InID · Registro en un toque</h1>
            <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success"><p>Guardado.</p></div><?php endif; ?>

            <?php if ($funnel && !is_wp_error($funnel)) :
                $totals = $funnel['totals'];
                $max = max(1, max(array_values($totals)));
                ?>
                <h2>Últimos 7 días</h2>
                <p>Solo conteos: InID no le dice a la tienda quién visitó su sitio. Cuentas creadas con InID: <strong><?php echo esc_html($this->customers->created_count()); ?></strong>.</p>
                <table class="widefat striped" style="max-width:720px">
                    <?php foreach (self::STEPS as $key => $label) : $n = (int) ($totals[$key] ?? 0); ?>
                        <tr>
                            <td style="width:240px"><?php echo esc_html($label); ?></td>
                            <td><div style="background:#f0f0f1;height:10px;border-radius:5px"><div style="background:#2271b1;height:10px;border-radius:5px;width:<?php echo esc_attr(round($n / $max * 100)); ?>%"></div></div></td>
                            <td style="width:40px;text-align:right"><strong><?php echo esc_html($n); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php elseif ($this->settings->ready()) : ?>
                <div class="notice notice-warning inline"><p>No se pudo leer el embudo de InID.</p></div>
            <?php endif; ?>

            <h2>Checkouts sin terminar</h2>
            <p>Clientes que autorizaron la tienda con InID, se les llenó el checkout y no compraron.</p>
            <table class="widefat striped" style="max-width:720px">
                <thead><tr><th>Nombre</th><th>Celular</th><th>Correo</th><th>Cuándo</th></tr></thead>
                <tbody>
                <?php if (!$abandoned) : ?><tr><td colspan="4">Ninguno por ahora.</td></tr><?php endif; ?>
                <?php foreach ($abandoned as $a) : ?>
                    <tr><td><?php echo esc_html($a['name']); ?></td><td><?php echo esc_html($a['phone']); ?></td><td><?php echo esc_html($a['email'] ?: '—'); ?></td><td><?php echo esc_html($a['created_at']); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Conexión</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="inid_save">
                <?php wp_nonce_field('inid_save'); ?>
                <table class="form-table">
                    <tr><th>Llave pública</th><td><input class="regular-text" name="inid[publishable_key]" value="<?php echo esc_attr($s['publishable_key']); ?>" placeholder="pk_..."></td></tr>
                    <tr><th>Llave secreta</th><td><input class="regular-text" type="password" name="inid[secret_key]" value="" placeholder="<?php echo $s['secret_key'] ? 'Guardada · escribe para cambiarla' : 'sk_...'; ?>"></td></tr>
                    <tr><th>Secreto del webhook</th><td><input class="regular-text" type="password" name="inid[webhook_secret]" value="" placeholder="<?php echo $s['webhook_secret'] ? 'Guardado · escribe para cambiarlo' : 'whsec_...'; ?>">
                        <p class="description">En InID, usa como URL del webhook: <code><?php echo esc_html($webhook); ?></code></p></td></tr>
                    <tr><th>URL de InID</th><td><input class="regular-text" name="inid[public_url]" value="<?php echo esc_attr($s['public_url']); ?>"></td></tr>
                    <tr><th>URL de InID desde el servidor</th><td><input class="regular-text" name="inid[api_url]" value="<?php echo esc_attr($s['api_url']); ?>" placeholder="Igual a la anterior">
                        <p class="description">Solo si este servidor llega a InID por otra dirección (por ejemplo, en Docker).</p></td></tr>
                    <tr><th>URL de la analítica</th><td><input class="regular-text" name="inid[analytics_url]" value="<?php echo esc_attr($s['analytics_url']); ?>" placeholder="Igual a la anterior"></td></tr>
                    <tr><th>Iniciar sesión con InID</th><td><label><input type="checkbox" name="inid[signin_on_account]" value="yes" <?php checked($s['signin_on_account'], 'yes'); ?>> En el formulario de Mi cuenta</label></td></tr>
                </table>
                <?php submit_button('Guardar'); ?>
            </form>
        </div>
        <?php
    }
}
