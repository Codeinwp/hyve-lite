/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import { useDispatch, useSelect } from '@wordpress/data';

import { useState } from '@wordpress/element';

/**
 * Save the current settings from the `hyve` store.
 *
 * @return {{settings: Object, isSaving: boolean, save: Function}} `save`
 * resolves with the endpoint response, or null when the request failed.
 */
const useSaveSettings = () => {
	const [ isSaving, setIsSaving ] = useState( false );

	const settings = useSelect( ( select ) => select( 'hyve' ).getSettings() );

	const { createNotice } = useDispatch( 'core/notices' );

	const save = async () => {
		setIsSaving( true );

		try {
			const response = await apiFetch( {
				path: `${ window.hyve.api }/settings`,
				method: 'POST',
				data: { data: settings },
			} );

			if ( response.error ) {
				throw new Error( response.error );
			}

			createNotice( 'success', __( 'Settings saved.', 'hyve-lite' ), {
				type: 'snackbar',
				isDismissible: true,
			} );

			return response;
		} catch ( error ) {
			createNotice( 'error', error.message, {
				type: 'snackbar',
				isDismissible: true,
			} );

			return null;
		} finally {
			setIsSaving( false );
		}
	};

	return { settings, isSaving, save };
};

export default useSaveSettings;
