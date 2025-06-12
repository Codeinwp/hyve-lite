/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import { Button } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { setUtm } from '../utils';

const UpsellContainer = ( { title, description, campaign, children } ) => {
	return (
		<div className="col-span-6 xl:col-span-4 relative">
			{ children }

			<div className="w-full h-full absolute bg-white/75 flex justify-center items-center top-0">
				<div className="flex flex-col items-center gap-2 p-6 rounded-lg bg-white shadow-lg">
					<div className="text-xl font-bold">{ title }</div>
					<p>{ description }</p>

					<Button
						variant="primary"
						className="mt-2"
						target="_blank"
						href={ setUtm( window?.hyve?.pro, campaign ) }
					>
						{ __( 'Get Hyve Pro!', 'hyve-lite' ) }
					</Button>
				</div>
			</div>
		</div>
	);
};

export default UpsellContainer;
