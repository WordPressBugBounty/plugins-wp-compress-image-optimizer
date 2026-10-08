<?php

class wps_ic_users extends wps_ic{

    public static $settings;

    public function __construct()
    {
        self::$settings = parent::$settings;
        $this->checkAndAddCaps();
    }


    /**
     * Check and add missing capabilities for roles.
     */
    public function checkAndAddCaps() {
        $this->ensureRoleHasCap('administrator', 'manage_wpc_settings');

        $roles = $this->getRoles(['skip_admin' => true]);
        if (!empty($roles)) {
            foreach ($roles as $key => $role) {

                if ($this->permissionEnabled($key, 'purge')) {
                    $this->ensureRoleHasCap($key, 'manage_wpc_purge');
                } else {
                    $this->removeCap($key, 'manage_wpc_purge');
                }

                if ($this->permissionEnabled($key, 'manage_wpc')) {
                    $this->ensureRoleHasCap($key, 'manage_wpc_settings');
                } else {
                    $this->removeCap($key, 'manage_wpc_settings');
                }
            }
        }
    }


    /**
     * Check if permission is added
     */
    public function permissionEnabled($role, $permission)
    {
        $grants = self::grants();
        return isset($grants[$role . '_' . $permission]) && $grants[$role . '_' . $permission] === '1';
    }


    public static function grants()
    {
        $grants = get_option('wpc_role_grants', null);
        if (is_array($grants)) {
            return $grants;
        }
        if (get_option('wpc_role_grants_seeded')) {
            update_option('wpc_role_grants', [], true);
            return [];
        }
        $settings = get_option(WPS_IC_SETTINGS);
        $legacy = self::grantsFrom(is_array($settings) && isset($settings['permissions']) ? $settings['permissions'] : []);
        $grants = [];
        $dropped = [];
        foreach ($legacy as $key => $value) {
            if (substr($key, -11) === '_manage_wpc' && self::roleCannotEdit(substr($key, 0, -11))) {
                $dropped[] = $key;
                continue;
            }
            $grants[$key] = $value;
        }
        update_option('wpc_role_grants', $grants, true);
        update_option('wpc_role_grants_seeded', '1', true);
        if (!empty($dropped)) {
            update_option('wpc_role_grants_dropped', $dropped, false);
        }

        return $grants;
    }


    private static function roleCannotEdit($roleName)
    {
        $role = function_exists('get_role') ? get_role($roleName) : null;

        return $role && !$role->has_cap('edit_others_posts');
    }


    public static function isProtectedOption($name)
    {
        return in_array(strtolower(trim((string) $name)), ['wpc_role_grants', 'wpc_role_grants_seeded', 'wpc_role_grants_dropped'], true);
    }


    public static function isLocalOnlySetting($key)
    {
        return in_array(strtolower(trim((string) $key)), ['permissions'], true);
    }


    public static function keepLocalOnlySettings($incoming, $stored)
    {
        if (!is_array($incoming)) {
            return $incoming;
        }
        foreach (array_keys($incoming) as $key) {
            if (self::isLocalOnlySetting($key)) {
                unset($incoming[$key]);
            }
        }
        if (is_array($stored)) {
            foreach ($stored as $key => $value) {
                if (self::isLocalOnlySetting($key)) {
                    $incoming[$key] = $value;
                }
            }
        }

        return $incoming;
    }


    public static function grantsFrom($matrix)
    {
        $grants = [];
        if (!is_array($matrix)) {
            return $grants;
        }
        foreach ($matrix as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^([A-Za-z0-9_.-]+)_(purge|manage_wpc)$/', $key, $m) && $m[1] !== 'administrator'
                && ($value === '1' || $value === 1 || $value === true)) {
                $grants[$key] = '1';
            }
        }

        return $grants;
    }


    /**
     * Whether this request may change which roles have plugin access: an administrator
     * (manage_options) on the site itself. Never in the agency portal: its settings screen shows
     * a client site, whose grants are changed on that site.
     */
    public static function canEditGrants()
    {
        if (defined('WPS_IC_AGENCY') && WPS_IC_AGENCY) {
            return false;
        }

        return function_exists('current_user_can') && current_user_can('manage_options');
    }


    public static function setGrant($key, $value)
    {
        if (!self::canEditGrants()) {
            return false;
        }
        $key = (string) $key;
        if (self::grantsFrom([$key => '1']) === []) {
            return false;
        }
        $grants = self::grants();
        if ((string) $value === '1') {
            $grants[$key] = '1';
        } else {
            unset($grants[$key]);
        }
        update_option('wpc_role_grants', $grants, true);

        return true;
    }


    public static function saveGrants($matrix)
    {
        if (!self::canEditGrants()) {
            return false;
        }
        update_option('wpc_role_grants', self::grantsFrom($matrix), true);

        return true;
    }


    /**
     * The names of the roles whose plugin settings access the one-time import of earlier grants
     * removed (option wpc_role_grants_dropped) and that an administrator has not granted again.
     */
    public static function droppedGrantRoles()
    {
        $dropped = get_option('wpc_role_grants_dropped', []);
        if (!is_array($dropped) || !$dropped) {
            return [];
        }
        $grants = get_option('wpc_role_grants', []);
        $grants = is_array($grants) ? $grants : [];
        global $wp_roles;
        $names = [];
        foreach ($dropped as $key) {
            if (!is_string($key) || substr($key, -11) !== '_manage_wpc' || (isset($grants[$key]) && $grants[$key] === '1')) {
                continue;
            }
            $slug = substr($key, 0, -11);
            $name = (is_object($wp_roles) && isset($wp_roles->roles[$slug]['name']) && is_string($wp_roles->roles[$slug]['name']))
                ? $wp_roles->roles[$slug]['name'] : $slug;
            $names[$slug] = function_exists('translate_user_role') ? translate_user_role($name) : $name;
        }

        return array_values($names);
    }


    /**
     * Raises the administrator-only state notice naming those roles, and clears it once there
     * are none. A dismissal holds until the notice is cleared.
     */
    public static function syncDroppedGrantsNotice()
    {
        if (!function_exists('wpc_set_state_notice') || (function_exists('wp_doing_ajax') && wp_doing_ajax())) {
            return;
        }
        $roles = self::droppedGrantRoles();
        if (!$roles) {
            wpc_clear_state_notice('role_grants_dropped');
            return;
        }
        $text = sprintf(
            /* translators: 1: plugin name, 2: comma-separated role names */
            __('%1$s security update: settings access was removed from these roles: %2$s. Grant it again under User Permissions only if they still need it.', 'wp-compress-image-optimizer'),
            function_exists('wpc_brand_name') ? wpc_brand_name() : 'WP Compress',
            implode(', ', $roles)
        );
        wpc_set_state_notice('role_grants_dropped', 'warning', $text, function_exists('wpc_settings_page_url') ? wpc_settings_page_url() : '',
            __('Review permissions', 'wp-compress-image-optimizer'), 'manage_options');
    }


    public function removeCap($role, $cap)
    {
        $role = get_role($role);

        if ($role && $role->has_cap($cap)) {
            $role->remove_cap($cap);
        }
    }


    /**
     * Ensures that a role has a specific capability. Adds it if missing.
     *
     * @param string $role_name
     * @param string $cap
     */
    private function ensureRoleHasCap($role_name, $cap) {
        $role = get_role($role_name);

        if ($role && !$role->has_cap($cap)) {
            $role->add_cap($cap);
        }
    }


    public function getRoles($args = []) {
        global $wp_roles;

        if ( ! isset( $wp_roles ) ) {
            $wp_roles = new WP_Roles();
        }

        $roles = [];
        foreach ( $wp_roles->roles as $key => $role ) {
            if (isset($args['skip_admin']) && $args['skip_admin']) {
                if ($key == 'administrator') continue;
            }
            $roles[$key] = $role['name'];
        }

        return $roles;
    }


}