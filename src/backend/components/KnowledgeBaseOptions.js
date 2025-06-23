/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import { Button, Panel, PanelRow, RangeControl } from '@wordpress/components';

import { useState } from '@wordpress/element';

import { useDispatch, useSelect } from '@wordpress/data';

export const KnowledgeBaseOptions = () => {
	const settings = useSelect( ( select ) => select( 'hyve' ).getSettings() );
	const { setSetting } = useDispatch( 'hyve' );

	const { createNotice } = useDispatch( 'core/notices' );

	const [ isSaving, setIsSaving ] = useState( false );

	const onSave = async () => {
		setIsSaving( true );

		try {
			const response = await apiFetch( {
				path: `${ window.hyve.api }/settings`,
				method: 'POST',
				data: {
					data: settings,
				},
			} );

			if ( response.error ) {
				throw new Error( response.error );
			}

			createNotice( 'success', __( 'Settings saved.', 'hyve-lite' ), {
				type: 'snackbar',
				isDismissible: true,
			} );
		} catch ( error ) {
			createNotice( 'error', error?.message, {
				type: 'snackbar',
				isDismissible: true,
			} );
		}

		setIsSaving( false );
	};

	return (
		<Panel header={ __( 'Options', 'hyve-lite' ) }>
			<PanelRow>
				<RangeControl
					label={ __( 'Cosine Similarity Threshold', 'hyve-lite' ) }
					help={ __(
						'Determines how similar a user query must be to knowledge base content. A higher value means stricter, more relevant matches, while a lower value allows for broader, potentially less precise results. This threshold is applied to the cosine similarity score between the query embedding and the knowledge base embeddings.',
						'hyve-lite'
					) }
					initialPosition={
						settings?.similarity_score_threshold ?? 0.4
					}
					max={ 1 }
					min={ -1 }
					step={ 0.01 }
					value={ settings?.similarity_score_threshold }
					disabled={ isSaving }
					allowReset
					resetFallbackValue={ 0.4 }
					className="py-4"
					onChange={ ( newValue ) => {
						setSetting( 'similarity_score_threshold', newValue );
					} }
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
					{ __( 'Save', 'hyve-lite' ) }
				</Button>
			</PanelRow>
		</Panel>
	);
};
