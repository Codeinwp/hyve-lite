/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies.
 */
import { getRoutes } from '../router';
import ChatBehavior from './ChatBehavior';
import ChatAppearance from './ChatAppearance';
import { ProviderPanel } from './AI';
import SettingsGeneral from './SettingsGeneral';
import { QdrantPanel, ApiAccessPanel } from './Integrations';
import ConnectPanel from './Connect';

const PANELS = {
	'chat-behavior': ChatBehavior,
	'chat-appearance': ChatAppearance,
	'ai-provider': ProviderPanel,
	'hyve-connect': ConnectPanel,
	qdrant: QdrantPanel,
	'api-access': ApiAccessPanel,
	general: SettingsGeneral,
};

const Settings = ( { sub } ) => {
	const hasLoaded = useSelect( ( select ) => select( 'hyve' ).hasLoaded() );

	if ( ! hasLoaded ) {
		return null;
	}

	// Pro swaps whole panels by attaching `component` to a route sub entry.
	const Panel =
		getRoutes().settings?.subs?.[ sub ]?.component ?? PANELS[ sub ];

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
