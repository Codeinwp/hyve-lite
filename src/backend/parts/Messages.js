/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import {
	Button,
	Modal,
	Panel,
	PanelRow,
	Spinner
} from '@wordpress/components';

import {
	useEffect,
	useState
} from '@wordpress/element';

import { applyFilters } from '@wordpress/hooks';

import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies.
 */
import UpsellContainer from './UpsellContainer';

const LoadMore = ({ onClick }) => (
	<Button
		onClick={ onClick }
		className="flex items-center justify-center p-4 h-auto w-full text-base font-normal text-gray-900 hover:text-gray-900 hover:bg-gray-100 border-t-gray-300 border-t-[0.5px] border-solid"
	>
		<div className="flex flex-row gap-1">
			{ __( 'Load more', 'hyve' ) }
		</div>
	</Button>
);

const Messages = () => {
	const [ posts, setPosts ] = useState([]);
	const [ selectedPost, setSelectedPost ] = useState( null );
	const [ hasMore, setHasMore ] = useState( false );
	const [ isUpsellOpen, setUpsellOpen ] = useState( false );
	const [ isLoading, setLoading ] = useState( true );

	const fetchPosts = async() => {
		setLoading( true );

		const response = await apiFetch({
			path: addQueryArgs( `${ window.hyve.api }/threads`, {
				offset: posts?.length || 0
			})
		});

		setLoading( false );
		setPosts( posts.concat( response.posts ) );
		setHasMore( response.more );
	};

	useEffect( () => {
		fetchPosts();
	}, []);

	return (
		<div className="col-span-6 xl:col-span-4">
			{ isUpsellOpen && (
				<Modal
					onRequestClose={ () => setUpsellOpen( false ) }
					className="md:max-w-3xl md:w-full overflow-hidden"
				>
					<UpsellContainer
						title={ __( 'Message History is a Premium feature', 'hyve' ) }
						description={ __( 'Upgrade to Hyve Premium to unlock entire Message History feature and many more.', 'hyve' ) }
						campaign="messages-feature"
					>
						<img
							className="border-t-gray-300 border-t-[0.5px] border-x-0 border-b-0 border-solid"
							src={ `${ window?.hyve?.assets?.images }threads.png` }
						/>
					</UpsellContainer>
				</Modal>
			) }

			<Panel
				header={ __( 'Messages', 'hyve' ) }
			>
				<PanelRow>
					<p className="py-4">{ __( 'Here you can see an history of all the messages between Hyve and your users.', 'hyve' ) }</p>

					{ ( isLoading && ! posts?.length ) && (
						<div className="flex justify-center items-center h-52 border-[0.5px] border-gray-300 border-solid">
							<Spinner />
						</div>
					)}

					{ ( ! isLoading && ! posts?.length ) && (
						<div className="flex justify-center items-center h-52 border-[0.5px] border-gray-300 border-solid">
							<p className="text-xs">{ __( 'Messsages between Hyve and your users will appear here', 'hyve' ) }</p>
						</div>
					)}

					{ ( ( ! isLoading && ( posts && 0 < posts.length ) ) || ( posts && 0 < posts.length ) ) && (
						<div className="grid grid-cols-6 relative border-[0.5px] border-gray-300 border-solid">
							{ ( ! isLoading || ( posts && 0 < posts.length ) ) && (
								<div className="col-span-6 xl:col-span-2 border-r-gray-300 border-r-[0.5px] border-solid max-h-[672px] overflow-scroll">
									{ posts?.map( ( post, index ) => (
										<Button
											key={ post.ID }
											onClick={ () => setSelectedPost( post ) }
											className={ classnames(
												'flex items-center p-4 h-auto w-full text-base font-normal text-gray-900 hover:text-gray-900 hover:bg-gray-100',
												{
													'bg-gray-100 hover:text-gray-900': selectedPost?.ID === post.ID,
													'border-t-gray-300 border-t-[0.5px] border-solid': 0 !== index
												}
											) }
										>
											<div className="flex flex-col items-start gap-1">
												<span className="text-sm overflow-hidden text-ellipsis text-left">{ post.title }</span>
												<span className="text-xs text-gray-500">{ post.date }</span>
											</div>
										</Button>
									) ) }

									{ hasMore && applyFilters(
										'hyve.messages.load-more',
										<LoadMore
											onClick={ () => setUpsellOpen( true ) }
										/>,
										isLoading,
										fetchPosts
									) }
								</div>
							) }

							<div className="flex flex-col col-span-6 xl:col-span-4 p-4 max-h-[672px]">
								{ ! selectedPost && (
									<div className="flex justify-center w-full h-full items-center">
										<p className="text-xs">{ __( 'Select a message to view the conversation', 'hyve' ) }</p>
									</div>
								) }

								{ selectedPost && (
									<>
										<div className="flex justify-between pb-3 border-b-gray-300 border-b-[0.5px] border-solid">
											<h2 className="text-xs font-semibold">{ __( 'Thread ID', 'hyve' ) }</h2>
											<p className="text-xs text-gray-500">{ selectedPost?.thread_id?.replace( 'thread_', '' ) }</p>
										</div>

										<div className="overflow-scroll">
											{ selectedPost?.thread && ( 0 < selectedPost?.thread?.length ) && selectedPost?.thread.map( ( message, index ) => {
												const datetime = new Date( message.time * 1000 );
												const date = `${ String( datetime.getDate() ).padStart( 2, 0 ) }/${ String( datetime.getMonth() + 1 ).padStart( 2, 0 ) }/${ datetime.getFullYear() } ${ String( datetime.getHours() ).padStart( 2, 0 ) }:${ String( datetime.getMinutes() ).padStart( 2, 0 ) } ${ 12 <= datetime.getHours() ? 'PM' : 'AM' }`;

												if ( 'bot' === message.sender ) {
													return (
														<div
															key={ index }
															className="text-black max-w- max-w-[75%] min-w-[50%] flex flex-col items-start mr-auto my-3.5"
														>
															<p className="text-[13px] flex w-full break-words bg-[#ecf1fb] justify-start m-0 p-2.5 rounded-md">{ message.message }</p>
															<time className="text-[10px] text-black p-1">{ date }</time>
														</div>
													);
												}

												if ( 'user' === message.sender ) {
													return (
														<div
															key={ index }
															className="max-w-[75%] min-w-[50%] text-[white] flex flex-col items-end ml-auto my-3.5"
														>
															<p className="text-[13px] flex w-full break-words bg-[#1155cc] justify-end m-0 p-2.5 rounded-md">{ message.message }</p>
															<time className="text-[10px] text-black p-1">{ date }</time>
														</div>
													);
												}

												return null;
											}) }
										</div>
									</>
								) }
							</div>
						</div>
					) }
				</PanelRow>
			</Panel>
		</div>
	);
};

export default Messages;
