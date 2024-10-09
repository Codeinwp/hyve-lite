/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import {
	archive,
	blockMeta,
	comment,
	customLink,
	home,
	settings,
	wordpress
} from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import Home from './parts/Home';
import KnowledgeBase from './parts/data/KnowledgeBase';
import Posts from './parts/data/Posts';
import Updated from './parts/data/Updated';
import FailedModeration from './parts/data/FailedModeration';
import Custom from './parts/data/Custom';
import FAQ from './parts/data/FAQ';
import Messages from './parts/Messages';
import Qdrant from './parts/integrations/Qdrant';
import General from './parts/settings/General';
import Appearance from './parts/settings/Appearance';
import Assistant from './parts/settings/Assistant';
import Moderation from './parts/settings/Moderation';
import Advanced from './parts/settings/Advanced';

export const ROUTE_TREE = {
	home: {
		label: __( 'Dashboard', 'hyve-lite' ),
		icon: home,
		component: Home,
		disabled: false
	},
	data: {
		label: __( 'Knowledge Base', 'hyve-lite' ),
		icon: archive,
		children: {
			data: {
				label: __( 'Knowledge Base', 'hyve-lite' ),
				component: KnowledgeBase
			},
			update: {
				label: __( 'Requires Update', 'hyve-lite' ),
				component: Updated
			},
			flagged: {
				label: __( 'Failed Moderation', 'hyve-lite' ),
				component: FailedModeration
			},
			faq: {
				label: __( 'FAQ', 'hyve-lite' ),
				component: FAQ,
				isPro: true
			}
		}
	},
	messages: {
		label: __( 'Messages', 'hyve-lite' ),
		icon: comment,
		component: Messages
	},
	integrations: {
		label: __( 'Integrations', 'hyve-lite' ),
		icon: blockMeta,
		children: {
			integrations: {
				label: __( 'Qdrant', 'hyve-lite' ),
				component: Qdrant
			}
		}
	},
	settings: {
		label: __( 'Settings', 'hyve-lite' ),
		icon: settings,
		children: {
			settings: {
				label: __( 'General', 'hyve-lite' ),
				component: General
			},
			appearance: {
				label: __( 'Appearance', 'hyve-lite' ),
				component: Appearance,
				disabled: true,
				isPro: true
			},
			assistant: {
				label: __( 'Assistant', 'hyve-lite' ),
				component: Assistant,
				disabled: true
			},
			moderation: {
				label: __( 'Moderation', 'hyve-lite' ),
				component: Moderation,
				disabled: true
			},
			advanced: {
				label: __( 'Advanced', 'hyve-lite' ),
				component: Advanced,
				disabled: false
			}
		}
	}
};

export const KNOWLEDGE_BASE = {
	posts: {
		label: __( 'WordPress', 'hyve-lite' ),
		description: __( 'Import your WordPress content into the Knowledge Base.', 'hyve-lite' ),
		icon: wordpress,
		component: Posts
	},
	custom: {
		label: __( 'Custom Data', 'hyve-lite' ),
		description: __( 'Add custom data to your Knowledge Base.', 'hyve-lite' ),
		icon: archive,
		component: Custom,
		isPro: true
	},
	url: {
		label: __( 'Website URL', 'hyve-lite' ),
		description: __( 'Crawl URLs to add content to the Knowledge Base.', 'hyve-lite' ),
		icon: customLink,
		isPro: true
	},
	sitemap: {
		label: __( 'Sitemap', 'hyve-lite' ),
		description: __( 'Add a sitemap to the Knowledge Base.', 'hyve-lite' ),
		icon: blockMeta,
		isPro: true
	}
};
