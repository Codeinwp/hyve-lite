/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';

import domReady from '@wordpress/dom-ready';

import { dispatch } from '@wordpress/data';

import { createRoot } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies.
 */
import './store';
import App from './App';
import { getChatIcons } from './utils';

// Keep the dashboard service-error notice in sync with every settings save,
// regardless of which component (lite or pro) triggers it. The settings
// endpoint returns the freshly-computed service errors on each save.
apiFetch.use( async ( options, next ) => {
	const response = await next( options );

	if (
		'POST' === options.method &&
		'string' === typeof options.path &&
		options.path.includes( '/settings' ) &&
		response &&
		Array.isArray( response.serviceErrors )
	) {
		dispatch( 'hyve' ).setServiceErrors( response.serviceErrors );
	}

	return response;
} );

domReady( () => {
	addFilter( 'hyve.appearance.chat-icons', 'hyve/data', getChatIcons );
	setUpTracking();

	const root = createRoot( document.getElementById( 'hyve-options' ) );
	root.render( <App /> );
} );

function setUpTracking() {
	if ( window?.tiTrk ) {
		window.tiTrk.autoSendIntervalTime = 30 * 1000; // 30 seconds.
		window.hyveTrk = window.tiTrk?.with( 'hyve' );
		window.tiTrk?.start();
	}
}
