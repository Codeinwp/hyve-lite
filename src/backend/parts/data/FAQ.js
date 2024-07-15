/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import {
	Button,
	Panel,
	PanelRow
} from '@wordpress/components';

/**
 * Internal dependencies.
 */
import UpsellContainer from '../UpsellContainer';

const posts = [
	{
		question: __( 'How do I reset my password?', 'hyve' ),
		count: 5
	},
	{
		question: __( 'How do I change my email address?', 'hyve' ),
		count: 3
	},
	{
		question: __( 'How do I update my payment method?', 'hyve' ),
		count: 2
	},
	{
		question: __( 'How do I cancel my subscription?', 'hyve' ),
		count: 1
	},
	{
		question: __( 'How do I change my plan?', 'hyve' ),
		count: 1
	},
	{
		question: __( 'How do I update my billing information?', 'hyve' ),
		count: 1
	}
];

const Custom = () => {
	return (
		<div className="col-span-6 xl:col-span-4">
			<Panel
				header={ __( 'FAQ', 'hyve' ) }
			>
				<PanelRow>
					<p className="py-4">{ __( 'The FAQ captures frequently asked questions that went unanswered by our chatbot, providing you with a valuable insight into what your users are seeking. This feature allows you to review these queries and decide whether to incorporate them into your bot\'s knowledge base. By actively updating your FAQ, you can continuously refine your chatbot\'s ability to address user needs effectively and enhance their interactive experience. These aren\'t updated instantly.', 'hyve' ) }</p>

					<div className="relative pt-4 pb-8 overflow-x-auto">

						<UpsellContainer
							title={ __( 'FAQ is a Premium feature', 'hyve' ) }
							description={ __( 'Review unanswered questions, enhance your bot\'s knowledge base, and refine your users\' interactive experience. Upgrade now!', 'hyve' ) }
							campaign="faq-feature"
						>
							<div className="flex flex-col">
								<div className="bg-gray-50 px-6 py-3 text-left text-xs text-gray-700 uppercase">
									<div className="flex">
										<div className="flex-1">{ __( 'Title', 'hyve' ) }</div>
										<div className="w-1/6">{ __( 'Count', 'hyve' ) }</div>
										<div className="w-1/6 flex justify-center">{ __( 'Action', 'hyve' ) }</div>
									</div>
								</div>
								<div className="flex flex-col">
									{ posts?.map( ( post, key ) => (
										<div
											key={ key }
											className="flex items-center bg-white px-6 py-4 border-b text-sm text-gray-500"
										>
											<div className="flex-1 text-left rtl:text-right overflow-hidden">
												<span className="max-w-full text-ellipsis overflow-hidden">{ post.question }</span>
											</div>

											<div className="w-1/6">{ post.count }</div>

											<div className="w-1/6 text-center flex gap-4">
												<Button
													variant="secondary"
													onClick={ () => {} }
													className="w-20 justify-center"
												>
													{ __( 'Delete', 'hyve' ) }
												</Button>

												<Button
													variant="primary"
													onClick={ () => {} }
													className="w-20 justify-center"
												>
													{ __( 'Add', 'hyve' ) }
												</Button>
											</div>
										</div>
									) )}
								</div>
							</div>
						</UpsellContainer>
					</div>
				</PanelRow>
			</Panel>
		</div>
	);
};

export default Custom;
