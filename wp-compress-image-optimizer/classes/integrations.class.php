<?php
include_once(ABSPATH . 'wp-admin/includes/plugin.php');

spl_autoload_register(function ($class_name) {
    if (strpos($class_name, 'wps_ic_') !== false) {
        $class_name = str_replace('wps_ic_', '', $class_name);
        $class_name = $class_name . '.php';

        // Try main integrations folder first
        if (file_exists(WPS_IC_DIR . 'integrations/' . $class_name)) {
            include WPS_IC_DIR . 'integrations/' . $class_name;
        }
        // Try hosting subfolder
        else if (file_exists(WPS_IC_DIR . 'integrations/hosting/' . $class_name)) {
            include WPS_IC_DIR . 'integrations/hosting/' . $class_name;
        }
    }
});

class wps_ic_integrations extends wps_ic
{
    protected $plugin_checks = [];
    protected $overrides;
    protected $int_option;

    protected $wps_settings;
    protected $notices_class;
    protected $list;

    public function __construct()
    {
        $this->int_option = get_option('wps_ic_integrations');

        if (!$this->int_option) {
            $this->int_option = [];
        }

        $this->wps_settings = parent::$settings;
        $this->notices_class = new wps_ic_notices();
    }

    public function render_plugin_notices(){
      $this->notices_class->render_plugin_notices();
    }

    public function fix($plugin, $setting)
    {
        $this->init();

        foreach ($this->plugin_checks as $plugin_check) {
            if (get_class($plugin_check) === 'wps_ic_' . $plugin) {
                if (method_exists($plugin_check, 'fix')) {
                    return $plugin_check->fix_setting($setting);
                }
            }
        }

        return false;
    }

    public function init()
    {
        $list = [];

        //This should only be done in admin, it saves all needed fixes, notices, filters and hooks to option
        $this->int_option['overrides'] = [];
        $this->int_option['front_filters'] = [];
        $this->int_option['admin_filters'] = [];
        $this->int_option['admin_hooks'] = [];

        $this->plugin_checks = [
            new wps_ic_rocket(),
            new wps_ic_perfmatters(),
            new wps_ic_litespeed(),
            new wps_ic_optimizepress(),
            new wps_ic_elementor(),
            new wps_ic_beaverbuilder(),
            new wps_ic_avada(),
            new wps_ic_simplecustomcss(),
            new wps_ic_bridge(),
            new wps_ic_advanced_custom_fields(),
            new wps_ic_studiopress(),
            // Cache Plugins
            new wps_ic_wp_optimize(),
            new wps_ic_wp_super_cache(),
            new wps_ic_w3_total_cache(),
            new wps_ic_wp_fastest_cache(),
            new wps_ic_cachify(),
            new wps_ic_comet_cache(),
            new wps_ic_zen_cache(),
            new wps_ic_breeze(),
            new wps_ic_swift_performance(),
            new wps_ic_hummingbird(),
            new wps_ic_autoptimize(),
            new wps_ic_nginx_helper(),
            new wps_ic_varnish_http_purge(),
            // CDN/Cloudflare
            new wps_ic_cloudflare(),
            new wps_ic_wp_cloudflare_page_cache(),
            // Hosting Providers
            new wps_ic_pantheon(),
            new wps_ic_pressidium(),
            new wps_ic_pagely(),
            new wps_ic_presslabs(),
            new wps_ic_siteground(),
            new wps_ic_kinsta(),
            new wps_ic_wpengine(),
            new wps_ic_cloudways(),
            new wps_ic_pressable(),
            new wps_ic_savvii(),
            new wps_ic_spinupwp(),
            new wps_ic_o2switch(),
            new wps_ic_godaddy(),
            new wps_ic_wordpresscom(),
            new wps_ic_dreampress(),
            new wps_ic_flywheel(),
            new wps_ic_wp_serveur(),

            new wps_ic_yith_wcmcs_currency_switcher()
        ];


        foreach ($this->plugin_checks as $plugin_check) {
            if ($plugin_check->is_active()) {
                if (method_exists($plugin_check, 'getConflictsList')) {
                    $list[get_class($plugin_check)] = $plugin_check->getConflictsList();
                }

                if (method_exists($plugin_check, 'do_frontend_filters')) {
                    $this->int_option['front_filters'][get_class($plugin_check)] = $plugin_check->do_frontend_filters();
                }

                if (method_exists($plugin_check, 'do_admin_filters')) {
                    $this->int_option['admin_filters'][get_class($plugin_check)] = $plugin_check->do_admin_filters();
                }

                if (method_exists($plugin_check, 'add_admin_hooks')) {
                    $this->int_option['admin_hooks'][get_class($plugin_check)] = $plugin_check->add_admin_hooks();
                }
            }
        }

        update_option('wps_ic_conflicts', $list);

        //at this point all overrides, filters and hooks are included, so save to option
        update_option('wps_ic_integrations', $this->int_option);

        //These are conflicted settings checks that dont have to run on every load
        $checked = get_transient('wps_ic_conflicts_check');
        if ($checked){
          return;
        }

        foreach ($this->plugin_checks as $plugin_check) {
            if ($plugin_check->is_active()) {
                $plugin_check->do_checks();
            }
        }

        //CF checks
        $cf = get_option(WPS_IC_CF);
        if (!empty($cf) && !empty($cf['token'])){
            require_once WPS_IC_DIR.'/addons/cf-sdk/cf-sdk.php';
            $cfsdk = new WPC_CloudflareAPI($cf['token']);
            $rocketSettings = $cfsdk->checkRocketLoader($cf['zone']);
            if (isset($rocketSettings) && $rocketSettings === 'failed to fetch rocket loader') {
              // Do nothing!
            } else {
                if (isset($rocketSettings[$cf['zone']]['value']) && $rocketSettings[$cf['zone']]['value'] == 'on') {
                    $cfsdk->setRocketLoader($cf['zone'], 'off');
                    $cache = new wps_ic_cache_integrations();
                    $cache->purgeAll();
                }
            }
        }

        set_transient('wps_ic_conflicts_check', true, 15 * MINUTE_IN_SECONDS);
    }

    public function add_admin_hooks()
    {
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

    public function getConflicts()
    {
        return get_option('wps_ic_conflicts', []);
    }

    public function apply_frontend_filters()
    {
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

    public function apply_admin_filters()
    {
        if (isset($this->int_option['admin_filters']) && is_array($this->int_option['admin_filters'])) {
            foreach ($this->int_option['admin_filters'] as $class => $hooks) {
                $plugin_instance = new $class();
                if (!empty($hooks) && is_array($hooks)) {
                    foreach ($hooks as $hook => $data) {
                        add_filter($hook, [$plugin_instance, $data['callback']], $data['priority'], $data['args']);
                    }
                }
            }
        }
    }
}