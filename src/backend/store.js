/**
 * WordPress dependencies.
 */
import { createReduxStore, register } from '@wordpress/data';

const DEFAULT_STATE = {
	route: 'home',
	hasLoaded: false,
	settings: {},
	processed: [],
	hasAPI: Boolean( window.hyve.hasAPIKey ),
	isQdrantActive: Boolean( window.hyve.isQdrantActive ),
	totalChunks: 0,
};

const actions = {
	setRoute( route ) {
		return {
			type: 'SET_ROUTE',
			route,
		};
	},
	setLoading() {
		return {
			type: 'HAS_LOADED',
		};
	},
	setSettings( settings ) {
		return {
			type: 'SET_SETTINGS',
			settings,
		};
	},
	setSetting( key, value ) {
		return {
			type: 'SET_SETTING',
			key,
			value,
		};
	},
	setHasAPI( hasAPI ) {
		return {
			type: 'SET_HAS_API',
			hasAPI,
		};
	},
	setTotalChunks( totalChunks ) {
		return {
			type: 'SET_TOTAL_CHUNKS',
			totalChunks,
		};
	},
	setQdrantStatus( isQdrantActive ) {
		return {
			type: 'SET_QDRANT_STATUS',
			isQdrantActive,
		};
	},
};

const selectors = {
	getRoute( state ) {
		return state.route;
	},
	hasLoaded( state ) {
		return state.hasLoaded;
	},
	getSettings( state ) {
		return state.settings;
	},
	hasAPI( state ) {
		return state.hasAPI;
	},
	getTotalChunks( state ) {
		return state.totalChunks;
	},
	hasReachedLimit( state ) {
		return (
			window.hyve.chunksLimit <= Number( state.totalChunks ) &&
			! Boolean( window.hyve.isQdrantActive )
		);
	},
	isQdrantActive( state ) {
		return state.isQdrantActive;
	},
};

const reducer = ( state = DEFAULT_STATE, action ) => {
	switch ( action.type ) {
		case 'SET_ROUTE':
			return {
				...state,
				route: action.route,
			};
		case 'HAS_LOADED':
			return {
				...state,
				hasLoaded: true,
			};
		case 'SET_SETTINGS':
			return {
				...state,
				settings: action.settings,
			};
		case 'SET_SETTING':
			return {
				...state,
				settings: {
					...state.settings,
					[ action.key ]: action.value,
				},
			};
		case 'SET_HAS_API':
			return {
				...state,
				hasAPI: action.hasAPI,
			};
		case 'SET_TOTAL_CHUNKS':
			return {
				...state,
				totalChunks: action.totalChunks,
			};
		case 'SET_QDRANT_STATUS':
			return {
				...state,
				isQdrantActive: action.isQdrantActive,
			};
		default:
			return state;
	}
};

const store = createReduxStore( 'hyve', {
	reducer,
	actions,
	selectors,
} );

register( store );
