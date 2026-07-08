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
import Notices from './components/Notices';
import Dashboard from './screens/Dashboard';
import Messages from './screens/Messages';
import Chat from './screens/Chat';
import AI from './screens/AI';

const SCREENS = {
	dashboard: Dashboard,
	messages: Messages,
	chat: Chat,
	ai: AI,
};

const App = () => {
	const { screen, sub, item } = useRoute();

	const hasAPI = useSelect( ( select ) => select( 'hyve' ).hasAPI() );

	const { setSettings, setLoading } = useDispatch( 'hyve' );

	useEffect( () => {
		const fetchSettings = async () => {
			const response = await apiFetch( {
				path: `${ window.hyve.api }/settings`,
			} );

			setSettings( response );
			setLoading();
		};

		fetchSettings();
	}, [ setSettings, setLoading ] );

	const routes = getRoutes();
	const current = routes[ screen ];
	const Screen = SCREENS[ screen ];

	useEffect( () => {
		if ( ! hasAPI && current?.requiresAPI ) {
			navigate( 'dashboard', null, { replace: true } );
		}
	}, [ hasAPI, current ] );

	const subs = current?.subs
		? Object.entries( current.subs ).filter(
				( [ , entry ] ) => ! entry.hidden
		  )
		: [];

	return (
		<div className="hyve-next">
			<HeaderBar />

			<TabNav />

			<div className="hyve-next__wrap">
				{ current && (
					<div className="hyve-next-pagehead">
						<h1>{ current.label }</h1>
						{ current.description && (
							<p>{ current.description }</p>
						) }
					</div>
				) }

				{ 1 < subs.length && (
					<div className="hyve-next__subnav">
						{ subs.map( ( [ key, entry ] ) => (
							<button
								key={ key }
								type="button"
								className={ `hyve-next__sublink${
									key === sub ? ' is-active' : ''
								}` }
								onClick={ () => navigate( screen, key ) }
							>
								{ entry.label }
							</button>
						) ) }
					</div>
				) }

				{ Screen ? (
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
				) }
			</div>

			<Notices />
		</div>
	);
};

export default App;
