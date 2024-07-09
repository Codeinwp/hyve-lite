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
	Notice,
	Panel,
	PanelRow,
	SearchControl
} from '@wordpress/components';

import {
	useDispatch,
	useSelect
} from '@wordpress/data';

import {
	useEffect,
	useState
} from '@wordpress/element';

import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies.
 */
import PostModal from '../PostModal';
import PostsTable from '../PostsTable';

const Custom = () => {
	const [ posts, setPosts ] = useState({});
	const [ hasMore, setHasMore ] = useState( false );
	const [ isLoading, setLoading ] = useState( true );
	const [ isModalOpen, setModalOpen ] = useState( false );
	const [ selectedPost, setSelectedPost ] = useState({});

	const [ query, setQuery ] = useState({
		search: ''
	});

	const { setTotalChunks } = useDispatch( 'hyve' );
	const hasReachedLimit = useSelect( ( select ) => select( 'hyve' ).hasReachedLimit() );

	const fetchData = async() => {
		setLoading( true );

		const queryHash = hash( query );

		const response = await apiFetch({
			path: addQueryArgs( `${ window.hyve.api }/knowledge`, {
				offset: posts[ queryHash ]?.length || 0,
				...query
			})
		});

		setLoading( false );

		setPosts({
			...posts,
			[ queryHash ]: [
				...( posts[ queryHash ] || []),
				...response.posts
			]
		});

		setHasMore( response.more );
		setTotalChunks( response?.totalChunks );
	};

	useEffect( () => {
		const handler = setTimeout( () => fetchData(), 1000 );
		return () => clearTimeout( handler );
	}, [ query ]);

	const onChangeQuery = ( key, value ) => {
		setQuery({
			...query,
			[ key ]: value
		});
	};

	const onClose = ( refresh = false ) => {
		setModalOpen( false );
		setSelectedPost({});

		if ( refresh ) {
			setPosts({});
			setQuery({ search: '' });
		}
	};

	const onOpenModal = ( id = {}) => {
		setModalOpen( true );
		const post = id ? posts[ hash( query )]?.find( post => post.ID === id ) : {};
		setSelectedPost( post );
	};

	return (
		<>
			{ isModalOpen && (
				<PostModal
					action={ Boolean( selectedPost ) ? 'edit' : 'create' }
					post={ selectedPost }
					onClose={ onClose }
				/>
			) }

			<div className="col-span-6 xl:col-span-4">
				<Panel
					header={ __( 'Custom Data', 'hyve' ) }
				>
					<PanelRow>
						{ hasReachedLimit && (
							<Notice
								status="warning"
								isDismissible={ false }
							>
								{ __( 'You have reached the limit of posts that can be added to the knowledge base. Please delete existing posts if you wish to add more.', 'hyve' ) }
							</Notice>
						) }

						<p className="py-4">{ __( 'Custom Data allows you to privately feed specific data directly into your chat bot without displaying this information on your public website. With this, you can equip your bot with unique, specialized knowledge that aligns with your business needs and customer queries.', 'hyve' ) }</p>

						<div className="relative pt-4 pb-8 overflow-x-auto">
							<div className="flex gap-4 pb-4 flex-col">
								<div className="w-full">
									<SearchControl
										label={ __( 'Search for Posts', 'hyve' ) }
										value={ query.search }
										onChange={ e => onChangeQuery( 'search', e )}
									/>
								</div>

								<div className="w-full flex justify-end">
									<Button
										variant="secondary"
										onClick={ () => onOpenModal() }
										disabled={ hasReachedLimit }
									>
										{ __( 'Add New', 'hyve' ) }
									</Button>
								</div>
							</div>

							<PostsTable
								posts={ posts[ hash( query ) ] || [] }
								isLoading={ isLoading }
								hasMore={ hasMore }
								onFetch={ fetchData }
								onAction={ onOpenModal }
								actionLabel={ __( 'Edit', 'hyve' ) }
								isBusy={ [] }
							/>
						</div>
					</PanelRow>
				</Panel>
			</div>
		</>
	);
};

export default Custom;
