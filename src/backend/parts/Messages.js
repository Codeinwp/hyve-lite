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

const Pagination = ( {
	currentPage,
	onPageChange,
	postsPerPage,
	totalPosts,
	setUpsellOpen,
} ) => {
	const totalPages = Math.ceil( totalPosts / postsPerPage );
	const hasNext = currentPage < totalPages;
	const hasPrev = currentPage > 1;
	const isFreePlan = ! window.hyve.hasPro;

	const handlePageChange = ( page ) => {
		if ( isFreePlan ) {
			setUpsellOpen( true );
			return;
		}

		if ( page >= 1 && page <= totalPages ) {
			onPageChange( page );
		}
	};

	return (
		<div className="flex items-center justify-between py-2 px-4">
			<div className="text-[#50575e] text-[14px] font-medium flex-1 text-end px-5">
				{ totalPosts } { __( 'items', 'hyve-lite' ) }
			</div>
			<div className="flex items-center">
				<button
					onClick={ () => handlePageChange( 1 ) }
					disabled={ ! hasPrev && ! isFreePlan }
					className={ `flex items-center justify-center w-8 h-8 border border-[#c3c4c7] ${
						! hasPrev && ! isFreePlan
							? 'bg-[#f0f0f1] text-[#a7aaad] cursor-default'
							: 'bg-white text-[#2271b1] hover:bg-[#f6f7f7] hover:border-[#8c8f94]'
					} rounded-sm focus:outline-none mr-1` }
					aria-label={ __( 'Go to the first page', 'hyve-lite' ) }
				>
					<span className="text-lg">«</span>
				</button>

				<button
					onClick={ () => handlePageChange( currentPage - 1 ) }
					disabled={ ! hasPrev && ! isFreePlan }
					className={ `flex items-center justify-center w-8 h-8 border border-[#c3c4c7] ${
						! hasPrev && ! isFreePlan
							? 'bg-[#f0f0f1] text-[#a7aaad] cursor-default'
							: 'bg-white text-[#2271b1] hover:bg-[#f6f7f7] hover:border-[#8c8f94]'
					} rounded-sm focus:outline-none mr-3` }
					aria-label={ __( 'Go to the previous page', 'hyve-lite' ) }
				>
					<span className="text-lg">‹</span>
				</button>

				<div className="inline-flex items-center justify-center px-3 text-[14px] text-[#50575e] font-medium">
					{ currentPage } { __( 'of', 'hyve-lite' ) } { totalPages }
				</div>

				<button
					onClick={ () => handlePageChange( currentPage + 1 ) }
					disabled={ ! hasNext && ! isFreePlan }
					className={ `flex items-center justify-center w-8 h-8 border border-[#c3c4c7] ${
						! hasNext && ! isFreePlan
							? 'bg-[#f0f0f1] text-[#a7aaad] cursor-default'
							: 'bg-white text-[#2271b1] hover:bg-[#f6f7f7] hover:border-[#8c8f94]'
					} rounded-sm focus:outline-none ml-3 mr-1` }
					aria-label={ __( 'Go to the next page', 'hyve-lite' ) }
				>
					<span className="text-lg">›</span>
				</button>

				<button
					onClick={ () => handlePageChange( totalPages ) }
					disabled={ ! hasNext && ! isFreePlan }
					className={ `flex items-center justify-center w-8 h-8 border border-[#c3c4c7] ${
						! hasNext && ! isFreePlan
							? 'bg-[#f0f0f1] text-[#a7aaad] cursor-default'
							: 'bg-white text-[#2271b1] hover:bg-[#f6f7f7] hover:border-[#8c8f94]'
					} rounded-sm focus:outline-none` }
					aria-label={ __( 'Go to the last page', 'hyve-lite' ) }
				>
					<span className="text-lg">»</span>
				</button>
			</div>
		</div>
	);
};

const MessageList = ( { posts, selectedPost, onPostSelect } ) => {
	return (
		<div className="col-span-6 xl:col-span-2 border-r-gray-300 border-r-[0.5px] border-solid">
			{ posts?.map( ( post, index ) => (
				<MessageListItem
					key={ post.ID }
					post={ post }
					onClick={ onPostSelect }
					isSelected={ selectedPost?.ID === post.ID }
					isFirst={ index === 0 }
				/>
			) ) }
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
	const [ cachedPages, setCachedPages ] = useState( {} );
	const [ selectedPost, setSelectedPost ] = useState( null );
	const [ isUpsellOpen, setUpsellOpen ] = useState( false );
	const [ isLoading, setLoading ] = useState( true );
	const [ currentPage, setCurrentPage ] = useState( 1 );
	const [ totalPosts, setTotalPosts ] = useState( 0 );
	const [ postsPerPage, setPostsPerPage ] = useState( 1 );

	const { createNotice } = useDispatch( 'core/notices' );

	const currentPosts = cachedPages[ currentPage ] || [];

	const fetchPage = useCallback(
		async ( page ) => {
			if ( cachedPages[ page ] ) {
				return;
			}

			setLoading( true );

			try {
				const offset = ( page - 1 ) * postsPerPage;
				const response = await apiFetch( {
					path: addQueryArgs( `${ window.hyve.api }/threads`, {
						offset,
						per_page: postsPerPage,
					} ),
				} );

				setCachedPages( ( prev ) => ( {
					...prev,
					[ page ]: response.posts || [],
				} ) );
				setTotalPosts( response.total || 0 );
				setPostsPerPage( response.postPerPage || 3 );
			} catch ( error ) {
				createNotice( 'error', error?.message, {
					type: 'snackbar',
					isDismissible: true,
				} );
			} finally {
				setLoading( false );
			}
		},
		[ createNotice, cachedPages, postsPerPage ]
	);

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
					setCachedPages( ( prev ) => ( {
						...prev,
						[ currentPage ]:
							prev[ currentPage ]?.filter(
								( post ) => post.ID !== threadId
							) || [],
					} ) );
					setSelectedPost( null );
					setTotalPosts( totalPosts - 1 || 0 );

					const newTotalPages = Math.ceil(
						( totalPosts - 1 ) / postsPerPage
					);
					if ( currentPage > newTotalPages && newTotalPages > 0 ) {
						setCurrentPage( newTotalPages );
					}

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
		[ createNotice, currentPage, postsPerPage, totalPosts ]
	);

	useEffect( () => {
		fetchPage( currentPage );
	}, [ fetchPage, currentPage ] );

	const handlePageChange = ( page ) => {
		setCurrentPage( page );
		setSelectedPost( null );
		fetchPage( page );
	};

	return (
		<div className="col-span-6 xl:col-span-4">
			<UpsellModalComponent
				isOpen={ isUpsellOpen }
				onRequestClose={ () => setUpsellOpen( false ) }
			/>

			<Panel header={ __( 'Messages', 'hyve-lite' ) }>
				<PanelRow>
					<p>
						{ __(
							'Here you can see an history of all the messages between Hyve and your users.',
							'hyve-lite'
						) }
					</p>
					<Pagination
						currentPage={ currentPage }
						onPageChange={ handlePageChange }
						postsPerPage={ postsPerPage }
						totalPosts={ totalPosts }
						setUpsellOpen={ setUpsellOpen }
						currentPosts={ currentPosts }
					/>

					{ isLoading && ! totalPosts && (
						<div className="flex justify-center items-center h-52 border-[0.5px] border-gray-300 border-solid">
							<Spinner />
						</div>
					) }

					{ ! isLoading && ! totalPosts && (
						<div className="flex justify-center items-center h-52 border-[0.5px] border-gray-300 border-solid">
							<p className="text-xs">
								{ __(
									'Messsages between Hyve and your users will appear here',
									'hyve-lite'
								) }
							</p>
						</div>
					) }

					{ ( ( ! isLoading &&
						currentPosts &&
						0 < currentPosts.length ) ||
						( currentPosts && 0 < currentPosts.length ) ) && (
						<>
							<div className="grid grid-cols-6 relative border-[0.5px] border-gray-300 border-solid">
								<MessageList
									posts={ currentPosts }
									selectedPost={ selectedPost }
									onPostSelect={ setSelectedPost }
								/>
								<div className="flex flex-col col-span-6 xl:col-span-4">
									<MessageThreadView
										selectedPost={ selectedPost }
										onDelete={ deleteConversation }
									/>
								</div>
							</div>
							<Pagination
								currentPage={ currentPage }
								onPageChange={ handlePageChange }
								postsPerPage={ postsPerPage }
								totalPosts={ totalPosts }
								setUpsellOpen={ setUpsellOpen }
								currentPosts={ currentPosts }
							/>
							<div className="flex justify-end">
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
