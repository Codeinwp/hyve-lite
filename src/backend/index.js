/**
 * WordPress dependencies.
 */
import domReady from '@wordpress/dom-ready';

import { createRoot } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';

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
import { OthersSection } from './parts/data/OthersSection';

window.hyveComponents = {};

window.hyveComponents.PostsTable = PostsTable;
window.hyveComponents.PostModal = PostModal;

domReady( () => {
	addFilter( 'hyve.appearance.chat-icons', 'hyve/data', getChatIcons );
	if ( window?.tiTrk ) {
		window.hyveTrk = window.tiTrk?.with( 'hyve' );
	}
	setUpTracking();

	addFilter(
		'hyve.others',
		'hyve/others',
		( el, isSaving, settings, setSetting, onSave ) => (
			<OthersSection
				isSaving={ isSaving }
				settings={ settings }
				setSetting={ setSetting }
				onSave={ onSave }
			/>
		)
	);

	const root = createRoot( document.getElementById( 'hyve-options' ) );
	root.render( <App /> );
} );
