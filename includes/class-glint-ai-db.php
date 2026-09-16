<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Glint_AI_DB {

	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'glint_ai_spam_logs';
	}

	public static function create_table() {
		global $wpdb;
		$table_name = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			to_email varchar(255) NOT NULL,
			subject varchar(255) NOT NULL,
			message longtext NOT NULL,
			headers text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			sender_name varchar(255) DEFAULT '',
			sender_email varchar(255) DEFAULT '',
			ai_reason text,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}
}
