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
	Icon,
	Modal,
	Panel,
	PanelRow,
	Spinner,
} from '@wordpress/components';

import { useEffect, useState, useCallback } from '@wordpress/element';

import { applyFilters } from '@wordpress/hooks';

import { addQueryArgs } from '@wordpress/url';

import { useDispatch } from '@wordpress/data';

/**
 * Internal dependencies.
 */
import UpsellContainer from './UpsellContainer';

import { formatDate } from '../utils';

const LoadMore = ( { onClick } ) => (
	<Button
		onClick={ onClick }
		className="flex items-center justify-center p-4 h-auto w-full text-base font-normal text-gray-900 hover:text-gray-900 hover:bg-gray-100 border-t-gray-300 border-t-[0.5px] border-solid"
	>
		<div className="flex flex-row gap-1">
			{ __( 'Load more', 'hyve-lite' ) }
		</div>
	</Button>
);

const UpsellModalComponent = ( { isOpen, onRequestClose } ) => {
	if ( ! isOpen ) {
		return null;
	}
	return (
		<Modal
			onRequestClose={ onRequestClose }
			className="md:max-w-3xl md:w-full overflow-hidden"
		>
			<UpsellContainer
				title={ __(
					'Message History is a Premium feature',
					'hyve-lite'
				) }
				description={ __(
					'Upgrade to Hyve Premium to unlock entire Message History feature and many more.',
					'hyve-lite'
				) }
				campaign="messages-feature"
			>
				<img
					className="border-t-gray-300 border-t-[0.5px] border-x-0 border-b-0 border-solid"
					src={ `${ window?.hyve?.assets?.images }threads.png` }
					alt={ __(
						'Message threads preview showing conversation history',
						'hyve-lite'
					) }
				/>
			</UpsellContainer>
		</Modal>
	);
};

const ChatMessageBubble = ( { message } ) => {
	const date = formatDate( message.time * 1000 );

	if ( 'bot' === message.sender ) {
		return (
			<div className="text-black max-w-[75%] min-w-[50%] flex flex-col items-start mr-auto my-3.5">
				<p
					className="hyve-chat-message text-[13px] flex flex-col w-full break-words bg-[#ecf1fb] justify-start m-0 p-2.5 rounded-md"
					dangerouslySetInnerHTML={ { __html: message.message } }
				/>
				<time className="text-[10px] text-black p-1">{ date }</time>
			</div>
		);
	}

	if ( 'user' === message.sender ) {
		return (
			<div className="max-w-[75%] min-w-[50%] text-[white] flex flex-col items-end ml-auto my-3.5">
				<p className="hyve-chat-message text-[13px] flex flex-col w-full break-words bg-[#1155cc] justify-end m-0 p-2.5 rounded-md">
					{ message.message }
				</p>
				<time className="text-[10px] text-black p-1">{ date }</time>
			</div>
		);
	}

	return null;
};

const MessageThreadView = ( { selectedPost, onDelete } ) => {
	if ( ! selectedPost ) {
		return (
			<div className="flex justify-center w-full h-full items-center">
				<p className="text-xs">
					{ __(
						'Select a message to view the conversation',
						'hyve-lite'
					) }
				</p>
			</div>
		);
	}

	return (
		<>
			<div className="flex justify-between px-4 border-b-gray-300 border-b-[0.5px] border-solid">
				<h2 className="text-xs font-semibold">
					{ __( 'Thread ID', 'hyve-lite' ) }
				</h2>
				<p className="text-xs text-gray-500">
					{ selectedPost?.thread_id?.replace( 'thread_', '' ) }
				</p>
				<Button
					isDestructive={ true }
					aria-label={ __( 'Delete conversation', 'hyve-lite' ) }
					onClick={ () => onDelete( selectedPost?.ID ) }
				>
					<Icon icon={ 'trash' } />
				</Button>
			</div>
			<div className="overflow-scroll pl-4 grow">
				{ selectedPost?.thread?.map( ( message, index ) => (
					<ChatMessageBubble key={ index } message={ message } />
				) ) }
			</div>
		</>
	);
};

const MessageListItem = ( { post, onClick, isSelected, isFirst } ) => (
	<Button
		key={ post.ID }
		onClick={ () => onClick( post ) }
		className={ classnames(
			'flex items-center p-4 h-auto w-full text-base font-normal text-gray-900 hover:text-gray-900 hover:bg-gray-100',
			{
				'bg-gray-100 hover:text-gray-900': isSelected,
				'border-t-gray-300 border-t-[0.5px] border-solid': ! isFirst,
			}
		) }
	>
		<div className="flex flex-col items-start gap-1">
			<span className="text-sm overflow-hidden text-ellipsis text-left">
				{ post.title }
			</span>
			<span className="text-xs text-gray-500">
				{ formatDate( post.date ) }
			</span>
		</div>
	</Button>
);

const MessageList = ( {
	posts,
	selectedPost,
	onPostSelect,
	loadMoreButton,
} ) => {
	return (
		<div className="col-span-6 xl:col-span-2 border-r-gray-300 border-r-[0.5px] border-solid max-h-[672px] overflow-scroll">
			{ posts?.map( ( post, index ) => (
				<MessageListItem
					key={ post.ID }
					post={ post }
					onClick={ onPostSelect }
					isSelected={ selectedPost?.ID === post.ID }
					isFirst={ index === 0 }
				/>
			) ) }
			{ loadMoreButton }
		</div>
	);
};

const ExportMessagesAction = ( { onClick } ) => {
	return applyFilters(
		'hyve.messages.export-messages',
		<Button variant="tertiary" onClick={ onClick }>
			<Icon icon={ 'lock' } />
			{ __( 'Export Messages', 'hyve-lite' ) }
		</Button>,
		<Icon icon={ 'download' } />,
		__( 'Export Messages', 'hyve-lite' )
	);
};

const Messages = () => {
	const [ posts, setPosts ] = useState( [] );
	const [ selectedPost, setSelectedPost ] = useState( null );
	const [ hasMore, setHasMore ] = useState( false );
	const [ isUpsellOpen, setUpsellOpen ] = useState( false );
	const [ isLoading, setLoading ] = useState( true );
	const [ offset, setOffset ] = useState( 0 );

	const { createNotice } = useDispatch( 'core/notices' );

	const deleteConversation = useCallback(
		async ( threadId ) => {
			try {
				const response = await apiFetch( {
					path: addQueryArgs( `${ window.hyve.api }/threads`, {
						id: threadId,
					} ),
					method: 'DELETE',
				} );

				if ( response.data ) {
					setPosts( ( prev ) =>
						prev.filter( ( post ) => post.ID !== threadId )
					);
					setSelectedPost( null );
					createNotice( 'success', response.data, {
						type: 'snackbar',
						isDismissible: true,
					} );

					window.hyveTrk?.add?.( {
						feature: 'dashboard',
						featureComponent: 'messages-tab',
						featureValue: 'delete-thread',
					} );
				}
			} catch ( error ) {
				createNotice( 'error', error?.message, {
					type: 'snackbar',
					isDismissible: true,
				} );
			}
		},
		[ createNotice ]
	);

	useEffect( () => {
		const fetchPosts = async () => {
			setLoading( true );

			const response = await apiFetch( {
				path: addQueryArgs( `${ window.hyve.api }/threads`, {
					offset,
				} ),
			} );

			setLoading( false );
			setPosts( ( prev ) => [ ...prev, ...response.posts ] );
			setHasMore( response.more );
		};

		fetchPosts();
	}, [ offset ] );

	const loadMoreButton = hasMore
		? applyFilters(
				'hyve.messages.load-more',
				<LoadMore onClick={ () => setUpsellOpen( true ) } />,
				isLoading,
				() => {
					setOffset( posts.length );
				}
		  )
		: null;

	return (
		<div className="col-span-6 xl:col-span-4">
			<UpsellModalComponent
				isOpen={ isUpsellOpen }
				onRequestClose={ () => setUpsellOpen( false ) }
			/>

			<Panel header={ __( 'Messages', 'hyve-lite' ) }>
				<PanelRow>
					<p className="py-4">
						{ __(
							'Here you can see an history of all the messages between Hyve and your users.',
							'hyve-lite'
						) }
					</p>

					{ isLoading && ! posts?.length && (
						<div className="flex justify-center items-center h-52 border-[0.5px] border-gray-300 border-solid">
							<Spinner />
						</div>
					) }

					{ ! isLoading && ! posts?.length && (
						<div className="flex justify-center items-center h-52 border-[0.5px] border-gray-300 border-solid">
							<p className="text-xs">
								{ __(
									'Messsages between Hyve and your users will appear here',
									'hyve-lite'
								) }
							</p>
						</div>
					) }

					{ ( ( ! isLoading && posts && 0 < posts.length ) ||
						( posts && 0 < posts.length ) ) && (
						<>
							<div className="grid grid-cols-6 relative border-[0.5px] border-gray-300 border-solid">
								{ ( ! isLoading ||
									( posts && 0 < posts.length ) ) && (
									<MessageList
										posts={ posts }
										selectedPost={ selectedPost }
										onPostSelect={ setSelectedPost }
										loadMoreButton={ loadMoreButton }
									/>
								) }

								<div className="flex flex-col col-span-6 xl:col-span-4 max-h-[672px]">
									<MessageThreadView
										selectedPost={ selectedPost }
										onDelete={ deleteConversation }
									/>
								</div>
							</div>
							<div className="flex justify-end mt-1">
								<ExportMessagesAction
									onClick={ () => setUpsellOpen( true ) }
								/>
							</div>
						</>
					) }
				</PanelRow>
			</Panel>
		</div>
	);
};

export default Messages;
