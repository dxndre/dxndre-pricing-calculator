<?php

function dx_pricing_config() {
	$config = [
		'services' => [],

		'scope' => [
			'small' => 0,
			'medium' => 75,
			'large' => 150
		],

        'type' => ['business'=>50,'blog'=>50,'education'=>25,'forum'=>50,'personal'=>50,'entertainment'=>50,'nonprofit'=>-75,'event'=>50,'portfolio'=>50,'gaming'=>200],

        'features' => [
			'user_account' => 250,
			'ecommerce' => 300,
			'integration' => 150,
			'none' => 0
		],

		'design_timeline' => [
			'no_rush' => -75,
			'flexible' => 0,
			'priority' => 100,
			'urgent' => 200
		],

		'timeline' => [
            'no_rush' => -75,
			'flexible' => 0,
			'priority' => 200,
			'urgent' => 500
		],

		'extras' => [
			'maintenance' => 250,
			'seo' => 300,
			'hosting' => 200
		]
	];
	$config['services'] = [];
	foreach (dx_calculator_services() as $service) {
		$config['services'][$service['key']] = $service['price'];
	}
	$config['retainer'] = dx_retainer_config();
	return $config;
}

/** Stable post metadata binds calculator behaviour independently of titles and slugs. */
function dx_calculator_services() {
	$items = [];
	$posts = get_posts(['post_type' => 'service', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'menu_order title', 'order' => 'ASC']);
	foreach ($posts as $post) {
		$key = get_post_meta($post->ID, '_dx_calculator_key', true);
		if (!$key) continue;
		$fixed = get_post_meta($post->ID, 'fixed_fee', true);
		$raw = get_post_meta($post->ID, $fixed ? 'fixed_fee_amount' : 'prioe_min_bank_transfer', true);
		if ($raw === '' || !is_numeric($raw) || (float) $raw < 0) continue;
		$features = get_post_meta($post->ID, 'feature_list', true);
		if (!$features && preg_match_all('/<li\b[^>]*>(.*?)<\/li>/si', $post->post_content, $matches)) {
			$features = implode("\n", array_map('wp_strip_all_tags', $matches[1]));
		}
		$items[] = ['key' => $key, 'title' => get_the_title($post), 'price' => (float) $raw, 'monthly' => $key === 'retainer', 'fixed' => (bool) $fixed, 'excerpt' => wp_strip_all_tags(get_the_excerpt($post)), 'image' => get_the_post_thumbnail_url($post, 'large'), 'features' => $features];
	}
	$order = array_flip(['comprehensive', 'wp_development', 'development', 'design', 'one_off', 'retainer']);
	usort($items, static function ($a, $b) use ($order) {
		return ($order[$a['key']] ?? PHP_INT_MAX) <=> ($order[$b['key']] ?? PHP_INT_MAX);
	});
	return $items;
}

/** Retainer pricing and recommendation indicators share one configurable source. */
function dx_retainer_config() {
	return [
		'tiers' => ['essentials' => ['name'=>'Essentials','price'=>200,'hours'=>4], 'growth'=>['name'=>'Growth','price'=>375,'hours'=>8], 'partner'=>['name'=>'Partner','price'=>625,'hours'=>14]],
		'areas' => ['design'=>['name'=>'Design','prices'=>[40,30,20],'weight'=>3], 'landing_pages'=>['name'=>'Landing Pages','prices'=>[60,45,30],'weight'=>4], 'wordpress'=>['name'=>'WordPress Development','prices'=>[40,30,20],'weight'=>4], 'performance'=>['name'=>'Performance Optimisation','prices'=>[35,25,15],'weight'=>3], 'seo'=>['name'=>'SEO Implementation','prices'=>[20,15,10],'weight'=>3], 'content_updates'=>['name'=>'Content Updates','prices'=>[25,15,0],'weight'=>1], 'analytics'=>['name'=>'Analytics & Tracking','prices'=>[30,20,0],'weight'=>2], 'technical_support'=>['name'=>'Technical Support','prices'=>[0,0,0],'weight'=>1]],
		'turnaround'=>['standard'=>['name'=>'Standard','price'=>0,'weight'=>0,'description'=>'Requests are scheduled within five business days, depending on complexity and availability.'], 'priority'=>['name'=>'Priority','price'=>75,'weight'=>1,'description'=>'Priority scheduling, typically within two business days.'], 'fast_track'=>['name'=>'Fast-track','price'=>150,'weight'=>2,'description'=>'Highest scheduling priority, typically within one business day.']],
		'commitments'=>['monthly'=>['name'=>'Monthly rolling','multiplier'=>1.1,'months'=>0], '3_months'=>['name'=>'3 months','multiplier'=>1,'months'=>3], '6_months'=>['name'=>'6 months','multiplier'=>.95,'months'=>6], '12_months'=>['name'=>'12 months','multiplier'=>.9,'months'=>12]],
		'addons'=>['strategy_call'=>['name'=>'Extra Strategy Call','price'=>75,'description'=>'One additional scheduled 60-minute strategy session each month. Follow-up consultancy and implementation are separate.'], 'additional_landing_page'=>['name'=>'Additional Landing Page Capacity','price'=>150,'hours'=>2,'description'=>'Two dedicated landing-page development hours per month. This does not guarantee an entire page; complex work is quoted separately.'], 'reporting_dashboard'=>['name'=>'Reporting Dashboard','price'=>35,'description'=>'Maintenance and review of an existing performance dashboard. Bespoke setup, new builds and integrations are quoted separately.'], 'emergency_support'=>['name'=>'Emergency Support','price'=>100,'description'=>'Agreed emergency escalation access. Incident work is separately authorised and charged; availability and response terms must be agreed. No 24/7 guarantee.']],
		'recommendation'=>['thresholds'=>[8,18], 'generalCapacityBands'=>[8,18,36], 'landingCapacityBand'=>4, 'discussionThreshold'=>20]
	];
}

function dx_calculate_retainer($state) {
	$c = dx_retainer_config();
	$tierKey = $state['retainer_level'] ?? 'essentials';
	$tier = $c['tiers'][$tierKey] ?? $c['tiers']['essentials'];
	$index = array_search($tierKey, array_keys($c['tiers']), true);
	$index = $index === false ? 0 : $index;
	$rows = [['label'=>$tier['name'], 'amount'=>$tier['price'], 'group'=>'retainer_level']];
	$subtotal = $tier['price'];
	foreach (array_unique($state['retainer_areas'] ?? []) as $key) {
		if (!isset($c['areas'][$key])) continue;
		$a = $c['areas'][$key]; $price = $a['prices'][$index]; $subtotal += $price;
		$rows[] = ['label'=>$a['name'], 'amount'=>$price, 'group'=>'retainer_areas'];
	}
	$turn = $c['turnaround'][$state['retainer_turnaround'] ?? 'standard'] ?? $c['turnaround']['standard'];
	$subtotal += $turn['price']; $rows[] = ['label'=>$turn['name'].' scheduling', 'amount'=>$turn['price'], 'group'=>'retainer_turnaround'];
	$landing = 0;
	foreach (array_unique($state['retainer_addons'] ?? []) as $key) {
		if (!isset($c['addons'][$key])) continue;
		$a = $c['addons'][$key]; $subtotal += $a['price']; $landing += $a['hours'] ?? 0;
		$rows[] = ['label'=>$a['name'], 'amount'=>$a['price'], 'group'=>'retainer_addons'];
	}
	$commit = $c['commitments'][$state['retainer_commitment'] ?? '3_months'] ?? $c['commitments']['3_months'];
	$total = round($subtotal * $commit['multiplier'], 2);
	$rows[] = ['label'=>'Monthly subtotal', 'amount'=>$subtotal, 'group'=>''];
	$rows[] = ['label'=>$commit['name'].' commitment adjustment', 'amount'=>round($total-$subtotal,2), 'group'=>'retainer_commitment'];
	return ['total'=>$total,'subtotal'=>$subtotal,'adjustment'=>round($total-$subtotal,2),'general'=>$tier['hours'],'landing'=>$landing,'capacity'=>$tier['hours']+$landing,'months'=>$commit['months'],'commitmentTotal'=>$commit['months'] ? round($total*$commit['months'],2) : null,'rows'=>$rows];
}
