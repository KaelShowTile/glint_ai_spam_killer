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

		$default_prompt = "You are an AI spam filter. Analyze the email below and determine if it is spam (e.g. meaningless random characters, promotional garbage). Respond ONLY with a valid JSON object containing exactly two keys: \"is_spam\" (boolean) and \"reason\" (string explaining your decision). Do not output any other text or markdown.";
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

			$email_content = "{$system_prompt}\n\n---\nEmail Subject: {$log->subject}\nEmail Body:\n{$clean_message}\n---";

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
				)
			);

			$response = wp_remote_post($url, array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body' => wp_json_encode($body_data)
			));

			if (is_wp_error($response)) {
				$error_msg = $response->get_error_message();
				error_log('Glint AI Spam Killer: WP Error on API request: ' . $error_msg);
				$wpdb->update($table_name, array('ai_reason' => 'API Error: ' . $error_msg), array('id' => $log->id));
				continue;
			}

			$status_code = wp_remote_retrieve_response_code($response);
			$response_body = wp_remote_retrieve_body($response);

			error_log('Glint AI Spam Killer: API response status: ' . $status_code);

			if ($status_code !== 200) {
				// API error, keep pending but log reason
				$error_data = json_decode($response_body, true);
				$error_text = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'HTTP Error ' . $status_code;
				error_log('Glint AI Spam Killer: API returned error: ' . $error_text);
				$wpdb->update($table_name, array('ai_reason' => 'API Error: ' . $error_text), array('id' => $log->id));
				continue;
			}

			$data = json_decode($response_body, true);

			$is_spam = null;
			$reason = '';

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
					$is_spam = (bool) $ai_json['is_spam'];
					$reason = isset($ai_json['reason']) ? sanitize_text_field($ai_json['reason']) : '';
				}
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
				$wpdb->update(
					$table_name,
					array('ai_reason' => $reason),
					array('id' => $log->id),
					array('%s'),
					array('%d')
				);
				Glint_AI_Interceptor::force_send($log->id);
			} else {
				error_log('Glint AI Spam Killer: Failed to parse AI response. Text: ' . mb_substr($ai_text ?? $response_body, 0, 200));
				$wpdb->update($table_name, array('ai_reason' => 'Failed to parse AI response: ' . wp_strip_all_tags(mb_substr($ai_text ?? $response_body, 0, 200))), array('id' => $log->id));
			}
		}
	}
}
