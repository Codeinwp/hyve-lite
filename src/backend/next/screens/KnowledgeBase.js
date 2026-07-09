/**
 * WordPress dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import {
	Button,
	Icon,
	Modal,
	SearchControl,
	SelectControl,
} from '@wordpress/components';

import { useDispatch, useSelect } from '@wordpress/data';

import { useEffect, useRef, useState } from '@wordpress/element';

import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies.
 */
import { getRoutes, navigate } from '../router';
import { onProcessData, setUtm } from '../../utils';
import Card from '../components/Card';
import Chip from '../components/Chip';
import ChunkLimitNotice from '../components/ChunkLimitNotice';
import DataTable from '../components/DataTable';
import ModerationModal from '../components/ModerationModal';
import Pagination from '../components/Pagination';

/**
 * Refresh the pending + moderation count behind the Needs Attention badge.
 *
 * @param {Function} setAttentionCount Store action.
 */
const fetchAttentionCount = async ( setAttentionCount ) => {
	try {
		const response = await apiFetch( {
			path: `${ window.hyve.api }/data/counts`,
		} );

		setAttentionCount(
			Number( response.pending ?? 0 ) + Number( response.moderation ?? 0 )
		);
	} catch ( error ) {}
};

const getSources = () =>
	Object.entries( getRoutes().kb?.subs ?? {} ).filter( ( [ key ] ) =>
		key.startsWith( 'source-' )
	);

const SourcesGrid = () => {
	const isPro = Boolean( window.hyve?.license );

	return (
		<Card title={ __( 'Add a source', 'hyve-lite' ) }>
			<div className="hyve-next-card__body">
				<div className="hyve-next-src">
					{ getSources().map( ( [ key, source ] ) => (
						<button
							key={ key }
							type="button"
							className="hyve-next-src__card"
							onClick={ () => {
								navigate( 'kb', key );

								window.hyveTrk?.add?.( {
									feature: 'knowledge-base-source',
									featureValue: source.track ?? key,
								} );
							} }
						>
							{ source.isPro && ! isPro && (
								<Chip tone="pro" dot={ false }>
									{ __( 'Pro', 'hyve-lite' ) }
								</Chip>
							) }
							<span className="hyve-next-src__icon">
								<Icon icon={ source.icon } />
							</span>
							<h4>{ source.label }</h4>
							<p>{ source.description }</p>
						</button>
					) ) }
				</div>
			</div>
		</Card>
	);
};

// Pro sources join the Source filter of the unified listing; their values are
// resolved server-side by pro through the `hyve_data_query_args` filter.
const getIndexedSources = () => {
	const options = getPostTypes();

	if ( ! window.hyve?.license ) {
		return options;
	}

	getSources().forEach( ( [ key, source ] ) => {
		if ( 'source-wordpress' === key || ! source.track ) {
			return;
		}

		options.push( {
			label: source.label,
			value: `hyve:${ source.track }`,
		} );
	} );

	return options;
};

const IndexedContent = () => {
	const [ rows, setRows ] = useState( [] );
	const [ hasMore, setHasMore ] = useState( false );
	const [ isLoading, setLoading ] = useState( true );
	const [ isDeleting, setDeleting ] = useState( [] );
	const [ confirmRemove, setConfirmRemove ] = useState( null );
	const [ page, setPage ] = useState( 0 );
	const [ total, setTotal ] = useState( 0 );
	const [ refresh, setRefresh ] = useState( 0 );
	const [ sourceType, setSourceType ] = useState( 'any' );

	// Page size comes from the endpoint.
	const perPageRef = useRef( 20 );

	const totalChunks = useSelect( ( select ) =>
		select( 'hyve' ).getTotalChunks()
	);

	const { setTotalChunks, setAttentionCount } = useDispatch( 'hyve' );
	const { createNotice } = useDispatch( 'core/notices' );

	useEffect( () => {
		const fetchPosts = async () => {
			setLoading( true );

			try {
				// `hyve:all` asks pro to include its sources in the union;
				// plain `any` stays WordPress-only so the old dashboard's
				// listings keep their behavior.
				const type =
					'any' === sourceType && window.hyve?.license
						? 'hyve:all'
						: sourceType;

				const response = await apiFetch( {
					path: addQueryArgs( `${ window.hyve.api }/data`, {
						offset: page * perPageRef.current,
						status: 'included',
						type,
					} ),
				} );

				setRows( response.posts ?? [] );
				setHasMore( Boolean( response.more ) );
				setTotal( Number( response.total ?? 0 ) );
				setTotalChunks( response?.totalChunks );

				if ( response.per_page ) {
					perPageRef.current = Number( response.per_page );
				}
			} catch ( error ) {
				setHasMore( false );
			}

			setLoading( false );
		};

		fetchPosts();
	}, [ page, refresh, sourceType, setTotalChunks ] );

	const onDelete = async ( id ) => {
		setDeleting( ( prev ) => [ ...prev, id ] );

		try {
			await apiFetch( {
				path: addQueryArgs( `${ window.hyve.api }/data`, { id } ),
				method: 'DELETE',
			} );

			createNotice(
				'success',
				__( 'Post has been removed.', 'hyve-lite' ),
				{
					type: 'snackbar',
					isDismissible: true,
				}
			);

			// Refetch so the page refills; step back when the page emptied.
			if ( 1 === rows.length && 0 < page ) {
				setPage( page - 1 );
			} else {
				setRefresh( ( prev ) => prev + 1 );
			}

			// The removed post may have been counted as needing an update.
			fetchAttentionCount( setAttentionCount );
		} catch ( error ) {
			createNotice( 'error', error?.message ?? String( error ), {
				type: 'snackbar',
				isDismissible: true,
			} );
		}

		setDeleting( ( prev ) => prev.filter( ( postId ) => postId !== id ) );
	};

	const totalPages = Math.max( 1, Math.ceil( total / perPageRef.current ) );

	return (
		<Card
			title={ __( 'Indexed content', 'hyve-lite' ) }
			footer={
				( 0 < page || hasMore ) && (
					<Pagination
						page={ page }
						totalPages={ totalPages }
						hasMore={ hasMore }
						isLoading={ isLoading }
						onChange={ setPage }
					/>
				)
			}
			actions={
				<Chip tone="muted">
					{ sprintf(
						/* translators: %s: number of knowledge base chunks. */
						__( '%s chunks', 'hyve-lite' ),
						Number( totalChunks ?? 0 ).toLocaleString()
					) }
				</Chip>
			}
		>
			<div className="hyve-next-card__intro">
				<p>
					{ __(
						'All the content that has been added to the Knowledge Base.',
						'hyve-lite'
					) }
				</p>
			</div>

			<div className="hyve-next-card__body">
				<div className="hyve-next-toolbar">
					<SelectControl
						__nextHasNoMarginBottom
						hideLabelFromVision
						label={ __( 'Source', 'hyve-lite' ) }
						options={ getIndexedSources() }
						value={ sourceType }
						onChange={ ( value ) => {
							setSourceType( value );
							setPage( 0 );
						} }
					/>
					<span className="hyve-next-toolbar__grow"></span>
					{ ! isLoading && (
						<span className="hyve-next-toolbar__count">
							{ sprintf(
								/* translators: %s: number of matching items. */
								_n(
									'%s result',
									'%s results',
									total,
									'hyve-lite'
								),
								total.toLocaleString()
							) }
						</span>
					) }
				</div>
			</div>

			<DataTable
				columns={ [
					{
						key: 'title',
						label: __( 'Title', 'hyve-lite' ),
						render: ( row ) => (
							<div className="hyve-next-table__main">
								<span className="hyve-next-table__title">
									{ row.title }
								</span>
								{ row.error && (
									<span className="hyve-next-table__sub">
										{ sprintf(
											// translators: %s: the reason indexing failed, including whether it will be retried.
											__(
												'Indexing failed: %s',
												'hyve-lite'
											),
											row.error
										) }
									</span>
								) }
							</div>
						),
					},
					{
						key: 'type',
						label: __( 'Source', 'hyve-lite' ),
					},
					{
						key: 'chunks',
						label: __( 'Chunks', 'hyve-lite' ),
						align: 'num',
						render: ( row ) =>
							Number( row.chunks ?? 0 ).toLocaleString(),
					},
					{
						key: 'status',
						label: __( 'Status', 'hyve-lite' ),
						render: ( row ) =>
							row.error ? (
								<Chip tone="warn">
									{ __( 'Indexing failed', 'hyve-lite' ) }
								</Chip>
							) : (
								<Chip tone="ok">
									{ __( 'Indexed', 'hyve-lite' ) }
								</Chip>
							),
					},
					{
						key: 'actions',
						label: __( 'Actions', 'hyve-lite' ),
						align: 'actions',
						render: ( row ) => (
							<Button
								variant="secondary"
								isDestructive
								isBusy={ isDeleting.includes( row.ID ) }
								disabled={ isDeleting.includes( row.ID ) }
								onClick={ () => setConfirmRemove( row ) }
							>
								{ __( 'Remove', 'hyve-lite' ) }
							</Button>
						),
					},
				] }
				rows={ rows }
				isLoading={ isLoading }
				empty={ __(
					'Content you add to the Knowledge Base will appear here.',
					'hyve-lite'
				) }
			/>

			{ confirmRemove && (
				<Modal
					className="hyve-next-modal"
					size="medium"
					title={ __(
						'Remove from the Knowledge Base?',
						'hyve-lite'
					) }
					onRequestClose={ () => setConfirmRemove( null ) }
				>
					<p>
						{ confirmRemove.permanent
							? sprintf(
									/* translators: %s: title of the entry being removed. */
									__(
										'Hyve will stop using "%s" in its answers. The entry is deleted permanently.',
										'hyve-lite'
									),
									confirmRemove.title
							  )
							: sprintf(
									/* translators: %s: title of the content being removed. */
									__(
										'Hyve will stop using "%s" in its answers. The content itself stays on your site, and you can add it back at any time.',
										'hyve-lite'
									),
									confirmRemove.title
							  ) }
					</p>
					<div className="hyve-next-modal__actions">
						<Button
							variant="tertiary"
							onClick={ () => setConfirmRemove( null ) }
						>
							{ __( 'Cancel', 'hyve-lite' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							onClick={ () => {
								const id = confirmRemove.ID;
								setConfirmRemove( null );
								onDelete( id );
							} }
						>
							{ __( 'Remove', 'hyve-lite' ) }
						</Button>
					</div>
				</Modal>
			) }
		</Card>
	);
};

const excludeTypes = [ 'attachment' ];

const getPostTypes = () => {
	const postTypes = ( window.hyve?.postTypes ?? [] ).filter(
		( postType ) => ! excludeTypes.includes( postType.value )
	);

	postTypes.unshift( { label: __( 'All', 'hyve-lite' ), value: 'any' } );

	return postTypes;
};

const VISIBILITY_LABELS = {
	public: __( 'Public', 'hyve-lite' ),
	private: __( 'Private', 'hyve-lite' ),
	password: __( 'Password protected', 'hyve-lite' ),
};

const WordPressDrill = () => {
	const [ rows, setRows ] = useState( [] );
	const [ hasMore, setHasMore ] = useState( false );
	const [ isLoading, setLoading ] = useState( true );
	const [ isUpdating, setUpdating ] = useState( [] );
	const [ processedPosts, setProcessedPosts ] = useState( [] );
	const [ confirmPost, setConfirmPost ] = useState( null );
	const [ page, setPage ] = useState( 0 );
	const [ total, setTotal ] = useState( 0 );
	const [ query, setQuery ] = useState( { type: 'any', search: '' } );

	// Selected rows keyed by ID; rows are kept so the queue can run across
	// pages and check visibility without refetching.
	const [ selected, setSelected ] = useState( {} );
	const [ bulk, setBulk ] = useState( null );
	const [ isBulkConfirmOpen, setBulkConfirmOpen ] = useState( false );
	const [ reviewPost, setReviewPost ] = useState( null );

	// Guards against out-of-order responses when the query changes mid-fetch.
	const requestRef = useRef( 0 );

	// Page size comes from the endpoint.
	const perPageRef = useRef( 20 );

	const { setTotalChunks, setAttentionCount } = useDispatch( 'hyve' );
	const { createNotice } = useDispatch( 'core/notices' );
	const hasReachedLimit = useSelect( ( select ) =>
		select( 'hyve' ).hasReachedLimit()
	);

	useEffect( () => {
		const request = ++requestRef.current;

		setLoading( true );

		const handler = setTimeout( async () => {
			try {
				const response = await apiFetch( {
					path: addQueryArgs( `${ window.hyve.api }/data`, {
						offset: page * perPageRef.current,
						...query,
					} ),
				} );

				if ( request !== requestRef.current ) {
					return;
				}

				setRows( response.posts ?? [] );
				setHasMore( Boolean( response.more ) );
				setTotal( Number( response.total ?? 0 ) );
				setTotalChunks( response?.totalChunks );

				if ( response.per_page ) {
					perPageRef.current = Number( response.per_page );
				}
			} catch ( error ) {
				if ( request !== requestRef.current ) {
					return;
				}

				setHasMore( false );
			}

			setLoading( false );
		}, 500 );

		return () => clearTimeout( handler );
	}, [ query, page, setTotalChunks ] );

	const onChangeQuery = ( key, value ) => {
		setQuery( ( prev ) => ( { ...prev, [ key ]: value } ) );
		setPage( 0 );
	};

	const totalPages = Math.max( 1, Math.ceil( total / perPageRef.current ) );

	const processPost = async ( id ) => {
		setUpdating( ( prev ) => [ ...prev, id ] );

		const currentPost = rows.find( ( row ) => row.ID === id );

		await onProcessData( {
			post: currentPost,
			onSuccess: () => {
				setUpdating( ( prev ) =>
					prev.filter( ( postId ) => postId !== id )
				);
				setProcessedPosts( ( prev ) => [ ...prev, id ] );

				window.hyveTrk?.add?.( {
					feature: 'knowledge-base',
					featureComponent: 'add-data',
					featureValue: 'import-wordpress-data',
				} );
			},
			onError: ( error ) => {
				if (
					'content_failed_moderation' === error?.code &&
					undefined !== error.review
				) {
					setReviewPost( {
						...currentPost,
						review: error.review,
					} );
				}

				fetchAttentionCount( setAttentionCount );
				setUpdating( ( prev ) =>
					prev.filter( ( postId ) => postId !== id )
				);
			},
		} );
	};

	const onProcess = ( id ) => {
		const currentPost = rows.find( ( row ) => row.ID === id );

		// Restricted content is surfaced to any chat visitor once added, so
		// require an explicit confirmation before importing it.
		if ( currentPost && 'public' !== currentPost.visibility ) {
			setConfirmPost( currentPost );
			return;
		}

		processPost( id );
	};

	const toggleSelected = ( row ) => {
		setSelected( ( prev ) => {
			const next = { ...prev };

			if ( next[ row.ID ] ) {
				delete next[ row.ID ];
			} else {
				next[ row.ID ] = row;
			}

			return next;
		} );
	};

	const pageSelectable = rows.filter(
		( row ) => ! processedPosts.includes( row.ID )
	);
	const isPageSelected =
		0 < pageSelectable.length &&
		pageSelectable.every( ( row ) => selected[ row.ID ] );

	const togglePage = () => {
		setSelected( ( prev ) => {
			const next = { ...prev };

			pageSelectable.forEach( ( row ) => {
				if ( isPageSelected ) {
					delete next[ row.ID ];
				} else {
					next[ row.ID ] = row;
				}
			} );

			return next;
		} );
	};

	const runBulk = async ( items ) => {
		setBulk( { done: 0, total: items.length } );

		let added = 0;

		for ( const item of items ) {
			try {
				const response = await apiFetch( {
					path: `${ window.hyve.api }/data`,
					method: 'POST',
					data: { data: item },
				} );

				if ( response.error ) {
					throw new Error( response.error );
				}

				added++;
				setProcessedPosts( ( prev ) => [ ...prev, item.ID ] );
				setSelected( ( prev ) => {
					const next = { ...prev };
					delete next[ item.ID ];
					return next;
				} );

				window.hyveTrk?.add?.( {
					feature: 'knowledge-base',
					featureComponent: 'add-data',
					featureValue: 'import-wordpress-data',
				} );
			} catch ( error ) {
				// Failed items stay selected so they can be retried.
			}

			setBulk( ( prev ) =>
				prev ? { ...prev, done: prev.done + 1 } : prev
			);
		}

		setBulk( null );
		fetchAttentionCount( setAttentionCount );

		const failed = items.length - added;

		if ( failed ) {
			createNotice(
				'warning',
				sprintf(
					/* translators: 1: number of items added, 2: number of items that failed. */
					__(
						'%1$s items added, %2$s failed. Failed items stay selected.',
						'hyve-lite'
					),
					added,
					failed
				),
				{ type: 'snackbar', isDismissible: true }
			);
			return;
		}

		createNotice(
			'success',
			sprintf(
				/* translators: %s: number of items added. */
				_n(
					'%s item added to the Knowledge Base.',
					'%s items added to the Knowledge Base.',
					added,
					'hyve-lite'
				),
				added
			),
			{ type: 'snackbar', isDismissible: true }
		);
	};

	const selectedRows = Object.values( selected );
	const restrictedRows = selectedRows.filter(
		( row ) => 'public' !== row.visibility
	);

	const onBulkAdd = () => {
		if ( 0 < restrictedRows.length ) {
			setBulkConfirmOpen( true );
			return;
		}

		runBulk( selectedRows );
	};

	return (
		<>
			<div className="hyve-next-backrow">
				<Button
					variant="link"
					onClick={ () => navigate( 'kb', 'all' ) }
				>
					{ __( '← All sources', 'hyve-lite' ) }
				</Button>
			</div>

			<Card
				title={ __( 'WordPress', 'hyve-lite' ) }
				footer={
					( 0 < page || hasMore ) && (
						<Pagination
							page={ page }
							totalPages={ totalPages }
							hasMore={ hasMore }
							isLoading={ isLoading }
							onChange={ setPage }
						/>
					)
				}
			>
				<div className="hyve-next-card__intro">
					<p>
						{ __(
							'Select posts that are informative, engaging, and relevant. These will be the building blocks that empower your chat assistant to deliver precise and helpful responses. Whether it is answering FAQs or diving into detailed explanations, the content you choose here will shape how effectively your assistant interacts with users.',
							'hyve-lite'
						) }
					</p>
				</div>

				<div className="hyve-next-card__body">
					<ChunkLimitNotice />

					{ ( 0 < selectedRows.length || bulk ) && (
						<div className="hyve-next-bulkbar">
							<strong>
								{ sprintf(
									/* translators: %s: number of selected items. */
									_n(
										'%s item selected',
										'%s items selected',
										selectedRows.length,
										'hyve-lite'
									),
									selectedRows.length
								) }
							</strong>
							<Button
								variant="secondary"
								disabled={ Boolean( bulk ) }
								onClick={ () => setSelected( {} ) }
							>
								{ __( 'Clear', 'hyve-lite' ) }
							</Button>
							<Button
								variant="primary"
								isBusy={ Boolean( bulk ) }
								disabled={ Boolean( bulk ) || hasReachedLimit }
								onClick={ onBulkAdd }
							>
								{ bulk
									? sprintf(
											/* translators: 1: items processed so far, 2: total items in the queue. */
											__(
												'Adding %1$s of %2$s',
												'hyve-lite'
											),
											Math.min(
												bulk.done + 1,
												bulk.total
											),
											bulk.total
									  )
									: sprintf(
											/* translators: %s: number of selected items. */
											__(
												'Add %s to Knowledge Base',
												'hyve-lite'
											),
											selectedRows.length
									  ) }
							</Button>
						</div>
					) }

					<div className="hyve-next-toolbar">
						<SelectControl
							__nextHasNoMarginBottom
							hideLabelFromVision
							label={ __( 'Post Type', 'hyve-lite' ) }
							options={ getPostTypes() }
							value={ query.type }
							onChange={ ( value ) =>
								onChangeQuery( 'type', value )
							}
						/>
						<SearchControl
							__nextHasNoMarginBottom
							className="hyve-next-toolbar__grow"
							label={ __( 'Search for Posts', 'hyve-lite' ) }
							value={ query.search }
							onChange={ ( value ) =>
								onChangeQuery( 'search', value )
							}
						/>
						{ ! isLoading && (
							<span className="hyve-next-toolbar__count">
								{ sprintf(
									/* translators: %s: number of matching items. */
									_n(
										'%s result',
										'%s results',
										total,
										'hyve-lite'
									),
									total.toLocaleString()
								) }
							</span>
						) }
					</div>
				</div>

				<DataTable
					columns={ [
						{
							key: 'select',
							label: __( 'Select', 'hyve-lite' ),
							align: 'check',
							renderHeader: () => (
								<input
									type="checkbox"
									aria-label={ __(
										'Select all on this page',
										'hyve-lite'
									) }
									checked={ isPageSelected }
									disabled={
										Boolean( bulk ) ||
										0 === pageSelectable.length
									}
									onChange={ togglePage }
								/>
							),
							render: ( row ) =>
								processedPosts.includes( row.ID ) ? null : (
									<input
										type="checkbox"
										aria-label={ sprintf(
											/* translators: %s: title of the item. */
											__( 'Select %s', 'hyve-lite' ),
											row.title
										) }
										checked={ Boolean(
											selected[ row.ID ]
										) }
										disabled={ Boolean( bulk ) }
										onChange={ () => toggleSelected( row ) }
									/>
								),
						},
						{
							key: 'title',
							label: __( 'Title', 'hyve-lite' ),
							render: ( row ) => (
								<div className="hyve-next-table__main">
									<span className="hyve-next-table__title">
										{ row.title }
									</span>
								</div>
							),
						},
						{
							key: 'type',
							label: __( 'Type', 'hyve-lite' ),
						},
						{
							key: 'visibility',
							label: __( 'Visibility', 'hyve-lite' ),
							render: ( row ) =>
								VISIBILITY_LABELS[ row.visibility ] ??
								row.visibility,
						},
						{
							key: 'actions',
							label: __( 'Actions', 'hyve-lite' ),
							align: 'actions',
							render: ( row ) =>
								processedPosts.includes( row.ID ) ? (
									<Chip tone="ok">
										{ __( 'Added', 'hyve-lite' ) }
									</Chip>
								) : (
									<Button
										variant="secondary"
										isBusy={ isUpdating.includes( row.ID ) }
										disabled={
											isUpdating.includes( row.ID ) ||
											hasReachedLimit ||
											Boolean( bulk )
										}
										onClick={ () => onProcess( row.ID ) }
									>
										{ __( 'Add', 'hyve-lite' ) }
									</Button>
								),
						},
					] }
					rows={ rows }
					isLoading={ isLoading }
				/>
			</Card>

			{ confirmPost && (
				<Modal
					className="hyve-next-modal"
					size="medium"
					title={ __( 'Add restricted content?', 'hyve-lite' ) }
					onRequestClose={ () => setConfirmPost( null ) }
				>
					<p>
						{ 'private' === confirmPost.visibility
							? __(
									'This is a private post. Adding it to the Knowledge Base lets the chatbot surface its content to any visitor, even though the post itself is not publicly viewable.',
									'hyve-lite'
							  )
							: __(
									'This is a password-protected post. Adding it to the Knowledge Base lets the chatbot surface its content to any visitor without entering the password.',
									'hyve-lite'
							  ) }
					</p>
					<div className="hyve-next-modal__actions">
						<Button
							variant="tertiary"
							onClick={ () => setConfirmPost( null ) }
						>
							{ __( 'Cancel', 'hyve-lite' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ () => {
								const id = confirmPost.ID;
								setConfirmPost( null );
								processPost( id );
							} }
						>
							{ __( 'Add anyway', 'hyve-lite' ) }
						</Button>
					</div>
				</Modal>
			) }

			{ isBulkConfirmOpen && (
				<Modal
					className="hyve-next-modal"
					size="medium"
					title={ __( 'Add restricted content?', 'hyve-lite' ) }
					onRequestClose={ () => setBulkConfirmOpen( false ) }
				>
					<p>
						{ sprintf(
							/* translators: %s: number of restricted items in the selection. */
							_n(
								'%s selected item is private or password protected. Adding it to the Knowledge Base lets the chatbot surface its content to any visitor.',
								'%s selected items are private or password protected. Adding them to the Knowledge Base lets the chatbot surface their content to any visitor.',
								restrictedRows.length,
								'hyve-lite'
							),
							restrictedRows.length
						) }
					</p>
					<div className="hyve-next-modal__actions">
						<Button
							variant="tertiary"
							onClick={ () => setBulkConfirmOpen( false ) }
						>
							{ __( 'Cancel', 'hyve-lite' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () => {
								setBulkConfirmOpen( false );
								runBulk(
									selectedRows.filter(
										( row ) => 'public' === row.visibility
									)
								);
							} }
						>
							{ __( 'Skip them', 'hyve-lite' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ () => {
								setBulkConfirmOpen( false );
								runBulk( selectedRows );
							} }
						>
							{ __( 'Add anyway', 'hyve-lite' ) }
						</Button>
					</div>
				</Modal>
			) }

			{ reviewPost && (
				<ModerationModal
					post={ reviewPost }
					onClose={ () => setReviewPost( null ) }
					onSuccess={ () => {
						setProcessedPosts( ( prev ) => [
							...prev,
							reviewPost.ID,
						] );
						setReviewPost( null );
						fetchAttentionCount( setAttentionCount );
					} }
				/>
			) }
		</>
	);
};

const ISSUE_CHIPS = {
	pending: (
		<Chip tone="warn">{ __( 'Edited since indexing', 'hyve-lite' ) }</Chip>
	),
	moderation: (
		<Chip tone="bad">{ __( 'Failed moderation', 'hyve-lite' ) }</Chip>
	),
};

const AttentionPanel = () => {
	const [ rows, setRows ] = useState( [] );
	const [ isLoading, setLoading ] = useState( true );
	const [ isUpdating, setUpdating ] = useState( [] );
	const [ reviewPost, setReviewPost ] = useState( null );
	const [ bulkUpdate, setBulkUpdate ] = useState( null );
	const [ refresh, setRefresh ] = useState( 0 );

	const { setTotalChunks, setAttentionCount } = useDispatch( 'hyve' );
	const { createNotice } = useDispatch( 'core/notices' );

	useEffect( () => {
		let isStale = false;

		// Fetches every page of a status; these queues stay small in
		// practice, so a merged full list beats a paginated two-source table.
		const fetchAll = async ( status ) => {
			const collected = [];
			let offset = 0;

			for ( let guard = 0; 10 > guard; guard++ ) {
				const response = await apiFetch( {
					path: addQueryArgs( `${ window.hyve.api }/data`, {
						offset,
						status,
					} ),
				} );

				collected.push(
					...( response.posts ?? [] ).map( ( post ) => ( {
						...post,
						issue: status,
					} ) )
				);

				setTotalChunks( response?.totalChunks );

				if ( ! response.more ) {
					break;
				}

				offset = collected.length;
			}

			return collected;
		};

		const fetchLists = async () => {
			setLoading( true );

			try {
				const [ pending, moderation ] = await Promise.all( [
					fetchAll( 'pending' ),
					fetchAll( 'moderation' ),
				] );

				if ( isStale ) {
					return;
				}

				setRows( [ ...pending, ...moderation ] );
				setAttentionCount( pending.length + moderation.length );
			} catch ( error ) {}

			setLoading( false );
		};

		fetchLists();

		return () => {
			isStale = true;
		};
	}, [ refresh, setTotalChunks, setAttentionCount ] );

	const pendingRows = rows.filter( ( row ) => 'pending' === row.issue );

	const removeRow = ( id ) => {
		setRows( ( prev ) => prev.filter( ( row ) => row.ID !== id ) );
		setAttentionCount( rows.length - 1 );
	};

	const onUpdate = async ( id ) => {
		setUpdating( ( prev ) => [ ...prev, id ] );

		const currentPost = rows.find( ( row ) => row.ID === id );

		await onProcessData( {
			post: currentPost,
			params: {
				action: 'update',
			},
			onSuccess: () => {
				removeRow( id );
			},
			onError: ( error ) => {
				if (
					'content_failed_moderation' === error?.code &&
					undefined !== error.review
				) {
					setReviewPost( {
						...currentPost,
						review: error.review,
					} );
				}
			},
		} );

		setUpdating( ( prev ) => prev.filter( ( postId ) => postId !== id ) );
	};

	const onUpdateAll = async () => {
		const queue = pendingRows;

		setBulkUpdate( { done: 0, total: queue.length } );

		let updated = 0;

		for ( const item of queue ) {
			try {
				const response = await apiFetch( {
					path: `${ window.hyve.api }/data`,
					method: 'POST',
					data: {
						action: 'update',
						data: item,
					},
				} );

				if ( response.error ) {
					throw new Error( response.error );
				}

				updated++;
			} catch ( error ) {
				// Failures stay listed; moderation ones move to Review after
				// the refresh below.
			}

			setBulkUpdate( ( prev ) =>
				prev ? { ...prev, done: prev.done + 1 } : prev
			);
		}

		setBulkUpdate( null );
		setRefresh( ( prev ) => prev + 1 );

		const failed = queue.length - updated;

		if ( failed ) {
			createNotice(
				'warning',
				sprintf(
					/* translators: 1: number of items updated, 2: number of items that failed. */
					__( '%1$s items updated, %2$s failed.', 'hyve-lite' ),
					updated,
					failed
				),
				{ type: 'snackbar', isDismissible: true }
			);
			return;
		}

		createNotice(
			'success',
			sprintf(
				/* translators: %s: number of items updated. */
				_n(
					'%s item updated.',
					'%s items updated.',
					updated,
					'hyve-lite'
				),
				updated
			),
			{ type: 'snackbar', isDismissible: true }
		);
	};

	return (
		<>
			<Card
				title={ __( 'Needs attention', 'hyve-lite' ) }
				actions={
					0 < pendingRows.length && (
						<Button
							variant="primary"
							isBusy={ Boolean( bulkUpdate ) }
							disabled={ Boolean( bulkUpdate ) }
							onClick={ onUpdateAll }
						>
							{ bulkUpdate
								? sprintf(
										/* translators: 1: items processed so far, 2: total items in the queue. */
										__(
											'Updating %1$s of %2$s',
											'hyve-lite'
										),
										Math.min(
											bulkUpdate.done + 1,
											bulkUpdate.total
										),
										bulkUpdate.total
								  )
								: __( 'Update all', 'hyve-lite' ) }
						</Button>
					)
				}
			>
				<div className="hyve-next-card__intro">
					<p>
						{ __(
							'One inbox for content that needs a decision: items edited since they were indexed, and items that failed moderation. Review shows the flagged categories and lets you override a false positive.',
							'hyve-lite'
						) }
					</p>
				</div>

				<DataTable
					columns={ [
						{
							key: 'title',
							label: __( 'Title', 'hyve-lite' ),
							render: ( row ) => (
								<div className="hyve-next-table__main">
									<span className="hyve-next-table__title">
										{ row.title }
									</span>
								</div>
							),
						},
						{
							key: 'type',
							label: __( 'Source', 'hyve-lite' ),
						},
						{
							key: 'issue',
							label: __( 'Issue', 'hyve-lite' ),
							render: ( row ) => ISSUE_CHIPS[ row.issue ],
						},
						{
							key: 'actions',
							label: __( 'Actions', 'hyve-lite' ),
							align: 'actions',
							render: ( row ) =>
								'pending' === row.issue ? (
									<Button
										variant="secondary"
										isBusy={ isUpdating.includes( row.ID ) }
										disabled={
											isUpdating.includes( row.ID ) ||
											Boolean( bulkUpdate )
										}
										onClick={ () => onUpdate( row.ID ) }
									>
										{ __( 'Update', 'hyve-lite' ) }
									</Button>
								) : (
									<div className="hyve-next-buttons">
										<Button
											variant="secondary"
											disabled={
												isUpdating.includes( row.ID ) ||
												Boolean( bulkUpdate )
											}
											onClick={ () =>
												setReviewPost( row )
											}
										>
											{ __( 'Review', 'hyve-lite' ) }
										</Button>
										<Button
											variant="secondary"
											isBusy={ isUpdating.includes(
												row.ID
											) }
											disabled={
												isUpdating.includes( row.ID ) ||
												Boolean( bulkUpdate )
											}
											onClick={ () => onUpdate( row.ID ) }
										>
											{ __( 'Retry', 'hyve-lite' ) }
										</Button>
									</div>
								),
						},
					] }
					rows={ rows }
					isLoading={ isLoading }
					empty={ __(
						'Nothing needs your attention right now.',
						'hyve-lite'
					) }
				/>
			</Card>

			{ reviewPost && (
				<ModerationModal
					post={ reviewPost }
					onClose={ () => setReviewPost( null ) }
					onSuccess={ () => {
						setReviewPost( null );
						setRefresh( ( prev ) => prev + 1 );
					} }
				/>
			) }
		</>
	);
};

// Body copy and upsell blocks reuse the old drill-in strings; the pro-owned
// working panels replace these routes via the `hyve.routes` filter.
const LOCKED_COPY = {
	'source-custom': {
		body: __(
			'Custom Data allows you to privately feed specific data directly into your chat bot without displaying this information on your public website. With this, you can equip your bot with unique, specialized knowledge that aligns with your business needs and customer queries.',
			'hyve-lite'
		),
		title: __( 'Custom Data is a Premium feature', 'hyve-lite' ),
		text: __(
			'Privately feed specific data directly into your chatbot, equipping specialized knowledge that aligns with your business needs and customer queries. Upgrade now!',
			'hyve-lite'
		),
		campaign: 'custom-data-feature',
	},
	'source-url': {
		body: __(
			'Crawl URLs to add content to the Knowledge Base.',
			'hyve-lite'
		),
		title: __( 'URL Crawling is a Premium feature', 'hyve-lite' ),
		text: __(
			'Use this tool to crawl a website and add its content to the Knowledge Base using the sitemap. Upgrade now!',
			'hyve-lite'
		),
		campaign: 'website-crawling-feature',
	},
	'source-sitemap': {
		body: __(
			'Use this tool to crawl a website and add its content to the Knowledge Base using the sitemap.',
			'hyve-lite'
		),
		title: __( 'Sitemap Crawling is a Premium feature', 'hyve-lite' ),
		text: __(
			'Use this tool to crawl a website and add its content to the Knowledge Base using the sitemap. Upgrade now!',
			'hyve-lite'
		),
		campaign: 'sitemap-crawling-feature',
	},
	'source-documents': {
		body: __(
			'Import PDF, Word, Markdown, Text, or CSV files from your Media Library into the Knowledge Base.',
			'hyve-lite'
		),
		title: __( 'Document Import is a Premium feature', 'hyve-lite' ),
		text: __(
			'Upload PDF, Word, Markdown, Text, and CSV files and add their content to the Knowledge Base. Upgrade now!',
			'hyve-lite'
		),
		campaign: 'document-import-feature',
	},
};

const previewTitleColumn = ( key, label ) => ( {
	key,
	label,
	render: ( row ) => (
		<div className="hyve-next-table__main">
			<span className="hyve-next-table__title">{ row[ key ] }</span>
		</div>
	),
} );

const previewActionColumn = ( label, isDestructive = false ) => ( {
	key: 'actions',
	label: __( 'Actions', 'hyve-lite' ),
	align: 'actions',
	render: () => (
		<Button variant="secondary" isDestructive={ isDestructive } disabled>
			{ label }
		</Button>
	),
} );

// Dummy rows from the old lite locked pages, previewing the pro panels.
const LOCKED_PREVIEWS = {
	'source-custom': {
		columns: [
			previewTitleColumn( 'title', __( 'Title', 'hyve-lite' ) ),
			previewActionColumn( __( 'Edit', 'hyve-lite' ) ),
		],
		rows: [
			__( 'Halloween Limited Time Deal Information', 'hyve-lite' ),
			__( 'What to do if my order is missing an item?', 'hyve-lite' ),
			__( 'How do I return an item?', 'hyve-lite' ),
			__( 'How do I track my order?', 'hyve-lite' ),
			__( 'How do I change my delivery address?', 'hyve-lite' ),
			__( 'How do I cancel my order?', 'hyve-lite' ),
		].map( ( title, index ) => ( { ID: index + 1, title } ) ),
	},
	'source-url': {
		columns: [
			previewTitleColumn( 'title', __( 'URL', 'hyve-lite' ) ),
			previewActionColumn( __( 'Delete', 'hyve-lite' ), true ),
		],
		rows: Array.from( { length: 5 }, ( _, index ) => ( {
			ID: index + 1,
			title: `https://example.com/page${ index + 1 }`,
		} ) ),
	},
	'source-sitemap': {
		columns: [
			previewTitleColumn( 'url', __( 'Sitemap URL', 'hyve-lite' ) ),
			{
				key: 'status',
				label: __( 'Status', 'hyve-lite' ),
				render: ( row ) => (
					<Chip tone={ row.done ? 'ok' : 'warn' }>
						{ row.done
							? __( 'Completed', 'hyve-lite' )
							: __( 'Queued', 'hyve-lite' ) }
					</Chip>
				),
			},
			previewActionColumn( __( 'Details', 'hyve-lite' ) ),
		],
		rows: [ false, true, true ].map( ( done, index ) => ( {
			ID: index + 1,
			url: 'https://example.com/sitemap.xml',
			done,
		} ) ),
	},
	'source-documents': {
		columns: [
			previewTitleColumn( 'title', __( 'Title', 'hyve-lite' ) ),
			previewActionColumn( __( 'Delete', 'hyve-lite' ), true ),
		],
		rows: Array.from( { length: 5 }, ( _, index ) => ( {
			ID: index + 1,
			title: `document-${ index + 1 }.pdf`,
		} ) ),
	},
};

const LockedSource = ( { subKey } ) => {
	const isPro = Boolean( window.hyve?.license );

	const source = getRoutes().kb?.subs?.[ subKey ];
	const copy = LOCKED_COPY[ subKey ];

	return (
		<>
			<div className="hyve-next-backrow">
				<Button
					variant="link"
					onClick={ () => navigate( 'kb', 'all' ) }
				>
					{ __( '← All sources', 'hyve-lite' ) }
				</Button>
			</div>

			<Card
				title={ source?.label }
				actions={
					! isPro && (
						<Chip tone="pro" dot={ false }>
							{ __( 'Pro', 'hyve-lite' ) }
						</Chip>
					)
				}
			>
				<div className="hyve-next-card__body">
					<p>{ copy?.body ?? source?.description }</p>
					{ isPro && (
						<p className="hyve-next-card__hint">
							{ __(
								'This panel is on its way here.',
								'hyve-lite'
							) }
						</p>
					) }
				</div>

				{ ! isPro && LOCKED_PREVIEWS[ subKey ] && (
					<div className="hyve-next-preview">
						<DataTable
							columns={ LOCKED_PREVIEWS[ subKey ].columns }
							rows={ LOCKED_PREVIEWS[ subKey ].rows }
							rowKey={ ( row ) => row.ID }
						/>
					</div>
				) }

				{ ! isPro && copy && (
					<div className="hyve-next-act__upsell">
						<strong>{ copy.title }</strong>
						<p>{ copy.text }</p>
						<Button
							variant="primary"
							href={ setUtm( window.hyve?.pro, copy.campaign ) }
							target="_blank"
						>
							{ __( 'Unlock with Pro', 'hyve-lite' ) }
						</Button>
					</div>
				) }
			</Card>
		</>
	);
};

// Dummy rows from the old lite FAQ page, previewing how the pro panel looks.
const FAQ_PREVIEW = [
	{
		question: __( 'How do I reset my password?', 'hyve-lite' ),
		count: 5,
	},
	{
		question: __( 'How do I change my email address?', 'hyve-lite' ),
		count: 3,
	},
	{
		question: __( 'How do I update my payment method?', 'hyve-lite' ),
		count: 2,
	},
	{
		question: __( 'How do I cancel my subscription?', 'hyve-lite' ),
		count: 1,
	},
	{
		question: __( 'How do I change my plan?', 'hyve-lite' ),
		count: 1,
	},
	{
		question: __( 'How do I update my billing information?', 'hyve-lite' ),
		count: 1,
	},
];

// The working FAQ panel is pro-owned (`GET {api}/faq`) and ships with P2.
const FaqPanel = () => {
	const isPro = Boolean( window.hyve?.license );

	return (
		<Card
			title={ __( 'FAQ', 'hyve-lite' ) }
			actions={
				! isPro && (
					<Chip tone="pro" dot={ false }>
						{ __( 'Pro', 'hyve-lite' ) }
					</Chip>
				)
			}
		>
			<div className="hyve-next-card__body">
				<p>
					{ __(
						"The FAQ captures frequently asked questions that went unanswered by our chatbot, providing you with a valuable insight into what your users are seeking. This feature allows you to review these queries and decide whether to incorporate them into your bot's knowledge base. By actively updating your FAQ, you can continuously refine your chatbot's ability to address user needs effectively and enhance their interactive experience. These aren't updated instantly.",
						'hyve-lite'
					) }
				</p>
				{ isPro && (
					<p className="hyve-next-card__hint">
						{ __( 'This panel is on its way here.', 'hyve-lite' ) }
					</p>
				) }
			</div>

			{ ! isPro && (
				<>
					<div className="hyve-next-preview">
						<DataTable
							columns={ [
								{
									key: 'question',
									label: __( 'Question', 'hyve-lite' ),
									render: ( row ) => (
										<div className="hyve-next-table__main">
											<span className="hyve-next-table__title">
												{ row.question }
											</span>
										</div>
									),
								},
								{
									key: 'count',
									label: __( 'Asked', 'hyve-lite' ),
									align: 'num',
								},
								{
									key: 'actions',
									label: __( 'Actions', 'hyve-lite' ),
									align: 'actions',
									render: () => (
										<div className="hyve-next-buttons">
											<Button
												variant="secondary"
												isDestructive
												disabled
											>
												{ __( 'Delete', 'hyve-lite' ) }
											</Button>
											<Button
												variant="secondary"
												disabled
											>
												{ __( 'Answer', 'hyve-lite' ) }
											</Button>
										</div>
									),
								},
							] }
							rows={ FAQ_PREVIEW }
							rowKey={ ( row ) => row.question }
						/>
					</div>

					<div className="hyve-next-act__upsell">
						<strong>
							{ __( 'FAQ is a Premium feature', 'hyve-lite' ) }
						</strong>
						<p>
							{ __(
								"Review unanswered questions, enhance your bot's knowledge base, and refine your users' interactive experience. Upgrade now!",
								'hyve-lite'
							) }
						</p>
						<Button
							variant="primary"
							href={ setUtm( window.hyve?.pro, 'faq-feature' ) }
							target="_blank"
						>
							{ __( 'Unlock with Pro', 'hyve-lite' ) }
						</Button>
					</div>
				</>
			) }
		</Card>
	);
};

const KnowledgeBase = ( { sub } ) => {
	const { setAttentionCount } = useDispatch( 'hyve' );

	// Keeps the Needs Attention badge current on every panel of the screen.
	useEffect( () => {
		fetchAttentionCount( setAttentionCount );
	}, [ setAttentionCount ] );

	// Pro swaps whole panels by attaching `component` to a route sub entry.
	const ProPanel = sub ? getRoutes().kb?.subs?.[ sub ]?.component : null;

	if ( ProPanel ) {
		return <ProPanel />;
	}

	if ( 'source-wordpress' === sub ) {
		return <WordPressDrill />;
	}

	if ( sub?.startsWith( 'source-' ) ) {
		return <LockedSource subKey={ sub } />;
	}

	if ( 'attention' === sub ) {
		return <AttentionPanel />;
	}

	if ( 'faq' === sub ) {
		return <FaqPanel />;
	}

	return (
		<>
			<SourcesGrid />
			<IndexedContent />
		</>
	);
};

export default KnowledgeBase;
