/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import {
	Button,
	Panel,
	PanelRow
} from '@wordpress/components';

import {
	useDispatch,
	useSelect
} from '@wordpress/data';

import {
	archive,
	brush,
	help
} from '@wordpress/icons';

const STATUS = [
	{
		label: __( 'Sessions', 'hyve' ),
		value: hyve?.stats?.threads,
		description: __( 'Unique chat sessions created by your users.', 'hyve' )
	},
	{
		label: __( 'Messages', 'hyve' ),
		value: hyve?.stats?.messages,
		description: __( 'Total messages exchanged between users and Hyve.', 'hyve' )
	},
	{
		label: __( 'Knowledge Base', 'hyve' ),
		value: `${ hyve?.stats?.totalChunks } / ${ hyve?.chunksLimit }`,
		description: __( 'Current knowledge base chunks used.', 'hyve' )
	}
];

const Home = () => {
	const hasAPI = useSelect( ( select ) => select( 'hyve' ).hasAPI() );

	const { setRoute } = useDispatch( 'hyve' );

	if ( ! hasAPI ) {
		return (
			<div className="col-span-6 xl:col-span-4">
				<Panel
					header={ __( 'Dashboard', 'hyve' ) }
				>
					<PanelRow>
						<div className="hyve-video">
							<iframe width="560" height="315" className="py-4" src="https://www.youtube.com/embed/av2sVbWSG3c?si=E9DEEsv00-guyn1c" title="YouTube video player" frameBorder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerPolicy="strict-origin-when-cross-origin" allowFullScreen></iframe>
						</div>

						<p className="py-2">{ __( 'Welcome to Hyve! Designed to seamlessly integrate AI chat into your WordPress site, this plugin allows you to craft a personalized chat experience using your own posts and pages. Enjoy engaging with your website visitors through Hyve!', 'hyve' ) }</p>

						<p className="py-2">{ __( 'To begin using the Hyve plugin, you\'ll need an OpenAI API key. This key enables Hyve to communicate with OpenAI\'s powerful language models, ensuring you get the best possible responses.', 'hyve' ) }</p>

						<div className="flex gap-4">
							<Button
								variant="primary"
								className="mt-2"
								onClick={ () => setRoute( 'advanced' ) }
							>
								{ __( 'Setup API Key', 'hyve' ) }
							</Button>

							<Button
								variant="secondary"
								className="mt-2"
								href={ window?.hyve?.docs }
								target="_blank"
							>
								{ __( 'Documentation', 'hyve' ) }
							</Button>
						</div>
					</PanelRow>
				</Panel>
			</div>
		);
	}

	const ACTIONS = [
		{
			label: __( 'Knowledge Base', 'hyve' ),
			description: __( 'In Knowledge Base, view and remove any Posts/Pages added to Hyve from the chat\'s data source.', 'hyve' ),
			icon: archive,
			action: () => setRoute( 'data' )
		},
		{
			label: __( 'Personalize', 'hyve' ),
			description: __( 'Customize Hyve\'s behavior and appearance to better suit your website and brand.', 'hyve' ),
			icon: brush,
			action: () => setRoute( 'settings' )
		},
		{
			label: __( 'Need help?', 'hyve' ),
			description: __( 'Check out our documentation or contact support for assistance.', 'hyve' ),
			icon: help,
			action: () => window?.open( window?.hyve?.docs, '_blank' )
		}
	];

	return (
		<div className="col-span-6 xl:col-span-4">
			<Panel
				header={ __( 'Dashboard', 'hyve' ) }
			>
				<PanelRow>
					<h2 className="text-xl py-2">
						{ __( 'Overview', 'hyve' ) }
					</h2>

					<div className="grid grid-cols-1 gap-5 sm:grid-cols-3 mt-4">
						{ STATUS.map( ({ label, value, description }) => (
							<div key={ label } className="bg-white overflow-hidden shadow border-[0.5px] border-gray-300 border-solid rounded-md">
								<div className="px-4 py-5 sm:p-6">
									<dl>
										<dt className="text-sm leading-5 font-medium text-gray-500 truncate">
											{ label }
										</dt>

										<dd className="my-1 text-2xl leading-9 font-semibold">
											{ value }
										</dd>

										<dt className="text-xs text-gray-500">
											{ description }
										</dt>
									</dl>
								</div>
							</div>
						) ) }
					</div>

					<h2 className="text-xl pt-6 pb-2">
						{ __( 'Get Started', 'hyve' ) }
					</h2>

					<div className="grid grid-cols-1 gap-5 sm:grid-cols-3 my-4">
						{ ACTIONS.map( ({ label, description, icon, action }) => (
							<Button
								key={ label }
								className="bg-white h-auto text-left overflow-hidden shadow border-[0.5px] border-gray-300 border-solid rounded-md cursor-pointer"
								onClick={ action }
							>
								<div className="px-4 py-5 sm:p-6">
									<dl>
										<dt className="w-8">
											{ icon }
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
						) )}
					</div>
				</PanelRow>
			</Panel>
		</div>
	);
};

export default Home;
