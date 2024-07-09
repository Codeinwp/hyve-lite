/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import {
	BaseControl,
	Button,
	ColorPalette,
	Panel,
	PanelRow
} from '@wordpress/components';

import { useState } from '@wordpress/element';

import {
	useDispatch,
	useSelect
} from '@wordpress/data';

const colorOptions = [
	{
		label: __( 'Chat Background', 'hyve' ),
		value: 'chat_background'
	},
	{
		label: __( 'Assistant Background', 'hyve' ),
		value: 'assistant_background'
	},
	{
		label: __( 'User Background', 'hyve' ),
		value: 'user_background'
	},
	{
		label: __( 'Icon Background', 'hyve' ),
		value: 'icon_background'
	}
];

const Appearance = () => {
	const settings = useSelect( ( select ) => select( 'hyve' ).getSettings() );
	const { setSetting } = useDispatch( 'hyve' );

	const { createNotice } = useDispatch( 'core/notices' );

	const [ isSaving, setIsSaving ] = useState( false );

	const updateColor = ( key, value ) => {
		const colors = { ...settings.colors, [ key ]: value };
		setSetting( 'colors', colors );
	};

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
				__( 'Settings saved.', 'hyve' ),
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
				header={ __( 'Appearance Settings', 'hyve' ) }
			>
				<PanelRow>
					{ colorOptions.map( ( option ) => (
						<BaseControl
							key={ option.value }
							label={ option.label }
						>
							<ColorPalette
								colors={ [] }
								value={ settings.colors?.[ option.value ] }
								onChange={ color => updateColor( option.value, color ) }
							/>
						</BaseControl>
					) ) }
				</PanelRow>

				<PanelRow>
					<Button
						variant="primary"
						isBusy={ isSaving }
						disabled={ isSaving }
						className="mt-2"
						onClick={ onSave }
					>
						{ __( 'Save', 'hyve' ) }
					</Button>
				</PanelRow>
			</Panel>
		</div>
	);
};

export default Appearance;
