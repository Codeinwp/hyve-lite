/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';


import {
	BaseControl,
	Button,
	ColorPalette,
	Panel,
	PanelRow
} from '@wordpress/components';

/**
 * Internal dependencies.
 */
import UpsellContainer from '../UpsellContainer';

const colorOptions = [
	{
		label: __( 'Chat Background', 'hyve' ),
		value: 'chat_background',
		default: '#ffffff'
	},
	{
		label: __( 'Assistant Background', 'hyve' ),
		value: 'assistant_background',
		default: '#ecf1fb'
	},
	{
		label: __( 'User Background', 'hyve' ),
		value: 'user_background',
		default: '#1155cc'
	},
	{
		label: __( 'Icon Background', 'hyve' ),
		value: 'icon_background',
		default: '#1155cc'
	}
];

const Appearance = () => {
	return (
		<div className="col-span-6 xl:col-span-4">
			<Panel
				header={ __( 'Appearance Settings', 'hyve' ) }
			>
				<UpsellContainer
					title={ __( 'Appearance customization is a Premium feature', 'hyve' ) }
					description={ __( 'Customize the look and feel of your chat box with our Premium subscription. Upgrade now!', 'hyve' ) }
					campaign="appearance-settings"
				>
					<PanelRow>
						{ colorOptions.map( ( option ) => (
							<BaseControl
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
							{ __( 'Save', 'hyve' ) }
						</Button>
					</PanelRow>
				</UpsellContainer>
			</Panel>
		</div>
	);
};

export default Appearance;
