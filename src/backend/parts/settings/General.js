/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import {
	BaseControl,
	Button,
	Panel,
	PanelRow,
	TextControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';

import { useState } from '@wordpress/element';

import { useDispatch, useSelect } from '@wordpress/data';

import { applyFilters } from '@wordpress/hooks';

const SuggestedQuestions = () => {
	return (
		<UpsellContainer
			title={ __(
				'Suggested Questions is a Premium feature',
				'hyve-lite'
			) }
			description={ __(
				'Get the conversation started with suggested questions. Upgrade now!',
				'hyve-lite'
			) }
			campaign="suggested-questions-settings"
		>
			<PanelRow>
				<BaseControl
					id={ 'suggested-questions' }
					label={ __( 'Suggested Questions', 'hyve-lite' ) }
				>
					<p className="components-base-control__help text-xs not-italic text-[rgb(117,117,117)] mt-[calc(8px)] mb-[revert]">
						{ __(
							'These questions will be displayed in the chat to get the conversation started.',
							'hyve-lite'
						) }
					</p>

					<div className="overflow-y-auto max-h-96 flex flex-col gap-4">
						{ Array.from( { length: 3 } ).map( ( _, index ) => (
							<TextControl
								key={ index }
								value={ '' }
								placeholder={ __(
									'e.g. Do you ship to Europe?',
									'hyve-lite'
								) }
								className="flex-1"
								onChange={ () => {} }
							/>
						) ) }
					</div>
				</BaseControl>
			</PanelRow>
		</UpsellContainer>
	);
};

/**
 * Internal dependencies.
 */
import UpsellContainer from '../UpsellContainer';

const General = () => {
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

			window.tiTrk?.uploadEvents?.();
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

	return (
		<div className="col-span-6 xl:col-span-4">
			<Panel header={ __( 'General Settings', 'hyve-lite' ) }>
				<PanelRow>
					<ToggleGroupControl
						__nextHasNoMarginBottom
						isBlock
						label={ __(
							'Enable “Add to Hyve” Post Action',
							'hyve-lite'
						) }
						value={ Boolean( settings.post_row_addon_enabled ) }
						onChange={ ( newValue ) =>
							setSetting(
								'post_row_addon_enabled',
								Boolean( newValue )
							)
						}
						help={ __(
							'When enabled, an “Add to Hyve” button will appear in the post/page row actions. Disable this to remove the option entirely from the posts/pages list.',
							'hyve-lite'
						) }
					>
						<ToggleGroupControlOption
							aria-label={ __(
								'Enable “Add to Hyve” button',
								'hyve-lite'
							) }
							label={ __( 'Enable', 'hyve-lite' ) }
							showTooltip
							value={ true }
						/>
						<ToggleGroupControlOption
							aria-label={ __(
								'Disable “Add to Hyve” button',
								'hyve-lite'
							) }
							label={ __( 'Disable', 'hyve-lite' ) }
							showTooltip
							value={ false }
						/>
					</ToggleGroupControl>
				</PanelRow>

				<PanelRow>
					<TextControl
						label={ __( 'Welcome Message', 'hyve-lite' ) }
						help={ __(
							'This message will be displayed when the chat is opened.',
							'hyve-lite'
						) }
						value={ settings.welcome_message || '' }
						disabled={ isSaving }
						onChange={ ( newValue ) =>
							setSetting( 'welcome_message', newValue )
						}
					/>
				</PanelRow>

				<PanelRow>
					<TextControl
						label={ __( 'Default Message', 'hyve-lite' ) }
						help={ __(
							'This message will return when the chat is unable to find an answer.',
							'hyve-lite'
						) }
						value={ settings.default_message || '' }
						disabled={ isSaving }
						onChange={ ( newValue ) =>
							setSetting( 'default_message', newValue )
						}
					/>
				</PanelRow>

				{ applyFilters(
					'hyve.suggestedQuestions',
					<SuggestedQuestions />,
					isSaving,
					settings,
					setSetting
				) }

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

export default General;
