/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import {
	Button,
	CheckboxControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { setUtm } from '../utils';
import Card from '../components/Card';
import Chip from '../components/Chip';
import FieldRow from '../components/FieldRow';
import Slot from '../components/Slot';

const ProChip = () => (
	<Chip tone="pro" dot={ false }>
		{ __( 'Pro', 'hyve-lite' ) }
	</Chip>
);

const PREVIEW_FIELDS = [
	{
		label: __( 'Name', 'hyve-lite' ),
		type: __( 'Text', 'hyve-lite' ),
		required: false,
	},
	{
		label: __( 'Email', 'hyve-lite' ),
		type: __( 'Email', 'hyve-lite' ),
		required: true,
	},
	{
		label: __( 'Message', 'hyve-lite' ),
		type: __( 'Long text', 'hyve-lite' ),
		required: false,
	},
];

/**
 * Free-tier preview: the real panel's controls, disabled, plus the upsell.
 */
const LeadsPreview = () => (
	<>
		<Card
			title={ __( 'Lead capture', 'hyve-lite' ) }
			actions={ <ProChip /> }
		>
			<FieldRow
				label={ __( 'Collect leads', 'hyve-lite' ) }
				description={ __(
					'Show a contact form in the chat so visitors can leave their details.',
					'hyve-lite'
				) }
			>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Disabled', 'hyve-lite' ) }
					checked={ false }
					disabled
					onChange={ () => {} }
				/>
			</FieldRow>

			<FieldRow
				label={ __( 'Before the chat starts', 'hyve-lite' ) }
				description={ __(
					'Ask visitors to fill the form before their first message.',
					'hyve-lite'
				) }
			>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Disabled', 'hyve-lite' ) }
					checked={ false }
					disabled
					onChange={ () => {} }
				/>
			</FieldRow>

			<FieldRow
				label={ __( "When the bot can't answer", 'hyve-lite' ) }
				description={ __(
					'Offer the form whenever Hyve has no answer for a question.',
					'hyve-lite'
				) }
			>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Enabled', 'hyve-lite' ) }
					checked
					disabled
					onChange={ () => {} }
				/>
			</FieldRow>

			<FieldRow
				label={ __( 'When the visitor asks for a human', 'hyve-lite' ) }
				description={ __(
					'Hyve detects requests to talk to a person and offers the form.',
					'hyve-lite'
				) }
			>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Enabled', 'hyve-lite' ) }
					checked
					disabled
					onChange={ () => {} }
				/>
			</FieldRow>

			<div className="hyve-next-act__upsell">
				<strong>
					{ __( 'Turn conversations into leads', 'hyve-lite' ) }
				</strong>
				<p>
					{ __(
						'Let visitors leave their contact details right in the chat, review every lead in your dashboard and send them anywhere with webhooks. Part of Hyve Pro.',
						'hyve-lite'
					) }
				</p>
				<Button
					variant="primary"
					href={ setUtm( window.hyve?.pro, 'leads-settings' ) }
					target="_blank"
				>
					{ __( 'Unlock with Pro', 'hyve-lite' ) }
				</Button>
			</div>
		</Card>

		<Card
			title={ __( 'Form fields', 'hyve-lite' ) }
			actions={ <ProChip /> }
		>
			<div className="hyve-next-table-wrap hyve-next-demo">
				<table className="hyve-next-table hyve-next-leadfields">
					<thead>
						<tr>
							<th>{ __( 'Label', 'hyve-lite' ) }</th>
							<th>{ __( 'Type', 'hyve-lite' ) }</th>
							<th>{ __( 'Required', 'hyve-lite' ) }</th>
							<th>
								<span className="screen-reader-text">
									{ __( 'Actions', 'hyve-lite' ) }
								</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ PREVIEW_FIELDS.map( ( field, index ) => (
							<tr key={ index }>
								<td className="hyve-next-table__main">
									<TextControl
										__nextHasNoMarginBottom
										hideLabelFromVision
										label={ __(
											'Field label',
											'hyve-lite'
										) }
										value={ field.label }
										disabled
										onChange={ () => {} }
									/>
								</td>
								<td>
									<SelectControl
										__nextHasNoMarginBottom
										hideLabelFromVision
										label={ __(
											'Field type',
											'hyve-lite'
										) }
										value={ field.type }
										options={ [
											{
												label: field.type,
												value: field.type,
											},
										] }
										disabled
										onChange={ () => {} }
									/>
								</td>
								<td>
									<CheckboxControl
										__nextHasNoMarginBottom
										aria-label={ __(
											'Required',
											'hyve-lite'
										) }
										checked={ field.required }
										disabled
										onChange={ () => {} }
									/>
								</td>
								<td className="hyve-next-table__actions">
									<div className="hyve-next-buttons">
										<Button
											variant="tertiary"
											icon="arrow-up-alt2"
											label={ __(
												'Move up',
												'hyve-lite'
											) }
											disabled
										/>
										<Button
											variant="tertiary"
											icon="arrow-down-alt2"
											label={ __(
												'Move down',
												'hyve-lite'
											) }
											disabled
										/>
										<Button
											variant="tertiary"
											isDestructive
											disabled
										>
											{ __( 'Remove', 'hyve-lite' ) }
										</Button>
									</div>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
			<div className="hyve-next-card__body">
				<div>
					<Button variant="secondary" disabled>
						{ __( 'Add field', 'hyve-lite' ) }
					</Button>
				</div>
			</div>
		</Card>

		<Card title={ __( 'Messages', 'hyve-lite' ) } actions={ <ProChip /> }>
			<FieldRow
				label={ __( 'Form heading', 'hyve-lite' ) }
				description={ __(
					'Shown at the top of the contact form.',
					'hyve-lite'
				) }
			>
				<TextControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Form heading', 'hyve-lite' ) }
					value={ __(
						'Leave your details and we will get back to you.',
						'hyve-lite'
					) }
					disabled
					onChange={ () => {} }
				/>
			</FieldRow>

			<FieldRow
				label={ __( 'Offer prompt', 'hyve-lite' ) }
				description={ __(
					'The message that offers the form when the bot cannot help or the visitor asks for a human.',
					'hyve-lite'
				) }
			>
				<TextControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Offer prompt', 'hyve-lite' ) }
					value={ __(
						'Would you like to leave your contact details instead?',
						'hyve-lite'
					) }
					disabled
					onChange={ () => {} }
				/>
			</FieldRow>

			<FieldRow
				label={ __( 'Thank-you message', 'hyve-lite' ) }
				description={ __(
					'Shown after the visitor submits the form.',
					'hyve-lite'
				) }
			>
				<TextControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Thank-you message', 'hyve-lite' ) }
					value={ __(
						'Thanks! Your details have been sent. We will get back to you soon.',
						'hyve-lite'
					) }
					disabled
					onChange={ () => {} }
				/>
			</FieldRow>
		</Card>
	</>
);

/**
 * Leads settings panel: Pro fills the slot; free installs get the preview.
 */
const Leads = () => {
	const hasPro = Boolean( window.hyve?.license );

	if ( ! hasPro ) {
		return <LeadsPreview />;
	}

	return (
		<Slot
			name="settings-leads"
			fallback={
				<div className="hyve-next__card">
					<p>
						{ __(
							'The lead capture settings are on their way here.',
							'hyve-lite'
						) }
					</p>
				</div>
			}
		/>
	);
};

export default Leads;
