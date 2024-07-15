/**
 * External dependencies.
 */
import { getEncoding } from 'js-tiktoken';

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import { dispatch } from '@wordpress/data';

const { createNotice } = dispatch( 'core/notices' );

const tokenizer = getEncoding( 'cl100k_base' );

const createChunks = ( text, maxTokens, tokenizer ) => {
	const sentences = text.split( '. ' );

	const chunks = [];
	let tokensSoFar = 0;
	let chunk = [];

	for ( let i = 0; i < sentences.length; i++ ) {
		const sentence = sentences[i];
		const tokenLength = tokenizer.encode( ' ' + sentence ).length;

		if ( tokensSoFar + tokenLength > maxTokens ) {
			chunks.push( chunk.join( '. ' ) + '.' );
			chunk = [];
			tokensSoFar = 0;
		}

		if ( tokenLength > maxTokens ) {
			continue;
		}

		chunk.push( sentence );
		tokensSoFar += tokenLength + 1;
	}

	if ( 0 < chunk.length ) {
		chunks.push( chunk.join( '. ' ) + '.' );
	}

	return chunks;
};

export const tokenize = ( post, chunk = true ) => {
	let {
		ID,
		title,
		content
	} = post;

	content = content.replace( /<[^>]+>/g, '' );

	const tokens = tokenizer.encode( content );

	const article = {
		'post_id': ID,
		'post_title': title,
		'post_content': content,
		tokens
	};

	let data = [];

	const chunkedTokenSize = 1000;
	const tokenLength = article.tokens.length;

	if ( ( tokenLength > chunkedTokenSize ) && chunk ) {
		const shortenedSentences = createChunks( article.post_content, chunkedTokenSize, tokenizer );

		for ( const shortenedSentence of shortenedSentences ) {
			const chunkedTokens = tokenizer.encode( article.title + ' ' + shortenedSentence );

			data.push({
				...article,
				'post_content': shortenedSentence,
				tokens: chunkedTokens,
				'token_count': chunkedTokens.length
			});
		}
	} else {
		const chunkedTokens = tokenizer.encode( article.title + ' ' + article.post_content );

		data.push({
			...article,
			tokens: chunkedTokens,
			'token_count': chunkedTokens.length
		});
	}

	return data;
};

export const moderationLabels = {
	'hate': {
		label: __( 'Hate Speech', 'hyve' ),
		description: __( 'Content that expresses, incites, or promotes hate based on race, gender, ethnicity, religion, nationality, sexual orientation, disability status, or caste. Hateful content aimed at non-protected groups (e.g., chess players) is harassment.', 'hyve' ),
		default: 0.7
	},
	'hate/threatening': {
		label: __( 'Hate Speech/Threatening', 'hyve' ),
		description: __( 'Hateful content that also includes violence or serious harm towards the targeted group based on race, gender, ethnicity, religion, nationality, sexual orientation, disability status, or caste.', 'hyve' ),
		default: 0.6
	},
	'harassment': {
		label: __( 'Harassment', 'hyve' ),
		description: __( 'Content that expresses, incites, or promotes harassing language towards any target.', 'hyve' ),
		default: 0.7
	},
	'harassment/threatening': {
		label: __( 'Harassment/Threatening', 'hyve' ),
		description: __( 'Harassment content that also includes violence or serious harm towards any target.', 'hyve' ),
		default: 0.6
	},
	'self-harm': {
		label: __( 'Self-Harm', 'hyve' ),
		description: __( 'Content that promotes, encourages, or depicts acts of self-harm, such as suicide, cutting, and eating disorders.', 'hyve' ),
		default: 0.5
	},
	'self-harm/intent': {
		label: __( 'Self-Harm with Intent', 'hyve' ),
		description: __( 'Content where the speaker expresses that they are engaging or intend to engage in acts of self-harm, such as suicide, cutting, and eating disorders.', 'hyve' ),
		default: 0.5
	},
	'self-harm/instructions': {
		label: __( 'Self-Harm Instructions', 'hyve' ),
		description: __( 'Content that encourages performing acts of self-harm, such as suicide, cutting, and eating disorders, or that gives instructions or advice on how to commit such acts.', 'hyve' ),
		default: 0.5
	},
	'sexual': {
		label: __( 'Sexual Content', 'hyve' ),
		description: __( 'Content meant to arouse sexual excitement, such as the description of sexual activity, or that promotes sexual services (excluding sex education and wellness).', 'hyve' ),
		default: 0.8
	},
	'sexual/minors': {
		label: __( 'Sexual Content Involving Minors', 'hyve' ),
		description: __( 'Sexual content that includes an individual who is under 18 years old.', 'hyve' ),
		default: 0.5
	},
	'violence': {
		label: __( 'Violence', 'hyve' ),
		description: __( 'Content that depicts death, violence, or physical injury.', 'hyve' ),
		default: 0.7
	},
	'violence/graphic': {
		label: __( 'Graphic Violence', 'hyve' ),
		description: __( 'Content that depicts death, violence, or physical injury in graphic detail.', 'hyve' ),
		default: 0.8
	}
};

export const onProcessData = async({
	post = {},
	type = 'core',
	params = {},
	onSuccess = () => {},
	onError = () => {}
}) => {
	const chunks = tokenize( post );
	let bailOut = false;

	try {
		for ( const data of chunks ) {
			if ( bailOut ) {
				break;
			}

			let response = '';

			if ( 'knowledge' === type ) {
				if ( post.ID ) {
					response = await apiFetch({
						path: `${ window.hyve.api }/knowledge/${ post.ID }`,
						method: 'POST',
						data: {
							data,
							...params
						}
					});
				} else {
					response = await apiFetch({
						path: `${ window.hyve.api }/knowledge`,
						method: 'POST',
						data: {
							data,
							...params
						}
					});
				}
			} else {
				response = await apiFetch({
					path: `${ window.hyve.api }/data`,
					method: 'POST',
					data: {
						data,
						...params
					}
				});
			}

			if ( response.error ) {
				bailOut = true;
				throw response;
			}
		}

		createNotice(
			'success',
			__( 'Post has been updated.', 'hyve' ),
			{
				type: 'snackbar',
				isDismissible: true
			}
		);

		onSuccess();
	} catch ( error ) {
		createNotice(
			'error',
			error.error,
			{
				type: 'snackbar',
				isDismissible: true
			}
		);

		onError( error );
	}
};


/**
 * Helper function to add proper utm.
 * @param {string} url Url to add utms.
 * @param {string} area Descriptive name of the link
 * @returns {string}
 */
export const setUtm = ( urlAdress, linkArea ) => {
	const urlLink = new URL( urlAdress );
	urlLink.searchParams.set( 'utm_campaign', linkArea );
	return urlLink.toString();
};
