/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import { Button, Spinner } from '@wordpress/components';

/**
 * Shared data table. Columns: `{ key, label, align: 'num'|'actions'|'check',
 * render( row ), renderHeader() }`; `render` falls back to `row[ key ]`, and
 * `renderHeader` replaces the label (used for select-all checkboxes).
 * Screen-reader-only header for action columns.
 *
 * @param {Object}    props            Component props.
 * @param {Array}     props.columns    Column definitions.
 * @param {Array}     props.rows       Row objects.
 * @param {Function}  props.rowKey     Row key getter; defaults to `row.ID`.
 * @param {boolean}   props.isLoading  Whether a fetch is in flight.
 * @param {boolean}   props.hasMore    Whether more rows can be loaded.
 * @param {?Function} props.onLoadMore Load-more handler.
 * @param {string}    props.empty      Empty-state message.
 */
const DataTable = ( {
	columns,
	rows,
	rowKey = ( row ) => row.ID,
	isLoading = false,
	hasMore = false,
	onLoadMore,
	empty = __( 'No data found.', 'hyve-lite' ),
} ) => {
	const cellClass = ( column ) => {
		if ( 'num' === column.align ) {
			return 'hyve-next-table__num';
		}

		if ( 'actions' === column.align ) {
			return 'hyve-next-table__actions';
		}

		if ( 'check' === column.align ) {
			return 'hyve-next-table__check';
		}

		return undefined;
	};

	const headerContent = ( column ) => {
		if ( column.renderHeader ) {
			return column.renderHeader();
		}

		if ( 'actions' === column.align ) {
			return <span className="screen-reader-text">{ column.label }</span>;
		}

		return column.label;
	};

	return (
		<>
			{ 0 < rows.length && (
				<div className="hyve-next-table-wrap">
					<table className="hyve-next-table">
						<thead>
							<tr>
								{ columns.map( ( column ) => (
									<th
										key={ column.key }
										className={ cellClass( column ) }
									>
										{ headerContent( column ) }
									</th>
								) ) }
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( row ) => (
								<tr key={ rowKey( row ) }>
									{ columns.map( ( column ) => (
										<td
											key={ column.key }
											className={ cellClass( column ) }
										>
											{ column.render
												? column.render( row )
												: row[ column.key ] }
										</td>
									) ) }
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			{ ! isLoading && 0 === rows.length && (
				<p className="hyve-next-act__note">{ empty }</p>
			) }

			{ isLoading && (
				<div className="hyve-next-act__note">
					<Spinner />
				</div>
			) }

			{ hasMore && ! isLoading && (
				<div className="hyve-next-table__more">
					<Button variant="secondary" onClick={ onLoadMore }>
						{ __( 'Load more', 'hyve-lite' ) }
					</Button>
				</div>
			) }
		</>
	);
};

export default DataTable;
