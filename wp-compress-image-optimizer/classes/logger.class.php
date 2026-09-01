<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: classes/logger.class.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.01
 */

class wps_ic_logger
{

	public $folder;
	public $folderSanitized;
	public $logFile;
	public $userIP;
	public $userAgent;
	public $full_url;

	public function __construct($folder = '')
	{
		
		$this->userIP = $this->get_client_ip();
		$this->userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown';
		$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
		$this->full_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

		$datetime = date('Y-m-d_H-i-s');
		if (empty($folder)) {
			$this->folder = '';
			$this->folderSanitized = '';
			$this->logFile = WPS_IC_LOG . $datetime . '.log';
		} else {
			$this->folder = $folder;
			$this->folderSanitized = $this->sanitize($this->folder);
			$this->logFile = WPS_IC_LOG . $this->folderSanitized . '/' . $datetime . '.log';
		}

		
		$this->createDir();

		
		$this->log_request_info();
	}


	public function sanitize($string)
	{
		return preg_replace('/[^a-zA-Z0-9]+/', '-', strtolower($string));
	}


	public function createDir()
	{
		if (!file_exists(WPS_IC_LOG)) {
			mkdir(WPS_IC_LOG);
		}
		if (!file_exists(WPS_IC_LOG . $this->folderSanitized) && !empty($this->folderSanitized)) {
			mkdir(WPS_IC_LOG . $this->folderSanitized);
		}

		return $this;
	}

	



	public function get_client_ip() {
		$ip = 'Unknown';

		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} elseif (!empty($_SERVER['REMOTE_ADDR'])) {
			$ip = $_SERVER['REMOTE_ADDR'];
		}

		return $ip;
	}

	


	private function log_request_info() {
		$time = date('Y-m-d H:i:s') . sprintf('.%03d', round(microtime(true) * 1000) % 1000);

		$logEntry = $time . " | INIT | IP: " . $this->userIP . " | UA: " . $this->userAgent . PHP_EOL;
		$logEntry .= 'URL: ' . $this->full_url . PHP_EOL;

		
		$backtrace = debug_backtrace();
		$logEntry .= 'BACKTRACE: ' . PHP_EOL;
		foreach ($backtrace as $index => $trace) {
			$file = isset($trace['file']) ? $trace['file'] : 'unknown file';
			$line = isset($trace['line']) ? $trace['line'] : 'unknown line';
			$function = isset($trace['function']) ? $trace['function'] : 'unknown function';
			$class = isset($trace['class']) ? $trace['class'] : '';
			$type = isset($trace['type']) ? $trace['type'] : '';

			$logEntry .= "#{$index} {$file}({$line}): ";
			if (!empty($class)) {
				$logEntry .= "{$class}{$type}";
			}
			$logEntry .= "{$function}()" . PHP_EOL;
		}
		$logEntry .= PHP_EOL;

		
		file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
	}

	public function log($message, $error = false)
	{
		$time = date('Y-m-d H:i:s') . sprintf('.%03d', round(microtime(true) * 1000) % 1000);

		if ($error) {
			$logEntry = $time . " | ERROR | Message: " . $message . PHP_EOL;
		} else {
			$logEntry = $time . " | SUCCESS | Message: " . $message . PHP_EOL;
		}

		
		file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
	}
}