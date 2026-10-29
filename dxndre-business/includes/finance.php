<?php
/** Authoritative financial state transitions. All money remains integer pence. */
defined('ABSPATH') || exit;
define('DXBS_FIN_MAX', 1000000000);
function dxbs_rollback($code, $message) {
	global $wpdb;
	$wpdb->query('ROLLBACK');
	return dxbs_error($code, $message);
}
function dxbs_account_client_id($uid = 0) {
	global $wpdb;
	$uid = $uid ? : get_current_user_id();
	if (!$uid || ($uid !== get_current_user_id() && !dxbs_is_manager())) return 0;
	return (int)$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . dxbs_table('clients') . ' WHERE wp_user_id=%d', $uid));
}
function dxbs_type_table($type) {
	return ['quote' => 'quotes', 'invoice' => 'invoices', 'receipt' => 'receipts', 'credit' => 'credit_notes'][$type] ?? '';
}
function dxbs_record_raw($type, $id, $lock = false) {
	global $wpdb;
	$name = dxbs_type_table($type);
	if (!$name) return null;
	return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . dxbs_table($name) . ' WHERE id=%d' . ($lock ? ' FOR UPDATE' : ''), absint($id)), ARRAY_A);
}
function dxbs_can_read_financial($type, $row) {
	if (!$row) return false;
	if (dxbs_is_manager()) return true;
	$cid = dxbs_account_client_id();
	if (!$cid || (int)$row['client_id'] !== $cid) return false;
	return in_array($type, ['receipt', 'credit'], true) || !empty($row['issued_at']);
}
function dxbs_document($type, $id) {
	global $wpdb;
	$row = dxbs_record_raw($type, $id);
	if (!dxbs_can_read_financial($type, $row)) return null;
	foreach (['id', 'client_id', 'category_id', 'project_id', 'quote_id', 'invoice_id', 'payment_id', 'version', 'total_minor', 'subtotal_minor', 'tax_minor', 'discount_minor', 'deposit_minor'] as $key) if (isset($row[$key]))$row[$key] = (int)$row[$key];
	$row['snapshot'] = json_decode($row['snapshot'] ?? '{}', true) ? : [];
	$row['draft'] = json_decode($row['draft_data'] ?? '{}', true) ? : [];
	$row['lines'] = [];
	if (in_array($type, ['quote', 'invoice'], true))$row['lines'] = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . dxbs_table($type . '_lines') . ' WHERE ' . $type . '_id=%d ORDER BY position,id', $id), ARRAY_A);
	else $row['lines'] = $row['snapshot']['lines'] ?? [];
	$client = $wpdb->get_row($wpdb->prepare('SELECT name,company FROM ' . dxbs_table('clients') . ' WHERE id=%d', $row['client_id']), ARRAY_A);
	$row['client_name'] = $row['snapshot']['client']['name'] ?? $client['name'] ?? '';
	$row['category_name'] = $row['snapshot']['category']['name'] ?? $wpdb->get_var($wpdb->prepare('SELECT name FROM ' . dxbs_table('categories') . ' WHERE id=%d', $row['category_id']));
	if ($type === 'invoice') {
		$balance = dxbs_invoice_balance($id);
		$row = array_merge($row, $balance);
		$row['status'] = dxbs_invoice_status($row, $balance);
	}
	if ($type === 'receipt')$row['status'] = 'issued';
	if ($type === 'quote' && $row['status'] === 'sent' && $row['expires_at'] < dxbs_now())$row['status'] = 'expired';
	if (!empty($row['voided_at']))$row['status'] = 'void';
	$row['status_label'] = ucwords(str_replace('_', ' ', $row['status'] ?? 'issued'));
	$row['document_url'] = dxbs_document_url($type, $id);
	return $row;
}
function dxbs_status_filter($type, $status, $alias = 'd') {
	global $wpdb;
	if ($status === 'all' || $status === '') return '1=1';
	if ($type === 'receipt') return $status === 'void' ? "$alias.voided_at IS NOT NULL" : ($status === 'issued' ? "$alias.voided_at IS NULL" : '1=0');
	if ($type === 'credit') return $wpdb->prepare("$alias.status=%s", $status);
	if ($type === 'quote') {
		if ($status === 'expired') return $wpdb->prepare("$alias.status='sent' AND $alias.expires_at<%s", dxbs_now());
		if ($status === 'sent') return $wpdb->prepare("$alias.status='sent' AND $alias.expires_at>=%s", dxbs_now());
		return $wpdb->prepare("$alias.status=%s", $status);
	}
	if ($type !== 'invoice') return '1=0';
	$p = dxbs_table('payments');
	$c = dxbs_table('credit_notes');
	$paid = "COALESCE((SELECT SUM(pp.amount_minor) FROM $p pp WHERE pp.invoice_id=$alias.id AND pp.status='verified' AND pp.verified_at IS NOT NULL AND pp.verified_by>0 AND pp.reversed_at IS NULL AND pp.currency='GBP'),0)";
	$credit = "COALESCE((SELECT SUM(cc.total_minor) FROM $c cc WHERE cc.invoice_id=$alias.id AND cc.status='issued'),0)";
	$balance = "GREATEST(0,CAST($alias.total_minor AS SIGNED)-$paid-$credit)";
	$issued = "$alias.issued_at IS NOT NULL AND $alias.voided_at IS NULL AND $alias.status NOT IN ('void','cancelled')";
	if ($status === 'draft') return "$alias.issued_at IS NULL AND $alias.status='draft'";
	if ($status === 'void' || $status === 'cancelled') return "($alias.voided_at IS NOT NULL OR $alias.status IN ('void','cancelled'))";
	if ($status === 'paid') return "$issued AND $balance=0 AND ($credit=0 OR $paid>=$alias.total_minor)";
	if ($status === 'credited') return "$issued AND $balance=0 AND $credit>0 AND $paid<$alias.total_minor";
	if ($status === 'partially_paid') return "$issued AND $balance>0 AND $paid>0";
	if ($status === 'overdue') return $wpdb->prepare("$issued AND $balance>0 AND $alias.due_date<%s", dxbs_today());
	if ($status === 'awaiting_payment' || $status === 'sent') return $wpdb->prepare("$issued AND $balance>0 AND $paid=0 AND $alias.due_date>=%s", dxbs_today());
	return '1=0';
}
function dxbs_financial_documents($type, $args = []) {
	global $wpdb;
	$table = dxbs_type_table($type);
	if (!$table) return [];
	$manager = dxbs_is_manager();
	$cid = $manager ? absint($args['client_id'] ?? 0) : dxbs_account_client_id();
	if (!$manager && !$cid) return [];
	$table = dxbs_table($table);
	$where = ['1=1'];
	if ($cid)$where[] = $wpdb->prepare('d.client_id=%d', $cid);
	if (!$manager && in_array($type, ['quote', 'invoice'], true))$where[] = 'd.issued_at IS NOT NULL';
	if (!empty($args['category_id']))$where[] = $wpdb->prepare('d.category_id=%d', absint($args['category_id']));
	if (!empty($args['status']) && $args['status'] !== 'all')$where[] = dxbs_status_filter($type, sanitize_key($args['status']));
	if (!empty($args['search'])) {
		$term = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
		$where[] = $wpdb->prepare('(d.number LIKE %s OR c.name LIKE %s)', $term, $term);
	}
	foreach (['from' => '>=', 'to' => '<='] as $key => $op) if (!empty($args[$key]) && dxbs_date($args[$key]))$where[] = $wpdb->prepare("DATE(d.created_at) $op %s", $args[$key]);
	$orderby = ['date' => 'd.created_at', 'number' => 'd.number', 'total' => 'd.total_minor'][$args['orderby'] ?? 'date'] ?? 'd.created_at';
	if ($type === 'receipt' && ($args['orderby'] ?? '') === 'total')$orderby = 'd.amount_minor';
	$order = strtoupper($args['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
	$ids = $wpdb->get_col($wpdb->prepare("SELECT d.id FROM $table d LEFT JOIN " . dxbs_table('clients') . " c ON c.id=d.client_id WHERE " . implode(' AND ', $where) . " ORDER BY $orderby $order,d.id $order LIMIT %d", min(200, max(1, (int)($args['limit'] ?? 100)))));
	$out = [];
	foreach ($ids as $id) {
		$doc = dxbs_document($type, $id);
		if ($doc)$out[] = $doc;
	}
	return $out;
}
function dxbs_client_documents($client_id, $type = 'invoice') {
	if (!dxbs_is_manager() && (int)$client_id !== dxbs_account_client_id()) return [];
	return dxbs_financial_documents($type, ['client_id' => (int)$client_id, 'limit' => 200]);
}
function dxbs_today() {
	return wp_date('Y-m-d', null, new DateTimeZone('Europe/London'));
}
function dxbs_date($date) {
	if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) return '';
	$d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Europe/London'));
	return $d && $d->format('Y-m-d') === $date ? $date : '';
}
function dxbs_quantity($value) {
	if (!is_scalar($value) || !preg_match('/^(0|[1-9][0-9]{0,3})(?:\.([0-9]{1,3}))?$/D', (string)$value, $m)) return dxbs_error('quantity', 'Enter a quantity with up to three decimal places.');
	$n = (int)$m[1] * 1000 + (int)str_pad($m[2] ?? '', 3, '0');
	return $n > 0 && $n <= 1000000 ? $n : dxbs_error('quantity', 'Quantity must be between 0.001 and 1000.');
}
function dxbs_document_calculate($data, $settings) {
	$rows = $data['lines'] ?? [];
	if (is_array($rows))$rows = array_values(array_filter($rows, fn($l) => !is_array($l) || trim((string)($l['description'] ?? '')) !== '' || trim((string)($l['unit_price'] ?? '')) !== ''));
	if (!is_array($rows) || count($rows) < 1 || count($rows) > 100) return dxbs_error('lines', 'Add between one and 100 line items.');
	$lines = [];
	$subtotal = 0;
	foreach ($rows as $row) {
		if (!is_array($row)) return dxbs_error('line', 'Invalid line item.');
		$desc = sanitize_textarea_field($row['description'] ?? '');
		$q = dxbs_quantity($row['quantity'] ?? '1');
		$unit = dxbs_minor_units($row['unit_price'] ?? '');
		$tax = (string)($row['tax_basis_points'] ?? ($settings['vat_registered'] ? $settings['default_vat_basis_points'] : 0));
		if (!$desc || strlen($desc) > 2000 || is_wp_error($q) || is_wp_error($unit) || !preg_match('/^\d{1,5}$/D', $tax) || (int)$tax > 10000 || (!$settings['vat_registered'] && (int)$tax)) return dxbs_error('line', 'Check the line description, quantity, price and VAT rate. VAT requires business VAT registration.');
		$net = dxbs_line_total($unit, $q);
		if (is_wp_error($net) || $net > DXBS_FIN_MAX) return dxbs_error('limit', 'This line exceeds the supported £10,000,000 limit.');
		$subtotal += $net;
		if ($subtotal > DXBS_FIN_MAX) return dxbs_error('limit', 'The document exceeds the supported £10,000,000 limit.');
		$lines[] = ['description' => $desc, 'quantity_millis' => $q, 'unit_minor' => $unit, 'net_minor' => $net, 'discount_minor' => 0, 'tax_basis_points' => (int)$tax];
	}
	$dtype = $data['discount_type'] ?? 'none';
	if (!in_array($dtype, ['none', 'fixed', 'percent'], true)) return dxbs_error('discount', 'Choose a valid discount type.');
	$discount = 0;
	if ($dtype !== 'none') {
		$v = dxbs_minor_units((string)($data['discount_value'] ?? '0'));
		if (is_wp_error($v) || ($dtype === 'percent' && $v > 10000)) return dxbs_error('discount', 'Enter a valid discount.');
		$discount = $dtype === 'percent' ? dxbs_percent_amount($subtotal, $v) : $v;
	}
	if ($discount > $subtotal) return dxbs_error('discount', 'The discount cannot exceed the line subtotal.');
	$allocated = 0;
	$cumulative = 0;
	$tax_total = 0;
	foreach ($lines as $i => &$line) {
		$cumulative += $line['net_minor'];
		$target = $subtotal ? intdiv($cumulative * $discount, $subtotal) : 0;
		$part = $target - $allocated;
		$allocated = $target;
		$line['discount_minor'] = $part;
		$line['tax_minor'] = dxbs_percent_amount($line['net_minor'] - $part, $line['tax_basis_points']);
		$line['total_minor'] = $line['net_minor'] - $part + $line['tax_minor'];
		$tax_total += $line['tax_minor'];
	}
	unset ($line);
	$total = $subtotal - $discount + $tax_total;
	if ($total <= 0 || $total > DXBS_FIN_MAX) return dxbs_error('total', 'The total must be greater than zero and within the supported limit.');
	$dep = 0;
	$dep_type = $data['deposit_type'] ?? 'none';
	if (!in_array($dep_type, ['none', 'fixed', 'percent'], true)) return dxbs_error('deposit', 'Choose a valid deposit type.');
	if ($dep_type !== 'none') {
		$v = dxbs_minor_units((string)($data['deposit_value'] ?? '0'));
		if (is_wp_error($v) || ($dep_type === 'percent' && $v > 10000)) return dxbs_error('deposit', 'Enter a valid deposit.');
		$dep = $dep_type === 'percent' ? dxbs_percent_amount($total, $v) : $v;
	}
	if ($dep > $total) return dxbs_error('deposit', 'The deposit cannot exceed the total.');
	return ['lines' => $lines, 'subtotal_minor' => $subtotal, 'discount_minor' => $discount, 'tax_minor' => $tax_total, 'total_minor' => $total, 'deposit_minor' => $dep];
}
function dxbs_save_quote($data) {
	return dxbs_save_draft('quote', $data);
}
function dxbs_save_invoice($data) {
	return dxbs_save_draft('invoice', $data);
}
function dxbs_save_draft($type, $data) {
	global $wpdb;
	if (!dxbs_is_manager()) return dxbs_require_manager();
	if (!is_array($data)) return dxbs_error('draft', 'Invalid document data.');
	$id = absint($data['id'] ?? 0);
	$old = $id ? dxbs_record_raw($type, $id) : null;
	if ($id && (!$old || $old['status'] !== 'draft' || $old['issued_at'])) return dxbs_error('immutable', 'Only draft documents can be edited.');
	if ($old && $type === 'invoice' && !empty($old['quote_id'])) return dxbs_error('allocation_locked', 'Invoice allocations from an accepted quotation stay fixed. Review and issue them, or void the allocation and correct the underlying agreement.');
	if ($old && absint($data['version'] ?? 0) !== (int)$old['version']) return dxbs_error('version', 'Refresh the document before editing this version.');
	$client = dxbs_client(absint($data['client_id'] ?? 0));
	$category = null;
	foreach (dxbs_categories() as $c) if ($c['id'] === absint($data['category_id'] ?? 0))$category = $c;
	if (!$client || $client['status'] !== 'active' || !$category) return dxbs_error('parties', 'Choose an active client and service category.');
	$pid = absint($data['project_id'] ?? 0);
	if ($pid && (get_post_type($pid) !== 'dxcp_project' || (int)get_post_meta($pid, '_dxcp_client_id', true) !== (int)$client['wp_user_id'])) return dxbs_error('project', 'The project must belong to the selected client account.');
	$calc = dxbs_document_calculate($data, dxbs_settings());
	if (is_wp_error($calc)) return $calc;
	$title = sanitize_text_field($data['title'] ?? '');
	$supply = dxbs_date($data['supply_date'] ?? '');
	$date = dxbs_date($data[$type === 'quote' ? 'expires_at' : 'due_date'] ?? '');
	if (!$title || strlen($title) > 191 || !$supply || !$date) return dxbs_error('dates', 'Add a title, valid supply date and ' . ($type === 'quote' ? 'expiry date.' : 'payment due date.'));
	$draft = ['title' => $title, 'terms' => sanitize_textarea_field($data['terms'] ?? ''), 'notes' => sanitize_textarea_field($data['notes'] ?? ''), 'discount_type' => $data['discount_type'] ?? 'none', 'discount_value' => (string)($data['discount_value'] ?? '0'), 'deposit_type' => $data['deposit_type'] ?? 'none', 'deposit_value' => (string)($data['deposit_value'] ?? '0'), 'lines' => $calc['lines']];
	if (strlen($draft['terms']) > 12000 || strlen($draft['notes']) > 12000) return dxbs_error('length', 'Terms and notes must each be shorter than 12,000 characters.');
	$row = ['client_id' => $client['id'], 'category_id' => $category['id'], 'project_id' => $pid ? : null, 'title' => $title, 'draft_data' => wp_json_encode($draft), 'supply_date' => $supply, 'subtotal_minor' => $calc['subtotal_minor'], 'discount_minor' => $calc['discount_minor'], 'tax_minor' => $calc['tax_minor'], 'total_minor' => $calc['total_minor'], 'version' => $old ? (int)$old['version'] + 1 : 1, 'updated_at' => dxbs_now()];
	if ($type === 'quote') {
		$row['deposit_minor'] = $calc['deposit_minor'];
		$row['expires_at'] = (new DateTimeImmutable($date . ' 23:59:59', new DateTimeZone('Europe/London')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}
	else $row['due_date'] = $date;
	$table = dxbs_table(dxbs_type_table($type));
	$wpdb->query('START TRANSACTION');
	if ($old)$ok = $wpdb->update($table, $row, ['id' => $id, 'version' => $old['version'], 'status' => 'draft']);
	else {
		$row['snapshot'] = '{}';
		$row['created_at'] = dxbs_now();
		$ok = $wpdb->insert($table, $row);
		$id = (int)$wpdb->insert_id;
	}
	if (!$ok) {
		$wpdb->query('ROLLBACK');
		return dxbs_error('save', 'The draft could not be saved.');
	}
	$lt = dxbs_table($type . '_lines');
	$wpdb->delete($lt, [$type . '_id' => $id]);
	foreach ($calc['lines'] as $i => $l) {
		$line = [$type . '_id' => $id, 'position' => $i, 'description' => $l['description'], 'quantity_millis' => $l['quantity_millis'], 'unit_minor' => $l['unit_minor'], 'discount_minor' => $l['discount_minor'], 'tax_basis_points' => $l['tax_basis_points'], 'total_minor' => $l['total_minor']];
		if (false === $wpdb->insert($lt, $line)) {
			$wpdb->query('ROLLBACK');
			return dxbs_error('save', 'The draft lines could not be saved.');
		}
	}
	if (!dxbs_audit($old ? 'draft_updated' : 'draft_created', $type, $id, ['fields' => array_keys($row), 'version' => $row['version']])) {
		$wpdb->query('ROLLBACK');
		return dxbs_error('audit', 'The audit could not be recorded.');
	}
	$wpdb->query('COMMIT');
	return $id;
}
function dxbs_next_number($type) {
	global $wpdb;
	$prefix = ['quote' => 'Q', 'invoice' => 'I', 'receipt' => 'R', 'credit' => 'C'][$type] ?? '';
	if (!$prefix) return dxbs_error('number', 'Invalid document series.');
	$year = wp_date('Y', null, new DateTimeZone('Europe/London'));
	$series = $prefix . '-' . $year;
	$t = dxbs_table('sequences');
	if (false === $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t(series,last_number) VALUES(%s,0)", $series))) return dxbs_error('number', 'The document sequence is unavailable.');
	$last = (int)$wpdb->get_var($wpdb->prepare("SELECT last_number FROM $t WHERE series=%s FOR UPDATE", $series));
	if (false === $wpdb->update($t, ['last_number' => $last + 1], ['series' => $series])) return dxbs_error('number', 'The document number could not be reserved.');
	return 'DX-' . $series . '-' . str_pad((string)($last + 1), 5, '0', STR_PAD_LEFT);
}
function dxbs_issue_requirements($client, $settings) {
	if (empty($settings['legal_name']) || empty($settings['company_type']) || empty($settings['address_1']) || empty($settings['postcode']) || empty($settings['email'])) return dxbs_error('identity', 'Complete your legal business name, business type, address, postcode and contact email in Settings before issuing documents.');
	if ($settings['company_type'] === 'limited_company' && !$settings['company_number']) return dxbs_error('identity', 'Add the registered company number before issuing limited-company documents.');
	if (empty($client['billing_address_1']) || empty($client['billing_city']) || empty($client['billing_postcode']) || empty($client['billing_country'])) return dxbs_error('client_address', 'Complete the client billing address, city, postcode and country before issue.');
	return true;
}
function dxbs_snapshot($type, $row, $number) {
	$client = dxbs_client($row['client_id']);
	$settings = dxbs_settings();
	$required = dxbs_issue_requirements($client, $settings);
	if ((int)$row['tax_minor'] > 0 && !$settings['vat_registered']) return dxbs_error('vat_changed', 'This draft charges VAT but the business is no longer configured as VAT registered. Correct the draft before issue.');
	if (is_wp_error($required)) return $required;
	$draft = json_decode($row['draft_data'], true) ? : [];
	$business = array_diff_key($settings, array_flip(['bank_account_holder', 'bank_name', 'bank_sort_code', 'bank_account_number', 'version']));
	$client = array_diff_key($client, array_flip(['notes', 'version', 'category_ids', 'created_at', 'updated_at']));
	$cat = null;
	foreach (dxbs_categories(false) as $c) if ($c['id'] === (int)$row['category_id'])$cat = $c;
	return ['type' => $type, 'number' => $number, 'title' => $row['title'], 'version' => (int)$row['version'], 'issue_date' => wp_date('Y-m-d', null, new DateTimeZone('Europe/London')), 'supply_date' => $row['supply_date'], 'due_date' => $row['due_date'] ?? null, 'expires_at' => $row['expires_at'] ?? null, 'business' => $business, 'client' => $client, 'category' => $cat, 'lines' => $draft['lines'] ?? [], 'terms' => $draft['terms'] ?? '', 'notes' => $draft['notes'] ?? '', 'subtotal_minor' => (int)$row['subtotal_minor'], 'discount_minor' => (int)$row['discount_minor'], 'tax_minor' => (int)$row['tax_minor'], 'total_minor' => (int)$row['total_minor'], 'deposit_minor' => (int)($row['deposit_minor'] ?? 0), 'project_id' => (int)($row['project_id'] ?? 0), 'quote_id' => (int)($row['quote_id'] ?? 0), 'currency' => 'GBP'];
}
function dxbs_issue_document($type, $id, $version, $confirm = false, $send = false) {
	global $wpdb;
	if (!dxbs_is_manager()) return dxbs_require_manager();
	if (!$confirm || !in_array($type, ['quote', 'invoice'], true)) return dxbs_error('confirm', 'Explicitly confirm document issue.');
	$wpdb->query('START TRANSACTION');
	$row = dxbs_record_raw($type, $id, true);
	if (!$row || $row['status'] !== 'draft' || $row['issued_at'] || (int)$row['version'] !== (int)$version) {
		$wpdb->query('ROLLBACK');
		return dxbs_error('immutable', 'Only the current draft version can be issued.');
	}
	if ($type === 'invoice' && !empty($row['quote_id'])) {
		$q = dxbs_record_raw('quote', $row['quote_id'], true);
		$reserved = (int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(total_minor),0) FROM ' . dxbs_table('invoices') . " WHERE quote_id=%d AND id<>%d AND voided_at IS NULL AND status NOT IN ('void','cancelled')", $row['quote_id'], $id));
		if (!$q || $q['status'] !== 'accepted' || $reserved + (int)$row['total_minor'] > (int)$q['total_minor']) {
			$wpdb->query('ROLLBACK');
			return dxbs_error('allocation', 'These invoices would exceed the accepted quotation value. Correct the draft allocation before issue.');
		}
	}
	$number = dxbs_next_number($type);
	if (is_wp_error($number)) {
		$wpdb->query('ROLLBACK');
		return $number;
	}
	$snapshot = dxbs_snapshot($type, $row, $number);
	if (is_wp_error($snapshot)) {
		$wpdb->query('ROLLBACK');
		return $snapshot;
	}
	$updated = ['number' => $number, 'snapshot' => wp_json_encode($snapshot), 'status' => $type === 'quote' ? 'sent' : 'awaiting_payment', 'issued_at' => dxbs_now(), 'issued_by' => get_current_user_id(), 'updated_at' => dxbs_now()];
	if ($type === 'invoice')$updated['payment_reference'] = $number;
	if (false === $wpdb->update(dxbs_table(dxbs_type_table($type)), $updated, ['id' => $id]) || !dxbs_audit('document_issued', $type, $id, ['version' => $version])) {
		$wpdb->query('ROLLBACK');
		return dxbs_error('issue', 'Document issue could not be recorded.');
	}
	$wpdb->query('COMMIT');
	$pdf = dxbs_generate_pdf($type, $id);
	if ($send) {
		$mail = dxbs_send_document($type, $id);
		if (is_wp_error($mail)) return $mail;
	}
	return is_wp_error($pdf) ? dxbs_error('pdf_after_issue', 'The document was issued and locked, but PDF archiving needs retry: ' . $pdf->get_error_message()) : (int)$id;
}
function dxbs_duplicate_quote($id) {
	if (!dxbs_is_manager()) return dxbs_require_manager();
	$q = dxbs_document('quote', $id);
	if (!$q) return dxbs_error('missing', 'Quotation not found.');
	$d = $q['draft'];
	$data = array_merge($d, ['client_id' => $q['client_id'], 'category_id' => $q['category_id'], 'project_id' => $q['project_id'], 'title' => $q['title'] . ' — copy', 'supply_date' => $q['supply_date'], 'expires_at' => substr($q['expires_at'], 0, 10)]);
	$data['lines'] = array_map('dxbs_line_to_input', $d['lines'] ?? $q['lines']);
	return dxbs_save_quote($data);
}
function dxbs_decimal_minor($n) {
	return intdiv((int)$n, 100) . '.' . str_pad((string)((int)$n % 100), 2, '0', STR_PAD_LEFT);
}
function dxbs_line_to_input($l) {
	return ['description' => $l['description'], 'quantity' => intdiv((int)$l['quantity_millis'], 1000) . '.' . str_pad((string)((int)$l['quantity_millis'] % 1000), 3, '0', STR_PAD_LEFT), 'unit_price' => dxbs_decimal_minor($l['unit_minor']), 'tax_basis_points' => $l['tax_basis_points']];
}
function dxbs_accept_quote($id, $version, $confirmation) {
	global $wpdb;
	if (!is_user_logged_in() || current_user_can('manage_options') || !$confirmation) return dxbs_error('accept', 'The assigned client must explicitly confirm quotation acceptance.');
	$wpdb->query('START TRANSACTION');
	$q = dxbs_record_raw('quote', $id, true);
	if (!$q || !dxbs_can_read_financial('quote', $q) || $q['status'] !== 'sent' || (int)$q['version'] !== (int)$version || $q['expires_at'] < dxbs_now()) {
		$wpdb->query('ROLLBACK');
		return dxbs_error('accept', 'This quotation is unavailable, expired or changed. Refresh and review the current document.');
	}
	$ok = $wpdb->update(dxbs_table('quotes'), ['status' => 'accepted', 'accepted_at' => dxbs_now(), 'accepted_by' => get_current_user_id(), 'accepted_version' => $version, 'updated_at' => dxbs_now()], ['id' => $id, 'status' => 'sent']);
	if (!$ok) {
		$wpdb->query('ROLLBACK');
		return dxbs_error('accept', 'Acceptance could not be recorded.');
	}
	if (!dxbs_fin_audit('quote_accepted', 'quote', $id, ['version' => $version])) return dxbs_rollback('audit', 'Acceptance could not be audited and was not applied.');
	$wpdb->query('COMMIT');
	dxbs_queue_event('quote_accepted', 'quote', $id, 'accept-' . $id . '-' . $version);
	return true;
}
/** Audits for client events omit private values and cannot confer financial authority. */
function dxbs_fin_audit($event, $type, $id, $metadata = []) {
	global $wpdb;
	$safe = [];
	foreach (['version', 'invoice_id', 'payment_id', 'amount_minor', 'reason', 'status'] as $key) if (isset($metadata[$key]))$safe[$key] = is_int($metadata[$key]) ? $metadata[$key] : sanitize_text_field($metadata[$key]);
	return false !== $wpdb->insert(dxbs_table('audit'), ['actor_id' => get_current_user_id(), 'event' => sanitize_key($event), 'entity_type' => sanitize_key($type), 'entity_id' => (int)$id, 'metadata' => wp_json_encode($safe), 'created_at' => dxbs_now()]);
}
function dxbs_import_quote($reference, $client_id, $category_id, $project_id = 0, $reviewed = false) {
	global $wpdb;
	if (!dxbs_is_manager()) return dxbs_require_manager();
	if (!$reviewed) return dxbs_error('review', 'Review the indicative calculator estimate before importing it as a draft.');
	$reference = sanitize_text_field($reference);
	if ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . dxbs_table('quotes') . ' WHERE legacy_reference=%s', $reference))) return dxbs_error('duplicate', 'This calculator estimate has already been imported.');
	$source = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dx_quotes WHERE invoice=%s", $reference), ARRAY_A);
	if (!$source) return dxbs_error('missing', 'Calculator estimate not found.');
	$client = dxbs_client($client_id);
	if (!$client) return dxbs_error('client', 'Choose an existing client.');
	if (function_exists('dxcp_quote_assignment')) {
		$a = dxcp_quote_assignment($reference);
		if (!empty($a['client_id']) && (int)$a['client_id'] !== (int)$client['wp_user_id']) return dxbs_error('assignment', 'This estimate belongs to another account. Resolve the assignment before import.');
	}
	$state = json_decode($source['state'], true) ? : [];
	$labels = [];
	foreach ($state['estimate_breakdown'] ?? [] as $l)$labels[] = sanitize_text_field(($l['category'] ?? '') . ': ' . ($l['label'] ?? ''));
	$price = (string)$source['total'];
	$id = dxbs_save_quote(['client_id' => $client_id, 'category_id' => $category_id, 'project_id' => $project_id, 'title' => 'Calculator brief ' . $reference . ' — review before issue', 'supply_date' => dxbs_today(), 'expires_at' => wp_date('Y-m-d', time() + 30 * DAY_IN_SECONDS), 'notes' => 'Imported indicative estimate ' . $reference . '. Review the scope, pricing, payment terms and tax before issuing a final quotation.', 'lines' => [['description' => mb_substr(implode("\n", $labels) ? : 'Reviewed calculator brief ' . $reference, 0, 1900), 'quantity' => '1', 'unit_price' => $price, 'tax_basis_points' => '0']]]);
	if (is_wp_error($id)) return $id;
	if (false === $wpdb->update(dxbs_table('quotes'), ['legacy_reference' => $reference], ['id' => $id])) {
		$wpdb->delete(dxbs_table('quote_lines'), ['quote_id' => $id]);
		$wpdb->delete(dxbs_table('quotes'), ['id' => $id]);
		return dxbs_error('duplicate', 'This calculator reference could not be attached, possibly because it was imported elsewhere.');
	}
	dxbs_audit('calculator_imported', 'quote', $id, ['fields' => ['legacy_reference']]);
	return $id;
}
function dxbs_convert_quote($id, $version, $plan = 'full', $count = 1, $first_due_date = '') {
	global $wpdb;
	if (!dxbs_is_manager()) return dxbs_require_manager();
	$date = dxbs_date($first_due_date);
	if (!$date || !in_array($plan, ['full', 'deposit_final', 'instalments'], true)) return dxbs_error('plan', 'Choose a payment plan and valid first due date.');
	$wpdb->query('START TRANSACTION');
	$q = dxbs_record_raw('quote', $id, true);
	if (!$q || $q['status'] !== 'accepted' || (int)$q['version'] !== (int)$version || !empty($q['converted_at'])) {
		$wpdb->query('ROLLBACK');
		return dxbs_error('conversion', 'An accepted quotation can be converted once, using its current version.');
	}
	$total = (int)$q['total_minor'];
	$dep = (int)$q['deposit_minor'];
	if ($plan === 'deposit_final') {
		if (!$dep || $dep >= $total) {
			$wpdb->query('ROLLBACK');
			return dxbs_error('deposit', 'The accepted quotation needs a deposit greater than zero and lower than its total.');
		}
		$amounts = [$dep, $total - $dep];
	}
	elseif ($plan === 'instalments') {
		if ($count < 2 || $count > 12) {
			$wpdb->query('ROLLBACK');
			return dxbs_error('instalments', 'Choose 2 to 12 installments.');
		}
		$amounts = dxbs_split_minor($total, (int)$count);
	}
	else $amounts = [$total];
	$snapshot = json_decode($q['snapshot'], true);
	$source = $snapshot['lines'];
	$source_i = 0;
	$source_remaining = [];
	foreach ($source as $l)$source_remaining[] = ['net' => (int)$l['net_minor'] - (int)$l['discount_minor'], 'tax' => (int)$l['tax_minor'], 'description' => $l['description'], 'rate' => (int)$l['tax_basis_points']];
	$ids = [];
	foreach ($amounts as $part => $amount) {
		$need = $amount;
		$lines = [];
		while ($need > 0 && isset($source_remaining[$source_i])) {
			$l = &$source_remaining[$source_i];
			$gross = $l['net'] + $l['tax'];
			if ($gross === 0) {
				$source_i++;
				continue;
			}
			$take = min($need, $gross);
			$tax = $take === $gross ? $l['tax'] : intdiv($l['tax'] * $take + intdiv($gross, 2), $gross);
			$tax = (int)$tax;
			$net = $take - $tax;
			$lines[] = ['description' => ($plan === 'deposit_final' ? ($part === 0 ? 'Deposit allocation — ' : 'Final allocation — ') : 'Agreed allocation — ') . $l['description'], 'quantity_millis' => 1000, 'unit_minor' => $net, 'net_minor' => $net, 'discount_minor' => 0, 'tax_basis_points' => $l['rate'], 'tax_minor' => $tax, 'total_minor' => $take];
			$l['net'] -= $net;
			$l['tax'] -= $tax;
			$need -= $take;
			if ($take === $gross)$source_i++;
			unset ($l);
		}
		if ($need) {
			$wpdb->query('ROLLBACK');
			return dxbs_error('allocation', 'The quotation allocation could not be reconciled.');
		}
		$kind = $plan === 'deposit_final' ? ($part === 0 ? 'deposit' : 'final') : ($plan === 'instalments' ? 'instalment' : 'standard');
		$first = new DateTimeImmutable($date, new DateTimeZone('Europe/London'));
		$month = $first->modify('first day of this month')->modify('+' . $part . ' months');
		$due = $month->setDate((int)$month->format('Y'), (int)$month->format('m'), min((int)$first->format('d'), (int)$month->format('t')))->format('Y-m-d');
		$draft = ['title' => ucfirst($kind) . ' — ' . $q['number'], 'terms' => $snapshot['terms'], 'notes' => $snapshot['notes'], 'lines' => $lines, 'source_quote_number' => $q['number']];
		$row = ['client_id' => $q['client_id'], 'category_id' => $q['category_id'], 'project_id' => $q['project_id'], 'quote_id' => $id, 'kind' => $kind, 'title' => $draft['title'], 'draft_data' => wp_json_encode($draft), 'snapshot' => '{}', 'supply_date' => $q['supply_date'], 'due_date' => $due, 'subtotal_minor' => array_sum(array_column($lines, 'net_minor')), 'tax_minor' => array_sum(array_column($lines, 'tax_minor')), 'total_minor' => $amount, 'created_at' => dxbs_now(), 'updated_at' => dxbs_now()];
		if (false === $wpdb->insert(dxbs_table('invoices'), $row)) {
			$wpdb->query('ROLLBACK');
			return dxbs_error('conversion', 'Invoice drafts could not be created.');
		}
		$invoice = (int)$wpdb->insert_id;
		$ids[] = $invoice;
		foreach ($lines as $i => $l) if (false === $wpdb->insert(dxbs_table('invoice_lines'), ['invoice_id' => $invoice, 'position' => $i, 'description' => $l['description'], 'quantity_millis' => 1000, 'unit_minor' => $l['unit_minor'], 'discount_minor' => 0, 'tax_basis_points' => $l['tax_basis_points'], 'total_minor' => $l['total_minor']])) {
			$wpdb->query('ROLLBACK');
			return dxbs_error('conversion', 'Invoice allocation lines could not be saved.');
		}
	}
	if (false === $wpdb->update(dxbs_table('quotes'), ['converted_at' => dxbs_now()], ['id' => $id]) || !dxbs_fin_audit('quote_converted', 'quote', $id, ['version' => (int)$version])) return dxbs_rollback('conversion', 'The conversion audit could not be saved.');
	$wpdb->query('COMMIT');
	return $ids;
}
function dxbs_invoice_balance($id) {
	global $wpdb;
	$row = dxbs_record_raw('invoice', $id);
	if (!$row) return ['paid_minor' => 0, 'credited_minor' => 0, 'balance_minor' => 0];
	$paid = (int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM ' . dxbs_table('payments') . " WHERE invoice_id=%d AND status='verified' AND verified_by>0 AND verified_at IS NOT NULL AND reversed_at IS NULL AND currency='GBP'", $id));
	$credited = (int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(total_minor),0) FROM ' . dxbs_table('credit_notes') . " WHERE invoice_id=%d AND status='issued'", $id));
	return ['paid_minor' => $paid, 'credited_minor' => $credited, 'balance_minor' => $row['voided_at'] ? 0 : max(0, (int)$row['total_minor'] - $paid - $credited)];
}
function dxbs_invoice_status($row, $balance) {
	if ($row['voided_at'] || in_array($row['status'], ['void', 'cancelled'], true)) return 'void';
	if (!$row['issued_at']) return 'draft';
	if (!$balance['balance_minor']) return $balance['credited_minor'] > 0 && $balance['paid_minor'] < (int)$row['total_minor'] ? 'credited' : 'paid';
	if ($balance['paid_minor'] > 0) return 'partially_paid';
	if ($row['due_date'] && $row['due_date'] < wp_date('Y-m-d', null, new DateTimeZone('Europe/London'))) return 'overdue';
	return 'awaiting_payment';
}
function dxbs_void_invoice($id, $reason, $confirm = false) {
	global $wpdb;
	if (!dxbs_is_manager()) return dxbs_require_manager();
	$reason = sanitize_textarea_field($reason);
	if (!$confirm || strlen($reason) < 5) return dxbs_error('reason', 'Confirm cancellation and provide a reason.');
	$wpdb->query('START TRANSACTION');
	$row = dxbs_record_raw('invoice', $id, true);
	$b = dxbs_invoice_balance($id);
	if (!$row || $row['voided_at'] || $b['paid_minor'] || $b['credited_minor']) {
		$wpdb->query('ROLLBACK');
		return dxbs_error('void', 'An invoice with a verified payment or credit must be corrected through a credit note or payment reversal.');
	}
	if (false === $wpdb->update(dxbs_table('invoices'), ['status' => 'void', 'voided_at' => dxbs_now(), 'updated_at' => dxbs_now()], ['id' => $id]) || !dxbs_fin_audit('invoice_voided', 'invoice', $id, ['reason' => $reason])) return dxbs_rollback('void', 'The invoice cancellation could not be audited.');
	$wpdb->query('COMMIT');
	dxbs_revoke_payment_links($id);
	return true;
}
function dxbs_credit_lines($lines, $offset, $amount) {
	$out = [];
	foreach ($lines as $l) {
		$net = (int)$l['net_minor'] - (int)($l['discount_minor'] ?? 0);
		$tax = (int)($l['tax_minor'] ?? 0);
		$gross = $net + $tax;
		if (!$gross)continue;
		if ($offset >= $gross) {
			$offset -= $gross;
			continue;
		}
		if ($offset) {
			$skip_tax = intdiv($tax * $offset + intdiv($gross, 2), $gross);
			$tax -= $skip_tax;
			$net -= ($offset - $skip_tax);
			$gross = $net + $tax;
			$offset = 0;
		}
		$take = min($amount, $gross);
		if ($take <= 0)break;
		$part_tax = $take === $gross ? $tax : intdiv($tax * $take + intdiv($gross, 2), $gross);
		$part_net = $take - $part_tax;
		$out[] = ['description' => 'Credit allocation — ' . $l['description'], 'quantity_millis' => 1000, 'unit_minor' => $part_net, 'net_minor' => $part_net, 'discount_minor' => 0, 'tax_basis_points' => (int)$l['tax_basis_points'], 'tax_minor' => $part_tax, 'total_minor' => $take];
		$amount -= $take;
		if (!$amount)break;
	}
	return $amount ? dxbs_error('credit_allocation', 'The credit allocation could not be reconciled.') : $out;
}
function dxbs_credit_invoice($id, $amount, $reason, $supply_date, $confirm = false) {
	global $wpdb;
	if (!dxbs_is_manager()) return dxbs_require_manager();
	$minor = dxbs_minor_units($amount);
	$reason = sanitize_textarea_field($reason);
	if (!$confirm || is_wp_error($minor) || $minor < 1 || strlen($reason) < 5 || strlen($reason) > 2000 || !dxbs_date($supply_date)) return dxbs_error('credit', 'Confirm the correction, amount, supply date and reason.');
	$wpdb->query('START TRANSACTION');
	$row = dxbs_record_raw('invoice', $id, true);
	$b = dxbs_invoice_balance($id);
	if (!$row || !$row['issued_at'] || $row['voided_at'] || $minor > (int)$row['total_minor'] - $b['credited_minor']) return dxbs_rollback('credit', 'This credit exceeds the remaining invoice value or the invoice is unavailable.');
	$number = dxbs_next_number('credit');
	if (is_wp_error($number)) {
		$wpdb->query('ROLLBACK');
		return $number;
	}
	$snapshot = json_decode($row['snapshot'], true);
	$lines = dxbs_credit_lines($snapshot['lines'], $b['credited_minor'], $minor);
	if (is_wp_error($lines)) {
		$wpdb->query('ROLLBACK');
		return $lines;
	}
	$tax = array_sum(array_column($lines, 'tax_minor'));
	$net = $minor - $tax;
	$snapshot = array_merge($snapshot, ['type' => 'credit', 'number' => $number, 'original_invoice' => $row['number'], 'original_invoice_date' => substr($row['issued_at'], 0, 10), 'issue_date' => dxbs_today(), 'supply_date' => $supply_date, 'notes' => $reason, 'subtotal_minor' => $net, 'discount_minor' => 0, 'tax_minor' => $tax, 'total_minor' => $minor, 'deposit_minor' => 0, 'due_date' => null, 'expires_at' => null, 'lines' => $lines]);
	$ok = $wpdb->insert(dxbs_table('credit_notes'), ['invoice_id' => $id, 'client_id' => $row['client_id'], 'category_id' => $row['category_id'], 'number' => $number, 'subtotal_minor' => $net, 'tax_minor' => $tax, 'total_minor' => $minor, 'snapshot' => wp_json_encode($snapshot), 'supply_date' => $supply_date, 'created_by' => get_current_user_id(), 'created_at' => dxbs_now()]);
	if (!$ok) return dxbs_rollback('credit', 'Credit note creation failed.');
	$credit = (int)$wpdb->insert_id;
	if (!dxbs_fin_audit('credit_issued', 'credit', $credit, ['invoice_id' => (int)$id, 'amount_minor' => $minor, 'reason' => $reason])) return dxbs_rollback('audit', 'The credit note could not be audited.');
	$wpdb->query('COMMIT');
	$pdf = dxbs_generate_pdf('credit', $credit);
	if (is_wp_error($pdf)) return dxbs_error('credit_archive', 'Credit note issued and retained; PDF archiving needs retry. ' . $pdf->get_error_message());
	return $credit;
}
function dxbs_payment_link($id, $rotate = false) {
	global $wpdb;
	$row = dxbs_record_raw('invoice', $id);
	if (!dxbs_is_manager() || !$row || !$row['issued_at'] || $row['voided_at']) return '';
	if ($rotate)dxbs_revoke_payment_links($id);
	$token = bin2hex(random_bytes(32));
	$wpdb->insert(dxbs_table('access_tokens'), ['entity_type' => 'invoice', 'entity_id' => $id, 'token_hash' => hash('sha256', $token), 'created_by' => get_current_user_id(), 'created_at' => dxbs_now(), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 90 * DAY_IN_SECONDS)]);
	dxbs_fin_audit('payment_link_created', 'invoice', $id);
	return add_query_arg(['dxbs_transfer' => $id, 'access' => $token], home_url('/'));
}
function dxbs_revoke_payment_links($id) {
	global $wpdb;
	if (!dxbs_is_manager()) return false;
	return false !== $wpdb->update(dxbs_table('access_tokens'), ['revoked_at' => dxbs_now()], ['entity_type' => 'invoice', 'entity_id' => (int)$id]);
}
function dxbs_transfer_authorized($id, $token = '') {
	global $wpdb;
	$row = dxbs_record_raw('invoice', $id);
	if (!$row || !$row['issued_at'] || $row['voided_at']) return false;
	if (dxbs_can_read_financial('invoice', $row)) return true;
	if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
	return (bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . dxbs_table('access_tokens') . " WHERE entity_type='invoice' AND entity_id=%d AND token_hash=%s AND revoked_at IS NULL AND expires_at>%s", $id, hash('sha256', $token), dxbs_now()));
}
function dxbs_report_transfer($id, $token = '') {
	global $wpdb;
	if (!dxbs_transfer_authorized($id, $token)) return dxbs_error('forbidden', 'This payment link is unavailable or expired.');
	$wpdb->query('START TRANSACTION');
	$row = dxbs_record_raw('invoice', $id, true);
	$b = dxbs_invoice_balance($id);
	if (!$b['balance_minor']) {
		$wpdb->query('ROLLBACK');
		return dxbs_error('settled', 'This invoice has no outstanding balance.');
	}
	$existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . dxbs_table('payments') . " WHERE invoice_id=%d AND status='reported' LIMIT 1", $id));
	if ($existing) {
		$wpdb->query('COMMIT');
		return (int)$existing;
	}
	$ok = $wpdb->insert(dxbs_table('payments'), ['invoice_id' => $id, 'client_id' => $row['client_id'], 'category_id' => $row['category_id'], 'amount_minor' => 0, 'status' => 'reported', 'idempotency_key' => 'report-' . bin2hex(random_bytes(24)), 'created_at' => dxbs_now()]);
	if (!$ok) {
		$wpdb->query('ROLLBACK');
		return dxbs_error('report', 'The transfer report could not be recorded.');
	}
	$report = (int)$wpdb->insert_id;
	if (!dxbs_fin_audit('client_reported_transfer', 'invoice', $id, ['payment_id' => $report])) return dxbs_rollback('audit', 'The transfer report could not be audited.');
	$wpdb->query('COMMIT');
	dxbs_queue_event('transfer_reported', 'invoice', $id, 'report-' . $report);
	return $report;
}
function dxbs_prepare_payment($data) {
	global $wpdb;
	if (!dxbs_is_manager()) return dxbs_require_manager();
	$id = absint($data['invoice_id'] ?? 0);
	$row = dxbs_record_raw('invoice', $id);
	$minor = dxbs_minor_units($data['amount'] ?? '');
	$date = dxbs_date($data['bank_date'] ?? '');
	$bank_ref = sanitize_text_field($data['bank_reference'] ?? '');
	if (!$row || !$row['issued_at'] || $row['voided_at'] || is_wp_error($minor) || $minor < 1 || !$date || $date > dxbs_today() || strlen($bank_ref) > 191) return dxbs_error('payment', 'Choose an issued invoice, a positive amount and the actual bank payment date (not in the future).');
	$b = dxbs_invoice_balance($id);
	if (!empty($data['report_id']) && !$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . dxbs_table('payments') . " WHERE id=%d AND invoice_id=%d AND status='reported'", absint($data['report_id']), $id))) return dxbs_error('report', 'Choose an open transfer report for this invoice.');
	if ($minor > $b['balance_minor']) return dxbs_error('overpayment', 'The received amount exceeds the invoice outstanding balance.');
	$duplicate = (int)$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . dxbs_table('payments') . " WHERE invoice_id=%d AND amount_minor=%d AND bank_date=%s AND bank_reference=%s AND status='verified' AND reversed_at IS NULL LIMIT 1", $id, $minor, $date, $bank_ref));
	if ($duplicate && empty($data['allow_duplicate'])) return dxbs_error('duplicate_bank_entry', 'A matching bank entry is already recorded. Only acknowledge a separate transfer if you have checked that these are genuinely different transactions.');
	$token = bin2hex(random_bytes(32));
	$review = ['duplicate_acknowledged' => (bool)$duplicate, 'token' => $token, 'invoice_id' => $id, 'invoice_number' => $row['number'], 'invoice_version' => (int)$row['version'], 'amount_minor' => $minor, 'bank_date' => $date, 'bank_reference' => $bank_ref, 'receipt_delivery' => ($data['receipt_delivery'] ?? 'hold') === 'send' ? 'send' : 'hold', 'balance_minor' => $b['balance_minor'], 'report_id' => absint($data['report_id'] ?? 0)];
	$stored = $review;
	unset ($stored['token']);
	$ok = $wpdb->insert(dxbs_table('payment_intents'), ['token_hash' => hash('sha256', $token), 'actor_id' => get_current_user_id(), 'invoice_id' => $id, 'payload' => wp_json_encode($stored), 'created_at' => dxbs_now(), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS)]);
	if (!$ok) return dxbs_error('review', 'The payment review could not be prepared.');
	set_transient('dxbs_payment_review_' . get_current_user_id(), $review, 15 * MINUTE_IN_SECONDS);
	return $review;
}
function dxbs_payment_review() {
	if (!dxbs_is_manager()) return null;
	$r = get_transient('dxbs_payment_review_' . get_current_user_id());
	return is_array($r) ? $r : null;
}
function dxbs_verify_payment($token, $verified_bank = false) {
	global $wpdb;
	if (!dxbs_is_manager()) return dxbs_require_manager();
	if (!$verified_bank || !is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) return dxbs_error('verify', 'Explicitly confirm that you checked the bank account and the funds have arrived.');
	$wpdb->query('START TRANSACTION');
	$it = dxbs_table('payment_intents');
	$intent = $wpdb->get_row($wpdb->prepare("SELECT * FROM $it WHERE token_hash=%s FOR UPDATE", hash('sha256', $token)), ARRAY_A);
	if (!$intent || (int)$intent['actor_id'] !== get_current_user_id() || $intent['expires_at'] < dxbs_now()) {
		$wpdb->query('ROLLBACK');
		return dxbs_error('verify', 'The payment review expired. Prepare a fresh review.');
	}
	if ($intent['status'] === 'complete') {
		$wpdb->query('COMMIT');
		return (int)$intent['payment_id'];
	}
	$r = json_decode($intent['payload'], true);
	$invoice = dxbs_record_raw('invoice', $intent['invoice_id'], true);
	$b = dxbs_invoice_balance($invoice['id'] ?? 0);
	if (!$invoice || $invoice['voided_at'] || !$invoice['issued_at'] || (int)$invoice['version'] !== $r['invoice_version'] || $b['balance_minor'] !== $r['balance_minor'] || $r['amount_minor'] > $b['balance_minor']) {
		$wpdb->query('ROLLBACK');
		return dxbs_error('stale', 'The invoice balance changed. Prepare and review the payment again.');
	}
	$payment = ['invoice_id' => $invoice['id'], 'client_id' => $invoice['client_id'], 'category_id' => $invoice['category_id'], 'amount_minor' => $r['amount_minor'], 'currency' => 'GBP', 'status' => 'verified', 'bank_date' => $r['bank_date'], 'bank_reference' => $r['bank_reference'], 'idempotency_key' => hash('sha256', 'verified-' . $token), 'verified_by' => get_current_user_id(), 'verified_at' => dxbs_now(), 'verification_note' => 'Account holder explicitly confirmed funds checked in bank.', 'report_id' => $r['report_id'] ? : null, 'created_at' => dxbs_now()];
	if (false === $wpdb->insert(dxbs_table('payments'), $payment)) {
		$wpdb->query('ROLLBACK');
		return dxbs_error('duplicate', 'This verified payment could not be recorded, or the submission was already used.');
	}
	$pid = (int)$wpdb->insert_id;
	if (false === $wpdb->update($it, ['status' => 'complete', 'payment_id' => $pid], ['id' => $intent['id']])) return dxbs_rollback('verify', 'The verification review could not be committed.');
	$new_b = dxbs_invoice_balance($invoice['id']);
	if (false === $wpdb->update(dxbs_table('invoices'), ['status' => $new_b['balance_minor'] ? 'partially_paid' : 'paid', 'version' => (int)$invoice['version'] + 1, 'updated_at' => dxbs_now()], ['id' => $invoice['id']])) return dxbs_rollback('verify', 'The invoice balance state could not be committed.');
	if ($r['report_id'] && false === $wpdb->update(dxbs_table('payments'), ['status' => 'reviewed'], ['id' => $r['report_id'], 'invoice_id' => $invoice['id'], 'status' => 'reported'])) return dxbs_rollback('verify', 'The linked report could not be updated.');
	if (!dxbs_fin_audit('bank_payment_verified', 'payment', $pid, ['invoice_id' => (int)$invoice['id'], 'amount_minor' => $r['amount_minor']])) {
		$wpdb->query('ROLLBACK');
		return dxbs_error('audit', 'Payment verification could not be audited.');
	}
	$wpdb->query('COMMIT');
	delete_transient('dxbs_payment_review_' . get_current_user_id());
	$receipt = dxbs_create_receipt($pid, $r['receipt_delivery']);
	if (is_wp_error($receipt))set_transient('dxbs_payment_warning_' . get_current_user_id(), 'Payment verified and retained; receipt requires retry: ' . $receipt->get_error_message(), 10 * MINUTE_IN_SECONDS);
	dxbs_queue_event('payment_verified', 'invoice', $invoice['id'], 'verify-' . $pid);
	return $pid;
}
function dxbs_payment_records($args = []) {
	global $wpdb;
	if (!dxbs_is_manager()) return [];
	$where = ['1=1'];
	foreach (['invoice_id', 'client_id', 'category_id'] as $k) if (!empty($args[$k]))$where[] = $wpdb->prepare("p.$k=%d", absint($args[$k]));
	if (!empty($args['status']))$where[] = $wpdb->prepare('p.status=%s', sanitize_key($args['status']));
	$t = dxbs_table('payments');
	$invoices = dxbs_table('invoices');
	$clients = dxbs_table('clients');
	$rows = $wpdb->get_results($wpdb->prepare("SELECT p.*,i.number AS invoice_number,c.name AS client_name FROM $t p LEFT JOIN $invoices i ON i.id=p.invoice_id LEFT JOIN $clients c ON c.id=p.client_id WHERE " . implode(' AND ', $where) . ' ORDER BY p.created_at DESC,p.id DESC LIMIT %d', min(200, max(1, (int)($args['limit'] ?? 100)))), ARRAY_A);
	foreach ($rows as &$r) foreach (['id', 'invoice_id', 'client_id', 'category_id', 'amount_minor', 'verified_by'] as $k)$r[$k] = (int)$r[$k];
	return $rows;
}
function dxbs_create_receipt($payment_id, $delivery = 'hold') {
	global $wpdb;
	if (!dxbs_is_manager()) return dxbs_require_manager();
	$wpdb->query('START TRANSACTION');
	$p = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . dxbs_table('payments') . ' WHERE id=%d FOR UPDATE', $payment_id), ARRAY_A);
	if (!$p || $p['status'] !== 'verified' || !$p['verified_at'] || !$p['verified_by'] || $p['reversed_at']) {
		$wpdb->query('ROLLBACK');
		return dxbs_error('unverified', 'Receipts require a bank payment explicitly verified by the authorised account.');
	}
	$existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . dxbs_table('receipts') . ' WHERE payment_id=%d', $payment_id));
	if ($existing) {
		$wpdb->query('COMMIT');
		if ($delivery === 'send')dxbs_send_document('receipt', (int)$existing);
		return (int)$existing;
	}
	$invoice = dxbs_record_raw('invoice', $p['invoice_id']);
	$snapshot = json_decode($invoice['snapshot'], true);
	$number = dxbs_next_number('receipt');
	if (is_wp_error($number)) {
		$wpdb->query('ROLLBACK');
		return $number;
	}
	$snapshot = array_merge($snapshot, ['type' => 'receipt', 'number' => $number, 'invoice_number' => $invoice['number'], 'invoice_id' => (int)$invoice['id'], 'payment_id' => (int)$payment_id, 'bank_date' => $p['bank_date'], 'verified_at' => $p['verified_at'], 'issue_date' => dxbs_today(), 'amount_minor' => (int)$p['amount_minor'], 'total_minor' => (int)$p['amount_minor'], 'invoice_balance_minor' => dxbs_invoice_balance($invoice['id'])['balance_minor'], 'notes' => 'Receipt for verified bank-transfer funds.', 'lines' => [['description' => 'Verified payment against ' . $invoice['number'], 'quantity_millis' => 1000, 'unit_minor' => (int)$p['amount_minor'], 'total_minor' => (int)$p['amount_minor']]]]);
	$ok = $wpdb->insert(dxbs_table('receipts'), ['payment_id' => $payment_id, 'invoice_id' => $invoice['id'], 'client_id' => $p['client_id'], 'category_id' => $p['category_id'], 'number' => $number, 'amount_minor' => $p['amount_minor'], 'snapshot' => wp_json_encode($snapshot), 'created_by' => get_current_user_id(), 'created_at' => dxbs_now()]);
	if (!$ok) {
		$wpdb->query('ROLLBACK');
		return dxbs_error('receipt', 'Receipt creation failed. The verified payment is retained.');
	}
	$id = (int)$wpdb->insert_id;
	if (!dxbs_fin_audit('receipt_created', 'receipt', $id, ['payment_id' => (int)$payment_id])) return dxbs_rollback('audit', 'The receipt audit could not be saved. The verified payment is retained.');
	$wpdb->query('COMMIT');
	$pdf = dxbs_generate_pdf('receipt', $id);
	if (is_wp_error($pdf)) return dxbs_error('receipt_archive', 'Receipt recorded; PDF archiving needs retry. ' . $pdf->get_error_message());
	if ($delivery === 'send') {
		$mail = dxbs_send_document('receipt', $id);
		if (is_wp_error($mail)) return $mail;
	}
	return $id;
}
function dxbs_reverse_payment($id, $reason, $verified_bank = false) {
	global $wpdb;
	if (!dxbs_is_manager()) return dxbs_require_manager();
	$reason = sanitize_textarea_field($reason);
	if (!$verified_bank || strlen($reason) < 5) return dxbs_error('reverse', 'Confirm the bank reconciliation and provide a reason.');
	$wpdb->query('START TRANSACTION');
	$p = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . dxbs_table('payments') . ' WHERE id=%d FOR UPDATE', $id), ARRAY_A);
	if (!$p || $p['status'] !== 'verified' || $p['reversed_at']) {
		$wpdb->query('ROLLBACK');
		return dxbs_error('reverse', 'This verified payment is unavailable or already reversed.');
	}
	$i = dxbs_record_raw('invoice', $p['invoice_id'], true);
	if (false === $wpdb->update(dxbs_table('payments'), ['reversed_at' => dxbs_now()], ['id' => $id]) || false === $wpdb->update(dxbs_table('receipts'), ['voided_at' => dxbs_now(), 'void_reason' => $reason], ['payment_id' => $id])) return dxbs_rollback('reverse', 'The payment reversal could not be committed.');
	$b = dxbs_invoice_balance($i['id']);
	if (false === $wpdb->update(dxbs_table('invoices'), ['status' => dxbs_invoice_status($i, $b), 'version' => (int)$i['version'] + 1, 'updated_at' => dxbs_now()], ['id' => $i['id']]) || !dxbs_fin_audit('payment_reversed', 'payment', $id, ['reason' => $reason, 'amount_minor' => (int)$p['amount_minor']])) return dxbs_rollback('audit', 'The payment reversal could not be audited.');
	$wpdb->query('COMMIT');
	return true;
}
function dxbs_invoice_payment_url($id) {
	$r = dxbs_document('invoice', $id);
	return $r && $r['issued_at'] && !$r['voided_at'] ? add_query_arg('dxbs_transfer', absint($id), home_url('/')) : '';
}
function dxbs_reports($filters = []) {
	global $wpdb;
	if (!dxbs_is_manager()) return [];
	$p = dxbs_table('payments');
	$i = dxbs_table('invoices');
	$c = dxbs_table('categories');
	$cl = dxbs_table('clients');
	$cn = dxbs_table('credit_notes');
	$q = dxbs_table('quotes');
	$where = ["p.status='verified'", "p.verified_at IS NOT NULL", "p.verified_by>0", "p.reversed_at IS NULL", "p.currency='GBP'"];
	$iw = ["i.issued_at IS NOT NULL", "i.voided_at IS NULL", "i.status NOT IN ('draft','void','cancelled')", "i.currency='GBP'"];
	$qw = ['q.issued_at IS NOT NULL'];
	foreach (['client_id', 'category_id'] as $key) if (!empty($filters[$key])) {
		foreach (['where' => 'p', 'iw' => 'i', 'qw' => 'q'] as $var => $alias)$ {
			$var
		}
		[] = $wpdb->prepare("$alias.$key=%d", absint($filters[$key]));
	}
	foreach (['from' => '>=', 'to' => '<='] as $key => $op) if (!empty($filters[$key]) && dxbs_date($filters[$key])) {
		$where[] = $wpdb->prepare("p.bank_date $op %s", $filters[$key]);
		$iw[] = $wpdb->prepare("DATE(i.issued_at) $op %s", $filters[$key]);
		$qw[] = $wpdb->prepare("DATE(q.issued_at) $op %s", $filters[$key]);
	}
	$ps = implode(' AND ', $where);
	$is = implode(' AND ', $iw);
	$qs = implode(' AND ', $qw);
	$out = ['revenue_received_minor' => 0, 'outstanding_minor' => 0, 'overdue_minor' => 0, 'quotes_issued' => 0, 'quotes_accepted' => 0, 'acceptance_rate' => 0, 'monthly' => [], 'categories' => [], 'payments' => [], 'invoices' => [], 'unpaid_final_minor' => 0, 'deposit_received_minor' => 0, 'pending_transfer_count' => 0, 'overdue_invoice_count' => 0];
	$out['revenue_received_minor'] = (int)$wpdb->get_var("SELECT COALESCE(SUM(p.amount_minor),0) FROM $p p INNER JOIN $i i ON i.id=p.invoice_id WHERE $ps");
	$out['deposit_received_minor'] = (int)$wpdb->get_var("SELECT COALESCE(SUM(p.amount_minor),0) FROM $p p INNER JOIN $i i ON i.id=p.invoice_id WHERE $ps AND i.kind='deposit'");
	$out['monthly'] = $wpdb->get_results("SELECT DATE_FORMAT(p.bank_date,'%Y-%m') AS month,SUM(p.amount_minor) AS received_minor FROM $p p INNER JOIN $i i ON i.id=p.invoice_id WHERE $ps GROUP BY month ORDER BY month", ARRAY_A);
	$out['categories'] = $wpdb->get_results("SELECT p.category_id,COALESCE(c.name,'Unassigned category') AS name,SUM(p.amount_minor) AS received_minor FROM $p p INNER JOIN $i i ON i.id=p.invoice_id LEFT JOIN $c c ON c.id=p.category_id WHERE $ps GROUP BY p.category_id,c.name ORDER BY received_minor DESC", ARRAY_A);
	foreach (['monthly', 'categories'] as $key) foreach ($out[$key] as &$r)$r['received_minor'] = (int)$r['received_minor'];
	unset ($r);
	$out['payments'] = $wpdb->get_results("SELECT p.*,i.number AS invoice_number,cl.name AS client_name,c.name AS category_name FROM $p p INNER JOIN $i i ON i.id=p.invoice_id LEFT JOIN $cl cl ON cl.id=p.client_id LEFT JOIN $c c ON c.id=p.category_id WHERE $ps ORDER BY p.bank_date DESC,p.id DESC LIMIT 200", ARRAY_A);
	$balances = $wpdb->get_results("SELECT i.id,i.number,i.title,i.kind,i.client_id,i.category_id,i.due_date,i.total_minor,cl.name AS client_name,c.name AS category_name,GREATEST(0,CAST(i.total_minor AS SIGNED)-COALESCE(pp.paid,0)-COALESCE(cc.credited,0)) AS balance_minor FROM $i i LEFT JOIN (SELECT invoice_id,SUM(amount_minor) AS paid FROM $p WHERE status='verified' AND verified_at IS NOT NULL AND verified_by>0 AND reversed_at IS NULL AND currency='GBP' GROUP BY invoice_id) pp ON pp.invoice_id=i.id LEFT JOIN (SELECT invoice_id,SUM(total_minor) AS credited FROM $cn WHERE status='issued' GROUP BY invoice_id) cc ON cc.invoice_id=i.id LEFT JOIN $cl cl ON cl.id=i.client_id LEFT JOIN $c c ON c.id=i.category_id WHERE $is ORDER BY i.due_date,i.id", ARRAY_A);
	foreach ($balances as $r) {
		$bal = (int)$r['balance_minor'];
		$out['outstanding_minor'] += $bal;
		if ($r['due_date'] < dxbs_today())$out['overdue_minor'] += $bal;
		if ($r['kind'] === 'final')$out['unpaid_final_minor'] += $bal;
	}
	$out['invoices'] = array_slice($balances, 0, 200);
	$out['pending_transfer_count'] = (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . dxbs_table('payments') . " WHERE status='reported'");
	foreach ($balances as $balance) if ((int)$balance['balance_minor'] > 0 && $balance['due_date'] < dxbs_today())++$out['overdue_invoice_count'];
	$out['quotes_issued'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $q q WHERE $qs");
	$out['quotes_accepted'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $q q WHERE $qs AND q.accepted_at IS NOT NULL");
	$out['acceptance_rate'] = $out['quotes_issued'] ? round(100 * $out['quotes_accepted'] / $out['quotes_issued'], 1) : 0;
	return $out;
}
/** Forward-only batches keep bookkeeping exports complete without retaining the whole ledger in memory. */
function dxbs_export_rows($report, $filters = []) {
	global $wpdb;
	if (!dxbs_is_manager()) return ;
	$map = ['payments' => 'payments', 'invoices' => 'invoices', 'quotes' => 'quotes', 'clients' => 'clients'];
	if (!isset($map[$report])) return ;
	$last = 0;
	$table = dxbs_table($map[$report]);
	do {
		$where = [$wpdb->prepare('d.id>%d', $last)];
		foreach (['client_id', 'category_id'] as $k) if ($report !== 'clients' && !empty($filters[$k]))$where[] = $wpdb->prepare("d.$k=%d", absint($filters[$k]));
		foreach (['from' => '>=', 'to' => '<='] as $key => $op) if (!empty($filters[$key]) && dxbs_date($filters[$key])) {
			$column = $report === 'payments' ? 'd.bank_date' : 'DATE(d.created_at)';
			$where[] = $wpdb->prepare("$column $op %s", $filters[$key]);
		}
		if ($report === 'payments')$where[] = "d.status='verified' AND d.verified_at IS NOT NULL AND d.verified_by>0 AND d.reversed_at IS NULL";
		$rows = $wpdb->get_results("SELECT d.* FROM $table d WHERE " . implode(' AND ', $where) . ' ORDER BY d.id LIMIT 500', ARRAY_A);
		foreach ($rows as $row) {
			$last = (int)$row['id'];
			if ($report === 'payments') {
				$invoice = dxbs_record_raw('invoice', $row['invoice_id']);
				$client = dxbs_client($row['client_id']);
				$row['invoice_number'] = $invoice['number'] ?? '';
				$row['client_name'] = $client['name'] ?? '';
			}
			elseif ($report === 'invoices') {
				$row = dxbs_document('invoice', $row['id']);
			}
			elseif ($report === 'quotes')$row = dxbs_document('quote', $row['id']);
			yield $row;
		}
	}
	while (count($rows) === 500);
}
