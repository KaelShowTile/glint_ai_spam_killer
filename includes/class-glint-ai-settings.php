<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Glint_AI_Settings {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'admin_footer', array( __CLASS__, 'admin_footer_scripts' ) );
		add_action( 'wp_ajax_glint_ai_fetch_models', array( __CLASS__, 'ajax_fetch_models' ) );
	}

	public static function add_admin_menu() {
		add_menu_page(
			'AI Spam Killer',
			'AI Spam Killer',
			'manage_options',
			'glint-ai-spam-killer',
			array( __CLASS__, 'settings_page_html' ),
			'dashicons-shield',
			80
		);

		add_submenu_page(
			'glint-ai-spam-killer',
			'Settings',
			'Settings',
			'manage_options',
			'glint-ai-spam-killer',
			array( __CLASS__, 'settings_page_html' )
		);
	}

	public static function enqueue_scripts( $hook ) {
		if ( 'toplevel_page_glint-ai-spam-killer' !== $hook ) {
			return;
		}

		wp_enqueue_script( 'jquery' );
	}

	public static function admin_footer_scripts() {
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_glint-ai-spam-killer' !== $screen->id ) {
			return;
		}
		?>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('#glint_ai_fetch_models_btn').on('click', function(e) {
				e.preventDefault();
				var btn = $(this);
				var apiKey = $('#glint_ai_api_key').val();
				var provider = $('#glint_ai_api_provider').val();
				
				if (!apiKey) {
					alert('Please enter an API Key first.');
					return;
				}
				
				btn.prop('disabled', true).text('Fetching...');
				
				$.post(ajaxurl, {
					action: 'glint_ai_fetch_models',
					api_key: apiKey,
					provider: provider,
					nonce: '<?php echo wp_create_nonce("glint_ai_fetch_models_nonce"); ?>'
				}, function(response) {
					btn.prop('disabled', false).text('Fetch Models');
					if (response.success) {
						var select = $('#glint_ai_llm_model');
						var currentValue = select.val();
						select.empty();
						$.each(response.data, function(index, model) {
							select.append($('<option>', {
								value: model.name,
								text: model.displayName || model.name,
								selected: (model.name === currentValue)
							}));
						});
						alert('Models fetched successfully! Please save settings.');
					} else {
						alert('Failed to fetch models: ' + response.data);
					}
				});
			});
		});
		</script>
		<?php
	}

	public static function ajax_fetch_models() {
		check_ajax_referer( 'glint_ai_fetch_models_nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		
		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';
		
		if ( empty( $api_key ) || 'gemini' !== $provider ) {
			wp_send_json_error( 'Invalid API Key or Provider.' );
		}
		
		$url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $api_key;
		
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}
		
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		
		if ( isset( $data['models'] ) ) {
			// Filter for only generateContent models
			$models = array_filter( $data['models'], function( $model ) {
				return in_array( 'generateContent', $model['supportedGenerationMethods'] ?? array() );
			} );
			
			// Normalize names (remove 'models/' prefix)
			$models_out = array();
			foreach ( $models as $model ) {
				$name = str_replace( 'models/', '', $model['name'] );
				$display = isset( $model['displayName'] ) ? $model['displayName'] : $name;
				$models_out[] = array(
					'name' => $name,
					'displayName' => $display,
				);
			}
			wp_send_json_success( $models_out );
		} else if ( isset( $data['error'] ) ) {
			wp_send_json_error( $data['error']['message'] ?? 'Unknown API Error' );
		}
		
		wp_send_json_error( 'Unexpected response format.' );
	}

	public static function register_settings() {
		register_setting( 'glint_ai_settings', 'glint_ai_api_provider' );
		register_setting( 'glint_ai_settings', 'glint_ai_api_key' );
		register_setting( 'glint_ai_settings', 'glint_ai_llm_model' );
		register_setting( 'glint_ai_settings', 'glint_ai_system_prompt' );
		register_setting( 'glint_ai_settings', 'glint_ai_rate_limit' );
		register_setting( 'glint_ai_settings', 'glint_ai_whitelist_sender' );
		register_setting( 'glint_ai_settings', 'glint_ai_whitelist_recipient' );
		register_setting( 'glint_ai_settings', 'glint_ai_whitelist_reply_to' );
		register_setting( 'glint_ai_settings', 'glint_ai_blacklist_content' );
		register_setting( 'glint_ai_settings', 'glint_ai_cached_models' );

		add_settings_section( 'glint_ai_main_section', 'Main Settings', null, 'glint-ai-spam-killer' );

		add_settings_field( 'glint_ai_api_provider', 'API Provider', array( __CLASS__, 'field_api_provider' ), 'glint-ai-spam-killer', 'glint_ai_main_section' );
		add_settings_field( 'glint_ai_api_key', 'API Key', array( __CLASS__, 'field_api_key' ), 'glint-ai-spam-killer', 'glint_ai_main_section' );
		add_settings_field( 'glint_ai_llm_model', 'LLM Model', array( __CLASS__, 'field_llm_model' ), 'glint-ai-spam-killer', 'glint_ai_main_section' );
		add_settings_field( 'glint_ai_system_prompt', 'Additional System Prompt', array( __CLASS__, 'field_system_prompt' ), 'glint-ai-spam-killer', 'glint_ai_main_section' );
		add_settings_field( 'glint_ai_rate_limit', 'Rate Limit (per run)', array( __CLASS__, 'field_rate_limit' ), 'glint-ai-spam-killer', 'glint_ai_main_section' );
		
		add_settings_section( 'glint_ai_whitelist_section', 'Whitelists & Blacklists', null, 'glint-ai-spam-killer' );
		
		add_settings_field( 'glint_ai_whitelist_sender', 'Sender Whitelist (From)', array( __CLASS__, 'field_whitelist_sender' ), 'glint-ai-spam-killer', 'glint_ai_whitelist_section' );
		add_settings_field( 'glint_ai_whitelist_recipient', 'Recipient Whitelist (To)', array( __CLASS__, 'field_whitelist_recipient' ), 'glint-ai-spam-killer', 'glint_ai_whitelist_section' );
		add_settings_field( 'glint_ai_whitelist_reply_to', 'Reply-To Whitelist', array( __CLASS__, 'field_whitelist_reply_to' ), 'glint-ai-spam-killer', 'glint_ai_whitelist_section' );
		add_settings_field( 'glint_ai_blacklist_content', 'Content Blacklist', array( __CLASS__, 'field_blacklist_content' ), 'glint-ai-spam-killer', 'glint_ai_whitelist_section' );
	}

	public static function field_api_provider() {
		$val = get_option( 'glint_ai_api_provider', 'gemini' );
		echo '<select name="glint_ai_api_provider" id="glint_ai_api_provider">';
		echo '<option value="gemini" ' . selected( $val, 'gemini', false ) . '>Gemini</option>';
		echo '</select>';
	}

	public static function field_api_key() {
		$val = get_option( 'glint_ai_api_key', '' );
		echo '<input type="password" name="glint_ai_api_key" id="glint_ai_api_key" value="' . esc_attr( $val ) . '" class="regular-text" />';
	}

	public static function field_llm_model() {
		$val = get_option( 'glint_ai_llm_model', 'gemini-1.5-flash' );
		echo '<select name="glint_ai_llm_model" id="glint_ai_llm_model">';
		echo '<option value="' . esc_attr( $val ) . '">' . esc_html( $val ) . '</option>';
		echo '</select>';
		echo ' <button type="button" id="glint_ai_fetch_models_btn" class="button">Fetch/Refresh Models</button>';
		echo ' <p class="description">Click the button to fetch available models using your API Key.</p>';
	}

	public static function field_system_prompt() {
		$val = get_option( 'glint_ai_system_prompt', '' );
		echo '<textarea name="glint_ai_system_prompt" rows="5" class="large-text code">' . esc_textarea( $val ) . '</textarea>';
		echo '<p class="description">Optional. Additional instructions to append to the default spam-checking prompt.</p>';
	}

	public static function field_rate_limit() {
		$val = get_option( 'glint_ai_rate_limit', 20 );
		echo '<input type="number" name="glint_ai_rate_limit" value="' . esc_attr( $val ) . '" min="1" max="100" class="small-text" />';
		echo ' <p class="description">Maximum number of emails to process per minute.</p>';
	}

	public static function field_whitelist_sender() {
		$val = get_option( 'glint_ai_whitelist_sender', '' );
		echo '<textarea name="glint_ai_whitelist_sender" rows="4" class="large-text code">' . esc_textarea( $val ) . '</textarea>';
		echo '<p class="description">One email (admin@test.com) or domain (@test.com) per line. Applies to the From address.</p>';
	}

	public static function field_whitelist_recipient() {
		$val = get_option( 'glint_ai_whitelist_recipient', '' );
		echo '<textarea name="glint_ai_whitelist_recipient" rows="4" class="large-text code">' . esc_textarea( $val ) . '</textarea>';
		echo '<p class="description">One email (admin@test.com) or domain (@test.com) per line. Applies to the To address.</p>';
	}

	public static function field_whitelist_reply_to() {
		$val = get_option( 'glint_ai_whitelist_reply_to', '' );
		echo '<textarea name="glint_ai_whitelist_reply_to" rows="4" class="large-text code">' . esc_textarea( $val ) . '</textarea>';
		echo '<p class="description">One email (admin@test.com) or domain (@test.com) per line. Applies to the Reply-To address.</p>';
	}

	public static function field_blacklist_content() {
		$val = get_option( 'glint_ai_blacklist_content', '' );
		echo '<textarea name="glint_ai_blacklist_content" rows="4" class="large-text code">' . esc_textarea( $val ) . '</textarea>';
		echo '<p class="description">One keyword or phrase per line. If the email subject or body contains ANY of these exactly (case-sensitive, including spaces), it will be blocked immediately without AI check.</p>';
	}

	public static function settings_page_html() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>AI Spam Killer Settings</h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'glint_ai_settings' );
				do_settings_sections( 'glint-ai-spam-killer' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
