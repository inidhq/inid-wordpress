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
        'form_seen' => 'Vieron el formulario (todos)',
        'form_sent' => 'Enviaron el formulario (todos)',
    ];

    /** @var InID_Settings */
    private $settings;
    /** @var InID_Client */
    private $client;
    /** @var InID_Customers */
    private $customers;
    /** @var InID_Domain */
    private $domain;

    public function __construct(InID_Settings $settings, InID_Client $client, InID_Customers $customers, InID_Domain $domain)
    {
        $this->settings = $settings;
        $this->client = $client;
        $this->customers = $customers;
        $this->domain = $domain;
    }

    public function register(): void
    {
        add_action('admin_menu', function () {
            add_submenu_page('woocommerce', 'InID', 'InID', 'manage_woocommerce', 'inid', [$this, 'page']);
        });
        add_action('admin_post_inid_save', [$this, 'save']);
        add_action('admin_post_inid_offer', [$this, 'save_offer']);
        // Users → a column with what InID checked about each customer who came through it
        add_filter('manage_users_columns', function ($columns) {
            $columns['inid_signals'] = 'InID';
            return $columns;
        });
        add_filter('manage_users_custom_column', [$this, 'signals_column'], 10, 3);
    }

    public function signals_column($output, string $column, int $user_id)
    {
        if ($column !== 'inid_signals') {
            return $output;
        }
        $s = get_user_meta($user_id, '_inid_signals', true);
        if (!is_array($s)) {
            return '—';
        }
        $lines = [];
        if (!empty($s['phone_verified_since'])) {
            $lines[] = '✓ Celular verificado desde ' . esc_html(date_i18n('M Y', strtotime($s['phone_verified_since'] . '-01')));
        }
        $n = (int) ($s['completed_purchases'] ?? 0);
        $lines[] = esc_html($n . ($n === 1 ? ' compra completada' : ' compras completadas') . ' con InID');
        if (!empty($s['strong_login'])) {
            $lines[] = 'Entra con Face ID o huella';
        }
        return '<span style="color:#3d5c00">' . implode('<br>', $lines) . '</span>';
    }

    // The store's own offer for buyers who left at its checkout: the store writes it
    public function save_offer(): void
    {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('inid_offer')) {
            wp_die('No autorizado');
        }
        $text = sanitize_text_field(wp_unslash($_POST['offer_text'] ?? ''));
        $code = sanitize_text_field(wp_unslash($_POST['offer_code'] ?? ''));
        $this->client->set_offer(isset($_POST['off']) || $text === '' ? null : array_filter(['text' => $text, 'code' => $code]));
        wp_safe_redirect(admin_url('admin.php?page=inid&saved=1#recover'));
        exit;
    }

    public function save(): void
    {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('inid_save')) {
            wp_die('No autorizado');
        }
        $this->settings->save($_POST['inid'] ?? []);
        $this->domain->prove();
        wp_safe_redirect(admin_url('admin.php?page=inid&saved=1'));
        exit;
    }

    public function page(): void
    {
        $s = $this->settings->all();
        $funnel = $this->settings->ready() ? $this->client->funnel(7) : null;
        $audience = $this->settings->ready() ? $this->client->audience(30) : null;
        $account = $this->settings->ready() ? $this->client->account() : null;
        $offer = ($account && !is_wp_error($account)) ? ($account['recoveryOffer'] ?? null) : null;
        $unverified = $this->domain->pending($account);
        $insights = $this->settings->ready() ? $this->client->insights(isset($_GET['fresh'])) : null;
        $abandoned = $this->customers->abandoned();
        $webhook = rest_url('inid/v1/webhook');
        ?>
        <div class="wrap">
            <h1><svg width="28" height="28" viewBox="0 0 100 100" aria-hidden="true" style="vertical-align:-7px;margin-right:8px"><rect width="100" height="100" rx="23" fill="#111113"/><rect x="24" y="50" width="20" height="20" rx="3" fill="#fff"/><rect x="56" y="30" width="20" height="20" rx="3" fill="#c6ff3d"/></svg>InID · Registro en un toque</h1>
            <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success"><p>Guardado.</p></div><?php endif; ?>
            <?php if ($unverified) : ?>
                <div class="notice notice-warning"><p><strong>Tu llave live aún no funciona en <?php echo esc_html(implode(', ', $unverified)); ?>.</strong> Este plugin publica la prueba en <code><?php echo esc_html(home_url(InID_Domain::PATH)); ?></code> y le pide a InID que la revise. Si tu servidor no deja llegar esa dirección a WordPress, verifica con un registro DNS desde el <a href="https://panel.inid.co/" target="_blank" rel="noopener">panel de InID</a>.</p></div>
            <?php endif; ?>

            <?php if ($insights && !is_wp_error($insights)) : ?>
                <h2>Tu semana</h2>
                <div class="card" style="max-width:720px">
                    <p style="font-size:15px;font-weight:600;margin-top:0"><?php echo esc_html($insights['headline']); ?></p>
                    <ol>
                        <?php foreach ((array) $insights['items'] as $item) : ?>
                            <li style="margin-bottom:10px"><strong><?php echo esc_html($item['title']); ?></strong><br><?php echo esc_html($item['finding']); ?><br>Prueba: <?php echo esc_html($item['suggestion']); ?></li>
                        <?php endforeach; ?>
                    </ol>
                    <p class="description"><?php echo $insights['source'] === 'ai' ? 'Escrito con IA a partir de tus totales anónimos' : 'Resumen automático a partir de tus totales anónimos'; ?> · <a href="<?php echo esc_url(admin_url('admin.php?page=inid&fresh=1')); ?>">Escribir de nuevo</a></p>
                </div>
            <?php endif; ?>

            <?php if ($funnel && !is_wp_error($funnel)) :
                $totals = $funnel['totals'];
                $max = max(1, max(array_values($totals)));
                ?>
                <?php $pct = function ($v) { return $v === null ? '—' : str_replace('.', ',', (string) $v) . ' %'; }; ?>
                <h2>Últimos 7 días</h2>
                <div style="display:flex;gap:16px;margin:8px 0 14px">
                    <div class="card" style="margin:0;padding:12px 16px;max-width:none;border-color:#111113;background:#f7ffe6"><p style="margin:0">Completan el checkout <strong>con InID</strong></p><p style="font-size:24px;margin:4px 0 0"><strong><?php echo esc_html($pct($funnel['compare']['withInid'] ?? null)); ?></strong></p></div>
                    <div class="card" style="margin:0;padding:12px 16px;max-width:none"><p style="margin:0">Completan el checkout <strong>sin InID</strong></p><p style="font-size:24px;margin:4px 0 0"><strong><?php echo esc_html($pct($funnel['compare']['withoutInid'] ?? null)); ?></strong></p></div>
                </div>
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

            <h2 id="recover">Recuperar clientes</h2>
            <?php $shown = (int) ($funnel['totals']['offer_shown'] ?? 0); $back = (int) ($funnel['totals']['recovered'] ?? 0); ?>
            <div style="display:flex;gap:16px;margin:8px 0 14px;flex-wrap:wrap">
                <div class="card" style="margin:0;padding:12px 16px;max-width:none"><p style="margin:0">Vieron tu oferta al volver (7 días)</p><p style="font-size:24px;margin:4px 0 0"><strong><?php echo esc_html($shown); ?></strong></p></div>
                <div class="card" style="margin:0;padding:12px 16px;max-width:none;border-color:#111113;background:#f7ffe6"><p style="margin:0">Volvieron y compraron</p><p style="font-size:24px;margin:4px 0 0"><strong><?php echo esc_html($back); ?></strong></p></div>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="card" style="max-width:720px">
                <input type="hidden" name="action" value="inid_offer">
                <?php wp_nonce_field('inid_offer'); ?>
                <h3 style="margin-top:0">Tu oferta para quienes se fueron sin comprar</h3>
                <p>La escribes tú. InID se la muestra a los compradores que vieron tu checkout y no compraron, cuando vuelven (hasta 14 días). Si pones un cupón de WooCommerce, se aplica solo. No sabes quiénes son hasta que compran.</p>
                <p><label>Oferta<br><input class="regular-text" name="offer_text" maxlength="120" placeholder="10 % en tu primera compra" value="<?php echo esc_attr($offer['text'] ?? ''); ?>"></label></p>
                <p><label>Código de cupón de WooCommerce (opcional)<br><input class="regular-text" name="offer_code" maxlength="30" placeholder="VUELVE10" value="<?php echo esc_attr($offer['code'] ?? ''); ?>"></label></p>
                <p><button class="button button-primary"><?php echo $offer ? 'Guardar cambios' : 'Activar oferta'; ?></button>
                <?php if ($offer) : ?> <button class="button" name="off" value="1">Desactivar</button><?php endif; ?></p>
            </form>

            <h2 id="avisos">Avísame · últimos 7 días</h2>
            <p style="max-width:720px">Compradores que no compraron todavía y pidieron que InID les avisara si su carrito baja de precio o pones una oferta. InID les escribe una sola vez: cuando guardas un producto con un precio menor, o cuando activas una oferta arriba. No sabes quiénes son hasta que compran.</p>
            <?php
            $notice_kpis = ['watch_created' => 'Pidieron aviso', 'notice_sent' => 'Les escribimos', 'notice_opened' => 'Volvieron', 'notice_bought' => 'Compraron', 'notice_stopped' => 'Dejaron de recibir'];
            $sent = (int) ($funnel['totals']['notice_sent'] ?? 0);
            ?>
            <div style="display:flex;gap:16px;margin:8px 0 14px;flex-wrap:wrap">
                <?php foreach ($notice_kpis as $key => $label) : ?>
                    <div class="card" style="margin:0;padding:12px 16px;max-width:none<?php echo $key === 'notice_bought' ? ';border-color:#111113;background:#f7ffe6' : ''; ?>"><p style="margin:0"><?php echo esc_html($label); ?></p><p style="font-size:24px;margin:4px 0 0"><strong><?php echo esc_html((int) ($funnel['totals'][$key] ?? 0)); ?></strong></p></div>
                <?php endforeach; ?>
            </div>
            <?php if ($sent >= 20 && ((int) ($funnel['totals']['notice_stopped'] ?? 0)) / $sent >= 0.1) : ?>
                <div class="notice notice-warning inline"><p>Muchos compradores apagaron tus avisos: InID los pausó para cuidar que sigan llegando. Revisa tus ofertas y precios.</p></div>
            <?php endif; ?>

            <h2>Audiencia · últimos 30 días</h2>
            <p style="max-width:720px">Compradores con InID que visitaron la tienda, en totales y porcentajes: nunca quién es cada uno ni en qué otras tiendas compra. Los grupos pequeños se juntan en "Otros". Solo cuentan quienes aceptaron estadísticas anónimas y donde el navegador deja reconocerlos (Chrome y Android).</p>
            <?php if ($audience && !is_wp_error($audience) && !empty($audience['enough'])) : ?>
                <div style="display:flex;gap:16px;margin:8px 0 14px;flex-wrap:wrap">
                    <div class="card" style="margin:0;padding:12px 16px;max-width:none"><p style="margin:0">Visitantes con InID</p><p style="font-size:24px;margin:4px 0 0"><strong><?php echo esc_html($audience['visitors']); ?></strong></p></div>
                    <div class="card" style="margin:0;padding:12px 16px;max-width:none;border-color:#111113;background:#f7ffe6"><p style="margin:0">Clientes potenciales (aún no te compran)</p><p style="font-size:24px;margin:4px 0 0"><strong><?php echo esc_html($audience['potential']); ?></strong></p></div>
                    <div class="card" style="margin:0;padding:12px 16px;max-width:none"><p style="margin:0">De ellos, compran en línea seguido</p><p style="font-size:24px;margin:4px 0 0"><strong><?php echo esc_html($audience['activeBuyers'] === null ? '—' : $audience['activeBuyers'] . ' %'); ?></strong></p></div>
                </div>
                <?php
                $blocks = [
                    'Dónde viven tus clientes potenciales' => $audience['where']['potential'] ?? null,
                    'Dónde viven tus clientes' => $audience['where']['customers'] ?? null,
                    'Ciudades' => $audience['cities'] ?? null,
                    'Desde qué dispositivo' => $audience['devices'] ?? null,
                    'Qué días visitan' => $audience['weekdays'] ?? null,
                ];
                if (!empty($audience['leaving'])) {
                    $blocks['Vieron el checkout y se fueron sin comprar: ' . $audience['leaving']['left'] . ' % (de ' . $audience['leaving']['sawForm'] . ')'] = $audience['leaving']['where'];
                }
                ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;max-width:1000px">
                    <?php foreach ($blocks as $title => $rows) : ?>
                        <div class="card" style="margin:0;max-width:none">
                            <h3 style="margin-top:0"><?php echo esc_html($title); ?></h3>
                            <?php if (!$rows) : ?><p>Aún muy pocos para mostrarlo sin exponer a nadie.</p><?php endif; ?>
                            <?php foreach ((array) $rows as $r) : $other = $r['label'] === 'Otros'; ?>
                                <div style="display:grid;grid-template-columns:140px 1fr 44px;gap:10px;align-items:center;padding:4px 0;<?php echo $other ? 'color:#646970' : ''; ?>">
                                    <span><?php echo esc_html($r['label']); ?></span>
                                    <div style="background:#f0f0f1;height:10px;border-radius:5px"><div style="background:<?php echo $other ? '#b5b8bc' : '#111113'; ?>;height:10px;border-radius:5px;width:<?php echo esc_attr((int) $r['share']); ?>%"></div></div>
                                    <strong style="text-align:right"><?php echo esc_html($r['share']); ?> %</strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($audience && !is_wp_error($audience)) : ?>
                <div class="notice notice-info inline"><p>Aún no hay suficientes visitantes con InID para mostrar cifras sin exponer a nadie (mínimo <?php echo esc_html($audience['min'] ?? 10); ?>).</p></div>
            <?php elseif ($this->settings->ready()) : ?>
                <div class="notice notice-warning inline"><p>No se pudo leer la audiencia de InID.</p></div>
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
