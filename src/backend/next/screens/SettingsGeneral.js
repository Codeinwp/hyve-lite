/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import { ToggleControl } from '@wordpress/components';

import { useDispatch } from '@wordpress/data';

import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import Card from '../components/Card';
import FieldRow from '../components/FieldRow';
import useSaveSettings from '../data/useSaveSettings';

const SettingsGeneral = () => {
	const { settings, isSaving, save } = useSaveSettings();

	const { setSetting } = useDispatch( 'hyve' );

	// Toggles auto-save; the effect runs after the store update so save() posts
	// the fresh value.
	const [ isDirty, setDirty ] = useState( false );

	useEffect( () => {
		if ( ! isDirty ) {
			return;
		}

		setDirty( false );
		save();
	}, [ isDirty, save ] );

	const toggle = ( key, value ) => {
		setSetting( key, Boolean( value ) );
		setDirty( true );
	};

	const rowAction = Boolean( settings.post_row_addon_enabled );
	const telemetry = Boolean( settings.telemetry_enabled );

	return (
		<Card title={ __( 'Site integration', 'hyve-lite' ) }>
			<FieldRow
				label={ __( '“Add to Hyve” row action', 'hyve-lite' ) }
				description={ __(
					'When enabled, an “Add to Hyve” button will appear in the post/page row actions. Disable this to remove the option entirely from the posts/pages list.',
					'hyve-lite'
				) }
			>
				<ToggleControl
					__nextHasNoMarginBottom
					label={
						rowAction
							? __( 'Enabled', 'hyve-lite' )
							: __( 'Disabled', 'hyve-lite' )
					}
					checked={ rowAction }
					disabled={ isSaving }
					onChange={ ( value ) =>
						toggle( 'post_row_addon_enabled', value )
					}
				/>
			</FieldRow>

			<FieldRow
				label={ __( 'Telemetry', 'hyve-lite' ) }
				description={ __(
					'Enable telemetry to help us improve the plugin by sending anonymous usage data. Data is private and not shared third-party entities.',
					'hyve-lite'
				) }
			>
				<ToggleControl
					__nextHasNoMarginBottom
					label={
						telemetry
							? __( 'Enabled', 'hyve-lite' )
							: __( 'Disabled', 'hyve-lite' )
					}
					checked={ telemetry }
					disabled={ isSaving }
					onChange={ ( value ) =>
						toggle( 'telemetry_enabled', value )
					}
				/>
			</FieldRow>
		</Card>
	);
};

export default SettingsGeneral;
