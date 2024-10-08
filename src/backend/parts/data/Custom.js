/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import {
	Button,
	Panel,
	PanelRow,
	SearchControl
} from '@wordpress/components';

/**
 * Internal dependencies.
 */
import PostsTable from '../PostsTable';
import UpsellContainer from '../UpsellContainer';

const posts = [
	{
		ID: 1,
		title: __( 'Halloween Limited Time Deal Information', 'hyve-lite' )
	},
	{
		ID: 2,
		title: __( 'What to do if my order is missing an item?', 'hyve-lite' )
	},
	{
		ID: 3,
		title: __( 'How do I return an item?', 'hyve-lite' )
	},
	{
		ID: 4,
		title: __( 'How do I track my order?', 'hyve-lite' )
	},
	{
		ID: 5,
		title: __( 'How do I change my delivery address?', 'hyve-lite' )
	},
	{
		ID: 6,
		title: __( 'How do I cancel my order?', 'hyve-lite' )
	}
];

const Custom = () => {
	return (
		<div className="col-span-6 xl:col-span-4">
			<Panel
				header={ __( 'Custom Data', 'hyve-lite' ) }
			>
				<PanelRow>
					<p className="py-4">{ __( 'Custom Data allows you to privately feed specific data directly into your chat bot without displaying this information on your public website. With this, you can equip your bot with unique, specialized knowledge that aligns with your business needs and customer queries.', 'hyve-lite' ) }</p>

					<div className="relative pt-4 pb-8 overflow-x-auto">
						<UpsellContainer
							title={ __( 'Custom Data is a Premium feature', 'hyve-lite' ) }
							description={ __( 'Privately feed specific data directly into your chatbot, equipping specialized knowledge that aligns with your business needs and customer queries. Upgrade now!', 'hyve-lite' ) }
							campaign="custom-data-feature"
						>
							<div className="flex gap-4 pb-4 flex-col">
								<div className="w-full">
									<SearchControl
										label={ __( 'Search for Posts', 'hyve-lite' ) }
										value=""
										onChange={ () => {} }
									/>
								</div>

								<div className="w-full flex justify-end">
									<Button
										variant="secondary"
										onClick={ () => {} }
									>
										{ __( 'Add New', 'hyve-lite' ) }
									</Button>
								</div>
							</div>

							<PostsTable
								posts={ posts }
								isLoading={ false }
								hasMore={ false }
								onFetch={ () => {} }
								onAction={ () => {} }
								actionLabel={ __( 'Edit', 'hyve-lite' ) }
								isBusy={ [] }
							/>
						</UpsellContainer>
					</div>
				</PanelRow>
			</Panel>
		</div>
	);
};

export default Custom;
