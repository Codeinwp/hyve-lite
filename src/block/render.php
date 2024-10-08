<?php
/**
 * The wp_kses_post function is used to ensure any HTML that is not allowed in a post will be escaped.
 * 
 * @package Codeinwp/HyveLite
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$hyve_id = 'hyve-inline-chat';

if ( isset( $attributes['variant'] ) && 'floating' === $attributes['variant'] ) {
	$hyve_id = 'hyve-chat';
}
?>

<div 
<?php
echo wp_kses_data(
	get_block_wrapper_attributes(
		[
			'id' => $hyve_id,
		]
	) 
);
?>
></div>
