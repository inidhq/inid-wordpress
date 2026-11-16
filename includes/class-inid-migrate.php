<?php
defined('ABSPATH') || exit;

/**
 * Bringing the site's existing users to InID.
 *
 * Usuarios → Pasar a InID: how many accounts are connected, invited or still with their
 * password, by WordPress role; inviting them creates InID invitations (they get in even if the
 * app only takes some people, with the app role their WordPress role maps to), and the site can
 * email them. An invited account is connected the first time its person signs in with InID
 * with that same email, confirmed.
 *
 * Each person can also connect their own account from their profile (or WooCommerce's My
 * Account); someone still signing in with a password sees a notice in the admin.
 */
class InID_Migrate
{
    const INVITED = '_inid_invited';
    const DISMISSED = '_inid_notice_dismissed';
    const BATCH = 200;
    const PER_RUN = 1000;

    private $settings;
    private $client;

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
        add_action('admin_menu', function () {
            add_users_page('Pasar a InID', 'Pasar a InID', 'manage_options', 'inid-migrate', [$this, 'page']);
        });
        add_action('admin_post_inid_migrate', [$this, 'invite']);
        add_action('admin_post_inid_unlink', [$this, 'unlink']);
        add_action('admin_post_inid_dismiss', [$this, 'dismiss']);
        add_action('show_user_profile', [$this, 'profile']);
        add_action('admin_notices', [$this, 'notice']);
        add_action('woocommerce_account_dashboard', [$this, 'account']);
        add_filter('manage_users_columns', function ($columns) {
            $columns['inid'] = 'InID';
            return $columns;
        });
        add_filter('manage_users_custom_column', function ($output, $column, $user_id) {
            if ($column !== 'inid') {
                return $output;
            }
            if (get_user_meta($user_id, InID_SignIn::SUB_META, true)) {
                return '<span style="color:#008a20">Conectada</span>';
            }
            return get_user_meta($user_id, self::INVITED, true) ? 'Invitada' : '—';
        }, 10, 3);
    }

    // ---------- the admin's page ----------

    /** Accounts by WordPress role: all, connected, invited (and not yet connected) */
    private function counts(): array
    {
        $counts = [];
        foreach (wp_roles()->get_names() as $role => $name) {
            $all = (int) (new WP_User_Query(['role' => $role, 'count_total' => true, 'number' => 1, 'fields' => 'ID']))->get_total();
            if (!$all) {
                continue;
            }
            $connected = (int) (new WP_User_Query(['role' => $role, 'count_total' => true, 'number' => 1, 'fields' => 'ID', 'meta_key' => InID_SignIn::SUB_META, 'meta_compare' => 'EXISTS']))->get_total();
            $invited = (int) (new WP_User_Query(['role' => $role, 'count_total' => true, 'number' => 1, 'fields' => 'ID', 'meta_query' => [
                ['key' => self::INVITED, 'compare' => 'EXISTS'],
                ['key' => InID_SignIn::SUB_META, 'compare' => 'NOT EXISTS'],
            ]]))->get_total();
            $counts[$role] = ['name' => translate_user_role($name), 'all' => $all, 'connected' => $connected, 'invited' => $invited, 'pending' => $all - $connected - $invited];
        }
        return $counts;
    }

    /** The app role a WordPress role becomes in InID (the role map, read backwards) */
    private function app_role(string $wp_role): ?string
    {
        foreach (InID_SignIn::role_map($this->settings->get('role_map')) as $app => $wp) {
            if ($wp === $wp_role) {
                return (string) $app;
            }
        }
        return null;
    }

    public function page(): void
    {
        $counts = $this->counts();
        $total = array_sum(array_column($counts, 'all'));
        $connected = array_sum(array_column($counts, 'connected'));
        $invited = array_sum(array_column($counts, 'invited'));
        ?>
        <div class="wrap">
            <h1>Pasar a InID</h1>
            <?php if (isset($_GET['done'])) : ?>
                <div class="notice notice-success"><p><?php echo esc_html(sprintf('%d invitadas%s.', (int) $_GET['done'], !empty($_GET['failed']) ? sprintf(' · %d no se pudieron invitar (correo inválido o rol que la app ya no tiene)', (int) $_GET['failed']) : '')); ?><?php echo !empty($_GET['more']) ? ' Quedan más: vuelve a pulsar Invitar.' : ''; ?></p></div>
            <?php elseif (isset($_GET['error'])) : ?>
                <div class="notice notice-error"><p>InID no respondió. Revisa la conexión del plugin e inténtalo de nuevo.</p></div>
            <?php endif; ?>
            <p style="max-width:760px">Tus usuarios entran con InID, sin contraseña: su celular o una passkey (Face ID, huella, Windows Hello o el PIN del dispositivo). Al invitarlos, InID los deja entrar con el correo que tienen aquí (aunque tu app solo admita a ciertas personas) y con el rol de la app que corresponde a su rol de WordPress. La primera vez que entran con InID, su cuenta de aquí queda conectada: sus pedidos, su contenido y su rol siguen siendo los mismos.</p>
            <p><strong><?php echo esc_html("$connected de $total cuentas conectadas"); ?></strong><?php echo $invited ? esc_html(" · $invited invitadas") : ''; ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="inid_migrate">
                <?php wp_nonce_field('inid_migrate'); ?>
                <table class="widefat striped" style="max-width:760px">
                    <thead><tr><th style="width:28px"></th><th>Rol de WordPress</th><th>Cuentas</th><th>Conectadas</th><th>Invitadas</th><th>Sin invitar</th><th>Rol en la app</th></tr></thead>
                    <tbody>
                    <?php foreach ($counts as $role => $c) : $admin = $role === 'administrator'; ?>
                        <tr>
                            <td><input type="checkbox" name="roles[]" value="<?php echo esc_attr($role); ?>" <?php checked(!$admin && $c['pending'] > 0); ?> <?php disabled($c['pending'] === 0); ?>></td>
                            <td><?php echo esc_html($c['name']); ?></td>
                            <td><?php echo (int) $c['all']; ?></td>
                            <td><?php echo (int) $c['connected']; ?></td>
                            <td><?php echo (int) $c['invited']; ?></td>
                            <td><?php echo (int) $c['pending']; ?></td>
                            <td><?php echo esc_html($this->app_role($role) ?? '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description" style="max-width:760px">El rol en la app sale de <strong>Roles</strong> en los ajustes de InID (<code>rol de la app=rol de WordPress</code>). Los administradores se invitan, pero su cuenta se conecta solo desde su propio perfil.</p>
                <p><label><input type="checkbox" name="email" value="1" checked> Enviarles un correo desde este sitio para que entren con InID</label></p>
                <?php submit_button('Invitar', 'primary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    /** Invites the chosen roles' accounts that aren't connected or invited yet */
    public function invite(): void
    {
        if (!current_user_can('manage_options') || !check_admin_referer('inid_migrate')) {
            wp_die('No autorizado');
        }
        $roles = array_map('sanitize_key', (array) wp_unslash($_POST['roles'] ?? []));
        $back = admin_url('users.php?page=inid-migrate');
        if (!$roles) {
            wp_safe_redirect($back);
            exit;
        }
        $users = get_users(['role__in' => $roles, 'number' => self::PER_RUN + 1, 'meta_query' => [
            ['key' => InID_SignIn::SUB_META, 'compare' => 'NOT EXISTS'],
            ['key' => self::INVITED, 'compare' => 'NOT EXISTS'],
        ]]);
        $more = count($users) > self::PER_RUN;
        $users = array_slice($users, 0, self::PER_RUN);
        $done = 0;
        $failed = 0;
        foreach (array_chunk($users, self::BATCH) as $chunk) {
            $list = [];
            foreach ($chunk as $user) {
                if (!is_email($user->user_email)) {
                    $failed++;
                    continue;
                }
                $role = $this->app_role((string) ($user->roles[0] ?? ''));
                $list[strtolower($user->user_email)] = ['user' => $user, 'invitation' => ['email' => $user->user_email] + ($role ? ['role' => $role] : [])];
            }
            if (!$list) {
                continue;
            }
            $result = $this->client->invite_batch(array_column($list, 'invitation'));
            if (is_wp_error($result)) {
                wp_safe_redirect(add_query_arg('error', '1', $back));
                exit;
            }
            $refused = array_map('strtolower', array_column((array) ($result['failed'] ?? []), 'email'));
            foreach ($list as $email => $item) {
                if (in_array($email, $refused, true)) {
                    $failed++;
                    continue;
                }
                update_user_meta($item['user']->ID, self::INVITED, time());
                $done++;
                if (!empty($_POST['email'])) {
                    $this->mail($item['user']);
                }
            }
        }
        wp_safe_redirect(add_query_arg(['done' => $done, 'failed' => $failed, 'more' => $more ? 1 : 0], $back));
        exit;
    }

    /** "Ahora entras con InID", from the site itself, in its language */
    private function mail(WP_User $user): void
    {
        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $name = $user->first_name ?: $user->display_name;
        $lang = substr(get_locale(), 0, 2);
        $texts = [
            'es' => ["$site: ahora entras con InID", "Hola %s:\n\nAhora puedes entrar a %s sin contraseña, con InID: tu celular o una passkey (Face ID, huella, Windows Hello o el PIN del dispositivo).\n\nEntra aquí y, si aún no tienes InID, créalo con este mismo correo (%s): tu cuenta, tus datos y tus pedidos siguen siendo los mismos.\n\n%s\n"],
            'pt' => ["$site: agora você entra com InID", "Olá, %s:\n\nAgora você pode entrar em %s sem senha, com InID: seu celular ou uma passkey (Face ID, digital, Windows Hello ou o PIN do dispositivo).\n\nEntre aqui e, se ainda não tiver InID, crie-o com este mesmo e-mail (%s): sua conta, seus dados e seus pedidos continuam os mesmos.\n\n%s\n"],
            'en' => ["$site: you now sign in with InID", "Hi %s,\n\nYou can now sign in to %s without a password, with InID: your phone or a passkey (Face ID, fingerprint, Windows Hello or your device PIN).\n\nSign in here and, if you don't have InID yet, create it with this same email (%s): your account, your data and your orders stay the same.\n\n%s\n"],
        ];
        [$subject, $body] = $texts[$lang] ?? $texts['en'];
        wp_mail($user->user_email, $subject, sprintf($body, $name, $site, $user->user_email, wp_login_url()));
    }

    // ---------- each person ----------

    /** On their own profile: connected, or a button to connect */
    public function profile(WP_User $user): void
    {
        $sub = get_user_meta($user->ID, InID_SignIn::SUB_META, true);
        echo '<h2>InID</h2><table class="form-table"><tr><th>Inicio de sesión</th><td>';
        if (isset($_GET['inid_linked'])) {
            echo $_GET['inid_linked'] === '1'
                ? '<div class="notice notice-success inline"><p>Tu cuenta quedó conectada con InID.</p></div>'
                : '<div class="notice notice-error inline"><p>Ese InID ya está conectado a otra cuenta de este sitio.</p></div>';
        }
        if ($sub) {
            $unlink = wp_nonce_url(admin_url('admin-post.php?action=inid_unlink'), 'inid_unlink');
            echo '<p>Conectada: entras con tu celular o una passkey.</p><p><a class="button" href="' . esc_url($unlink) . '">Desconectar</a></p>';
        } else {
            echo '<p>Entra sin contraseña: con tu celular o una passkey.</p><p><a class="button button-primary" href="' . esc_url(InID_SignIn::link_url(admin_url('profile.php'))) . '">Conectar con InID</a></p>';
        }
        echo '</td></tr></table>';
    }

    public function unlink(): void
    {
        if (!is_user_logged_in() || !check_admin_referer('inid_unlink')) {
            wp_die('No autorizado');
        }
        delete_user_meta(get_current_user_id(), InID_SignIn::SUB_META);
        wp_safe_redirect(admin_url('profile.php'));
        exit;
    }

    /** In the admin, to whoever still signs in with a password */
    public function notice(): void
    {
        $user_id = get_current_user_id();
        if (!$user_id || get_user_meta($user_id, InID_SignIn::SUB_META, true) || get_user_meta($user_id, self::DISMISSED, true)) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->id === 'profile') {
            return;
        }
        $later = wp_nonce_url(admin_url('admin-post.php?action=inid_dismiss'), 'inid_dismiss');
        echo '<div class="notice notice-info"><p><strong>Entra sin contraseña.</strong> Conecta tu cuenta con InID y entra con tu celular o una passkey. <a class="button button-primary" style="margin-left:8px" href="' . esc_url(InID_SignIn::link_url(admin_url('profile.php'))) . '">Conectar con InID</a> <a style="margin-left:8px" href="' . esc_url($later) . '">Ahora no</a></p></div>';
    }

    public function dismiss(): void
    {
        if (!is_user_logged_in() || !check_admin_referer('inid_dismiss')) {
            wp_die('No autorizado');
        }
        update_user_meta(get_current_user_id(), self::DISMISSED, time());
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    /** WooCommerce's My Account: the same, for customers */
    public function account(): void
    {
        $user_id = get_current_user_id();
        if (!$user_id || get_user_meta($user_id, InID_SignIn::SUB_META, true)) {
            if (isset($_GET['inid_linked']) && $_GET['inid_linked'] === '1') {
                echo '<p class="woocommerce-message">Tu cuenta quedó conectada con InID.</p>';
            }
            return;
        }
        $here = wc_get_page_permalink('myaccount');
        echo '<p class="inid-connect" style="padding:14px 16px;border:1px solid #e2e2e6;border-radius:10px">Entra sin contraseña la próxima vez: con tu celular o una passkey. <a class="button" href="' . esc_url(InID_SignIn::link_url($here)) . '">Conectar con InID</a></p>';
    }
}
