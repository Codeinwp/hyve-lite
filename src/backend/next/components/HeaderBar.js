/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import { Icon } from '@wordpress/components';

import { useSelect } from '@wordpress/data';

import { help } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { setUtm } from '../../utils';

const HeaderBar = () => {
	const hasAPI = useSelect( ( select ) => select( 'hyve' ).hasAPI() );

	const status = hasAPI
		? { tone: 'live', label: __( 'API connected', 'hyve-lite' ) }
		: { tone: 'setup', label: __( 'API not connected', 'hyve-lite' ) };

	return (
		<div className="hyve-next__bar">
			<img
				className="hyve-next__mark"
				src={ `${ window.hyve?.assets?.images }icon.png` }
				alt=""
				aria-hidden="true"
			/>
			<span className="hyve-next__name">
				{ __( 'Hyve', 'hyve-lite' ) }
			</span>
			{ window.hyve?.version && (
				<span className="hyve-next__pill">
					{ `v${ window.hyve.version }` }
				</span>
			) }

			<span className="hyve-next__spacer"></span>

			<span className={ `hyve-next__status is-${ status.tone }` }>
				<span className="hyve-next__status-dot"></span>
				{ status.label }
			</span>

			<a
				className="hyve-next__doclink"
				href={ window.hyve?.docs }
				target="_blank"
				rel="noreferrer"
			>
				<Icon icon={ help } size={ 15 } />
				{ __( 'Docs', 'hyve-lite' ) }
			</a>

			{ ! window.hyve?.license && (
				<a
					className="hyve-next__cta"
					href={ setUtm( window.hyve?.pro, 'header-upgrade' ) }
					target="_blank"
					rel="noreferrer"
				>
					{ __( 'Upgrade to Pro', 'hyve-lite' ) }
				</a>
			) }
		</div>
	);
};

export default HeaderBar;
