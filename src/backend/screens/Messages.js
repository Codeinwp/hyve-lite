/**
 * WordPress dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import { Button, Modal, Spinner } from '@wordpress/components';

import { useDispatch } from '@wordpress/data';

import { useEffect, useRef, useState } from '@wordpress/element';

import { lock } from '@wordpress/icons';

import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies.
 */
import { navigate } from '../router';
import { setUtm } from '../utils';
import Card from '../components/Card';
import Chip from '../components/Chip';
import Pagination from '../components/Pagination';

// Threads already fetched for the list, so the drill-in can open instantly.
const threadCache = new Map();

const cacheThreads = ( posts ) =>
	posts.forEach( ( post ) => threadCache.set( String( post.ID ), post ) );

const shortDate = ( dateString ) => {
	const date = new Date( dateString );

	const options = { month: 'short', day: 'numeric' };

	if ( date.getFullYear() !== new Date().getFullYear() ) {
		options.year = 'numeric';
	}

	return new Intl.DateTimeFormat( undefined, options ).format( date );
};

const timeOfDay = ( unixSeconds ) =>
	new Intl.DateTimeFormat( undefined, {
		hour: 'numeric',
		minute: '2-digit',
	} ).format( new Date( unixSeconds * 1000 ) );

const messageCount = ( thread ) =>
	Array.isArray( thread.thread ) ? thread.thread.length : 0;

const snippet = ( thread ) => {
	const last = Array.isArray( thread.thread )
		? thread.thread[ thread.thread.length - 1 ]
		: null;

	return ( last?.message || '' ).replace( /<[^>]+>/g, '' ).trim();
};

const ExportAction = () => {
	const exportURL = window.hyve?.exportMessagesURL;

	if ( exportURL ) {
		return (
			<Button variant="secondary" href={ exportURL }>
				{ __( 'Export CSV', 'hyve-lite' ) }
			</Button>
		);
	}

	return (
		<>
			<Chip tone="pro" dot={ false }>
				{ __( 'Pro', 'hyve-lite' ) }
			</Chip>
			<Button variant="secondary" icon={ lock } disabled>
				{ __( 'Export CSV', 'hyve-lite' ) }
			</Button>
		</>
	);
};

const ConversationsPanel = () => {
	const isPro = Boolean( window.hyve?.license );

	const [ posts, setPosts ] = useState( [] );
	const [ hasMore, setHasMore ] = useState( false );
	const [ isLoading, setLoading ] = useState( true );
	const [ page, setPage ] = useState( 0 );
	const [ total, setTotal ] = useState( 0 );

	// Page size comes from the endpoint (3 on free, 10 on pro).
	const perPageRef = useRef( 0 );

	useEffect( () => {
		const fetchThreads = async () => {
			setLoading( true );

			try {
				const response = await apiFetch( {
					path: addQueryArgs( `${ window.hyve.api }/threads`, {
						offset: page * perPageRef.current,
					} ),
				} );

				const threads = response.posts ?? [];

				cacheThreads( threads );
				setPosts( threads );
				setHasMore( Boolean( response.more ) );
				setTotal( Number( response.total ?? 0 ) );

				if ( response.per_page ) {
					perPageRef.current = Number( response.per_page );
				}
			} catch {
				setHasMore( false );
			}

			setLoading( false );
		};

		fetchThreads();
	}, [ page ] );

	const totalPages = perPageRef.current
		? Math.max( 1, Math.ceil( total / perPageRef.current ) )
		: 1;

	return (
		<Card
			title={ __( 'Conversations', 'hyve-lite' ) }
			actions={ <ExportAction /> }
			footer={
				isPro &&
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
			{ isLoading && 0 === posts.length && (
				<div className="hyve-next-act__note">
					<Spinner />
				</div>
			) }

			{ ! isLoading && 0 === posts.length && (
				<p className="hyve-next-act__note">
					{ __(
						'Conversations will appear here once visitors start chatting with Hyve.',
						'hyve-lite'
					) }
				</p>
			) }

			{ 0 < posts.length && (
				<div className="hyve-next-table-wrap">
					<table className="hyve-next-table">
						<thead>
							<tr>
								<th>{ __( 'Conversation', 'hyve-lite' ) }</th>
								<th>{ __( 'Messages', 'hyve-lite' ) }</th>
								<th>{ __( 'Date', 'hyve-lite' ) }</th>
								<th>
									<span className="screen-reader-text">
										{ __( 'Actions', 'hyve-lite' ) }
									</span>
								</th>
							</tr>
						</thead>
						<tbody>
							{ posts.map( ( post ) => (
								<tr key={ post.ID }>
									<td className="hyve-next-table__main">
										<span className="hyve-next-table__title">
											{ post.title }
										</span>
										{ snippet( post ) && (
											<span className="hyve-next-table__sub">
												{ snippet( post ) }
											</span>
										) }
									</td>
									<td className="hyve-next-table__num">
										{ messageCount( post ) }
									</td>
									<td>{ shortDate( post.date ) }</td>
									<td className="hyve-next-table__actions">
										<Button
											variant="secondary"
											onClick={ () =>
												navigate(
													'messages',
													'thread',
													{ item: post.ID }
												)
											}
										>
											{ __( 'View', 'hyve-lite' ) }
										</Button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			{ ! isPro && hasMore && (
				<div className="hyve-next-act__upsell">
					<strong>
						{ __( 'Read every conversation', 'hyve-lite' ) }
					</strong>
					<p>
						{ __(
							'Hyve Pro shows the full history, not just the latest three.',
							'hyve-lite'
						) }
					</p>
					<Button
						variant="primary"
						href={ setUtm( window.hyve?.pro, 'messages-feature' ) }
						target="_blank"
					>
						{ __( 'Unlock with Pro', 'hyve-lite' ) }
					</Button>
				</div>
			) }
		</Card>
	);
};

const ThreadView = ( { item } ) => {
	const [ thread, setThread ] = useState(
		() => threadCache.get( String( item ) ) ?? null
	);
	const [ isDeleting, setDeleting ] = useState( false );
	const [ isConfirmOpen, setConfirmOpen ] = useState( false );

	const { createNotice } = useDispatch( 'core/notices' );

	useEffect( () => {
		if ( thread ) {
			return;
		}

		// Deep link: the list was never loaded, so look the thread up in the
		// first page of results.
		const findThread = async () => {
			try {
				const response = await apiFetch( {
					path: addQueryArgs( `${ window.hyve.api }/threads`, {
						offset: 0,
					} ),
				} );

				cacheThreads( response.posts ?? [] );
				setThread( threadCache.get( String( item ) ) ?? false );
			} catch {
				setThread( false );
			}
		};

		findThread();
	}, [ thread, item ] );

	const onDelete = async () => {
		setDeleting( true );

		try {
			const response = await apiFetch( {
				path: addQueryArgs( `${ window.hyve.api }/threads`, {
					id: thread.ID,
				} ),
				method: 'DELETE',
			} );

			if ( response.data ) {
				threadCache.delete( String( thread.ID ) );
				createNotice( 'success', response.data, {
					type: 'snackbar',
					isDismissible: true,
				} );

				window.hyveTrk?.add?.( {
					feature: 'dashboard',
					featureComponent: 'messages-tab',
					featureValue: 'delete-thread',
				} );

				navigate( 'messages', 'conversations' );
				return;
			}
		} catch ( error ) {
			createNotice( 'error', error?.message, {
				type: 'snackbar',
				isDismissible: true,
			} );
		}

		setDeleting( false );
	};

	return (
		<>
			<div className="hyve-next-backrow">
				<Button
					variant="link"
					onClick={ () => navigate( 'messages', 'conversations' ) }
				>
					{ __( '← All conversations', 'hyve-lite' ) }
				</Button>
			</div>

			{ null === thread && (
				<div className="hyve-next-act__note">
					<Spinner />
				</div>
			) }

			{ false === thread && (
				<div className="hyve-next__card">
					<h1>{ __( 'Conversation not found', 'hyve-lite' ) }</h1>
					<p>
						{ __(
							'It may have been deleted, or the link is out of date.',
							'hyve-lite'
						) }
					</p>
				</div>
			) }

			{ thread && (
				<Card
					title={ thread.title }
					actions={
						<Button
							variant="secondary"
							isDestructive
							isBusy={ isDeleting }
							disabled={ isDeleting }
							onClick={ () => setConfirmOpen( true ) }
						>
							{ __( 'Delete conversation', 'hyve-lite' ) }
						</Button>
					}
				>
					<div className="hyve-next-thread__meta">
						{ shortDate( thread.date ) }
						{ ' · ' }
						{ sprintf(
							/* translators: %d: number of messages in the conversation. */
							_n(
								'%d message',
								'%d messages',
								messageCount( thread ),
								'hyve-lite'
							),
							messageCount( thread )
						) }
						{ thread.thread_id && (
							<>
								{ ' · ' }
								{ thread.thread_id.replace( 'thread_', '' ) }
							</>
						) }
					</div>

					<div className="hyve-next-thread">
						{ ( thread.thread ?? [] ).map( ( message, index ) => {
							if ( 'bot' === message.sender ) {
								return (
									<div
										key={ index }
										className="hyve-next-bubble is-bot"
									>
										<p
											dangerouslySetInnerHTML={ {
												__html: message.message,
											} }
										/>
										<time>
											{ timeOfDay( message.time ) }
										</time>
									</div>
								);
							}

							if ( 'user' === message.sender ) {
								return (
									<div
										key={ index }
										className="hyve-next-bubble is-user"
									>
										<p>{ message.message }</p>
										<time>
											{ timeOfDay( message.time ) }
										</time>
									</div>
								);
							}

							return null;
						} ) }
					</div>
				</Card>
			) }

			{ isConfirmOpen && thread && (
				<Modal
					className="hyve-next-modal"
					size="medium"
					title={ __( 'Delete this conversation?', 'hyve-lite' ) }
					onRequestClose={ () => setConfirmOpen( false ) }
				>
					<p>
						{ __(
							'It will be removed permanently. This cannot be undone.',
							'hyve-lite'
						) }
					</p>
					<div className="hyve-next-modal__actions">
						<Button
							variant="tertiary"
							onClick={ () => setConfirmOpen( false ) }
						>
							{ __( 'Cancel', 'hyve-lite' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							onClick={ () => {
								setConfirmOpen( false );
								onDelete();
							} }
						>
							{ __( 'Delete conversation', 'hyve-lite' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
};

const Messages = ( { sub, item } ) => {
	if ( 'thread' === sub && item ) {
		return <ThreadView item={ item } />;
	}

	return <ConversationsPanel />;
};

export default Messages;
