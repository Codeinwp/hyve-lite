/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import {
	Button,
	TextControl,
	Panel,
	PanelRow
} from '@wordpress/components';

/**
 * Internal dependencies.
 */
import PostsTable from '../PostsTable';
import UpsellContainer from '../UpsellContainer';

const posts = [
	{
		id: 1,
		title: 'https://example.com/page1'
	},
	{
		id: 2,
		title: 'https://example.com/page2'
	},
	{
		id: 3,
		title: 'https://example.com/page3'
	},
	{
		id: 4,
		title: 'https://example.com/page4'
	},
	{
		id: 5,
		title: 'https://example.com/page5'
	}
];

const URLCrawler = ({ setView }) => {
	return (
		<>
			<div className="col-span-6 xl:col-span-4">
				<Panel>
					<div className="items-center gap-4 flex shrink-0 h-12 px-4 py-0 border-b-[#ddd] border-b border-solid">
						<Button
							icon="arrow-left-alt"
							hideLabel
							label={ __( 'Back', 'hyve' ) }
							onClick={ () => setView( null ) }
						/>

						<h3>{ __( 'Website URL', 'hyve' ) }</h3>
					</div>

					<PanelRow>
						<p className="py-4">{ __( 'This page allows you to add URLs to the Knowledge Base. You can add URLs to the Knowledge Base by entering the URL in the field below and clicking the "Crawl URL" button.', 'hyve' ) }</p>

						<div className="relative pt-4 pb-8 overflow-x-auto">
							<UpsellContainer
								title={ __( 'URL Crawling is a Premium feature', 'hyve-lite' ) }
								description={ __( 'Use this tool to crawl a website and add its content to the Knowledge Base using the sitemap. Upgrade now!', 'hyve-lite' ) }
								campaign="website-crawling-feature"
							>
								<div className="flex gap-4 pb-4 flex-col">
									<div className="flex w-full items-end gap-4">
										<TextControl
											label={ __( 'Website URL', 'hyve' ) }
											placeholder={ 'https://' }
											type="url"
											className="w-full"
											value=""
											onChange={ () => {} }
										/>

										<Button
											variant="secondary"
											onClick={ () => {} }
										>
											{ __( 'Crawl URL', 'hyve' ) }
										</Button>
									</div>
								</div>

								<PostsTable
									posts={ posts || [] }
									isLoading={ false }
									hasMore={ false }
									onFetch={ () => {} }
									onAction={ () => {} }
									isBusy={ [] }
									actionProps={{
										variant: 'secondary',
										isDestructive: true
									}}
									actionLabel={ __( 'Delete', 'hyve' ) }
								/>
							</UpsellContainer>
						</div>
					</PanelRow>
				</Panel>
			</div>
		</>
	);
};

export default URLCrawler;
