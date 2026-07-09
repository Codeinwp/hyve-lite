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
import Pagination from '../components/Pagination';

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

const IndexedContent = () => {
	const [ rows, setRows ] = useState( [] );
	const [ hasMore, setHasMore ] = useState( false );
	const [ isLoading, setLoading ] = useState( true );
	const [ isDeleting, setDeleting ] = useState( [] );
	const [ confirmRemove, setConfirmRemove ] = useState( null );
	const [ page, setPage ] = useState( 0 );
	const [ total, setTotal ] = useState( 0 );
	const [ refresh, setRefresh ] = useState( 0 );

	// Page size comes from the endpoint.
	const perPageRef = useRef( 20 );

	const totalChunks = useSelect( ( select ) =>
		select( 'hyve' ).getTotalChunks()
	);

	const { setTotalChunks } = useDispatch( 'hyve' );
	const { createNotice } = useDispatch( 'core/notices' );

	useEffect( () => {
		const fetchPosts = async () => {
			setLoading( true );

			try {
				const response = await apiFetch( {
					path: addQueryArgs( `${ window.hyve.api }/data`, {
						offset: page * perPageRef.current,
						status: 'included',
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
	}, [ page, refresh, setTotalChunks ] );

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
						'All the content from your WordPress site that has been added to the Knowledge Base.',
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
						{ sprintf(
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

	// Guards against out-of-order responses when the query changes mid-fetch.
	const requestRef = useRef( 0 );

	// Page size comes from the endpoint.
	const perPageRef = useRef( 20 );

	const { setTotalChunks } = useDispatch( 'hyve' );
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
			// Moderation-failure review escalation arrives with S2.5; until
			// then onProcessData surfaces the error as a snackbar.
			onError: () => {
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
		</>
	);
};

// Body copy and upsell blocks reuse the old drill-in strings; the pro-owned
// working panels replace these routes via `hyve.next.routes` (P1).
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

const Placeholder = () => (
	<div className="hyve-next__card">
		<p>{ __( 'This panel is on its way here.', 'hyve-lite' ) }</p>
	</div>
);

const KnowledgeBase = ( { sub } ) => {
	if ( 'source-wordpress' === sub ) {
		return <WordPressDrill />;
	}

	if ( sub?.startsWith( 'source-' ) ) {
		return <LockedSource subKey={ sub } />;
	}

	if ( 'attention' === sub || 'faq' === sub ) {
		return <Placeholder />;
	}

	return (
		<>
			<SourcesGrid />
			<IndexedContent />
		</>
	);
};

export default KnowledgeBase;
