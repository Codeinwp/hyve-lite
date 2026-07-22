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
import { isLicenseActive, setUtm } from '../utils';
import Card from '../components/Card';
import Chip from '../components/Chip';
import Pagination from '../components/Pagination';
import Slot from '../components/Slot';

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

// List URLs come through an extension filter, so only link http(s) ones.
const isSafeUrl = ( url ) =>
	'string' === typeof url && /^https?:\/\//i.test( url );

// One skill display card, mirroring what the widget showed the visitor
// (image, label, description, meta chip, action pills).
const DisplayCard = ( { item } ) => {
	const actions = Array.isArray( item.actions )
		? item.actions.filter( ( action ) => action?.label )
		: [];

	return (
		<div className="hyve-next-cards__item">
			{ isSafeUrl( item.image ) && (
				<img
					className="hyve-next-cards__image"
					src={ item.image }
					alt=""
				/>
			) }
			<span className="hyve-next-cards__body">
				{ isSafeUrl( item.url ) ? (
					<a
						className="hyve-next-cards__title"
						href={ item.url }
						target="_blank"
						rel="noreferrer"
					>
						{ item.label }
					</a>
				) : (
					<span className="hyve-next-cards__title">
						{ item.label }
					</span>
				) }
				{ item.description && (
					<span className="hyve-next-cards__desc">
						{ item.description }
					</span>
				) }
				{ item.meta && (
					<span className="hyve-next-cards__meta">{ item.meta }</span>
				) }
				{ 0 < actions.length && (
					<span className="hyve-next-cards__actions">
						{ actions.map( ( action, i ) =>
							isSafeUrl( action.url ) ? (
								<a
									key={ i }
									className="hyve-next-cards__action"
									href={ action.url }
									target="_blank"
									rel="noreferrer"
								>
									{ action.label }
								</a>
							) : (
								<span
									key={ i }
									className="hyve-next-cards__action"
								>
									{ action.label }
								</span>
							)
						) }
					</span>
				) }
			</span>
		</div>
	);
};

const messageCount = ( thread ) =>
	Array.isArray( thread.thread ) ? thread.thread.length : 0;

const snippet = ( thread ) => {
	const last = Array.isArray( thread.thread )
		? thread.thread[ thread.thread.length - 1 ]
		: null;

	return ( last?.message || '' ).replace( /<[^>]+>/g, '' ).trim();
};

const ExportAction = () => {
	if ( ! window.hyve?.canManageMessages ) {
		return null;
	}

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
	const isPro = isLicenseActive();

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
											{ Boolean( post.lead_id ) && (
												<Chip tone="ok" dot={ false }>
													{ __(
														'Lead',
														'hyve-lite'
													) }
												</Chip>
											) }
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

const DEMO_LEADS = [
	{
		name: 'Jane Cooper',
		email: 'jane@example.com',
		date: __( 'Apr 12', 'hyve-lite' ),
	},
	{
		name: 'Devon Lane',
		email: 'devon@example.com',
		date: __( 'Apr 9', 'hyve-lite' ),
	},
	{
		name: 'Courtney Henry',
		email: 'courtney@example.com',
		date: __( 'Apr 2', 'hyve-lite' ),
	},
];

const LeadsPanel = () => {
	const hasPro = Boolean( window.hyve?.license );

	if ( hasPro ) {
		return (
			<Slot
				name="messages-leads"
				fallback={
					<div className="hyve-next__card">
						<p>
							{ __(
								'The leads list is on its way here.',
								'hyve-lite'
							) }
						</p>
					</div>
				}
			/>
		);
	}

	return (
		<Card
			title={ __( 'Leads', 'hyve-lite' ) }
			actions={
				<Chip tone="pro" dot={ false }>
					{ __( 'Pro', 'hyve-lite' ) }
				</Chip>
			}
		>
			<div className="hyve-next-table-wrap hyve-next-demo">
				<table className="hyve-next-table">
					<thead>
						<tr>
							<th>{ __( 'Contact', 'hyve-lite' ) }</th>
							<th>{ __( 'Conversation', 'hyve-lite' ) }</th>
							<th>{ __( 'Date', 'hyve-lite' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ DEMO_LEADS.map( ( lead ) => (
							<tr key={ lead.email }>
								<td className="hyve-next-table__main">
									<span className="hyve-next-table__title">
										{ lead.name }
									</span>
									<span className="hyve-next-table__sub">
										{ lead.email }
									</span>
								</td>
								<td>
									<Button variant="secondary" disabled>
										{ __( 'View', 'hyve-lite' ) }
									</Button>
								</td>
								<td>{ lead.date }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>

			<div className="hyve-next-act__upsell">
				<strong>
					{ __( 'Turn conversations into leads', 'hyve-lite' ) }
				</strong>
				<p>
					{ __(
						'With Hyve Pro, visitors can leave their contact details right in the chat. Every lead lands here, linked to the conversation it came from, and webhooks can send it to your CRM the moment it arrives.',
						'hyve-lite'
					) }
				</p>
				<Button
					variant="primary"
					href={ setUtm( window.hyve?.pro, 'leads-list' ) }
					target="_blank"
				>
					{ __( 'Unlock with Pro', 'hyve-lite' ) }
				</Button>
			</div>
		</Card>
	);
};

const EVENT_LABELS = {
	contact_form: __( 'Visitor submitted the contact form', 'hyve-lite' ),
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
		if ( ! window.hyve?.canManageMessages ) {
			return;
		}

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
						window.hyve?.canManageMessages && (
							<Button
								variant="secondary"
								isDestructive
								isBusy={ isDeleting }
								disabled={ isDeleting }
								onClick={ () => setConfirmOpen( true ) }
							>
								{ __( 'Delete conversation', 'hyve-lite' ) }
							</Button>
						)
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
						{ Boolean( thread.lead_id ) && (
							<>
								{ ' · ' }
								<Button
									variant="link"
									onClick={ () =>
										navigate( 'messages', 'leads' )
									}
								>
									{ __( 'View lead', 'hyve-lite' ) }
								</Button>
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
										<div
											className="hyve-next-bubble__msg"
											dangerouslySetInnerHTML={ {
												__html: message.message,
											} }
										/>
										{ Array.isArray(
											message.display?.items
										) && (
											<div className="hyve-next-cards">
												{ message.display.items.map(
													( cardItem, i ) => (
														<DisplayCard
															key={ i }
															item={ cardItem }
														/>
													)
												) }
											</div>
										) }
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
										<div className="hyve-next-bubble__msg">
											{ message.message }
										</div>
										<time>
											{ timeOfDay( message.time ) }
										</time>
									</div>
								);
							}

							if (
								'event' === message.sender &&
								EVENT_LABELS[ message.message ]
							) {
								return (
									<div
										key={ index }
										className="hyve-next-thread__event"
									>
										<span>
											{ EVENT_LABELS[ message.message ] }
										</span>
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

	if ( 'leads' === sub ) {
		return <LeadsPanel />;
	}

	return <ConversationsPanel />;
};

export default Messages;
