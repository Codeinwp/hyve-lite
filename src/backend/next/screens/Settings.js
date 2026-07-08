/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies.
 */
import ChatBehavior from './ChatBehavior';
import ChatAppearance from './ChatAppearance';
import { ProviderPanel, AdvancedPanel } from './AI';

const PANELS = {
	'chat-behavior': ChatBehavior,
	'chat-appearance': ChatAppearance,
	'ai-provider': ProviderPanel,
	'ai-advanced': AdvancedPanel,
};

const Settings = ( { sub } ) => {
	const hasLoaded = useSelect( ( select ) => select( 'hyve' ).hasLoaded() );

	if ( ! hasLoaded ) {
		return null;
	}

	const Panel = PANELS[ sub ];

	if ( Panel ) {
		return <Panel />;
	}

	return (
		<div className="hyve-next__card">
			<p>
				{ __(
					'This panel is on its way. Use the sidebar to move around; the URL updates so every view is linkable.',
					'hyve-lite'
				) }
			</p>
		</div>
	);
};

export default Settings;
