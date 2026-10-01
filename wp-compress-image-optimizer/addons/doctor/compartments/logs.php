<?php
/*
 * The logs compartment: "something threw", and the catch-all for receipts no compartment claims.
 *
 * The journal itself (the cache-first log): its file, what the window actually holds (a busy site
 * rotates hours, a quiet one keeps weeks, so "no receipt" means nothing before the first entry),
 * how many entries each compartment claims over the whole site, and the events none claims. Then
 * the three option logs no screen reads: PHP warnings raised in plugin files
 * (wpc_error_debug_log), the diagnostic log and whether it is armed (wpc_diagnostic_log,
 * wpc_diag_until), the purge debug log (wpc_purge_debug_log); and the old text log files under
 * cache/logs/ when that log is switched on. Its receipts are this page's unclaimed entries.
 */
if (!class_exists('wps_ic_doctor_logs')) {
    class wps_ic_doctor_logs extends wps_ic_doctor_compartment
    {
        const NAME = 'logs';
        const TITLE = 'Logs';
        const SYMPTOMS = 'something threw; a receipt no other card explains';
        const EVENTS = [];
        /** The runner hands this card the page's entries no compartment claims. */
        const UNCLAIMED = true;

        /** How many lines of each old text log file the report carries. */
        const TEXT_LOG_TAIL = 20;

        public function collect(wps_ic_doctor_context $ctx)
        {
            $facts = [];
            $now = $ctx->now;
            $meta = $ctx->journal_meta;
            $file = function_exists('wpc_cflog_path') ? wpc_cflog_path() : '';
            $state = self::file_state($file, $now);
            $facts[] = self::fact('journal file', $state === null ? 'absent' : ['path' => $state['path'], 'bytes' => $state['bytes'], 'mtime' => self::at($state['mtime'])], 'fn:wpc_cflog_path');
            $truncated = (int) ($meta['lines'] ?? 0) >= (int) ($meta['limit'] ?? 0);
            $facts[] = self::fact('journal window', [
                'asked'     => $ctx->hours . ' h, since ' . self::at((int) ($meta['since'] ?? 0)),
                'holds'     => (int) ($meta['lines'] ?? 0) . ' entries',
                'first'     => self::at((int) ($meta['first'] ?? 0)),
                'last'      => self::at((int) ($meta['last'] ?? 0)),
                'truncated' => $truncated,
            ], 'fn:wpc_cflog_lines');

            // Entries per compartment over the whole site, and the ones none claims.
            $registry = function_exists('wpc_doctor_registry') ? wpc_doctor_registry() : [];
            $perCompartment = [];
            $unclaimed = 0;
            foreach ($ctx->journal as $entry) {
                $event = (string) ($entry['event'] ?? '');
                $any = false;
                foreach ($registry as $name => $class) {
                    if (function_exists('wpc_doctor_event_claimed') && wpc_doctor_event_claimed($event, $class::EVENTS)) {
                        $perCompartment[$name] = ($perCompartment[$name] ?? 0) + 1;
                        $any = true;
                    }
                }
                if (!$any) {
                    $unclaimed++;
                }
            }
            $perCompartment['unclaimed'] = $unclaimed;
            $facts[] = self::fact('entries per compartment (every page)', $perCompartment, 'receipt: grouped by the compartments\' event prefixes');

            // The option logs.
            $errors = self::option_lines('wpc_error_debug_log', 50);
            $recentErrors = self::lines_since($errors, (int) ($meta['since'] ?? 0));
            $facts[] = self::fact('PHP warnings in plugin files', $errors === [] ? 'none recorded' : ['in_window' => count($recentErrors), 'lines' => $errors], 'option:wpc_error_debug_log (last 50)');
            $diagUntil = (int) get_option('wpc_diag_until', 0);
            $facts[] = self::fact('diagnostic log', [
                'armed_until' => $diagUntil >= $now ? self::at($diagUntil) : 'not armed',
                'lines'       => self::option_lines('wpc_diagnostic_log', 100),
            ], 'option:wpc_diag_until, wpc_diagnostic_log (last 100)');
            $facts[] = self::fact('purge debug log', self::option_lines('wpc_purge_debug_log', 20), 'option:wpc_purge_debug_log (last 20)');

            // The old text log.
            $textOn = (string) get_option('wps_ic_debug_log', '') === 'true';
            $files = [];
            if (defined('WPS_IC_LOG')) {
                foreach (self::list_dir(WPS_IC_LOG, $now) as $logFile) {
                    $files[] = $logFile + ['tail' => self::tail(rtrim(WPS_IC_LOG, '/') . '/' . basename($logFile['path']), self::TEXT_LOG_TAIL)];
                }
            }
            $facts[] = self::fact('text debug log', ['on' => $textOn, 'files' => $files === [] ? 'none' : $files], 'option:wps_ic_debug_log; dir:cache/logs/');

            if ($recentErrors !== []) {
                $verdict = self::verdict('warn', 'php-warnings', sprintf('%d PHP warning(s) from plugin files in the window, the last: %s', count($recentErrors), end($recentErrors)));
            } elseif ($truncated) {
                $verdict = self::verdict('warn', 'journal-truncated', sprintf('the window holds more than %d entries: only the newest are read, from %s', (int) ($meta['limit'] ?? 0), self::at((int) ($meta['first'] ?? 0))));
            } elseif ((int) ($meta['lines'] ?? 0) === 0) {
                $verdict = self::verdict('unknown', 'journal-empty', 'the journal holds nothing in the window: no receipt can be read');
            } else {
                $verdict = self::verdict('ok', 'ok', sprintf('the journal holds %d entries from %s; %d claimed by no compartment', (int) $meta['lines'], self::at((int) ($meta['first'] ?? 0)), $unclaimed));
            }
            return ['verdict' => $verdict, 'facts' => $facts, 'artifacts' => $state === null ? [] : [$state]];
        }

        /** An option log (an array of strings) as it is stored, the last $max lines. */
        private static function option_lines($option, $max)
        {
            $lines = get_option($option, []);
            return is_array($lines) ? array_values(array_slice($lines, -(int) $max)) : [];
        }

        /** The lines of an option log stamped "Y-m-d H:i:s | ..." at or after $since. */
        private static function lines_since(array $lines, $since)
        {
            $out = [];
            foreach ($lines as $line) {
                $stamp = strtotime(substr((string) $line, 0, 19) . ' UTC');
                if ($stamp !== false && $stamp >= $since) {
                    $out[] = (string) $line;
                }
            }
            return $out;
        }

        /** The last $n lines of a text file, read from its last 64 KB. */
        private static function tail($path, $n)
        {
            $size = (int) @filesize($path);
            if ($size <= 0) {
                return [];
            }
            $text = (string) @file_get_contents($path, false, null, max(0, $size - 65536));
            $lines = preg_split('/\r?\n/', rtrim($text));
            return array_values(array_slice(is_array($lines) ? $lines : [], -(int) $n));
        }
    }
}
