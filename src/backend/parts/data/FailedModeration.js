/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import { Panel, PanelRow } from '@wordpress/components';

import { useDispatch } from '@wordpress/data';

import { useEffect, useState } from '@wordpress/element';

import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies.
 */
import { onProcessData } from '../../utils';
import PostsTable from '../PostsTable';
import ModerationReview from '../ModerationReview';

const FailedModeration = () => {
	const [ needsUpdate, setNeedsUpdate ] = useState( [] );
	const [ hasMoreUpdate, setHasMoreUpdate ] = useState( false );
	const [ isLoadingUpdate, setLoadingUpdate ] = useState( false );
	const [ isUpdating, setUpdating ] = useState( [] );
	const [ isOpen, setOpen ] = useState( false );
	const [ post, setPost ] = useState( null );

	const { setTotalChunks } = useDispatch( 'hyve' );

	const fetchUpdate = async () => {
		setLoadingUpdate( true );

		const response = await apiFetch( {
			path: addQueryArgs( `${ window.hyve.api }/data`, {
				offset: needsUpdate?.length || 0,
				status: 'moderation',
			} ),
		} );

		setLoadingUpdate( false );
		setNeedsUpdate( [ ...needsUpdate, ...response.posts ] );
		setHasMoreUpdate( response.more );
		setTotalChunks( response?.totalChunks );
	};

	const onUpdate = async ( id ) => {
		setUpdating( ( prev ) => [ ...prev, id ] );
		const post = needsUpdate.find( ( post ) => post.ID === id );

		await onProcessData( {
			post,
			params: {
				action: 'update',
			},
			onSuccess: () => {
				setUpdating( ( prev ) =>
					prev.filter( ( postId ) => postId !== id )
				);
				setNeedsUpdate( ( prev ) =>
					prev.filter( ( post ) => post.ID !== id )
				);
			},
			onError: ( error ) => {
				if (
					'content_failed_moderation' === error?.code &&
					undefined !== error.review
				) {
					const newPost = {
						...post,
						review: error.review,
					};

					setPost( newPost );
					setOpen( true );
				}

				setUpdating( ( prev ) =>
					prev.filter( ( postId ) => postId !== id )
				);
			},
		} );
	};

	useEffect( () => {
		fetchUpdate();
	}, [] );

	return (
		<>
			<div className="col-span-6 xl:col-span-4">
				<Panel header={ __( 'Failed Moderation', 'hyve-lite' ) }>
					<PanelRow>
						<p className="py-4">
							{ __(
								"On this page, you'll find posts and pages that could not be added or updated due to non-compliance with content policies. Review these to understand the limitations and possibly modify content to align with required standards. You can also adjust the moderation threshold in the Settings.",
								'hyve-lite'
							) }
						</p>

						<div className="relative pt-4 pb-8 overflow-x-auto">
							<PostsTable
								posts={ needsUpdate || [] }
								isLoading={ isLoadingUpdate }
								hasMore={ hasMoreUpdate }
								onFetch={ fetchUpdate }
								actions={ [
									{
										label: __( 'More Info', 'hyve-lite' ),
										isBusy: isUpdating,
										variant: 'secondary',
										onClick: ( ID ) => {
											const post = needsUpdate.find(
												( post ) => post.ID === ID
											);
											setOpen( true );
											setPost( post );
										},
									},
									{
										label: __( 'Update', 'hyve-lite' ),
										isBusy: isUpdating,
										onClick: onUpdate,
									},
								] }
							/>
						</div>
					</PanelRow>
				</Panel>
			</div>

			<ModerationReview
				post={ post }
				isOpen={ isOpen }
				onClose={ () => {
					setOpen( false );
					setUpdating( ( prev ) =>
						prev.filter( ( postId ) => postId !== post.ID )
					);
				} }
				onSuccess={ () => {
					setNeedsUpdate( ( prev ) =>
						prev.filter( ( i ) => i.ID !== post.ID )
					);
					setUpdating( ( prev ) =>
						prev.filter( ( postId ) => postId !== post.ID )
					);
				} }
			/>
		</>
	);
};

export default FailedModeration;
