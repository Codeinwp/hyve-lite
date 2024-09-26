/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import {
	Button,
	Panel,
	PanelRow,
	RangeControl,
	SelectControl
} from '@wordpress/components';

import {
	useState
} from '@wordpress/element';

import {
	useDispatch,
	useSelect
} from '@wordpress/data';

const Assistant = () => {
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
				header={ __( 'Assistant Settings', 'hyve-lite' ) }
			>
				<PanelRow>
					<SelectControl
						label={ __( 'Model', 'hyve-lite' ) }
						help={ __( 'What model to use for Chat.', 'hyve-lite' ) }
						options={ [
							{
								label: __( 'GPT 4o Mini', 'hyve-lite' ),
								value: 'gpt-4o-mini'
							},
							{
								label: __( 'GPT 3.5 Turbo 0125', 'hyve-lite' ),
								value: 'gpt-3.5-turbo-0125'
							}
						] }
						value={ settings.model }
						disabled={ isSaving }
						onChange={ ( newValue ) => setSetting( 'chat_model', newValue ) }
					/>
				</PanelRow>

				<PanelRow>
					<RangeControl
						label={ __( 'Temperature', 'hyve-lite' ) }
						help={ __( 'What sampling temperature to use, between 0 and 2. Higher values like 0.8 will make the output more random, while lower values like 0.2 will make it more focused and deterministic. We generally recommend altering this or temperature but not both.', 'hyve-lite' ) }
						initialPosition={ settings.temperature || 1 }
						max={ 2 }
						min={ 0.1 }
						step={ 0.1 }
						value={ settings.temperature || 1 }
						disabled={ isSaving }
						onChange={ ( newValue ) => setSetting( 'temperature', newValue ) }
					/>
				</PanelRow>

				<PanelRow>
					<RangeControl
						label={ __( 'Top P', 'hyve-lite' ) }
						help={ __( 'What sampling temperature to use, between 0 and 2. Higher values like 0.8 will make the output more random, while lower values like 0.2 will make it more focused and deterministic.', 'hyve-lite' ) }
						initialPosition={ settings.top_p || 1 }
						max={ 1 }
						min={ 0.1 }
						step={ 0.1 }
						value={ settings.top_p || 1 }
						disabled={ isSaving }
						onChange={ ( newValue ) => setSetting( 'top_p', newValue ) }
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
		</div>
	);
};

export default Assistant;
