/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

// eslint-disable-next-line import/no-extraneous-dependencies
import { capitalize } from 'lodash';

import { Button, Panel, PanelRow } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import UpsellContainer from '../UpsellContainer';

const posts = {
	1: {
		url: 'https://example.com/sitemap.xml',
		status: 'queued',
	},
	2: {
		url: 'https://example.com/sitemap.xml',
		status: 'completed',
	},
	3: {
		url: 'https://example.com/sitemap.xml',
		status: 'completed',
	},
};

const SitemapCrawler = ( { setView } ) => {
	return (
		<>
			<div className="col-span-6 xl:col-span-4">
				<Panel>
					<div className="items-center gap-4 flex shrink-0 h-12 px-4 py-0 border-b-[#ddd] border-b border-solid">
						<Button
							icon="arrow-left-alt"
							hideLabel
							label={ __( 'Back', 'hyve-lite' ) }
							onClick={ () => setView( null ) }
						/>

						<h3>{ __( 'Sitemap', 'hyve-lite' ) }</h3>
					</div>

					<PanelRow>
						<p className="py-4">
							{ __(
								'Use this tool to crawl a website and add its content to the Knowledge Base using the sitemap.',
								'hyve-lite'
							) }
						</p>

						<div className="relative pt-4 pb-8 overflow-x-auto">
							<UpsellContainer
								title={ __(
									'Sitemap Crawling is a Premium feature',
									'hyve-lite'
								) }
								description={ __(
									'Use this tool to crawl a website and add its content to the Knowledge Base using the sitemap. Upgrade now!',
									'hyve-lite'
								) }
								campaign="sitemap-crawling-feature"
							>
								<div className="flex gap-4 pb-4 flex-col">
									<div className="w-full flex justify-end">
										<Button
											variant="primary"
											onClick={ () => {} }
										>
											{ __( 'Add Sitemap', 'hyve-lite' ) }
										</Button>
									</div>
								</div>

								<div className="flex flex-col">
									<div className="bg-gray-50 px-6 py-3 text-left text-xs text-gray-700 uppercase">
										<div className="flex">
											<div className="flex-1">
												{ __(
													'Sitemap URL',
													'hyve-lite'
												) }
											</div>
											<div className="w-1/6">
												{ __( 'Status', 'hyve-lite' ) }
											</div>
											<div className="w-1/6 flex justify-center">
												{ __( 'Action', 'hyve-lite' ) }
											</div>
										</div>
									</div>

									<div className="flex flex-col">
										{ Object.keys( posts ).map(
											( post ) => (
												<div
													key={ post }
													className="flex items-center bg-white px-6 py-4 border-b text-sm text-gray-500"
												>
													<div className="flex-1 text-left rtl:text-right overflow-hidden">
														<span className="max-w-full text-ellipsis overflow-hidden">
															{
																posts[ post ]
																	.url
															}
														</span>
													</div>

													<div className="w-1/6">
														{ capitalize(
															posts[ post ].status
														) }
													</div>

													<div className="w-1/6 flex justify-center">
														<Button
															onClick={ () => {} }
															variant="secondary"
															className="w-20 justify-center"
														>
															{ __(
																'Details',
																'hyve-lite'
															) }
														</Button>
													</div>
												</div>
											)
										) }
									</div>
								</div>
							</UpsellContainer>
						</div>
					</PanelRow>
				</Panel>
			</div>
		</>
	);
};

export default SitemapCrawler;
