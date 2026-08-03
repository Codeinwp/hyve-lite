/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import {
	Button,
	ExternalLink,
	RangeControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';

import { useDispatch, useSelect } from '@wordpress/data';

import { useState } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import Card from '../components/Card';
import Chip from '../components/Chip';
import FieldRow from '../components/FieldRow';
import useSaveSettings from '../data/useSaveSettings';
import { navigate } from '../router';

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
			'Fast and low cost, the recommended default for most chats.',
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
			'Capable and proven, great for detailed answers.',
			'hyve-lite'
		),
	},
	{
		label: __( 'GPT-4.1 mini', 'hyve-lite' ),
		value: 'gpt-4.1-mini',
		description: __(
			'Faster and cheaper than GPT-4.1, a solid all-rounder.',
			'hyve-lite'
		),
	},
	{
		label: __( 'GPT-4.1 nano', 'hyve-lite' ),
		value: 'gpt-4.1-nano',
		description: __(
			'Ultra-fast and very low cost, best for lightweight chats.',
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
			'Fastest and most affordable, best for most chats.',
			'hyve-lite'
		),
	},
];

const ADVANCED_DEFAULTS = {
	similarity_score_threshold: 0.4,
};

const getInitialApiStatus = () => {
	if ( window.hyve?.isApiKeyConnected ) {
		return 'connected';
	}

	return window.hyve?.hasAPIKey ? 'error' : 'none';
};

const ApiStatusChip = ( { status } ) => {
	if ( 'connected' === status ) {
		return <Chip tone="ok">{ __( 'Connected', 'hyve-lite' ) }</Chip>;
	}

	if ( 'error' === status ) {
		return <Chip tone="warn">{ __( 'Not connected', 'hyve-lite' ) }</Chip>;
	}

	if ( 'editing' === status ) {
		return <Chip tone="muted">{ __( 'Unsaved', 'hyve-lite' ) }</Chip>;
	}

	return null;
};

export const ProviderPanel = () => {
	const [ apiStatus, setApiStatus ] = useState( getInitialApiStatus );

	const { settings, isSaving, save } = useSaveSettings();

	const { setSetting, setHasAPI } = useDispatch( 'hyve' );
	const { createNotice } = useDispatch( 'core/notices' );

	const isConnectActive = useSelect( ( select ) =>
		select( 'hyve' ).isConnectActive()
	);

	const hasStoredKey = Boolean( window.hyve?.hasAPIKey );
	const providerLocked = isConnectActive;
	const apiKeyLocked = isConnectActive && ! hasStoredKey;

	const onSave = async () => {
		const response = await save();

		if ( ! response ) {
			return;
		}

		const hasKey = Boolean( settings.api_key );

		setHasAPI( hasKey );

		if ( hasKey ) {
			window.hyveTrk?.add?.( {
				feature: 'openai',
				featureComponent: 'api-key',
				featureValue: 'added',
			} );
		}

		if ( response.warning ) {
			createNotice( 'warning', response.warning, {
				type: 'snackbar',
				isDismissible: true,
			} );
			setApiStatus( 'error' );
		} else {
			setApiStatus( hasKey ? 'connected' : 'none' );
		}
	};

	// GPT-3.5 is no longer offered (it errors with structured outputs); show
	// the default instead. A still-valid but no-longer-listed saved model is
	// appended so it isn't silently lost.
	const savedModel = settings.chat_model;
	const isLegacyModel =
		'string' === typeof savedModel && savedModel.startsWith( 'gpt-3.5' );
	const selectedModel =
		( isLegacyModel ? 'gpt-5.4-nano' : savedModel ) || 'gpt-5.4-nano';

	const modelOptions = MODEL_OPTIONS.some(
		( option ) => option.value === selectedModel
	)
		? MODEL_OPTIONS
		: [
				...MODEL_OPTIONS,
				{
					label: selectedModel,
					value: selectedModel,
					description: __(
						'Your currently selected model.',
						'hyve-lite'
					),
				},
		  ];

	const selectedModelOption = modelOptions.find(
		( option ) => option.value === selectedModel
	);

	return (
		<Card
			title={ __( 'OpenAI', 'hyve-lite' ) }
			footer={
				<Button
					variant="primary"
					isBusy={ isSaving }
					disabled={ isSaving }
					onClick={ onSave }
				>
					{ __( 'Save changes', 'hyve-lite' ) }
				</Button>
			}
		>
			{ isConnectActive && (
				<div className="hyve-next-notice">
					<div className="hyve-next-notice__body">
						<strong>
							{ __(
								'Hyve Connect is handling AI.',
								'hyve-lite'
							) }
						</strong>{ ' ' }
						{ __(
							'These OpenAI settings stay inactive while Connect is on.',
							'hyve-lite'
						) }{ ' ' }
						<Button
							variant="link"
							onClick={ () =>
								navigate( 'settings', 'hyve-connect' )
							}
						>
							{ __( 'Manage Hyve Connect', 'hyve-lite' ) }
						</Button>
					</div>
				</div>
			) }

			<FieldRow
				label={ __( 'API key', 'hyve-lite' ) }
				description={ __(
					'Used for chat, embeddings and moderation.',
					'hyve-lite'
				) }
				wide
			>
				<div className="hyve-next-field__inline">
					<TextControl
						__nextHasNoMarginBottom
						hideLabelFromVision
						label={ __( 'API key', 'hyve-lite' ) }
						type="password"
						value={ settings.api_key || '' }
						disabled={ isSaving || apiKeyLocked }
						onChange={ ( value ) => {
							setSetting( 'api_key', value );
							setApiStatus( 'editing' );
						} }
					/>
					<ApiStatusChip status={ apiStatus } />
				</div>
				{ 'none' === apiStatus && ! apiKeyLocked && (
					<p className="hyve-next-field__hint">
						<ExternalLink href="https://platform.openai.com/api-keys">
							{ __( 'Get an API key', 'hyve-lite' ) }
						</ExternalLink>
					</p>
				) }
			</FieldRow>

			<FieldRow
				label={ __( 'Model', 'hyve-lite' ) }
				description={ __(
					'More capable models give better answers but cost more per message.',
					'hyve-lite'
				) }
			>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Model', 'hyve-lite' ) }
					value={ selectedModel }
					options={ modelOptions.map( ( { label, value } ) => ( {
						label,
						value,
					} ) ) }
					disabled={ isSaving || providerLocked }
					onChange={ ( value ) => setSetting( 'chat_model', value ) }
				/>
				{ selectedModelOption?.description && (
					<p className="hyve-next-field__hint">
						{ selectedModelOption.description }
					</p>
				) }
				<p className="hyve-next-field__hint">
					<ExternalLink href="https://developers.openai.com/api/docs/pricing">
						{ __( 'Compare model pricing', 'hyve-lite' ) }
					</ExternalLink>
				</p>
			</FieldRow>
		</Card>
	);
};

export const AdvancedPanel = () => {
	const { settings, isSaving, save } = useSaveSettings();

	const { setSetting } = useDispatch( 'hyve' );
	const isConnectActive = useSelect( ( select ) =>
		select( 'hyve' ).isConnectActive()
	);

	const resetDefaults = () => {
		Object.entries( ADVANCED_DEFAULTS ).forEach( ( [ key, value ] ) =>
			setSetting( key, value )
		);
	};

	return (
		<Card
			title={ __( 'Advanced tuning', 'hyve-lite' ) }
			footer={
				<>
					<Button
						variant="primary"
						isBusy={ isSaving }
						disabled={ isSaving }
						onClick={ save }
					>
						{ __( 'Save changes', 'hyve-lite' ) }
					</Button>
					<Button
						variant="secondary"
						disabled={ isSaving }
						onClick={ resetDefaults }
					>
						{ __( 'Reset to defaults', 'hyve-lite' ) }
					</Button>
				</>
			}
		>
			<FieldRow
				label={ __( 'Similarity threshold', 'hyve-lite' ) }
				description={ __(
					'How closely Knowledge Base content must match a question to be used.',
					'hyve-lite'
				) }
			>
				<RangeControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Similarity threshold', 'hyve-lite' ) }
					value={ settings.similarity_score_threshold ?? 0.4 }
					min={ -1 }
					max={ 1 }
					step={ 0.01 }
					disabled={ isSaving || isConnectActive }
					onChange={ ( value ) =>
						setSetting( 'similarity_score_threshold', value )
					}
				/>
			</FieldRow>
		</Card>
	);
};
