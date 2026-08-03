/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import { Button, Modal } from '@wordpress/components';

import { useDispatch, useSelect } from '@wordpress/data';

import { useCallback, useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { setUtm, percentOf, quotaOf } from '../utils';
import Card from '../components/Card';
import Chip from '../components/Chip';
import FieldRow from '../components/FieldRow';

/**
 * A labelled usage meter row (used / limit with a bar).
 *
 * @param {Object} props       Component props.
 * @param {string} props.label Row label.
 * @param {number} props.used  Amount used.
 * @param {number} props.limit Allowance.
 * @param {string} props.unit  Trailing unit/label after the numbers.
 * @param {string} [props.sub] Optional sub-line under the bar.
 *
 * @return {Element} The row.
 */
const QuotaRow = ( { label, used, limit, unit, sub } ) => {
	const percent = percentOf( used, limit );

	return (
		<div className="hyve-next-quota__row">
			<div className="hyve-next-quota__lab">
				<span className="hyve-next-quota__k">{ label }</span>
				<span className="hyve-next-quota__v">
					{ Number( used ).toLocaleString() } /{ ' ' }
					{ Number( limit ).toLocaleString() } { unit }
				</span>
			</div>
			<div className="hyve-next-meter" aria-hidden="true">
				<i style={ { width: `${ percent }%` } }></i>
			</div>
			{ sub && <div className="hyve-next-quota__sub">{ sub }</div> }
		</div>
	);
};

/**
 * A selectable disconnect path (radio + label) for the confirm modal.
 *
 * @param {Object}   props          Component props.
 * @param {string}   props.value    Option value.
 * @param {boolean}  props.selected Whether this option is chosen.
 * @param {Function} props.onSelect Selection handler.
 * @param {string}   props.title    Option title.
 * @param {string}   props.text     Option description.
 *
 * @return {Element} The option.
 */
const DisconnectOption = ( { value, selected, onSelect, title, text } ) => {
	const id = `hyve-disconnect-${ value }`;

	return (
		<div className={ `hyve-next-opt${ selected ? ' is-selected' : '' }` }>
			<input
				id={ id }
				type="radio"
				name="hyve-disconnect"
				checked={ selected }
				onChange={ onSelect }
			/>
			<label htmlFor={ id } className="hyve-next-opt__txt">
				<strong>{ title }</strong>
				{ text }
			</label>
		</div>
	);
};

export const ConnectPanel = () => {
	const isConnectActive = useSelect( ( select ) =>
		select( 'hyve' ).isConnectActive()
	);
	const connect = useSelect( ( select ) => select( 'hyve' ).getConnect() );
	const connectSync = useSelect( ( select ) =>
		select( 'hyve' ).getConnectSync()
	);
	const settings = useSelect( ( select ) => select( 'hyve' ).getSettings() );
	const isQdrantActive = useSelect( ( select ) =>
		select( 'hyve' ).isQdrantActive()
	);

	const { setSetting, setAiMode, setConnect, setConnectSync } =
		useDispatch( 'hyve' );
	const { createNotice } = useDispatch( 'core/notices' );

	const [ isBusy, setBusy ] = useState( false );
	const [ isConfirmOpen, setConfirmOpen ] = useState( false );
	const [ disconnectMode, setDisconnectMode ] = useState( 'import' );

	// Pull the latest Connect stats and mirror them into the store.
	const refreshConnectStats = useCallback( async () => {
		const stats = await apiFetch( { path: `${ window.hyve.api }/stats` } );
		setConnect( stats?.connect ?? null );
		setConnectSync( stats?.connectSync ?? null );
	}, [ setConnect, setConnectSync ] );

	const isSyncing = Boolean( connectSync?.in_progress );

	// While the to-Connect sync job runs, poll the stats route so the progress
	// bar advances and the panel flips to its normal view when it finishes.
	useEffect( () => {
		if ( ! isSyncing ) {
			return undefined;
		}

		const timer = setInterval( async () => {
			try {
				await refreshConnectStats();
			} catch {
				// A transient poll failure just retries on the next tick.
			}
		}, 4000 );

		return () => clearInterval( timer );
	}, [ isSyncing, refreshConnectStats ] );

	const hasKey = Boolean( window.hyve?.hasAPIKey );
	const isPro = Boolean( window.hyve?.license );
	const isOffline = isConnectActive && connect?.service === 'error';

	const onEnable = async () => {
		setBusy( true );

		try {
			const next = { ...settings, ai_mode: 'hyve_connect' };
			const response = await apiFetch( {
				path: `${ window.hyve.api }/settings`,
				method: 'POST',
				data: { data: next },
			} );

			if ( response.error ) {
				throw new Error( response.error );
			}

			setSetting( 'ai_mode', 'hyve_connect' );
			setAiMode( 'hyve_connect' );

			await refreshConnectStats();

			createNotice( 'success', __( 'Hyve Connect is on.', 'hyve-lite' ), {
				type: 'snackbar',
				isDismissible: true,
			} );
		} catch ( error ) {
			createNotice( 'error', error?.message ?? String( error ), {
				type: 'snackbar',
				isDismissible: true,
			} );
		}

		setBusy( false );
	};

	const onDisconnect = async () => {
		setBusy( true );

		try {
			const response = await apiFetch( {
				path: `${ window.hyve.api }/connect`,
				method: 'POST',
				data: { mode: disconnectMode },
			} );

			if ( response.error ) {
				throw new Error( response.error );
			}

			setSetting( 'ai_mode', 'self_hosted' );
			setAiMode( 'self_hosted' );
			setConnect( null );
			setConnectSync( null );
			setConfirmOpen( false );

			createNotice(
				'success',
				'import' === disconnectMode
					? __(
							'Hyve Connect disconnected. Your content was imported back.',
							'hyve-lite'
					  )
					: __(
							'Hyve Connect disconnected and your knowledge base was cleared.',
							'hyve-lite'
					  ),
				{ type: 'snackbar', isDismissible: true }
			);
		} catch ( error ) {
			createNotice( 'error', error?.message ?? String( error ), {
				type: 'snackbar',
				isDismissible: true,
			} );
		}

		setBusy( false );
	};

	const onSync = async () => {
		setBusy( true );

		try {
			const response = await apiFetch( {
				path: `${ window.hyve.api }/connect/reconcile`,
				method: 'POST',
			} );

			if ( response?.error ) {
				throw new Error( response.error );
			}

			// Reconcile may have queued a re-push; refresh so progress shows.
			await refreshConnectStats();

			createNotice(
				'success',
				__( 'Knowledge base synced with Hyve Connect.', 'hyve-lite' ),
				{ type: 'snackbar', isDismissible: true }
			);
		} catch ( error ) {
			createNotice( 'error', error?.message ?? String( error ), {
				type: 'snackbar',
				isDismissible: true,
			} );
		}

		setBusy( false );
	};

	// --- Connected ---------------------------------------------------------
	if ( isConnectActive ) {
		const kbQuota = quotaOf( connect, 'kb' );
		const chatQuota = quotaOf( connect, 'chat' );
		const isExhausted = kbQuota.full || chatQuota.full;

		const isBlocked = Boolean( connectSync?.blocked );
		const isLicenseExpired = 'expired' === connect?.license;
		const syncTotal = Number( connectSync?.total ?? 0 );
		const syncCurrent = Math.min(
			syncTotal,
			Number( connectSync?.current ?? 0 )
		);
		const syncPercent = syncTotal
			? Math.round( ( syncCurrent / syncTotal ) * 100 )
			: 0;

		let blockedReason = __(
			'It goes over the free plan limit. Upgrade to Pro to sync everything.',
			'hyve-lite'
		);
		if ( isLicenseExpired ) {
			blockedReason = __(
				'It goes over the free plan limit that applies while your license is expired.',
				'hyve-lite'
			);
		} else if ( isPro ) {
			blockedReason = __(
				'It goes over your current plan limit.',
				'hyve-lite'
			);
		}

		let statusText = __(
			'Running on the free plan. Connect a license to raise these limits.',
			'hyve-lite'
		);
		if ( isOffline ) {
			statusText = __(
				"Can't reach Hyve Connect right now. We'll keep retrying.",
				'hyve-lite'
			);
		} else if ( isPro ) {
			statusText = __(
				'Running on Pro. Limits pool across all your sites.',
				'hyve-lite'
			);
		}

		const chip = isOffline ? (
			<Chip tone="bad">{ __( 'Offline', 'hyve-lite' ) }</Chip>
		) : (
			<Chip tone="ok">{ __( 'Connected', 'hyve-lite' ) }</Chip>
		);

		return (
			<>
				<Card
					title={ __( 'Hyve Connect', 'hyve-lite' ) }
					actions={ chip }
				>
					{ isSyncing && (
						<div className="hyve-next-notice is-info is-flush">
							<div className="hyve-next-notice__body">
								<strong className="hyve-next-notice__title">
									{ __(
										'Syncing your knowledge base…',
										'hyve-lite'
									) }
								</strong>
								<p className="hyve-next-notice__text">
									{ sprintf(
										/* translators: %1$d: sources synced. %2$d: total sources. */
										__(
											'Moving your content to Hyve Connect: %1$d of %2$d sources.',
											'hyve-lite'
										),
										syncCurrent,
										syncTotal
									) }
								</p>
								<div
									className="hyve-next-meter"
									aria-hidden="true"
								>
									<i
										style={ { width: `${ syncPercent }%` } }
									></i>
								</div>
							</div>
						</div>
					) }

					{ isLicenseExpired && (
						<div className="hyve-next-notice is-warn is-flush">
							<div className="hyve-next-notice__body">
								<strong>
									{ __(
										'Your Hyve license has expired.',
										'hyve-lite'
									) }
								</strong>{ ' ' }
								{ __(
									'You are on free plan limits until you renew it. Your assistant keeps answering in the meantime.',
									'hyve-lite'
								) }
							</div>
						</div>
					) }

					{ isBlocked && (
						<div className="hyve-next-notice is-warn is-flush">
							<div className="hyve-next-notice__body">
								<strong>
									{ __(
										"Some content couldn't be synced.",
										'hyve-lite'
									) }
								</strong>{ ' ' }
								{ blockedReason }
							</div>
						</div>
					) }

					<FieldRow
						label={ __( 'Status', 'hyve-lite' ) }
						description={
							isPro
								? __(
										'Unlocked by your Hyve license.',
										'hyve-lite'
								  )
								: __(
										"Identified by this site's domain, no key needed.",
										'hyve-lite'
								  )
						}
					>
						<div className="hyve-next-quota__status">
							{ chip }
							<span>{ statusText }</span>
						</div>
					</FieldRow>

					{ ! isOffline && (
						<FieldRow
							label={ __( 'Usage', 'hyve-lite' ) }
							description={
								isPro
									? __(
											'Pooled across your licensed sites.',
											'hyve-lite'
									  )
									: __(
											'Pulled live from Hyve Connect.',
											'hyve-lite'
									  )
							}
						>
							<div className="hyve-next-quota">
								<QuotaRow
									label={ __(
										'Knowledge base',
										'hyve-lite'
									) }
									used={ kbQuota.used }
									limit={ kbQuota.limit }
									unit={ __( 'chunks', 'hyve-lite' ) }
									sub={
										kbQuota.full
											? __(
													'Limit reached.',
													'hyve-lite'
											  )
											: undefined
									}
								/>
								<QuotaRow
									label={ __( 'Chat messages', 'hyve-lite' ) }
									used={ chatQuota.used }
									limit={ chatQuota.limit }
									unit={ __( 'this month', 'hyve-lite' ) }
									sub={
										chatQuota.full
											? __(
													'Limit reached.',
													'hyve-lite'
											  )
											: undefined
									}
								/>
							</div>
						</FieldRow>
					) }

					{ ! isPro && (
						<div
							className={ `hyve-next-act__upsell${
								isExhausted ? ' is-exhausted' : ''
							}` }
						>
							<strong>
								{ isExhausted
									? __(
											"You've reached your free limit",
											'hyve-lite'
									  )
									: __( 'Need more room?', 'hyve-lite' ) }
							</strong>
							<p>
								{ isExhausted
									? __(
											"You've used up your free Hyve Connect allowance. Upgrade to Pro to keep indexing content and answering visitors.",
											'hyve-lite'
									  )
									: __(
											'Hyve Pro raises your knowledge base and monthly messages, pooled across all your sites.',
											'hyve-lite'
									  ) }
							</p>
							<Button
								variant="primary"
								href={ setUtm(
									window.hyve?.pro,
									'hyve-connect'
								) }
								target="_blank"
							>
								{ __( 'Upgrade to Pro', 'hyve-lite' ) }
							</Button>
						</div>
					) }

					{ isPro && isExhausted && (
						<div className="hyve-next-notice is-warn is-flush">
							<div className="hyve-next-notice__body">
								<strong>
									{ __(
										"You've reached your plan limit.",
										'hyve-lite'
									) }
								</strong>{ ' ' }
								{ __(
									'Usage pools across your licensed sites and resets next cycle.',
									'hyve-lite'
								) }
							</div>
						</div>
					) }

					<div className="hyve-next-card__foot">
						<Button
							variant="secondary"
							isBusy={ isBusy }
							disabled={ isBusy || isSyncing || isOffline }
							onClick={ onSync }
						>
							{ __( 'Sync', 'hyve-lite' ) }
						</Button>
						<Button
							variant="secondary"
							isDestructive
							disabled={ isBusy }
							onClick={ () => setConfirmOpen( true ) }
						>
							{ __( 'Disconnect', 'hyve-lite' ) }
						</Button>
					</div>
				</Card>

				{ isConfirmOpen && (
					<Modal
						className="hyve-next-modal"
						size="medium"
						title={ __( 'Disconnect Hyve Connect?', 'hyve-lite' ) }
						onRequestClose={ () => setConfirmOpen( false ) }
					>
						<p>
							{ __(
								'Your site will stop using Hyve Connect. Choose what happens to the content it indexed:',
								'hyve-lite'
							) }
						</p>
						<div className="hyve-next-opts">
							<DisconnectOption
								value="import"
								selected={ 'import' === disconnectMode }
								onSelect={ () => setDisconnectMode( 'import' ) }
								title={ __(
									'Import my content, then disconnect',
									'hyve-lite'
								) }
								text={ __(
									'Bring your knowledge base back to this site. Add your own OpenAI key afterward and keep answering, no re-indexing.',
									'hyve-lite'
								) }
							/>
							<DisconnectOption
								value="clear"
								selected={ 'clear' === disconnectMode }
								onSelect={ () => setDisconnectMode( 'clear' ) }
								title={ __(
									'Clear my knowledge base',
									'hyve-lite'
								) }
								text={ __(
									'Delete everything on this site and on Hyve Connect. This cannot be undone.',
									'hyve-lite'
								) }
							/>
						</div>
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
	}

	// --- Not connected -----------------------------------------------------
	return (
		<Card
			title={ __( 'Hyve Connect', 'hyve-lite' ) }
			actions={
				<Chip tone="muted">{ __( 'Not connected', 'hyve-lite' ) }</Chip>
			}
		>
			{ isQdrantActive ? (
				<div className="hyve-next-notice is-warn is-flush">
					<div className="hyve-next-notice__body">
						<strong>
							{ __( 'Qdrant is connected.', 'hyve-lite' ) }
						</strong>{ ' ' }
						{ __(
							"Qdrant and Hyve Connect can't run at the same time. Disconnect Qdrant first, then switch this site to Hyve Connect.",
							'hyve-lite'
						) }
					</div>
				</div>
			) : (
				hasKey && (
					<div className="hyve-next-notice is-warn is-flush">
						<div className="hyve-next-notice__body">
							<strong>
								{ __(
									"You're using your own OpenAI key.",
									'hyve-lite'
								) }
							</strong>{ ' ' }
							{ __(
								"Hyve Connect and a self-hosted key can't run at the same time. Enabling it stops using your key, and your indexed content will be synced to Hyve Connect.",
								'hyve-lite'
							) }
						</div>
					</div>
				)
			) }

			<div className="hyve-next-card__intro">
				<p>
					{ __(
						'Turn on Hyve Connect to index your content and answer visitors without an OpenAI key. We host the AI; you just switch it on.',
						'hyve-lite'
					) }
				</p>
				<ul className="hyve-next-feats">
					<li>
						{ __( 'No API key or account to set up', 'hyve-lite' ) }
					</li>
					<li>
						{ __(
							'Indexing, embeddings, and chat all handled for you',
							'hyve-lite'
						) }
					</li>
					<li>
						{ __(
							'Your license unlocks higher limits automatically',
							'hyve-lite'
						) }
					</li>
				</ul>
			</div>

			<div className="hyve-next-card__foot">
				<Button
					variant="primary"
					isBusy={ isBusy }
					disabled={ isBusy || isQdrantActive }
					onClick={ onEnable }
				>
					{ hasKey
						? __( 'Switch to Hyve Connect', 'hyve-lite' )
						: __( 'Enable Hyve Connect', 'hyve-lite' ) }
				</Button>
				<span className="hyve-next-card__hint">
					{ isQdrantActive
						? __(
								'Disconnect Qdrant to enable Hyve Connect.',
								'hyve-lite'
						  )
						: __( 'Free plan, no key required.', 'hyve-lite' ) }
				</span>
			</div>
		</Card>
	);
};

export default ConnectPanel;
