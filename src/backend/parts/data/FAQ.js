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
	Spinner
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

const Custom = () => {
	const [ posts, setPosts ] = useState([]);
	const [ isLoading, setLoading ] = useState( true );
	const [ isModalOpen, setModalOpen ] = useState( false );
	const [ selectedPost, setSelectedPost ] = useState({});

	const { setTotalChunks } = useDispatch( 'hyve' );
	const { createNotice } = useDispatch( 'core/notices' );

	const hasReachedLimit = useSelect( ( select ) => select( 'hyve' ).hasReachedLimit() );

	const fetchData = async() => {
		setLoading( true );

		const response = await apiFetch({
			path: addQueryArgs( `${ window.hyve.api }/faq` )
		});

		setLoading( false );
		setPosts( response?.items );
		setTotalChunks( response?.totalChunks );
	};

	useEffect( () => {
		fetchData();
	}, []);

	const onClose = async( refresh = false ) => {
		setModalOpen( false );

		if ( refresh ) {
			onDelete( selectedPost.hash );
		}

		setSelectedPost({});
	};

	const onDelete = async( id ) => {
		const response = await apiFetch({
			path: `${ window.hyve.api }/faq/${ id }`,
			method: 'POST',
			headers: {
				'X-HTTP-Method-Override': 'DELETE'
			}
		});

		if ( response.error ) {
			createNotice(
				'error',
				response.error,
				{
					type: 'snackbar',
					isDismissible: true
				}
			);
			setLoading( false );

			return;
		}

		fetchData();
	};

	const onOpenModal = ( item ) => {
		setModalOpen( true );

		const post = {
			title: item.question,
			hash: item.hash
		};

		setSelectedPost( post );
	};

	return (
		<>
			{ isModalOpen && (
				<PostModal
					post={ selectedPost }
					onClose={ onClose }
				/>
			) }

			<div className="col-span-6 xl:col-span-4">
				<Panel
					header={ __( 'FAQ', 'hyve' ) }
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

						<p className="py-4">{ __( 'The FAQ captures frequently asked questions that went unanswered by our chatbot, providing you with a valuable insight into what your users are seeking. This feature allows you to review these queries and decide whether to incorporate them into your bot\'s knowledge base. By actively updating your FAQ, you can continuously refine your chatbot\'s ability to address user needs effectively and enhance their interactive experience. These aren\'t updated instantly.', 'hyve' ) }</p>

						<div className="relative pt-4 pb-8 overflow-x-auto">

							<div className="flex flex-col">
								<div className="bg-gray-50 px-6 py-3 text-left text-xs text-gray-700 uppercase">
									<div className="flex">
										<div className="flex-1">{ __( 'Title', 'hyve' ) }</div>
										<div className="w-1/6">{ __( 'Count', 'hyve' ) }</div>
										<div className="w-1/6 flex justify-center">{ __( 'Action', 'hyve' ) }</div>
									</div>
								</div>
								<div className="flex flex-col">
									{ posts?.map( ( post ) => (
										<div
											key={ post.hash }
											className="flex items-center bg-white px-6 py-4 border-b text-sm text-gray-500"
										>
											<div className="flex-1 text-left rtl:text-right overflow-hidden">
												<span className="max-w-full text-ellipsis overflow-hidden">{ post.question }</span>
											</div>

											<div className="w-1/6">{ post.count }</div>

											<div className="w-1/6 text-center flex gap-4">
												<Button
													variant="secondary"
													onClick={ () => onDelete( post.hash ) }
													className="w-20 justify-center"
												>
													{ __( 'Delete', 'hyve' ) }
												</Button>

												<Button
													variant="primary"
													onClick={ () => onOpenModal( post ) }
													className="w-20 justify-center"
												>
													{ __( 'Add', 'hyve' ) }
												</Button>
											</div>
										</div>
									) )}

									{ ( ! posts.length && ! isLoading ) && (
										<div className="flex justify-center py-4">
											{__( 'No data found.', 'hyve' )}
										</div>
									)}
								</div>
							</div>

							{ isLoading && (
								<div className="flex justify-center pt-8">
									<Spinner />
								</div>
							)}
						</div>
					</PanelRow>
				</Panel>
			</div>
		</>
	);
};

export default Custom;
