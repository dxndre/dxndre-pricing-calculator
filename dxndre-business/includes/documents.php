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
	if ($type === 'quote' && !empty($s['revises_quote_number'])) : ?><p>Revises quotation <strong><?php
	echo esc_html($s['revises_quote_number']);
	?></strong>.</p><?php
	endif;
	if ($type === 'invoice' && !empty($s['source_quote_number'])) : ?><p>Quotation reference <strong><?php
	echo esc_html($s['source_quote_number']);
	?></strong>.</p><?php
	endif;
	if ($type === 'receipt') : ?><p>Invoice: <strong><?php
	echo esc_html($s['invoice_number']);
	?></strong><br>Bank payment date: <?php
	echo esc_html($s['bank_date']);
	?><br>Payment method: <?php
	echo esc_html($s['payment_method'] ?? 'Bank transfer');
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
    $where = ['1=1'];
    if (!empty($args['status'])) $where[] = $wpdb->prepare('status=%s', sanitize_key($args['status']));
    if (!empty($args['type'])) $where[] = $wpdb->prepare('entity_type=%s', sanitize_key($args['type']));
    if (!empty($args['id'])) $where[] = $wpdb->prepare('entity_id=%d', absint($args['id']));
    $conditions = implode(' AND ', $where);
    $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . dxbs_table('email_jobs') . " WHERE $conditions ORDER BY id DESC LIMIT %d", min(200, max(1, (int) ($args['limit'] ?? 100)))), ARRAY_A);
    foreach ($rows as &$row) {
        $payload = json_decode($row['payload'], true) ?: [];
        $row['recipient'] = $payload['to'] ?? '';
        $row['subject'] = $payload['subject'] ?? '';
        $row['history'] = (array) ($payload['history'] ?? []);
        unset($row['payload']);
    }
    return $rows;
}

function dxbs_email_job_id($dedupe) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . dxbs_table('email_jobs') . ' WHERE dedupe_key=%s', $dedupe));
}

function dxbs_queue_email($template, $type, $id, $dedupe, $to, $subject, $body, $attachments = [], $authorised_by = 0, $hold = false, $options = []) {
    global $wpdb;
    $actor = (int) ($authorised_by ?: get_current_user_id());
    if (!$actor || !dxbs_is_manager($actor) || !is_email($to)) {
        return dxbs_error('mail_authority', 'This email has not been authorised by the business owner.');
    }
    $dedupe = sanitize_text_field((string) $dedupe);
    if ($dedupe === '') return dxbs_error('mail_dedupe', 'Provide an identifier for this notification.');
    if (strlen($dedupe) > 191) $dedupe = 'mail-' . hash('sha256', $dedupe);
    $existing = dxbs_email_job_id($dedupe);
    if ($existing) return $existing;

    $payload = [
        'to' => $to, 'subject' => sanitize_text_field($subject), 'body' => $body,
        'attachments' => $attachments, 'automated' => !empty($options['automated']),
        'audience' => $options['audience'] ?? 'client', 'payment_id' => absint($options['payment_id'] ?? 0),
        'due_date' => $options['due_date'] ?? '', 'balance_minor' => $options['balance_minor'] ?? null,
        'history' => [['at' => dxbs_now(), 'state' => $hold ? 'held' : 'queued']],
    ];
    $table = dxbs_table('email_jobs');
    $now = dxbs_now();
    $inserted = $wpdb->query($wpdb->prepare(
        "INSERT INTO $table (entity_type, entity_id, template, dedupe_key, status, payload, authorised_by, authorised_at, created_at, last_error)
        VALUES (%s, %d, %s, %s, %s, %s, %d, %s, %s, '')
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
        $type, $id, $template, $dedupe, $hold ? 'held' : 'queued', wp_json_encode($payload), $actor, $now, $now
    ));
    if ($inserted === false) return dxbs_error('queue', 'The email could not be queued.');
    $job = (int) $wpdb->insert_id;
    if ($inserted === 1) {
        dxbs_fin_audit('email_queued', $type, $id);
        if (!$hold) wp_schedule_single_event(time() + 2, 'dxbs_process_email', [$job]);
    }
    return $job;
}

function dxbs_email_html($heading, $copy, $link = '', $label = 'Open client space') {
    return '<table role="presentation" width="100%" style="background:#f2efe6;font-family:Arial,sans-serif;color:#172016"><tr><td align="center" style="padding:24px 12px"><table role="presentation" width="100%" style="max-width:620px;background:#fff;border-top:4px solid #b78933"><tr><td style="padding:28px"><p style="font-size:34px;font-weight:bold;margin:0 0 20px">dx.</p><h1 style="font-size:25px;line-height:1.25;margin:0 0 16px">' . esc_html($heading) . '</h1><p style="line-height:1.7">' . nl2br(esc_html($copy)) . '</p>' . ($link ? '<p style="margin:24px 0"><a style="display:inline-block;background:#dbb665;color:#172016;padding:14px 20px;text-decoration:none;font-weight:bold" href="' . esc_url($link) . '">' . esc_html($label) . '</a></p><p style="font-size:13px"><a style="color:#535b45" href="' . esc_url($link) . '">Open the link in your browser</a></p>' : '') . '<p style="color:#535b45;font-size:13px;margin-top:26px">DXNDRE Business</p></td></tr></table></td></tr></table>';
}

function dxbs_send_document($type, $id, $direct_email = '') {
    global $wpdb;
    if (!dxbs_is_manager()) return dxbs_require_manager();
    $record = dxbs_record_raw($type, $id);
    if (!$record || (in_array($type, ['quote', 'invoice'], true) && !$record['issued_at'])) return dxbs_error('draft', 'Issue the document before sending it.');
    if ($type === 'receipt' && !dxbs_payment_for_receipt($record)) return dxbs_error('unverified', 'This receipt is unavailable because its payment is not verified or was reversed.');
    if (!empty($record['voided_at'])) return dxbs_error('void', 'A void document cannot be delivered as payable.');

    if ($type === 'quote' && $record['status'] === 'superseded') return dxbs_error('superseded', 'Send the current quotation instead.');
    $snapshot = json_decode($record['snapshot'], true) ?: [];
    if ($direct_email !== '' && !is_email($direct_email)) return dxbs_error('email', 'Enter a valid recipient email address.');
    $dedupe = 'document-' . $type . '-' . $id . '-' . ($snapshot['version'] ?? 1);
    if ($direct_email !== '') $dedupe .= '-' . hash('sha256', strtolower($direct_email));
    $existing = dxbs_email_job_id($dedupe);
    if ($existing) return $existing;
    $pdf = dxbs_generate_pdf($type, $id);
    if (is_wp_error($pdf)) return $pdf;

    $event = $type === 'quote' && !empty($snapshot['revises_quote_id']) ? 'quote_revised' : 'document_' . $type;
    $date = $record['due_date'] ?? '';
    [$subject, $copy] = dxbs_notification_content($event, [
        'reference' => $record['number'],
        'name' => $record['title'] ?? $record['number'],
        'date' => $date ? wp_date('j F Y', strtotime($date . ' UTC'), new DateTimeZone('Europe/London')) : '',
    ]);
    $link = dxbs_document_url($type, $id);
    $label = 'Open document';
    if ($type === 'invoice') {
        $settings = dxbs_settings();
        if ($settings['bank_account_holder'] && $settings['bank_sort_code'] && $settings['bank_account_number']) {
            $account_id = (int) $wpdb->get_var($wpdb->prepare('SELECT wp_user_id FROM ' . dxbs_table('clients') . ' WHERE id=%d', $record['client_id']));
            $link = $account_id ? dxbs_invoice_payment_url($id) : dxbs_payment_link($id);
            $label = 'Bank transfer instructions';
        }
    }
    $direct = $direct_email !== '';
    return dxbs_queue_email($event, $type, $id, $dedupe, $direct ? $direct_email : ($snapshot['client']['email'] ?? ''), $subject,
        dxbs_email_html($subject, $copy, $direct ? '' : $link, $label), [$pdf], 0, false, ['audience' => $direct ? 'direct_document' : 'client']);
}

function dxbs_payment_for_receipt($receipt) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . dxbs_table('payments') . " WHERE id=%d AND status='verified' AND verified_at IS NOT NULL AND verified_by>0 AND reversed_at IS NULL", $receipt['payment_id']), ARRAY_A);
}

function dxbs_queue_event($event, $type, $id, $dedupe) {
    $record = dxbs_record_raw($type, $id);
    if (!$record) return false;
    $audience = in_array($event, ['quote_accepted', 'quote_declined', 'transfer_reported'], true) ? 'owner' : 'client';
    $payment_id = $event === 'payment_verified' ? absint(substr($dedupe, strlen('verify-'))) : 0;
    $job = dxbs_finance_notification($event, $type, $record, $dedupe, ['audience' => $audience, 'payment_id' => $payment_id]);
    if ($event === 'payment_verified' && $type === 'invoice' && !dxbs_invoice_balance($id)['balance_minor']) {
        dxbs_finance_notification('invoice_settled', 'invoice', $record, 'settled-' . $id . '-' . $payment_id, ['payment_id' => $payment_id]);
    }
    return $job;
}

function dxbs_email_job_state($job, $state, $reason = '', $next = null, $attempts = null) {
    global $wpdb;
    $payload = json_decode($job['payload'], true) ?: [];
    $history = (array) ($payload['history'] ?? []);
    $history[] = array_filter(['at' => dxbs_now(), 'state' => $state, 'reason' => $reason]);
    $payload['history'] = $history;
    $data = ['status' => $state, 'last_error' => $reason, 'next_attempt_at' => $next, 'payload' => wp_json_encode($payload)];
    if ($attempts !== null) $data['attempts'] = $attempts;
    if ($state === 'sent') $data['sent_at'] = dxbs_now();
    return $wpdb->update(dxbs_table('email_jobs'), $data, ['id' => $job['id'], 'status' => $job['status']]);
}

function dxbs_email_job_is_current($job, $payload, $record) {
    $event = $job['template'];
    $type = $job['entity_type'];
    $id = $job['entity_id'];
    $template = dxbs_notification_templates()[$event] ?? null;
    $scheduled = ['payment_reminder', 'payment_upcoming', 'payment_due', 'payment_overdue', 'quote_expiring'];
    if (($template && (!empty($payload['automated']) || in_array($event, $scheduled, true))) && !dxbs_notification_preferences()['enabled'][$template[0]]) {
        return 'This notification category is disabled.';
    }
    if (dxbs_type_table($type)) {
        if (!$record || !empty($record['voided_at']) || ($type === 'receipt' && !dxbs_payment_for_receipt($record))) {
            return 'The document or its verified payment is no longer available.';
        }
        if ($type === 'invoice' && in_array($event, array_slice($scheduled, 0, 4), true)) {
            $balance = dxbs_invoice_balance($id)['balance_minor'];
            if (empty(get_option('dxbs_settings', [])['reminders_enabled']) || !$balance) {
                return 'The invoice has no remaining balance, or scheduled payment emails are disabled.';
            }
            if ((!empty($payload['due_date']) && $record['due_date'] !== $payload['due_date']) || (isset($payload['balance_minor']) && $balance !== (int) $payload['balance_minor'])) {
                return 'The invoice due date or balance changed after this message was prepared.';
            }
            if (($event === 'payment_due' && $record['due_date'] !== dxbs_today()) || ($event === 'payment_upcoming' && $record['due_date'] <= dxbs_today())) {
                return 'This scheduled payment notice is no longer timely.';
            }
        }
        if ($type === 'quote' && in_array($event, ['document_quote', 'quote_revised'], true) && $record['status'] === 'superseded') {
            return 'A newer quotation has replaced this version.';
        }
        if ($event === 'quote_expiring' && ($record['status'] !== 'sent' || $record['expires_at'] <= dxbs_now())) {
            return 'The quotation is no longer awaiting a response.';
        }
        if ($event === 'invoice_settled' && dxbs_invoice_balance($id)['balance_minor']) return 'The invoice is no longer fully settled.';
        if (!empty($payload['payment_id'])) {
            if (!dxbs_payment_for_receipt(['payment_id' => $payload['payment_id']])) return 'The bank payment is no longer verified.';
        }
    }
    $audience = $payload['audience'] ?? (in_array($event, ['quote_accepted', 'quote_declined', 'transfer_reported'], true) ? 'owner' : 'client');
    if ($audience === 'direct_document' && $record && in_array($event, ['document_quote','quote_revised','document_invoice','document_receipt','document_credit'], true)) {
        return is_email($payload['to'] ?? '') ? '' : 'The recipient email is invalid.';
    }
    $expected = dxbs_notification_recipient($type, $id, $audience, $record);
    return $expected && is_email($payload['to'] ?? '') && strcasecmp($payload['to'], $expected) === 0
        ? ''
        : 'The intended recipient no longer has access to this record.';
}

add_action('dxbs_process_email', 'dxbs_process_email');
function dxbs_process_email($id) {
    global $wpdb;
    $table = dxbs_table('email_jobs');
    $wpdb->query('START TRANSACTION');
    $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE", $id), ARRAY_A);
    if (!$job || !in_array($job['status'], ['queued', 'retry'], true) || (int) $job['attempts'] >= 3 || (!empty($job['next_attempt_at']) && $job['next_attempt_at'] > dxbs_now())) {
        $wpdb->query('ROLLBACK');
        return false;
    }
    $owner = get_userdata($job['authorised_by']);
    $record = dxbs_type_table($job['entity_type']) ? dxbs_record_raw($job['entity_type'], $job['entity_id']) : null;
    $payload = json_decode($job['payload'], true) ?: [];
    $reason = dxbs_email_job_is_current($job, $payload, $record);
    if (!$owner || !dxbs_is_manager($owner->ID)) {
        dxbs_email_job_state($job, 'held', 'Business owner authorisation is unavailable.');
    } elseif ($reason) {
        dxbs_email_job_state($job, 'cancelled', $reason);
    } else {
        $attachments = [];
        if (str_starts_with($job['template'], 'document_') || $job['template'] === 'quote_revised') {
            $pdf = dxbs_pdf_path($job['entity_type'], $job['entity_id']);
            if ($pdf) $attachments = [$pdf];
            else $reason = 'The archived PDF is missing or failed its integrity check. Rebuild it before retrying.';
        }
        if (!$reason) {
            $attempt = (int) $job['attempts'] + 1;
            $updated = dxbs_email_job_state($job, 'sending', '', gmdate('Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS), $attempt);
            $committed = $wpdb->query('COMMIT');
            if ($updated !== 1 || $committed === false) return false;
            return dxbs_deliver_email($job['id'], $payload, $record, $owner, $attachments, $attempt);
        }
        dxbs_email_job_state($job, 'failed', $reason);
    }
    $wpdb->query('COMMIT');
    return false;
}

function dxbs_email_live_transport() {
    $host = wp_parse_url(home_url('/'), PHP_URL_HOST) ?: '';
    return wp_get_environment_type() === 'production' && $host !== '' && !preg_match('/(?:^localhost$|^127\.|^\[::1\]$|\.local$|\.test$|\.invalid$)/i', $host);
}

function dxbs_deliver_email($id, $payload, $record, $owner, array $attachments, $attempt) {
    global $wpdb;
    $table = dxbs_table('email_jobs');
    $state = 'simulated';
    $reason = '';
    $next = null;
    if (dxbs_email_live_transport()) {
        $snapshot = json_decode($record['snapshot'] ?? '{}', true) ?: [];
        $from = sanitize_email($snapshot['business']['email'] ?? $owner->user_email);
        $name = sanitize_text_field($snapshot['business']['business_name'] ?? 'DXNDRE');
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        if ($from) $headers[] = 'From: ' . $name . ' <' . $from . '>';
        try {
            $sent = wp_mail($payload['to'], $payload['subject'], $payload['body'], $headers, $attachments);
            $state = $sent ? 'sent' : ($attempt < 3 ? 'retry' : 'failed');
            if (!$sent) $reason = 'The WordPress mail transport did not accept the message.';
            if ($state === 'retry') $next = gmdate('Y-m-d H:i:s', time() + $attempt * 5 * MINUTE_IN_SECONDS);
        } catch (Throwable $error) {
            $state = 'held';
            $reason = 'Delivery outcome is unknown. Check the mail transport before retrying.';
        }
    }
    $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);
    if (!$job || $job['status'] !== 'sending' || dxbs_email_job_state($job, $state, $reason, $next) !== 1) return false;
    if ($next) wp_schedule_single_event(strtotime($next . ' UTC'), 'dxbs_process_email', [$id]);
    dxbs_fin_audit('email_' . $state, $job['entity_type'], $job['entity_id']);
    return in_array($state, ['sent', 'simulated'], true);
}

function dxbs_retry_email($id) {
    global $wpdb;
    if (!dxbs_is_manager()) return dxbs_require_manager();
    $table = dxbs_table('email_jobs');
    $wpdb->query('START TRANSACTION');
    $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE", $id), ARRAY_A);
    if (!$job || !in_array($job['status'], ['failed', 'held'], true)) {
        $wpdb->query('ROLLBACK');
        return dxbs_error('retry', 'Only failed or held emails can be retried.');
    }
    $updated = dxbs_email_job_state($job, 'retry', 'Retry requested by the business owner.', null, 0);
    if ($updated !== 1 || $wpdb->update($table, ['authorised_by' => get_current_user_id(), 'authorised_at' => dxbs_now()], ['id' => $id]) === false) {
        $wpdb->query('ROLLBACK');
        return dxbs_error('retry', 'The email retry could not be scheduled.');
    }
    $wpdb->query('COMMIT');
    wp_schedule_single_event(time() + 1, 'dxbs_process_email', [$id]);
    return true;
}

add_filter('cron_schedules', function ($schedules) {
    $schedules['dxbs_outbox_interval'] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every five minutes'];
    return $schedules;
});
add_action('init', function () {
    if (!wp_next_scheduled('dxbs_outbox')) wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'dxbs_outbox_interval', 'dxbs_outbox');
    if (!wp_next_scheduled('dxbs_reminders')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'dxbs_reminders');
});
add_action('dxbs_outbox', 'dxbs_run_outbox');
function dxbs_run_outbox() {
    global $wpdb;
    $table = dxbs_table('email_jobs');
    $jobs = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE status IN ('queued','retry','sending') AND (next_attempt_at<=%s OR (next_attempt_at IS NULL AND (status<>'sending' OR created_at<=%s))) ORDER BY id LIMIT 30",
        dxbs_now(), gmdate('Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS)
    ), ARRAY_A);
    foreach ($jobs as $job) {
        if ($job['status'] === 'sending') {
            dxbs_email_job_state($job, 'held', 'The delivery worker stopped before recording the result. Check the mail transport before retrying.');
        } else {
            dxbs_process_email($job['id']);
        }
    }
}

add_action('dxbs_reminders', 'dxbs_run_reminders');
function dxbs_run_reminders() {
    global $wpdb;
    $settings = array_merge(dxbs_settings_defaults(), (array) get_option('dxbs_settings', []));
    $today = dxbs_today();
    $timezone = new DateTimeZone('Europe/London');
    $day = new DateTimeImmutable($today, $timezone);
    $ahead = $day->modify('+3 days');
    $quotes = dxbs_table('quotes');
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $quotes WHERE status='sent' AND expires_at BETWEEN %s AND %s ORDER BY expires_at",
        dxbs_now(), $ahead->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
    ), ARRAY_A);
    foreach ($rows as $quote) {
        dxbs_finance_notification('quote_expiring', 'quote', $quote, 'quote-expiring-' . $quote['id'] . '-' . $quote['version']);
    }
    if (empty($settings['reminders_enabled'])) return;

    $invoices = dxbs_table('invoices');
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $invoices WHERE issued_at IS NOT NULL AND voided_at IS NULL AND due_date<=%s AND status NOT IN ('draft','void','cancelled','paid') ORDER BY due_date",
        $ahead->format('Y-m-d')
    ), ARRAY_A);
    foreach ($rows as $invoice) {
        $balance = dxbs_invoice_balance($invoice['id'])['balance_minor'];
        if (!$balance) continue;
        $due = new DateTimeImmutable($invoice['due_date'], $timezone);
        $days_overdue = (int) $due->diff($day)->format('%r%a');
        $interval = max(1, min(30, (int) $settings['reminder_days']));
        if ($days_overdue > 1 && ($days_overdue - 1) % $interval !== 0) continue;
        $event = match (true) {
            $days_overdue < 0 => 'payment_upcoming',
            $days_overdue === 0 => 'payment_due',
            $days_overdue === 1 => 'payment_overdue',
            default => 'payment_reminder',
        };
        $occurrence = $days_overdue > 1 ? $today : $invoice['due_date'];
        dxbs_finance_notification($event, 'invoice', $invoice, $event . '-' . $invoice['id'] . '-' . $occurrence, [
            'amount' => dxbs_money($balance), 'balance_minor' => $balance, 'due_date' => $invoice['due_date'],
        ]);
    }
}
