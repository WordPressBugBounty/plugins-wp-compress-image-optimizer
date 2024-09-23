<?php
include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

spl_autoload_register( function ( $class_name ) {
	if ( strpos( $class_name, 'wps_ic_' ) !== false ) {
		$class_name = str_replace( 'wps_ic_', '', $class_name );
		$class_name = $class_name . '.php';
		if ( file_exists( WPS_IC_DIR . 'integrations/' . $class_name ) ) {
			include WPS_IC_DIR . 'integrations/' . $class_name;
		}
	}
} );

class wps_ic_integrations extends wps_ic {
	protected $wps_settings;
	protected $notices_class;
	protected $plugin_checks = [];
	protected $overrides;

	public function __construct() {
		$this->int_option    = get_option( 'wps_ic_integrations' );
		$this->wps_settings  = parent::$settings;
		$this->notices_class = new wps_ic_notices();
	}

	public function init() {
		//This should only be done in admin, it saves all needed fixes, notices, filters and hooks to option
		$this->int_option['overrides']     = [];
		$this->int_option['front_filters'] = [];
		$this->int_option['admin_hooks'] = [];

		$this->plugin_checks = [
			new wps_ic_rocket(),
			new wps_ic_perfmatters(),
			new wps_ic_litespeed(),
			new wps_ic_optimizepress(),
			new wps_ic_elementor()
			// ... Add other plugin classes here.
		];


		foreach ( $this->plugin_checks as $plugin_check ) {
			if ( $plugin_check->is_active() ) {
				$plugin_check->do_checks();

				if (method_exists($plugin_check, 'do_frontend_filters')) {
					$this->int_option['front_filters'][get_class($plugin_check)] = $plugin_check->do_frontend_filters();
				}

				if (method_exists($plugin_check, 'add_admin_hooks')) {
					$this->int_option['admin_hooks'][get_class($plugin_check)] = $plugin_check->add_admin_hooks();
				}
			}
		}

		//at this point all overrides, filters and hooks are included, so save to option
		update_option( 'wps_ic_integrations', $this->int_option );
	}

	public function fix( $plugin, $setting ) {
		$this->init();

		foreach ( $this->plugin_checks as $plugin_check ) {
			if ( get_class( $plugin_check ) === 'wps_ic_' . $plugin ) {
				if ( method_exists( $plugin_check, 'fix' ) ) {
					return $plugin_check->fix_setting( $setting );
				}
			}
		}

		return false;
	}

	public function add_override( $setting ) {
		$this->int_option['overrides'][ $setting ] = true;
	}

	public function remove_override( $setting ) {
		//this is only called from fix_setting() from ajax
		unset ( $this->int_option['overrides'][ $setting ] );
		update_option( 'wps_ic_integrations', $this->int_option['overrides'] );
	}

	public function is_override_active( $setting ) {
		return isset( $this->int_option['overrides'][ $setting ] ) ? $this->int_option['overrides'][ $setting ] : false;
	}

	public function apply_frontend_filters() {
		if (isset($this->int_option['front_filters']) && is_array($this->int_option['front_filters'])) {
			foreach ($this->int_option['front_filters'] as $class => $hooks) {
				$plugin_instance = new $class();
                if (!empty($hooks) && is_array($hooks)) {
                    foreach ($hooks as $hook => $data) {
                        add_filter($hook, [$plugin_instance, $data['callback']], $data['priority'], $data['args']);
                    }
                }
			}
		}
	}

	public function add_admin_hooks() {
		if (isset($this->int_option['admin_hooks']) && is_array($this->int_option['admin_hooks'])) {
			foreach ($this->int_option['admin_hooks'] as $class => $hooks) {
				$plugin_instance = new $class();
                if (!empty($hooks) && is_array($hooks)) {
                    foreach ($hooks as $hook => $data) {
                        add_action($hook, [$plugin_instance, $data['callback']], $data['priority'], $data['args']);
                    }
                }
			}
		}
	}
}