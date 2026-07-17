/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';

import { Button, Icon } from '@wordpress/components';

import { useSelect } from '@wordpress/data';

import { applyFilters } from '@wordpress/hooks';

import { check } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { navigate } from '../router';

const SetupChecklist = () => {
	const { hasAPI, chunks } = useSelect( ( select ) => ( {
		hasAPI: select( 'hyve' ).hasAPI(),
		chunks: select( 'hyve' ).getTotalChunks(),
	} ) );

	const totalChunks = Number( chunks ?? 0 );

	// AI is "connected" via hosted Hyve Connect or a personal OpenAI key.
	const aiConnected = hasAPI;

	/**
	 * Setup steps. Pro prepends its license step (and locks the rest while
	 * the license is inactive) through the same filter.
	 */
	const steps = applyFilters( 'hyve.setup-steps', [
		{
			title: __( 'Connect to AI', 'hyve-lite' ),
			description: __(
				'Let Hyve Connect handle the AI, no API key or setup needed.',
				'hyve-lite'
			),
			done: aiConnected,
			locked: false,
			action: {
				label: __( 'Connect to AI', 'hyve-lite' ),
				onClick: () => navigate( 'settings', 'hyve-connect' ),
			},
			secondary: {
				label: __( 'Use your own API key instead', 'hyve-lite' ),
				onClick: () => navigate( 'settings', 'ai-provider' ),
			},
		},
		{
			title: __( 'Add content to the Knowledge Base', 'hyve-lite' ),
			description: __(
				'The chat can only answer from content you add. Start with your most useful pages.',
				'hyve-lite'
			),
			done: 0 < totalChunks,
			locked: ! aiConnected,
			action: {
				label: __( 'Add content', 'hyve-lite' ),
				onClick: () => navigate( 'kb' ),
			},
		},
	] );

	const required = steps.filter( ( step ) => ! step.optional );
	const doneCount = required.filter( ( step ) => step.done ).length;
	const firstIncomplete = steps.findIndex( ( step ) => ! step.done );

	return (
		<div className="hyve-next-checklist">
			<div className="hyve-next-checklist__head">
				<h2>{ __( "Let's get Hyve running", 'hyve-lite' ) }</h2>
				<span className="hyve-next-checklist__progress">
					{ sprintf(
						/* translators: 1: completed steps, 2: total required steps. */
						__( '%1$d of %2$d steps done', 'hyve-lite' ),
						doneCount,
						required.length
					) }
				</span>
			</div>

			{ steps.map( ( step, index ) => (
				<div
					key={ step.title }
					className={ `hyve-next-checklist__step${
						step.done ? ' is-done' : ''
					}${ step.locked ? ' is-locked' : '' }` }
				>
					<span className="hyve-next-checklist__num">
						{ step.done ? (
							<>
								<Icon icon={ check } size={ 16 } />
								<span className="screen-reader-text">
									{ __( 'Done', 'hyve-lite' ) }
								</span>
							</>
						) : (
							index + 1
						) }
					</span>
					<div className="hyve-next-checklist__body">
						<b>
							{ step.title }
							{ step.optional && (
								<span className="hyve-next-checklist__optional">
									{ __( 'Optional', 'hyve-lite' ) }
								</span>
							) }
						</b>
						<p>{ step.description }</p>
					</div>
					{ ! step.done && (
						<div className="hyve-next-checklist__actions">
							<Button
								__next40pxDefaultSize={ false }
								size="small"
								variant={
									index === firstIncomplete
										? 'primary'
										: 'secondary'
								}
								disabled={ step.locked }
								onClick={ step.action.onClick }
							>
								{ step.locked
									? sprintf(
											/* translators: %d: number of the step this one waits on. */
											__(
												'Waiting for step %d',
												'hyve-lite'
											),
											firstIncomplete + 1
									  )
									: step.action.label }
							</Button>

							{ step.secondary && ! step.locked && (
								<Button
									variant="link"
									className="hyve-next-checklist__alt"
									onClick={ step.secondary.onClick }
								>
									{ step.secondary.label }
								</Button>
							) }
						</div>
					) }
				</div>
			) ) }

			<p className="hyve-next-checklist__foot">
				{ __(
					'This checklist stays on your dashboard until the required steps are done.',
					'hyve-lite'
				) }
			</p>
		</div>
	);
};

export default SetupChecklist;
