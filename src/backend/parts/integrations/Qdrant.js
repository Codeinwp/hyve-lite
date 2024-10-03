/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import {
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

const Qdrant = () => {
	const settings = useSelect( ( select ) => select( 'hyve' ).getSettings() );
	const { setSetting } = useDispatch( 'hyve' );

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
				header={ __( 'Qdrant Integration', 'hyve-lite' ) }
			>
				<PanelRow>
					<p className="py-4">{ __( 'Use Qdrant to increase the Knowledge Base limit of Hyve. To integrate Qdrant with your application, you\'ll need an API key and endpoint.', 'hyve-lite' ) }</p>

					<ExternalLink
						href={ window.hyve?.qdrant_docs }
						className="text-blue-600"
					>
						{ __( 'Learn more about Qdrant', 'hyve-lite' ) }
					</ExternalLink>
				</PanelRow>

				<PanelRow>
					<TextControl
						label={ __( 'API Key', 'hyve-lite' ) }
						type="password"
						value={ settings.qdrant_api_key || '' }
						disabled={ isSaving }
						onChange={ ( newValue ) => setSetting( 'qdrant_api_key', newValue ) }
					/>
				</PanelRow>

				<PanelRow>
					<TextControl
						label={ __( 'API Endpoint', 'hyve-lite' ) }
						value={ settings.qdrant_endpoint || '' }
						disabled={ isSaving }
						onChange={ ( newValue ) => setSetting( 'qdrant_endpoint', newValue ) }
					/>
				</PanelRow>

				<PanelRow>
					<Button
						variant="primary"
						isBusy={ isSaving }
						disabled={ isSaving }
						className="mt-2"
						onClick={ onSave }
					>
						{ __( 'Connect', 'hyve-lite' ) }
					</Button>
				</PanelRow>
			</Panel>
		</div>
	);
};

export default Qdrant;
