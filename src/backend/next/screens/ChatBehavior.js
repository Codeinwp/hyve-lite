/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';

import {
	Button,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

import { useDispatch } from '@wordpress/data';

/**
 * Internal dependencies.
 */
import { setUtm } from '../../utils';
import Card from '../components/Card';
import Chip from '../components/Chip';
import FieldRow from '../components/FieldRow';
import useSaveSettings from '../data/useSaveSettings';

const VISIBILITY_OPTIONS = [
	{ value: 'all', label: __( 'Show on all pages', 'hyve-lite' ) },
	{ value: 'include', label: __( 'Only on selected content', 'hyve-lite' ) },
	{
		value: 'exclude',
		label: __( 'Everywhere except selected content', 'hyve-lite' ),
	},
	{ value: 'manual', label: __( "Don't show automatically", 'hyve-lite' ) },
];

const QUESTION_PLACEHOLDERS = [
	__( 'Do you ship to Europe?', 'hyve-lite' ),
	__( "What's your refund policy?", 'hyve-lite' ),
	__( 'How do I contact support?', 'hyve-lite' ),
];

const SaveButton = ( { isSaving, save } ) => (
	<Button
		variant="primary"
		isBusy={ isSaving }
		disabled={ isSaving }
		onClick={ save }
	>
		{ __( 'Save changes', 'hyve-lite' ) }
	</Button>
);

const VisibilityCard = () => {
	const { settings, isSaving, save } = useSaveSettings();

	const { setSetting } = useDispatch( 'hyve' );

	const displayMode = settings.display_mode ?? 'all';
	const displayRules = Array.isArray( settings.display_rules )
		? settings.display_rules
		: [];

	const updateRule = ( index, key, value ) =>
		setSetting(
			'display_rules',
			displayRules.map( ( rule, i ) =>
				i === index ? { ...rule, [ key ]: value } : rule
			)
		);

	return (
		<Card
			title={ __( 'Where should Hyve appear?', 'hyve-lite' ) }
			footer={ <SaveButton isSaving={ isSaving } save={ save } /> }
		>
			<div className="hyve-next-card__body">
				<div
					className="hyve-next-rcards"
					role="radiogroup"
					aria-label={ __(
						'Where should Hyve appear?',
						'hyve-lite'
					) }
				>
					{ VISIBILITY_OPTIONS.map( ( option ) => (
						<label
							key={ option.value }
							htmlFor={ `hyve-next-mode-${ option.value }` }
							className={ `hyve-next-rcard${
								displayMode === option.value
									? ' is-selected'
									: ''
							}` }
						>
							<input
								type="radio"
								id={ `hyve-next-mode-${ option.value }` }
								name="hyve-next-display-mode"
								value={ option.value }
								checked={ displayMode === option.value }
								disabled={ isSaving }
								onChange={ () =>
									setSetting( 'display_mode', option.value )
								}
							/>
							{ option.label }
						</label>
					) ) }
				</div>

				{ ( 'include' === displayMode ||
					'exclude' === displayMode ) && (
					<div className="hyve-next-rules">
						<strong>{ __( 'Content URLs', 'hyve-lite' ) }</strong>
						<p>
							{ __(
								'Match by URL path. Use “contains” for a whole section (e.g. /shop/), or “matches” for one exact page.',
								'hyve-lite'
							) }
						</p>

						{ displayRules.map( ( rule, index ) => (
							<div
								className="hyve-next-rules__row"
								// eslint-disable-next-line react/no-array-index-key
								key={ index }
							>
								<TextControl
									__nextHasNoMarginBottom
									hideLabelFromVision
									label={ __( 'Path', 'hyve-lite' ) }
									placeholder="/example-page/"
									value={ rule.path || '' }
									disabled={ isSaving }
									onChange={ ( value ) =>
										updateRule( index, 'path', value )
									}
								/>
								<SelectControl
									__nextHasNoMarginBottom
									hideLabelFromVision
									label={ __( 'Operator', 'hyve-lite' ) }
									value={ rule.operator || 'contains' }
									options={ [
										{
											label: __(
												'contains',
												'hyve-lite'
											),
											value: 'contains',
										},
										{
											label: __( 'matches', 'hyve-lite' ),
											value: 'matches',
										},
									] }
									disabled={ isSaving }
									onChange={ ( value ) =>
										updateRule( index, 'operator', value )
									}
								/>
								<Button
									variant="tertiary"
									isDestructive
									disabled={ isSaving }
									onClick={ () =>
										setSetting(
											'display_rules',
											displayRules.filter(
												( _, i ) => i !== index
											)
										)
									}
								>
									{ __( 'Remove', 'hyve-lite' ) }
								</Button>
							</div>
						) ) }

						<Button
							variant="secondary"
							disabled={ isSaving }
							onClick={ () =>
								setSetting( 'display_rules', [
									...displayRules,
									{ path: '', operator: 'contains' },
								] )
							}
						>
							{ __( 'Add URL rule', 'hyve-lite' ) }
						</Button>
					</div>
				) }

				<p className="hyve-next-card__hint">
					{ __(
						'Blocks and shortcodes stay available for inline or manual placement regardless of this setting.',
						'hyve-lite'
					) }
				</p>
			</div>
		</Card>
	);
};

const ConversationCard = () => {
	const { settings, isSaving, save } = useSaveSettings();

	const { setSetting } = useDispatch( 'hyve' );

	const soundEnabled = Boolean( settings.sound_enabled ?? true );

	return (
		<Card
			title={ __( 'Conversation', 'hyve-lite' ) }
			footer={ <SaveButton isSaving={ isSaving } save={ save } /> }
		>
			<FieldRow
				label={ __( 'Welcome message', 'hyve-lite' ) }
				description={ __(
					'The first thing visitors see when the chat opens.',
					'hyve-lite'
				) }
			>
				<TextControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Welcome message', 'hyve-lite' ) }
					value={ settings.welcome_message || '' }
					disabled={ isSaving }
					onChange={ ( value ) =>
						setSetting( 'welcome_message', value )
					}
				/>
			</FieldRow>

			<FieldRow
				label={ __( 'Default message', 'hyve-lite' ) }
				description={ __(
					"Shown when Hyve can't find an answer in the knowledge base.",
					'hyve-lite'
				) }
			>
				<TextControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Default message', 'hyve-lite' ) }
					value={ settings.default_message || '' }
					disabled={ isSaving }
					onChange={ ( value ) =>
						setSetting( 'default_message', value )
					}
				/>
			</FieldRow>

			<FieldRow
				label={ __( 'Chat sound', 'hyve-lite' ) }
				description={ __(
					'Play a sound when the chat opens and when a new message arrives. Visitors can still mute it for themselves from within the chat.',
					'hyve-lite'
				) }
			>
				<ToggleControl
					__nextHasNoMarginBottom
					label={
						soundEnabled
							? __( 'Enabled', 'hyve-lite' )
							: __( 'Disabled', 'hyve-lite' )
					}
					checked={ soundEnabled }
					disabled={ isSaving }
					onChange={ ( value ) =>
						setSetting( 'sound_enabled', Boolean( value ) )
					}
				/>
			</FieldRow>
		</Card>
	);
};

const SuggestionsCard = () => {
	const isPro = Boolean( window.hyve?.license );

	const { settings, isSaving, save } = useSaveSettings();

	const { setSetting } = useDispatch( 'hyve' );

	const questions = Array.isArray( settings.predefined_questions )
		? settings.predefined_questions
		: [];

	const updateQuestion = ( index, value ) => {
		const next = [ ...questions ];
		next[ index ] = value;
		setSetting( 'predefined_questions', next );
	};

	return (
		<Card
			title={ __( 'Suggestions', 'hyve-lite' ) }
			actions={
				! isPro && (
					<Chip tone="pro" dot={ false }>
						{ __( 'Pro', 'hyve-lite' ) }
					</Chip>
				)
			}
			footer={
				isPro && <SaveButton isSaving={ isSaving } save={ save } />
			}
		>
			<FieldRow
				label={ __( 'Suggested questions', 'hyve-lite' ) }
				description={ __(
					'Up to three openers shown before the conversation starts.',
					'hyve-lite'
				) }
			>
				<div className="hyve-next-stack">
					{ [ 0, 1, 2 ].map( ( index ) => (
						<TextControl
							key={ index }
							__nextHasNoMarginBottom
							hideLabelFromVision
							label={ sprintf(
								/* translators: %d: the position of the suggested question, 1 to 3. */
								__( 'Suggested question %d', 'hyve-lite' ),
								index + 1
							) }
							placeholder={ QUESTION_PLACEHOLDERS[ index ] }
							value={ questions[ index ] || '' }
							disabled={ ! isPro || isSaving }
							onChange={ ( value ) =>
								updateQuestion( index, value )
							}
						/>
					) ) }
				</div>
			</FieldRow>

			{ ! isPro && (
				<div className="hyve-next-act__upsell">
					<strong>
						{ __(
							'Greet visitors with ready-made questions',
							'hyve-lite'
						) }
					</strong>
					<p>
						{ __(
							'Suggested questions are part of Hyve Pro.',
							'hyve-lite'
						) }
					</p>
					<Button
						variant="primary"
						href={ setUtm(
							window.hyve?.pro,
							'suggested-questions-settings'
						) }
						target="_blank"
					>
						{ __( 'Unlock with Pro', 'hyve-lite' ) }
					</Button>
				</div>
			) }
		</Card>
	);
};

const ChatBehavior = () => {
	return (
		<>
			<VisibilityCard />
			<ConversationCard />
			<SuggestionsCard />
		</>
	);
};

export default ChatBehavior;
