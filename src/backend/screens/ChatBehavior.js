/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';

import {
	Button,
	SelectControl,
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

import { useDispatch } from '@wordpress/data';

import { createInterpolateElement } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { isLicenseActive, setUtm } from '../utils';
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

const ProChip = () => (
	<Chip tone="pro" dot={ false }>
		{ __( 'Pro', 'hyve-lite' ) }
	</Chip>
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
							<div className="hyve-next-rules__row" key={ index }>
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
	const isPro = isLicenseActive();
	const { settings, isSaving, save } = useSaveSettings();

	const { setSetting } = useDispatch( 'hyve' );

	const soundEnabled = Boolean( settings.sound_enabled ?? true );
	const pageAwareness = Boolean( settings.page_context_enabled ?? true );

	return (
		<Card
			title={ __( 'Conversation', 'hyve-lite' ) }
			footer={
				<>
					<SaveButton isSaving={ isSaving } save={ save } />
					{ ! isPro && (
						<div className="hyve-next-card__foot-upsell">
							<span>
								{ __(
									'Custom instructions are part of Hyve Pro.',
									'hyve-lite'
								) }
							</span>
							<Button
								variant="secondary"
								href={ setUtm(
									window.hyve?.pro,
									'system-prompt-settings'
								) }
								target="_blank"
							>
								{ __( 'Unlock with Pro', 'hyve-lite' ) }
							</Button>
						</div>
					) }
				</>
			}
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
					"Shown when Hyve can't find an answer in the Knowledge Base.",
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
				label={
					<>
						{ __( 'Custom instructions', 'hyve-lite' ) }{ ' ' }
						{ ! isPro && <ProChip /> }
					</>
				}
				description={ __(
					'Shape the assistant’s tone and persona with your own instructions. The built-in answer format and Knowledge Base rules always stay in effect.',
					'hyve-lite'
				) }
			>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Custom instructions', 'hyve-lite' ) }
					placeholder={ __(
						'e.g. You are the friendly support assistant for Acme Co. Keep replies short and warm.',
						'hyve-lite'
					) }
					rows={ 6 }
					value={ settings.system_prompt || '' }
					disabled={ ! isPro || isSaving }
					onChange={ ( value ) =>
						setSetting( 'system_prompt', value )
					}
				/>
			</FieldRow>
			<FieldRow
				label={
					<>
						{ __( 'Page awareness', 'hyve-lite' ) }{ ' ' }
						{ ! isPro && <ProChip /> }
					</>
				}
				description={ __(
					'Let the assistant see the page a visitor is chatting from, so questions like "how much does this cost?" get answered from that page, even when it isn\'t in the Knowledge Base.',
					'hyve-lite'
				) }
			>
				<ToggleControl
					__nextHasNoMarginBottom
					label={
						pageAwareness
							? __( 'Enabled', 'hyve-lite' )
							: __( 'Disabled', 'hyve-lite' )
					}
					checked={ pageAwareness }
					disabled={ ! isPro || isSaving }
					onChange={ ( value ) =>
						setSetting( 'page_context_enabled', Boolean( value ) )
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
	const isPro = isLicenseActive();

	const { settings, isSaving, save } = useSaveSettings();

	const followUps = Boolean( settings.follow_up_questions ?? true );

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

			<FieldRow
				label={ __( 'Follow-up questions', 'hyve-lite' ) }
				description={ __(
					'After each answer, suggest a few related questions the visitor can click to keep the conversation going. Only questions the Knowledge Base can answer get suggested.',
					'hyve-lite'
				) }
			>
				<ToggleControl
					__nextHasNoMarginBottom
					label={
						followUps
							? __( 'Enabled', 'hyve-lite' )
							: __( 'Disabled', 'hyve-lite' )
					}
					checked={ followUps }
					disabled={ ! isPro || isSaving }
					onChange={ ( value ) =>
						setSetting( 'follow_up_questions', Boolean( value ) )
					}
				/>
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
							'Suggested questions and follow-ups are part of Hyve Pro.',
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

const INVITE_PLACEHOLDER = __(
	'Hi there! Have any questions? I can help.',
	'hyve-lite'
);

const TRIGGER_OPTIONS = [
	{ value: 'none', label: __( 'Disabled', 'hyve-lite' ) },
	{ value: 'time', label: __( 'Time on page', 'hyve-lite' ) },
	{ value: 'exit', label: __( 'Exit intent', 'hyve-lite' ) },
	{ value: 'scroll', label: __( 'Scroll depth', 'hyve-lite' ) },
];

const TRIGGER_DESCRIPTIONS = {
	none: __(
		'Pick a trigger to show a small bubble next to the closed chat button, inviting the visitor to start a conversation.',
		'hyve-lite'
	),
	time: __(
		'The invite appears after the visitor has been on the page for this long.',
		'hyve-lite'
	),
	exit: __(
		'The invite appears when the pointer leaves toward the top of the page, as if about to close the tab. Desktop only.',
		'hyve-lite'
	),
	scroll: __(
		'The invite appears once the visitor has scrolled this far down the page.',
		'hyve-lite'
	),
};

const ProactiveCard = () => {
	const isPro = isLicenseActive();

	const { settings, isSaving, save } = useSaveSettings();

	const { setSetting } = useDispatch( 'hyve' );

	const trigger = settings.proactive_trigger || 'none';
	const enabled = 'none' !== trigger;

	return (
		<Card
			title={ __( 'Proactive message', 'hyve-lite' ) }
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
				label={ __( 'Show it when', 'hyve-lite' ) }
				description={ TRIGGER_DESCRIPTIONS[ trigger ] }
			>
				<div className="hyve-next-stack">
					<SelectControl
						__nextHasNoMarginBottom
						hideLabelFromVision
						label={ __( 'Trigger', 'hyve-lite' ) }
						options={ TRIGGER_OPTIONS }
						value={ trigger }
						disabled={ ! isPro || isSaving }
						onChange={ ( value ) =>
							setSetting( 'proactive_trigger', value )
						}
					/>

					{ 'time' === trigger && (
						<TextControl
							__nextHasNoMarginBottom
							type="number"
							min={ 0 }
							max={ 600 }
							label={ __( 'Seconds on page', 'hyve-lite' ) }
							value={ settings.proactive_delay ?? 10 }
							disabled={ ! isPro || isSaving }
							onChange={ ( value ) =>
								setSetting(
									'proactive_delay',
									Math.max(
										0,
										Math.min(
											600,
											parseInt( value, 10 ) || 0
										)
									)
								)
							}
						/>
					) }

					{ 'scroll' === trigger && (
						<TextControl
							__nextHasNoMarginBottom
							type="number"
							min={ 1 }
							max={ 100 }
							label={ __( 'Scroll percentage', 'hyve-lite' ) }
							value={ settings.proactive_scroll_depth ?? 50 }
							disabled={ ! isPro || isSaving }
							onChange={ ( value ) =>
								setSetting(
									'proactive_scroll_depth',
									Math.max(
										1,
										Math.min(
											100,
											parseInt( value, 10 ) || 1
										)
									)
								)
							}
						/>
					) }
				</div>
			</FieldRow>

			{ enabled && (
				<FieldRow
					label={ __( 'Invite message', 'hyve-lite' ) }
					description={ __(
						'What the bubble says to the visitor. Visitors can dismiss it, and it shows at most once per session.',
						'hyve-lite'
					) }
				>
					<div className="hyve-next-stack">
						<TextControl
							__nextHasNoMarginBottom
							hideLabelFromVision
							label={ __( 'Invite message', 'hyve-lite' ) }
							placeholder={ INVITE_PLACEHOLDER }
							value={ settings.proactive_message || '' }
							disabled={ ! isPro || isSaving }
							onChange={ ( value ) =>
								setSetting( 'proactive_message', value )
							}
						/>

						{ window.hyveApp?.previewTeaser && (
							<div>
								<Button
									variant="secondary"
									disabled={ ! isPro || isSaving }
									onClick={ () =>
										window.hyveApp.previewTeaser(
											settings.proactive_message ||
												INVITE_PLACEHOLDER
										)
									}
								>
									{ __( 'Preview invite', 'hyve-lite' ) }
								</Button>
							</div>
						) }
					</div>
				</FieldRow>
			) }

			{ ! isPro && (
				<div className="hyve-next-act__upsell">
					<strong>
						{ __(
							'Start conversations before visitors do',
							'hyve-lite'
						) }
					</strong>
					<p>
						{ __(
							'Proactive messages are part of Hyve Pro.',
							'hyve-lite'
						) }
					</p>
					<Button
						variant="primary"
						href={ setUtm(
							window.hyve?.pro,
							'proactive-message-settings'
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

const TrustCard = () => {
	const { settings, isSaving, save } = useSaveSettings();

	const { setSetting } = useDispatch( 'hyve' );

	const sourceLinks = Boolean( settings.show_source_link );
	const privacyNotice = Boolean( settings.privacy_notice_enabled ?? false );

	const privacySettingsUrl =
		window.hyve?.privacySettings || 'options-privacy.php';

	const privacyLink = (
		// eslint-disable-next-line jsx-a11y/anchor-has-content
		<a href={ privacySettingsUrl } target="_blank" rel="noreferrer" />
	);

	return (
		<Card
			title={ __( 'Trust & sources', 'hyve-lite' ) }
			footer={ <SaveButton isSaving={ isSaving } save={ save } /> }
		>
			<FieldRow
				label={ __( 'Source links', 'hyve-lite' ) }
				description={ __(
					'When enabled, chat responses include a link to the source content they were based on. The link is only added for publicly accessible sources.',
					'hyve-lite'
				) }
			>
				<ToggleControl
					__nextHasNoMarginBottom
					label={
						sourceLinks
							? __( 'Enabled', 'hyve-lite' )
							: __( 'Disabled', 'hyve-lite' )
					}
					checked={ sourceLinks }
					disabled={ isSaving }
					onChange={ ( value ) =>
						setSetting( 'show_source_link', Boolean( value ) )
					}
				/>
			</FieldRow>

			<FieldRow
				label={ __( 'Privacy notice', 'hyve-lite' ) }
				description={ createInterpolateElement(
					__(
						'Show a short “By chatting, you agree to our Privacy Policy” notice above the chat input. The link points to the page set under <a>Settings → Privacy</a>.',
						'hyve-lite'
					),
					{ a: privacyLink }
				) }
			>
				<ToggleControl
					__nextHasNoMarginBottom
					label={
						privacyNotice
							? __( 'Enabled', 'hyve-lite' )
							: __( 'Disabled', 'hyve-lite' )
					}
					checked={ privacyNotice }
					disabled={ isSaving }
					onChange={ ( value ) =>
						setSetting( 'privacy_notice_enabled', Boolean( value ) )
					}
				/>

				{ privacyNotice && ! window.hyve?.hasPrivacyPage && (
					<div className="hyve-next-notice is-warn is-compact">
						<div className="hyve-next-notice__body">
							<p className="hyve-next-notice__text">
								{ createInterpolateElement(
									__(
										'No Privacy Policy page is set, so the notice won’t appear on your site yet. Choose one under <a>Settings → Privacy</a>.',
										'hyve-lite'
									),
									{ a: privacyLink }
								) }
							</p>
						</div>
					</div>
				) }
			</FieldRow>
		</Card>
	);
};

const ChatBehavior = () => {
	return (
		<>
			<VisibilityCard />
			<ConversationCard />
			<SuggestionsCard />
			<ProactiveCard />
			<TrustCard />
		</>
	);
};

export default ChatBehavior;
