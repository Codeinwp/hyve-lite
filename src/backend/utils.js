/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import { dispatch } from '@wordpress/data';

const { createNotice } = dispatch( 'core/notices' );

import { ReactComponent as ChatBubbleOvalLeftIcon } from '../../assets/icons/chat-bubble-oval-left.svg';
import { ReactComponent as ChatBubbleBottomCenterTextIcon } from '../../assets/icons/chat-bubble-bottom-center-text.svg';
import { ReactComponent as ChatBubbleBottomCenterIcon } from '../../assets/icons/chat-bubble-bottom-center.svg';
import { ReactComponent as ChatBubbleLeftEllipsisIcon } from '../../assets/icons/chat-bubble-left-ellipsis.svg';
import { ReactComponent as ChatBubbleLeftIcon } from '../../assets/icons/chat-bubble-left.svg';
import { ReactComponent as ChatBubbleLeftRightIcon } from '../../assets/icons/chat-bubble-left-right.svg';

export const moderationLabels = {
	hate: {
		label: __( 'Hate Speech', 'hyve-lite' ),
		description: __(
			'Content that expresses, incites, or promotes hate based on race, gender, ethnicity, religion, nationality, sexual orientation, disability status, or caste. Hateful content aimed at non-protected groups (e.g., chess players) is harassment.',
			'hyve-lite'
		),
		default: 0.7,
	},
	'hate/threatening': {
		label: __( 'Hate Speech/Threatening', 'hyve-lite' ),
		description: __(
			'Hateful content that also includes violence or serious harm towards the targeted group based on race, gender, ethnicity, religion, nationality, sexual orientation, disability status, or caste.',
			'hyve-lite'
		),
		default: 0.6,
	},
	harassment: {
		label: __( 'Harassment', 'hyve-lite' ),
		description: __(
			'Content that expresses, incites, or promotes harassing language towards any target.',
			'hyve-lite'
		),
		default: 0.7,
	},
	'harassment/threatening': {
		label: __( 'Harassment/Threatening', 'hyve-lite' ),
		description: __(
			'Harassment content that also includes violence or serious harm towards any target.',
			'hyve-lite'
		),
		default: 0.6,
	},
	'self-harm': {
		label: __( 'Self-Harm', 'hyve-lite' ),
		description: __(
			'Content that promotes, encourages, or depicts acts of self-harm, such as suicide, cutting, and eating disorders.',
			'hyve-lite'
		),
		default: 0.5,
	},
	'self-harm/intent': {
		label: __( 'Self-Harm with Intent', 'hyve-lite' ),
		description: __(
			'Content where the speaker expresses that they are engaging or intend to engage in acts of self-harm, such as suicide, cutting, and eating disorders.',
			'hyve-lite'
		),
		default: 0.5,
	},
	'self-harm/instructions': {
		label: __( 'Self-Harm Instructions', 'hyve-lite' ),
		description: __(
			'Content that encourages performing acts of self-harm, such as suicide, cutting, and eating disorders, or that gives instructions or advice on how to commit such acts.',
			'hyve-lite'
		),
		default: 0.5,
	},
	sexual: {
		label: __( 'Sexual Content', 'hyve-lite' ),
		description: __(
			'Content meant to arouse sexual excitement, such as the description of sexual activity, or that promotes sexual services (excluding sex education and wellness).',
			'hyve-lite'
		),
		default: 0.8,
	},
	'sexual/minors': {
		label: __( 'Sexual Content Involving Minors', 'hyve-lite' ),
		description: __(
			'Sexual content that includes an individual who is under 18 years old.',
			'hyve-lite'
		),
		default: 0.5,
	},
	violence: {
		label: __( 'Violence', 'hyve-lite' ),
		description: __(
			'Content that depicts death, violence, or physical injury.',
			'hyve-lite'
		),
		default: 0.7,
	},
	'violence/graphic': {
		label: __( 'Graphic Violence', 'hyve-lite' ),
		description: __(
			'Content that depicts death, violence, or physical injury in graphic detail.',
			'hyve-lite'
		),
		default: 0.8,
	},
};

export const onProcessData = async ( {
	post = {},
	type = 'core',
	params = {},
	onSuccess = () => {},
	onError = () => {},
} ) => {
	let response;

	try {
		if ( 'knowledge' === type ) {
			response = await apiFetch( {
				path: post.ID
					? `${ window.hyve.api }/knowledge/${ post.ID }`
					: `${ window.hyve.api }/knowledge`,
				method: 'POST',
				data: {
					data: post,
					...params,
				},
			} );
		} else if ( 'link' === type ) {
			response = await apiFetch( {
				path: `${ window.hyve.api }/link`,
				method: 'POST',
				data: {
					data: post,
					...params,
				},
			} );
		} else {
			response = await apiFetch( {
				path: `${ window.hyve.api }/data`,
				method: 'POST',
				data: {
					data: post,
					...params,
				},
			} );
		}

		if ( response.error ) {
			throw response;
		}

		createNotice( 'success', __( 'Post has been updated.', 'hyve-lite' ), {
			type: 'snackbar',
			isDismissible: true,
		} );

		onSuccess();
	} catch ( error ) {
		createNotice( 'error', error.error, {
			type: 'snackbar',
			isDismissible: true,
		} );

		onError( error );
	}
};

export const formatDate = ( date ) => {
	const options = {
		day: '2-digit',
		month: '2-digit',
		year: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
		hour12: false,
	};

	return new Intl.DateTimeFormat( 'en-GB', options )
		.format( new Date( date ) )
		.replace( ',', '' );
};

/**
 * Helper function to add proper utm.
 *
 * @param {string} urlAdress Url to add utms.
 * @param {string} linkArea  Descriptive name of the link.
 * @return {string} The URL with UTM parameters added.
 */
export const setUtm = ( urlAdress, linkArea ) => {
	const urlLink = new URL( urlAdress );
	urlLink.searchParams.set( 'utm_campaign', linkArea );
	return urlLink.toString();
};

export const getChatIcons = () => [
	{
		icon: ChatBubbleLeftEllipsisIcon,
		label: __( 'Chat Bubble Left Ellipsis', 'hyve-lite' ),
		value: 'default',
	},
	{
		icon: ChatBubbleOvalLeftIcon,
		label: __( 'Chat Bubble Oval Left', 'hyve-lite' ),
		value: 'chat-bubble-oval-left',
	},
	{
		icon: ChatBubbleBottomCenterTextIcon,
		label: __( 'Chat Bubble Bottom Center Text', 'hyve-lite' ),
		value: 'chat-bubble-bottom-center-text',
	},
	{
		icon: ChatBubbleBottomCenterIcon,
		label: __( 'Chat Bubble Bottom Center', 'hyve-lite' ),
		value: 'chat-bubble-bottom-center',
	},
	{
		icon: ChatBubbleLeftIcon,
		label: __( 'Chat Bubble Left', 'hyve-lite' ),
		value: 'chat-bubble-left',
	},
	{
		icon: ChatBubbleLeftRightIcon,
		label: __( 'Chat Bubble Left Right', 'hyve-lite' ),
		value: 'chat-bubble-left-right',
	},
];
