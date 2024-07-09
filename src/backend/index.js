/**
 * WordPress dependencies.
 */
import domReady from '@wordpress/dom-ready';

import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import './style.scss';
import './store';
import App from './App';

domReady( () => {
	const root = createRoot( document.getElementById( 'hyve-options' ) );
	root.render( <App /> );
});
