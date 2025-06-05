/**
 * WordPress dependencies.
 */
import domReady from '@wordpress/dom-ready';

/**
 * Internal dependencies.
 */
import './style.scss';
import App from './App';

domReady( () => {
	new App();
} );
