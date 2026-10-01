<?php
/*
 * THE DOCTOR'S COMPARTMENT CONTRACT.
 *
 * A compartment answers one kind of ticket for one install and, optionally, one URL: what its
 * part of the plugin decided and why, read from the state the plugin already keeps. collect()
 * reads and returns; it never writes an option, a transient, a file or a folder, never schedules,
 * purges or dispatches, and never makes a network call. probe() is the one place a network call
 * may happen, and only when the person running the doctor asked for it.
 *
 * A compartment never requires a plugin file. When an owner it would call is not loaded in this
 * context, the fact says "unavailable: <owner> not loaded" and the verdict is unknown, so a run
 * under a reduced load (WP-CLI) shows what it could not see instead of printing less.
 */
if (!class_exists('wps_ic_doctor_compartment')) {
    abstract class wps_ic_doctor_compartment
    {
        /** The name the surfaces use ('crit'). */
        const NAME = '';
        /** The card title ('Critical CSS'). */
        const TITLE = '';
        /** One line: the ticket symptoms this compartment answers. */
        const SYMPTOMS = '';
        /** Journal event prefixes this compartment claims (wpc_doctor_group_receipts). */
        const EVENTS = [];
        /** True for the one card (logs) that is handed the page's entries no compartment claims. */
        const UNCLAIMED = false;

        /**
         * Read-only. Returns ['verdict' => [...], 'facts' => [...], 'artifacts' => [...]]; never
         * writes, never calls the network.
         */
        abstract public function collect(wps_ic_doctor_context $ctx);

        /** Opt-in network step (a self-fetch or a HEAD); [] when the compartment has none. */
        public function probe(wps_ic_doctor_context $ctx)
        {
            return [];
        }

        /** One fact: what it is, its value, and where the value came from (file:, option:, fn:, receipt:). */
        protected static function fact($label, $value, $source)
        {
            return ['label' => (string) $label, 'value' => $value, 'source' => (string) $source];
        }

        protected static function verdict($level, $code, $line)
        {
            return ['level' => (string) $level, 'code' => (string) $code, 'line' => (string) $line];
        }

        /** The value a fact carries when the owner that answers it is not loaded here. */
        protected static function unavailable($owner)
        {
            return 'unavailable: ' . $owner . ' not loaded';
        }

        /** A path as the report prints it: relative to wp-content when it sits there. */
        protected static function relative($path)
        {
            $path = str_replace('\\', '/', (string) $path);
            $root = defined('WP_CONTENT_DIR') ? rtrim(str_replace('\\', '/', WP_CONTENT_DIR), '/') . '/' : '';
            return ($root !== '' && strpos($path, $root) === 0) ? substr($path, strlen($root)) : $path;
        }

        /** One file's size and time, or null when there is no such file. */
        protected static function file_state($path, $now)
        {
            if ((string) $path === '' || !@is_file($path)) {
                return null;
            }
            $mtime = (int) @filemtime($path);
            return ['path' => self::relative($path), 'bytes' => (int) @filesize($path), 'mtime' => $mtime, 'age_s' => max(0, (int) $now - $mtime)];
        }

        /** The first $max bytes of a small text file, trimmed; '' when absent. */
        protected static function read_text($path, $max = 256)
        {
            if ((string) $path === '' || !@is_file($path)) {
                return '';
            }
            return trim((string) @file_get_contents($path, false, null, 0, (int) $max));
        }

        /** A JSON file decoded when it is under 1 MB; null when absent, unreadable or larger. */
        protected static function read_json($path)
        {
            if ((string) $path === '' || !@is_file($path) || (int) @filesize($path) > 1048576) {
                return null;
            }
            $decoded = json_decode((string) @file_get_contents($path), true);
            return is_array($decoded) ? $decoded : null;
        }

        /** Every file directly inside $dir with its size and time, sorted by name; [] when absent. */
        protected static function list_dir($dir, $now)
        {
            $out = [];
            if ((string) $dir === '' || !@is_dir($dir)) {
                return $out;
            }
            $names = @scandir($dir);
            foreach (is_array($names) ? $names : [] as $name) {
                $state = ($name === '.' || $name === '..') ? null : self::file_state(rtrim($dir, '/') . '/' . $name, $now);
                if ($state !== null) {
                    $out[] = $state;
                }
            }
            return $out;
        }

        /** The newest journal entry for this context's key (or site-wide) named $event, or null. */
        protected static function last_entry(wps_ic_doctor_context $ctx, $event)
        {
            $found = null;
            foreach ($ctx->journal as $entry) {
                if ((string) ($entry['event'] ?? '') === $event && $ctx->entry_is_mine($entry)) {
                    $found = $entry;
                }
            }
            return $found;
        }

        /** How many journal entries for this key (or site-wide) are named $event. */
        protected static function count_entries(wps_ic_doctor_context $ctx, $event)
        {
            $n = 0;
            foreach ($ctx->journal as $entry) {
                if ((string) ($entry['event'] ?? '') === $event && $ctx->entry_is_mine($entry)) {
                    $n++;
                }
            }
            return $n;
        }

        /** A unix time as the report prints it. */
        protected static function at($time)
        {
            return (int) $time > 0 ? gmdate('Y-m-d H:i:s', (int) $time) . 'Z' : '-';
        }
    }
}
