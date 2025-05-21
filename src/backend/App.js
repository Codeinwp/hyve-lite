/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import { Spinner } from '@wordpress/components';

import { useSelect, useDispatch } from '@wordpress/data';

import { useEffect } from '@wordpress/element';

import { applyFilters } from '@wordpress/hooks';

/**
 * Internal dependencies.
 */
import { ROUTE_TREE as ROUTE } from './route';
import Sidebar from './parts/Sidebar';
import Notices from './parts/Notices';

const App = () => {
	const hasLoaded = useSelect( ( select ) => select( 'hyve' ).hasLoaded() );
	const route = useSelect( ( select ) => select( 'hyve' ).getRoute() );

	const { setSettings, setLoading, setRoute } = useDispatch( 'hyve' );

	useEffect( () => {
		const fetchData = async() => {
			const response = await apiFetch({
				path: `${ window.hyve.api }/settings`
			});

			setSettings( response );
			setLoading();

			const sdkEvent = new Event( 'themeisle:banner:init' );
			document.dispatchEvent( sdkEvent );
		};

		fetchData();

		const urlParams = new URLSearchParams( window.location.search );
		const nav = urlParams.get( 'nav' );

		if ( nav ) {
			setRoute( nav );
		}

		if ( window.tsdk_reposition_notice ) {
			window.tsdk_reposition_notice();
		}
	}, []);

	const ROUTE_TREE = applyFilters( 'hyve.route', ROUTE );

	const ROUTE_COMPONENTS = Object.keys( ROUTE_TREE ).reduce( ( acc, key ) => {
		if ( ROUTE_TREE[ key ].component ) {
			acc[ key ] = ROUTE_TREE[ key ].component;
		}

		if ( ROUTE_TREE[ key ].children ) {
			Object.keys( ROUTE_TREE[ key ].children ).forEach( ( childKey ) => {
				acc[ childKey ] =
					ROUTE_TREE[ key ].children[ childKey ].component;
			});
		}

		return acc;
	}, {});

	const Page = ROUTE_COMPONENTS[ route ] || null;

	return (
		<>
			{ ! hasLoaded && (
				<div className="flex items-center justify-center absolute w-full h-screen z-10 bg-white">
					<Spinner />
				</div>
			) }

			<div className="mx-auto max-w-screen-2xl p-4 md:p-6 2xl:p-10">
				<div id="tsdk_banner"></div>
				<div className="mx-auto max-w-270">
					<div className="grid grid-cols-6 gap-8">
						<Sidebar />

						{ Page && <Page /> }
					</div>
				</div>
			</div>

			<Notices />
		</>
	);
};

export default App;
