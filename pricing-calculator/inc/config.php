<?php

function dx_pricing_config() {
	return [
		'services' => [
			'design' => 250,
			'landing_page' => 200,
			'development' => 500,
			'wp_development' => 650,
			'comprehensive' => 850,
			'retainer' => 1200,
			'one_off' => 300
		],

		'scope' => [
			'small' => 0,
			'medium' => 300,
			'large' => 600
		],

        'type' => [
			'business' => 50,
            'blog' => 25,
            'education' => 75,
            'forum' => 50,
            'personal' => 100,
            'entertainment' => 200,
            'nonprofit' => -75,
            'event' => 50,
			'portfolio' => 75
		],

        'features' => [
			'user_account' => 250,
			'ecommerce' => 300,
			'integration' => 150,
			'none' => 0
		],

		'timeline' => [
            'no_rush' => -75,
			'flexible' => 0,
			'priority' => 300,
			'urgent' => 750
		],

		'extras' => [
			'maintenance' => 250,
			'seo' => 300,
			'hosting' => 200
		]
	];
}