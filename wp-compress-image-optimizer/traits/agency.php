<?php

trait wps_ic_agency_trait
{




    public static $remoteVitalsHasData = false;

    public function isAgencyPortal()
    {
        if (defined('WPS_IC_AGENCY') && WPS_IC_AGENCY) {
            self::$api_key = $this->extractApiKey();
            return true;
        }

        return false;
    }

    public function extractApiKey()
    {
        $uri = $_SERVER['REQUEST_URI'];
        if (preg_match('#^/view-site/([a-f0-9]+)/?$#', $uri, $matches)) {
            $key = $matches[1];
            return $key;
        }

        return false;
    }

    public function injectRemoteSettingsAsOptions(array $remoteSettings)
    {
        $settings    = $remoteSettings['settings']         ?? [];
        $mode        = $remoteSettings['mode']             ?? '';
        $excludes    = $remoteSettings['excludes']         ?? [];
        $inline      = $remoteSettings['inline']           ?? [];
        $urlExcludes = $remoteSettings['wpc-url-excludes'] ?? [];
        $allowLive   = $remoteSettings['allow_live']       ?? false;
        $gps         = $remoteSettings['gps']              ?? false;
        $tests       = $remoteSettings['tests']            ?? false;
        $planVersion = $remoteSettings['plan_version']     ?? '';
        $fontsMap    = $remoteSettings['fonts_map']        ?? false;
        $cf          = $remoteSettings['cf']               ?? [];
        $cfCname     = $remoteSettings['cf_cname']         ?? '';
        $remoteSiteUrl  = $remoteSettings['site_url']     ?? '';
        $remoteHomeUrl  = $remoteSettings['home_url']     ?? $remoteSiteUrl;

        // Override the static already populated at init time (before filters apply)
        wps_ic::$settings = $settings;

        add_filter('pre_option_' . WPS_IC_SETTINGS,   function() use ($settings)    { return $settings; });
        add_filter('pre_option_' . WPS_IC_PRESET,     function() use ($mode)        { return $mode; });
        add_filter('pre_option_wpc-excludes',         function() use ($excludes)    { return $excludes; });
        add_filter('pre_option_wpc-inline',           function() use ($inline)      { return $inline; });
        add_filter('pre_option_wpc-url-excludes',     function() use ($urlExcludes) { return $urlExcludes; });
        add_filter('pre_option_wps_ic_allow_live',    function() use ($allowLive)   { return $allowLive; });
        add_filter('pre_option_' . WPS_IC_LITE_GPS,   function() use ($gps)         { return $gps; });
        add_filter('pre_option_' . WPS_IC_TESTS,      function() use ($tests)       { return $tests; });
        add_filter('pre_option_' . WPS_IC_FONTS_MAP,  function() use ($fontsMap)    { return $fontsMap; });
        add_filter('pre_option_' . WPS_IC_CF,            function() use ($cf)            { return $cf ?: false; });
        add_filter('pre_option_' . WPS_IC_CF_CNAME,      function() use ($cfCname)       { return $cfCname; });
        add_filter('pre_option_wpc_remote_site_url',     function() use ($remoteSiteUrl) { return $remoteSiteUrl; });
        add_filter('pre_option_wpc_remote_home_url',     function() use ($remoteHomeUrl) { return $remoteHomeUrl; });
        // Inject remote plan version so templates can gate features correctly (e.g. Woohoo footer)
        // Read local options now (before the filter is registered) to avoid recursive pre_option trigger
        $localOpts = get_option(WPS_IC_OPTIONS) ?: [];
        add_filter('pre_option_' . WPS_IC_OPTIONS,    function() use ($localOpts, $planVersion) {
            $opts = $localOpts;
            $opts['version'] = $planVersion;
            return $opts;
        });

        $this->injectRemoteVitals($remoteSettings['vitals'] ?? null);
        $this->injectRemoteAutoMode($remoteSettings['auto_mode'] ?? null);
    }


    public function injectRemoteVitals($vitals)
    {
        $vitals = is_array($vitals) ? $vitals : [];
        $daily    = (isset($vitals['daily']) && is_array($vitals['daily'])) ? $vitals['daily'] : [];
        $sample   = max(1, (int) ($vitals['sample'] ?? 1));
        $enabled  = !empty($vitals['enabled']) ? '1' : '0';


        $baseline = (isset($vitals['baseline']) && is_array($vitals['baseline'])) ? $vitals['baseline'] : [];

        add_filter('pre_option_wpc_vitals_daily',    function() use ($daily)    { return $daily; });
        add_filter('pre_option_wpc_vitals_baseline', function() use ($baseline) { return $baseline; });
        add_filter('pre_option_wpc_vitals_sample',   function() use ($sample)   { return $sample; });
        add_filter('pre_option_wpc_vitals_enabled',  function() use ($enabled)  { return $enabled; });


        add_filter('wpc_vitals_today_partial_pre', '__return_false', 99);


        add_filter('wpc_vitals_sample_preview', '__return_false', 99);

        self::$remoteVitalsHasData = function_exists('wpc_vitals_export_has_data')
            ? (bool) wpc_vitals_export_has_data($vitals)
            : false;
    }


    public function injectRemoteAutoMode($autoMode)
    {
        $autoMode = is_array($autoMode) ? $autoMode : [];
        $on    = !empty($autoMode['on']) ? '1' : '0';
        $state = (isset($autoMode['state']) && is_array($autoMode['state'])) ? $autoMode['state'] : [];

        add_filter('pre_option_wpc_auto_mode',       function() use ($on)    { return $on; });


        add_filter('pre_option_wpc_auto_mode_state', function() use ($state) { return $state; });
    }

}


if (!function_exists('wpc_agency_forward')) {
    function wpc_agency_forward($action, $form = [], $apikey = '')
    {
        if (!defined('WPS_IC_AGENCY') || !WPS_IC_AGENCY) {
            return null;
        }

        if ($apikey === '') {
            $apikey = !empty($_POST['apikey']) ? sanitize_text_field($_POST['apikey']) : '';
        }
        if ($apikey === '') {


            return ['success' => false, 'data' => ['msg' => 'No site selected for this action.']];
        }

        global $api;
        if (empty($api) || !isset($api::$comms) || !method_exists($api::$comms, 'callSiteAction')) {
            return ['success' => false, 'data' => ['msg' => 'Agency comms unavailable — update the agency API plugin.']];
        }

        $call = $api::$comms->callSiteAction($apikey, $action, $form);
        if (!is_array($call)) {
            return ['success' => false, 'data' => ['msg' => 'Could not reach the site (' . (string) $call . ').']];
        }

        return $call;
    }
}


if (!function_exists('wpc_agency_forward_json')) {
    function wpc_agency_forward_json($action, $form = [])
    {
        $fwd = wpc_agency_forward($action, $form);
        if ($fwd === null) {
            return false;
        }
        if (!empty($fwd['success'])) {
            wp_send_json_success($fwd['data'] ?? []);
        }


        $data = $fwd['data'] ?? null;
        if (!is_array($data)) {
            $reasons = [
                'url-not-working'  => "Couldn't reach the site — it may be down or blocking the portal.",
                'ssl-problem'      => 'The site has an SSL certificate problem the portal cannot verify.',
                'malformed-url'    => 'The stored site URL is malformed.',
                'invalid-response' => 'The site returned a response the portal could not read.',
                'site-url-not-found' => 'No site URL is on file for this key.',


                'Function does not exist' => 'This site is on an older WP Compress version that cannot run this remotely — update the plugin there.',
            ];
            $raw  = (string) $data;
            $data = ['msg' => $reasons[$raw] ?? ($raw !== '' ? 'Remote call failed (' . $raw . ').' : 'Remote call failed.')];
        }

        wp_send_json_error($data);
        return true;
    }
}
