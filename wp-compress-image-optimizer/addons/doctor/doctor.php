<?php
/*
 * THE DOCTOR: one read-only core, compartments per ticket type, thin surfaces over it.
 *
 * From a ticket (a site, optionally a URL) to the cause: every compartment reads what its part of
 * the plugin decided and why, from state the plugin already keeps and receipts it already writes.
 * Nothing is guessed and nothing is written: no option, transient, file, folder, cron event,
 * purge or dispatch, and no network call unless the person running it asks for the probes. A run
 * writes no receipt of its own.
 *
 *   wpc_doctor_run($names, $url, $opts)  the report, redacted once at the end
 *   wpc_doctor_bundle($url, $opts)       every compartment plus the journal window: a ticket bundle
 *   wpc_doctor_redact($value)            the one redactor
 *   wpc_doctor_table_lines($report)      the report as the CLI prints it
 *   wpc_doctor_ajax()                    admin-ajax wpc_doctor (manage_wpc_settings + nonce; forwarded
 *                                        to the client site in the agency portal)
 *   wpc_doctor_comms($form)              the agency relay, comms_action=doctor (wps_ic_comms::doctor)
 *   wpc_doctor_cli($args, $assoc)        wp wpcompress doctor
 *
 * Loaded in the admin (admin-ajax included), on a comms request and by the CLI command; never on
 * a visitor render.
 * The checks that hold every compartment to these rules are tests/suites/t1620.php.
 */
require_once __DIR__ . '/compartment.php';
require_once __DIR__ . '/context.php';
require_once __DIR__ . '/compartments/cache.php';
require_once __DIR__ . '/compartments/crit.php';
require_once __DIR__ . '/compartments/render.php';
require_once __DIR__ . '/compartments/site.php';
require_once __DIR__ . '/compartments/logs.php';

if (!function_exists('wpc_doctor_registry')) {
    /** The report schema; a reader of an older bundle checks it. */
    define('WPC_DOCTOR_SCHEMA', 1);

    /** Every compartment, name => class, in report order. The list is the plugin's: no filter. */
    function wpc_doctor_registry()
    {
        return [
            'site'   => 'wps_ic_doctor_site',
            'cache'  => 'wps_ic_doctor_cache',
            'crit'   => 'wps_ic_doctor_crit',
            'render' => 'wps_ic_doctor_render',
            'logs'   => 'wps_ic_doctor_logs',
        ];
    }

    /**
     * The URL a run is about: '' is the homepage, a path is this site's page, an absolute URL must
     * be on this site's host. Anything else answers '' and the run says so: the probes fetch the
     * URL, and a door that fetched any URL it was handed would be a way to make the site request
     * someone else's.
     */
    function wpc_doctor_url($url)
    {
        $url = trim((string) $url);
        $home = function_exists('home_url') ? (string) home_url('/') : '';
        if ($url === '') {
            return $home;
        }
        if ($url[0] === '/' && (strlen($url) === 1 || $url[1] !== '/')) {
            return rtrim($home, '/') . $url;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $homeHost = strtolower((string) parse_url($home, PHP_URL_HOST));
        return ($host !== '' && $host === $homeHost && in_array($scheme, ['http', 'https'], true)) ? $url : '';
    }

    /**
     * What this site runs: the active plugins, the active theme and the server, as getSettings()
     * answers them to the agency portal (the portal reads these three keys, so their shape is a
     * contract), and as the site compartment and a ticket bundle report them. $withVersions adds
     * each plugin's and the theme's version, which the portal's shape does not carry.
     */
    function wpc_site_facts($withVersions = false)
    {
        if (!function_exists('get_plugins') && defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/plugin.php')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all = function_exists('get_plugins') ? get_plugins() : [];
        $active = get_option('active_plugins', []);
        $plugins = [];
        foreach (is_array($active) ? $active : [] as $path) {
            $slug = explode('/', $path)[0];
            $plugin = ['slug' => $slug, 'name' => $all[$path]['Name'] ?? $slug, 'path' => $path];
            if ($withVersions) {
                $plugin['version'] = (string) ($all[$path]['Version'] ?? '');
            }
            $plugins[] = $plugin;
        }
        $theme = wp_get_theme();
        $activeTheme = [
            'slug' => $theme->get_stylesheet(),
            'name' => $theme->get('Name'),
            'type' => 'theme',
        ];
        if ($withVersions) {
            $activeTheme['version'] = (string) $theme->get('Version');
            $activeTheme['parent'] = $theme->get_template() !== $theme->get_stylesheet() ? (string) $theme->get_template() : '';
        }
        return [
            'active_plugins' => $plugins,
            'active_theme'   => $activeTheme,
            'server_info'    => [
                'php_version'  => phpversion(),
                'wp_version'   => isset($GLOBALS['wp_version']) ? $GLOBALS['wp_version'] : '',
                'max_upload'   => function_exists('size_format') && function_exists('wp_max_upload_size') ? size_format(wp_max_upload_size()) : '',
                'memory_limit' => ini_get('memory_limit'),
            ],
        ];
    }

    /** Whether $event starts with one of $prefixes. */
    function wpc_doctor_event_claimed($event, array $prefixes)
    {
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && strpos((string) $event, (string) $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * The journal split by compartment. An entry belongs to a compartment when its event starts
     * with one of the compartment's prefixes AND it is about this page (its key, or site-wide); an
     * event two compartments claim shows in both. An event no registered compartment claims, for
     * any page, is counted under 'unclaimed' by name, so a prefix table that falls behind shows
     * in every report instead of silently dropping lines; this page's unclaimed entries go to the
     * card that takes them (UNCLAIMED, the logs card).
     */
    function wpc_doctor_group_receipts(wps_ic_doctor_context $ctx, array $registry)
    {
        $claimed = [];
        $unclaimed = [];
        foreach ($ctx->journal as $entry) {
            $event = (string) ($entry['event'] ?? '');
            $mine = $ctx->entry_is_mine($entry);
            $any = false;
            foreach ($registry as $name => $class) {
                if (!wpc_doctor_event_claimed($event, $class::EVENTS)) {
                    continue;
                }
                $any = true;
                if ($mine) {
                    $claimed[$name][] = $entry;
                }
            }
            if (!$any) {
                $unclaimed[$event] = ($unclaimed[$event] ?? 0) + 1;
                foreach ($registry as $name => $class) {
                    if ($mine && $class::UNCLAIMED) {
                        $claimed[$name][] = $entry;
                    }
                }
            }
        }
        arsort($unclaimed);
        return ['claimed' => $claimed, 'unclaimed' => $unclaimed];
    }

    /**
     * Run the named compartments ([] = all) for $url ('' = the homepage). $opts: probe (bool),
     * hours (the journal window, default 24), lines (its line limit), device ('desktop' or
     * 'mobile'), now (the clock), bundle (also return every journal entry of the window, as
     * 'journal'). Returns ['meta', 'compartments', ('journal',) 'unclaimed'], redacted.
     */
    function wpc_doctor_run(array $names = [], $url = '', array $opts = [])
    {
        $started = microtime(true);
        $registry = wpc_doctor_registry();
        $errors = [];
        $wanted = [];
        foreach ($names as $name) {
            $name = strtolower(trim((string) $name));
            if ($name === '') {
                continue;
            }
            if (isset($registry[$name])) {
                $wanted[$name] = true;
            } else {
                $errors[] = 'unknown compartment: ' . preg_replace('/[^a-z0-9_-]/', '', $name);
            }
        }
        $wanted = $wanted ? array_keys($wanted) : ($names ? [] : array_keys($registry));

        $pageUrl = wpc_doctor_url($url);
        if ($pageUrl === '') {
            $errors[] = 'not a URL of this site: the report is for the homepage';
        }
        $ctx = new wps_ic_doctor_context($pageUrl, $opts);
        $grouped = wpc_doctor_group_receipts($ctx, $registry);

        $reports = [];
        foreach ($wanted as $name) {
            $class = $registry[$name];
            $began = microtime(true);
            $report = [
                'title'     => $class::TITLE,
                'symptoms'  => $class::SYMPTOMS,
                'verdict'   => ['level' => 'unknown', 'code' => 'no-verdict', 'line' => ''],
                'facts'     => [],
                'artifacts' => [],
                'receipts'  => [],
                'errors'    => [],
            ];
            $compartment = new $class();
            try {
                $collected = $compartment->collect($ctx);
                foreach (['verdict', 'facts', 'artifacts'] as $part) {
                    if (isset($collected[$part]) && is_array($collected[$part])) {
                        $report[$part] = $collected[$part];
                    }
                }
            } catch (\Throwable $e) {
                $report['errors'][] = get_class($e) . ': ' . $e->getMessage();
            }
            $mine = isset($grouped['claimed'][$name]) ? $grouped['claimed'][$name] : [];
            $report['receipt_count'] = count($mine);
            $report['receipts'] = array_slice($mine, -30);
            if (!empty($opts['probe'])) {
                try {
                    $report['probe'] = $compartment->probe($ctx);
                } catch (\Throwable $e) {
                    $report['errors'][] = 'probe: ' . get_class($e) . ': ' . $e->getMessage();
                }
            }
            $report['ms'] = round((microtime(true) - $began) * 1000, 1);
            $reports[$name] = $report;
        }

        $result = [
            'meta' => [
                'plugin_version' => defined('WPC_PLUGIN_VERSION') ? (string) WPC_PLUGIN_VERSION : '',
                'schema'         => WPC_DOCTOR_SCHEMA,
                'generated_at'   => gmdate('Y-m-d\TH:i:s\Z', $ctx->now),
                'url'            => $ctx->url,
                'key'            => $ctx->key,
                'page_id'        => $ctx->page_id,
                'hours'          => $ctx->hours,
                'probe'          => !empty($opts['probe']),
                'bundle'         => !empty($opts['bundle']),
                'journal'        => $ctx->journal_meta,
                'errors'         => array_merge($errors, $ctx->errors),
                'ms'             => round((microtime(true) - $started) * 1000, 1),
            ],
            'compartments' => $reports,
        ];
        if (!empty($opts['bundle'])) {
            $result['journal'] = $ctx->journal;
        }
        $result = wpc_doctor_redact($result);
        // Event names and counts only; added after the redaction so an event named after what it
        // is about ('keys-call-failed') keeps its count.
        $result['unclaimed'] = $grouped['unclaimed'];
        return $result;
    }

    /**
     * A ticket bundle: every compartment for $url, plus every journal entry of the window (the
     * cards carry only their last 30 each), redacted like any report. Its meta names the build,
     * the URL, the key and what the window actually holds, so the file stands on its own. JSON
     * only: a gzip of it is all a zip would add. $opts as wpc_doctor_run() (hours, lines, device,
     * probe).
     */
    function wpc_doctor_bundle($url = '', array $opts = [])
    {
        return wpc_doctor_run([], $url, ['bundle' => true] + $opts);
    }

    /**
     * Whether an array key names a secret. The generic words match on a word boundary, so 'auth'
     * catches 'x-origin-auth' without 'author'; the glued spellings are listed; inside a
     * Cloudflare node an account email, id or zone is a credential too. 'key' alone, and a
     * url key, are the page's url key everywhere in the plugin and in the log: the join key
     * between a report, the journal and the crit service's logs, so it stays.
     */
    function wpc_doctor_is_secret_key($name, $parent = '')
    {
        $name = strtolower((string) $name);
        $parent = strtolower((string) $parent);
        if ($name === '' || $name === 'key' || preg_match('/(^|_)url_?key$/', $name)) {
            return false;
        }
        if (preg_match('/(^|[^a-z])(key|token|tokens|secret|secrets|auth|password|passwd|pwd|credential|credentials|signature|sig)([^a-z]|$)/', $name)) {
            return true;
        }
        foreach (['apikey', 'devkey', 'privatekey', 'accesskey', 'secretkey', 'authkey', 'nonce', 'license', 'cookie_hash'] as $needle) {
            if (strpos($name, $needle) !== false) {
                return true;
            }
        }
        $cloudflare = ($parent === 'cf' || strpos($parent, 'cloudflare') !== false || strpos($parent, 'cf_') === 0);
        return $cloudflare && preg_match('/(email|id|zone|account)/', $name) === 1;
    }

    /**
     * The secret values this site holds, as literal strings to strike from any report: every
     * secret-named value in the plugin's options row and its Cloudflare row (the API key, the
     * response key, the CF token and the bypass token among them), the callback secret and the
     * diagnostic token (read, never minted). Shorter than eight characters is not a secret worth
     * matching and would strike ordinary words.
     */
    function wpc_doctor_secret_values()
    {
        $values = [];
        $walk = function ($node, $name, $parent) use (&$walk, &$values) {
            if (is_array($node)) {
                foreach ($node as $childName => $child) {
                    $walk($child, is_int($childName) ? $name : (string) $childName, (string) $name);
                }
                return;
            }
            if (is_scalar($node) && strlen((string) $node) >= 8 && wpc_doctor_is_secret_key($name, $parent)) {
                $values[(string) $node] = true;
            }
        };
        foreach ([defined('WPS_IC_OPTIONS') ? WPS_IC_OPTIONS : 'wps_ic', defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf'] as $option) {
            $walk(get_option($option), '', '');
        }
        $direct = [
            (string) get_option('wpc_cb_secret650', ''),
            function_exists('wpc_perf_debug_token') ? (string) wpc_perf_debug_token(false) : (string) get_option('wpc_perf_debug_token', ''),
        ];
        foreach ($direct as $value) {
            if (strlen($value) >= 8) {
                $values[$value] = true;
            }
        }
        $values = array_keys($values);
        // Longest first, so a secret that contains another is struck whole.
        usort($values, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        return $values;
    }

    /**
     * The one redactor, applied once to a whole report by wpc_doctor_run(), never by a
     * compartment: secret-named keys become "[redacted]" whatever their value; every known
     * secret value is struck from every string; e-mail addresses keep their first letter and
     * domain; then the plugin log's own mask (API keys in URLs, IPv4 addresses) runs over all
     * of it. Crit uuids and url keys stay: they are the join keys.
     */
    function wpc_doctor_redact($value, $name = '', $parent = '', $secrets = null)
    {
        $top = $secrets === null;
        if ($top) {
            $secrets = wpc_doctor_secret_values();
        }
        // A count or a flag under a secret-sounding name (preload-set's dropped.same-key) is not
        // a secret, and striking it hid the number support reads.
        if ($name !== '' && !is_int($name) && !is_int($value) && !is_float($value) && !is_bool($value) && wpc_doctor_is_secret_key($name, $parent)) {
            $value = '[redacted]';
        } elseif (is_array($value)) {
            foreach ($value as $childName => $child) {
                $value[$childName] = wpc_doctor_redact($child, is_int($childName) ? '' : $childName, is_int($name) ? '' : (string) $name, $secrets);
            }
        } elseif (is_string($value) && $value !== '') {
            foreach ($secrets as $secret) {
                if (strpos($value, $secret) !== false) {
                    $value = str_replace($secret, '[redacted]', $value);
                }
            }
            if (strpos($value, '@') !== false && preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $value, $found)) {
                foreach (array_unique($found[0]) as $email) {
                    // A receipt's source reads "function@file.php" (purge-local-all's src): a
                    // code file, not an address; masking it hid which code purged.
                    if (preg_match('/\.(php|js|css|json|html?|txt|log)$/i', $email)) {
                        continue;
                    }
                    $masked = class_exists('wps_ic_plan') ? wps_ic_plan::mask_email($email) : '[email]';
                    $value = str_replace($email, $masked, $value);
                }
            }
        }
        if ($top && function_exists('wpc_cflog_mask')) {
            $value = wpc_cflog_mask($value);
        }
        return $value;
    }

    /** A value as one table cell: scalars as they are, arrays as compact JSON. */
    function wpc_doctor_cell($value)
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if ($value === null) {
            return '-';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return is_string($json) ? $json : '?';
    }

    /**
     * The report as the CLI table prints it: per compartment its verdict, then each fact as
     * "label: value (source)", then its receipts as "HH:MM:SSZ event key {layers}".
     */
    function wpc_doctor_table_lines(array $report)
    {
        $meta = isset($report['meta']) && is_array($report['meta']) ? $report['meta'] : [];
        $journal = isset($meta['journal']) && is_array($meta['journal']) ? $meta['journal'] : [];
        $lines = [];
        $lines[] = sprintf('WP Compress %s doctor  url=%s  key=%s  page=%s  %s', $meta['plugin_version'] ?? '', $meta['url'] ?? '', $meta['key'] ?? '', wpc_doctor_cell($meta['page_id'] ?? ''), $meta['generated_at'] ?? '');
        $lines[] = sprintf('journal: %d lines (limit %d), %s .. %s%s', (int) ($journal['lines'] ?? 0), (int) ($journal['limit'] ?? 0),
            !empty($journal['first']) ? gmdate('Y-m-d H:i:s\Z', (int) $journal['first']) : '-',
            !empty($journal['last']) ? gmdate('Y-m-d H:i:s\Z', (int) $journal['last']) : '-',
            !empty($meta['probe']) ? '  (with probes)' : '');
        foreach ((array) ($meta['errors'] ?? []) as $error) {
            $lines[] = 'error: ' . $error;
        }
        foreach ((array) ($report['compartments'] ?? []) as $name => $card) {
            $verdict = isset($card['verdict']) && is_array($card['verdict']) ? $card['verdict'] : [];
            $lines[] = '';
            $lines[] = sprintf('[%s] %s %s  (%s, %sms)', $name, strtoupper((string) ($verdict['level'] ?? 'unknown')), (string) ($verdict['line'] ?? ''), (string) ($verdict['code'] ?? ''), (string) ($card['ms'] ?? ''));
            foreach ((array) ($card['errors'] ?? []) as $error) {
                $lines[] = '  error: ' . $error;
            }
            foreach ((array) ($card['facts'] ?? []) as $fact) {
                $lines[] = sprintf('  %s: %s  (%s)', (string) ($fact['label'] ?? ''), wpc_doctor_cell($fact['value'] ?? null), (string) ($fact['source'] ?? ''));
            }
            if (!empty($card['artifacts'])) {
                $lines[] = '  artifacts:';
                foreach ((array) $card['artifacts'] as $artifact) {
                    $lines[] = sprintf('    %s  %d B  %s', (string) ($artifact['path'] ?? ''), (int) ($artifact['bytes'] ?? 0), !empty($artifact['mtime']) ? gmdate('Y-m-d H:i:s\Z', (int) $artifact['mtime']) : '-');
                }
            }
            if (isset($card['probe'])) {
                $lines[] = '  probe: ' . wpc_doctor_cell($card['probe']);
            }
            $lines[] = sprintf('  receipts: %d in the window, last %d:', (int) ($card['receipt_count'] ?? 0), count((array) ($card['receipts'] ?? [])));
            foreach ((array) ($card['receipts'] ?? []) as $entry) {
                $lines[] = sprintf('    %s %s %s %s', !empty($entry['t']) ? gmdate('H:i:s\Z', (int) $entry['t']) : '-', (string) ($entry['event'] ?? ''), (string) ($entry['key'] ?? ''), wpc_doctor_cell($entry['layers'] ?? []));
            }
        }
        if (!empty($report['unclaimed'])) {
            $lines[] = '';
            $lines[] = 'unclaimed events in the window (no compartment claims them): ' . wpc_doctor_cell($report['unclaimed']);
        }
        return $lines;
    }

    /**
     * One request to the doctor from any door, as [names, url, opts]: compartments (a list, or a
     * comma-separated string; empty = all), url, probe, bundle, hours, device. A bundle over the
     * agency relay ($relay) carries at most 5000 journal entries: the answer travels back through
     * the portal's GET.
     */
    function wpc_doctor_request(array $input, $relay = false)
    {
        $names = isset($input['compartments']) ? $input['compartments'] : [];
        $names = is_array($names) ? $names : explode(',', (string) $names);
        $opts = [
            'probe'  => !empty($input['probe']),
            'bundle' => !empty($input['bundle']),
            'hours'  => isset($input['hours']) ? (int) $input['hours'] : 24,
        ];
        if (isset($input['device']) && (string) $input['device'] !== '') {
            $opts['device'] = (string) $input['device'];
        }
        if ($opts['bundle'] && $relay) {
            $opts['lines'] = 5000;
        }
        return [$opts['bundle'] ? [] : array_map('strval', $names), isset($input['url']) ? (string) $input['url'] : '', $opts];
    }

    /** The report or the bundle a request asks for. */
    function wpc_doctor_answer(array $input, $relay = false)
    {
        list($names, $url, $opts) = wpc_doctor_request($input, $relay);
        return $opts['bundle'] ? wpc_doctor_bundle($url, $opts) : wpc_doctor_run($names, $url, $opts);
    }

    /**
     * admin-ajax wpc_doctor: compartments=crit,cache&url=...&probe=0&bundle=0&hours=24&device=
     * &wps_ic_nonce=... (GET or POST). The capability and nonce check is wpc_cflog_tail's. The
     * answer is the report (or the bundle) as JSON. In the agency portal the report is the client
     * site's: the request is forwarded to it as comms_action=doctor through the portal's generic
     * relay, for the site the page was opened for (the POSTed apikey); a site on an older plugin
     * answers "update the plugin there".
     */
    function wpc_doctor_ajax()
    {
        $nonce = isset($_REQUEST['wps_ic_nonce']) ? (string) $_REQUEST['wps_ic_nonce'] : '';
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($nonce, 'wps_ic_nonce_action')) {
            wp_send_json_error('forbidden', 403);
        }
        $input = [];
        foreach (['compartments', 'url', 'probe', 'bundle', 'hours', 'device'] as $field) {
            if (isset($_REQUEST[$field])) {
                $input[$field] = (string) wp_unslash($_REQUEST[$field]);
            }
        }
        nocache_headers();
        if (function_exists('wpc_agency_forward_json')) {
            list($names, $url, $opts) = wpc_doctor_request($input);
            wpc_agency_forward_json('doctor', [
                'compartments' => $names,
                'url'          => $url,
                'probe'        => $opts['probe'] ? 1 : 0,
                'bundle'       => $opts['bundle'] ? 1 : 0,
                'hours'        => $opts['hours'],
                'device'       => $opts['device'] ?? '',
            ]);
        }
        wp_send_json_success(wpc_doctor_answer($input));
    }
    if (function_exists('add_action')) {
        add_action('wp_ajax_wpc_doctor', 'wpc_doctor_ajax');
    }

    /**
     * The body of comms_action=doctor (wps_ic_comms::doctor): the agency portal's relay, behind
     * start_comms()'s key check. $form is the JSON form the portal's callSiteAction() sends.
     */
    function wpc_doctor_comms($form)
    {
        return wpc_doctor_answer(is_array($form) ? $form : [], true);
    }

    /** The body of `wp wpcompress doctor`; the command in wp-compress-cli.php only loads this file. */
    function wpc_doctor_cli(array $args, array $assoc)
    {
        $input = ['compartments' => $args, 'url' => isset($assoc['url']) ? (string) $assoc['url'] : ''];
        foreach (['probe', 'bundle', 'hours', 'device'] as $field) {
            if (isset($assoc[$field])) {
                $input[$field] = $assoc[$field];
            }
        }
        $report = wpc_doctor_answer($input);
        if (!empty($input['bundle']) || (isset($assoc['format']) && $assoc['format'] === 'json')) {
            WP_CLI::line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
            return;
        }
        foreach (wpc_doctor_table_lines($report) as $line) {
            WP_CLI::line($line);
        }
    }
}
