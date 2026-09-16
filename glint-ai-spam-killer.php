<?php
/**
 * Plugin Name: ST AI Spam Killer
 * Description: Intercepts wp_mail and uses Gemini LLM to filter out spam emails asynchronously.
 * Version: 1.0.0
 * Author: Kael
 * Text Domain: glint-ai-spam-killer
 */

if (!defined('ABSPATH')) {
	exit;
}

define('GLINT_AI_SPAM_KILLER_VERSION', '1.0.1');
define('GLINT_AI_SPAM_KILLER_DIR', plugin_dir_path(__FILE__));
define('GLINT_AI_SPAM_KILLER_URL', plugin_dir_url(__FILE__));

// Include necessary files
require_once GLINT_AI_SPAM_KILLER_DIR . 'includes/class-glint-ai-db.php';
require_once GLINT_AI_SPAM_KILLER_DIR . 'includes/class-glint-ai-settings.php';
require_once GLINT_AI_SPAM_KILLER_DIR . 'includes/class-glint-ai-list-table.php';
require_once GLINT_AI_SPAM_KILLER_DIR . 'includes/class-glint-ai-interceptor.php';
require_once GLINT_AI_SPAM_KILLER_DIR . 'includes/class-glint-ai-cron.php';

class Glint_AI_Spam_Killer
{

	private static $instance = null;

	public static function get_instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct()
	{
		register_activation_hook(__FILE__, array($this, 'activate'));
		register_deactivation_hook(__FILE__, array($this, 'deactivate'));

		add_action('plugins_loaded', array($this, 'init'));
	}

	public function activate()
	{
		Glint_AI_DB::create_table();
		Glint_AI_Cron::schedule_events();
	}

	public function deactivate()
	{
		Glint_AI_Cron::clear_events();
	}

	public function init()
	{
		$this->check_db_update();
		
		Glint_AI_Settings::init();
		Glint_AI_List_Table::init();
		Glint_AI_Interceptor::init();
		Glint_AI_Cron::init();
	}

	private function check_db_update() {
		$db_version = get_option('glint_ai_db_version', '0');
		if (version_compare($db_version, GLINT_AI_SPAM_KILLER_VERSION, '<')) {
			Glint_AI_DB::create_table();
			update_option('glint_ai_db_version', GLINT_AI_SPAM_KILLER_VERSION);
		}
	}
}

Glint_AI_Spam_Killer::get_instance();
