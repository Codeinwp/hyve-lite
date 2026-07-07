/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import { Button, Panel, PanelRow } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { PostsTable } from '../PostsTable';
import UpsellContainer from '../UpsellContainer';

const posts = Array.from( { length: 5 }, ( _, i ) => ( {
	id: i + 1,
	title: `document-${ i + 1 }.pdf`,
} ) );

const Documents = ( { setView } ) => {
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

						<h3>{ __( 'Documents', 'hyve-lite' ) }</h3>
					</div>

					<PanelRow>
						<p className="py-4">
							{ __(
								'Import PDF, Word, Markdown, Text, or CSV files from your Media Library into the Knowledge Base.',
								'hyve-lite'
							) }
						</p>

						<div className="relative pt-4 pb-8 overflow-x-auto">
							<UpsellContainer
								title={ __(
									'Document Import is a Premium feature',
									'hyve-lite'
								) }
								description={ __(
									'Upload PDF, Word, Markdown, Text, and CSV files and add their content to the Knowledge Base. Upgrade now!',
									'hyve-lite'
								) }
								campaign="document-import-feature"
							>
								<div className="flex gap-4 pb-4 flex-col">
									<div className="flex w-full items-end gap-4">
										<Button
											variant="secondary"
											onClick={ () => {} }
										>
											{ __(
												'Select Files',
												'hyve-lite'
											) }
										</Button>
									</div>
								</div>

								<PostsTable
									posts={ posts || [] }
									isLoading={ false }
									hasMore={ false }
									onFetch={ () => {} }
									actions={ [
										{
											label: __( 'Delete', 'hyve-lite' ),
											onClick: () => {},
											isBusy: [],
											variant: 'secondary',
											isDestructive: true,
										},
									] }
								/>
							</UpsellContainer>
						</div>
					</PanelRow>
				</Panel>
			</div>
		</>
	);
};

export default Documents;
