/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import {
	useDispatch,
	useSelect
} from '@wordpress/data';

import {
	Button,
	Icon,
	Panel
} from '@wordpress/components';

import { chevronDown } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { ROUTE as MENU_ITEMS } from '../route';

const Sidebar = () => {
	const route = useSelect( ( select ) => select( 'hyve' ).getRoute() );
	const hasAPI = useSelect( ( select ) => select( 'hyve' ).hasAPI() );

	const { setRoute } = useDispatch( 'hyve' );

	return (
		<div className="col-span-6 xl:col-span-2">
			<Panel
				header={ __( 'Menu', 'hyve' ) }
			>
				<div className="max-w-2xl mx-auto">
					<aside aria-label="Sidebar">
						<div className="px-3 py-4 overflow-y-auto rounded bg-white">
							<ul className="space-y-2">
								{ Object.keys( MENU_ITEMS ).map( ( key ) => (
									<li key={ key }>
										<Button
											onClick={ () => setRoute( key ) }
											disabled={ ( ! hasAPI && false !== MENU_ITEMS[key]?.disabled ) }
											className={ classnames(
												'flex items-center p-2 h-16 w-full text-base font-normal text-gray-900 hover:text-gray-900 rounded-lg hover:bg-gray-100',
												{ 'bg-gray-100 hover:text-gray-900': route === key }
											) }
										>
											<Icon
												icon={ MENU_ITEMS[key].icon }
												className="w-6 h-6 text-gray-500 transition duration-75"
											/>
											<span className="ml-3">{ MENU_ITEMS[key].label }</span>

											{ MENU_ITEMS[key].children && (
												<Icon
													icon={ chevronDown }
													className="w-6 h-6 ml-auto text-gray-500 transition duration-75"
												/>
											)}
										</Button>

										{ ( ( MENU_ITEMS[key]?.children && key === route ) || ( MENU_ITEMS[key]?.children && Object.keys( MENU_ITEMS[key]?.children ).includes( route ) ) ) && (
											<ul className="py-2 space-y-2">
												{ Object.keys( MENU_ITEMS[key].children ).map( ( childKey ) => (
													<li key={ childKey }>
														<Button
															onClick={ () => setRoute( childKey ) }
															disabled={ ( ! hasAPI && false !== MENU_ITEMS[key].children[childKey]?.disabled ) }
															className={ classnames(
																'flex items-center w-full h-12 p-2 text-base font-normal text-gray-900 hover:text-gray-900 transition duration-75 rounded-lg group hover:bg-gray-100 pl-11',
																{ 'bg-gray-100 hover:text-gray-900': route === childKey }
															) }
														>
															{ MENU_ITEMS[key].children[childKey].label }
														</Button>
													</li>
												) )}
											</ul>
										)}
									</li>
								) )}
							</ul>
						</div>
					</aside>
				</div>
			</Panel>
		</div>
	);
};

export default Sidebar;
