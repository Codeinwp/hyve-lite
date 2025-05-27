<?php
/**
 * Hyve Lite.
 *
 * @package Codeinwp/hyve-lite
 *
 * Plugin Name:       Hyve Lite
 * Plugin URI:        https://themeisle.com/plugins/hyve/
 * Description:       Hyve is an AI-powered chatbot that transforms your WordPress content into engaging conversations.
 * Version:           1.2.3
 * Author:            ThemeIsle
 * Author URI:        https://themeisle.com
 * License:           GPL-3.0+
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain:       hyve-lite
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'Hyve Lite requires PHP 8.1 or higher. Please upgrade your PHP version.', 'hyve-lite' ); ?></p>
			</div>
			<?php
		}
	);

	return;
}

define( 'HYVE_LITE_BASEFILE', __FILE__ );
define( 'HYVE_LITE_URL', plugins_url( '/', __FILE__ ) );
define( 'HYVE_LITE_PATH', __DIR__ );
define( 'HYVE_LITE_VERSION', '1.2.3' );
define( 'HYVE_PRODUCT_SLUG', basename( dirname( 'HYVE_LITE_BASEFILE' ) ) );

$vendor_file = HYVE_LITE_PATH . '/vendor/autoload.php';

if ( is_readable( $vendor_file ) ) {
	require_once $vendor_file;
}

add_filter(
	'themeisle_sdk_products',
	function ( $products ) {
		$products[] = HYVE_LITE_BASEFILE;

		return $products;
	}
);

add_action(
	'plugins_loaded',
	function () {
		new \ThemeIsle\HyveLite\Main();
	} 
);
