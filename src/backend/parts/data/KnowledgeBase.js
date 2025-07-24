/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import { Button, Panel, PanelRow } from '@wordpress/components';

import { useState } from '@wordpress/element';

import { applyFilters } from '@wordpress/hooks';

/**
 * Internal dependencies.
 */
import { KNOWLEDGE_BASE } from '../../route';
import { KnowledgeBaseOptions } from '../../components/KnowledgeBaseOptions';

const KnowledgeBase = () => {
	const [ view, setView ] = useState( null );

	const SOURCES = applyFilters( 'hyve.data', KNOWLEDGE_BASE );

	if ( view ) {
		const { component: Component } = SOURCES[ view ];
		return <Component setView={ setView } />;
	}

	return (
		<div className="col-span-6 xl:col-span-4">
			<Panel header={ __( 'Knowledge Base', 'hyve-lite' ) }>
				<PanelRow>
					<p className="py-4">
						{ __(
							"A list of all the content that has been added to the Knowledge Base. It's the foundation that supports your chat assistant, enabling it to provide accurate and insightful responses.",
							'hyve-lite'
						) }
					</p>

					<div className="grid grid-cols-1 gap-5 sm:grid-cols-2 my-4">
						{ Object.keys( SOURCES ).map( ( key ) => {
							const {
								label,
								description,
								icon,
								isPro = false,
							} = SOURCES[ key ];
							return (
								<Button
									key={ label }
									className="bg-white h-auto text-left overflow-hidden shadow-sm border-[0.5px] border-gray-300 border-solid rounded-md cursor-pointer flex items-start p-2"
									onClick={ () => {
										setView( key );
										window.hyveTrk?.add( {
											feature: 'knowledge-base-source',
											featureValue: key,
										} );
									} }
								>
									<div className="px-4 py-5 w-full sm:p-6">
										<dl>
											<dt className="flex flex-row justify-between">
												<div className="w-8">
													{ icon }
												</div>

												{ isPro && (
													<div className="text-xs h-6 py-1 px-3 bg-blue-500 text-white uppercase font-bold rounded-full">
														{ __(
															'Pro',
															'hyve-lite'
														) }
													</div>
												) }
											</dt>

											<dt className="text-sm leading-5 font-medium py-4">
												{ label } →
											</dt>

											<dt className="text-xs font-medium text-gray-500">
												{ description }
											</dt>
										</dl>
									</div>
								</Button>
							);
						} ) }
					</div>
				</PanelRow>
			</Panel>
			<br />
			<KnowledgeBaseOptions />
		</div>
	);
};

export default KnowledgeBase;
