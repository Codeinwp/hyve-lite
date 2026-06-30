/**
 * WordPress dependencies.
 */

import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';

export const ErrorSection = () => {
	const serviceErrors = useSelect(
		( select ) => select( 'hyve' ).getServiceErrors(),
		[]
	);

	const instructionMessage =
		__( 'Please test the chat after solving the problem.', 'hyve-lite' ) +
		' ' +
		__(
			'The error will disappear after a successful interaction with the chat.',
			'hyve-lite'
		);

	return (
		<>
			{ serviceErrors?.map( ( { provider, date, message, code } ) => {
				return (
					<Notice
						key={ provider }
						className="service-error mb-4 mx-auto max-w-270 break-all"
						isDismissible={ false }
						status="error"
					>
						<b>
							[{ provider }]{ ' ' }
							{ __( 'Service Error', 'hyve-lite' ) }:
						</b>{ ' ' }
						{ message } ({ code }) |{ ' ' }
						{ new Date( date ).toLocaleString() }
						<br />
						<br />
						{ instructionMessage }
					</Notice>
				);
			} ) }
		</>
	);
};
