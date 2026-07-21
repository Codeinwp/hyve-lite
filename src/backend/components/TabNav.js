/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import { Icon } from '@wordpress/components';

import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies.
 */
import { getAccessibleRoutes, navigate, useRoute } from '../router';

const TabNav = () => {
	const { screen } = useRoute();

	const hasAPI = useSelect( ( select ) => select( 'hyve' ).hasAPI() );

	const routes = getAccessibleRoutes();

	return (
		<nav
			className="hyve-next__tabs"
			aria-label={ __( 'Hyve sections', 'hyve-lite' ) }
		>
			{ Object.entries( routes ).map( ( [ key, route ] ) => {
				const isMuted = ! hasAPI && route.requiresAPI;

				return (
					<button
						key={ key }
						type="button"
						className={ `hyve-next__tab${
							key === screen ? ' is-active' : ''
						}` }
						disabled={ isMuted }
						aria-current={ key === screen ? 'page' : undefined }
						onClick={ () => navigate( key ) }
					>
						<Icon icon={ route.icon } size={ 16 } />
						{ route.label }
					</button>
				);
			} ) }
		</nav>
	);
};

export default TabNav;
