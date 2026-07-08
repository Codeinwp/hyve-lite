/**
 * WordPress dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import { Button, Icon, SelectControl, Spinner } from '@wordpress/components';

import { useSelect } from '@wordpress/data';

import { useEffect, useState } from '@wordpress/element';

import { archive, brush, cloud, comment, help, people } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { navigate } from '../router';
import { setUtm } from '../../utils';
import Card from '../components/Card';
import Chip from '../components/Chip';
import SetupChecklist from '../components/SetupChecklist';
import StatCard from '../components/StatCard';
import UsageChart from '../components/UsageChart';

const VISIBILITY_COPY = {
	all: {
		title: __( 'Chat is live on all pages', 'hyve-lite' ),
		text: __(
			'Visitors can chat with Hyve site-wide. Blocks and shortcodes stay available for manual placement.',
			'hyve-lite'
		),
	},
	include: {
		title: __( 'Chat is live on selected pages', 'hyve-lite' ),
		text: __(
			'Visitors can chat only on the pages matching your URL rules.',
			'hyve-lite'
		),
	},
	exclude: {
		title: __( 'Chat is live on most pages', 'hyve-lite' ),
		text: __(
			'Visitors can chat everywhere except the pages matching your URL rules.',
			'hyve-lite'
		),
	},
	manual: {
		title: __( 'Chat appears only where you place it', 'hyve-lite' ),
		text: __(
			'Hyve shows where you add its block or shortcode, and nowhere else.',
			'hyve-lite'
		),
	},
};

const RANGE_OPTIONS = [
	{ value: 7, label: __( 'Last 7 days', 'hyve-lite' ) },
	{ value: 14, label: __( 'Last 14 days', 'hyve-lite' ) },
	{ value: 30, label: __( 'Last 30 days', 'hyve-lite' ) },
	{ value: 90, label: __( 'Last 90 days', 'hyve-lite' ) },
];

const GET_STARTED = [
	{
		id: 'knowledge-base',
		icon: archive,
		title: __( 'Grow the knowledge base', 'hyve-lite' ),
		text: __(
			'Add posts, pages and other sources so Hyve answers more accurately.',
			'hyve-lite'
		),
		go: () => navigate( 'kb' ),
	},
	{
		id: 'settings',
		icon: brush,
		title: __( 'Personalize the chat', 'hyve-lite' ),
		text: __(
			'Set the welcome message, brand the widget, and choose where it appears.',
			'hyve-lite'
		),
		go: () => navigate( 'settings', 'chat-behavior' ),
	},
	{
		id: 'docs',
		icon: help,
		title: __( 'Need help?', 'hyve-lite' ),
		text: __(
			'Browse the docs or reach support whenever you get stuck.',
			'hyve-lite'
		),
		go: () => window.open( window.hyve?.docs, '_blank' ),
	},
];

const timeAgo = ( dateString ) => {
	const seconds = Math.max(
		0,
		( Date.now() - new Date( dateString ).getTime() ) / 1000
	);

	const formatter = new Intl.RelativeTimeFormat( undefined, {
		numeric: 'auto',
		style: 'narrow',
	} );

	if ( 3600 > seconds ) {
		return formatter.format(
			-Math.max( 1, Math.round( seconds / 60 ) ),
			'minute'
		);
	}

	if ( 86400 > seconds ) {
		return formatter.format( -Math.round( seconds / 3600 ), 'hour' );
	}

	return formatter.format( -Math.round( seconds / 86400 ), 'day' );
};

const VisibilityNotice = ( { mode } ) => {
	const copy = VISIBILITY_COPY[ mode ] ?? VISIBILITY_COPY.all;

	return (
		<div className="hyve-next-notice">
			<div className="hyve-next-notice__body">
				<strong className="hyve-next-notice__title">
					{ copy.title }
				</strong>
				<p className="hyve-next-notice__text">{ copy.text }</p>
			</div>

			<Button
				variant="secondary"
				onClick={ () => navigate( 'settings', 'chat-behavior' ) }
			>
				{ __( 'Manage visibility', 'hyve-lite' ) }
			</Button>
		</div>
	);
};

const StatsGrid = () => {
	const stats = window.hyve?.stats ?? {};
	const sessions = Number( stats.threads ?? 0 );
	const messages = Number( stats.messages ?? 0 );
	const totalChunks = Number( stats.totalChunks ?? 0 );
	const chunksLimit = Number( window.hyve?.chunksLimit ?? 500 );
	const isQdrantActive = Boolean( window.hyve?.isQdrantActive );

	const usedPercent = Math.min(
		100,
		Math.round( ( totalChunks / chunksLimit ) * 100 )
	);

	const needsStorage = ! isQdrantActive && 400 < totalChunks;

	return (
		<div className="hyve-next-stats">
			<StatCard
				icon={ people }
				label={ __( 'Sessions', 'hyve-lite' ) }
				value={ sessions.toLocaleString() }
				foot={ __(
					'Unique chat sessions started by visitors.',
					'hyve-lite'
				) }
			/>

			<StatCard
				icon={ comment }
				label={ __( 'Messages', 'hyve-lite' ) }
				value={ messages.toLocaleString() }
				foot={ __(
					'Messages exchanged between visitors and Hyve.',
					'hyve-lite'
				) }
			/>

			<StatCard
				icon={ archive }
				label={ __( 'Knowledge base', 'hyve-lite' ) }
				value={ totalChunks.toLocaleString() }
				suffix={
					isQdrantActive
						? __( 'chunks', 'hyve-lite' )
						: sprintf(
								/* translators: %s: the chunk limit of the free plan. */
								__( '/ %s chunks', 'hyve-lite' ),
								chunksLimit.toLocaleString()
						  )
				}
				meter={ isQdrantActive ? undefined : usedPercent }
				foot={
					isQdrantActive ? (
						__( 'Stored in your Qdrant cluster.', 'hyve-lite' )
					) : (
						<>
							{ sprintf(
								/* translators: %d: percentage of the free limit used. */
								__(
									'%d%% of the free limit used.',
									'hyve-lite'
								),
								usedPercent
							) }{ ' ' }
							{ needsStorage && (
								<Button
									variant="link"
									onClick={ () =>
										navigate( 'settings', 'qdrant' )
									}
								>
									{ __( 'Need more storage?', 'hyve-lite' ) }
								</Button>
							) }
						</>
					)
				}
			/>

			<StatCard
				planned
				icon={ cloud }
				label={ __( 'Hyve Connect usage', 'hyve-lite' ) }
				value={ __( 'N/A', 'hyve-lite' ) }
				chip={
					<Chip tone="planned" dot={ false }>
						{ __( 'Planned', 'hyve-lite' ) }
					</Chip>
				}
				foot={ __(
					'Reserved for hosted AI quota once Hyve Connect ships.',
					'hyve-lite'
				) }
			/>
		</div>
	);
};

const UsageCard = () => {
	const [ range, setRange ] = useState( 30 );

	const chart = window.hyve?.chart;
	const hasData = 0 < ( chart?.data?.messages?.length ?? 0 );

	return (
		<Card
			title={ __( 'Usage', 'hyve-lite' ) }
			actions={
				hasData && (
					<SelectControl
						__nextHasNoMarginBottom
						hideLabelFromVision
						label={ __( 'Show data for', 'hyve-lite' ) }
						value={ range }
						options={ RANGE_OPTIONS }
						onChange={ ( value ) => {
							setRange( Number( value ) );
							window.hyveTrk?.add?.( {
								feature: 'charts',
								featureComponent: 'days-filter',
								featureValue: value,
							} );
						} }
					/>
				)
			}
		>
			{ hasData ? (
				<UsageChart
					labels={ chart.labels.slice( -range ) }
					messages={ chart.data.messages.slice( -range ) }
					sessions={ chart.data.sessions.slice( -range ) }
				/>
			) : (
				<p className="hyve-next-card__note">
					{ __(
						'Usage data will appear here once visitors start chatting.',
						'hyve-lite'
					) }
				</p>
			) }
		</Card>
	);
};

const RecentConversations = () => {
	const isPro = Boolean( window.hyve?.license );
	const limit = isPro ? 5 : 3;

	const [ threads, setThreads ] = useState( null );
	const [ hasMore, setHasMore ] = useState( false );

	useEffect( () => {
		const fetchThreads = async () => {
			try {
				const response = await apiFetch( {
					path: `${ window.hyve.api }/threads`,
				} );

				setThreads( response.posts ?? [] );
				setHasMore( Boolean( response.more ) );
			} catch ( error ) {
				setThreads( [] );
			}
		};

		fetchThreads();
	}, [] );

	const rows = ( threads ?? [] ).slice( 0, limit );
	const showUpsell = ! isPro && null !== threads && hasMore;

	return (
		<Card
			title={ __( 'Recent conversations', 'hyve-lite' ) }
			actions={
				<Button variant="link" onClick={ () => navigate( 'messages' ) }>
					{ __( 'View all', 'hyve-lite' ) }
				</Button>
			}
		>
			{ null === threads && (
				<div className="hyve-next-act__note">
					<Spinner />
				</div>
			) }

			{ threads && 0 === threads.length && (
				<p className="hyve-next-act__note">
					{ __(
						'Conversations will appear here once visitors start chatting with Hyve.',
						'hyve-lite'
					) }
				</p>
			) }

			{ rows.map( ( thread ) => {
				const count = Array.isArray( thread.thread )
					? thread.thread.length
					: 0;

				return (
					<div className="hyve-next-act__row" key={ thread.ID }>
						<span className="hyve-next-act__body">
							<span className="hyve-next-act__msg">
								{ thread.title }
							</span>
							<span className="hyve-next-act__meta">
								{ sprintf(
									/* translators: %d: number of messages in the conversation. */
									_n(
										'%d message',
										'%d messages',
										count,
										'hyve-lite'
									),
									count
								) }
							</span>
						</span>

						<span className="hyve-next-act__when">
							{ timeAgo( thread.date ) }
						</span>
					</div>
				);
			} ) }

			{ showUpsell && (
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

const GetStarted = () => {
	return (
		<>
			<h2 className="hyve-next-h2">
				{ __( 'Get started', 'hyve-lite' ) }
			</h2>

			<div className="hyve-next-qa">
				{ GET_STARTED.map( ( item ) => (
					<button
						key={ item.id }
						type="button"
						className="hyve-next-qa__item"
						onClick={ () => {
							item.go();
							window.hyveTrk?.add?.( {
								feature: 'dashboard',
								featureComponent: 'get-started-shortcut',
								featureValue: item.id,
							} );
						} }
					>
						<span className="hyve-next-qa__icon">
							<Icon icon={ item.icon } size={ 18 } />
						</span>
						<span className="hyve-next-qa__title">
							{ item.title } →
						</span>
						<span className="hyve-next-qa__text">
							{ item.text }
						</span>
					</button>
				) ) }
			</div>
		</>
	);
};

const Dashboard = () => {
	const hasAPI = useSelect( ( select ) => select( 'hyve' ).hasAPI() );
	const settings = useSelect( ( select ) => select( 'hyve' ).getSettings() );

	const totalChunks = Number( window.hyve?.stats?.totalChunks ?? 0 );
	const showChecklist = ! hasAPI || 0 === totalChunks;

	return (
		<>
			{ showChecklist && <SetupChecklist /> }

			{ hasAPI && (
				<>
					{ 0 < totalChunks && (
						<VisibilityNotice
							mode={ settings.display_mode ?? 'all' }
						/>
					) }

					<StatsGrid />

					<div className="hyve-next-grid2">
						<UsageCard />
						<RecentConversations />
					</div>

					<GetStarted />
				</>
			) }
		</>
	);
};

export default Dashboard;
