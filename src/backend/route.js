/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import {
	archive,
	comment,
	home,
	settings
} from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import Home from './parts/Home';
import KnowledgeBase from './parts/data/KnowledgeBase';
import AddData from './parts/data/AddData';
import Updated from './parts/data/Updated';
import FailedModeration from './parts/data/FailedModeration';
import Custom from './parts/data/Custom';
import FAQ from './parts/data/FAQ';
import Messages from './parts/Messages';
import General from './parts/settings/General';
import Appearance from './parts/settings/Appearance';
import Assistant from './parts/settings/Assistant';
import Moderation from './parts/settings/Moderation';
import Advanced from './parts/settings/Advanced';

export const ROUTE_TREE = {
	home: {
		label: __( 'Dashboard', 'hyve' ),
		icon: home,
		component: Home,
		disabled: false
	},
	data: {
		label: __( 'Data', 'hyve' ),
		icon: archive,
		children: {
			data: {
				label: __( 'Knowledge Base', 'hyve' ),
				component: KnowledgeBase
			},
			add: {
				label: __( 'Add Data', 'hyve' ),
				component: AddData
			},
			update: {
				label: __( 'Requires Update', 'hyve' ),
				component: Updated
			},
			flagged: {
				label: __( 'Failed Moderation', 'hyve' ),
				component: FailedModeration
			},
			custom: {
				label: __( 'Custom Data', 'hyve' ),
				component: Custom,
				isPro: true
			},
			faq: {
				label: __( 'FAQ', 'hyve' ),
				component: FAQ,
				isPro: true
			}
		}
	},
	messages: {
		label: __( 'Messages', 'hyve' ),
		icon: comment,
		component: Messages
	},
	settings: {
		label: __( 'Settings', 'hyve' ),
		icon: settings,
		children: {
			settings: {
				label: __( 'General', 'hyve' ),
				component: General
			},
			appearance: {
				label: __( 'Appearance', 'hyve' ),
				component: Appearance,
				disabled: true,
				isPro: true
			},
			assistant: {
				label: __( 'Assistant', 'hyve' ),
				component: Assistant,
				disabled: true
			},
			moderation: {
				label: __( 'Moderation', 'hyve' ),
				component: Moderation,
				disabled: true
			},
			advanced: {
				label: __( 'Advanced', 'hyve' ),
				component: Advanced,
				disabled: false
			}
		}
	}
};
