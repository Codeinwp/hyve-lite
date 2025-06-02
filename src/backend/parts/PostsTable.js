/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import { Button, Spinner } from '@wordpress/components';

const PostsTable = ( { posts, isLoading, hasMore, onFetch, actions } ) => {
	return (
		<>
			<div className="flex flex-col">
				<div className="bg-gray-50 px-6 py-3 text-left text-xs text-gray-700 uppercase">
					<div className="flex">
						<div className="w-1/6">{ __( 'ID', 'hyve-lite' ) }</div>
						<div className="flex-1">
							{ __( 'Title', 'hyve-lite' ) }
						</div>
						<div className="w-1/6 flex justify-center">
							{ __( 'Action', 'hyve-lite' ) }
						</div>
					</div>
				</div>
				<div className="flex flex-col">
					{ posts?.map( ( post ) => (
						<div
							key={ post.ID }
							className="flex items-center bg-white px-6 py-4 border-b text-sm text-gray-500"
						>
							<div className="w-1/6">{ post.ID }</div>

							<div className="flex-1 text-left rtl:text-right overflow-hidden">
								<span className="max-w-full text-ellipsis overflow-hidden">
									{ post.title }
								</span>
							</div>

							<div className="text-center flex gap-4">
								{ actions?.map( ( action ) => (
									<Button
										key={ action?.label }
										variant={ action?.variant || 'primary' }
										onClick={ () =>
											action?.onClick( post.ID )
										}
										disabled={
											action?.isBusy.includes(
												post.ID
											) || action?.isDisabled
										}
										isBusy={ action?.isBusy.includes(
											post.ID
										) }
										isDestructive={ action?.isDestructive }
										className="w-20 justify-center"
									>
										{ action?.label }
									</Button>
								) ) }
							</div>
						</div>
					) ) }

					{ ! posts.length && ! isLoading && (
						<div className="flex justify-center py-4">
							{ __( 'No data found.', 'hyve-lite' ) }
						</div>
					) }
				</div>
			</div>

			{ isLoading && (
				<div className="flex justify-center pt-8">
					<Spinner />
				</div>
			) }

			{ hasMore && (
				<div className="flex justify-center pt-8">
					<Button variant="secondary" onClick={ onFetch }>
						{ __( 'Load More', 'hyve-lite' ) }
					</Button>
				</div>
			) }
		</>
	);
};

export default PostsTable;
