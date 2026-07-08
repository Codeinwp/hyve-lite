/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import {
	Button,
	ColorPicker,
	Dropdown,
	TextControl,
	ToggleControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOptionIcon as ToggleGroupControlOptionIcon,
} from '@wordpress/components';

import { MediaUpload } from '@wordpress/media-utils';

import { useDispatch, useSelect } from '@wordpress/data';

import { useEffect, useMemo } from '@wordpress/element';

import { applyFilters } from '@wordpress/hooks';

/**
 * Internal dependencies.
 */
import { setUtm } from '../../utils';
import Card from '../components/Card';
import Chip from '../components/Chip';
import FieldRow from '../components/FieldRow';
import useSaveSettings from '../data/useSaveSettings';

const COLOR_OPTIONS = [
	{
		label: __( 'Chat background', 'hyve-lite' ),
		value: 'chat_background',
	},
	{
		label: __( 'Assistant background', 'hyve-lite' ),
		value: 'assistant_background',
	},
	{
		label: __( 'User background', 'hyve-lite' ),
		value: 'user_background',
	},
	{
		label: __( 'Icon background', 'hyve-lite' ),
		value: 'icon_background',
	},
];

const DEFAULT_COLORS = {
	chat_background: '#ffffff',
	assistant_background: '#ecf1fb',
	user_background: '#1155cc',
	icon_background: '#1155cc',
};

/**
 * Mirror of the PHP `is_dark_color()` so the live preview applies the same
 * light/dark contrast classes the real frontend does.
 *
 * @param {string} hex Hex color.
 * @return {boolean} True if the color is dark.
 */
const isDarkColor = ( hex ) => {
	if ( ! hex ) {
		return false;
	}

	let color = hex.replace( '#', '' );

	if ( 3 === color.length ) {
		color = color
			.split( '' )
			.map( ( c ) => c + c )
			.join( '' );
	}

	if ( 6 !== color.length ) {
		return false;
	}

	const r = parseInt( color.slice( 0, 2 ), 16 );
	const g = parseInt( color.slice( 2, 4 ), 16 );
	const b = parseInt( color.slice( 4, 6 ), 16 );

	if ( Number.isNaN( r ) || Number.isNaN( g ) || Number.isNaN( b ) ) {
		return false;
	}

	return 0.2126 * r + 0.7152 * g + 0.0722 * b < 128;
};

const ProChip = () => (
	<Chip tone="pro" dot={ false }>
		{ __( 'Pro', 'hyve-lite' ) }
	</Chip>
);

const ColorTile = ( { label, value, disabled, onChange } ) => {
	const tile = (
		<>
			<span
				className="hyve-next-tile__swatch"
				style={ { background: value } }
			/>
			<span className="hyve-next-tile__meta">
				<span className="hyve-next-tile__name">{ label }</span>
				<span className="hyve-next-tile__hex">
					{ value.toUpperCase() }
				</span>
			</span>
		</>
	);

	if ( disabled ) {
		return (
			<button type="button" className="hyve-next-tile" disabled>
				{ tile }
			</button>
		);
	}

	return (
		<Dropdown
			className="hyve-next-tile-wrap"
			popoverProps={ { placement: 'bottom-start' } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<button
					type="button"
					className="hyve-next-tile"
					onClick={ onToggle }
					aria-expanded={ isOpen }
					aria-label={ label }
				>
					{ tile }
				</button>
			) }
			renderContent={ () => (
				<ColorPicker
					color={ value }
					enableAlpha={ false }
					onChange={ ( newColor ) =>
						onChange(
							'string' === typeof newColor
								? newColor
								: newColor?.hex
						)
					}
				/>
			) }
		/>
	);
};

const ChatAppearance = () => {
	const isPro = Boolean( window.hyve?.license );

	const { settings, isSaving, save } = useSaveSettings();

	const { setSetting } = useDispatch( 'hyve' );

	const chatIconOptions = useMemo(
		() => applyFilters( 'hyve.appearance.chat-icons', [] ),
		[]
	);

	const chatIcon = useMemo(
		() => settings?.chat_icon || {},
		[ settings?.chat_icon ]
	);

	const mediaId = 'media' === chatIcon.type ? Number( chatIcon.value ) : 0;

	const mediaUrl = useSelect(
		( select ) =>
			mediaId ? select( 'core' ).getMedia( mediaId )?.source_url : null,
		[ mediaId ]
	);

	const colors = useMemo(
		() => ( { ...DEFAULT_COLORS, ...( settings.colors || {} ) } ),
		[ settings.colors ]
	);

	// Push edits into the floating test widget so it previews live, before any
	// save.
	useEffect( () => {
		if ( ! window.hyveApp?.applyPreviewAppearance ) {
			return;
		}

		const colorsDark = {};
		Object.keys( colors ).forEach( ( key ) => {
			colorsDark[ key ] = isDarkColor( colors[ key ] );
		} );

		const icon = { ...chatIcon };
		if ( 'media' === chatIcon.type && mediaUrl ) {
			icon.url = mediaUrl;
		}

		window.hyveApp.applyPreviewAppearance( {
			chatPosition: 'left' === settings.chat_position ? 'left' : 'right',
			showTimestamp: false !== settings.show_timestamp,
			chatName: settings.chat_name || '',
			colors,
			colorsDark,
			chatIcon: icon,
		} );
	}, [
		settings.chat_position,
		settings.show_timestamp,
		settings.chat_name,
		colors,
		chatIcon,
		mediaUrl,
	] );

	const setIcon = ( value ) => {
		const nextIcon = { type: 'svg', value };

		if ( 'default' === value ) {
			nextIcon.type = '';
			nextIcon.value = '';
		}

		setSetting( 'chat_icon', nextIcon );
	};

	return (
		<>
			{ window.hyveApp?.applyPreviewAppearance && (
				<div className="hyve-next-notice">
					<div className="hyve-next-notice__body">
						<strong className="hyve-next-notice__title">
							{ __(
								'The chat in the corner of this page is your live preview',
								'hyve-lite'
							) }
						</strong>
						<p className="hyve-next-notice__text">
							{ __(
								'Changes below apply to it as you type, before you save.',
								'hyve-lite'
							) }
						</p>
					</div>
				</div>
			) }

			<Card
				title={ __( 'Widget', 'hyve-lite' ) }
				footer={
					<Button
						variant="primary"
						isBusy={ isSaving }
						disabled={ isSaving }
						onClick={ save }
					>
						{ __( 'Save changes', 'hyve-lite' ) }
					</Button>
				}
			>
				<FieldRow
					label={
						<>
							{ __( 'Assistant name', 'hyve-lite' ) }{ ' ' }
							{ ! isPro && <ProChip /> }
						</>
					}
					description={ __(
						'The name shown in the chat header. Leave empty to use the default.',
						'hyve-lite'
					) }
				>
					<TextControl
						__nextHasNoMarginBottom
						hideLabelFromVision
						label={ __( 'Assistant name', 'hyve-lite' ) }
						value={ settings.chat_name || '' }
						disabled={ ! isPro || isSaving }
						onChange={ ( value ) =>
							setSetting( 'chat_name', value )
						}
					/>
				</FieldRow>

				<FieldRow
					label={ __( 'Position', 'hyve-lite' ) }
					description={ __(
						'Which side of the screen the chat button and window appear on.',
						'hyve-lite'
					) }
				>
					<ToggleGroupControl
						__nextHasNoMarginBottom
						hideLabelFromVision
						isBlock
						label={ __( 'Position', 'hyve-lite' ) }
						value={
							'left' === settings.chat_position ? 'left' : 'right'
						}
						disabled={ isSaving }
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
				</FieldRow>

				<FieldRow
					label={ __( 'Timestamps', 'hyve-lite' ) }
					description={ __(
						'Show the time each message was sent below the message.',
						'hyve-lite'
					) }
				>
					<ToggleControl
						__nextHasNoMarginBottom
						label={
							Boolean( settings.show_timestamp ?? true )
								? __( 'Enabled', 'hyve-lite' )
								: __( 'Disabled', 'hyve-lite' )
						}
						checked={ Boolean( settings.show_timestamp ?? true ) }
						disabled={ isSaving }
						onChange={ ( value ) =>
							setSetting( 'show_timestamp', Boolean( value ) )
						}
					/>
				</FieldRow>

				{ isPro && 0 < chatIconOptions?.length && (
					<FieldRow
						label={ __( 'Launcher icon', 'hyve-lite' ) }
						description={ __(
							'Pick a built-in icon for the chat button.',
							'hyve-lite'
						) }
						wide
					>
						<ToggleGroupControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							hideLabelFromVision
							isBlock
							className="hyve-next-icon-group"
							label={ __( 'Launcher icon', 'hyve-lite' ) }
							value={
								'svg' === chatIcon.type
									? chatIcon.value
									: 'default'
							}
							disabled={ isSaving }
							onChange={ setIcon }
						>
							{ chatIconOptions.map(
								( { icon, label, value } ) => {
									const CustomIcon = icon;
									return (
										<ToggleGroupControlOptionIcon
											key={ value }
											icon={ <CustomIcon width="24" /> }
											label={ label }
											value={ value }
										/>
									);
								}
							) }
						</ToggleGroupControl>
					</FieldRow>
				) }

				<FieldRow
					label={
						<>
							{ __( 'Custom icon image', 'hyve-lite' ) }{ ' ' }
							{ ! isPro && <ProChip /> }
						</>
					}
					description={ __(
						'Use an image from your Media Library for the chat button and header avatar.',
						'hyve-lite'
					) }
				>
					{ mediaUrl && (
						<img
							src={ mediaUrl }
							alt=""
							className="hyve-next-icon-preview"
						/>
					) }
					<MediaUpload
						allowedTypes={ [ 'image' ] }
						value={ mediaId }
						onSelect={ ( media ) =>
							setSetting( 'chat_icon', {
								type: 'media',
								value: media.id,
							} )
						}
						render={ ( { open } ) => (
							<div className="hyve-next-buttons">
								<Button
									variant="secondary"
									disabled={ ! isPro || isSaving }
									onClick={ open }
								>
									{ mediaUrl
										? __( 'Replace image', 'hyve-lite' )
										: __( 'Select image', 'hyve-lite' ) }
								</Button>
								{ 'media' === chatIcon.type && (
									<Button
										variant="tertiary"
										isDestructive
										disabled={ ! isPro || isSaving }
										onClick={ () =>
											setSetting( 'chat_icon', {
												type: '',
												value: '',
											} )
										}
									>
										{ __( 'Remove', 'hyve-lite' ) }
									</Button>
								) }
							</div>
						) }
					/>
				</FieldRow>

				<FieldRow
					label={
						<>
							{ __( 'Colors', 'hyve-lite' ) }{ ' ' }
							{ ! isPro && <ProChip /> }
						</>
					}
					description={ __(
						'Chat, assistant, user and launcher backgrounds.',
						'hyve-lite'
					) }
				>
					<div className="hyve-next-tiles">
						{ COLOR_OPTIONS.map( ( option ) => (
							<ColorTile
								key={ option.value }
								label={ option.label }
								value={ colors[ option.value ] }
								disabled={ ! isPro || isSaving }
								onChange={ ( color ) =>
									setSetting( 'colors', {
										...settings.colors,
										[ option.value ]: color,
									} )
								}
							/>
						) ) }
					</div>
					{ isPro && (
						<p className="hyve-next-field__hint">
							<Button
								variant="link"
								disabled={ isSaving }
								onClick={ () =>
									setSetting( 'colors', {
										...DEFAULT_COLORS,
									} )
								}
							>
								{ __( 'Reset to defaults', 'hyve-lite' ) }
							</Button>
						</p>
					) }
				</FieldRow>

				{ ! isPro && (
					<div className="hyve-next-act__upsell">
						<strong>
							{ __(
								'Make the chat match your brand',
								'hyve-lite'
							) }
						</strong>
						<p>
							{ __(
								'A custom name, icon and colors are part of Hyve Pro.',
								'hyve-lite'
							) }
						</p>
						<Button
							variant="primary"
							href={ setUtm(
								window.hyve?.pro,
								'appearance-settings'
							) }
							target="_blank"
						>
							{ __( 'Unlock with Pro', 'hyve-lite' ) }
						</Button>
					</div>
				) }
			</Card>
		</>
	);
};

export default ChatAppearance;
