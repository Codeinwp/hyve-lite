/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import { useEffect, useState } from '@wordpress/element';

import { applyFilters } from '@wordpress/hooks';

import {
	archive,
	blockMeta,
	comment,
	customLink,
	home,
	page,
	settings,
	wordpress,
} from '@wordpress/icons';

/**
 * Route registry. The URL is the source of truth
 * (`&nav=<screen>&sub=<panel>&item=<id>`). Sub-panels: `default` is the
 * landing panel, `hidden` keeps drill-ins out of the subnav. Pro extends
 * entries via the `hyve.routes` filter.
 */
const ROUTES = {
	dashboard: {
		label: __( 'Dashboard', 'hyve-lite' ),
		description: __(
			'An overview of how Hyve is performing on your site.',
			'hyve-lite'
		),
		icon: home,
		capability: 'manage_options',
		requiresAPI: false,
	},
	kb: {
		label: __( 'Knowledge Base', 'hyve-lite' ),
		description: __(
			'The content Hyve draws its answers from.',
			'hyve-lite'
		),
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
				badge: true,
			},
			faq: {
				label: __( 'FAQ', 'hyve-lite' ),
				isPro: true,
			},
			'source-wordpress': {
				label: __( 'WordPress', 'hyve-lite' ),
				description: __(
					'Import your WordPress content into the Knowledge Base.',
					'hyve-lite'
				),
				icon: wordpress,
				track: 'posts',
				hidden: true,
			},
			'source-custom': {
				label: __( 'Custom Data', 'hyve-lite' ),
				description: __(
					'Add custom data to your Knowledge Base.',
					'hyve-lite'
				),
				icon: archive,
				track: 'custom',
				hidden: true,
				isPro: true,
			},
			'source-url': {
				label: __( 'Website URL', 'hyve-lite' ),
				description: __(
					'Crawl URLs to add content to the Knowledge Base.',
					'hyve-lite'
				),
				icon: customLink,
				track: 'url',
				hidden: true,
				isPro: true,
			},
			'source-sitemap': {
				label: __( 'Sitemap', 'hyve-lite' ),
				description: __(
					'Add a sitemap to the Knowledge Base.',
					'hyve-lite'
				),
				icon: blockMeta,
				track: 'sitemap',
				hidden: true,
				isPro: true,
			},
			'source-documents': {
				label: __( 'Documents', 'hyve-lite' ),
				description: __(
					'Import PDF, Word, Markdown, Text, or CSV files into the Knowledge Base.',
					'hyve-lite'
				),
				icon: page,
				track: 'documents',
				hidden: true,
				isPro: true,
			},
		},
	},
	messages: {
		label: __( 'Messages', 'hyve-lite' ),
		description: __(
			'Every conversation your visitors have had with Hyve.',
			'hyve-lite'
		),
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
				isPro: true,
			},
			thread: {
				label: __( 'Conversation', 'hyve-lite' ),
				hidden: true,
			},
		},
	},
	settings: {
		label: __( 'Settings', 'hyve-lite' ),
		description: __(
			'Configure the chat, the AI engine, integrations and the plugin.',
			'hyve-lite'
		),
		icon: settings,
		capability: 'manage_options',
		requiresAPI: false,
		sidebar: true,
		subs: {
			'chat-behavior': {
				label: __( 'Behavior', 'hyve-lite' ),
				group: __( 'Chat', 'hyve-lite' ),
				default: true,
				requiresAPI: true,
			},
			'chat-appearance': {
				label: __( 'Appearance', 'hyve-lite' ),
				group: __( 'Chat', 'hyve-lite' ),
				requiresAPI: true,
			},
			'chat-leads': {
				label: __( 'Leads', 'hyve-lite' ),
				group: __( 'Chat', 'hyve-lite' ),
				requiresAPI: true,
			},
			'hyve-connect': {
				label: __( 'Hyve Connect', 'hyve-lite' ),
				group: __( 'AI', 'hyve-lite' ),
			},
			'ai-provider': {
				label: __( 'Provider & model', 'hyve-lite' ),
				group: __( 'AI', 'hyve-lite' ),
			},
			qdrant: {
				label: __( 'Qdrant', 'hyve-lite' ),
				group: __( 'Integrations', 'hyve-lite' ),
				requiresAPI: true,
			},
			'api-access': {
				label: __( 'API access', 'hyve-lite' ),
				group: __( 'Integrations', 'hyve-lite' ),
				requiresAPI: true,
			},
			webhooks: {
				label: __( 'Webhooks', 'hyve-lite' ),
				group: __( 'Integrations', 'hyve-lite' ),
				requiresAPI: true,
			},
			general: {
				label: __( 'General', 'hyve-lite' ),
				group: __( 'Plugin', 'hyve-lite' ),
				requiresAPI: true,
			},
		},
	},
};

export const getRoutes = () => applyFilters( 'hyve.routes', ROUTES );

/**
 * Check whether the current user can access a route.
 *
 * Route capabilities are also enforced by WordPress REST permissions; this
 * keeps unauthorized sections out of the dashboard navigation and prevents
 * direct URL access from rendering them.
 *
 * @param {Object} route Screen entry from the registry.
 *
 * @return {boolean} Whether the current user can access the route.
 */
const canAccessRoute = ( route ) => {
	if ( ! route?.capability ) {
		return true;
	}

	if ( 'manage_options' === route.capability ) {
		return Boolean( window.hyve?.canManage );
	}

	if ( 'hyve_read_messages' === route.capability ) {
		return Boolean( window.hyve?.canReadMessages );
	}

	return true;
};

/**
 * Get routes available to the current user.
 *
 * @return {Object} The filtered route registry.
 */
export const getAccessibleRoutes = () =>
	Object.fromEntries(
		Object.entries( getRoutes() ).filter( ( [ , route ] ) =>
			canAccessRoute( route )
		)
	);

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
 * @return {{screen: string, sub: ?string, item: ?string}} Current route.
 */
const parseLocation = () => {
	const routes = getAccessibleRoutes();
	const params = new URLSearchParams( window.location.search );

	let screen = params.get( 'nav' ) || window.hyve?.view || 'dashboard';
	const sub = params.get( 'sub' );

	if ( ! routes[ screen ] ) {
		screen = Object.keys( routes )[ 0 ] || 'dashboard';
	}

	return {
		screen,
		sub: resolveSub( routes[ screen ], sub ),
		item: params.get( 'item' ) || null,
	};
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
 * @param {?string} options.item    Item id for drill-in panels.
 */
export const navigate = (
	screen,
	sub = null,
	{ replace = false, item = null } = {}
) => {
	const routes = getAccessibleRoutes();

	if ( ! routes[ screen ] ) {
		return;
	}

	const resolved = resolveSub( routes[ screen ], sub );
	const resolvedItem = item ? String( item ) : null;
	const current = parseLocation();

	if (
		current.screen === screen &&
		current.sub === resolved &&
		current.item === resolvedItem &&
		! replace
	) {
		return;
	}

	const url = new URL( window.location.href );
	url.searchParams.set( 'nav', screen );

	if ( resolved ) {
		url.searchParams.set( 'sub', resolved );
	} else {
		url.searchParams.delete( 'sub' );
	}

	if ( resolvedItem ) {
		url.searchParams.set( 'item', resolvedItem );
	} else {
		url.searchParams.delete( 'item' );
	}

	window.history[ replace ? 'replaceState' : 'pushState' ](
		{ screen, sub: resolved, item: resolvedItem },
		'',
		url.toString()
	);

	notify();

	window.scrollTo( 0, 0 );

	// Replace navigations are programmatic redirects, not user intent.
	if ( ! replace ) {
		window.hyveTrk?.add?.( {
			feature: 'dashboard',
			featureComponent: 'route',
			featureValue: resolved ? `${ screen }/${ resolved }` : screen,
		} );
	}
};

/**
 * Current route; re-renders on navigate() and browser back/forward.
 *
 * @return {{screen: string, sub: ?string, item: ?string}} Current route.
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
