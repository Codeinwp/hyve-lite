/**
 * WordPress dependencies.
 */
import { applyFilters } from '@wordpress/hooks';

/**
 * Named extension slot, filled through the `hyve.slot` filter. Whole panels
 * swap through `component` on `hyve.routes` entries instead.
 *
 * @param {Object}  props          Component props.
 * @param {string}  props.name     Slot name, e.g. 'api-access-tokens'.
 * @param {?Object} props.fallback Rendered when nothing fills the slot.
 * @param {Object}  props.props    Extra props passed to the filter.
 */
const Slot = ( { name, fallback = null, ...props } ) =>
	applyFilters( 'hyve.slot', fallback, name, props );

export default Slot;
