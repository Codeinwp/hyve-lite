<?php
require_once ABSPATH . 'wp-load.php';

$settings = [
	'api_key'              => 'sk_XXXXXXXXX', // Dummy license key.
	'assistant_id'         => 'asst_TtalCGxTygMEqb7g3vNy6q8h', // Dummy assistant key.
	'qdrant_api_key'       => '',
	'qdrant_endpoint'      => '',
	'chat_enabled'         => false,
	'welcome_message'      => 'Hello! How can I help you today?',
	'default_message'      => 'Sorry, I\'m not able to help with that.',
	'chat_model'           => 'gpt-4o-mini',
	'temperature'          => 1,
	'top_p'                => 1,
	'moderation_threshold' => [
		'sexual'                 => 80,
		'hate'                   => 70,
		'harassment'             => 70,
		'self-harm'              => 50,
		'sexual/minors'          => 50,
		'hate/threatening'       => 60,
		'violence/graphic'       => 80,
		'self-harm/intent'       => 50,
		'self-harm/instructions' => 50,
		'harassment/threatening' => 60,
		'violence'               => 70,
	],
];

update_option( 'hyve_settings', $settings );
