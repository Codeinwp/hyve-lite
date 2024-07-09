/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import {
	Button,
	Modal,
	TextControl,
	TextareaControl
} from '@wordpress/components';

import { useDispatch } from '@wordpress/data';

import { useState } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { onProcessData } from '../utils';
import ModerationReview from './ModerationReview';

const PostModal = ({
	action,
	post,
	onClose
}) => {
	const [ data, setData ] = useState({ ...post });
	const [ isLoading, setLoading ] = useState( false );
	const [ isModerationModalOpen, setModerationModalOpen ] = useState( false );

	const { createNotice } = useDispatch( 'core/notices' );

	const isEdit = 'edit' === action;

	const onDelete = async() => {
		setLoading( true );

		const response = await apiFetch({
			path: `${ window.hyve.api }/knowledge/${ data.ID }`,
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

		createNotice(
			'success',
			__( 'Post has been deleted.', 'hyve' ),
			{
				type: 'snackbar',
				isDismissible: true
			}
		);

		onClose( true );
	};

	const onProcess = async() => {
		setLoading( true );

		await onProcessData({
			post: data,
			type: 'knowledge',
			onSuccess: () => {
				onClose( true );
				setLoading( false );
			},
			onError: ( error ) => {
				if ( 'content_failed_moderation' === error?.code && undefined !== error.review ) {
					const newPost = {
						...data,
						review: error.review
					};

					setData( newPost );
					setModerationModalOpen( true );
				}

				setLoading( false );
			}
		});
	};

	if ( isModerationModalOpen ) {
		return (
			<ModerationReview
				post={ data }
				type="knowledge"
				isOpen={ isModerationModalOpen }
				onClose={ () => {
					setModerationModalOpen( false );
				} }
				onSuccess={ () => {
					onClose( true );
					setModerationModalOpen( false );
				} }
			/>
		);
	}

	return (
		<Modal
			title={ isEdit ? __( 'Edit Data', 'hyve' ) : __( 'Add Data', 'hyve' )}
			onRequestClose={ () => onClose( false ) }
			shouldCloseOnOverlayClick={ true }
			isOpen={ true }
			className="md:max-w-3xl md:w-full"
		>
			<div className="flex flex-col gap-4">
				<TextControl
					label={ __( 'Title', 'hyve' ) }
					value={ data?.title || '' }
					disabled={ isLoading }
					onChange={ ( title ) => {
						setData({
							...data,
							title
						});
					} }
				/>

				<TextareaControl
					label={ __( 'Content', 'hyve' ) }
					value={ data?.content || '' }
					rows={ 8 }
					disabled={ isLoading }
					maxLength={ 4000 }
					onChange={ ( content ) => {
						setData({
							...data,
							content
						});
					} }
				/>

				<div className="flex">
					{ isEdit && (
						<Button
							variant="tertiary"
							isDestructive
							disabled={ isLoading }
							isBusy={ isLoading }
							onClick={ onDelete }
						>
							{ __( 'Delete', 'hyve' ) }
						</Button>
					) }

					<Button
						variant="primary"
						className="ml-auto"
						disabled={ isLoading || ! data.title || ! data.content }
						isBusy={ isLoading }
						onClick={ onProcess }
					>
						{ isEdit ? __( 'Save', 'hyve' ) : __( 'Add', 'hyve' )}
					</Button>
				</div>
			</div>
		</Modal>
	);
};

export default PostModal;
