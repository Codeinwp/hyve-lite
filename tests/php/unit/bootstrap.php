<?php
/**
 * PHPUnit bootstrap file
 * 
 * @package Codeinwp\HyveLite
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( class_exists( '\Yoast\PHPUnitPolyfills\Autoload' ) === false ) {
	require_once dirname( __DIR__ ) . '/../../vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
}

if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/../../hyve-lite.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

require_once dirname( __DIR__ ) . '/../../vendor/autoload.php';

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

activate_plugin( 'hyve-lite/hyve-lite.php' );

global $current_user;
$current_user = new WP_User( 1 ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$current_user->set_role( 'administrator' );

wp_update_user(
	[
		'ID'         => 1,
		'first_name' => 'Admin',
		'last_name'  => 'User',
	]
);

// Clean DB before tests.
global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'hyve' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
