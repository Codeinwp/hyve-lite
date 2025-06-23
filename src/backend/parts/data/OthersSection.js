/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import { Panel, PanelRow, ToggleControl } from '@wordpress/components';

import { useState, useEffect } from '@wordpress/element';

export const OthersSection = ( { settings, setSetting, isSaving, onSave } ) => {
	const [ isUpdated, setUpdated ] = useState( false );

	useEffect( () => {
		if ( ! isUpdated ) {
			return;
		}

		onSave();
		setUpdated( false );
	}, [ isUpdated, settings, onSave ] );

	return (
		<div className="col-span-6 xl:col-span-4">
			<Panel header={ __( 'Others', 'hyve-lite' ) }>
				<PanelRow>
					<ToggleControl
						label={ __( 'Telemetry', 'hyve-lite' ) }
						help={ __(
							'Enable telemetry to help us improve the plugin by sending anonymous usage data. Data is private and not shared third-party entities.',
							'hyve-lite'
						) }
						checked={ Boolean( settings.telemetry_enabled ) }
						onChange={ ( checked ) => {
							setSetting( 'telemetry_enabled', checked );
							setTimeout( () => {
								setUpdated( true );
							}, 500 );
						} }
						disabled={ isSaving || isUpdated }
					/>
				</PanelRow>
			</Panel>
		</div>
	);
};
