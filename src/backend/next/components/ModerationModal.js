/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';

import { Button, Modal } from '@wordpress/components';

import { useState } from '@wordpress/element';

import { info } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { moderationLabels, onProcessData } from '../../utils';

/**
 * Moderation review modal: flagged categories with score bars and an
 * override action for false positives.
 *
 * @param {Object}   props           Component props.
 * @param {?Object}  props.post      Post with a `review` scores object.
 * @param {string}   props.type      onProcessData type ('core'/'knowledge').
 * @param {Function} props.onClose   Close handler.
 * @param {Function} props.onSuccess Called after a successful override.
 */
const ModerationModal = ( { post, type = '', onClose, onSuccess } ) => {
	const [ isBusy, setBusy ] = useState( false );

	if ( ! post?.review ) {
		return null;
	}

	const onOverride = async () => {
		setBusy( true );

		await onProcessData( {
			post,
			type,
			params: {
				action: 'override',
			},
			onSuccess: () => {
				onClose();
				onSuccess();
			},
		} );

		setBusy( false );
	};

	return (
		<Modal
			className="hyve-next-modal"
			size="large"
			title={ sprintf(
				// translators: %s the reason for failed moderation.
				__( 'Failed Moderation: %s', 'hyve-lite' ),
				post.title || __( 'Untitled', 'hyve-lite' )
			) }
			shouldCloseOnClickOutside={ false }
			onRequestClose={ onClose }
		>
			<p>
				{ __(
					'The content of the post listed here could not be added or updated due to non-compliance with content policies. Review these to understand the limitations and possibly modify content to align with required standards.',
					'hyve-lite'
				) }
			</p>

			<p>
				{ __( 'The following content was flagged for:', 'hyve-lite' ) }
			</p>

			<div className="hyve-next-modrows">
				{ Object.keys( post.review ).map( ( category ) => {
					const percent = Math.floor( post.review[ category ] * 100 );

					return (
						<div key={ category } className="hyve-next-modrow">
							<span className="hyve-next-modrow__label">
								{ moderationLabels[ category ]?.label ??
									category }
								<Button
									icon={ info }
									showTooltip
									label={
										moderationLabels[ category ]
											?.description
									}
								/>
							</span>
							<span
								className="hyve-next-meter"
								aria-hidden="true"
							>
								<i style={ { width: `${ percent }%` } }></i>
							</span>
							<span className="hyve-next-modrow__pct">
								{ percent }%
							</span>
						</div>
					);
				} ) }
			</div>

			<p>
				{ __(
					"Occasionally, OpenAI's Moderation system may incorrectly flag content as a violation; these are false positives. Such errors can occur because automated systems sometimes lack the necessary context to interpret nuances accurately. If your content is flagged but you believe it adheres to the guidelines, please manually review it. Should you determine it does not violate the content policies, you can click the button below to override the moderation decision.",
					'hyve-lite'
				) }
			</p>

			<div className="hyve-next-modal__actions">
				<Button
					variant="tertiary"
					disabled={ isBusy }
					onClick={ onClose }
				>
					{ __( 'Cancel', 'hyve-lite' ) }
				</Button>
				<Button
					variant="primary"
					isBusy={ isBusy }
					disabled={ isBusy }
					onClick={ onOverride }
				>
					{ __( 'Override moderation', 'hyve-lite' ) }
				</Button>
			</div>
		</Modal>
	);
};

export default ModerationModal;
