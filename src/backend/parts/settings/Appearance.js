/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import {
	BaseControl,
	Button,
	ColorPalette,
	Panel,
	PanelRow,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOptionIcon as ToggleGroupControlOptionIcon,
} from '@wordpress/components';

import { applyFilters } from '@wordpress/hooks';
import { useMemo } from '@wordpress/element';

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

const Appearance = () => {
	const chatIconOptions = useMemo( () => {
		return applyFilters( 'hyve.appearance.chat-icons', [] );
	}, [] );

	return (
		<div className="col-span-6 xl:col-span-4">
			<Panel header={ __( 'Appearance Settings', 'hyve-lite' ) }>
				<UpsellContainer
					title={ __(
						'Appearance customization is a Premium feature',
						'hyve-lite'
					) }
					description={ __(
						'Customize the look and feel of your chat box with our Premium subscription. Upgrade now!',
						'hyve-lite'
					) }
					campaign="appearance-settings"
				>
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
								{ chatIconOptions?.map(
									( { icon, label, value } ) => {
										const CustomIcon = icon;
										return (
											<ToggleGroupControlOptionIcon
												key={ value }
												icon={
													<CustomIcon width="24" />
												}
												label={ label }
												value={ value }
											/>
										);
									}
								) }
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

					<PanelRow>
						<Button
							variant="primary"
							className="mt-2"
							onClick={ () => {} }
						>
							{ __( 'Save', 'hyve-lite' ) }
						</Button>
					</PanelRow>
				</UpsellContainer>
			</Panel>
		</div>
	);
};

export default Appearance;
