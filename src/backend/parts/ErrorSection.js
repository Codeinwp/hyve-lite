/**
 * WordPress dependencies.
 */

import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export const ErrorSection = () => {
	const instructionMessage =
		__( 'Please test the chat after solving the problem.', 'hyve-line' ) +
		' ' +
		__(
			'The error will disappear after a successful interaction with the chat.',
			'hyve-line'
		);

	return (
		<>
			{ window.hyve?.serviceErrors?.map(
				( { provider, date, message, code } ) => {
					return (
						<Notice
							key={ provider }
							className="service-error mb-4"
							isDismissible={ false }
							status="error"
						>
							<b>
								[{ provider }]{ ' ' }
								{ __( 'Service Error', 'hyve-line' ) }:
							</b>{ ' ' }
							{ message } ({ code }) |{ ' ' }
							{ new Date( date ).toLocaleString() }
							<br />
							<br />
							{ instructionMessage }
						</Notice>
					);
				}
			) }
		</>
	);
};
