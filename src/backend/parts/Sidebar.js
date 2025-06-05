/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import { useDispatch, useSelect } from '@wordpress/data';

import { Button, Icon, Panel } from '@wordpress/components';

import { applyFilters } from '@wordpress/hooks';

import { chevronDown } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { ROUTE_TREE } from '../route';
import { setUtm } from '../utils';

const Sidebar = () => {
	const route = useSelect( ( select ) => select( 'hyve' ).getRoute() );
	const hasAPI = useSelect( ( select ) => select( 'hyve' ).hasAPI() );

	const { setRoute } = useDispatch( 'hyve' );

	const MENU_ITEMS = applyFilters( 'hyve.route', ROUTE_TREE );

	return (
		<div className="col-span-6 xl:col-span-2">
			<Panel header={ __( 'Menu', 'hyve-lite' ) }>
				<div className="max-w-2xl mx-auto">
					<aside aria-label="Sidebar">
						<div className="px-3 py-4 overflow-y-auto rounded-sm bg-white">
							<ul className="space-y-2">
								{ Object.keys( MENU_ITEMS ).map( ( key ) => (
									<li key={ key }>
										<Button
											onClick={ () => setRoute( key ) }
											disabled={
												! hasAPI &&
												false !==
													MENU_ITEMS[ key ]?.disabled
											}
											className={ classnames(
												'flex items-center p-2 h-16 w-full text-base font-normal text-gray-900 hover:text-gray-900 rounded-lg hover:bg-gray-100',
												{
													'bg-gray-100 hover:text-gray-900':
														route === key,
												}
											) }
										>
											<Icon
												icon={ MENU_ITEMS[ key ].icon }
												className="w-6 h-6 text-gray-500 transition duration-75"
											/>
											<span className="ml-3">
												{ MENU_ITEMS[ key ].label }
											</span>

											{ MENU_ITEMS[ key ].children && (
												<Icon
													icon={ chevronDown }
													className="w-6 h-6 ml-auto text-gray-500 transition duration-75"
												/>
											) }
										</Button>

										{ ( ( MENU_ITEMS[ key ]?.children &&
											key === route ) ||
											( MENU_ITEMS[ key ]?.children &&
												Object.keys(
													MENU_ITEMS[ key ]?.children
												).includes( route ) ) ) && (
											<ul className="py-2 space-y-2">
												{ Object.keys(
													MENU_ITEMS[ key ].children
												).map( ( childKey ) => (
													<li key={ childKey }>
														<Button
															onClick={ () =>
																setRoute(
																	childKey
																)
															}
															disabled={
																! hasAPI &&
																false !==
																	MENU_ITEMS[
																		key
																	].children[
																		childKey
																	]?.disabled
															}
															className={ classnames(
																'flex items-center justify-between w-full h-12 p-2 text-base font-normal text-gray-900 hover:text-gray-900 transition duration-75 rounded-lg group hover:bg-gray-100 pl-11',
																{
																	'bg-gray-100 hover:text-gray-900':
																		route ===
																		childKey,
																}
															) }
														>
															{
																MENU_ITEMS[
																	key
																].children[
																	childKey
																].label
															}

															{ MENU_ITEMS[ key ]
																.children[
																childKey
															].isPro && (
																<div className="text-xs py-1 px-3 bg-blue-500 text-white uppercase font-bold rounded-full">
																	{ __(
																		'Pro',
																		'hyve-lite'
																	) }
																</div>
															) }
														</Button>
													</li>
												) ) }
											</ul>
										) }
									</li>
								) ) }
							</ul>
						</div>
					</aside>
				</div>
			</Panel>

			{ hasAPI && ! window?.hyve?.license && (
				<>
					<br />

					<Panel>
						<div className="flex flex-col items-center justify-center py-8 px-4 rounded-lg">
							<div className="text-center">
								<h3 className="text-lg font-semibold text-gray-800">
									{ __( 'Upgrade to Premium', 'hyve-lite' ) }
								</h3>
								<p className="text-sm text-gray-500">
									{ __(
										'Unlock powerful features and enhance your chatbot experience.',
										'hyve-lite'
									) }
								</p>
							</div>

							<Button
								variant="primary"
								className="mt-4"
								target="_blank"
								href={ setUtm(
									window?.hyve?.pro,
									'sidebar-banner'
								) }
							>
								{ __( 'Learn More', 'hyve-lite' ) }
							</Button>
						</div>
					</Panel>
				</>
			) }
		</div>
	);
};

export default Sidebar;
