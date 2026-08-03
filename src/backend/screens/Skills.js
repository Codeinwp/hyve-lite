/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import { Button, CheckboxControl, ToggleControl } from '@wordpress/components';

import { Icon, chevronUp } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { setUtm } from '../utils';
import Card from '../components/Card';
import Chip from '../components/Chip';
import FieldRow from '../components/FieldRow';
import Slot from '../components/Slot';

// A canned preview so the free screen shows what Skills looks like once a
// plugin such as WooCommerce exposes read-only abilities.
const PREVIEW = [
	{
		label: __( 'Check order status', 'hyve-lite' ),
		description: __(
			'Look up the status of an order by its number or the customer’s email.',
			'hyve-lite'
		),
	},
	{
		label: __( 'Look up stock', 'hyve-lite' ),
		description: __(
			'Tell the visitor whether a product is in stock and how many are left.',
			'hyve-lite'
		),
	},
];

/**
 * Skills settings panel. Pro fills the real screen; without a license this
 * shows a preview of the feature and the upgrade prompt.
 */
const Skills = () => {
	const hasPro = Boolean( window.hyve?.license );

	if ( hasPro ) {
		return (
			<Slot
				name="settings-skills"
				fallback={
					<div className="hyve-next__card">
						<p>
							{ __(
								'The Skills settings are on their way here.',
								'hyve-lite'
							) }
						</p>
					</div>
				}
			/>
		);
	}

	return (
		<Card
			title={ __( 'Skills', 'hyve-lite' ) }
			actions={
				<Chip tone="pro" dot={ false }>
					{ __( 'Pro', 'hyve-lite' ) }
				</Chip>
			}
		>
			<div className="hyve-next-card__intro">
				<p>
					{ __(
						'Let the assistant do things live, like checking an order or looking up stock, by calling functions your plugins provide. It only calls the ones you allow, and each call still respects that function’s own permissions.',
						'hyve-lite'
					) }
				</p>
			</div>

			<FieldRow
				label={ __( 'Enable Skills', 'hyve-lite' ) }
				description={ __(
					'Allow the assistant to call the functions you select during a chat.',
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

			<div className="hyve-next-skills__group">
				<div className="hyve-next-skills__head">
					<span className="hyve-next-skills__toggle">
						<Icon icon={ chevronUp } />
						<span className="hyve-next-skills__group-name">
							WooCommerce
						</span>
						<span className="hyve-next-skills__count">
							{ __( '2 of 2 enabled', 'hyve-lite' ) }
						</span>
					</span>
					<CheckboxControl
						__nextHasNoMarginBottom
						hideLabelFromVision
						label={ __(
							'Enable all WooCommerce skills',
							'hyve-lite'
						) }
						checked
						disabled
						onChange={ () => {} }
					/>
				</div>
				{ PREVIEW.map( ( item ) => (
					<div className="hyve-next-skills__row" key={ item.label }>
						<CheckboxControl
							__nextHasNoMarginBottom
							checked
							disabled
							onChange={ () => {} }
							label={
								<span className="hyve-next-skills__meta">
									<span className="hyve-next-skills__label">
										{ item.label }
									</span>
									<span className="hyve-next-skills__badges">
										<Chip tone="ok" dot={ false }>
											{ __( 'Read-only', 'hyve-lite' ) }
										</Chip>
									</span>
								</span>
							}
						/>
						<p className="hyve-next-skills__desc">
							{ item.description }
						</p>
					</div>
				) ) }
			</div>

			<div className="hyve-next-act__upsell">
				<strong>
					{ __( 'Give your assistant real skills', 'hyve-lite' ) }
				</strong>
				<p>
					{ __(
						'Go beyond answering from your content. Let the assistant check orders, stock and more, live in the chat, using the plugins you already run. Part of Hyve Pro.',
						'hyve-lite'
					) }
				</p>
				<Button
					variant="primary"
					href={ setUtm( window.hyve?.pro, 'skills-settings' ) }
					target="_blank"
				>
					{ __( 'Unlock with Pro', 'hyve-lite' ) }
				</Button>
			</div>
		</Card>
	);
};

export default Skills;
