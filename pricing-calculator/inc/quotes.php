<?php
if (!defined('ABSPATH')) exit;

/**
 * Fetch a quote by invoice number
 */
function dx_get_quote_by_invoice($invoice) {
	global $wpdb;

	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}dx_quotes WHERE invoice = %s",
			$invoice
		)
	);
}

/**
 * Save a quote
 */
function dx_save_quote($invoice, $state, $total) {
	global $wpdb;

	return $wpdb->insert(
		$wpdb->prefix . 'dx_quotes',
		[
			'invoice'    => $invoice,
			'state'      => wp_json_encode($state),
			'total'      => $total,
			'created_at' => current_time('mysql'),
			'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
		],
		['%s', '%s', '%f', '%s', '%s']
	);
}
/** Validate recurring selections and calculate trusted totals from central pricing. */
function dx_validate_estimate_state($state) {
	foreach (['client_name','client_company'] as $key) $state[$key] = sanitize_text_field($state[$key] ?? '');
	if (($state['service'] ?? '') !== 'retainer') {
		$state['estimate_breakdown'] = dx_estimate_selection_rows($state, true);
		if (($state['service'] ?? '') === 'one_off') $state['total'] = max(0, array_sum(array_column($state['estimate_breakdown'], 'amount')));
		$state['payment_plan'] = dx_payment_schedule($state)['plan'];
		return $state;
	}
	$c = dx_retainer_config();
	foreach (['retainer_level'=>'tiers','retainer_turnaround'=>'turnaround','retainer_commitment'=>'commitments'] as $key=>$section) {
		if (!isset($state[$key]) || !is_string($state[$key]) || !isset($c[$section][$state[$key]])) return new WP_Error('invalid_selection','Please complete your retainer package selections.');
	}
	foreach (['retainer_areas'=>'areas','retainer_addons'=>'addons'] as $key=>$section) {
		if (!isset($state[$key])) $state[$key] = [];
		if (!is_array($state[$key])) return new WP_Error('invalid_selection','Invalid support selection.');
		foreach ($state[$key] as $value) if (!is_string($value) || !isset($c[$section][$value])) return new WP_Error('invalid_selection','Invalid support selection.');
		$state[$key] = array_values(array_unique($state[$key]));
	}
	$state['retainer_estimate'] = dx_calculate_retainer($state);
	$state['total'] = $state['retainer_estimate']['total'];
	$state['payment_plan'] = dx_payment_schedule($state)['plan'];
	return $state;
}

/** Shared breakdown for printable and online recurring estimates. */
function dx_retainer_export_html($state, $columns = 2) {
	$r = $state['retainer_estimate'] ?? dx_calculate_retainer($state);
	$span = $columns === 3 ? ' colspan="2"' : '';
	$html = '';
	foreach ($r['rows'] as $row) $html .= '<tr><td'.$span.'>'.esc_html($row['label']).'</td><td align="right">'.(($row['group'] === 'retainer_areas' && $row['amount'] == 0) ? 'Included' : '£'.number_format($row['amount'],2)).'</td></tr>';
	$html .= '<tr><td'.$span.'>General support hours</td><td align="right">'.(int)$r['general'].' / month</td></tr><tr><td'.$span.'>Additional landing-page hours</td><td align="right">'.(int)$r['landing'].' / month</td></tr><tr><td'.$span.'>Total monthly capacity</td><td align="right">'.(int)$r['capacity'].' hours</td></tr>';
	if ($r['months']) $html .= '<tr><td'.$span.'>'.(int)$r['months'].'-month commitment total</td><td align="right">£'.number_format($r['commitmentTotal'],2).'</td></tr>';
	return $html;
}

/** Labels and prices are captured when saved, rather than reconstructed at view time. */
function dx_estimate_selection_rows($state, $capture_prices = false) {
	$pricing = $capture_prices ? dx_pricing_config() : [];
	$services = [];
	foreach (dx_calculator_services() as $service) $services[$service['key']] = $service['title'];
	$labels = [
		'service'=>$services,
		'scope'=>['small'=>'Small (1–3 Pages / Simple Functionality)','medium'=>'Medium (4–8 Pages / Moderate Functionality)','large'=>'Large (9+ Pages / Complex Functionality)'],
		'type'=>['business'=>'Business','blog'=>'Blog','education'=>'Education','forum'=>'Forum','personal'=>'Personal','entertainment'=>'Entertainment','nonprofit'=>'Non-Profit Charity','event'=>'Event','portfolio'=>'Portfolio','gaming'=>'Gaming'],
		'features'=>['user_account'=>'User Accounts','ecommerce'=>'eCommerce','integration'=>'External Integrations','none'=>'No added functionality'],
		'timeline'=>['no_rush'=>'No Rush (12+ Weeks Turnaround)','flexible'=>'Standard (5 - 12 Week turnaround)','priority'=>'Priority (1–4 Weeks Turnaround)','urgent'=>'Urgent (<7 Day Turnaround)'],
		'extras'=>['maintenance'=>'Ongoing Maintenance & Updates','seo'=>'Basic SEO setup & Optimisation','hosting'=>'Hosting & Monitoring']
	];
	$categories = ['service'=>'Service','scope'=>'Project scope','type'=>'Project type','features'=>'Additional functionality','timeline'=>'Timeline','extras'=>'Ongoing support','retainer_level'=>'Support package','retainer_areas'=>'Support category','retainer_turnaround'=>'Scheduling','retainer_commitment'=>'Commitment','retainer_addons'=>'Monthly add-on'];
	$service = $state['service'] ?? '';
	$groups = ['service','scope','type','features','timeline','extras'];
	if ($service === 'design') {
		$groups = ['service','scope','type','timeline'];
		$labels['scope'] = ['small'=>'Small (1–3 Pages)','medium'=>'Medium (4–8 Pages)','large'=>'Large (9+ Pages)'];
	}
	if ($service === 'landing_page') $groups = ['service','type','timeline'];
	if ($service === 'retainer') {
		$c = dx_retainer_config();
		foreach (['retainer_level'=>'tiers','retainer_areas'=>'areas','retainer_turnaround'=>'turnaround','retainer_commitment'=>'commitments','retainer_addons'=>'addons'] as $group=>$section) {
			$labels[$group] = [];
			foreach ($c[$section] as $key=>$option) $labels[$group][$key] = $option['name'];
		}
		$groups = ['service','retainer_level','retainer_areas','retainer_turnaround','retainer_commitment','retainer_addons'];
	}
	$rows = [];
	foreach ($groups as $group) {
		$values = $state[$group] ?? [];
		$values = is_array($values) ? $values : [$values];
		foreach (array_unique($values) as $value) {
			if (!is_string($value) || $value === '') continue;
			$section = $group === 'service' ? 'services' : $group;
			if ($service === 'design' && $group === 'timeline') $section = 'design_timeline';
			$rows[] = ['category'=>$categories[$group], 'label'=>$labels[$group][$value] ?? ucwords(str_replace('_',' ',$value)), 'amount'=>$capture_prices ? (($service === 'one_off' && in_array($value, ['nonprofit', 'no_rush'], true)) ? -5 : ($pricing[$section][$value] ?? 0)) : null, 'group'=>$group];
		}
	}
	return $rows;
}

/** Preserve legacy totals without assigning today's prices to old selections. */
function dx_estimate_breakdown_html($state, $columns = 3) {
	if (($state['service'] ?? '') === 'retainer' && isset($state['retainer_estimate']['rows'])) {
		$span = $columns === 3 ? ' colspan="2"' : '';
		return '<tr><td'.$span.'>Service: Retainer</td><td align="right">—</td></tr>'.dx_retainer_export_html($state, $columns);
	}
	$rows = $state['estimate_breakdown'] ?? dx_estimate_selection_rows($state);
	$html = '';
	foreach ($rows as $row) {
		$amount = $row['amount'] === null ? 'Selected' : (($row['amount'] < 0 ? '−' : '').'£'.number_format(abs($row['amount']),2));
		$html .= '<tr><td>'.esc_html($row['category']).($columns === 2 ? ': '.esc_html($row['label']) : '').'</td>'.($columns === 3 ? '<td>'.esc_html($row['label']).'</td>' : '').'<td align="right">'.esc_html($amount).'</td></tr>';
	}
	return $html;
}

function dx_payment_schedule($state) {
 $quote=max(0,(int)round((float)($state['total']??0)*100));
 $photo=($state['service_category']??'')==='photography';$deposit=min($quote,$photo?1000:5000);
 $plan=($state['service']??'')==='retainer'?'full':($photo?'after':($state['payment_plan']??'full'));
 if((!$photo&&$plan==='after')||!in_array($plan,['full','three','six','after','custom'],true)||($plan==='six'&&$quote<=60000))$plan='full';
 $interest=$plan==='six'?(int)round($quote*.05):0;$balance=$quote+$interest-$deposit;$count=$plan==='six'?6:($plan==='three'?3:1);
 $payments=array_fill(0,$count,intdiv($balance,$count));$payments[$count-1]+=$balance-array_sum($payments);
 return compact('plan','deposit','interest','balance','payments');
}
function dx_payment_terms($state) {
 $p=dx_payment_schedule($state);$money=fn($c)=>'£'.number_format($c/100,2);
 if(($state['service']??'')==='retainer') return 'Nothing is payable now. Your retainer is billed monthly at £'.number_format((float)($state['total']??0),2).'. After our call and acceptance of the final quotation, the '.$money($p['deposit']).' deposit is credited toward your first monthly bill. Billing dates are confirmed in your final quotation.';
 $text='Nothing is payable now. After our initial call and your acceptance of the final quotation, a '.$money($p['deposit']).' deposit is due before work begins, included in your total. ';
 if($p['plan']==='custom')return $text.'Custom instalment plan: remaining balance and payment dates to be agreed during our call, before work begins.';
 if($p['plan']==='after')return $text.$money($p['balance']).' payable after the photography service.';
 if($p['plan']==='three')return $text.'Remaining balance in three instalments: '.implode(', ',array_map($money,$p['payments'])).' — on completion of design, completion of development, and 30 days post-launch.';
 if($p['plan']==='six')return $text.'One-off interest of '.$money($p['interest']).' (5% of the full quote). Six monthly instalments: '.implode(', ',array_map($money,$p['payments'])).'. First instalment one month after the deposit.';
 return $text.'Remaining '.$money($p['balance']).' payable in full before work begins.';
}
