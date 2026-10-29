<?php
if (!defined('ABSPATH')) {
	exit;
}
/** Table identifiers are selected only from this closed map, never from request data. */
function dxbs_table($name) {
	global $wpdb;
	$allowed = array('clients', 'categories', 'client_categories', 'quotes', 'quote_lines', 'invoices', 'invoice_lines', 'payments', 'receipts', 'audit', 'email_jobs', 'sequences', 'access_tokens', 'payment_intents', 'credit_notes', 'archives');
	return in_array($name, $allowed, true) ? $wpdb->prefix . 'dxbs_' . $name : '';
}
function dxbs_schema_tables() {
	global $wpdb;
	$collate = $wpdb->get_charset_collate();
	$t = array();
	foreach (array('clients', 'categories', 'client_categories', 'quotes', 'quote_lines', 'invoices', 'invoice_lines', 'payments', 'receipts', 'audit', 'email_jobs', 'sequences', 'access_tokens', 'payment_intents', 'credit_notes', 'archives') as $name) {
		$t[$name] = dxbs_table($name);
	}
	return array("CREATE TABLE {$t['clients']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wp_user_id bigint(20) unsigned DEFAULT NULL,
			name varchar(191) NOT NULL,
			company varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL,
			phone varchar(80) NOT NULL DEFAULT '',
			billing_address_1 varchar(191) NOT NULL DEFAULT '',
			billing_address_2 varchar(191) NOT NULL DEFAULT '',
			billing_city varchar(120) NOT NULL DEFAULT '',
			billing_region varchar(120) NOT NULL DEFAULT '',
			billing_postcode varchar(32) NOT NULL DEFAULT '',
			billing_country varchar(2) NOT NULL DEFAULT '',
			notes longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			version bigint(20) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			UNIQUE KEY wp_user_id (wp_user_id),
			KEY status_name (status,name)
		) ENGINE=InnoDB $collate;", "CREATE TABLE {$t['categories']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(80) NOT NULL,
			name varchar(191) NOT NULL,
			description text NOT NULL,
			accent varchar(7) NOT NULL DEFAULT '#C9A44D',
			active tinyint(1) unsigned NOT NULL DEFAULT 1,
			sort_order int(11) NOT NULL DEFAULT 0,
			version bigint(20) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY active_order (active,sort_order)
		) ENGINE=InnoDB $collate;", "CREATE TABLE {$t['client_categories']} (
			client_id bigint(20) unsigned NOT NULL,
			category_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (client_id,category_id),
			KEY category_id (category_id)
		) ENGINE=InnoDB $collate;", "CREATE TABLE {$t['quotes']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			client_id bigint(20) unsigned NOT NULL,
			category_id bigint(20) unsigned NOT NULL,
			project_id bigint(20) unsigned DEFAULT NULL,
			legacy_reference varchar(191) DEFAULT NULL,
			number varchar(80) DEFAULT NULL,
			status varchar(30) NOT NULL DEFAULT 'draft',
			currency char(3) NOT NULL DEFAULT 'GBP',
			subtotal_minor bigint(20) unsigned NOT NULL DEFAULT 0,
			discount_minor bigint(20) unsigned NOT NULL DEFAULT 0,
			tax_minor bigint(20) unsigned NOT NULL DEFAULT 0,
			total_minor bigint(20) unsigned NOT NULL DEFAULT 0,
			deposit_minor bigint(20) unsigned NOT NULL DEFAULT 0,
			snapshot longtext NOT NULL,
			version bigint(20) unsigned NOT NULL DEFAULT 1,
			issued_at datetime DEFAULT NULL,
			expires_at datetime DEFAULT NULL,
			accepted_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY number (number),
			UNIQUE KEY legacy_reference (legacy_reference),
			KEY client_status (client_id,status),
			KEY category_id (category_id),
			KEY project_id (project_id)
		) ENGINE=InnoDB $collate;", "CREATE TABLE {$t['quote_lines']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			quote_id bigint(20) unsigned NOT NULL,
			position int(11) unsigned NOT NULL DEFAULT 0,
			description text NOT NULL,
			quantity_millis bigint(20) unsigned NOT NULL DEFAULT 1000,
			unit_minor bigint(20) unsigned NOT NULL DEFAULT 0,
			discount_minor bigint(20) unsigned NOT NULL DEFAULT 0,
			tax_basis_points int(11) unsigned NOT NULL DEFAULT 0,
			total_minor bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY quote_position (quote_id,position)
		) ENGINE=InnoDB $collate;", "CREATE TABLE {$t['invoices']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			client_id bigint(20) unsigned NOT NULL,
			category_id bigint(20) unsigned NOT NULL,
			project_id bigint(20) unsigned DEFAULT NULL,
			quote_id bigint(20) unsigned DEFAULT NULL,
			parent_invoice_id bigint(20) unsigned DEFAULT NULL,
			number varchar(80) DEFAULT NULL,
			kind varchar(30) NOT NULL DEFAULT 'standard',
			status varchar(30) NOT NULL DEFAULT 'draft',
			currency char(3) NOT NULL DEFAULT 'GBP',
			subtotal_minor bigint(20) unsigned NOT NULL DEFAULT 0,
			discount_minor bigint(20) unsigned NOT NULL DEFAULT 0,
			tax_minor bigint(20) unsigned NOT NULL DEFAULT 0,
			total_minor bigint(20) unsigned NOT NULL DEFAULT 0,
			snapshot longtext NOT NULL,
			version bigint(20) unsigned NOT NULL DEFAULT 1,
			issued_at datetime DEFAULT NULL,
			due_date date DEFAULT NULL,
			voided_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY number (number),
			KEY client_status (client_id,status),
			KEY category_id (category_id),
			KEY project_id (project_id),
			KEY quote_id (quote_id),
			KEY due_date (due_date)
		) ENGINE=InnoDB $collate;", "CREATE TABLE {$t['invoice_lines']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			invoice_id bigint(20) unsigned NOT NULL,
			position int(11) unsigned NOT NULL DEFAULT 0,
			description text NOT NULL,
			quantity_millis bigint(20) unsigned NOT NULL DEFAULT 1000,
			unit_minor bigint(20) unsigned NOT NULL DEFAULT 0,
			discount_minor bigint(20) unsigned NOT NULL DEFAULT 0,
			tax_basis_points int(11) unsigned NOT NULL DEFAULT 0,
			total_minor bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY invoice_position (invoice_id,position)
		) ENGINE=InnoDB $collate;", "CREATE TABLE {$t['payments']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			invoice_id bigint(20) unsigned NOT NULL,
			client_id bigint(20) unsigned NOT NULL,
			category_id bigint(20) unsigned NOT NULL,
			amount_minor bigint(20) unsigned NOT NULL,
			currency char(3) NOT NULL DEFAULT 'GBP',
			status varchar(30) NOT NULL DEFAULT 'reported',
			bank_date date DEFAULT NULL,
			bank_reference varchar(191) NOT NULL DEFAULT '',
			idempotency_key varchar(64) NOT NULL,
			verified_by bigint(20) unsigned DEFAULT NULL,
			verified_at datetime DEFAULT NULL,
			reversed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY invoice_status (invoice_id,status),
			KEY client_id (client_id),
			KEY category_verified (category_id,verified_at)
		) ENGINE=InnoDB $collate;", "CREATE TABLE {$t['receipts']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			payment_id bigint(20) unsigned NOT NULL,
			invoice_id bigint(20) unsigned NOT NULL,
			client_id bigint(20) unsigned NOT NULL,
			category_id bigint(20) unsigned NOT NULL,
			number varchar(80) NOT NULL,
			amount_minor bigint(20) unsigned NOT NULL,
			currency char(3) NOT NULL DEFAULT 'GBP',
			snapshot longtext NOT NULL,
			private_file_key varchar(191) DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY payment_id (payment_id),
			UNIQUE KEY number (number),
			KEY invoice_id (invoice_id),
			KEY client_id (client_id)
		) ENGINE=InnoDB $collate;", "CREATE TABLE {$t['audit']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actor_id bigint(20) unsigned NOT NULL,
			event varchar(80) NOT NULL,
			entity_type varchar(40) NOT NULL,
			entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
			metadata longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY entity (entity_type,entity_id),
			KEY created_at (created_at)
		) ENGINE=InnoDB $collate;", "CREATE TABLE {$t['email_jobs']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entity_type varchar(40) NOT NULL,
			entity_id bigint(20) unsigned NOT NULL,
			template varchar(80) NOT NULL,
			dedupe_key varchar(191) NOT NULL,
			status varchar(30) NOT NULL DEFAULT 'held',
			attempts int(11) unsigned NOT NULL DEFAULT 0,
			payload longtext NOT NULL,
			authorised_by bigint(20) unsigned DEFAULT NULL,
			authorised_at datetime DEFAULT NULL,
			sent_at datetime DEFAULT NULL,
			next_attempt_at datetime DEFAULT NULL,
			last_error text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY dedupe_key (dedupe_key),
			KEY status_attempt (status,next_attempt_at)
		) ENGINE=InnoDB $collate;", );
}
/** Versioned, additive migration. No legacy records are imported or interpreted as verified money. */
function dxbs_install() {
	global $wpdb;
	if ((int)get_option('dxbs_schema_version', 0) >= DXBS_SCHEMA_VERSION) {
		return true;
	}
	$lock = (int)get_option('dxbs_schema_lock', 0);
	if ($lock && $lock > time() - 120) {
		return dxbs_error('migration_busy', 'A Business database update is already running. Try again shortly.');
	}
	if ($lock) {
		delete_option('dxbs_schema_lock');
	}
	if (!add_option('dxbs_schema_lock', time(), '', false)) {
		return dxbs_error('migration_busy', 'A Business database update is already running.');
	}
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	try {
		if ((int)get_option('dxbs_schema_version', 0) < 1) {
			foreach (dxbs_schema_tables() as $sql) {
				$wpdb->last_error = '';
				dbDelta($sql);
				if ($wpdb->last_error) {
					return dxbs_error('migration_failed', 'The Business tables could not be updated. No existing client portal records were changed.');
				}
			}
			$now = dxbs_now();
			foreach (array('digital' => 'Digital', 'fitness' => 'Fitness', 'photography' => 'Photography') as $slug => $name) {
				$table = dxbs_table('categories');
				$wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table (slug,name,description,accent,active,sort_order,created_at,updated_at) VALUES (%s,%s,'','#C9A44D',1,%d,%s,%s)", $slug, $name, array_search($slug, array('digital', 'fitness', 'photography'), true) * 10, $now, $now));
			}
			if ($wpdb->last_error) {
				return dxbs_error('migration_failed', 'The Business service categories could not be installed.');
			}
			update_option('dxbs_schema_version', 1, false);
			add_option('dxbs_settings', dxbs_settings_defaults(), '', false);
		}
		if ((int)get_option('dxbs_schema_version', 0) < 2) {
			foreach (dxbs_schema_v2() as $sql) {
				$wpdb->last_error = '';
				$wpdb->query($sql);
				if ($wpdb->last_error) return dxbs_error('migration_failed', 'The Business financial schema could not be updated.');
			}
			update_option('dxbs_schema_version', 2, false);
		}
		return true;
	}
	finally {
		delete_option('dxbs_schema_lock');
	}
}
/** Additive V2 migration; checks every column so an interrupted run is safe to retry. */
function dxbs_schema_v2() {
	global $wpdb;
	$sql = [];
	$cols = ['quotes' => ['title' => "varchar(191) NOT NULL DEFAULT ''", 'draft_data' => 'longtext NULL', 'supply_date' => 'date NULL', 'accepted_by' => 'bigint(20) unsigned NULL', 'accepted_version' => 'bigint(20) unsigned NULL', 'converted_at' => 'datetime NULL', 'issued_by' => 'bigint(20) unsigned NULL'], 'invoices' => ['title' => "varchar(191) NOT NULL DEFAULT ''", 'draft_data' => 'longtext NULL', 'supply_date' => 'date NULL', 'issued_by' => 'bigint(20) unsigned NULL', 'payment_reference' => "varchar(80) NULL"], 'payments' => ['report_id' => 'bigint(20) unsigned NULL', 'verification_note' => 'text NULL'], 'receipts' => ['voided_at' => 'datetime NULL', 'void_reason' => 'text NULL'], ];
	foreach ($cols as $name => $fields) {
		$table = dxbs_table($name);
		$existing = $wpdb->get_col("SHOW COLUMNS FROM $table");
		foreach ($fields as $field => $def) if (!in_array($field, $existing, true))$sql[] = "ALTER TABLE $table ADD COLUMN $field $def";
	}
	$c = $wpdb->get_charset_collate();
	$sql[] = "CREATE TABLE IF NOT EXISTS " . dxbs_table('archives') . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT,entity_type varchar(20) NOT NULL,entity_id bigint(20) unsigned NOT NULL,version bigint(20) unsigned NOT NULL,file_key varchar(191) NOT NULL,sha256 char(64) NOT NULL,created_at datetime NOT NULL,PRIMARY KEY (id),UNIQUE KEY entity_version (entity_type,entity_id,version)) ENGINE=InnoDB $c";
	$sql[] = "CREATE TABLE IF NOT EXISTS " . dxbs_table('sequences') . " (series varchar(40) NOT NULL,last_number bigint(20) unsigned NOT NULL DEFAULT 0,PRIMARY KEY (series)) ENGINE=InnoDB $c";
	$sql[] = "CREATE TABLE IF NOT EXISTS " . dxbs_table('access_tokens') . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT,entity_type varchar(20) NOT NULL,entity_id bigint(20) unsigned NOT NULL,token_hash char(64) NOT NULL,created_by bigint(20) unsigned NOT NULL,created_at datetime NOT NULL,expires_at datetime NOT NULL,revoked_at datetime NULL,PRIMARY KEY (id),UNIQUE KEY token_hash (token_hash),KEY entity (entity_type,entity_id)) ENGINE=InnoDB $c";
	$sql[] = "CREATE TABLE IF NOT EXISTS " . dxbs_table('payment_intents') . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT,token_hash char(64) NOT NULL,actor_id bigint(20) unsigned NOT NULL,invoice_id bigint(20) unsigned NOT NULL,payload longtext NOT NULL,status varchar(20) NOT NULL DEFAULT 'pending',payment_id bigint(20) unsigned NULL,created_at datetime NOT NULL,expires_at datetime NOT NULL,PRIMARY KEY (id),UNIQUE KEY token_hash (token_hash)) ENGINE=InnoDB $c";
	$sql[] = "CREATE TABLE IF NOT EXISTS " . dxbs_table('credit_notes') . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT,invoice_id bigint(20) unsigned NOT NULL,client_id bigint(20) unsigned NOT NULL,category_id bigint(20) unsigned NOT NULL,number varchar(80) NOT NULL,status varchar(20) NOT NULL DEFAULT 'issued',subtotal_minor bigint(20) unsigned NOT NULL,tax_minor bigint(20) unsigned NOT NULL,total_minor bigint(20) unsigned NOT NULL,snapshot longtext NOT NULL,supply_date date NOT NULL,created_by bigint(20) unsigned NOT NULL,created_at datetime NOT NULL,PRIMARY KEY (id),UNIQUE KEY number (number),KEY invoice_id (invoice_id)) ENGINE=InnoDB $c";
	return $sql;
}
