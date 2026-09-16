<?php
if (!defined('ABSPATH')) {
	exit;
}

class Glint_AI_Cron
{

	public static function init()
	{
		add_filter('cron_schedules', array(__CLASS__, 'add_cron_schedule'));
		add_action('glint_ai_process_spam_queue', array(__CLASS__, 'process_queue'));

		if (!wp_next_scheduled('glint_ai_process_spam_queue')) {
			wp_schedule_event(time(), 'every_minute', 'glint_ai_process_spam_queue');
		}
	}

	public static function add_cron_schedule($schedules)
	{
		if (!isset($schedules['every_minute'])) {
			$schedules['every_minute'] = array(
				'interval' => 60,
				'display' => __('Every Minute')
			);
		}
		return $schedules;
	}

	public static function schedule_events()
	{
		if (!wp_next_scheduled('glint_ai_process_spam_queue')) {
			wp_schedule_event(time(), 'every_minute', 'glint_ai_process_spam_queue');
		}
	}

	public static function clear_events()
	{
		wp_clear_scheduled_hook('glint_ai_process_spam_queue');
	}

	public static function process_queue()
	{
		error_log('Glint AI Spam Killer: process_queue started.');

		$api_key = get_option('glint_ai_api_key', '');
		$model = get_option('glint_ai_llm_model', 'gemini-1.5-flash');
		$provider = get_option('glint_ai_api_provider', 'gemini');

		if (empty($api_key) || 'gemini' !== $provider) {
			error_log('Glint AI Spam Killer: process_queue exited. API Key missing or provider is not gemini.');
			return; // Not configured
		}

		global $wpdb;
		$table_name = Glint_AI_DB::get_table_name();
		$rate_limit = (int) get_option('glint_ai_rate_limit', 20);

		$logs = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM $table_name WHERE status = 'pending' ORDER BY created_at ASC LIMIT %d",
			$rate_limit
		));

		if (empty($logs)) {
			error_log('Glint AI Spam Killer: process_queue exited. No pending logs found.');
			return;
		}

		error_log('Glint AI Spam Killer: Found ' . count($logs) . ' pending emails to process.');

		$default_prompt = "You are an AI spam filter. Analyze the email below and determine if it is spam (e.g. meaningless random characters, promotional garbage). If it is NOT spam, you must extract the true sender's name, their plain email address (e.g. name@example.com, no brackets), and the actual message content they wrote. Respond ONLY with a valid JSON object containing exactly 5 keys: \"is_spam\" (boolean), \"reason\" (string explaining your decision), \"sender_name\" (string, or empty string if not found), \"sender_email\" (string, or empty string if not found), and \"true_message\" (string, or empty string if not found). Do not output any other text or markdown.";
		$custom_prompt = get_option('glint_ai_system_prompt', '');
		$system_prompt = $default_prompt;
		if (!empty(trim($custom_prompt))) {
			$system_prompt .= "\n\nAdditional Instructions:\n" . trim($custom_prompt);
		}

		$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";

		foreach ($logs as $log) {
			// Pre-process message
			$clean_message = wp_strip_all_tags($log->message);
			if (mb_strlen($clean_message) > 2000) {
				$clean_message = mb_substr($clean_message, 0, 2000) . '...';
			}

			$email_content = "{$system_prompt}\n\n---\nEmail Headers:\n{$log->headers}\n\nEmail Subject: {$log->subject}\nEmail Body:\n{$clean_message}\n---";

			$body_data = array(
				'contents' => array(
					array(
						'role' => 'user',
						'parts' => array(
							array('text' => $email_content)
						)
					)
				),
				'generationConfig' => array(
					'responseMimeType' => 'application/json',
					'responseSchema' => array(
						'type' => 'OBJECT',
						'properties' => array(
							'is_spam' => array('type' => 'BOOLEAN'),
							'reason' => array('type' => 'STRING'),
							'sender_name' => array('type' => 'STRING'),
							'sender_email' => array('type' => 'STRING'),
							'true_message' => array('type' => 'STRING')
						),
						'required' => array('is_spam', 'reason', 'sender_name', 'sender_email', 'true_message')
					)
				)
			);

			$max_retries = 3;
			$is_spam = null;
			$reason = '';
			$sender_name = '';
			$sender_email = '';
			$true_message = '';
			$ai_text = '';
			$response_body = '';
			$last_error_reason = '';

			for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
				$response = wp_remote_post($url, array(
					'timeout' => 30,
					'headers' => array(
						'Content-Type' => 'application/json',
					),
					'body' => wp_json_encode($body_data)
				));

				if (is_wp_error($response)) {
					$error_msg = $response->get_error_message();
					error_log('Glint AI Spam Killer: WP Error on API request (attempt ' . $attempt . '): ' . $error_msg);
					$last_error_reason = 'API Error: ' . $error_msg;
					continue;
				}

				$status_code = wp_remote_retrieve_response_code($response);
				$response_body = wp_remote_retrieve_body($response);

				error_log('Glint AI Spam Killer: API response status (attempt ' . $attempt . '): ' . $status_code);

				if ($status_code !== 200) {
					$error_data = json_decode($response_body, true);
					$error_text = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'HTTP Error ' . $status_code;
					error_log('Glint AI Spam Killer: API returned error (attempt ' . $attempt . '): ' . $error_text);
					$last_error_reason = 'API Error: ' . $error_text;
					continue;
				}

				$data = json_decode($response_body, true);

				if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
					$ai_text = $data['candidates'][0]['content']['parts'][0]['text'];

					// Strip markdown code block wrappers if they exist
					$ai_text_clean = trim($ai_text);
					if (strpos($ai_text_clean, '```json') === 0) {
						$ai_text_clean = substr($ai_text_clean, 7);
					} elseif (strpos($ai_text_clean, '```') === 0) {
						$ai_text_clean = substr($ai_text_clean, 3);
					}
					if (substr($ai_text_clean, -3) === '```') {
						$ai_text_clean = substr($ai_text_clean, 0, -3);
					}
					$ai_text_clean = trim($ai_text_clean);

					$ai_json = json_decode($ai_text_clean, true);

					if (json_last_error() === JSON_ERROR_NONE && isset($ai_json['is_spam'])) {
						error_log('Glint AI Spam Killer: Successfully parsed JSON. LLM Output: ' . wp_json_encode($ai_json));

						$is_spam = (bool) $ai_json['is_spam'];
						$reason = isset($ai_json['reason']) ? sanitize_text_field($ai_json['reason']) : '';
						$sender_name = isset($ai_json['sender_name']) && $ai_json['sender_name'] !== null ? sanitize_text_field($ai_json['sender_name']) : '';

						$raw_email = isset($ai_json['sender_email']) && $ai_json['sender_email'] !== null ? $ai_json['sender_email'] : '';
						// Extract email if it's in "Name <email@example.com>" format
						if (preg_match('/<([^>]+)>/', $raw_email, $matches)) {
							$raw_email = $matches[1];
						}
						$sender_email = sanitize_email($raw_email);

						error_log("Glint AI Spam Killer: Extracted Email after sanitization: '{$sender_email}' (Raw was: '{$raw_email}')");

						$true_message = isset($ai_json['true_message']) && $ai_json['true_message'] !== null ? wp_kses_post($ai_json['true_message']) : '';

						break; // Success, exit retry loop
					}
				}

				error_log('Glint AI Spam Killer: Failed to parse AI response on attempt ' . $attempt . '. Retrying...');
				$last_error_reason = 'Failed to parse AI response: ' . wp_strip_all_tags(mb_substr($ai_text ?? $response_body, 0, 200));
			}

			if ($is_spam === true) {
				// Blocked
				error_log('Glint AI Spam Killer: Email ' . $log->id . ' marked as SPAM.');
				$wpdb->update(
					$table_name,
					array('status' => 'blocked', 'ai_reason' => $reason),
					array('id' => $log->id),
					array('%s', '%s'),
					array('%d')
				);
			} elseif ($is_spam === false) {
				// Sent (Not spam)
				error_log('Glint AI Spam Killer: Email ' . $log->id . ' marked as NOT SPAM. Sending mail...');

				$update_data = array(
					'ai_reason' => $reason,
					'sender_name' => $sender_name,
					'sender_email' => $sender_email
				);
				$update_format = array('%s', '%s', '%s');

				if (!empty($true_message)) {
					$update_data['message'] = $true_message;
					$update_format[] = '%s';

					// Also update the local log object so force_send uses the cleaned message
					$log->message = $true_message;
				}

				$wpdb->update(
					$table_name,
					$update_data,
					array('id' => $log->id),
					$update_format,
					array('%d')
				);

				// Force send the email
				Glint_AI_Interceptor::force_send($log->id);

				// Send to Omnisend
				if (!empty($sender_email)) {
					self::send_to_omnisend($sender_name, $sender_email);
				}
			} else {
				error_log('Glint AI Spam Killer: Giving up after ' . $max_retries . ' attempts. Last Error: ' . $last_error_reason);
				$wpdb->update($table_name, array('ai_reason' => $last_error_reason), array('id' => $log->id));
			}
		}
	}

	private static function send_to_omnisend($sender_name, $sender_email)
	{
		$omnisend_api_key = get_option('glint_ai_omnisend_api_key', '');
		if (empty($omnisend_api_key)) {
			return;
		}

		$url = 'https://api.omnisend.com/v3/contacts';

		// Split sender name to first and last name if possible
		$name_parts = explode(' ', trim($sender_name), 2);
		$first_name = isset($name_parts[0]) ? $name_parts[0] : '';
		$last_name = isset($name_parts[1]) ? $name_parts[1] : '';

		$body = array(
			'identifiers' => array(
				array(
					'type' => 'email',
					'id' => $sender_email,
					'channels' => array(
						'email' => array(
							'status' => 'nonSubscribed',
							'statusDate' => gmdate('Y-m-d\TH:i:s\Z')
						)
					)
				)
			),
			'tags' => array('after spam flittering')
		);

		if (!empty($first_name)) {
			$body['firstName'] = $first_name;
		}
		if (!empty($last_name)) {
			$body['lastName'] = $last_name;
		}

		$response = wp_remote_post($url, array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
				'X-API-KEY' => $omnisend_api_key
			),
			'body' => wp_json_encode($body)
		));

		if (is_wp_error($response)) {
			error_log('Glint AI Spam Killer: Omnisend API Error: ' . $response->get_error_message());
		} else {
			$status_code = wp_remote_retrieve_response_code($response);
			if ($status_code >= 300) {
				error_log('Glint AI Spam Killer: Omnisend API returned status ' . $status_code . ' - ' . wp_remote_retrieve_body($response));
			}
		}
	}
}
