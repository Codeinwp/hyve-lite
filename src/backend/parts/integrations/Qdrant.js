/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import {
	Button,
	ExternalLink,
	Icon,
	Modal,
	Panel,
	PanelRow,
	TextControl,
} from '@wordpress/components';

import { useEffect, useState, useCallback } from '@wordpress/element';

import { useDispatch, useSelect } from '@wordpress/data';

import { check } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import ProgressBar from '../../components/ProgressBar';

const Qdrant = () => {
	const settings = useSelect( ( select ) => select( 'hyve' ).getSettings() );
	const isQdrantActive = useSelect( ( select ) =>
		select( 'hyve' ).isQdrantActive()
	);

	const { setSetting, setQdrantStatus } = useDispatch( 'hyve' );

	const { createNotice } = useDispatch( 'core/notices' );

	const [ isSaving, setIsSaving ] = useState( false );
	const [ isOpen, setIsOpen ] = useState( false );
	const [ migrationStatus, setMigrationStatus ] = useState( [] );

	const getQdrantStatus = useCallback( async () => {
		try {
			const response = await apiFetch( {
				path: `${ window.hyve.api }/qdrant`,
			} );

			if ( response.error ) {
				throw new Error( response.error );
			}

			setQdrantStatus( Boolean( response.status ) );
			setMigrationStatus( response.migration );

			if ( response.migration?.in_progress ) {
				setTimeout( getQdrantStatus, 10000 );
			}
		} catch ( error ) {
			createNotice( 'error', error, {
				type: 'snackbar',
				isDismissible: true,
			} );
		}
	}, [ setQdrantStatus, createNotice ] );

	useEffect( () => {
		getQdrantStatus();
	}, [ getQdrantStatus ] );

	const onDeactivate = async () => {
		setIsSaving( true );

		try {
			const response = await apiFetch( {
				path: `${ window.hyve.api }/qdrant`,
				method: 'POST',
			} );

			if ( response.error ) {
				throw new Error( response.error );
			}

			setQdrantStatus( false );

			createNotice(
				'success',
				__( 'Qdrant disconnected.', 'hyve-lite' ),
				{
					type: 'snackbar',
					isDismissible: true,
				}
			);
			window.hyveTrk?.add( {
				feature: 'qdrant',
				featureComponent: 'api-key',
				featureValue: 'disconnected',
			} );
			window.tiTrk?.uploadEvents?.();
		} catch ( error ) {
			createNotice( 'error', error, {
				type: 'snackbar',
				isDismissible: true,
			} );
		}

		setIsSaving( false );
	};

	const onSave = async () => {
		setIsSaving( true );

		try {
			const response = await apiFetch( {
				path: `${ window.hyve.api }/settings`,
				method: 'POST',
				data: {
					data: settings,
				},
			} );

			if ( response.error ) {
				throw new Error( response.error );
			}

			await getQdrantStatus();

			createNotice( 'success', __( 'Settings saved.', 'hyve-lite' ), {
				type: 'snackbar',
				isDismissible: true,
			} );

			window.hyveTrk?.add( {
				feature: 'qdrant',
				featureComponent: 'api-key',
				featureValue: 'connected',
			} );
			window.tiTrk?.uploadEvents?.();
		} catch ( error ) {
			createNotice( 'error', error, {
				type: 'snackbar',
				isDismissible: true,
			} );
		}

		setIsSaving( false );
	};

	return (
		<div className="col-span-6 xl:col-span-4">
			<Panel header={ __( 'Qdrant Integration', 'hyve-lite' ) }>
				{ ! isQdrantActive && (
					<>
						<PanelRow>
							<p className="py-4">
								{ __(
									"Use Qdrant to increase the Knowledge Base limit of Hyve. By integrating Qdrant, you can manage larger datasets and improve query performance for your website. To integrate Qdrant with your application, you'll need an API key and endpoint.",
									'hyve-lite'
								) }
							</p>
							<p className="pt-0 pb-4">
								{ __(
									'Qdrant offers a free plan that supports thousands of data chunks, making it an excellent choice for most use cases without incurring additional costs.',
									'hyve-lite'
								) }
							</p>

							<ExternalLink
								href={ window.hyve?.qdrant_docs }
								className="text-blue-600"
							>
								{ __( 'Learn more about Qdrant', 'hyve-lite' ) }
							</ExternalLink>
						</PanelRow>

						<PanelRow>
							<TextControl
								label={ __( 'API Key', 'hyve-lite' ) }
								type="password"
								value={ settings.qdrant_api_key || '' }
								disabled={ isSaving }
								onChange={ ( newValue ) =>
									setSetting( 'qdrant_api_key', newValue )
								}
							/>
						</PanelRow>

						<PanelRow>
							<TextControl
								label={ __( 'API Endpoint', 'hyve-lite' ) }
								type="url"
								value={ settings.qdrant_endpoint || '' }
								disabled={ isSaving }
								onChange={ ( newValue ) =>
									setSetting( 'qdrant_endpoint', newValue )
								}
							/>
						</PanelRow>

						<PanelRow>
							<Button
								variant="primary"
								isBusy={ isSaving }
								disabled={ isSaving }
								className="mt-2"
								onClick={ onSave }
							>
								{ __( 'Connect', 'hyve-lite' ) }
							</Button>
						</PanelRow>
					</>
				) }

				{ isQdrantActive && Boolean( migrationStatus?.in_progress ) && (
					<PanelRow>
						<p className="py-4">
							{ __(
								'Migration in progress. Please wait while we process your data. This may take a couple of minutes.',
								'hyve-lite'
							) }
						</p>

						<ProgressBar
							value={ migrationStatus?.current ?? 0 }
							max={ migrationStatus?.total ?? 100 }
						/>
					</PanelRow>
				) }

				{ isQdrantActive &&
					! Boolean( migrationStatus?.in_progress ) && (
						<>
							{ isOpen && (
								<Modal
									title={ __(
										'Are you sure you want to disconnect Qdrant?',
										'hyve-lite'
									) }
									onRequestClose={ () => setIsOpen( false ) }
									size="medium"
								>
									<p className="pt-2 pb-4">
										{ __(
											'If you proceed, all the data associated with this website will be deleted from Qdrant, and all the posts exceeding the Knowledge Base limit will be removed from the Knowledge Base.',
											'hyve-lite'
										) }
									</p>

									<Button
										variant="primary"
										isDestructive
										isBusy={ isSaving }
										disabled={ isSaving }
										onClick={ onDeactivate }
									>
										{ __( 'Disconnect', 'hyve-lite' ) }
									</Button>
								</Modal>
							) }

							<PanelRow>
								<p className="pb-2 flex flex-row items-center">
									<Icon
										icon={ check }
										size={ 24 }
										className="fill-green-600 mr-2"
									/>

									{ __(
										'Qdrant is connected and ready to use.',
										'hyve-lite'
									) }
								</p>

								<Button
									variant="primary"
									isDestructive
									className="mt-2"
									onClick={ () => setIsOpen( true ) }
								>
									{ __( 'Disconnect', 'hyve-lite' ) }
								</Button>
							</PanelRow>
						</>
					) }
			</Panel>
		</div>
	);
};

export default Qdrant;
