<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Append-only internal history. Values are deliberately omitted; only changed field names are logged. */
function dxbs_audit( $event, $type, $id, $metadata = array() ) {
	global $wpdb;
	if ( ! dxbs_is_manager() ) {
		return false;
	}
	$safe = array();
	if ( isset( $metadata['fields'] ) && is_array( $metadata['fields'] ) ) {
		$safe['fields'] = array_values( array_map( 'sanitize_key', $metadata['fields'] ) );
	}
	if ( isset( $metadata['version'] ) ) {
		$safe['version'] = absint( $metadata['version'] );
	}
	return false !== $wpdb->insert( dxbs_table( 'audit' ), array( 'actor_id' => get_current_user_id(), 'event' => sanitize_key( $event ), 'entity_type' => sanitize_key( $type ), 'entity_id' => absint( $id ), 'metadata' => wp_json_encode( $safe ), 'created_at' => dxbs_now() ), array( '%d', '%s', '%s', '%d', '%s', '%s' ) );
}
function dxbs_audit_rows( $limit = 20 ) {
	global $wpdb;
	if ( ! dxbs_is_manager() ) {
		return array();
	}
	$table = dxbs_table( 'audit' );
	return $wpdb->get_results( $wpdb->prepare( "SELECT id,actor_id,event,entity_type,entity_id,metadata,created_at FROM $table ORDER BY id DESC LIMIT %d", min( 100, max( 1, (int) $limit ) ) ), ARRAY_A );
}
function dxbs_client_row( $row ) {
	global $wpdb;
	if ( ! $row ) {
		return null;
	}
	foreach ( array( 'id', 'version' ) as $key ) {
		$row[ $key ] = (int) $row[ $key ];
	}
	$row['wp_user_id'] = $row['wp_user_id'] ? (int) $row['wp_user_id'] : null;
	$table = dxbs_table( 'client_categories' );
	$row['category_ids'] = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT category_id FROM $table WHERE client_id = %d ORDER BY category_id", $row['id'] ) ) );
	return $row;
}
function dxbs_client( $id ) {
	global $wpdb;
	if ( ! dxbs_is_manager() ) {
		return null;
	}
	$table = dxbs_table( 'clients' );
	return dxbs_client_row( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", absint( $id ) ), ARRAY_A ) );
}
function dxbs_clients( $args = array() ) {
	global $wpdb;
	if ( ! dxbs_is_manager() ) {
		return array();
	}
	$args = wp_parse_args( $args, array( 'search' => '', 'category_id' => 0, 'status' => 'active', 'orderby' => 'name', 'order' => 'ASC', 'limit' => 100, 'offset' => 0 ) );
	$table = dxbs_table( 'clients' );
	$links = dxbs_table( 'client_categories' );
	$where = array( '1=1' );
	if ( in_array( $args['status'], array( 'active', 'archived' ), true ) ) {
		$where[] = $wpdb->prepare( 'c.status = %s', $args['status'] );
	} elseif ( 'all' !== $args['status'] ) {
		$where[] = "c.status = 'active'";
	}
	if ( $args['search'] !== '' ) {
		$term = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
		$where[] = $wpdb->prepare( '(c.name LIKE %s OR c.company LIKE %s OR c.email LIKE %s)', $term, $term, $term );
	}
	if ( absint( $args['category_id'] ) ) {
		$where[] = $wpdb->prepare( "EXISTS (SELECT 1 FROM $links cc WHERE cc.client_id = c.id AND cc.category_id = %d)", absint( $args['category_id'] ) );
	}
	$orderby = in_array( $args['orderby'], array( 'name', 'created_at', 'updated_at' ), true ) ? $args['orderby'] : 'name';
	$order = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';
	$sql = "SELECT c.* FROM $table c WHERE " . implode( ' AND ', $where ) . " ORDER BY c.$orderby $order,c.id $order";
	$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', min( 200, max( 1, (int) $args['limit'] ) ), max( 0, (int) $args['offset'] ) );
	return array_map( 'dxbs_client_row', $wpdb->get_results( $sql, ARRAY_A ) );
}
/** Explicit account import only: prefills editable fields, but does not write or merge records. */
function dxbs_client_from_user( $user_id ) {
	if ( ! dxbs_is_manager() || ! ( $user = get_userdata( absint( $user_id ) ) ) ) {
		return null;
	}
	return array( 'wp_user_id' => (int) $user->ID, 'name' => $user->display_name, 'email' => strtolower( $user->user_email ), 'company' => sanitize_text_field( get_user_meta( $user->ID, '_dxcp_company', true ) ?: get_user_meta( $user->ID, 'company', true ) ), 'phone' => sanitize_text_field( get_user_meta( $user->ID, '_dxcp_phone', true ) ) );
}
function dxbs_save_client( $input ) {
	global $wpdb;
	if ( ! dxbs_is_manager() ) {
		return dxbs_require_manager();
	}
	if ( ! is_array( $input ) ) {
		return dxbs_error( 'invalid_client', 'Client details are required.' );
	}
	foreach ( $input as $key => $value ) {
		if ( ! is_scalar( $value ) && null !== $value && 'category_ids' !== $key ) {
			return dxbs_error( 'invalid_field', 'A client field has an invalid format.' );
		}
	}
	if ( isset( $input['category_ids'] ) && ( ! is_array( $input['category_ids'] ) || count( array_filter( $input['category_ids'], 'is_scalar' ) ) !== count( $input['category_ids'] ) ) ) {
		return dxbs_error( 'invalid_category', 'Choose valid service categories.' );
	}
	$id = absint( $input['id'] ?? 0 );
	$old = $id ? dxbs_client( $id ) : null;
	if ( $id && ! $old ) {
		return dxbs_error( 'missing_client', 'This client no longer exists.' );
	}
	if ( $old && absint( $input['version'] ?? 0 ) !== $old['version'] ) {
		return dxbs_error( 'stale_client', 'This client was changed elsewhere. Refresh and review the latest details.' );
	}
	$data = array();
	$lengths = array( 'name' => 191, 'company' => 191, 'phone' => 80, 'billing_address_1' => 191, 'billing_address_2' => 191, 'billing_city' => 120, 'billing_region' => 120, 'billing_postcode' => 32 );
	foreach ( $lengths as $key => $max ) {
		$data[ $key ] = sanitize_text_field( $input[ $key ] ?? '' );
		if ( mb_strlen( $data[ $key ] ) > $max ) {
			return dxbs_error( 'client_field_length', 'One of the client fields is too long.' );
		}
	}
	$data['email'] = strtolower( trim( (string) ( $input['email'] ?? '' ) ) );
	if ( ! $data['name'] || ! is_email( $data['email'] ) || strlen( $data['email'] ) > 191 ) {
		return dxbs_error( 'invalid_client', 'Enter a client name and valid email address.' );
	}
	$data['billing_country'] = strtoupper( trim( (string) ( $input['billing_country'] ?? '' ) ) );
	if ( $data['billing_country'] !== '' && ! preg_match( '/^[A-Z]{2}$/D', $data['billing_country'] ) ) {
		return dxbs_error( 'invalid_country', 'Use a two-letter billing country code, for example GB.' );
	}
	$data['notes'] = sanitize_textarea_field( $input['notes'] ?? '' );
	if ( strlen( $data['notes'] ) > 20000 ) {
		return dxbs_error( 'notes_length', 'Internal notes must be shorter than 20,000 characters.' );
	}
	$data['status'] = in_array( $input['status'] ?? 'active', array( 'active', 'archived' ), true ) ? $input['status'] ?? 'active' : 'active';
	$data['wp_user_id'] = absint( $input['wp_user_id'] ?? 0 ) ?: null;
	if ( $data['wp_user_id'] && ! get_userdata( $data['wp_user_id'] ) ) {
		return dxbs_error( 'invalid_account', 'Choose an existing WordPress account or leave the account blank.' );
	}
	$category_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $input['category_ids'] ?? array() ) ) ) ) );
	$known_ids = array_column( dxbs_categories( false ), 'id' );
	foreach ( $category_ids as $category_id ) {
		if ( ! in_array( $category_id, $known_ids, true ) ) {
			return dxbs_error( 'invalid_category', 'Choose an existing service category.' );
		}
	}
	$table = dxbs_table( 'clients' );
	$links = dxbs_table( 'client_categories' );
	$dupe = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE email = %s AND id <> %d LIMIT 1", $data['email'], $id ) );
	if ( $dupe ) {
		return dxbs_error( 'duplicate_email', 'A client with this email already exists. Edit that record instead.' );
	}
	if ( $data['wp_user_id'] && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE wp_user_id = %d AND id <> %d LIMIT 1", $data['wp_user_id'], $id ) ) ) {
		return dxbs_error( 'duplicate_account', 'This WordPress account is already linked to another client.' );
	}
	$data['version'] = $old ? $old['version'] + 1 : 1;
	$data['updated_at'] = dxbs_now();
	$wpdb->query( 'START TRANSACTION' );
	if ( $old ) {
		$result = $wpdb->update( $table, $data, array( 'id' => $id, 'version' => $old['version'] ) );
	} else {
		$data['created_at'] = $data['updated_at'];
		$result = $wpdb->insert( $table, $data );
		$id = (int) $wpdb->insert_id;
	}
	if ( ! $result ) {
		$wpdb->query( 'ROLLBACK' );
		return dxbs_error( 'client_save_failed', 'The client could not be saved. Check for a duplicate account/email or refresh the record.' );
	}
	if ( false === $wpdb->delete( $links, array( 'client_id' => $id ), array( '%d' ) ) ) {
		$wpdb->query( 'ROLLBACK' );
		return dxbs_error( 'client_save_failed', 'The client service categories could not be saved.' );
	}
	foreach ( $category_ids as $category_id ) {
		if ( false === $wpdb->insert( $links, array( 'client_id' => $id, 'category_id' => $category_id ), array( '%d', '%d' ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return dxbs_error( 'client_save_failed', 'The client service categories could not be saved.' );
		}
	}
	$fields = array_keys( $data );
	$fields[] = 'category_ids';
	if ( ! dxbs_audit( $old ? 'client_updated' : 'client_created', 'client', $id, array( 'fields' => $fields, 'version' => $data['version'] ) ) ) {
		$wpdb->query( 'ROLLBACK' );
		return dxbs_error( 'audit_failed', 'The audit record could not be saved, so the client change was not applied.' );
	}
	$wpdb->query( 'COMMIT' );
	return $id;
}
function dxbs_categories( $active = true ) {
	global $wpdb;
	if ( ! dxbs_is_manager() ) {
		return array();
	}
	$table = dxbs_table( 'categories' );
	$rows = $wpdb->get_results( "SELECT * FROM $table" . ( $active ? ' WHERE active = 1' : '' ) . ' ORDER BY sort_order,name,id', ARRAY_A );
	foreach ( $rows as &$row ) {
		foreach ( array( 'id', 'sort_order', 'version' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		$row['active'] = (bool) $row['active'];
	}
	return $rows;
}
function dxbs_category_for_service( $service ) {
	$map = apply_filters( 'dxbs_legacy_service_categories', array( 'digital' => 'digital', 'support' => 'digital', 'fitness' => 'fitness', 'photography' => 'photography' ) );
	$slug = sanitize_title( $map[ $service ] ?? $service );
	foreach ( dxbs_categories( false ) as $category ) {
		if ( $category['slug'] === $slug ) {
			return $category;
		}
	}
	return null;
}
function dxbs_save_category( $input ) {
	global $wpdb;
	if ( ! dxbs_is_manager() ) {
		return dxbs_require_manager();
	}
	if ( ! is_array( $input ) ) {
		return dxbs_error( 'invalid_category', 'Service category details are required.' );
	}
	foreach ( $input as $value ) {
		if ( ! is_scalar( $value ) && null !== $value ) {
			return dxbs_error( 'invalid_category', 'A category field has an invalid format.' );
		}
	}
	$table = dxbs_table( 'categories' );
	$id = absint( $input['id'] ?? 0 );
	$old = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A ) : null;
	if ( $id && ! $old ) {
		return dxbs_error( 'missing_category', 'This service category no longer exists.' );
	}
	if ( $old && absint( $input['version'] ?? 0 ) !== (int) $old['version'] ) {
		return dxbs_error( 'stale_category', 'This category changed elsewhere. Refresh before editing it.' );
	}
	$name = sanitize_text_field( $input['name'] ?? '' );
	$slug = sanitize_title( $input['slug'] ?? $name );
	if ( ! $name || mb_strlen( $name ) > 191 || ! $slug || strlen( $slug ) > 80 ) {
		return dxbs_error( 'invalid_category', 'Enter a category name and a short unique slug.' );
	}
	if ( $old && $slug !== $old['slug'] ) {
		return dxbs_error( 'category_slug_locked', 'Category identifiers stay fixed to preserve existing project and financial associations.' );
	}
	$accent = sanitize_hex_color( $input['accent'] ?? '#C9A44D' );
	if ( ! $accent ) {
		return dxbs_error( 'invalid_accent', 'Enter a valid hex colour.' );
	}
	$data = array( 'name' => $name, 'slug' => $slug, 'description' => sanitize_textarea_field( $input['description'] ?? '' ), 'accent' => $accent, 'active' => ! empty( $input['active'] ) ? 1 : 0, 'sort_order' => max( -9999, min( 9999, (int) ( $input['sort_order'] ?? 0 ) ) ), 'version' => $old ? (int) $old['version'] + 1 : 1, 'updated_at' => dxbs_now() );
	if ( strlen( $data['description'] ) > 2000 ) {
		return dxbs_error( 'category_description_length', 'The category description must be shorter than 2,000 characters.' );
	}
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE slug = %s AND id <> %d", $slug, $id ) ) ) {
		return dxbs_error( 'duplicate_category', 'This category identifier is already in use.' );
	}
	$wpdb->query( 'START TRANSACTION' );
	if ( $old ) {
		$result = $wpdb->update( $table, $data, array( 'id' => $id, 'version' => (int) $old['version'] ) );
	} else {
		$data['created_at'] = $data['updated_at'];
		$result = $wpdb->insert( $table, $data );
		$id = (int) $wpdb->insert_id;
	}
	if ( ! $result || ! dxbs_audit( $old ? 'category_updated' : 'category_created', 'category', $id, array( 'fields' => array_keys( $data ), 'version' => $data['version'] ) ) ) {
		$wpdb->query( 'ROLLBACK' );
		return dxbs_error( 'category_save_failed', 'The service category could not be saved. Refresh before trying again.' );
	}
	$wpdb->query( 'COMMIT' );
	return $id;
}
/** Phase 1 reports only authoritative Business records; the legacy portal payment ledger is not queried. */
function dxbs_overview() {
	global $wpdb;
	if ( ! dxbs_is_manager() ) {
		return array();
	}
	$clients = dxbs_table( 'clients' );
	$quotes = dxbs_table( 'quotes' );
	$invoices = dxbs_table( 'invoices' );
	$payments = dxbs_table( 'payments' );
	$out = array( 'client_count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $clients WHERE status = 'active'" ), 'category_count' => count( dxbs_categories() ), 'revenue_received_minor' => 0, 'outstanding_minor' => 0, 'pending_quotations' => 0, 'overdue_invoices' => 0, 'recent_payments' => array(), 'upcoming_deadlines' => array(), 'revenue_by_category' => array(), 'financial_workflows_enabled' => true );
	$out['revenue_received_minor'] = (int) $wpdb->get_var( "SELECT COALESCE(SUM(p.amount_minor),0) FROM $payments p INNER JOIN $invoices i ON i.id = p.invoice_id WHERE p.status = 'verified' AND p.verified_at IS NOT NULL AND p.verified_by > 0 AND p.reversed_at IS NULL AND p.currency = 'GBP'" );
	// Do not derive money owed from draft documents, or from client-reported transfers.
	$balance_sql = "SELECT i.id,i.number,i.client_id,i.category_id,i.total_minor,i.due_date,GREATEST(0,CAST(i.total_minor AS SIGNED) - COALESCE(SUM(CASE WHEN p.status = 'verified' AND p.verified_at IS NOT NULL AND p.verified_by > 0 AND p.reversed_at IS NULL AND p.currency = i.currency THEN p.amount_minor ELSE 0 END),0)) AS balance_minor FROM $invoices i LEFT JOIN $payments p ON p.invoice_id = i.id WHERE i.issued_at IS NOT NULL AND i.voided_at IS NULL AND i.status NOT IN ('draft','void','cancelled') AND i.currency = 'GBP' GROUP BY i.id";
	$balances = $wpdb->get_results( $balance_sql, ARRAY_A );
	foreach ( $balances as $row ) {
		$balance = (int) $row['balance_minor'];
		$out['outstanding_minor'] += $balance;
		if ( $balance > 0 && $row['due_date'] && $row['due_date'] < current_time( 'Y-m-d' ) ) {
			++$out['overdue_invoices'];
		}
	}
	$out['pending_quotations'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $quotes WHERE issued_at IS NOT NULL AND status IN ('sent','awaiting_acceptance') AND accepted_at IS NULL" );
	$categories = dxbs_table( 'categories' );
	$verified_where = "p.status = 'verified' AND p.verified_at IS NOT NULL AND p.verified_by > 0 AND p.reversed_at IS NULL AND p.currency = 'GBP'";
	$out['recent_payments'] = $wpdb->get_results( "SELECT p.id,p.invoice_id,i.number AS invoice_number,p.client_id,c.name AS client_name,p.category_id,s.name AS category_name,p.amount_minor,p.bank_date,p.verified_at FROM $payments p INNER JOIN $invoices i ON i.id = p.invoice_id LEFT JOIN $clients c ON c.id = p.client_id LEFT JOIN $categories s ON s.id = p.category_id WHERE $verified_where ORDER BY p.verified_at DESC,p.id DESC LIMIT 10", ARRAY_A );
	foreach ( $out['recent_payments'] as &$payment ) {
		foreach ( array( 'id', 'invoice_id', 'client_id', 'category_id', 'amount_minor' ) as $key ) {
			$payment[ $key ] = (int) $payment[ $key ];
		}
	}
	unset( $payment );
	$out['revenue_by_category'] = $wpdb->get_results( "SELECT p.category_id,COALESCE(s.name,'Unassigned category') AS name,SUM(p.amount_minor) AS received_minor FROM $payments p INNER JOIN $invoices i ON i.id = p.invoice_id LEFT JOIN $categories s ON s.id = p.category_id WHERE $verified_where GROUP BY p.category_id,s.name ORDER BY received_minor DESC,p.category_id", ARRAY_A );
	foreach ( $out['revenue_by_category'] as &$category ) {
		$category['category_id'] = (int) $category['category_id'];
		$category['received_minor'] = (int) $category['received_minor'];
	}
	unset( $category );
	$out['upcoming_deadlines'] = $wpdb->get_results( $wpdb->prepare( "SELECT b.id AS invoice_id,b.number,b.client_id,c.name AS client_name,b.category_id,s.name AS category_name,b.due_date,b.balance_minor FROM ($balance_sql) b LEFT JOIN $clients c ON c.id = b.client_id LEFT JOIN $categories s ON s.id = b.category_id WHERE b.balance_minor > 0 AND b.due_date >= %s ORDER BY b.due_date,b.id LIMIT 10", current_time( 'Y-m-d' ) ), ARRAY_A );
	foreach ( $out['upcoming_deadlines'] as &$deadline ) {
		foreach ( array( 'invoice_id', 'client_id', 'category_id', 'balance_minor' ) as $key ) {
			$deadline[ $key ] = (int) $deadline[ $key ];
		}
	}
	unset( $deadline );
	if ( function_exists( 'dxbs_reports' ) ) {
		$report = dxbs_reports();
		$out['revenue_received_minor'] = $report['revenue_received_minor'];
		$out['outstanding_minor'] = $report['outstanding_minor'];
		$out['revenue_by_category'] = $report['categories'];
		$out['overdue_invoices'] = 0;
		$out['upcoming_deadlines'] = array();
		foreach ( $report['invoices'] as $invoice ) {
			if ( (int) $invoice['balance_minor'] > 0 && $invoice['due_date'] < wp_date( 'Y-m-d' ) ) ++$out['overdue_invoices'];
			if ( (int) $invoice['balance_minor'] > 0 && $invoice['due_date'] >= wp_date( 'Y-m-d' ) && count( $out['upcoming_deadlines'] ) < 10 ) $out['upcoming_deadlines'][] = array_merge( $invoice, array( 'invoice_id' => (int) $invoice['id'] ) );
		}
	}
	return $out;
}
