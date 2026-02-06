<?php


class wps_ic_log {

	public static $log_path;
	public static $debug;


	public function __construct($log_file = 'log') {
		$this::$log_path = WPS_IC_LOG . $log_file . '.txt';
	}


	public function write($log_file, $event, $message, $force = false) {

		if (!WPS_IC_DEBUG_LOG) {
			return;
		}

		if (empty($log_file)) {
			$log_file = $this::$log_path;
		}

		if ( ! file_exists($log_file)) {
			fopen($log_file, 'w');
		}

		$log = file_get_contents($log_file);

		if ($message == '-') {
			$log .= '--' . "\r\n";
		} else {
			if ( ! is_object($message) && ! is_object($event)) {
				$log .= '[' . date('d.m.Y H:i:s') . '] Event occured: ' . $event . ' - ' . $message . "\r\n";
			}
		}

		file_put_contents($log_file, $log);

	}


    public function logCachePurging($oldOptions, $newOptions, $message, $file = 'cachePurging') {
        if (!WPS_IC_DEBUG_LOG) {
            return;
        }

        $this->write(WP_CONTENT_DIR . '/' . $file . '.txt', 'Cache Purged: ' . $message, 'Old Options: ' . print_r($oldOptions,true) . ' - New Options: ' . print_r($newOptions,true));
    }



}