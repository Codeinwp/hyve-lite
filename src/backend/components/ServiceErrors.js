/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';

import { useSelect } from '@wordpress/data';

/**
 * Persistent service-error notices (OpenAI/Qdrant outages, expired keys,
 * missing credits). Seeded from the page load; the shared apiFetch middleware
 * refreshes the store on every settings save.
 */
const ServiceErrors = () => {
	const serviceErrors = useSelect(
		( select ) => select( 'hyve' ).getServiceErrors(),
		[]
	);

	if ( ! serviceErrors?.length ) {
		return null;
	}

	const instructionMessage = __(
		'After fixing the issue, send a test message in the chat. The error will clear once a message succeeds.',
		'hyve-lite'
	);

	return serviceErrors.map( ( { provider, date, message, code } ) => (
		<div key={ provider } className="hyve-next-notice is-bad">
			<div className="hyve-next-notice__body">
				<strong className="hyve-next-notice__title">
					{ sprintf(
						/* translators: %s: the external service reporting the error, e.g. OpenAI. */
						__( '[%s] Service Error:', 'hyve-lite' ),
						provider
					) }{ ' ' }
					{ message } ({ code })
				</strong>
				<p className="hyve-next-notice__text">
					{ new Date( date ).toLocaleString() }
					{ ' · ' }
					{ instructionMessage }
				</p>
			</div>
		</div>
	) );
};

export default ServiceErrors;
