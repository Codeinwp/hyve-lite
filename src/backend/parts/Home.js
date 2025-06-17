/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import { Button, Panel, PanelRow } from '@wordpress/components';

import { dispatch, useDispatch, useSelect } from '@wordpress/data';

import { archive, brush, help } from '@wordpress/icons';
import { UsageCharts } from '../components/UsageChart';

const { setRoute: changeRoute } = dispatch( 'hyve' );

const STATUS = [
	{
		label: __( 'Sessions', 'hyve-lite' ),
		value: window.hyve?.stats?.threads,
		description: __(
			'Unique chat sessions created by your users.',
			'hyve-lite'
		),
	},
	{
		label: __( 'Messages', 'hyve-lite' ),
		value: window.hyve?.stats?.messages,
		description: __(
			'Total messages exchanged between users and Hyve.',
			'hyve-lite'
		),
	},
	{
		label: __( 'Knowledge Base', 'hyve-lite' ),
		value: Boolean( window.hyve.isQdrantActive )
			? window.hyve?.stats?.totalChunks
			: `${ window.hyve?.stats?.totalChunks } / ${ window.hyve?.chunksLimit }`,
		description: __( 'Current knowledge base chunks used.', 'hyve-lite' ),
		action: {
			label: __( 'Need more storage?', 'hyve-lite' ),
			action: () => changeRoute( 'integrations' ),
			condition:
				! Boolean( window.hyve.isQdrantActive ) &&
				400 < window.hyve?.stats?.totalChunks,
		},
	},
];

const Dashboard = () => {
	const { setRoute } = useDispatch( 'hyve' );

	const ACTIONS = [
		{
			label: __( 'Knowledge Base', 'hyve-lite' ),
			description: __(
				"In Knowledge Base, view and remove any Posts/Pages added to Hyve from the chat's data source.",
				'hyve-lite'
			),
			icon: archive,
			action: () => setRoute( 'data' ),
		},
		{
			label: __( 'Personalize', 'hyve-lite' ),
			description: __(
				"Customize Hyve's behavior and appearance to better suit your website and brand.",
				'hyve-lite'
			),
			icon: brush,
			action: () => setRoute( 'settings' ),
		},
		{
			label: __( 'Need help?', 'hyve-lite' ),
			description: __(
				'Check out our documentation or contact support for assistance.',
				'hyve-lite'
			),
			icon: help,
			action: () => window?.open( window?.hyve?.docs, '_blank' ),
		},
	];

	return (
		<div className="col-span-6 xl:col-span-4">
			<Panel header={ __( 'Dashboard', 'hyve-lite' ) }>
				<PanelRow>
					<h2 className="text-xl py-2">
						{ __( 'Overview', 'hyve-lite' ) }
					</h2>

					<div className="grid grid-cols-1 gap-5 sm:grid-cols-3 mt-4">
						{ STATUS.map(
							( { label, value, description, action } ) => (
								<div
									key={ label }
									className="bg-white overflow-hidden shadow-sm border-[0.5px] border-gray-300 border-solid rounded-md"
								>
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

											{ action && action?.condition && (
												<button
													className="text-xs pt-1 text-blue-500 cursor-pointer bg-transparent border-none p-0 text-left"
													onClick={ action?.action }
												>
													{ action?.label }
												</button>
											) }
										</dl>
									</div>
								</div>
							)
						) }
					</div>

					<h2 className="text-xl pt-6 pb-2">
						{ __( 'Get Started', 'hyve-lite' ) }
					</h2>

					<div className="grid grid-cols-1 gap-5 sm:grid-cols-3 my-4">
						{ ACTIONS.map(
							( { label, description, icon, action } ) => (
								<Button
									key={ label }
									className="bg-white h-auto text-left overflow-hidden shadow-sm border-[0.5px] border-gray-300 border-solid rounded-md cursor-pointer"
									onClick={ action }
								>
									<div className="px-4 py-5 sm:p-6">
										<dl>
											<dt className="w-8">{ icon }</dt>

											<dt className="text-sm leading-5 font-medium py-4">
												{ label } →
											</dt>

											<dt className="text-xs font-medium text-gray-500">
												{ description }
											</dt>
										</dl>
									</div>
								</Button>
							)
						) }
					</div>
				</PanelRow>
			</Panel>
			{ 0 < window.hyve?.chart?.data?.messages?.length && (
				<>
					<br />
					<Panel header={ __( 'Chat Usage', 'hyve-lite' ) }>
						<PanelRow>
							<UsageCharts chart={ window.hyve.chart } />
						</PanelRow>
					</Panel>
				</>
			) }
		</div>
	);
};

const Home = () => {
	const hasAPI = useSelect( ( select ) => select( 'hyve' ).hasAPI() );

	const { setRoute } = useDispatch( 'hyve' );

	if ( ! hasAPI ) {
		return (
			<div className="col-span-6 xl:col-span-4 relative">
				<Dashboard />

				<div className="w-full h-full absolute bg-white/75 flex justify-center items-center top-0">
					<div className="flex flex-col items-center max-w-lg gap-2 p-6 rounded-lg bg-white shadow-lg">
						<p className="py-2">
							{ __(
								'Welcome to Hyve! Designed to seamlessly integrate AI chat into your WordPress site, this plugin allows you to craft a personalized chat experience using your own posts and pages. Enjoy engaging with your website visitors through Hyve!',
								'hyve-lite'
							) }
						</p>

						<p className="py-2">
							{ __(
								"To begin using the Hyve plugin, you'll need an OpenAI API key. This key enables Hyve to communicate with OpenAI's powerful language models, ensuring you get the best possible responses.",
								'hyve-lite'
							) }
						</p>

						<div className="flex gap-4">
							<Button
								variant="primary"
								className="mt-2"
								onClick={ () => setRoute( 'advanced' ) }
							>
								{ __( 'Setup API Key', 'hyve-lite' ) }
							</Button>

							<Button
								variant="secondary"
								className="mt-2"
								href={ window?.hyve?.docs }
								target="_blank"
							>
								{ __( 'Documentation', 'hyve-lite' ) }
							</Button>
						</div>
					</div>
				</div>
			</div>
		);
	}

	return <Dashboard />;
};

export default Home;
