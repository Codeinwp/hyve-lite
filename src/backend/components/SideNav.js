/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import { useSelect } from '@wordpress/data';

import { Fragment } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { navigate } from '../router';

const SideNav = ( { screen, subs, active } ) => {
	const hasAPI = useSelect( ( select ) => select( 'hyve' ).hasAPI() );

	let lastGroup = null;

	return (
		<nav
			className="hyve-next-side"
			aria-label={ __( 'Settings sections', 'hyve-lite' ) }
		>
			{ subs.map( ( [ key, entry ] ) => {
				const heading =
					entry.group && entry.group !== lastGroup
						? entry.group
						: null;
				lastGroup = entry.group ?? lastGroup;

				return (
					<Fragment key={ key }>
						{ heading && (
							<span className="hyve-next-side__group">
								{ heading }
							</span>
						) }
						<button
							type="button"
							className={ `hyve-next-side__link${
								key === active ? ' is-active' : ''
							}` }
							disabled={ ! hasAPI && entry.requiresAPI }
							aria-current={ key === active ? 'page' : undefined }
							onClick={ () => navigate( screen, key ) }
						>
							{ entry.label }
						</button>
					</Fragment>
				);
			} ) }
		</nav>
	);
};

export default SideNav;
