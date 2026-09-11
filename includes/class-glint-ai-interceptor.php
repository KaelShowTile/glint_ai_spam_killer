<?php
if (!defined('ABSPATH')) {
	exit;
}

class Glint_AI_Interceptor
{

	private static $is_force_sending = false;

	public static function init()
	{
		add_filter('pre_wp_mail', array(__CLASS__, 'intercept_mail'), 10, 2);
	}

	public static function intercept_mail($return, $atts)
	{
		// If we are currently force sending from the cron or manual action, let it pass.
		if (self::$is_force_sending) {
			return null;
		}

		$to = isset($atts['to']) ? $atts['to'] : '';
		$subject = isset($atts['subject']) ? $atts['subject'] : '';
		$message = isset($atts['message']) ? $atts['message'] : '';
		$headers = isset($atts['headers']) ? $atts['headers'] : '';

		// Parse headers to find From and Reply-To
		$parsed_headers = self::parse_headers($headers);

		// We should extract the actual email addresses
		$to_emails = self::extract_emails($to);
		$from_email = self::extract_email($parsed_headers['from']);
		$reply_to_email = self::extract_email($parsed_headers['reply_to']);

		// Check Whitelists
		if (
			self::is_whitelisted($to_emails, 'recipient') ||
			self::is_whitelisted(array($from_email), 'sender') ||
			self::is_whitelisted(array($reply_to_email), 'reply_to')
		) {
			return null; // Let it pass
		}

		// Check Content Blacklist
		if (self::is_content_blacklisted($subject . "\n" . $message)) {
			global $wpdb;
			$table_name = Glint_AI_DB::get_table_name();
			$headers_str = is_array($headers) ? implode("\n", $headers) : $headers;
			$to_str = is_array($to) ? implode(',', $to) : $to;
			
			$wpdb->insert(
				$table_name,
				array(
					'to_email' => $to_str,
					'subject' => $subject,
					'message' => $message,
					'headers' => $headers_str,
					'status' => 'blocked',
					'created_at' => current_time('mysql'),
					'ai_reason' => 'Blocked by content blacklist'
				),
				array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
			);
			return true;
		}

		// Not whitelisted or blacklisted, intercept and save to DB
		global $wpdb;
		$table_name = Glint_AI_DB::get_table_name();

		// Convert headers to string if it's an array
		$headers_str = is_array($headers) ? implode("\n", $headers) : $headers;
		$to_str = is_array($to) ? implode(',', $to) : $to;

		$wpdb->insert(
			$table_name,
			array(
				'to_email' => $to_str,
				'subject' => $subject,
				'message' => $message,
				'headers' => $headers_str,
				'status' => 'pending',
				'created_at' => current_time('mysql'),
			),
			array('%s', '%s', '%s', '%s', '%s', '%s')
		);

		// Return true to short-circuit wp_mail
		return true;
	}

	public static function force_send($log_id)
	{
		global $wpdb;
		$table_name = Glint_AI_DB::get_table_name();

		$log = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $log_id));
		if (!$log) {
			return false;
		}

		// We use a static flag to bypass interception instead of remove_filter/add_filter 
		// because of edge cases with object instances or closures, though remove_filter is also fine.
		// Requirements specifically asked for remove_filter/add_filter.

		remove_filter('pre_wp_mail', array(__CLASS__, 'intercept_mail'), 10);

		// Send mail
		$headers = explode("\n", $log->headers);
		$sent = wp_mail($log->to_email, $log->subject, $log->message, $headers);

		// Re-add filter
		add_filter('pre_wp_mail', array(__CLASS__, 'intercept_mail'), 10, 2);

		if ($sent) {
			$wpdb->update(
				$table_name,
				array('status' => 'sent'),
				array('id' => $log_id),
				array('%s'),
				array('%d')
			);
			return true;
		}

		return false;
	}

	private static function parse_headers($headers)
	{
		$result = array('from' => '', 'reply_to' => '');
		if (empty($headers)) {
			return $result;
		}

		if (!is_array($headers)) {
			$headers = explode("\n", str_replace("\r\n", "\n", $headers));
		}

		foreach ($headers as $header) {
			if (stripos($header, 'From:') === 0) {
				$result['from'] = trim(substr($header, 5));
			} elseif (stripos($header, 'Reply-To:') === 0) {
				$result['reply_to'] = trim(substr($header, 9));
			}
		}

		return $result;
	}

	private static function extract_emails($string_or_array)
	{
		if (empty($string_or_array)) {
			return array();
		}

		$str = is_array($string_or_array) ? implode(',', $string_or_array) : $string_or_array;
		preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $str, $matches);
		return !empty($matches[0]) ? $matches[0] : array();
	}

	private static function extract_email($string)
	{
		$emails = self::extract_emails($string);
		return !empty($emails) ? $emails[0] : '';
	}

	private static function is_whitelisted($emails, $type)
	{
		if (empty($emails)) {
			return false;
		}

		$whitelist_raw = get_option('glint_ai_whitelist_' . $type, '');
		if (empty(trim($whitelist_raw))) {
			return false;
		}

		$lines = explode("\n", str_replace("\r\n", "\n", $whitelist_raw));
		$whitelist = array();
		foreach ($lines as $line) {
			$line = trim($line);
			if (!empty($line)) {
				$whitelist[] = strtolower($line);
			}
		}

		foreach ($emails as $email) {
			if (empty($email))
				continue;
			$email = strtolower($email);

			foreach ($whitelist as $rule) {
				if (strpos($rule, '@') === 0) {
					// Domain match
					if (substr($email, -strlen($rule)) === $rule) {
						return true;
					}
				} else {
					// Exact email match
					if ($email === $rule) {
						return true;
					}
				}
			}
		}

		return false;
	}

	private static function is_content_blacklisted($content)
	{
		if (empty($content)) {
			return false;
		}

		$blacklist_raw = get_option('glint_ai_blacklist_content', '');
		if (empty(trim($blacklist_raw))) {
			return false;
		}

		$lines = explode("\n", str_replace("\r\n", "\n", $blacklist_raw));
		foreach ($lines as $line) {
			if ($line === '') { // Keep spaces if intended, but ignore completely empty lines
				continue;
			}
			
			// User requested strict check, e.g., case-sensitive and spacing-sensitive
			if (strpos($content, $line) !== false) {
				return true;
			}
		}

		return false;
	}
}
