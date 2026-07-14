/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import { useDispatch, useSelect } from '@wordpress/data';

import { useEffect } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import './style.scss';
import { getRoutes, navigate, useRoute } from './router';
import HeaderBar from './components/HeaderBar';
import TabNav from './components/TabNav';
import SideNav from './components/SideNav';
import Notices from './components/Notices';
import ServiceErrors from './components/ServiceErrors';
import Card from './components/Card';
import Chip from './components/Chip';
import ChunkLimitNotice from './components/ChunkLimitNotice';
import DataTable from './components/DataTable';
import FieldRow from './components/FieldRow';
import ModerationModal from './components/ModerationModal';
import Pagination from './components/Pagination';
import Dashboard from './screens/Dashboard';
import KnowledgeBase from './screens/KnowledgeBase';
import Messages from './screens/Messages';
import Settings from './screens/Settings';

// The layout kit and router, bridged for the pro bundle: panels registered
// via `hyve.routes` / `hyve.slot` build on these.
window.hyveComponents = window.hyveComponents || {};
window.hyveComponents.ui = {
	Card,
	Chip,
	ChunkLimitNotice,
	DataTable,
	FieldRow,
	ModerationModal,
	Pagination,
	navigate,
};

const SCREENS = {
	dashboard: Dashboard,
	kb: KnowledgeBase,
	messages: Messages,
	settings: Settings,
};

const App = () => {
	const { screen, sub, item } = useRoute();

	const hasAPI = useSelect( ( select ) => select( 'hyve' ).hasAPI() );

	const attentionCount = useSelect( ( select ) =>
		select( 'hyve' ).getAttentionCount()
	);

	const isConnectActive = useSelect( ( select ) =>
		select( 'hyve' ).isConnectActive()
	);

	const { setSettings, setLoading } = useDispatch( 'hyve' );

	useEffect( () => {
		const fetchSettings = async () => {
			const response = await apiFetch( {
				path: `${ window.hyve.api }/settings`,
			} );

			setSettings( response );
			setLoading();

			// The Themeisle SDK waits for this before injecting campaign
			// banners into #tsdk_banner.
			document.dispatchEvent( new Event( 'themeisle:banner:init' ) );
		};

		fetchSettings();

		window.tsdk_reposition_notice?.();
	}, [ setSettings, setLoading ] );

	const routes = getRoutes();
	const current = routes[ screen ];
	const Screen = SCREENS[ screen ];

	useEffect( () => {
		if ( hasAPI || ! current ) {
			return;
		}

		if ( current.requiresAPI ) {
			navigate( 'dashboard', null, { replace: true } );
			return;
		}

		// Key-gated sub-panel without a key: land on the first open panel.
		if ( sub && current.subs?.[ sub ]?.requiresAPI ) {
			const fallback = Object.keys( current.subs ).find(
				( key ) =>
					! current.subs[ key ].requiresAPI &&
					! current.subs[ key ].hidden
			);

			if ( fallback ) {
				navigate( screen, fallback, { replace: true } );
			}
		}
	}, [ hasAPI, current, screen, sub ] );

	const subs = current?.subs
		? Object.entries( current.subs ).filter(
				( [ key, entry ] ) =>
					! entry.hidden &&
					// Qdrant and Hyve Connect are mutually exclusive.
					! ( 'qdrant' === key && isConnectActive )
		  )
		: [];

	const screenContent = Screen ? (
		<Screen sub={ sub } item={ item } />
	) : (
		<div className="hyve-next__card">
			<p>
				{ __(
					'This screen is on its way. Use the tabs to move around; the URL updates so every view is linkable and browser back/forward works.',
					'hyve-lite'
				) }
			</p>
		</div>
	);

	return (
		<div className="hyve-next">
			<HeaderBar />

			<TabNav />

			<div className="hyve-next__wrap">
				{ /* Campaign banner slot, hidden for licensed pro users. */ }
				<div
					id="tsdk_banner"
					style={
						'valid' === window.hyve?.hasPro
							? { display: 'none' }
							: undefined
					}
				></div>

				<ServiceErrors />

				{ current && (
					<div className="hyve-next-pagehead">
						<h1>{ current.label }</h1>
						{ current.description && (
							<p>{ current.description }</p>
						) }
					</div>
				) }

				{ current?.sidebar ? (
					<div className="hyve-next-settings">
						<SideNav
							screen={ screen }
							subs={ subs }
							active={ sub }
						/>
						<div className="hyve-next-settings__content">
							{ screenContent }
						</div>
					</div>
				) : (
					<>
						{ 1 < subs.length && (
							<div className="hyve-next__subnav">
								{ subs.map( ( [ key, entry ] ) => (
									<button
										key={ key }
										type="button"
										className={ `hyve-next__sublink${
											key === sub ? ' is-active' : ''
										}` }
										onClick={ () =>
											navigate( screen, key )
										}
									>
										{ entry.label }
										{ entry.badge && 0 < attentionCount && (
											<span className="hyve-next__subbadge">
												{ attentionCount }
											</span>
										) }
									</button>
								) ) }
							</div>
						) }

						{ screenContent }
					</>
				) }
			</div>

			<Notices />
		</div>
	);
};

export default App;
