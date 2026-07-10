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
	SelectControl,
} from '@wordpress/components';

import { useState } from '@wordpress/element';

import { useDispatch, useSelect } from '@wordpress/data';

/**
 * Selectable chat models.
 *
 * Only models verified to support every feature Hyve relies on — structured
 * outputs (`json_schema`) plus the `temperature`/`top_p` controls — are listed.
 * Reasoning-only models (GPT-5, GPT-5.5, the `o`-series) reject those params,
 * and GPT-3.5 lacks structured outputs, so they are intentionally excluded.
 */
const MODEL_OPTIONS = [
	{
		label: __( 'GPT-5.4', 'hyve-lite' ),
		value: 'gpt-5.4',
		description: __(
			'Newest and most capable — best for complex questions.',
			'hyve-lite'
		),
	},
	{
		label: __( 'GPT-5.4 mini', 'hyve-lite' ),
		value: 'gpt-5.4-mini',
		description: __(
			'Newer model with a strong balance of quality and cost.',
			'hyve-lite'
		),
	},
	{
		label: __( 'GPT-5.4 nano', 'hyve-lite' ),
		value: 'gpt-5.4-nano',
		description: __(
			'Newer fast, low-cost option for high-traffic chats.',
			'hyve-lite'
		),
	},
	{
		label: __( 'GPT-4.1', 'hyve-lite' ),
		value: 'gpt-4.1',
		description: __(
			'Capable and proven — great for detailed answers.',
			'hyve-lite'
		),
	},
	{
		label: __( 'GPT-4.1 mini', 'hyve-lite' ),
		value: 'gpt-4.1-mini',
		description: __(
			'Faster and cheaper than GPT-4.1 — solid all-rounder.',
			'hyve-lite'
		),
	},
	{
		label: __( 'GPT-4.1 nano', 'hyve-lite' ),
		value: 'gpt-4.1-nano',
		description: __(
			'Ultra-fast and very low cost — best for lightweight chats.',
			'hyve-lite'
		),
	},
	{
		label: __( 'GPT-4o', 'hyve-lite' ),
		value: 'gpt-4o',
		description: __(
			'Smart, cost-effective general-purpose model.',
			'hyve-lite'
		),
	},
	{
		label: __( 'GPT-4o mini', 'hyve-lite' ),
		value: 'gpt-4o-mini',
		description: __(
			'Fastest and most affordable — best for most chats.',
			'hyve-lite'
		),
	},
];

const Assistant = () => {
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
			createNotice( 'error', error, {
				type: 'snackbar',
				isDismissible: true,
			} );
		}

		setIsSaving( false );
	};

	// GPT-3.5 is no longer offered (it errors with structured outputs); show the
	// default instead. A still-valid but no-longer-listed saved model (e.g. an
	// older GPT-4 a site picked earlier) is appended so it isn't silently lost.
	const savedModel = settings.chat_model;
	const isLegacyModel =
		'string' === typeof savedModel && savedModel.startsWith( 'gpt-3.5' );
	const selectedModel = isLegacyModel ? 'gpt-4o-mini' : savedModel;

	const modelOptions =
		selectedModel &&
		! MODEL_OPTIONS.some( ( option ) => option.value === selectedModel )
			? [
					...MODEL_OPTIONS,
					{
						label: selectedModel,
						value: selectedModel,
						description: __(
							'Your currently selected model.',
							'hyve-lite'
						),
					},
			  ]
			: MODEL_OPTIONS;

	const selectedModelOption = modelOptions.find(
		( option ) => option.value === selectedModel
	);

	return (
		<div className="col-span-6 xl:col-span-4">
			<Panel header={ __( 'Assistant Settings', 'hyve-lite' ) }>
				<PanelRow>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Model', 'hyve-lite' ) }
						help={
							selectedModelOption?.description ||
							__(
								'Choose the AI model that powers your chatbot. More advanced models provide better responses but may cost more.',
								'hyve-lite'
							)
						}
						options={ modelOptions.map( ( { label, value } ) => ( {
							label,
							value,
						} ) ) }
						value={ selectedModel }
						disabled={ isSaving }
						onChange={ ( newValue ) =>
							setSetting( 'chat_model', newValue )
						}
					/>
				</PanelRow>

				<PanelRow>
					<RangeControl
						label={ __( 'Temperature', 'hyve-lite' ) }
						help={ __(
							'What sampling temperature to use, between 0 and 2. Higher values like 0.8 will make the output more random, while lower values like 0.2 will make it more focused and deterministic. We generally recommend altering this or top_p but not both.',
							'hyve-lite'
						) }
						initialPosition={ settings.temperature || 1 }
						max={ 2 }
						min={ 0.1 }
						step={ 0.1 }
						value={ settings.temperature || 1 }
						disabled={ isSaving }
						onChange={ ( newValue ) =>
							setSetting( 'temperature', newValue )
						}
					/>
				</PanelRow>

				<PanelRow>
					<RangeControl
						label={ __( 'Top P', 'hyve-lite' ) }
						help={ __(
							'An alternative to sampling with temperature, called nucleus sampling, where the model considers the results of the tokens with top_p probability mass. We generally recommend altering this or temperature but not both.',
							'hyve-lite'
						) }
						initialPosition={ settings.top_p || 1 }
						max={ 1 }
						min={ 0.1 }
						step={ 0.1 }
						value={ settings.top_p || 1 }
						disabled={ isSaving }
						onChange={ ( newValue ) =>
							setSetting( 'top_p', newValue )
						}
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
