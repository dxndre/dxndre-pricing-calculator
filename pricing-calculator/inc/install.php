<?php

defined('ABSPATH') || exit;

function dx_pricing_create_tables() {
	global $wpdb;

	$table = $wpdb->prefix . 'dx_quotes';
	$charset = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sql = "
	CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		invoice VARCHAR(50) NOT NULL,
		state LONGTEXT NOT NULL,
		total DECIMAL(10,2) NOT NULL,
		created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY invoice (invoice)
	) {$charset};
	";

	dbDelta($sql);
}