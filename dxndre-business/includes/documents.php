<?php
/** Private PDF archive, guarded mail queue and opt-in reminders. */
defined('ABSPATH') || exit;
function dxbs_document_url($type, $id) {
	return add_query_arg(['dxbs_document' => sanitize_key($type), 'id' => absint($id)], home_url('/'));
}
function dxbs_private_dir() {
	return dirname(untrailingslashit(ABSPATH)) . '/dxndre-business-private';
}
function dxbs_pdf_path($type, $id) {
	global $wpdb;
	$row = dxbs_record_raw($type, $id);
	if (!$row) return '';
	$issued_snapshot = json_decode($row['snapshot'] ?? '{}', true);
	$version = (int)($issued_snapshot['version'] ?? 1);
	$archive = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . dxbs_table('archives') . ' WHERE entity_type=%s AND entity_id=%d AND version=%d', $type, $id, $version), ARRAY_A);
	if (!$archive || !preg_match('/^[a-f0-9]{64}\.pdf$/D', $archive['file_key'])) return '';
	$path = dxbs_private_dir() . '/' . $archive['file_key'];
	return is_file($path) && hash_equals($archive['sha256'], hash_file('sha256', $path)) ? $path : '';
}
function dxbs_money($n) {
	return '£' . number_format((int)$n / 100, 2, '.', ',');
}
function dxbs_pdf_html($type, $s, $draft = false) {
	$business = $s['business'] ?? [];
	$client = $s['client'] ?? [];
	$address = fn($a, $prefix = '') => implode('<br>', array_map('esc_html', array_filter([$a[$prefix . 'address_1'] ?? '', $a[$prefix . 'address_2'] ?? '', $a[$prefix . 'city'] ?? '', $a[$prefix . 'region'] ?? '', $a[$prefix . 'postcode'] ?? '', $a[$prefix . 'country'] ?? ''])));
	$labels = ['quote' => 'QUOTATION', 'invoice' => 'INVOICE', 'credit' => 'CREDIT NOTE', 'receipt' => 'PAYMENT RECEIPT'];
	ob_start();
	?><!doctype html><html><head><meta charset="UTF-8"><style>@page{margin:42px 44px 55px}body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#242724;line-height:1.55}header{border-bottom:2px solid #c8a14c;padding-bottom:20px;margin-bottom:30px}.brand{font-size:33px;font-weight:bold;letter-spacing:-2px}.eyebrow{font-size:9px;letter-spacing:2px;color:#92712e}h1{font-size:27px;font-weight:normal;margin:6px 0 2px}h2{font-size:17px;font-weight:normal}table{width:100%;border-collapse:collapse}td,th{vertical-align:top}th{text-align:left;font-size:9px;color:#746849;padding:10px 7px;border-bottom:1px solid #b49d69}td{padding:12px 7px;border-bottom:1px solid #e5e5df}.parties td{width:50%;border:0;padding:0 0 24px}.right{text-align:right}.muted{color:#73786f}.total{background:#f1ecdf;font-weight:bold}.notes{margin-top:25px}.footer{position:fixed;bottom:-30px;font-size:8px;color:#777;border-top:1px solid #ddd;width:100%;padding-top:9px}.draft{color:#9b7729;font-weight:bold}.small{font-size:8px}</style></head><body><header><table><tr><td style="border:0;padding:0"><div class="brand">dx.</div><div class="eyebrow"><?php
	echo esc_html($s['category']['name'] ?? 'DXNDRE BUSINESS');
	?></div></td><td class="right" style="border:0;padding:0"><div class="eyebrow"><?php
	echo esc_html($labels[$type] ?? 'DOCUMENT');
	?></div><h1><?php
	echo esc_html($s['number'] ?? 'DRAFT');
	?></h1><?php
	if ($draft) : ?><div class="draft">DRAFT — NOT ISSUED</div><?php
	endif;
	?><div>Issued: <?php
	echo esc_html($s['issue_date'] ?? 'Not issued');
	?></div><div>Supply date: <?php
	echo esc_html($s['supply_date'] ?? 'To be confirmed');
	?></div><?php
	if ($type === 'invoice' && !empty($s['due_date'])) : ?><div>Due: <?php
	echo esc_html($s['due_date']);
	?></div><?php
	endif;
	if (!empty($s['expires_at']) && $type === 'quote') : ?><div>Valid until: <?php
	echo esc_html(substr($s['expires_at'], 0, 10));
	?></div><?php
	endif;
	?></td></tr></table></header><table class="parties"><tr><td><div class="eyebrow">FROM</div><strong><?php
	echo esc_html($business['legal_name'] ?? '');
	?></strong><br><?php
	echo esc_html($business['business_name'] ?? 'DXNDRE');
	?><br><?php
	echo $address($business);
	?><br><?php
	echo esc_html($business['email'] ?? '');
	?><br><?php
	echo esc_html($business['phone'] ?? '');
	?><?php
	if (!empty($business['company_number'])) : ?><br>Company no. <?php
	echo esc_html($business['company_number']);
	?><?php
	endif;
	if (!empty($business['vat_registered'])) : ?><br>VAT no. <?php
	echo esc_html($business['vat_number']);
	?><?php
	endif;
	?></td><td><div class="eyebrow">TO</div><strong><?php
	echo esc_html($client['company'] ? : ($client['name'] ?? ''));
	?></strong><br><?php
	if (!empty($client['company'])) echo esc_html($client['name']) . '<br>';
	echo $address($client, 'billing_');
	?><br><?php
	echo esc_html($client['email'] ?? '');
	?></td></tr></table><h2><?php
	echo esc_html($s['title'] ?? '');
	?></h2><?php
	if ($type === 'receipt') : ?><p>Invoice: <strong><?php
	echo esc_html($s['invoice_number']);
	?></strong><br>Bank payment date: <?php
	echo esc_html($s['bank_date']);
	?><br>Verified: <?php
	echo esc_html($s['verified_at']);
	?> UTC</p><table><tr class="total"><td>Verified payment received</td><td class="right"><?php
	echo esc_html(dxbs_money($s['amount_minor']));
	?></td></tr><tr><td>Invoice balance after this payment</td><td class="right"><?php
	echo esc_html(dxbs_money($s['invoice_balance_minor']));
	?></td></tr></table><p>This receipt acknowledges the verified payment shown above. It is not a replacement VAT invoice.</p><?php
	else : if ($type === 'credit') : ?><p>Corrects invoice <?php
	echo esc_html($s['original_invoice'] ?? '');
	?> dated <?php
	echo esc_html($s['original_invoice_date'] ?? '');
	?>.</p><?php
	endif;
	?><table><thead><tr><th>Description</th><th class="right">Qty</th><th class="right">Unit price</th><th class="right">Discount</th><th class="right">VAT</th><th class="right">Amount</th></tr></thead><tbody><?php
	foreach ($s['lines'] ?? [] as $l) : ?><tr><td><?php
	echo nl2br(esc_html($l['description']));
	?></td><td class="right"><?php
	echo esc_html(rtrim(rtrim(number_format((int)($l['quantity_millis'] ?? 1000) / 1000, 3, '.', ''), '0'), '.'));
	?></td><td class="right"><?php
	echo esc_html(dxbs_money($l['unit_minor'] ?? 0));
	?></td><td class="right"><?php
	echo esc_html(dxbs_money($l['discount_minor'] ?? 0));
	?></td><td class="right"><?php
	echo esc_html(number_format((int)($l['tax_basis_points'] ?? 0) / 100, 2)) . '%';
	?><br><span class="small"><?php
	echo esc_html(dxbs_money($l['tax_minor'] ?? 0));
	?></span></td><td class="right"><?php
	echo esc_html(dxbs_money($l['total_minor'] ?? 0));
	?></td></tr><?php
	endforeach;
	?></tbody></table><table><tr><td>Subtotal</td><td class="right"><?php
	echo esc_html(dxbs_money($s['subtotal_minor'] ?? 0));
	?></td></tr><?php
	if (!empty($s['discount_minor'])) : ?><tr><td>Discount</td><td class="right">−<?php
	echo esc_html(dxbs_money($s['discount_minor']));
	?></td></tr><?php
	endif;
	?><tr><td><?php
	echo !empty($business['vat_registered']) ? 'VAT' : 'VAT not charged — not VAT registered';
	?></td><td class="right"><?php
	echo esc_html(dxbs_money($s['tax_minor'] ?? 0));
	?></td></tr><tr class="total"><td><?php
	echo $type === 'credit' ? 'Total credit' : 'Total GBP';
	?></td><td class="right"><?php
	echo esc_html(dxbs_money($s['total_minor'] ?? 0));
	?></td></tr><?php
	if ($type === 'quote' && !empty($s['deposit_minor'])) : ?><tr><td>Deposit requirement</td><td class="right"><?php
	echo esc_html(dxbs_money($s['deposit_minor']));
	?></td></tr><?php
	endif;
	?></table><?php
	if ($type === 'credit' && empty($business['vat_registered'])) : ?><p>This is not a credit note for VAT.</p><?php
	endif;
	endif;
	?><div class="notes"><?php
	if (!empty($s['terms'])) : ?><strong>Payment terms</strong><p><?php
	echo nl2br(esc_html($s['terms']));
	?></p><?php
	endif;
	if (!empty($s['notes'])) : ?><strong>Notes</strong><p><?php
	echo nl2br(esc_html($s['notes']));
	?></p><?php
	endif;
	?></div><div class="footer"><?php
	echo esc_html($business['business_name'] ?? 'DXNDRE');
	?> · <?php
	echo esc_html($s['number'] ?? 'Draft');
	?> · <?php
	echo esc_html($s['category']['name'] ?? '');
	?> · GBP<?php
	if ($type === 'invoice') : ?> · Please quote the invoice number with your payment.<?php
	endif;
	?></div></body></html><?php
	return ob_get_clean();
}
function dxbs_pdf_bytes($html) {
	if (!class_exists('\Dompdf\Dompdf')) {
		$autoload = WP_PLUGIN_DIR . '/pricing-calculator/vendor/autoload.php';
		if (is_file($autoload))require_once $autoload;
	}
	if (!class_exists('\Dompdf\Dompdf')) return dxbs_error('pdf_engine', 'The existing Dompdf engine is unavailable.');
	try {
		$options = new \Dompdf\Options();
		$options->set('isRemoteEnabled', false);
		$options->set('isPhpEnabled', false);
		$options->set('isJavascriptEnabled', false);
		$options->set('defaultFont', 'DejaVu Sans');
		$options->set('chroot', [__DIR__]);
		$pdf = new \Dompdf\Dompdf($options);
		$pdf->loadHtml($html, 'UTF-8');
		$pdf->setPaper('A4', 'portrait');
		$pdf->render();
		$canvas = $pdf->getCanvas();
		$font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
		$canvas->page_text(470, 823, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7, [0.45, 0.47, 0.44]);
		return $pdf->output();
	}
	catch (Throwable $e) {
		return dxbs_error('pdf_engine', 'PDF rendering failed. Please retry from the document.');
	}
}
function dxbs_generate_pdf($type, $id) {
	global $wpdb;
	if (!dxbs_is_manager() && !dxbs_document($type, $id)) return dxbs_error('forbidden', 'You cannot create this document.');
	$row = dxbs_record_raw($type, $id);
	if (!$row || in_array($type, ['quote', 'invoice'], true) && !$row['issued_at']) return dxbs_error('draft', 'Only issued documents are archived.');
	$existing = dxbs_pdf_path($type, $id);
	if ($existing) return $existing;
	$s = json_decode($row['snapshot'], true);
	if (!$s) return dxbs_error('snapshot', 'The issued document snapshot is unavailable.');
	$bytes = dxbs_pdf_bytes(dxbs_pdf_html($type, $s));
	if (is_wp_error($bytes)) return $bytes;
	$dir = dxbs_private_dir();
	if (!wp_mkdir_p($dir)) return dxbs_error('archive', 'Private document storage could not be created.');
	$key = bin2hex(random_bytes(32)) . '.pdf';
	$path = $dir . '/' . $key;
	if (false === file_put_contents($path, $bytes, LOCK_EX)) return dxbs_error('archive', 'The private PDF could not be saved.');
	@chmod($path, 0600);
	$ok = $wpdb->insert(dxbs_table('archives'), ['entity_type' => $type, 'entity_id' => $id, 'version' => (int)($s['version'] ?? 1), 'file_key' => $key, 'sha256' => hash('sha256', $bytes), 'created_at' => dxbs_now()]);
	if (!$ok) {
		@unlink($path);
		$race = dxbs_pdf_path($type, $id);
		return $race ? : dxbs_error('archive', 'The PDF archive index could not be saved.');
	}
	if ($type === 'receipt')$wpdb->update(dxbs_table('receipts'), ['private_file_key' => $key], ['id' => $id]);
	dxbs_fin_audit('pdf_archived', $type, $id);
	return $path;
}
function dxbs_email_jobs($args = []) {
	global $wpdb;
	if (!dxbs_is_manager()) return [];
	$where = '1=1';
	if (!empty($args['status']))$where = $wpdb->prepare('status=%s', sanitize_key($args['status']));
	$rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . dxbs_table('email_jobs') . " WHERE $where ORDER BY id DESC LIMIT %d", min(200, max(1, (int)($args['limit'] ?? 100)))), ARRAY_A);
	foreach ($rows as &$r) {
		$p = json_decode($r['payload'], true);
		$r['recipient'] = $p['to'] ?? '';
		$r['subject'] = $p['subject'] ?? '';
		unset ($r['payload']);
	}
	return $rows;
}
function dxbs_queue_email($template, $type, $id, $dedupe, $to, $subject, $body, $attachments = [], $authorised_by = 0, $hold = false) {
	global $wpdb;
	$owner = get_user_by('login', 'dxndre');
	$actor = $authorised_by ? : get_current_user_id();
	if (!$owner || (int)$owner->ID !== $actor || !user_can($owner, 'manage_options') || !is_email($to)) return dxbs_error('mail_authority', 'This email has not been authorised by the business owner.');
	$payload = ['to' => $to, 'subject' => $subject, 'body' => $body, 'attachments' => $attachments];
	$existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . dxbs_table('email_jobs') . ' WHERE dedupe_key=%s', $dedupe));
	if ($existing) return (int)$existing;
	$ok = $wpdb->insert(dxbs_table('email_jobs'), ['entity_type' => $type, 'entity_id' => $id, 'template' => $template, 'dedupe_key' => $dedupe, 'status' => $hold ? 'held' : 'queued', 'payload' => wp_json_encode($payload), 'authorised_by' => $actor, 'authorised_at' => dxbs_now(), 'created_at' => dxbs_now(), 'last_error' => '']);
	if (!$ok) return dxbs_error('queue', 'The email could not be queued.');
	$job = (int)$wpdb->insert_id;
	dxbs_fin_audit('email_queued', $type, $id);
	if (!$hold)wp_schedule_single_event(time() + 2, 'dxbs_process_email', [$job]);
	return $job;
}
function dxbs_email_html($heading, $copy, $link = '', $label = 'Open client space') {
	return '<div style="background:#f3f2ec;padding:32px;font-family:Arial,sans-serif;color:#202720"><table role="presentation" style="max-width:620px;width:100%;margin:auto;background:#fff;border-top:4px solid #c8a14c;padding:32px"><tr><td><p style="font-size:32px;font-weight:bold">dx.</p><h1 style="font-size:25px;font-weight:normal">' . esc_html($heading) . '</h1><p style="line-height:1.7">' . nl2br(esc_html($copy)) . '</p>' . ($link ? '<p><a style="display:inline-block;background:#d2af59;color:#172016;padding:14px 22px;text-decoration:none" href="' . esc_url($link) . '">' . esc_html($label) . '</a></p>' : '') . '<p style="color:#74786f;font-size:12px">DXNDRE Business · A clear view of the next step.<br>If the button does not open, use: ' . esc_html($link) . '</p></td></tr></table></div>';
}
function dxbs_send_document($type, $id) {
	if (!dxbs_is_manager()) return dxbs_require_manager();
	$r = dxbs_record_raw($type, $id);
	if (!$r || in_array($type, ['quote', 'invoice'], true) && !$r['issued_at']) return dxbs_error('draft', 'Issue the document before sending it.');
	if ($type === 'receipt') {
		$p = dxbs_payment_for_receipt($r);
		if (!$p) return dxbs_error('unverified', 'This receipt is unavailable because its payment is not verified or was reversed.');
	}
	if (!empty($r['voided_at'])) return dxbs_error('void', 'A void document cannot be delivered as payable.');
	$pdf = dxbs_generate_pdf($type, $id);
	if (is_wp_error($pdf)) return $pdf;
	$s = json_decode($r['snapshot'], true);
	$to = $s['client']['email'] ?? '';
	$label = ['quote' => 'Your quotation', 'invoice' => 'Your invoice', 'credit' => 'Your credit note', 'receipt' => 'Your payment receipt'][$type] ?? 'Your document';
	$link = dxbs_document_url($type, $id);
	if ($type === 'invoice') {
		$settings = dxbs_settings();
		if ($settings['bank_account_holder'] && $settings['bank_sort_code'] && $settings['bank_account_number'])$link = dxbs_payment_link($id);
	}
	$copy = 'Please find ' . $r['number'] . ' attached as a PDF. ' . ($type === 'receipt' ? 'This receipt is for funds manually verified in the bank account.' : ($type === 'quote' ? 'Review the scope and terms before accepting in your client space.' : 'Please review the document and its payment terms.'));
	return dxbs_queue_email('document_' . $type, $type, $id, 'document-' . $type . '-' . $id . '-' . ($s['version'] ?? 1), $to, $label . ' ' . $r['number'], dxbs_email_html($label, $copy, $link, $type === 'invoice' ? 'Bank transfer instructions' : 'Open document'), [$pdf]);
}
function dxbs_payment_for_receipt($receipt) {
	global $wpdb;
	return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . dxbs_table('payments') . " WHERE id=%d AND status='verified' AND verified_at IS NOT NULL AND verified_by>0 AND reversed_at IS NULL", $receipt['payment_id']), ARRAY_A);
}
function dxbs_queue_event($event, $type, $id, $dedupe) {
	$owner = get_user_by('login', 'dxndre');
	if (!$owner || !user_can($owner, 'manage_options')) return false;
	$row = dxbs_record_raw($type, $id);
	if (!$row) return false;
	$s = json_decode($row['snapshot'], true) ? : [];
	$admin = in_array($event, ['quote_accepted', 'transfer_reported'], true);
	$to = $admin ? $owner->user_email : ($s['client']['email'] ?? '');
	$head = ['quote_accepted' => 'Quotation accepted', 'transfer_reported' => 'Client reports a bank transfer', 'payment_verified' => 'Bank payment verified'][$event] ?? 'Project update';
	$copy = $event === 'transfer_reported' ? 'The client reports making a transfer. This has not marked the invoice paid. Check your bank account and use the two-step Record Payment review before recording funds.' : ($event === 'payment_verified' ? 'A bank payment has been manually verified and recorded against your invoice. Any receipt follows the recorded verification.' : 'The assigned client explicitly accepted the current issued quotation.');
	return dxbs_queue_email($event, $type, $id, $dedupe, $to, $head . ' ' . $row['number'], dxbs_email_html($head, $copy, home_url('/dashboard/')), [], (int)$owner->ID);
}
add_action('dxbs_process_email', 'dxbs_process_email');
function dxbs_process_email($id) {
	global $wpdb;
	$t = dxbs_table('email_jobs');
	$wpdb->query('START TRANSACTION');
	$job = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d FOR UPDATE", $id), ARRAY_A);
	if (!$job || !in_array($job['status'], ['queued', 'retry'], true) || $job['attempts'] >= 3) {
		$wpdb->query('ROLLBACK');
		return false;
	}
	$owner = get_userdata($job['authorised_by']);
	if (!$owner || $owner->user_login !== 'dxndre' || !user_can($owner, 'manage_options')) {
		$wpdb->update($t, ['status' => 'held', 'last_error' => 'Business owner authorisation is unavailable.'], ['id' => $id]);
		$wpdb->query('COMMIT');
		return false;
	}
	$record = dxbs_record_raw($job['entity_type'], $job['entity_id']);
	if (!$record || !empty($record['voided_at']) || ($job['entity_type'] === 'receipt' && !dxbs_payment_for_receipt($record))) {
		$wpdb->update($t, ['status' => 'cancelled', 'last_error' => 'Document or verified payment is no longer available.'], ['id' => $id]);
		$wpdb->query('COMMIT');
		return false;
	}
	if ($job['template'] === 'payment_reminder' && (empty(get_option('dxbs_settings', [])['reminders_enabled']) || !dxbs_invoice_balance($record['id'])['balance_minor'] || $record['voided_at'])) {
		$wpdb->update($t, ['status' => 'cancelled'], ['id' => $id]);
		$wpdb->query('COMMIT');
		return false;
	}
	if (str_starts_with($job['template'], 'document_')) {
		$verified_archive = dxbs_pdf_path($job['entity_type'], $job['entity_id']);
		if (!$verified_archive) {
			$wpdb->update($t, ['status' => 'failed', 'last_error' => 'The private PDF archive is missing or failed its integrity check. Rebuild it before retrying.'], ['id' => $id]);
			$wpdb->query('COMMIT');
			return false;
		}
		$payload = json_decode($job['payload'], true);
		$payload['attachments'] = [$verified_archive];
		$job['payload'] = wp_json_encode($payload);
	}
	$wpdb->update($t, ['status' => 'sending', 'attempts' => (int)$job['attempts'] + 1], ['id' => $id]);
	$wpdb->query('COMMIT');
	$p = json_decode($job['payload'], true);
	$s = json_decode($record['snapshot'], true) ? : [];
	$sender = $s['business']['email'] ?? $owner->user_email;
	$name = $s['business']['business_name'] ?? 'DXNDRE';
	$attachments = [];
	foreach ($p['attachments'] ?? [] as $path) if (is_string($path) && realpath(dirname($path)) === realpath(dxbs_private_dir()) && is_file($path))$attachments[] = $path;
	try {
		$sent = wp_mail($p['to'], $p['subject'], $p['body'], ['Content-Type: text/html; charset=UTF-8', 'From: ' . sanitize_text_field($name) . ' <' . sanitize_email($sender) . '>'], $attachments);
	}
	catch (Throwable $e) {
		$sent = false;
	}
	$wpdb->update($t, $sent ? ['status' => 'sent', 'sent_at' => dxbs_now(), 'last_error' => ''] : ['status' => 'failed', 'last_error' => 'The WordPress mail transport did not accept the message. Review transport configuration and retry manually.'], ['id' => $id]);
	dxbs_fin_audit($sent ? 'email_transport_accepted' : 'email_failed', $job['entity_type'], $job['entity_id']);
	return (bool)$sent;
}
function dxbs_retry_email($id) {
	global $wpdb;
	if (!dxbs_is_manager()) return dxbs_require_manager();
	$job = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . dxbs_table('email_jobs') . ' WHERE id=%d', $id), ARRAY_A);
	if (!$job || !in_array($job['status'], ['failed', 'held'], true)) return dxbs_error('retry', 'Only failed or held emails can be retried.');
	$wpdb->update(dxbs_table('email_jobs'), ['status' => 'retry', 'attempts' => 0, 'authorised_by' => get_current_user_id(), 'authorised_at' => dxbs_now()], ['id' => $id]);
	wp_schedule_single_event(time() + 1, 'dxbs_process_email', [$id]);
	return true;
}
add_action('init', function () {
	if (!wp_next_scheduled('dxbs_reminders'))wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'dxbs_reminders');
}
);
add_action('dxbs_reminders', 'dxbs_run_reminders');
function dxbs_run_reminders() {
	global $wpdb;
	$settings = array_merge(dxbs_settings_defaults(), (array)get_option('dxbs_settings', []));
	if (empty($settings['reminders_enabled'])) return ;
	$owner = get_user_by('login', 'dxndre');
	if (!$owner || !user_can($owner, 'manage_options')) return ;
	$rows = $wpdb->get_results('SELECT * FROM ' . dxbs_table('invoices') . " WHERE issued_at IS NOT NULL AND voided_at IS NULL AND due_date<='" . esc_sql(dxbs_today()) . "' AND status NOT IN ('draft','void','cancelled','paid') LIMIT 200", ARRAY_A);
	foreach ($rows as $r) {
		$b = dxbs_invoice_balance($r['id']);
		if (!$b['balance_minor'])continue;
		$days = max(1, min(30, (int)$settings['reminder_days']));
		$bucket = intdiv((int)floor(time() / DAY_IN_SECONDS), $days);
		$s = json_decode($r['snapshot'], true);
		$pdf = dxbs_pdf_path('invoice', $r['id']);
		dxbs_queue_email('payment_reminder', 'invoice', $r['id'], 'reminder-' . $r['id'] . '-' . $bucket, $s['client']['email'], 'Payment reminder ' . $r['number'], dxbs_email_html('A payment reminder', 'The recorded outstanding balance for ' . $r['number'] . ' is ' . dxbs_money($b['balance_minor']) . '. If you have made the transfer, please report it in your client space. A report is reviewed against the bank account before funds are recorded.', home_url('/dashboard/')), $pdf ? [$pdf] : [], (int)$owner->ID);
	}
}
