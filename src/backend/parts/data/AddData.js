/**
 * External dependencies.
 */
import hash from 'object-hash';

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import {
	Button,
	Modal,
	Notice,
	Panel,
	PanelRow,
	SearchControl,
	SelectControl,
} from '@wordpress/components';

import { useDispatch, useSelect } from '@wordpress/data';

import { useEffect, useState, useMemo } from '@wordpress/element';

import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies.
 */
import { onProcessData } from '../../utils';

import { PostsTable } from '../PostsTable';

import ModerationReview from '../ModerationReview';

const excludeTypes = [ 'attachment' ];

const postTypes = window.hyve.postTypes.filter(
	( postType ) => ! excludeTypes.includes( postType.value )
);

postTypes.unshift( {
	label: __( 'All', 'hyve-lite' ),
	value: 'any',
} );

const AddData = ( { refresh, setAddPost } ) => {
	const [ posts, setPosts ] = useState( {} );
	const [ processedPosts, setProcessedPosts ] = useState( [] );
	const [ hasMore, setHasMore ] = useState( false );
	const [ isLoading, setLoading ] = useState( true );
	const [ isUpdating, setUpdating ] = useState( [] );
	const [ isModerationModalOpen, setModerationModalOpen ] = useState( false );
	const [ post, setPost ] = useState( null );
	const [ confirmPost, setConfirmPost ] = useState( null );
	const [ offset, setOffset ] = useState( 0 );

	const [ query, setQuery ] = useState( {
		type: 'any',
		search: '',
	} );
	const queryHash = useMemo( () => hash( query ), [ query ] );

	const { setTotalChunks } = useDispatch( 'hyve' );
	const hasReachedLimit = useSelect( ( select ) =>
		select( 'hyve' ).hasReachedLimit()
	);

	const onProcess = ( id ) => {
		const currentPost = posts[ queryHash ]?.find( ( p ) => p.ID === id );

		// Restricted content is surfaced to any chat visitor once added, so
		// require an explicit confirmation before importing it.
		if ( currentPost && 'public' !== currentPost.visibility ) {
			setConfirmPost( currentPost );
			return;
		}

		processPost( id );
	};

	const processPost = async ( id ) => {
		setUpdating( ( prev ) => [ ...prev, id ] );
		const currentPost = posts[ queryHash ].find( ( p ) => p.ID === id );

		await onProcessData( {
			post: currentPost,
			onSuccess: () => {
				setUpdating( ( prev ) =>
					prev.filter( ( postId ) => postId !== id )
				);
				setProcessedPosts( ( prev ) => [ ...prev, id ] );
				refresh();
				window.hyveTrk?.add?.( {
					feature: 'knowledge-base',
					featureComponent: 'add-data',
					featureValue: 'import-wordpress-data',
				} );
			},
			onError: ( error ) => {
				if (
					'content_failed_moderation' === error?.code &&
					undefined !== error.review
				) {
					const newPost = {
						...currentPost,
						review: error.review,
					};

					setPost( newPost );
					setModerationModalOpen( true );
				}

				setUpdating( ( prev ) =>
					prev.filter( ( postId ) => postId !== id )
				);
			},
		} );
	};

	useEffect( () => {
		const fetchData = async () => {
			setLoading( true );

			const response = await apiFetch( {
				path: addQueryArgs( `${ window.hyve.api }/data`, {
					offset,
					...query,
				} ),
			} );

			setLoading( false );

			setPosts( ( prev ) => ( {
				...prev,
				[ queryHash ]: ( prev[ queryHash ] || [] ).concat(
					response.posts
				),
			} ) );

			setHasMore( response.more );
			setTotalChunks( response?.totalChunks );
		};

		const handler = setTimeout( () => fetchData(), 1000 );
		return () => clearTimeout( handler );
	}, [ query, setTotalChunks, queryHash, offset ] );

	const onChangeQuery = ( key, value ) => {
		setQuery( {
			...query,
			[ key ]: value,
		} );
	};

	return (
		<>
			<div className="col-span-6 xl:col-span-4">
				<Panel>
					<div className="items-center gap-4 flex shrink-0 h-12 px-4 py-0 border-b-[#ddd] border-b border-solid">
						<Button
							icon="arrow-left-alt"
							hideLabel
							label={ __( 'Back', 'hyve-lite' ) }
							onClick={ () => setAddPost( false ) }
						/>

						<h3>{ __( 'Add Data', 'hyve-lite' ) }</h3>
					</div>

					<PanelRow>
						{ hasReachedLimit && (
							<Notice status="warning" isDismissible={ false }>
								{ __(
									'You have reached the limit of posts that can be added to the Knowledge Base. Please delete existing posts if you wish to add more.',
									'hyve-lite'
								) }
							</Notice>
						) }

						<p className="py-4">
							{ __(
								'Select posts that are informative, engaging, and relevant. These will be the building blocks that empower your chat assistant to deliver precise and helpful responses. Whether it is answering FAQs or diving into detailed explanations, the content you choose here will shape how effectively your assistant interacts with users.',
								'hyve-lite'
							) }
						</p>

						<div className="relative pt-4 pb-8 overflow-x-auto">
							<div className="flex gap-4 pb-2">
								<div className="w-1/4">
									<SelectControl
										label={ __( 'Post Type', 'hyve-lite' ) }
										hideLabelFromVision={ true }
										className="h-10"
										options={ postTypes }
										onChange={ ( e ) =>
											onChangeQuery( 'type', e )
										}
									/>
								</div>

								<div className="w-3/4">
									<SearchControl
										label={ __(
											'Search for Posts',
											'hyve-lite'
										) }
										value={ query.search }
										onChange={ ( e ) =>
											onChangeQuery( 'search', e )
										}
									/>
								</div>
							</div>

							<PostsTable
								posts={
									posts[ hash( query ) ]?.filter(
										( p ) =>
											! processedPosts.includes( p.ID )
									) || []
								}
								isLoading={ isLoading }
								hasMore={ hasMore }
								onFetch={ () => {
									setOffset(
										posts[ queryHash ]?.length ?? 0
									);
								} }
								actions={ [
									{
										label: __( 'Add', 'hyve-lite' ),
										isBusy: isUpdating,
										onClick: onProcess,
										isDisabled: hasReachedLimit,
									},
								] }
							/>
						</div>
					</PanelRow>
				</Panel>
			</div>

			{ confirmPost && (
				<Modal
					title={ __( 'Add restricted content?', 'hyve-lite' ) }
					onRequestClose={ () => setConfirmPost( null ) }
				>
					<p className="max-w-md">
						{ 'private' === confirmPost.visibility
							? __(
									'This is a private post. Adding it to the Knowledge Base lets the chatbot surface its content to any visitor, even though the post itself is not publicly viewable.',
									'hyve-lite'
							  )
							: __(
									'This is a password-protected post. Adding it to the Knowledge Base lets the chatbot surface its content to any visitor without entering the password.',
									'hyve-lite'
							  ) }
					</p>

					<div className="flex justify-end gap-3 pt-6">
						<Button
							variant="tertiary"
							onClick={ () => setConfirmPost( null ) }
						>
							{ __( 'Cancel', 'hyve-lite' ) }
						</Button>

						<Button
							variant="primary"
							onClick={ () => {
								const id = confirmPost.ID;
								setConfirmPost( null );
								processPost( id );
							} }
						>
							{ __( 'Add anyway', 'hyve-lite' ) }
						</Button>
					</div>
				</Modal>
			) }

			<ModerationReview
				post={ post }
				isOpen={ isModerationModalOpen }
				onClose={ () => {
					setModerationModalOpen( false );
					setUpdating( ( prev ) =>
						prev.filter( ( postId ) => postId !== post.ID )
					);
				} }
				onSuccess={ () => {
					setModerationModalOpen( false );
					setUpdating( ( prev ) =>
						prev.filter( ( postId ) => postId !== post.ID )
					);
					setProcessedPosts( ( prev ) => [ ...prev, post.ID ] );
				} }
			/>
		</>
	);
};

export default AddData;
