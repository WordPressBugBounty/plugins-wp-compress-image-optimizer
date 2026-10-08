<?php

/**
 * The plan the site is on, what it allows, and how a keyless Lite site joins an account.
 *
 * One owner for three facts the rest of the plugin used to derive on its own: the stored version
 * (lite | free | pro), the feature allow-list the service sent with it, and the claim in flight.
 * Lanes ask allows('crit') or is_lite(); the settings page asks features(); the connect popup, the
 * locked toggles and the state strip all go through claim(), status() and the two admin request
 * handlers. The service decides what a plan includes; this class only stores and applies what it
 * is told (apply_payload), and asks the keys endpoint when the user types an email.
 */
class wps_ic_plan
{
    const FEATURES_OPTION = 'wpc_plan_features';
    const PLAN_OPTION = 'wpc_plan';
    const REQUEST_OPTION = 'wpc_claim_request';
    const FEATURE_KEYS = ['images', 'cdn', 'crit', 'css', 'js', 'fonts', 'smart_delivery', 'on_upload'];

    /** The stored version: lite, free, pro, or '' when the site never connected. */
    public static function version()
    {
        if (!defined('WPS_IC_OPTIONS')) {
            return '';
        }
        $o = get_option(WPS_IC_OPTIONS);
        return (is_array($o) && isset($o['version'])) ? (string) $o['version'] : '';
    }

    public static function is_lite()
    {
        return self::version() === 'lite';
    }

    /**
     * The feature allow-list. What the service sent, or the plan's own default: a Lite site allows
     * nothing that costs the service, an unknown or pro site allows everything (never lock a paying
     * customer out on a missing row).
     */
    public static function features()
    {
        $f = get_option(self::FEATURES_OPTION, null);
        if (is_array($f) && !empty($f)) {
            $out = [];
            foreach (self::FEATURE_KEYS as $k) {
                $out[$k] = !empty($f[$k]) ? 1 : 0;
            }
            return $out;
        }
        $all = self::is_lite() ? 0 : 1;
        return array_fill_keys(self::FEATURE_KEYS, $all);
    }

    public static function allows($feature)
    {
        $f = self::features();
        return !isset($f[$feature]) || !empty($f[$feature]);
    }

    /** The setting keys a feature owns: switched off when the plan stops allowing it, taken from the preset when it starts. */
    public static function feature_settings($feature)
    {
        $map = [
            'images'         => ['imagesPreset', 'generate_webp', 'generate_adaptive', 'picture_webp', 'picture_avif', 'retina', 'live-cdn', ['serve', 'jpg'], ['serve', 'png'], ['serve', 'gif'], ['serve', 'svg']],
            'cdn'            => ['cdnAll', ['serve', 'css'], ['serve', 'js'], ['serve', 'fonts']],
            'crit'           => [['critical', 'css']],
            'css'            => ['css'],
            'js'             => ['js', 'js_combine', 'js_minify', 'js_defer', 'delay-js', 'delay-js-v2'],
            'fonts'          => ['fonts', 'font-subsetting'],
            'smart_delivery' => [],
            'on_upload'      => ['on-upload'],
        ];
        return isset($map[$feature]) ? $map[$feature] : [];
    }

    /**
     * Stores a plan payload from the keys endpoint and makes the settings row agree with it: every
     * feature the plan newly allows takes its keys from the plan's preset, every feature it does not
     * allow is switched off, and everything else stays as the user left it. $prev is the allow-list the
     * site had before the caller touched the stored version; without it the stored list is read.
     * Returns the stored version or false when the payload has no usable version.
     */
    public static function apply_payload($data, $source = 'claim', $prev = null)
    {
        $data = self::to_array($data);
        $version = isset($data['version']) ? strtolower((string) $data['version']) : '';
        if (!in_array($version, ['lite', 'free', 'pro'], true) || !defined('WPS_IC_OPTIONS') || !defined('WPS_IC_SETTINGS')) {
            return false;
        }
        $prev_version = self::version();
        $prev = is_array($prev) ? $prev : self::features();

        $o = get_option(WPS_IC_OPTIONS);
        $o = is_array($o) ? $o : [];
        $o['version'] = $version;
        update_option(WPS_IC_OPTIONS, $o);

        $features = [];
        foreach (self::FEATURE_KEYS as $k) {
            $features[$k] = (isset($data['features']) && is_array($data['features']) && !empty($data['features'][$k])) ? 1 : 0;
        }
        if (!isset($data['features']) || !is_array($data['features'])) {
            $features = array_fill_keys(self::FEATURE_KEYS, $version === 'lite' ? 0 : 1);
        }
        update_option(self::FEATURES_OPTION, $features, true);
        update_option(self::PLAN_OPTION, [
            'plan'          => isset($data['plan']) ? sanitize_key((string) $data['plan']) : $version,
            'checkout_url'  => isset($data['checkout_url']) ? esc_url_raw((string) $data['checkout_url']) : '',
            'dashboard_url' => isset($data['dashboard_url']) ? esc_url_raw((string) $data['dashboard_url']) : '',
            'account_state' => isset($data['account_state']) ? sanitize_key((string) $data['account_state']) : '',
            'applied'       => time(),
            'source'        => sanitize_key((string) $source),
        ], false);

        $preset_name = $version === 'lite' ? 'lite' : ($version === 'pro' ? 'aggressive' : 'recommended');
        $preset = [];
        if (class_exists('wps_ic_options')) {
            $options = new wps_ic_options();
            $preset = $options->get_preset($preset_name);
            $preset = is_array($preset) ? $preset : [];
            if ($preset && function_exists('wpc_drop_preset_advanced_cache_if_foreign')) {
                $preset = wpc_drop_preset_advanced_cache_if_foreign($preset);
            }
        }

        $settings = get_option(WPS_IC_SETTINGS);
        $settings = is_array($settings) ? $settings : [];
        $gained = [];
        foreach ($features as $k => $on) {
            if ($on && empty($prev[$k])) {
                $gained[] = $k;
                foreach (self::feature_settings($k) as $key) {
                    if (is_array($key)) {
                        if (isset($preset[$key[0]][$key[1]])) {
                            if (!isset($settings[$key[0]]) || !is_array($settings[$key[0]])) {
                                $settings[$key[0]] = [];
                            }
                            $settings[$key[0]][$key[1]] = $preset[$key[0]][$key[1]];
                        }
                    } elseif (array_key_exists($key, $preset)) {
                        $settings[$key] = $preset[$key];
                    }
                }
                continue;
            }
            if ($on) {
                continue;
            }
            foreach (self::feature_settings($k) as $key) {
                if (is_array($key)) {
                    if (isset($settings[$key[0]]) && is_array($settings[$key[0]])) {
                        $settings[$key[0]][$key[1]] = '0';
                    }
                } else {
                    $settings[$key] = '0';
                }
            }
            if ($k === 'smart_delivery' && isset($settings['wpc_optimization_mode']) && $settings['wpc_optimization_mode'] === 'lazy_cdn') {
                $settings['wpc_optimization_mode'] = 'legacy';
            }
        }
        update_option(WPS_IC_SETTINGS, $settings);
        if (defined('WPS_IC_PRESET') && ($gained || $version !== $prev_version)) {
            update_option(WPS_IC_PRESET, $preset_name);
        }
        if (class_exists('wps_ic_cache_integrations')) {
            wps_ic_cache_integrations::purgeAll();
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('plan-applied', '', '', ['version' => $version, 'source' => $source, 'features' => implode(',', array_keys(array_filter($features))), 'gained' => implode(',', $gained)]);
        }
        return $version;
    }

    /**
     * The claim: an email typed into the one field. Makes sure the site has a Lite key, asks the
     * keys endpoint to attach the email, and either applies the plan (new address) or records the
     * pending link and tells the strip to say "check your inbox" (known address).
     * Returns ['state' => linked|pending|error, 'code' => ..., 'msg' => ...].
     */
    public static function claim($email)
    {
        $email = sanitize_email((string) $email);
        if ($email === '' || !is_email($email)) {
            return ['state' => 'error', 'code' => 'invalid-email', 'msg' => __('Enter a valid email address.', 'wp-compress-image-optimizer')];
        }
        $apikey = self::lite_apikey(true);
        if ($apikey === '') {
            return ['state' => 'error', 'code' => 'api-issue', 'msg' => __('We could not reach the optimization service. Try again in a moment.', 'wp-compress-image-optimizer')];
        }
        $r = self::keys_post('liteClaim', [
            'apikey'         => $apikey,
            'email'          => $email,
            'domain'         => site_url(),
            'plugin_version' => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '',
            'wp_locale'      => function_exists('get_locale') ? get_locale() : '',
        ]);
        if (empty($r['ok'])) {
            return self::error_from($r);
        }
        $d = $r['data'];
        $state = isset($d['state']) ? sanitize_key((string) $d['state']) : '';
        if ($state === 'linked') {
            return self::land($d, 'claim');
        }
        if ($state === 'pending') {
            update_option(self::REQUEST_OPTION, [
                'id'        => isset($d['request_id']) ? sanitize_text_field((string) $d['request_id']) : '',
                'email'     => self::mask_email($email),
                'started'   => time(),
                'next_poll' => time() + max(10, (int) (isset($d['poll_after']) ? $d['poll_after'] : 20)),
                'attempts'  => 0,
            ], false);
            self::strip('pending', $email);
            self::receipt('lite-claim-sent', ['state' => 'pending', 'request' => isset($d['request_id']) ? (string) $d['request_id'] : '']);
            return ['state' => 'pending', 'code' => 'pending', 'msg' => sprintf(__('Check %s for the link we sent.', 'wp-compress-image-optimizer'), self::mask_email($email))];
        }
        return ['state' => 'error', 'code' => 'api-issue', 'msg' => __('We could not reach the optimization service. Try again in a moment.', 'wp-compress-image-optimizer')];
    }

    /** Asks the keys endpoint whether the pending link landed. */
    public static function status()
    {
        $req = get_option(self::REQUEST_OPTION, null);
        if (!is_array($req) || empty($req['id'])) {
            return ['state' => 'none'];
        }
        $r = self::keys_post('claimStatus', [
            'apikey'     => self::lite_apikey(false),
            'request_id' => (string) $req['id'],
            'domain'     => site_url(),
        ]);
        $req['attempts'] = (int) $req['attempts'] + 1;
        if (empty($r['ok'])) {
            $req['next_poll'] = time() + self::backoff($req['attempts']);
            update_option(self::REQUEST_OPTION, $req, false);
            return self::error_from($r);
        }
        $d = $r['data'];
        $state = isset($d['state']) ? sanitize_key((string) $d['state']) : '';
        if ($state === 'linked') {
            delete_option(self::REQUEST_OPTION);
            return self::land($d, 'magic-link');
        }
        if ($state === 'expired' || $state === 'declined') {
            delete_option(self::REQUEST_OPTION);
            self::strip($state, $req['email']);
            self::receipt('lite-claim-' . $state, []);
            return ['state' => $state, 'code' => $state, 'msg' => $state === 'expired'
                ? __('That link expired. Send a new one.', 'wp-compress-image-optimizer')
                : __('That request was declined.', 'wp-compress-image-optimizer')];
        }
        if (time() - (int) $req['started'] > DAY_IN_SECONDS) {
            delete_option(self::REQUEST_OPTION);
            self::strip('expired', $req['email']);
            return ['state' => 'expired', 'code' => 'expired', 'msg' => __('That link expired. Send a new one.', 'wp-compress-image-optimizer')];
        }
        $req['next_poll'] = time() + max(10, (int) (isset($d['poll_after']) ? $d['poll_after'] : self::backoff($req['attempts'])));
        update_option(self::REQUEST_OPTION, $req, false);
        return ['state' => 'pending', 'code' => 'pending', 'msg' => sprintf(__('Check %s for the link we sent.', 'wp-compress-image-optimizer'), (string) $req['email'])];
    }

    /** The pending request as the settings page needs it, or null. */
    public static function pending()
    {
        $req = get_option(self::REQUEST_OPTION, null);
        return (is_array($req) && !empty($req['id'])) ? ['email' => (string) $req['email'], 'started' => (int) $req['started']] : null;
    }

    /** admin_init: a due poll runs after the response is sent, never inside it. */
    public static function poll_if_due()
    {
        $req = get_option(self::REQUEST_OPTION, null);
        if (!is_array($req) || empty($req['id']) || time() < (int) $req['next_poll']) {
            return;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return;
        }
        add_action('shutdown', function () {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            wps_ic_plan::status();
        }, 99);
    }

    /**
     * admin_init: the two links that land in wp-admin. ?wpc_claim=… is only a hint to poll now;
     * ?wpc_connect=<token> is exchanged through the keyed connect. Both need the settings capability
     * and both redirect to the clean settings URL.
     */
    public static function admin_requests()
    {
        if (!current_user_can('manage_wpc_settings')) {
            return;
        }
        if (isset($_GET['wpc_claim'])) {
            self::status();
            self::redirect_clean();
        }
        if (isset($_GET['wpc_connect'])) {
            $token = sanitize_text_field(wp_unslash((string) $_GET['wpc_connect']));
            if ($token !== '' && class_exists('wps_ic_connect')) {
                $connect = new wps_ic_connect();
                $res = $connect->connectWithKey($token);
                if (is_array($res) && !empty($res['success'])) {
                    self::receipt('connect-token-exchanged', ['reused_key' => (int) (self::lite_apikey(false) !== '')]);
                    self::strip('linked', '');
                } else {
                    $code = is_array($res) && !empty($res['code']) ? (string) $res['code'] : 'token-used';
                    $said = is_array($res) && isset($res['data']) ? self::to_array($res['data']) : [];
                    if ($code === 'not-successful' && !empty($said['code'])) {
                        $code = (string) $said['code'];
                    }
                    self::strip('token-' . sanitize_key($code), '');
                    self::receipt('connect-token-refused', ['code' => $code]);
                }
            }
            self::redirect_clean();
        }
    }

    // ---- internals ----

    /** Stores the payload, finishes provisioning through the keyed connect when the plan is not Lite. */
    private static function land($d, $source)
    {
        $version = self::apply_payload($d, $source);
        if ($version === false) {
            return ['state' => 'error', 'code' => 'api-issue', 'msg' => __('The optimization service answered without a plan. Try again in a moment.', 'wp-compress-image-optimizer')];
        }
        if ($version !== 'lite' && class_exists('wps_ic_connect')) {
            $connect = new wps_ic_connect();
            $res = $connect->connectWithKey(self::lite_apikey(false));
            if (is_array($res) && !empty($res['success'])) {
                self::apply_payload(isset($res['data']) ? $res['data'] : $d, $source);
            }
        }
        self::strip('linked', '');
        self::receipt('lite-claim-linked', ['plan' => $version, 'source' => $source, 'features' => implode(',', array_keys(array_filter(self::features())))]);
        return ['state' => 'linked', 'code' => 'linked', 'version' => $version, 'features' => self::features(),
            'msg' => $version === 'lite'
                ? __('You are on the Lite plan.', 'wp-compress-image-optimizer')
                : sprintf(__('Your site is on the %s plan.', 'wp-compress-image-optimizer'), ucfirst($version))];
    }

    /** The site's Lite key; with $create, a keyless site connects Lite first. */
    private static function lite_apikey($create)
    {
        $o = defined('WPS_IC_OPTIONS') ? get_option(WPS_IC_OPTIONS) : [];
        $key = (is_array($o) && !empty($o['api_key'])) ? (string) $o['api_key'] : '';
        if ($key !== '' || !$create || !class_exists('wps_ic_connect')) {
            return $key;
        }
        $connect = new wps_ic_connect();
        $r = $connect->connectLite(true);
        if (is_array($r) && !empty($r['connected'])) {
            $o = get_option(WPS_IC_OPTIONS);
            return (is_array($o) && !empty($o['api_key'])) ? (string) $o['api_key'] : '';
        }
        return '';
    }

    private static function keys_post($action, $body)
    {
        if (!defined('WPS_IC_KEYSURL')) {
            return ['ok' => false, 'code' => 'api-issue'];
        }
        $url = add_query_arg(['action' => $action], WPS_IC_KEYSURL);
        $call = wp_remote_post($url, [
            'timeout'    => 20,
            'headers'    => ['Content-Type' => 'application/json', 'User-Agent' => defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : 'WP Compress'],
            'body'       => wp_json_encode($body),
        ]);
        if (is_wp_error($call)) {
            return ['ok' => false, 'code' => 'api-issue', 'msg' => $call->get_error_message()];
        }
        $http = (int) wp_remote_retrieve_response_code($call);
        $json = json_decode((string) wp_remote_retrieve_body($call), true);
        if ($http === 429) {
            $retry = (int) wp_remote_retrieve_header($call, 'retry-after');
            return ['ok' => false, 'code' => 'rate-limited', 'retry' => $retry > 0 ? $retry : 300];
        }
        if (!is_array($json)) {
            return ['ok' => false, 'code' => 'api-issue', 'http' => $http];
        }
        if (empty($json['success'])) {
            $data = isset($json['data']) ? self::to_array($json['data']) : [];
            return ['ok' => false, 'code' => isset($data['code']) ? sanitize_key((string) $data['code']) : 'api-issue', 'data' => $data];
        }
        return ['ok' => true, 'data' => isset($json['data']) ? self::to_array($json['data']) : []];
    }

    private static function error_from($r)
    {
        $code = isset($r['code']) ? (string) $r['code'] : 'api-issue';
        $msgs = [
            'invalid-email'  => __('Enter a valid email address.', 'wp-compress-image-optimizer'),
            'domain-bound'   => __('This site is already on another account. Ask that owner, or use a key from your dashboard.', 'wp-compress-image-optimizer'),
            'token-used'     => __('This link was already used. Get a new one from Add site.', 'wp-compress-image-optimizer'),
            'token-expired'  => __('This link expired. Links last 15 minutes. Get a new one from Add site.', 'wp-compress-image-optimizer'),
            'rate-limited'   => __('Try again in a few minutes.', 'wp-compress-image-optimizer'),
            'api-issue'      => __('We could not reach the optimization service. Try again in a moment.', 'wp-compress-image-optimizer'),
        ];
        if ($code === 'domain-bound' && !empty($r['data']['owner'])) {
            $msgs['domain-bound'] = sprintf(__('This site is already on an account (%s). Ask that owner, or use a key from your dashboard.', 'wp-compress-image-optimizer'), sanitize_text_field((string) $r['data']['owner']));
        }
        self::receipt('lite-claim-error', ['code' => $code]);
        return ['state' => 'error', 'code' => $code, 'msg' => isset($msgs[$code]) ? $msgs[$code] : $msgs['api-issue']];
    }

    private static function strip($state, $email)
    {
        if (!function_exists('wpc_set_state_notice')) {
            return;
        }
        $page = self::settings_url();
        switch ($state) {
            case 'pending':
                wpc_set_state_notice('claim', 'info', sprintf(__('Check %s for the link we sent to finish linking this site.', 'wp-compress-image-optimizer'), self::mask_email($email)), '#wpc-claim-resend', __('Send again', 'wp-compress-image-optimizer'));
                break;
            case 'linked':
                if (function_exists('wpc_clear_state_notice')) {
                    wpc_clear_state_notice('claim');
                }
                break;
            case 'expired':
                wpc_set_state_notice('claim', 'warning', __('The link to finish linking this site expired.', 'wp-compress-image-optimizer'), '#wpc-claim-resend', __('Send a new one', 'wp-compress-image-optimizer'));
                break;
            case 'declined':
                wpc_set_state_notice('claim', 'warning', __('The request to link this site was declined.', 'wp-compress-image-optimizer'), '', '');
                break;
            default:
                wpc_set_state_notice('claim', 'warning', __('This link was already used or has expired. Get a new one from Add site in your dashboard.', 'wp-compress-image-optimizer'), $page, __('Try again', 'wp-compress-image-optimizer'));
        }
    }

    public static function mask_email($email)
    {
        $email = (string) $email;
        $at = strpos($email, '@');
        if ($at === false || strpos($email, '*') !== false) {
            return $email;
        }
        return substr($email, 0, 1) . '***' . substr($email, $at);
    }

    private static function backoff($attempts)
    {
        $steps = [20, 40, 80];
        return $attempts < count($steps) ? $steps[$attempts] : 300;
    }

    private static function to_array($v)
    {
        if (is_object($v)) {
            $v = json_decode(wp_json_encode($v), true);
        }
        return is_array($v) ? $v : [];
    }

    private static function receipt($event, $fields)
    {
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log($event, '', '', $fields);
        }
    }

    private static function settings_url()
    {
        return function_exists('wpc_settings_page_url') ? wpc_settings_page_url() : admin_url('admin.php?page=wpcompress');
    }

    private static function redirect_clean()
    {
        wp_safe_redirect(self::settings_url());
        exit;
    }
}
