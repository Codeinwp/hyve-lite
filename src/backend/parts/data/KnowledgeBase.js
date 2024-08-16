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
import PostsTable from '../PostsTable';

const KnowledgeBase = () => {
	const [ posts, setPosts ] = useState([]);
	const [ hasMore, setHasMore ] = useState( false );
	const [ isLoading, setLoading ] = useState( true );
	const [ isDeleting, setDeleting ] = useState([]);

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

	return (
		<div className="col-span-6 xl:col-span-4">
			<Panel
				header={ __( 'Knowledge Base', 'hyve-lite' ) }
			>
				<PanelRow>
					<p className="py-4">{ __( 'A list of all the content that has been added to the knowledge base. It\'s the foundation that supports your chat assistant, enabling it to provide accurate and insightful responses.', 'hyve-lite' ) }</p>

					<div className="relative pt-4 pb-8 overflow-x-auto">
						<PostsTable
							posts={ posts }
							isLoading={ isLoading }
							hasMore={ hasMore }
							onFetch={ fetchPosts }
							onAction={ onDelete }
							actionLabel={ __( 'Remove', 'hyve-lite' ) }
							isBusy={ isDeleting }
						/>
					</div>
				</PanelRow>
			</Panel>
		</div>
	);
};

export default KnowledgeBase;
