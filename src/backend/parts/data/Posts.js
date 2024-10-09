/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import {
	Button,
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
import PostsTable from '../PostsTable';
import AddData from './AddData';

const Posts = ({ setView }) => {
	const [ posts, setPosts ] = useState([]);
	const [ hasMore, setHasMore ] = useState( false );
	const [ isLoading, setLoading ] = useState( true );
	const [ isDeleting, setDeleting ] = useState([]);
	const [ addPost, setAddPost ] = useState( false );

	const { createNotice } = useDispatch( 'core/notices' );
	const { setTotalChunks } = useDispatch( 'hyve' );

	const fetchPosts = async() => {
		setLoading( true );

		const response = await apiFetch({
			path: addQueryArgs( `${ window.hyve.api }/data`, {
				offset: posts?.length || 0,
				status: 'included'
			})
		});

		setLoading( false );
		setPosts( posts.concat( response.posts ) );
		setHasMore( response.more );
		setTotalChunks( response?.totalChunks );
	};

	const onDelete = async( id ) => {
		setDeleting([ ...isDeleting, id ]);

		await apiFetch({
			path: addQueryArgs( `${ window.hyve.api }/data`, {
				id
			}),
			method: 'DELETE'
		});

		setPosts( posts.filter( ( post ) => post.ID !== id ) );

		createNotice(
			'success',
			__( 'Post has been removed.', 'hyve-lite' ),
			{
				type: 'snackbar',
				isDismissible: true
			}
		);
	};

	useEffect( () => {
		fetchPosts();
	}, []);

	if ( addPost ) {
		return <AddData setAddPost={ setAddPost } />;
	}

	return (
		<div className="col-span-6 xl:col-span-4">
			<Panel>
				<div className="items-center gap-4 flex shrink-0 h-12 px-4 py-0 border-b-[#ddd] border-b border-solid">
					<Button
						icon="arrow-left-alt"
						hideLabel
						label={ __( 'Back', 'hyve' ) }
						onClick={ () => setView( null ) }
					/>

					<h3>{ __( 'WordPress Posts', 'hyve-lite' ) }</h3>
				</div>

				<PanelRow>
					<p className="py-4">{ __( 'All the content from your WordPress site that has been added to the Knowledge Base.', 'hyve-lite' ) }</p>

					<div className="w-full flex justify-end">
						<Button
							variant="primary"
							onClick={ () => setAddPost( true ) }
						>
							{ __( 'Add Posts', 'hyve' ) }
						</Button>
					</div>

					<div className="relative pt-4 pb-8 overflow-x-auto">
						<PostsTable
							posts={ posts }
							isLoading={ isLoading }
							hasMore={ hasMore }
							onFetch={ fetchPosts }
							onAction={ onDelete }
							actionProps={{
								variant: 'secondary',
								isDestructive: true
							}}
							actionLabel={ __( 'Remove', 'hyve-lite' ) }
							isBusy={ isDeleting }
						/>
					</div>
				</PanelRow>
			</Panel>
		</div>
	);
};

export default Posts;