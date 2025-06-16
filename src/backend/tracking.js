/**
 * External dependencies.
 */
import objectHash from 'object-hash';

/**
 * WordPress dependencies.
 */
import { subscribe, select } from '@wordpress/data';

export function setUpTracking() {
	// Send the stats.
	Object.entries( window.hyve.stats )?.forEach( ( [ key, value ] ) => {
		window.hyveTrk?.add( {
			feature: 'stats',
			featureComponent: key,
			featureValue: Number( value ),
		} );
	} );

	// Track the current settings when an option is changed.
	let prevSettingsHash = '';
	subscribe( () => {
		const settings = select( 'hyve' ).getSettings();
		const settingsHash = objectHash( settings );

		if ( ! prevSettingsHash ) {
			prevSettingsHash = settingsHash;
		}

		if ( settingsHash === prevSettingsHash ) {
			return;
		}

		Object.entries( settings ).forEach( ( [ key, value ] ) => {
			if (
				[ 'api_key', 'assistant_id', '_endpoint' ].some( ( excluded ) =>
					key.includes( excluded )
				)
			) {
				return;
			}

			window.hyveTrk?.set( `hyve-${ key }`, {
				feature: 'settings',
				featureComponents: key,
				featureValue: value,
			} );
		} );
	}, 'hyve' );

	window.tiTrk?.uploadEvents?.();
}
