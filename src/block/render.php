<?php
/**
 * The wp_kses_post function is used to ensure any HTML that is not allowed in a post will be escaped.
 * 
 * @package Codeinwp/HyveLite
 */

$hyve_id = 'hyve-inline-chat';

if ( isset( $attributes['variant'] ) && 'floating' === $attributes['variant'] ) {
	$hyve_id = 'hyve-chat';
}
?>

<div 
<?php
echo wp_kses_data(
	get_block_wrapper_attributes(
		array(
			'id' => $hyve_id,
		)
	) 
);
?>
></div>
