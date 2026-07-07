<?php
require_once ABSPATH . 'wp-load.php';

$settings = [
	'api_key'              => 'sk_XXXXXXXXX', // Dummy license key.
	'qdrant_api_key'       => '',
	'qdrant_endpoint'      => '',
	'chat_enabled'         => false,
	'welcome_message'      => 'Hello! How can I help you today?',
	'default_message'      => 'Sorry, I\'m not able to help with that.',
	'chat_model'           => 'gpt-4o-mini',
	'temperature'          => 1,
	'top_p'                => 1,
];

update_option( 'hyve_settings', $settings );
