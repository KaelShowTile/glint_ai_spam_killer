<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Glint_AI_Cron {

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedule' ) );
		add_action( 'glint_ai_process_spam_queue', array( __CLASS__, 'process_queue' ) );
	}

	public static function add_cron_schedule( $schedules ) {
		if ( ! isset( $schedules['every_minute'] ) ) {
			$schedules['every_minute'] = array(
				'interval' => 60,
				'display'  => __( 'Every Minute' )
			);
		}
		return $schedules;
	}

	public static function schedule_events() {
		if ( ! wp_next_scheduled( 'glint_ai_process_spam_queue' ) ) {
			wp_schedule_event( time(), 'every_minute', 'glint_ai_process_spam_queue' );
		}
	}

	public static function clear_events() {
		wp_clear_scheduled_hook( 'glint_ai_process_spam_queue' );
	}

	public static function process_queue() {
		$api_key = get_option( 'glint_ai_api_key', '' );
		$model = get_option( 'glint_ai_llm_model', 'gemini-1.5-flash' );
		$provider = get_option( 'glint_ai_api_provider', 'gemini' );
		
		if ( empty( $api_key ) || 'gemini' !== $provider ) {
			return; // Not configured
		}

		global $wpdb;
		$table_name = Glint_AI_DB::get_table_name();
		$rate_limit = (int) get_option( 'glint_ai_rate_limit', 20 );

		$logs = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table_name WHERE status = 'pending' ORDER BY created_at ASC LIMIT %d",
			$rate_limit
		) );

		if ( empty( $logs ) ) {
			return;
		}

		$default_prompt = "You are an AI spam filter. Analyze the following email content and determine if it is spam (such as meaningless random characters, promotional garbage, or malicious content). You must respond strictly in JSON format with exactly two fields: \"is_spam\" (boolean) and \"reason\" (string explaining why). Do not wrap the JSON in Markdown formatting like ```json.";
		$custom_prompt = get_option( 'glint_ai_system_prompt', '' );
		$system_prompt = $default_prompt;
		if ( ! empty( trim( $custom_prompt ) ) ) {
			$system_prompt .= "\n\nAdditional Instructions:\n" . trim( $custom_prompt );
		}

		$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";

		foreach ( $logs as $log ) {
			// Pre-process message
			$clean_message = wp_strip_all_tags( $log->message );
			if ( mb_strlen( $clean_message ) > 2000 ) {
				$clean_message = mb_substr( $clean_message, 0, 2000 ) . '...';
			}
			
			$email_content = "Subject: {$log->subject}\n\nContent:\n{$clean_message}";

			$body_data = array(
				'systemInstruction' => array(
					'parts' => array(
						array( 'text' => $system_prompt )
					)
				),
				'contents' => array(
					array(
						'parts' => array(
							array( 'text' => $email_content )
						)
					)
				),
				'generationConfig' => array(
					'responseMimeType' => 'application/json',
				)
			);

			$response = wp_remote_post( $url, array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body' => wp_json_encode( $body_data )
			) );

			if ( is_wp_error( $response ) ) {
				// Failed or timeout, keep pending
				continue;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( $status_code !== 200 ) {
				// API error, keep pending
				continue;
			}

			$response_body = wp_remote_retrieve_body( $response );
			$data = json_decode( $response_body, true );

			$is_spam = null;
			$reason = '';

			if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
				$ai_text = $data['candidates'][0]['content']['parts'][0]['text'];
				$ai_json = json_decode( $ai_text, true );
				
				if ( json_last_error() === JSON_ERROR_NONE && isset( $ai_json['is_spam'] ) ) {
					$is_spam = (bool) $ai_json['is_spam'];
					$reason = isset( $ai_json['reason'] ) ? sanitize_text_field( $ai_json['reason'] ) : '';
				}
			}

			if ( $is_spam === true ) {
				// Blocked
				$wpdb->update(
					$table_name,
					array( 'status' => 'blocked', 'ai_reason' => $reason ),
					array( 'id' => $log->id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			} elseif ( $is_spam === false ) {
				// Sent (Not spam)
				// Note: force_send will update the status to sent
				$wpdb->update(
					$table_name,
					array( 'ai_reason' => $reason ),
					array( 'id' => $log->id ),
					array( '%s' ),
					array( '%d' )
				);
				Glint_AI_Interceptor::force_send( $log->id );
			}
			// If we couldn't parse the JSON or $is_spam is still null, it stays pending for next run or manual review
		}
	}
}
