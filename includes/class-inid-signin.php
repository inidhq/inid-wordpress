<?php
defined('ABSPATH') || exit;

/**
 * "Iniciar sesión con InID" for any WordPress: InID's own form inside wp-login.php (and the
 * [inid_signin] shortcode), as the Next.js SDK does it. The site is an OpenID Connect client
 * of InID: the authorization code flow with PKCE, the code traded by this server with its
 * secret key. The person's WordPress account is found by their InID id (or their confirmed
 * email) or created; their InID role becomes a WordPress role if the site maps it (never
 * administrator). InID's events keep it current: user.updated, user.deleted, session.ended;
 * and signing out of WordPress ends that InID session too.
 */
class InID_SignIn
{
    const FLOW_COOKIE = 'inid_flow_';
    // The same key WooCommerce's customers use (InID_Customers): one person, one account
    const SUB_META = '_inid_sub';

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
        add_action('login_form_inid', [$this, 'start']);
        add_action('login_form_inid_callback', [$this, 'callback']);
        if ($this->settings->get('signin_on_login') === 'yes') {
            add_action('login_form', [$this, 'login_form']);
        }
        add_shortcode('inid_signin', function ($atts) {
            $atts = shortcode_atts(['entrance' => ''], (array) $atts);
            return is_user_logged_in() ? '' : $this->card(home_url(add_query_arg([])), (string) $atts['entrance']);
        });
        // Pages only some roles see (their sign-in through the entrance they say)
        add_action('template_redirect', [$this, 'guard_routes']);
        // The InID session this WordPress session came from, kept with it
        add_filter('attach_session_information', [$this, 'session_information'], 10, 2);
        // Signing out here ends it at InID too
        add_action('login_form_logout', [$this, 'end_at_inid'], 1);
        // WooCommerce saving a customer writes its role back: the one InID gave stays
        add_action('inid_customer_saved', function ($user_id) {
            $given = (string) get_user_meta($user_id, 'inid_wp_role', true);
            $user = get_userdata($user_id);
            if ($given !== '' && $user && !in_array($given, (array) $user->roles, true) && !user_can($user, 'manage_options')) {
                $user->set_role($given);
            }
        });
    }

    // ---------- the form, inside the page ----------

    public function login_form(): void
    {
        $back = isset($_REQUEST['redirect_to']) ? wp_unslash((string) $_REQUEST['redirect_to']) : admin_url();
        // A protected page sends people here through its entrance; else the login's own
        $entrance = isset($_GET['inid_entrance']) ? (string) wp_unslash($_GET['inid_entrance']) : $this->settings->get('entrance_login');
        $or = ['es' => 'o con tu usuario y contraseña', 'pt' => 'ou com seu usuário e senha'][substr(get_locale(), 0, 2)] ?? 'or with your username and password';
        // At the top of the form, then the divider, then WordPress's own fields
        echo '<div class="inid-login">' . $this->card($back, $entrance) // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped
            . '<p class="inid-or" style="display:flex;align-items:center;gap:10px;color:#6b6b76;font-size:13px;margin:4px 0 18px"><span style="flex:1;height:1px;background:#dcdcde"></span>' . esc_html($or) . '<span style="flex:1;height:1px;background:#dcdcde"></span></p></div>'
            . '<script>(function(){var b=document.querySelector(".inid-login"),f=document.getElementById("loginform");if(b&&f)f.insertBefore(b,f.firstChild);})();</script>';
    }

    /** InID's form in a frame; it says how tall it is, so nothing scrolls inside */
    private function card(string $back, string $entrance = ''): string
    {
        $args = ['action' => 'inid', 'embed' => '1', 'redirect_to' => $back];
        if (self::entrance_key($entrance) !== '') {
            $args['entrance'] = self::entrance_key($entrance);
        }
        // Back from Google: the form goes on where InID left it
        if (isset($_GET['inid_continue'])) {
            $args['continue'] = wp_unslash((string) $_GET['inid_continue']);
        }
        $src = add_query_arg(array_map('rawurlencode', $args), wp_login_url());
        $id = 'inid-' . wp_generate_password(6, false);
        return '<div class="inid-signin"><iframe id="' . esc_attr($id) . '" src="' . esc_url($src) . '" title="' . esc_attr__('Iniciar sesión con InID', 'inid') . '" style="display:block;width:100%;height:300px;border:0;background:transparent" scrolling="no" allow="publickey-credentials-get *; publickey-credentials-create *"></iframe></div>'
            . '<script>(function(){var f=document.getElementById(' . wp_json_encode($id) . ');addEventListener("message",function(e){if(e.source!==f.contentWindow||!e.data||e.data.type!=="inid:height")return;var h=Number(e.data.height);if(h>0&&h<4000)f.style.height=Math.ceil(h)+"px";});})();</script>';
    }

    // ---------- to InID ----------

    public function start(): void
    {
        $embed = !empty($_GET['embed']);
        $issuer = rtrim($this->settings->get('public_url'), '/');
        // Back from Google: the frame goes on with the same sign-in
        if ($embed && isset($_GET['continue'])) {
            parse_str(wp_unslash((string) $_GET['continue']), $q);
            $kept = ['display' => 'embed'];
            foreach (['request', 'session', 'from', 'lang'] as $k) {
                if (!empty($q[$k])) {
                    $kept[$k] = sanitize_text_field((string) $q[$k]);
                }
            }
            wp_redirect($issuer . '/oauth/authorize/continue?' . http_build_query($kept));
            exit;
        }
        $state = $this->random(16);
        $flow = [
            'nonce' => $this->random(16),
            'verifier' => $this->random(32),
            'back' => wp_validate_redirect(wp_unslash((string) ($_GET['redirect_to'] ?? '')), admin_url()),
            'embed' => $embed,
        ];
        setcookie(self::FLOW_COOKIE . $state, base64_encode(wp_json_encode($flow)), ['expires' => time() + 600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        $query = [
            'client_id' => $this->settings->get('publishable_key'),
            'redirect_uri' => $this->redirect_uri(),
            'response_type' => 'code',
            'scope' => 'openid profile email phone',
            'state' => $state,
            'nonce' => $flow['nonce'],
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $flow['verifier'], true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'ui_locales' => substr(get_locale(), 0, 2),
        ];
        // The door of the app this page chose: its rules, its role
        $entrance = self::entrance_key(wp_unslash((string) ($_GET['entrance'] ?? '')));
        if ($entrance !== '') {
            $query['entrance'] = $entrance;
        }
        // In the page, someone InID knows still clicks to go in (never just by loading it)
        if ($embed) {
            $query['display'] = 'embed';
            $query['prompt'] = 'login';
        }
        wp_redirect($issuer . '/oauth/authorize?' . http_build_query($query));
        exit;
    }

    // ---------- back from InID ----------

    public function callback(): void
    {
        $state = sanitize_text_field(wp_unslash((string) ($_GET['state'] ?? '')));
        $flow = $this->flow($state);
        $leave = function (string $to) use ($flow) {
            $this->leave($to, !empty($flow['embed']));
        };
        // Back from Google, a sign-in from the page goes on in the page
        if (isset($_GET['inid_continue']) && $flow && !empty($flow['embed'])) {
            wp_safe_redirect(add_query_arg('inid_continue', rawurlencode(wp_unslash((string) $_GET['inid_continue'])), wp_login_url($flow['back'])));
            exit;
        }
        $this->forget_flow($state);
        if (!$flow || empty($_GET['code'])) {
            $leave($flow['back'] ?? wp_login_url());
            return;
        }
        $tokens = $this->client->oidc_token((string) wp_unslash($_GET['code']), $flow['verifier'], $this->redirect_uri());
        $claims = is_wp_error($tokens) ? null : $this->claims((string) ($tokens['id_token'] ?? ''), $flow['nonce']);
        if (!$claims) {
            self::log('sign-in failed: ' . (is_wp_error($tokens) ? $tokens->get_error_code() : 'id_token not accepted'));
            $leave(add_query_arg('inid_error', '1', wp_login_url($flow['back'])));
            return;
        }
        $user_id = $this->user_for($claims);
        if (is_wp_error($user_id)) {
            self::log('sign-in failed: ' . $user_id->get_error_code() . ' ' . $user_id->get_error_message());
            $leave(add_query_arg('inid_error', '1', wp_login_url($flow['back'])));
            return;
        }
        // The session WordPress makes now carries InID's (to end both together)
        $this->signing_in = ['inid_sid' => (string) ($claims['sid'] ?? ''), 'inid_refresh' => (string) ($tokens['refresh_token'] ?? '')];
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true, is_ssl());
        do_action('wp_login', get_userdata($user_id)->user_login, get_userdata($user_id));
        $leave($flow['back']);
    }

    /** @var array|null what the session being made right now carries */
    private $signing_in = null;

    public function session_information(array $info, int $user_id): array
    {
        return $this->signing_in ? array_merge($info, $this->signing_in) : $info;
    }

    /** Where the whole window goes: from inside InID's frame, the top, not the frame */
    private function leave(string $to, bool $embed): void
    {
        if (!$embed) {
            wp_safe_redirect($to);
            exit;
        }
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><script>(window.top||window).location.replace(' . wp_json_encode(wp_validate_redirect($to, admin_url())) . ')</script>';
        exit;
    }

    // ---------- the person in WordPress ----------

    /** Found by their InID id, else by their confirmed email (then linked), else created */
    private function user_for(array $claims)
    {
        $sub = (string) $claims['sub'];
        $email = !empty($claims['email_verified']) ? sanitize_email((string) ($claims['email'] ?? '')) : '';
        $user_id = $this->existing($sub, $email);
        // Someone the app removed who authorized it again (InID let them in): back
        if ($user_id) {
            delete_user_meta($user_id, 'inid_removed');
        }
        if (!$user_id) {
            $login = $this->unique_login($email ?: ('inid_' . substr($sub, 0, 10)));
            $user_id = wp_insert_user([
                'user_login' => $login,
                'user_pass' => wp_generate_password(32, true, true),
                'user_email' => $email,
                'first_name' => (string) ($claims['given_name'] ?? ''),
                'last_name' => (string) ($claims['family_name'] ?? ''),
                'display_name' => trim(($claims['given_name'] ?? '') . ' ' . ($claims['family_name'] ?? '')) ?: $login,
                'role' => get_option('default_role', 'subscriber'),
            ]);
            // Made a moment ago by InID's webhook (WooCommerce's new customer): that one
            if (is_wp_error($user_id)) {
                $again = $this->existing($sub, $email);
                if (!$again) {
                    return $user_id;
                }
                $user_id = $again;
            }
        }
        update_user_meta($user_id, self::SUB_META, $sub);
        $this->apply_role($user_id, (array) ($claims['roles'] ?? []));
        return $user_id;
    }

    /**
     * The account already here for this person: by their InID id, else by their confirmed
     * email, linking only accounts that can't edit anything (subscribers, customers); the
     * others are linked by hand
     */
    private function existing(string $sub, string $email): int
    {
        $found = get_users(['meta_key' => self::SUB_META, 'meta_value' => $sub, 'number' => 1, 'fields' => 'ID']);
        if ($found) {
            return (int) $found[0];
        }
        $by_email = $email ? get_user_by('email', $email) : null;
        return $by_email && !user_can($by_email, 'edit_posts') ? (int) $by_email->ID : 0;
    }

    /**
     * InID roles → WordPress roles ("admin=editor" per line). A role that can manage the site
     * is never given, and an administrator's role is never touched. When InID takes the role
     * back, the WordPress role it gave goes back to the site's default.
     */
    private function apply_role(int $user_id, array $roles): void
    {
        $user = get_userdata($user_id);
        if (!$user || user_can($user, 'manage_options')) {
            return;
        }
        $map = self::role_map($this->settings->get('role_map'));
        $given = (string) get_user_meta($user_id, 'inid_wp_role', true);
        foreach ($roles as $role) {
            $wp_role = $map[$role] ?? '';
            $object = $wp_role ? get_role($wp_role) : null;
            if ($object && !$object->has_cap('manage_options')) {
                if (!in_array($wp_role, (array) $user->roles, true)) {
                    $user->set_role($wp_role);
                }
                update_user_meta($user_id, 'inid_wp_role', $wp_role);
                return;
            }
        }
        if ($given !== '' && in_array($given, (array) $user->roles, true)) {
            $user->set_role(get_option('default_role', 'subscriber'));
        }
        delete_user_meta($user_id, 'inid_wp_role');
    }

    public static function entrance_key(string $key): string
    {
        $key = strtolower(trim($key));
        return preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $key) ? $key : '';
    }

    /** "/intranet = author, editor (team)" per line: [path, roles, entrance] */
    public static function routes(string $text): array
    {
        $routes = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if (!preg_match('#^\s*(/[^\s=]*)\s*=?\s*([^()]*?)\s*(?:\(\s*([a-z][a-z0-9_-]*)\s*\))?\s*$#i', $line, $m)) {
                continue;
            }
            $roles = array_values(array_filter(array_map('sanitize_key', preg_split('/[\s,]+/', $m[2]))));
            $routes[] = ['path' => untrailingslashit($m[1]) ?: '/', 'roles' => $roles, 'entrance' => self::entrance_key($m[3] ?? '')];
        }
        return $routes;
    }

    /**
     * A protected page: whoever isn't signed in goes to sign in (through the page's entrance),
     * and whoever is but has none of its roles sees that it isn't for them. Administrators
     * always pass.
     */
    public function guard_routes(): void
    {
        $path = untrailingslashit((string) wp_parse_url(add_query_arg([]), PHP_URL_PATH)) ?: '/';
        $home = untrailingslashit((string) wp_parse_url(home_url('/'), PHP_URL_PATH));
        if ($home !== '' && strpos($path, $home) === 0) {
            $path = substr($path, strlen($home)) ?: '/';
        }
        foreach (self::routes($this->settings->get('protected_routes')) as $route) {
            $under = $route['path'] === '/' || $path === $route['path'] || strpos($path, $route['path'] . '/') === 0;
            if (!$under) {
                continue;
            }
            if (!is_user_logged_in()) {
                $to = wp_login_url(home_url(add_query_arg([])));
                wp_safe_redirect($route['entrance'] !== '' ? add_query_arg('inid_entrance', $route['entrance'], $to) : $to);
                exit;
            }
            $user = wp_get_current_user();
            if ($route['roles'] && !user_can($user, 'manage_options') && !array_intersect($route['roles'], (array) $user->roles)) {
                $texts = ['es' => ['Esta página no es para tu cuenta', 'Entra con otra cuenta'], 'pt' => ['Esta página não é para a sua conta', 'Entrar com outra conta']][substr(get_locale(), 0, 2)] ?? ['This page isn\'t for your account', 'Sign in with another account'];
                $again = wp_logout_url(add_query_arg($route['entrance'] !== '' ? ['inid_entrance' => $route['entrance']] : [], wp_login_url(home_url(add_query_arg([])))));
                wp_die('<p>' . esc_html($texts[0]) . '.</p><p><a href="' . esc_url($again) . '">' . esc_html($texts[1]) . '</a></p>', esc_html($texts[0]), ['response' => 403]);
            }
            return;
        }
    }

    public static function role_map(string $text): array
    {
        $map = [];
        foreach (preg_split('/[\r\n,]+/', $text) as $line) {
            $pair = array_map('trim', explode('=', $line, 2));
            if (count($pair) === 2 && $pair[0] !== '' && $pair[1] !== '') {
                $map[$pair[0]] = sanitize_key($pair[1]);
            }
        }
        return $map;
    }

    // ---------- what InID tells the site (its webhook) ----------

    /** user.updated, user.deleted, session.ended; true when the event was one of these */
    public function handle_event(array $event): bool
    {
        $type = (string) ($event['type'] ?? '');
        if (!in_array($type, ['user.updated', 'user.deleted', 'session.ended'], true)) {
            return false;
        }
        $sub = sanitize_text_field((string) ($event['sub'] ?? ''));
        $found = get_users(['meta_key' => self::SUB_META, 'meta_value' => $sub, 'number' => 1, 'fields' => 'ID']);
        if (!$found) {
            return true;
        }
        $user_id = (int) $found[0];
        if ($type === 'user.updated') {
            $u = $this->client->app_user($sub);
            if (!is_wp_error($u)) {
                $change = ['ID' => $user_id, 'first_name' => (string) ($u['firstName'] ?? ''), 'last_name' => (string) ($u['lastName'] ?? '')];
                $email = !empty($u['emailVerified']) ? sanitize_email((string) ($u['email'] ?? '')) : '';
                $owner = $email ? email_exists($email) : false;
                if ($email && (!$owner || (int) $owner === $user_id)) {
                    $change['user_email'] = $email;
                }
                wp_update_user($change);
                $this->apply_role($user_id, isset($u['role']) && $u['role'] ? [$u['role']] : []);
            }
        } elseif ($type === 'user.deleted') {
            // Gone from the app: their last copy (if InID gives one), and no more sign-ins
            if (!empty($event['token'])) {
                $copy = $this->client->exchange((string) $event['token']);
                if (!is_wp_error($copy)) {
                    update_user_meta($user_id, 'inid_final_copy', wp_json_encode($copy));
                }
            }
            update_user_meta($user_id, 'inid_removed', time());
            WP_Session_Tokens::get_instance($user_id)->destroy_all();
        } else {
            // That InID session ended: the WordPress session made from it ends too
            $sid = (string) ($event['sid'] ?? '');
            $sessions = get_user_meta($user_id, 'session_tokens', true);
            if ($sid && is_array($sessions)) {
                $kept = array_filter($sessions, function ($s) use ($sid) {
                    return ($s['inid_sid'] ?? '') !== $sid;
                });
                if (count($kept) !== count($sessions)) {
                    update_user_meta($user_id, 'session_tokens', $kept);
                }
            }
        }
        return true;
    }

    /** Signing out of WordPress ends that InID session too (its refresh token, revoked) */
    public function end_at_inid(): void
    {
        $user_id = get_current_user_id();
        $token = wp_get_session_token();
        if (!$user_id || !$token) {
            return;
        }
        $session = WP_Session_Tokens::get_instance($user_id)->get($token);
        if (!empty($session['inid_refresh'])) {
            $this->client->revoke((string) $session['inid_refresh']);
        }
    }

    // ---------- small things ----------

    private function redirect_uri(): string
    {
        return add_query_arg('action', 'inid_callback', wp_login_url());
    }

    private function flow(string $state)
    {
        if (!preg_match('/^[\w-]{10,64}$/', $state) || empty($_COOKIE[self::FLOW_COOKIE . $state])) {
            return null;
        }
        $flow = json_decode((string) base64_decode(wp_unslash($_COOKIE[self::FLOW_COOKIE . $state]), true), true);
        return is_array($flow) && !empty($flow['verifier']) ? $flow : null;
    }

    private function forget_flow(string $state): void
    {
        if ($state !== '') {
            setcookie(self::FLOW_COOKIE . $state, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        }
    }

    /**
     * The ID token's claims, checked: for this site, from InID, this sign-in's, still valid.
     * It came straight from InID's token endpoint over TLS (OpenID Connect Core 3.1.3.7), so
     * its signature needs no second look here.
     */
    private function claims(string $id_token, string $nonce)
    {
        $parts = explode('.', $id_token);
        if (count($parts) !== 3) {
            return null;
        }
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        if (!is_array($claims) || empty($claims['sub'])) {
            return null;
        }
        $issuer = rtrim($this->settings->get('public_url'), '/');
        $ok = ($claims['aud'] ?? '') === $this->settings->get('publishable_key')
            && rtrim((string) ($claims['iss'] ?? ''), '/') === $issuer
            && hash_equals($nonce, (string) ($claims['nonce'] ?? ''))
            && (int) ($claims['exp'] ?? 0) > time();
        return $ok ? $claims : null;
    }

    /** Why a sign-in didn't work, in the PHP log (with WP_DEBUG on); never tokens or data */
    private static function log(string $what): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[InID] ' . $what); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
        }
    }

    private function unique_login(string $base): string
    {
        $login = sanitize_user(strtok($base, '@') ?: 'inid', true) ?: 'inid';
        $candidate = $login;
        for ($i = 2; username_exists($candidate); $i++) {
            $candidate = $login . $i;
        }
        return $candidate;
    }

    private function random(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
