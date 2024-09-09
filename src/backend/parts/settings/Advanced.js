/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import {
	BaseControl,
	Button,
	ExternalLink,
	Panel,
	PanelRow,
	TextControl
} from '@wordpress/components';

import { useState } from '@wordpress/element';

import {
	useDispatch,
	useSelect
} from '@wordpress/data';

const Advanced = () => {
	const settings = useSelect( ( select ) => select( 'hyve' ).getSettings() );
	const {
		setHasAPI,
		setSetting
	} = useDispatch( 'hyve' );

	const { createNotice } = useDispatch( 'core/notices' );

	const [ isSaving, setIsSaving ] = useState( false );

	const onSave = async() => {
		setIsSaving( true );

		try {
			const response = await apiFetch({
				path: `${ window.hyve.api }/settings`,
				method: 'POST',
				data: {
					data: settings
				}
			});

			if ( response.error ) {
				throw new Error( response.error );
			}

			if ( settings.api_key ) {
				setHasAPI( true );
			} else {
				setHasAPI( false );
			}

			createNotice(
				'success',
				__( 'Settings saved.', 'hyve-lite' ),
				{
					type: 'snackbar',
					isDismissible: true
				});
		} catch ( error ) {
			createNotice(
				'error',
				error,
				{
					type: 'snackbar',
					isDismissible: true
				});
		}

		setIsSaving( false );
	};

	return (
		<div className="col-span-6 xl:col-span-4">
			<Panel
				header={ __( 'Advanced', 'hyve-lite' ) }
			>
				<PanelRow>
					<BaseControl
						help={ __( 'This plugin requires an OpenAI API key to function properly.', 'hyve-lite' ) }
					>
						<TextControl
							label={ __( 'API Key', 'hyve-lite' ) }
							type="password"
							value={ settings.api_key || '' }
							disabled={ isSaving }
							onChange={ ( newValue ) => setSetting( 'api_key', newValue ) }
						/>
					</BaseControl>

					<ExternalLink href="https://platform.openai.com/api-keys" className="flex mb-2 items-centertext-sm text-blue-600">
						{ __( 'Get an API key', 'hyve-lite' ) }
					</ExternalLink>

					<Button
						variant="primary"
						isBusy={ isSaving }
						disabled={ isSaving }
						className="mt-2"
						onClick={ onSave }
					>
						{ __( 'Save', 'hyve-lite' ) }
					</Button>
				</PanelRow>
			</Panel>
		</div>
	);
};

export default Advanced;
