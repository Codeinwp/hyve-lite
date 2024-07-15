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
import PostsTable from './parts/PostsTable';
import PostModal from './parts/PostModal';

window.hyveComponents = {};

window.hyveComponents.PostsTable = PostsTable;
window.hyveComponents.PostModal = PostModal;

domReady( () => {
	const root = createRoot( document.getElementById( 'hyve-options' ) );
	root.render( <App /> );
});
