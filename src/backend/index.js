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
import { PostsTable } from './parts/PostsTable';
import PostModal from './parts/PostModal';
import { addFilter } from '@wordpress/hooks';
import { getChatIcons } from './utils';
import { setUpTracking } from './tracking';

window.hyveComponents = {};

window.hyveComponents.PostsTable = PostsTable;
window.hyveComponents.PostModal = PostModal;

domReady( () => {
	addFilter( 'hyve.appearance.chat-icons', 'hyve/data', getChatIcons );
	if ( window?.tiTrk ) {
		window.hyveTrk = window.tiTrk?.with( 'hyve' );
	}
	setUpTracking();

	const root = createRoot( document.getElementById( 'hyve-options' ) );
	root.render( <App /> );
} );
