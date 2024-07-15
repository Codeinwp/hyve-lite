/**
 * WordPress dependencies.
 */
import {
	__,
	sprintf
} from '@wordpress/i18n';

import {
	Button,
	Modal
} from '@wordpress/components';

import { useState } from '@wordpress/element';

import { info } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import {
	moderationLabels,
	onProcessData
} from '../utils';

const ModerationReview = ({
	post,
	type = '',
	isOpen,
	onClose,
	onSuccess = () => {}
}) => {
	const [ isBusy, setBusy ] = useState( false );

	const onOverride = async() => {
		setBusy( true );

		await onProcessData({
			post,
			type,
			params: {
				action: 'override'
			},
			onSuccess: () => {
				onClose();
				onSuccess();
			}
		});

		setBusy( false );
	};

	if ( ! isOpen || ! post?.review ) {
		return null;
	}

	return (
		<Modal
			title={ sprintf( __( 'Failed Moderation: %s', 'hyve' ), ( post?.title || __( 'Untitled', 'hyve' ) ) ) }
			onRequestClose={ onClose }
			shouldCloseOnClickOutside={ false }
			className="md:max-w-3xl md:w-full"
		>
			<p className="pb-4">{ __( 'The content of the post listed here could not be added or updated due to non-compliance with content policies. Review these to understand the limitations and possibly modify content to align with required standards.', 'hyve' ) }</p>

			<p className="pb-4">{ __( 'The following content was flagged for:', 'hyve' ) }</p>

			{ post && Object.keys( post.review ).map( review => (
				<div
					key={ review }
					className="flex items-center gap-4 bg-white px-6 py-4 border-b text-sm text-gray-500"
				>
					<div className="flex items-center max-w-48 w-48">
						<h4 className="overflow-hidden text-ellipsis block text-nowrap">{ moderationLabels[ review ].label }</h4>

						<Button
							icon={ info }
							showTooltip={ true }
							isPressed={ false }
							label={ moderationLabels[ review ].description }
							className="focus:!shadow-none"
						/>
					</div>

					<div className="flex-1 text-left rtl:text-right overflow-hidden">
						<div className="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
							<div
								className="bg-blue-600 h-2.5 rounded-full"
								style={ {
									width: `${ Math.floor( post.review[ review ] * 100 ) }%`
								} }
							/>
						</div>
					</div>

					<div className="w-2/8 text-center flex">
						<span>{ Math.floor( post.review[ review ] * 100 ) }%</span>
					</div>
				</div>
			) ) }

			<p className="pt-4">{ __( 'Occasionally, OpenAI\'s Moderation system may incorrectly flag content as a violation—these are false positives. Such errors can occur because automated systems sometimes lack the necessary context to interpret nuances accurately. If your content is flagged but you believe it adheres to the guidelines, please manually review it. Should you determine it does not violate the content policies, you can click the button below to override the moderation decision.', 'hyve' ) }</p>

			<div className="flex">
				<Button
					variant="secondary"
					className="mt-4 ml-auto"
					disabled={ isBusy }
					isBusy={ isBusy }
					onClick={ onOverride }
				>
					{ __( 'Override Moderation', 'hyve' ) }
				</Button>
			</div>
		</Modal>
	);
};

export default ModerationReview;
