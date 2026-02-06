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
        if (isset(self::$settings['permissions'])) {
            $permissions = self::$settings['permissions'];
            if (isset($permissions[$role . '_' . $permission])) {
                return true;
            }
        }

        return false;
    }


    /*
     * Remove Role Capability
     */
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