<?php


class wps_ic_logger {

	public static $log_file;
	public static $debug;


	public function __construct($log_file = 'log') {
		$this::$log_file = WPS_IC_LOG . 'logger.txt';
	}


	public function log($message) {

		if ( ! WPS_IC_DEBUG_LOG) {
			return;
		}

		if ( ! file_exists($this::$log_file)) {
			fopen($this::$log_file, 'w+');
		}

		$log = file_get_contents($this::$log_file);

		$log .= '[' . date('d.m.Y H:i:s') . '] Event occured: ' . $message . "\r\n";
		file_put_contents($this::$log_file, $log);
		return true;
	}

}