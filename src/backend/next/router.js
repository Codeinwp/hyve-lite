/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import { useEffect, useState } from '@wordpress/element';

import { applyFilters } from '@wordpress/hooks';

import {
	archive,
	blockMeta,
	cog,
	comment,
	commentContent,
	home,
	settings,
} from '@wordpress/icons';

/**
 * Route registry. The URL is the source of truth (`&nav=<screen>&sub=<panel>`).
 * Sub-panels: `default` is the landing panel, `hidden` keeps drill-ins out of
 * the subnav. Pro extends entries via the `hyve.next.routes` filter.
 */
const ROUTES = {
	dashboard: {
		label: __( 'Dashboard', 'hyve-lite' ),
		icon: home,
		capability: 'manage_options',
		requiresAPI: false,
	},
	kb: {
		label: __( 'Knowledge Base', 'hyve-lite' ),
		icon: archive,
		capability: 'manage_options',
		requiresAPI: true,
		subs: {
			all: {
				label: __( 'All sources', 'hyve-lite' ),
				default: true,
			},
			attention: {
				label: __( 'Needs attention', 'hyve-lite' ),
			},
			faq: {
				label: __( 'FAQ', 'hyve-lite' ),
				isPro: true,
			},
			'source-wordpress': {
				label: __( 'WordPress', 'hyve-lite' ),
				hidden: true,
			},
			'source-custom': {
				label: __( 'Custom data', 'hyve-lite' ),
				hidden: true,
				isPro: true,
			},
			'source-url': {
				label: __( 'Website URL', 'hyve-lite' ),
				hidden: true,
				isPro: true,
			},
			'source-sitemap': {
				label: __( 'Sitemap', 'hyve-lite' ),
				hidden: true,
				isPro: true,
			},
			'source-documents': {
				label: __( 'Documents', 'hyve-lite' ),
				hidden: true,
				isPro: true,
			},
		},
	},
	messages: {
		label: __( 'Messages', 'hyve-lite' ),
		icon: comment,
		capability: 'hyve_read_messages',
		requiresAPI: true,
		subs: {
			conversations: {
				label: __( 'Conversations', 'hyve-lite' ),
				default: true,
			},
			leads: {
				label: __( 'Leads', 'hyve-lite' ),
				planned: true,
			},
			thread: {
				label: __( 'Conversation', 'hyve-lite' ),
				hidden: true,
			},
		},
	},
	chat: {
		label: __( 'Chat', 'hyve-lite' ),
		icon: commentContent,
		capability: 'manage_options',
		requiresAPI: true,
		subs: {
			behavior: {
				label: __( 'Behavior', 'hyve-lite' ),
				default: true,
			},
			appearance: {
				label: __( 'Appearance', 'hyve-lite' ),
			},
		},
	},
	ai: {
		label: __( 'AI', 'hyve-lite' ),
		icon: cog,
		capability: 'manage_options',
		requiresAPI: false,
		subs: {
			provider: {
				label: __( 'Provider & model', 'hyve-lite' ),
				default: true,
			},
			advanced: {
				label: __( 'Advanced', 'hyve-lite' ),
			},
		},
	},
	integrations: {
		label: __( 'Integrations', 'hyve-lite' ),
		icon: blockMeta,
		capability: 'manage_options',
		requiresAPI: true,
	},
	settings: {
		label: __( 'Settings', 'hyve-lite' ),
		icon: settings,
		capability: 'manage_options',
		requiresAPI: true,
	},
};

export const getRoutes = () => applyFilters( 'hyve.next.routes', ROUTES );

/**
 * Resolve a screen's sub-panel, falling back to its default panel.
 *
 * @param {Object}  route Screen entry from the registry.
 * @param {?string} sub   Requested sub-panel key.
 *
 * @return {?string} Resolved sub-panel key.
 */
const resolveSub = ( route, sub ) => {
	if ( ! route?.subs ) {
		return null;
	}

	if ( sub && route.subs[ sub ] ) {
		return sub;
	}

	const fallback = Object.keys( route.subs ).find(
		( key ) => route.subs[ key ].default
	);

	return fallback || null;
};

/**
 * Read the current route from the URL, falling back to the dashboard.
 *
 * @return {{screen: string, sub: ?string}} Current route.
 */
export const parseLocation = () => {
	const routes = getRoutes();
	const params = new URLSearchParams( window.location.search );

	let screen = params.get( 'nav' ) || 'dashboard';
	const sub = params.get( 'sub' );

	if ( ! routes[ screen ] ) {
		screen = 'dashboard';
	}

	return { screen, sub: resolveSub( routes[ screen ], sub ) };
};

const listeners = new Set();

const notify = () => listeners.forEach( ( listener ) => listener() );

/**
 * Go to a screen (and optionally one of its sub-panels), recording a browser
 * history entry.
 *
 * @param {string}  screen          Screen key from the registry.
 * @param {?string} sub             Sub-panel key.
 * @param {Object}  options         Options.
 * @param {boolean} options.replace Replace the current history entry.
 */
export const navigate = ( screen, sub = null, { replace = false } = {} ) => {
	const routes = getRoutes();

	if ( ! routes[ screen ] ) {
		return;
	}

	const resolved = resolveSub( routes[ screen ], sub );
	const current = parseLocation();

	if ( current.screen === screen && current.sub === resolved && ! replace ) {
		return;
	}

	const url = new URL( window.location.href );
	url.searchParams.set( 'nav', screen );

	if ( resolved ) {
		url.searchParams.set( 'sub', resolved );
	} else {
		url.searchParams.delete( 'sub' );
	}

	window.history[ replace ? 'replaceState' : 'pushState' ](
		{ screen, sub: resolved },
		'',
		url.toString()
	);

	notify();

	window.scrollTo( 0, 0 );
};

/**
 * Current route; re-renders on navigate() and browser back/forward.
 *
 * @return {{screen: string, sub: ?string}} Current route.
 */
export const useRoute = () => {
	const [ route, setRoute ] = useState( parseLocation );

	useEffect( () => {
		const update = () => setRoute( parseLocation() );

		listeners.add( update );
		window.addEventListener( 'popstate', update );

		return () => {
			listeners.delete( update );
			window.removeEventListener( 'popstate', update );
		};
	}, [] );

	return route;
};
