/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import { Icon } from '@wordpress/components';

import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies.
 */
<<<<<<< HEAD
import { getRoutes, navigate, useRoute } from '../router';
=======
import { getAccessibleRoutes, navigate, useRoute } from '../router';
>>>>>>> origin/development

const TabNav = () => {
	const { screen } = useRoute();

	const hasAPI = useSelect( ( select ) => select( 'hyve' ).hasAPI() );

<<<<<<< HEAD
	const routes = getRoutes();
=======
	const routes = getAccessibleRoutes();
>>>>>>> origin/development

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
