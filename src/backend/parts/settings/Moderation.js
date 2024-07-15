/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import {
	Button,
	Panel,
	PanelRow,
	RangeControl
} from '@wordpress/components';

import { useState } from '@wordpress/element';

import {
	useDispatch,
	useSelect
} from '@wordpress/data';

/**
 * Internal dependencies.
 */
import { moderationLabels } from '../../utils';

const Moderation = () => {
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

	const onChangeModeration = ( key, value ) => {
		setSetting( 'moderation_threshold', {
			...settings.moderation_threshold,
			[key]: Number( value ) * 100
		});
	};

	return (
		<div className="col-span-6 xl:col-span-4">
			<Panel
				header={ __( 'Moderation Settings', 'hyve' ) }
			>
				<PanelRow>
					<p className="py-4">{ __( 'With the moderation functionality, you can check whether your content is potentially harmful. Users can use it to identify content that might be harmful and take action.', 'hyve' ) }</p>

					<p className="pt-2 pb-4">{ __( 'Occasionally, OpenAI\'s Moderation system may incorrectly flag content as a violation—these are false positives. Such errors can occur because automated systems sometimes lack the necessary context to interpret nuances accurately. If your content is flagged but you believe it adheres to the guidelines, please manually review it. Should you determine it does not violate the content policies, you can also override the moderation decisions.', 'hyve' ) }</p>

					{ Object.keys( moderationLabels ).map( moderation => (
						<RangeControl
							key={ moderation }
							label={ moderationLabels[ moderation ].label }
							help={ moderationLabels[ moderation ].description }
							initialPosition={ ( settings?.moderation_threshold?.[moderation] / 100 ) || 0.5 }
							max={ 1 }
							min={ 0 }
							step={ 0.1 }
							value={ settings?.moderation_threshold?.[moderation] / 100 }
							disabled={ isSaving }
							allowReset
							resetFallbackValue={ moderationLabels[ moderation ].default }
							className="py-4"
							onChange={ ( newValue ) => onChangeModeration( moderation, newValue ) }
						/>
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

export default Moderation;
