/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import { Button, Panel, PanelRow, SelectControl } from '@wordpress/components';

import { useState } from '@wordpress/element';

import { useDispatch, useSelect } from '@wordpress/data';

/**
 * Selectable chat models.
 *
 * Every listed model must support structured outputs (`json_schema`), which
 * Hyve requires for chat responses. GPT-3.5 lacks them, so it is excluded.
 */
const MODEL_OPTIONS = [
	{
		label: __( 'GPT-5.6 Sol', 'hyve-lite' ),
		value: 'gpt-5.6-sol',
		description: __(
			'Flagship frontier model, the highest quality at the highest cost.',
			'hyve-lite'
		),
	},
	{
		label: __( 'GPT-5.6 Terra', 'hyve-lite' ),
		value: 'gpt-5.6-terra',
		description: __(
			'Newest mini-tier model balancing intelligence and cost.',
			'hyve-lite'
		),
	},
	{
		label: __( 'GPT-5.6 Luna', 'hyve-lite' ),
		value: 'gpt-5.6-luna',
		description: __(
			'Newest cost-optimized model, built for high-volume chats.',
			'hyve-lite'
		),
	},
	{
		label: __( 'GPT-5.5', 'hyve-lite' ),
		value: 'gpt-5.5',
		description: __(
			'Most capable reasoning model. Thinks before answering, so replies are slower and cost more.',
			'hyve-lite'
		),
	},
	{
		label: __( 'GPT-5.4', 'hyve-lite' ),
		value: 'gpt-5.4',
		description: __(
			'Most capable standard model, best for complex questions.',
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
		label: __( 'GPT-5', 'hyve-lite' ),
		value: 'gpt-5',
		description: __(
			'Reasoning model with strong quality at a lower cost than GPT-5.5.',
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
	const selectedModel = isLegacyModel ? 'gpt-5.4-nano' : savedModel;

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
