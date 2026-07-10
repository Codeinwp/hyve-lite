/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import { useSelect } from '@wordpress/data';

/**
 * Shared chunk-limit warning, shown in every add-content flow once the free
 * knowledge base limit is reached (never with Qdrant active).
 */
const ChunkLimitNotice = () => {
	const hasReachedLimit = useSelect( ( select ) =>
		select( 'hyve' ).hasReachedLimit()
	);

	if ( ! hasReachedLimit ) {
		return null;
	}

	return (
		<div className="hyve-next-notice is-warn">
			<div className="hyve-next-notice__body">
				<p className="hyve-next-notice__text">
					{ __(
						'You have reached the limit of posts that can be added to the Knowledge Base. Please delete existing posts if you wish to add more.',
						'hyve-lite'
					) }
				</p>
			</div>
		</div>
	);
};

export default ChunkLimitNotice;
