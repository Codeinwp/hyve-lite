/**
 * WordPress dependencies.
 */
import { createReduxStore, register } from '@wordpress/data';

const AI_MODE = window.hyve.aiMode || 'self_hosted';

const DEFAULT_STATE = {
	route: window.hyve?.view || 'dashboard',
	hasLoaded: false,
	settings: {},
	processed: [],
	aiMode: AI_MODE,
	connect: window.hyve.connect || null,
	connectSync: window.hyve.connectSync || null,
	hasKey: Boolean( window.hyve.hasAPIKey ),
	isQdrantActive: Boolean( window.hyve.isQdrantActive ),
	stats: window.hyve.stats || {},
	chart: window.hyve.chart || null,
	totalChunks: Number( window.hyve.stats?.totalChunks ?? 0 ),
	attentionCount: null,
	serviceErrors: window.hyve.serviceErrors || [],
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
	setStats( stats ) {
		return {
			type: 'SET_STATS',
			stats,
		};
	},
	setChart( chart ) {
		return {
			type: 'SET_CHART',
			chart,
		};
	},
	setQdrantStatus( isQdrantActive ) {
		return {
			type: 'SET_QDRANT_STATUS',
			isQdrantActive,
		};
	},
	setAiMode( aiMode ) {
		return {
			type: 'SET_AI_MODE',
			aiMode,
		};
	},
	setConnect( connect ) {
		return {
			type: 'SET_CONNECT',
			connect,
		};
	},
	setConnectSync( connectSync ) {
		return {
			type: 'SET_CONNECT_SYNC',
			connectSync,
		};
	},
	setAttentionCount( attentionCount ) {
		return {
			type: 'SET_ATTENTION_COUNT',
			attentionCount,
		};
	},
	setServiceErrors( serviceErrors ) {
		return {
			type: 'SET_SERVICE_ERRORS',
			serviceErrors,
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
		// A saved key, or Connect handling the AI, both count as "AI is set up".
		return state.hasKey || 'hyve_connect' === state.aiMode;
	},
	getTotalChunks( state ) {
		return state.totalChunks;
	},
	getStats( state ) {
		return state.stats;
	},
	getChart( state ) {
		return state.chart;
	},
	hasReachedLimit( state ) {
		return (
			window.hyve.chunksLimit <= Number( state.totalChunks ) &&
			! state.isQdrantActive &&
			'hyve_connect' !== state.aiMode
		);
	},
	isQdrantActive( state ) {
		return state.isQdrantActive;
	},
	isConnectActive( state ) {
		return 'hyve_connect' === state.aiMode;
	},
	getConnect( state ) {
		return state.connect;
	},
	getConnectSync( state ) {
		return state.connectSync;
	},
	getAttentionCount( state ) {
		return state.attentionCount;
	},
	getServiceErrors( state ) {
		return state.serviceErrors;
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
				hasKey: action.hasAPI,
			};
		case 'SET_TOTAL_CHUNKS':
			return {
				...state,
				totalChunks: action.totalChunks,
			};
		case 'SET_STATS':
			return {
				...state,
				stats: action.stats,
			};
		case 'SET_CHART':
			return {
				...state,
				chart: action.chart,
			};
		case 'SET_QDRANT_STATUS':
			return {
				...state,
				isQdrantActive: action.isQdrantActive,
			};
		case 'SET_AI_MODE':
			return {
				...state,
				aiMode: action.aiMode,
			};
		case 'SET_CONNECT':
			return {
				...state,
				connect: action.connect,
			};
		case 'SET_CONNECT_SYNC':
			return {
				...state,
				connectSync: action.connectSync,
			};
		case 'SET_ATTENTION_COUNT':
			return {
				...state,
				attentionCount: action.attentionCount,
			};
		case 'SET_SERVICE_ERRORS':
			return {
				...state,
				serviceErrors: action.serviceErrors,
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
