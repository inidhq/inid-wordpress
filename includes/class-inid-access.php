<?php
defined('ABSPATH') || exit;

/**
 * "Acceso con InID" on each page, post or product: anyone (the default), only people signed
 * in, only some roles of the app, or only some of its permissions; and the InID entrance
 * people sign in through to see it. The roles and permissions are the app's own (made in the
 * InID panel), not WordPress's: each person's come with their sign-in and with user.updated.
 *
 * Whoever isn't signed in goes to sign in; whoever is, without the role or permission, gets a
 * 403. The content is also kept out of lists, search, feeds and the REST API. Whoever can
 * edit the page, and administrators, always see it.
 */
class InID_Access
{
    const META = '_inid_access';
    const USER_ROLES = '_inid_app_roles';
    const USER_PERMISSIONS = '_inid_app_permissions';
    const CACHE = 'inid_app_roles';

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
        add_action('add_meta_boxes', [$this, 'add_box']);
        add_action('save_post', [$this, 'save'], 10, 2);
        add_action('template_redirect', [$this, 'guard'], 5);
        add_filter('the_content', [$this, 'hide'], 1);
        add_filter('get_the_excerpt', [$this, 'hide'], 1, 2);
        add_filter('display_post_states', [$this, 'state'], 10, 2);
    }

    // ---------- the app's roles ----------

    /** The app's roles, permissions and entrances (kept five minutes) */
    public function model(bool $fresh = false): array
    {
        $kept = $fresh ? false : get_transient(self::CACHE);
        if (is_array($kept)) {
            return $kept;
        }
        $got = $this->client->app_roles();
        $model = is_wp_error($got) ? ['roles' => [], 'entrances' => [], 'error' => true] : [
            'roles' => array_values((array) ($got['roles'] ?? [])),
            'entrances' => array_values((array) ($got['entrances'] ?? [])),
        ];
        set_transient(self::CACHE, $model, is_wp_error($got) ? MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS);
        return $model;
    }

    /** The permissions these roles of the app give */
    public function permissions_of(array $roles): array
    {
        $all = [];
        foreach ($this->model()['roles'] as $role) {
            if (in_array($role['key'] ?? '', $roles, true)) {
                $all = array_merge($all, (array) ($role['permissions'] ?? []));
            }
        }
        return array_values(array_unique($all));
    }

    /** A person's role and permissions in the app, as InID said them last */
    public static function remember(int $user_id, array $roles, array $permissions): void
    {
        update_user_meta($user_id, self::USER_ROLES, array_values(array_map('strval', $roles)));
        update_user_meta($user_id, self::USER_PERMISSIONS, array_values(array_map('strval', $permissions)));
    }

    // ---------- the box in the editor ----------

    public function add_box(): void
    {
        foreach (get_post_types(['public' => true]) as $type) {
            if ($type !== 'attachment') {
                add_meta_box('inid-access', 'Acceso con InID', [$this, 'box'], $type, 'side');
            }
        }
    }

    public function box(WP_Post $post): void
    {
        $rule = self::rule($post->ID);
        $model = $this->model(true);
        $permissions = [];
        foreach ($model['roles'] as $role) {
            $permissions = array_merge($permissions, (array) ($role['permissions'] ?? []));
        }
        $permissions = array_values(array_unique($permissions));
        wp_nonce_field('inid_access', 'inid_access_nonce');
        $mode = function ($value, $label) use ($rule) {
            printf('<label style="display:block;margin:4px 0"><input type="radio" name="inid_access[mode]" value="%s" %s> %s</label>', esc_attr($value), checked($rule['mode'], $value, false), esc_html($label));
        };
        echo '<div class="inid-access">';
        $mode('', 'Cualquiera');
        $mode('signed_in', 'Solo con sesión iniciada');
        $mode('roles', 'Solo estos roles de la app');
        echo '<div class="inid-access-list" data-for="roles" style="margin:2px 0 6px 22px">';
        foreach ($model['roles'] as $role) {
            printf('<label style="display:block"><input type="checkbox" name="inid_access[roles][]" value="%s" %s> %s</label>', esc_attr($role['key']), checked(in_array($role['key'], $rule['roles'], true), true, false), esc_html($role['name'] ?? $role['key']));
        }
        if (!$model['roles']) {
            echo '<p class="description">La app aún no tiene roles. Créalos en el panel de InID, en Roles.</p>';
        }
        echo '</div>';
        $mode('permissions', 'Solo con estos permisos de la app');
        echo '<div class="inid-access-list" data-for="permissions" style="margin:2px 0 6px 22px">';
        foreach ($permissions as $permission) {
            printf('<label style="display:block"><input type="checkbox" name="inid_access[permissions][]" value="%s" %s> <code>%s</code></label>', esc_attr($permission), checked(in_array($permission, $rule['permissions'], true), true, false), esc_html($permission));
        }
        if (!$permissions) {
            echo '<p class="description">Los roles de la app aún no tienen permisos.</p>';
        }
        echo '</div>';
        echo '<p style="margin:10px 0 4px"><label for="inid-entrance"><strong>Iniciar sesión por</strong></label></p><select id="inid-entrance" name="inid_access[entrance]" style="width:100%"><option value="">La entrada general</option>';
        foreach ($model['entrances'] as $entrance) {
            printf('<option value="%s" %s>%s</option>', esc_attr($entrance['key']), selected($rule['entrance'], $entrance['key'], false), esc_html($entrance['name'] ?? $entrance['key']));
        }
        echo '</select>';
        if (!empty($model['error'])) {
            echo '<p class="description" style="color:#b32d2e">No se pudo leer la app en InID. Revisa la conexión del plugin.</p>';
        }
        echo '<p class="description">Quien no tiene sesión va a iniciarla; quien no tiene el rol o el permiso ve que la página no es para su cuenta. Tú y los administradores siempre la ven.</p></div>';
        // Only the list of the chosen kind shows
        echo '<script>(function(){var b=document.querySelector(".inid-access");if(!b)return;function s(){var m=(b.querySelector("input[name=\'inid_access[mode]\']:checked")||{}).value||"";b.querySelectorAll(".inid-access-list").forEach(function(l){l.style.display=l.dataset.for===m?"":"none";});}b.addEventListener("change",s);s();})();</script>';
    }

    public function save(int $post_id, WP_Post $post): void
    {
        if (!isset($_POST['inid_access_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['inid_access_nonce'])), 'inid_access')) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id) || !current_user_can('edit_post', $post_id)) {
            return;
        }
        $in = (array) wp_unslash($_POST['inid_access'] ?? []);
        $mode = in_array($in['mode'] ?? '', ['signed_in', 'roles', 'permissions'], true) ? $in['mode'] : '';
        $clean = function ($list) {
            return array_values(array_unique(array_filter(array_map(function ($v) {
                return preg_replace('/[^A-Za-z0-9_:.\-]/', '', (string) $v);
            }, (array) $list))));
        };
        if ($mode === '') {
            delete_post_meta($post_id, self::META);
            return;
        }
        update_post_meta($post_id, self::META, [
            'mode' => $mode,
            'roles' => $mode === 'roles' ? $clean($in['roles'] ?? []) : [],
            'permissions' => $mode === 'permissions' ? $clean($in['permissions'] ?? []) : [],
            'entrance' => InID_SignIn::entrance_key((string) ($in['entrance'] ?? '')),
        ]);
    }

    // ---------- who sees it ----------

    public static function rule(int $post_id): array
    {
        $rule = get_post_meta($post_id, self::META, true);
        $rule = is_array($rule) ? $rule : [];
        return [
            'mode' => (string) ($rule['mode'] ?? ''),
            'roles' => (array) ($rule['roles'] ?? []),
            'permissions' => (array) ($rule['permissions'] ?? []),
            'entrance' => (string) ($rule['entrance'] ?? ''),
        ];
    }

    /** Whether the person signed in now may see this post */
    public static function allowed(int $post_id): bool
    {
        $rule = self::rule($post_id);
        if ($rule['mode'] === '') {
            return true;
        }
        if (!is_user_logged_in()) {
            return false;
        }
        if (current_user_can('manage_options') || current_user_can('edit_post', $post_id)) {
            return true;
        }
        $user_id = get_current_user_id();
        if ($rule['mode'] === 'roles') {
            return (bool) array_intersect($rule['roles'], (array) get_user_meta($user_id, self::USER_ROLES, true));
        }
        if ($rule['mode'] === 'permissions') {
            return (bool) array_intersect($rule['permissions'], (array) get_user_meta($user_id, self::USER_PERMISSIONS, true));
        }
        return true;
    }

    /** The page itself: to sign in, or a 403 */
    public function guard(): void
    {
        if (!is_singular()) {
            return;
        }
        $post_id = (int) get_queried_object_id();
        if (!$post_id || self::allowed($post_id)) {
            return;
        }
        $entrance = self::rule($post_id)['entrance'];
        if (!is_user_logged_in()) {
            InID_SignIn::to_sign_in($entrance);
        }
        InID_SignIn::deny($entrance);
    }

    /** Anywhere else it shows (lists, search, feeds, the REST API): not its content */
    public function hide($text, $post = null)
    {
        $post = get_post($post);
        if (!$post || self::allowed((int) $post->ID)) {
            return $text;
        }
        return ['es' => 'Contenido solo para algunas cuentas.', 'pt' => 'Conteúdo só para algumas contas.'][substr(get_locale(), 0, 2)] ?? 'Content only for some accounts.';
    }

    /** In the list of pages: which ones are protected */
    public function state(array $states, WP_Post $post): array
    {
        $rule = self::rule((int) $post->ID);
        if ($rule['mode'] === 'signed_in') {
            $states['inid'] = 'InID: con sesión';
        } elseif ($rule['mode'] === 'roles') {
            $names = [];
            foreach ($this->model()['roles'] as $role) {
                $names[$role['key']] = $role['name'] ?? $role['key'];
            }
            $states['inid'] = 'InID: ' . implode(', ', array_map(function ($key) use ($names) {
                return $names[$key] ?? $key;
            }, $rule['roles']));
        } elseif ($rule['mode'] === 'permissions') {
            $states['inid'] = 'InID: ' . implode(', ', $rule['permissions']);
        }
        return $states;
    }
}
