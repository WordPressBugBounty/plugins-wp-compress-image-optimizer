<?php

trait wps_ic_agency_trait
{

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
    }

}
