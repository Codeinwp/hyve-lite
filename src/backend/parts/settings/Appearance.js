/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import {
	BaseControl,
	Button,
	ColorPalette,
	Panel,
	PanelRow,
	TextControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOptionIcon as ToggleGroupControlOptionIcon,
} from '@wordpress/components';

import { applyFilters } from '@wordpress/hooks';

import { useState, useEffect, useMemo } from '@wordpress/element';

import { useDispatch, useSelect } from '@wordpress/data';

/**
 * Internal dependencies.
 */
import UpsellContainer from '../UpsellContainer';

const colorOptions = [
	{
		label: __( 'Chat Background', 'hyve-lite' ),
		value: 'chat_background',
		default: '#ffffff',
	},
	{
		label: __( 'Assistant Background', 'hyve-lite' ),
		value: 'assistant_background',
		default: '#ecf1fb',
	},
	{
		label: __( 'User Background', 'hyve-lite' ),
		value: 'user_background',
		default: '#1155cc',
	},
	{
		label: __( 'Icon Background', 'hyve-lite' ),
		value: 'icon_background',
		default: '#1155cc',
	},
];

/**
 * The Premium-only appearance options (name, icon, colors). On the free plugin
 * this renders an upsell with non-functional previews; the Pro plugin replaces
 * it via the `hyve.appearance.options` filter with the working controls.
 */
const ProOptionsUpsell = () => {
	const chatIconOptions = useMemo( () => {
		return applyFilters( 'hyve.appearance.chat-icons', [] );
	}, [] );

	return (
		<UpsellContainer
			title={ __(
				'Appearance customization is a Premium feature',
				'hyve-lite'
			) }
			description={ __(
				'Set a custom name, icon, and colors for your chat box with our Premium subscription. Upgrade now!',
				'hyve-lite'
			) }
			campaign="appearance-settings"
		>
			<PanelRow>
				<TextControl
					label={ __( 'Chat Name', 'hyve-lite' ) }
					help={ __(
						'The name shown in the chat header.',
						'hyve-lite'
					) }
					value={ '' }
					onChange={ () => {} }
				/>
			</PanelRow>

			{ 0 < chatIconOptions?.length && (
				<PanelRow>
					<ToggleGroupControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						isBlock
						label={ __( 'Chat Icons', 'hyve-lite' ) }
						value={ 'default' }
						onChange={ () => {} }
						className="hyve-chat-icons"
					>
						{ chatIconOptions?.map( ( { icon, label, value } ) => {
							const CustomIcon = icon;
							return (
								<ToggleGroupControlOptionIcon
									key={ value }
									icon={ <CustomIcon width="24" /> }
									label={ label }
									value={ value }
								/>
							);
						} ) }
					</ToggleGroupControl>
				</PanelRow>
			) }

			<PanelRow>
				{ colorOptions.map( ( option ) => (
					<BaseControl
						id={ option.label }
						key={ option.value }
						label={ option.label }
					>
						<ColorPalette
							colors={ [] }
							value={ option.default }
							onChange={ () => {} }
						/>
					</BaseControl>
				) ) }
			</PanelRow>
		</UpsellContainer>
	);
};

const Appearance = () => {
	const settings = useSelect( ( select ) => select( 'hyve' ).getSettings() );
	const { setSetting } = useDispatch( 'hyve' );

	const { createNotice } = useDispatch( 'core/notices' );

	const [ isSaving, setIsSaving ] = useState( false );

	// Push the free appearance options into the live test widget so the preview
	// updates as the admin edits, without waiting for a save.
	useEffect( () => {
		if ( ! window.hyveApp?.applyPreviewAppearance ) {
			return;
		}

		window.hyveApp.applyPreviewAppearance( {
			chatPosition: 'left' === settings.chat_position ? 'left' : 'right',
			showTimestamp: false !== settings.show_timestamp,
		} );
	}, [ settings.chat_position, settings.show_timestamp ] );

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

	return (
		<div className="col-span-6 xl:col-span-4">
			<Panel header={ __( 'Appearance Settings', 'hyve-lite' ) }>
				<PanelRow>
					<ToggleGroupControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						isBlock
						label={ __( 'Chat Position', 'hyve-lite' ) }
						help={ __(
							'Which side of the screen the chat button and window appear on.',
							'hyve-lite'
						) }
						value={
							'left' === settings.chat_position ? 'left' : 'right'
						}
						onChange={ ( value ) =>
							setSetting( 'chat_position', value )
						}
					>
						<ToggleGroupControlOption
							label={ __( 'Left', 'hyve-lite' ) }
							value="left"
						/>
						<ToggleGroupControlOption
							label={ __( 'Right', 'hyve-lite' ) }
							value="right"
						/>
					</ToggleGroupControl>
				</PanelRow>

				<PanelRow>
					<ToggleGroupControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						isBlock
						label={ __( 'Message Timestamp', 'hyve-lite' ) }
						value={ Boolean( settings.show_timestamp ?? true ) }
						onChange={ ( newValue ) =>
							setSetting( 'show_timestamp', Boolean( newValue ) )
						}
						help={ __(
							'Show the time each message was sent below the message.',
							'hyve-lite'
						) }
					>
						<ToggleGroupControlOption
							aria-label={ __(
								'Show message timestamp',
								'hyve-lite'
							) }
							label={ __( 'Show', 'hyve-lite' ) }
							showTooltip
							value={ true }
						/>
						<ToggleGroupControlOption
							aria-label={ __(
								'Hide message timestamp',
								'hyve-lite'
							) }
							label={ __( 'Hide', 'hyve-lite' ) }
							showTooltip
							value={ false }
						/>
					</ToggleGroupControl>
				</PanelRow>

				{ applyFilters(
					'hyve.appearance.options',
					<ProOptionsUpsell />,
					settings,
					setSetting,
					isSaving
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

export default Appearance;
