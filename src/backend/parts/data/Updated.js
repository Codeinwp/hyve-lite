/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import {
	Panel,
	PanelRow
} from '@wordpress/components';

import { useDispatch } from '@wordpress/data';

import {
	useEffect,
	useState
} from '@wordpress/element';

import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies.
 */
import { onProcessData } from '../../utils';
import PostsTable from '../PostsTable';
import ModerationReview from '../ModerationReview';

const Updated = () => {
	const [ needsUpdate, setNeedsUpdate ] = useState([]);
	const [ hasMoreUpdate, setHasMoreUpdate ] = useState( false );
	const [ isLoadingUpdate, setLoadingUpdate ] = useState( false );
	const [ isUpdating, setUpdating ] = useState([]);
	const [ isModerationModalOpen, setModerationModalOpen ] = useState( false );
	const [ post, setPost ] = useState( null );

	const { setTotalChunks } = useDispatch( 'hyve' );

	const fetchUpdate = async() => {
		setLoadingUpdate( true );

		const response = await apiFetch({
			path: addQueryArgs( `${ window.hyve.api }/data`, {
				offset: needsUpdate?.length || 0,
				status: 'pending'
			})
		});

		setLoadingUpdate( false );
		setNeedsUpdate([
			...needsUpdate,
			...response.posts
		]);
		setHasMoreUpdate( response.more );
		setTotalChunks( response?.totalChunks );
	};

	const onUpdate = async( id ) => {
		setUpdating( prev => [ ...prev, id ]);
		const post = needsUpdate.find( post => post.ID === id );

		await onProcessData({
			post,
			params: {
				action: 'update'
			},
			onSuccess: () => {
				setUpdating( prev => prev.filter( postId => postId !== id ) );
				setNeedsUpdate( prev => prev.filter( post => post.ID !== id ) );
			},
			onError: ( error ) => {
				if ( 'content_failed_moderation' === error?.code && undefined !== error.review ) {
					const newPost = {
						...post,
						review: error.review
					};

					setPost( newPost );
					setModerationModalOpen( true );
				}

				setUpdating( prev => prev.filter( postId => postId !== id ) );
			}
		});
	};

	useEffect( () => {
		fetchUpdate();
	}, []);

	return (
		<>
			<div className="col-span-6 xl:col-span-4">
				<Panel
					header={ __( 'Updated', 'hyve-lite' ) }
				>
					<PanelRow>
						<p className="py-4">{ __( 'Here, you\'ll see posts that have been updated since their addition to the knowledge base. This page allows you to review these updates and decide if you want to refresh the knowledge your assistant relies on.', 'hyve-lite' ) }</p>

						<div className="relative pt-4 pb-8 overflow-x-auto">
							<PostsTable
								posts={ needsUpdate || [] }
								isLoading={ isLoadingUpdate }
								hasMore={ hasMoreUpdate }
								onFetch={ fetchUpdate }
								onAction={ onUpdate }
								actionLabel={ __( 'Update', 'hyve-lite' ) }
								isBusy={ isUpdating }
							/>
						</div>
					</PanelRow>
				</Panel>
			</div>

			<ModerationReview
				post={ post }
				isOpen={ isModerationModalOpen }
				onClose={ () => {
					setModerationModalOpen( false );
					setUpdating( prev => prev.filter( postId => postId !== post.ID ) );
				} }
				onSuccess={ () => {
					setModerationModalOpen( false );
					setUpdating( prev => prev.filter( postId => postId !== post.ID ) );
					setNeedsUpdate( prev => prev.filter( item => item.ID !== post.ID ) );
				} }
			/>
		</>
	);
};

export default Updated;
