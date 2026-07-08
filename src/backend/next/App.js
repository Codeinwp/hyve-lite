/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';

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
import AI from './screens/AI';

const SCREENS = {
	dashboard: Dashboard,
	ai: AI,
};

const App = () => {
	const { screen, sub } = useRoute();

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
				{ 0 < subs.length && (
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
					<Screen sub={ sub } />
				) : (
					<div className="hyve-next__card">
						<h1>
							{ sub && current?.subs?.[ sub ]
								? sprintf(
										/* translators: 1: screen name, 2: sub-panel name. */
										__( '%1$s: %2$s', 'hyve-lite' ),
										current.label,
										current.subs[ sub ].label
								  )
								: current?.label }
						</h1>
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
