/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';

import { Button } from '@wordpress/components';

/**
 * Previous / "Page X of Y" / Next pager for card footers. Renders nothing
 * while everything fits on the first page.
 *
 * @param {Object}   props            Component props.
 * @param {number}   props.page       Zero-based current page.
 * @param {number}   props.totalPages Total number of pages.
 * @param {boolean}  props.hasMore    Whether a next page exists.
 * @param {boolean}  props.isLoading  Whether a fetch is in flight.
 * @param {Function} props.onChange   Page change handler.
 */
const Pagination = ( { page, totalPages, hasMore, isLoading, onChange } ) => {
	if ( 0 === page && ! hasMore ) {
		return null;
	}

	return (
		<div className="hyve-next-pagination">
			<Button
				variant="secondary"
				disabled={ isLoading || 0 === page }
				onClick={ () => onChange( page - 1 ) }
			>
				{ __( 'Previous', 'hyve-lite' ) }
			</Button>
			<span className="hyve-next-pagination__label">
				{ sprintf(
					/* translators: 1: current page number, 2: total number of pages. */
					__( 'Page %1$s of %2$s', 'hyve-lite' ),
					page + 1,
					totalPages
				) }
			</span>
			<Button
				variant="secondary"
				disabled={ isLoading || ! hasMore }
				onClick={ () => onChange( page + 1 ) }
			>
				{ __( 'Next', 'hyve-lite' ) }
			</Button>
		</div>
	);
};

export default Pagination;
