<?php
defined('ABSPATH') || exit;
function dxbs_finance_post($action, $manager = true) {
	if ($manager && !dxbs_is_manager())wp_die('Only the authorised business owner may perform this action.', 'Permission denied', ['response' => 403]);
	if (!$manager && !is_user_logged_in())wp_die('Sign in to continue.', 'Sign in', ['response' => 403]);
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), $action))wp_die('Your session expired. Refresh and try again.', 'Invalid request', ['response' => 403]);
	return wp_unslash($_POST);
}
function dxbs_finance_redirect($view, $result, $extra = []) {
	if (dxbs_is_manager()) {
		if (is_array($result))$msg = 'The financial action was completed.';
		elseif (is_wp_error($result))$msg = $result->get_error_message();
		else $msg = 'The financial action was completed.';
		$warning = get_transient('dxbs_payment_warning_' . get_current_user_id());
		if ($warning && !is_wp_error($result)) {
			$msg = $warning;
			delete_transient('dxbs_payment_warning_' . get_current_user_id());
		}
		set_transient('dxbs_notice_' . get_current_user_id(), ['type' => is_wp_error($result) ? 'error' : 'success', 'message' => $msg], 5 * MINUTE_IN_SECONDS);
		$url = add_query_arg(array_merge(['section' => 'business', 'business_view' => $view], $extra), home_url('/dashboard/'));
	}
	else {
		$url = add_query_arg(['section' => 'payments', 'business_notice' => is_wp_error($result) ? 'error' : 'saved'], home_url('/dashboard/'));
		if (is_wp_error($result))set_transient('dxbs_client_notice_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS);
	}
	wp_safe_redirect($url);
	exit;
}
foreach (['quote', 'invoice'] as $type)add_action('admin_post_dxbs_save_' . $type, function () use ($type) {
	$p = dxbs_finance_post('dxbs_save_' . $type);
	$result = dxbs_save_draft($type, is_array($p['document'] ?? null) ? $p['document'] : []);
	dxbs_finance_redirect($type === 'quote' ? 'quotations' : 'invoices', $result, is_numeric($result) ? ['document_id' => $result] : []);
}
);
add_action('admin_post_dxbs_issue_document', function () {
	$p = dxbs_finance_post('dxbs_issue_document');
	$type = sanitize_key($p['type'] ?? '');
	$r = dxbs_issue_document($type, absint($p['id'] ?? 0), absint($p['version'] ?? 0), ($p['confirm_issue'] ?? '') === '1', ($p['send_email'] ?? '') === '1');
	dxbs_finance_redirect($type === 'quote' ? 'quotations' : 'invoices', $r);
}
);
add_action('admin_post_dxbs_duplicate_quote', function () {
	$p = dxbs_finance_post('dxbs_duplicate_quote');
	dxbs_finance_redirect('quotations', dxbs_duplicate_quote(absint($p['id'] ?? 0)));
}
);
add_action('admin_post_dxbs_import_quote', function () {
	$p = dxbs_finance_post('dxbs_import_quote');
	dxbs_finance_redirect('quotations', dxbs_import_quote($p['reference'] ?? '', absint($p['client_id'] ?? 0), absint($p['category_id'] ?? 0), absint($p['project_id'] ?? 0), ($p['reviewed'] ?? '') === '1'));
}
);
add_action('admin_post_dxbs_convert_quote', function () {
	$p = dxbs_finance_post('dxbs_convert_quote');
	dxbs_finance_redirect('invoices', dxbs_convert_quote(absint($p['id'] ?? 0), absint($p['version'] ?? 0), sanitize_key($p['plan'] ?? 'full'), absint($p['count'] ?? 1), $p['first_due_date'] ?? ''));
}
);
add_action('admin_post_dxbs_accept_quote', function () {
	$p = dxbs_finance_post('dxbs_accept_quote', false);
	dxbs_finance_redirect('quotations', dxbs_accept_quote(absint($p['id'] ?? 0), absint($p['version'] ?? 0), ($p['confirmation'] ?? '') === '1'));
}
);
add_action('admin_post_dxbs_void_invoice', function () {
	$p = dxbs_finance_post('dxbs_void_invoice');
	dxbs_finance_redirect('invoices', dxbs_void_invoice(absint($p['id'] ?? 0), $p['reason'] ?? '', ($p['confirm_void'] ?? '') === '1'));
}
);
add_action('admin_post_dxbs_credit_invoice', function () {
	$p = dxbs_finance_post('dxbs_credit_invoice');
	dxbs_finance_redirect('invoices', dxbs_credit_invoice(absint($p['id'] ?? 0), $p['amount'] ?? '', $p['reason'] ?? '', $p['supply_date'] ?? '', ($p['confirm_credit'] ?? '') === '1'));
}
);
add_action('admin_post_dxbs_prepare_payment', function () {
	$p = dxbs_finance_post('dxbs_prepare_payment');
	dxbs_finance_redirect('payments', dxbs_prepare_payment($p));
}
);
add_action('admin_post_dxbs_verify_payment', function () {
	$p = dxbs_finance_post('dxbs_verify_payment');
	dxbs_finance_redirect('payments', dxbs_verify_payment($p['token'] ?? '', ($p['verified_bank'] ?? '') === '1'));
}
);
add_action('admin_post_dxbs_reverse_payment', function () {
	$p = dxbs_finance_post('dxbs_reverse_payment');
	dxbs_finance_redirect('payments', dxbs_reverse_payment(absint($p['payment_id'] ?? 0), $p['reason'] ?? '', ($p['verified_bank'] ?? '') === '1'));
}
);
add_action('admin_post_dxbs_receipt', function () {
	$p = dxbs_finance_post('dxbs_receipt');
	dxbs_finance_redirect('receipts', dxbs_create_receipt(absint($p['payment_id'] ?? 0), ($p['delivery'] ?? 'hold') === 'send' ? 'send' : 'hold'));
}
);
add_action('admin_post_dxbs_send_document', function () {
	$p = dxbs_finance_post('dxbs_send_document');
	if (($p['confirm_send'] ?? '') !== '1')dxbs_finance_redirect('documents', dxbs_error('confirm_send', 'Explicitly authorise this document email.'));
	dxbs_finance_redirect('documents', dxbs_send_document(sanitize_key($p['type'] ?? ''), absint($p['id'] ?? 0)));
}
);
add_action('admin_post_dxbs_retry_email', function () {
	$p = dxbs_finance_post('dxbs_retry_email');
	if (($p['confirm_send'] ?? '') !== '1')dxbs_finance_redirect('documents', dxbs_error('confirm_send', 'Explicitly authorise this email retry.'));
	dxbs_finance_redirect('documents', dxbs_retry_email(absint($p['job_id'] ?? 0)));
}
);
add_action('admin_post_dxbs_rotate_payment_link', function () {
	$p = dxbs_finance_post('dxbs_rotate_payment_link');
	if (($p['confirm_rotate'] ?? '') !== '1')dxbs_finance_redirect('invoices', dxbs_error('confirm_rotate', 'Confirm that existing shared payment links should be revoked.'));
	$id = absint($p['invoice_id'] ?? 0);
	$link = dxbs_payment_link($id, true);
	if ($link)set_transient('dxbs_payment_link_' . get_current_user_id(), $link, 10 * MINUTE_IN_SECONDS);
	dxbs_finance_redirect('invoices', $link ? : dxbs_error('link', 'The invoice link could not be created.'), ['document_id' => $id]);
}
);
function dxbs_latest_payment_link() {
	return dxbs_is_manager() ? get_transient('dxbs_payment_link_' . get_current_user_id()) : '';
}
function dxbs_transfer_form() {
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return ;
	$id = absint($_POST['invoice_id'] ?? 0);
	$token = sanitize_text_field(wp_unslash($_POST['token'] ?? $_POST['access'] ?? ''));
	if (!dxbs_transfer_authorized($id, $token) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'dxbs_report_transfer_' . $id . '_' . $token))wp_die('This transfer report could not be authorised.', 'Permission denied', ['response' => 403]);
	$r = dxbs_report_transfer($id, $token);
	$url = add_query_arg(['dxbs_transfer' => $id, 'access' => $token, 'transfer_reported' => is_wp_error($r) ? 0 : 1], home_url('/'));
	wp_safe_redirect($url);
	exit;
}
add_action('admin_post_dxbs_report_transfer', 'dxbs_transfer_form');
add_action('admin_post_nopriv_dxbs_report_transfer', 'dxbs_transfer_form');
function dxbs_no_cache_headers() {
	nocache_headers();
	header('X-Robots-Tag: noindex, nofollow, noarchive', true);
	header('Referrer-Policy: no-referrer', true);
	header('Cache-Control: no-store, private', true);
}
add_action('template_redirect', function () {
	if (isset($_GET['dxbs_transfer'])) {
		$id = absint($_GET['dxbs_transfer']);
		$token = sanitize_text_field(wp_unslash($_GET['access'] ?? ''));
		dxbs_no_cache_headers();
		if (!dxbs_transfer_authorized($id, $token))wp_die('This invoice-specific payment link is unavailable or expired.', 'Payment link unavailable', ['response' => 403]);
		$row = dxbs_record_raw('invoice', $id);
		$invoice = $row;
		$invoice['snapshot'] = json_decode($row['snapshot'], true);
		$invoice = array_merge($invoice, dxbs_invoice_balance($id));
		$settings = array_merge(dxbs_settings_defaults(), (array)get_option('dxbs_settings', []));
		$invoice['bank'] = ['account_holder' => sanitize_text_field($settings['bank_account_holder']), 'bank_name' => sanitize_text_field($settings['bank_name']), 'sort_code' => sanitize_text_field($settings['bank_sort_code']), 'account_number' => sanitize_text_field($settings['bank_account_number'])];
		$invoice['bank_details'] = ['bank_account_holder' => $invoice['bank']['account_holder'], 'bank_name' => $invoice['bank']['bank_name'], 'bank_sort_code' => $invoice['bank']['sort_code'], 'bank_account_number' => $invoice['bank']['account_number']];
		$invoice['bank_ready'] = $invoice['bank']['account_holder'] && $invoice['bank']['sort_code'] && $invoice['bank']['account_number'];
		$invoice['reported'] = (bool)$GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT id FROM ' . dxbs_table('payments') . " WHERE invoice_id=%d AND status='reported' LIMIT 1", $id));
		$invoice['report_nonce'] = wp_create_nonce('dxbs_report_transfer_' . $id . '_' . $token);
		$invoice['status'] = dxbs_invoice_status($row, dxbs_invoice_balance($id));
		$invoice['project_total_minor'] = (int)($row['quote_id'] ? dxbs_record_raw('quote', $row['quote_id'])['total_minor'] : $row['total_minor']);
		if (function_exists('dxcp_business_render_transfer')) {
			dxcp_business_render_transfer($invoice, $token);
			exit;
		}
		dxbs_fallback_transfer($invoice, $token);
		exit;
	}
	if (isset($_GET['dxbs_document'])) {
		$type = sanitize_key($_GET['dxbs_document']);
		$id = absint($_GET['id'] ?? 0);
		dxbs_no_cache_headers();
		$r = dxbs_document($type, $id);
		if (!$r)wp_die('Sign in to the account assigned to this document.', 'Private document', ['response' => 403]);
		if (!empty($r['voided_at']) && !dxbs_is_manager())wp_die('This document was voided. Please review the current records in your client space.', 'Voided document', ['response' => 410]);
		if (in_array($type, ['quote', 'invoice'], true) && !$r['issued_at']) {
			$s = dxbs_snapshot($type, $r, 'DRAFT');
			if (is_wp_error($s)) {
				$s = ['type' => $type, 'number' => 'DRAFT', 'title' => $r['title'], 'supply_date' => $r['supply_date'], 'business' => dxbs_settings(), 'client' => dxbs_client($r['client_id']), 'lines' => $r['draft']['lines'] ?? [], 'total_minor' => $r['total_minor'], 'subtotal_minor' => $r['subtotal_minor'], 'tax_minor' => $r['tax_minor'], 'discount_minor' => $r['discount_minor']];
			}
			$bytes = dxbs_pdf_bytes(dxbs_pdf_html($type, $s, true));
			if (is_wp_error($bytes))wp_die(esc_html($bytes->get_error_message()));
			header('Content-Type: application/pdf');
			header('Content-Disposition: inline; filename="DRAFT.pdf"');
			echo $bytes;
			exit;
		}
		$path = dxbs_pdf_path($type, $id);
		if (!$path) {
			$path = dxbs_generate_pdf($type, $id);
			if (is_wp_error($path))wp_die(esc_html($path->get_error_message()));
		}
		header('Content-Type: application/pdf');
		header('Content-Disposition: inline; filename="' . sanitize_file_name($r['number']) . '.pdf"');
		header('X-Content-Type-Options: nosniff');
		header('Content-Length: ' . filesize($path));
		readfile($path);
		exit;
	}
}
, 0);
function dxbs_fallback_transfer($i, $token) {
	$s = $i['snapshot'];
	?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bank transfer · <?php
	echo esc_html($i['number']);
	?></title><style>body{margin:0;background:#f2f1eb;color:#1d261e;font:16px Arial,sans-serif}main{max-width:680px;margin:4vh auto;background:white;padding:32px;border-top:4px solid #c9a74d}h1{font-size:32px}dl{display:grid;grid-template-columns:1fr 1fr;gap:16px}dd{margin:0;font-weight:bold}button{padding:14px;background:#d3b459;color:#19211b;border:0;cursor:pointer}input{padding:12px;border:1px solid #bbb;width:100%;box-sizing:border-box}.note{background:#f2eee0;padding:16px}</style></head><body><main><h1>dx. Bank transfer</h1><p><?php
	echo esc_html($s['client']['name'] ?? '');
	?> · <?php
	echo esc_html($i['number']);
	?></p><p><?php
	echo esc_html($i['title']);
	?></p><dl><dt>Project value</dt><dd><?php
	echo esc_html(dxbs_money($i['project_total_minor']));
	?></dd><dt>Outstanding now</dt><dd><?php
	echo esc_html(dxbs_money($i['balance_minor']));
	?></dd><dt>Status</dt><dd><?php
	echo esc_html(ucwords(str_replace('_', ' ', $i['status'])));
	?></dd></dl><?php
	if ($i['bank_ready'] && $i['balance_minor']) : foreach (['account_holder' => 'Account holder', 'bank_name' => 'Bank', 'sort_code' => 'Sort code', 'account_number' => 'Account number'] as $k => $label) : ?><label><?php
	echo esc_html($label);
	?><input value="<?php
	echo esc_attr($i['bank'][$k]);
	?>" readonly onclick="this.select()"></label><?php
	endforeach;
	?><label>Payment reference<input value="<?php
	echo esc_attr($i['payment_reference'] ? : $i['number']);
	?>" readonly onclick="this.select()"></label><p>Use the exact account-holder name and payment reference above. Select a value to copy it.</p><form method="post" action="<?php
	echo esc_url(admin_url('admin-post.php'));
	?>"><input type="hidden" name="action" value="dxbs_report_transfer"><input type="hidden" name="invoice_id" value="<?php
	echo absint($i['id']);
	?>"><input type="hidden" name="access" value="<?php
	echo esc_attr($token);
	?>"><input type="hidden" name="_wpnonce" value="<?php
	echo esc_attr($i['report_nonce']);
	?>"><button><?php
	echo $i['reported'] ? 'Transfer reported — awaiting review' : 'I’ve made the transfer';
	?></button></form><p class="note">A transfer report does not mark an invoice paid or generate a receipt. D’André first checks that the money has reached the bank account.</p><?php
	elseif (!$i['bank_ready']) : ?><p class="note">Bank instructions are not configured yet. Please contact D’André before making a transfer.</p><?php
	else : ?><p>This invoice has no outstanding balance.</p><?php
	endif;
	?></main></body></html><?php
}
add_action('admin_post_dxbs_export', function () {
	dxbs_finance_post('dxbs_export');
	$report = sanitize_key($_POST['report'] ?? 'payments');
	$filters = [];
	foreach (['from', 'to', 'category_id', 'client_id'] as $k)$filters[$k] = sanitize_text_field(wp_unslash($_POST[$k] ?? ''));
	$rows = dxbs_export_rows($report, $filters);
	if (!in_array($report, ['payments', 'invoices', 'quotes', 'clients'], true))wp_die('Invalid export.');
	dxbs_no_cache_headers();
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="dxndre-' . $report . '-' . dxbs_today() . '.csv"');
	$out = fopen('php://output', 'w');
	$keys = $report === 'payments' ? ['id', 'invoice_number', 'client_name', 'amount_minor', 'bank_date', 'status', 'verified_at'] : ($report === 'invoices' ? ['id', 'number', 'client_name', 'kind', 'total_minor', 'balance_minor', 'due_date'] : ($report === 'quotes' ? ['id', 'number', 'client_name', 'total_minor', 'status', 'issued_at', 'accepted_at'] : ['id', 'name', 'company', 'email', 'phone', 'status']));
	fputcsv($out, $keys);
	foreach ($rows as $r) {
		$line = [];
		foreach ($keys as $k) {
			$v = (string)($r[$k] ?? '');
			if (preg_match('/^[=+\-@\t\r]/', $v))$v = "'" . $v;
			$line[] = $v;
		}
		fputcsv($out, $line);
	}
	fclose($out);
	dxbs_fin_audit('csv_exported', 'report', 0);
	exit;
}
);
