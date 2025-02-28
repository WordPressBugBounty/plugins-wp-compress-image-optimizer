<?php
class wps_ic_logger
{

	public $folder;
	public $folderSanitized;
	public $logFile;
	public $userIP;
	public $userAgent;

	public function __construct($folder = '')
	{
		// Get user IP and user agent
		$this->userIP = $this->get_client_ip();
		$this->userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown';
		$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
		$this->full_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

		$datetime = date('Y-m-d_H-i-s');
		if (empty($folder)) {
			$this->folder = '';
			$this->folderSanitized = '';
			$this->logFile = WPS_IC_DIR . '/logs/' . $datetime . '.log';
		} else {
			$this->folder = $folder;
			$this->folderSanitized = $this->sanitize($this->folder);
			$this->logFile = WPS_IC_DIR . '/logs/' . $this->folderSanitized . '/' . $datetime . '.log';
		}

		// Create Dir
		$this->createDir();

		// Log the initial request information
		$this->log_request_info();
	}


	public function sanitize($string)
	{
		return preg_replace('/[^a-zA-Z0-9]+/', '-', strtolower($string));
	}


	public function createDir()
	{
		if (!file_exists(WPS_IC_DIR  . '/logs')) {
			mkdir(WPS_IC_DIR  . '/logs');
		}
		if (!file_exists(WPS_IC_DIR  . '/logs/' . $this->folderSanitized) && !empty($this->folderSanitized)) {
			mkdir(WPS_IC_DIR  . '/logs/' . $this->folderSanitized);
		}

		return $this;
	}

	/**
	 * Get the client IP address
	 * @return string
	 */
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

	/**
	 * Log request information at initialization
	 */
	private function log_request_info() {
		$time = date('Y-m-d H:i:s') . sprintf('.%03d', round(microtime(true) * 1000) % 1000);

		$logEntry = $time . " | INIT | IP: " . $this->userIP . " | UA: " . $this->userAgent . PHP_EOL;
		$logEntry .= 'URL: ' . $this->full_url . PHP_EOL;

		// Add PHP backtrace below the full URL
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

		// Append log entry to file
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

		// Append log entry to log.txt
		file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
	}
}