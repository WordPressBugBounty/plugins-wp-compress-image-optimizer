<?php
/*
 * THE DOCTOR'S CONTEXT: built once per run, read by every compartment.
 *
 * It holds the URL the run is about, its url key and folders, the settings and excludes rows, and
 * the journal window, each read once. Building it writes nothing: the url key comes from
 * wps_ic_url_key::setup($url) with the URL passed in (never the request's own), the journal from
 * wpc_cflog_lines() (the one reader of the plugin log), the rest from get_option().
 */
if (!class_exists('wps_ic_doctor_context')) {
    class wps_ic_doctor_context
    {
        /** The page the run is about (default: the homepage). */
        public $url = '';
        /** Its url key, '' when wps_ic_url_key is not loaded. */
        public $key = '';
        /** 'critical/<key>/', '' without a key. */
        public $crit_dir = '';
        /** The anonymous visitor's copy folder, 'wp-cio/<key>/', '' without a key. */
        public $cache_dir = '';
        /** 'home', a post id, or 0 when the URL resolves to neither. */
        public $page_id = 0;
        /** The page's own settings (wpc-excludes page_excludes of page_id), [] when none. */
        public $page_excludes = [];
        /** ['desktop', 'mobile'], or the one asked for. */
        public $devices = ['desktop', 'mobile'];
        public $now = 0;
        public $hours = 24;
        /** WPS_IC_SETTINGS and wpc-excludes, read once. */
        public $settings = [];
        public $excludes = [];
        /** The journal entries of the window, decoded, oldest first. */
        public $journal = [];
        /** What the window actually holds: lines, the line limit, first and last entry time. */
        public $journal_meta = [];
        /** Compartment-specific pass-through. */
        public $opts = [];
        /** What could not be read while building the context. */
        public $errors = [];

        public function __construct($url = '', array $opts = [])
        {
            $this->opts = $opts;
            $this->now = isset($opts['now']) ? (int) $opts['now'] : time();
            $this->hours = max(1, min(720, isset($opts['hours']) ? (int) $opts['hours'] : 24));
            $home = function_exists('home_url') ? (string) home_url('/') : '';
            $this->url = (string) $url !== '' ? (string) $url : $home;
            if (isset($opts['device']) && in_array($opts['device'], ['desktop', 'mobile'], true)) {
                $this->devices = [$opts['device']];
            }
            $settings = defined('WPS_IC_SETTINGS') ? get_option(WPS_IC_SETTINGS) : [];
            $this->settings = is_array($settings) ? $settings : [];
            $excludes = get_option('wpc-excludes');
            $this->excludes = is_array($excludes) ? $excludes : [];

            if (class_exists('wps_ic_url_key')) {
                $this->key = ltrim((string) (new wps_ic_url_key())->setup($this->url), '/');
                $homeKey = $home !== '' ? ltrim((string) (new wps_ic_url_key())->setup($home), '/') : '';
                if ($this->key !== '' && $this->key === $homeKey) {
                    $this->page_id = 'home';
                }
            } else {
                $this->errors[] = 'unavailable: wps_ic_url_key not loaded';
            }
            if ($this->page_id === 0 && function_exists('url_to_postid')) {
                $this->page_id = (int) url_to_postid($this->url);
            }
            $pages = isset($this->excludes['page_excludes']) && is_array($this->excludes['page_excludes']) ? $this->excludes['page_excludes'] : [];
            if ($this->page_id !== 0 && isset($pages[$this->page_id]) && is_array($pages[$this->page_id])) {
                $this->page_excludes = $pages[$this->page_id];
            }
            if ($this->key !== '') {
                $this->crit_dir = function_exists('wpc_crit_dir') ? wpc_crit_dir($this->key) : '';
                $this->cache_dir = defined('WPS_IC_CACHE') && strpos($this->key, '..') === false ? rtrim(WPS_IC_CACHE, '/') . '/' . $this->key . '/' : '';
            }
            $this->read_journal(isset($opts['lines']) ? (int) $opts['lines'] : 20000);
        }

        /** The journal window, read once through the log's one reader. */
        private function read_journal($limit)
        {
            $limit = max(1, min(20000, $limit));
            $this->journal_meta = ['lines' => 0, 'limit' => $limit, 'since' => $this->now - $this->hours * 3600, 'first' => 0, 'last' => 0];
            if (!function_exists('wpc_cflog_lines')) {
                $this->errors[] = 'unavailable: wpc_cflog_lines not loaded';
                return;
            }
            foreach (wpc_cflog_lines($limit, $this->journal_meta['since']) as $line) {
                $entry = json_decode((string) $line, true);
                if (is_array($entry)) {
                    $this->journal[] = $entry;
                }
            }
            $this->journal_meta['lines'] = count($this->journal);
            if ($this->journal) {
                $this->journal_meta['first'] = (int) ($this->journal[0]['t'] ?? 0);
                $this->journal_meta['last'] = (int) ($this->journal[count($this->journal) - 1]['t'] ?? 0);
            }
        }

        /** Whether a journal entry is about this page: its key, or site-wide ('', 'all', 'site'). */
        public function entry_is_mine(array $entry)
        {
            $key = ltrim((string) ($entry['key'] ?? ''), '/');
            return $key === '' || $key === 'all' || $key === 'site' || ($this->key !== '' && $key === $this->key);
        }
    }
}
