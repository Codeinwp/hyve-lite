/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import {
	Button,
	CheckboxControl,
	ExternalLink,
	Modal,
	TextControl,
} from '@wordpress/components';

import { useDispatch, useSelect } from '@wordpress/data';

import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { isLicenseActive, setUtm } from '../utils';
import Card from '../components/Card';
import Chip from '../components/Chip';
import FieldRow from '../components/FieldRow';
import Slot from '../components/Slot';
import useSaveSettings from '../data/useSaveSettings';

export const QdrantPanel = () => {
	const { settings, isSaving, save } = useSaveSettings();

	const isQdrantActive = useSelect( ( select ) =>
		select( 'hyve' ).isQdrantActive()
	);

	const { setSetting, setQdrantStatus } = useDispatch( 'hyve' );
	const { createNotice } = useDispatch( 'core/notices' );

	const [ migration, setMigration ] = useState( null );
	const [ isConfirmOpen, setConfirmOpen ] = useState( false );
	const [ isBusy, setBusy ] = useState( false );

	const timeoutRef = useRef( null );

	const getStatus = useCallback( async () => {
		try {
			const response = await apiFetch( {
				path: `${ window.hyve.api }/qdrant`,
			} );

			if ( response.error ) {
				throw new Error( response.error );
			}

			setQdrantStatus( Boolean( response.status ) );
			setMigration( response.migration ?? null );

			if ( response.migration?.in_progress ) {
				timeoutRef.current = setTimeout( getStatus, 10000 );
			}
		} catch ( error ) {
			createNotice( 'error', error?.message ?? String( error ), {
				type: 'snackbar',
				isDismissible: true,
			} );
		}
	}, [ setQdrantStatus, createNotice ] );

	useEffect( () => {
		getStatus();

		return () => clearTimeout( timeoutRef.current );
	}, [ getStatus ] );

	const onConnect = async () => {
		const response = await save();

		if ( ! response ) {
			return;
		}

		await getStatus();

		window.hyveTrk?.add?.( {
			feature: 'qdrant',
			featureComponent: 'api-key',
			featureValue: 'connected',
		} );
	};

	const onDisconnect = async () => {
		setBusy( true );

		try {
			const response = await apiFetch( {
				path: `${ window.hyve.api }/qdrant`,
				method: 'POST',
			} );

			if ( response.error ) {
				throw new Error( response.error );
			}

			setQdrantStatus( false );
			setMigration( null );
			setConfirmOpen( false );

			createNotice(
				'success',
				__( 'Qdrant disconnected.', 'hyve-lite' ),
				{
					type: 'snackbar',
					isDismissible: true,
				}
			);

			window.hyveTrk?.add?.( {
				feature: 'qdrant',
				featureComponent: 'api-key',
				featureValue: 'disconnected',
			} );
		} catch ( error ) {
			createNotice( 'error', error?.message ?? String( error ), {
				type: 'snackbar',
				isDismissible: true,
			} );
		}

		setBusy( false );
	};

	const isMigrating = isQdrantActive && Boolean( migration?.in_progress );

	const chip = ( () => {
		if ( isMigrating ) {
			return <Chip tone="warn">{ __( 'Migrating', 'hyve-lite' ) }</Chip>;
		}

		if ( isQdrantActive ) {
			return <Chip tone="ok">{ __( 'Connected', 'hyve-lite' ) }</Chip>;
		}

		return <Chip tone="muted">{ __( 'Not connected', 'hyve-lite' ) }</Chip>;
	} )();

	const migrated = Number( migration?.current ?? 0 );
	const migrationTotal = Number( migration?.total ?? 0 );
	const migrationPercent = migrationTotal
		? Math.min( 100, Math.round( ( migrated / migrationTotal ) * 100 ) )
		: 0;

	const endpointHost = ( settings.qdrant_endpoint || '' )
		.replace( /^https?:\/\//, '' )
		.replace( /\/$/, '' );

	return (
		<>
			<Card title={ __( 'Qdrant', 'hyve-lite' ) } actions={ chip }>
				{ isMigrating && (
					<div className="hyve-next-card__body">
						<p>
							{ __(
								'Moving your Knowledge Base chunks to your cluster. This runs in the background; you can keep working.',
								'hyve-lite'
							) }
						</p>
						<div className="hyve-next-meter" aria-hidden="true">
							<i
								style={ { width: `${ migrationPercent }%` } }
							></i>
						</div>
						<p className="hyve-next-card__hint">
							{ sprintf(
								/* translators: 1: chunks migrated so far, 2: total chunks to migrate. */
								__( '%1$s of %2$s chunks moved.', 'hyve-lite' ),
								migrated.toLocaleString(),
								migrationTotal.toLocaleString()
							) }
						</p>
					</div>
				) }

				{ ! isMigrating && isQdrantActive && (
					<FieldRow
						label={ __( 'Vector database', 'hyve-lite' ) }
						description={ __(
							'Embeddings are stored in your Qdrant cluster. The local chunk limit no longer applies.',
							'hyve-lite'
						) }
					>
						<div className="hyve-next-buttons">
							{ endpointHost && (
								<span className="hyve-next-endpoint">
									{ endpointHost }
								</span>
							) }
							<Button
								variant="secondary"
								isDestructive
								disabled={ isBusy }
								onClick={ () => setConfirmOpen( true ) }
							>
								{ __( 'Disconnect', 'hyve-lite' ) }
							</Button>
						</div>
					</FieldRow>
				) }

				{ ! isMigrating && ! isQdrantActive && (
					<>
						<div className="hyve-next-card__intro">
							<p>
								{ __(
									"Use Qdrant to increase the Knowledge Base limit of Hyve. By integrating Qdrant, you can manage larger datasets and improve query performance for your website. To integrate Qdrant with your application, you'll need an API key and endpoint.",
									'hyve-lite'
								) }
							</p>
							<p>
								{ __(
									'Qdrant offers a free plan that supports thousands of data chunks, making it an excellent choice for most use cases without incurring additional costs.',
									'hyve-lite'
								) }
							</p>
							<p>
								<ExternalLink href={ window.hyve?.qdrant_docs }>
									{ __(
										'Learn more about Qdrant',
										'hyve-lite'
									) }
								</ExternalLink>
							</p>
						</div>
						<FieldRow
							label={ __( 'Vector database', 'hyve-lite' ) }
							description={ __(
								'Store embeddings in your own Qdrant cluster and lift the local chunk limit.',
								'hyve-lite'
							) }
						>
							<div className="hyve-next-stack">
								<TextControl
									__nextHasNoMarginBottom
									hideLabelFromVision
									label={ __(
										'Qdrant API key',
										'hyve-lite'
									) }
									type="password"
									placeholder={ __( 'API key', 'hyve-lite' ) }
									value={ settings.qdrant_api_key || '' }
									disabled={ isSaving }
									onChange={ ( value ) =>
										setSetting( 'qdrant_api_key', value )
									}
								/>
								<TextControl
									__nextHasNoMarginBottom
									hideLabelFromVision
									label={ __(
										'Qdrant endpoint',
										'hyve-lite'
									) }
									type="url"
									placeholder="https://your-cluster.qdrant.io"
									value={ settings.qdrant_endpoint || '' }
									disabled={ isSaving }
									onChange={ ( value ) =>
										setSetting( 'qdrant_endpoint', value )
									}
								/>
								<div className="hyve-next-buttons">
									<Button
										variant="primary"
										isBusy={ isSaving }
										disabled={ isSaving }
										onClick={ onConnect }
									>
										{ __( 'Connect', 'hyve-lite' ) }
									</Button>
								</div>
							</div>
						</FieldRow>
					</>
				) }
			</Card>

			{ isConfirmOpen && (
				<Modal
					className="hyve-next-modal"
					size="medium"
					title={ __(
						'Are you sure you want to disconnect Qdrant?',
						'hyve-lite'
					) }
					onRequestClose={ () => setConfirmOpen( false ) }
				>
					<p>
						{ __(
							'If you proceed, all the data associated with this website will be deleted from Qdrant, and all the posts exceeding the Knowledge Base limit will be removed from the Knowledge Base.',
							'hyve-lite'
						) }
					</p>
					<div className="hyve-next-modal__actions">
						<Button
							variant="tertiary"
							disabled={ isBusy }
							onClick={ () => setConfirmOpen( false ) }
						>
							{ __( 'Cancel', 'hyve-lite' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							isBusy={ isBusy }
							disabled={ isBusy }
							onClick={ onDisconnect }
						>
							{ __( 'Disconnect', 'hyve-lite' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
};

export const WebhooksPanel = () => {
	const hasPro = Boolean( window.hyve?.license );

	if ( hasPro ) {
		return (
			<Slot
				name="settings-webhooks"
				fallback={
					<div className="hyve-next__card">
						<p>
							{ __(
								'The webhook settings are on their way here.',
								'hyve-lite'
							) }
						</p>
					</div>
				}
			/>
		);
	}

	return (
		<Card
			title={ __( 'Webhooks', 'hyve-lite' ) }
			actions={
				<Chip tone="pro" dot={ false }>
					{ __( 'Pro', 'hyve-lite' ) }
				</Chip>
			}
		>
			<div className="hyve-next-card__intro">
				<p>
					{ __(
						'Send chat activity to any URL as JSON events, as it happens. Route the events to Google Sheets, Slack, your CRM or a ticketing system with automation tools like Zapier, Make or n8n.',
						'hyve-lite'
					) }
				</p>
			</div>

			<FieldRow
				label={ __( 'Endpoint URL', 'hyve-lite' ) }
				description={ __(
					'Every selected event is sent to this URL as a signed POST request.',
					'hyve-lite'
				) }
			>
				<TextControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Endpoint URL', 'hyve-lite' ) }
					type="url"
					placeholder="https://hooks.example.com/hyve"
					value=""
					disabled
					onChange={ () => {} }
				/>
			</FieldRow>

			<FieldRow
				label={ __( 'Events', 'hyve-lite' ) }
				description={ __(
					'Choose which events are sent, so metered tools only receive what you use.',
					'hyve-lite'
				) }
			>
				<div className="hyve-next-stack">
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __( 'Visitor messages', 'hyve-lite' ) }
						checked
						disabled
						onChange={ () => {} }
					/>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __( 'Bot replies', 'hyve-lite' ) }
						checked
						disabled
						onChange={ () => {} }
					/>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __( 'Actions', 'hyve-lite' ) }
						checked
						disabled
						onChange={ () => {} }
					/>
				</div>
			</FieldRow>

			<div className="hyve-next-act__upsell">
				<strong>
					{ __( 'Put your chat data to work', 'hyve-lite' ) }
				</strong>
				<p>
					{ __(
						'Webhooks send conversations and captured leads to the tools your team already uses, the moment they happen. Part of Hyve Pro.',
						'hyve-lite'
					) }
				</p>
				<Button
					variant="primary"
					href={ setUtm( window.hyve?.pro, 'webhooks-settings' ) }
					target="_blank"
				>
					{ __( 'Unlock with Pro', 'hyve-lite' ) }
				</Button>
			</div>
		</Card>
	);
};

export const ApiAccessPanel = () => {
	const isPro = isLicenseActive();

	return (
		<>
			<Card
				title={ __( 'API access', 'hyve-lite' ) }
				actions={
					! isPro && (
						<Chip tone="pro" dot={ false }>
							{ __( 'Pro', 'hyve-lite' ) }
						</Chip>
					)
				}
			>
				<div className="hyve-next-card__body">
					<p>
						{ __(
							'Enable external services to search your Knowledge Base using advanced semantic search powered by Retrieval-Augmented Generation (RAG) and OpenAI embeddings.',
							'hyve-lite'
						) }{ ' ' }
						{ __(
							'Integrate automation tools (Zapier, n8n, etc.) with a secure API endpoint to perform intelligent content searches via simple API requests.',
							'hyve-lite'
						) }
					</p>
					<p>
						{ __(
							'With an Access Token, you can securely search your content from any location using the API.',
							'hyve-lite'
						) }
					</p>
					<pre className="hyve-next-code">
						{ [
							'curl --request POST',
							`  --url ${ window.hyve?.rest_url }/knowledge-base/search`,
							"  --header 'authorization: Bearer hyve_sk_MozAlDXXXXXXXXXXXXXXXX'",
							"  --header 'content-type: application/json'",
							"  --data '{",
							'        "query": "What is the cost of the phone?"',
							"}'",
						].join( '\n' ) }
					</pre>
				</div>
				{ ! isPro && (
					<div className="hyve-next-act__upsell">
						<strong>
							{ __(
								'Search your Knowledge Base from anywhere',
								'hyve-lite'
							) }
						</strong>
						<p>
							{ __(
								'Upgrade to Pro to unlock advanced access management: generate and manage secure API tokens, and control who can access your Knowledge Base via external integrations. Empower your team and automate workflows with confidence and security.',
								'hyve-lite'
							) }
						</p>
						<Button
							variant="primary"
							href={ setUtm( window.hyve?.pro, 'api-search' ) }
							target="_blank"
						>
							{ __( 'Unlock with Pro', 'hyve-lite' ) }
						</Button>
					</div>
				) }
			</Card>

			{ isPro && (
				<Slot
					name="api-access-tokens"
					fallback={
						<div className="hyve-next__card">
							<p>
								{ __(
									'The access token manager is on its way here.',
									'hyve-lite'
								) }
							</p>
						</div>
					}
				/>
			) }
		</>
	);
};
