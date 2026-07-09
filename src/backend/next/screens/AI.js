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

import { useDispatch } from '@wordpress/data';

import { useState } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import Card from '../components/Card';
import Chip from '../components/Chip';
import FieldRow from '../components/FieldRow';
import useSaveSettings from '../data/useSaveSettings';

const MODEL_OPTIONS = [
	{
		value: 'gpt-4o-mini',
		label: __(
			'GPT-4o mini (recommended): fastest and most affordable',
			'hyve-lite'
		),
	},
	{
		value: 'gpt-4.1',
		label: __( 'GPT-4.1: capable and proven', 'hyve-lite' ),
	},
	{
		value: 'gpt-4.1-mini',
		label: __( 'GPT-4.1 mini: faster, cheaper all-rounder', 'hyve-lite' ),
	},
	{
		value: 'gpt-4.1-nano',
		label: __( 'GPT-4.1 nano: ultra fast, very low cost', 'hyve-lite' ),
	},
	{
		value: 'gpt-4o',
		label: __( 'GPT-4o: smart, cost-effective', 'hyve-lite' ),
	},
	{
		value: 'gpt-3.5-turbo-0125',
		label: __( 'GPT-3.5 Turbo: legacy', 'hyve-lite' ),
	},
];

const ADVANCED_DEFAULTS = {
	temperature: 1,
	top_p: 1,
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
						disabled={ isSaving }
						onChange={ ( value ) => {
							setSetting( 'api_key', value );
							setApiStatus( 'editing' );
						} }
					/>
					<ApiStatusChip status={ apiStatus } />
				</div>
				{ 'none' === apiStatus && (
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
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Model', 'hyve-lite' ) }
					value={ settings.chat_model || 'gpt-4o-mini' }
					options={ MODEL_OPTIONS }
					disabled={ isSaving }
					onChange={ ( value ) => setSetting( 'chat_model', value ) }
				/>
			</FieldRow>
		</Card>
	);
};

export const AdvancedPanel = () => {
	const { settings, isSaving, save } = useSaveSettings();

	const { setSetting } = useDispatch( 'hyve' );

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
				label={ __( 'Temperature', 'hyve-lite' ) }
				description={ __(
					'Higher is more creative; lower is more focused.',
					'hyve-lite'
				) }
			>
				<RangeControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Temperature', 'hyve-lite' ) }
					value={ settings.temperature ?? 1 }
					min={ 0.1 }
					max={ 2 }
					step={ 0.1 }
					disabled={ isSaving }
					onChange={ ( value ) => setSetting( 'temperature', value ) }
				/>
			</FieldRow>

			<FieldRow
				label={ __( 'Top P', 'hyve-lite' ) }
				description={ __(
					'Nucleus-sampling alternative to temperature.',
					'hyve-lite'
				) }
			>
				<RangeControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Top P', 'hyve-lite' ) }
					value={ settings.top_p ?? 1 }
					min={ 0.1 }
					max={ 1 }
					step={ 0.1 }
					disabled={ isSaving }
					onChange={ ( value ) => setSetting( 'top_p', value ) }
				/>
			</FieldRow>

			<FieldRow
				label={ __( 'Similarity threshold', 'hyve-lite' ) }
				description={ __(
					'How closely knowledge base content must match a question to be used.',
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
					disabled={ isSaving }
					onChange={ ( value ) =>
						setSetting( 'similarity_score_threshold', value )
					}
				/>
			</FieldRow>
		</Card>
	);
};
