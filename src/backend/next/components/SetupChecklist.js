/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';

import { Button, Icon } from '@wordpress/components';

import { useSelect } from '@wordpress/data';

import { check } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { navigate } from '../router';

const SetupChecklist = () => {
	const { hasAPI, isQdrantActive, chunks } = useSelect( ( select ) => ( {
		hasAPI: select( 'hyve' ).hasAPI(),
		isQdrantActive: select( 'hyve' ).isQdrantActive(),
		chunks: select( 'hyve' ).getTotalChunks(),
	} ) );

	const totalChunks = Number( chunks ?? 0 );

	const steps = [
		{
			title: __( 'Connect OpenAI', 'hyve-lite' ),
			description: __(
				'Hyve uses your OpenAI API key to index content and answer visitors.',
				'hyve-lite'
			),
			done: hasAPI,
			locked: false,
			action: {
				label: __( 'Add API key', 'hyve-lite' ),
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
			locked: ! hasAPI,
			action: {
				label: __( 'Add content', 'hyve-lite' ),
				onClick: () => navigate( 'kb' ),
			},
		},
		{
			title: __( 'Connect Qdrant', 'hyve-lite' ),
			optional: true,
			description: __(
				'Only worth it for large sites: lifts the local limit on Knowledge Base size.',
				'hyve-lite'
			),
			done: isQdrantActive,
			locked: ! hasAPI,
			action: {
				label: __( 'View integrations', 'hyve-lite' ),
				onClick: () => navigate( 'settings', 'qdrant' ),
			},
		},
	];

	const doneCount = steps
		.slice( 0, 2 )
		.filter( ( step ) => step.done ).length;

	return (
		<div className="hyve-next-checklist">
			<div className="hyve-next-checklist__head">
				<h2>{ __( "Let's get Hyve running", 'hyve-lite' ) }</h2>
				<span className="hyve-next-checklist__progress">
					{ sprintf(
						/* translators: 1: completed steps, 2: total required steps. */
						__( '%1$d of %2$d steps done', 'hyve-lite' ),
						doneCount,
						2
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
						<Button
							__next40pxDefaultSize={ false }
							size="small"
							variant={ 0 === index ? 'primary' : 'secondary' }
							disabled={ step.locked }
							onClick={ step.action.onClick }
						>
							{ step.locked
								? __( 'Waiting for step 1', 'hyve-lite' )
								: step.action.label }
						</Button>
					) }
				</div>
			) ) }

			<p className="hyve-next-checklist__foot">
				{ __(
					'This checklist stays on your dashboard until the two required steps are done.',
					'hyve-lite'
				) }
			</p>
		</div>
	);
};

export default SetupChecklist;
