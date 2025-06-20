// @ts-check

import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Utility function to create error span
 *
 * @param {string} message The message.
 * @return {HTMLSpanElement} The error element.
 */
function createErrorSpan( message ) {
	const errorSpan = document.createElement( 'span' );
	errorSpan.className = 'hyve-add-post-error';
	errorSpan.style.color = 'red';
	errorSpan.style.marginLeft = '8px';
	errorSpan.textContent = message;
	return errorSpan;
}

/**
 * Initialize the button.
 *
 * @param {HTMLButtonElement} btnElem The button to add or remove a post to/from the knowledge base.
 */
function initAddPostButton( btnElem ) {
	if ( ! btnElem.dataset.postId ) {
		return;
	}

	/**
	 * Element with error message.
	 *
	 * @type {HTMLSpanElement|null }
	 */
	let errorSpanRef = null;

	btnElem.addEventListener( 'click', async ( event ) => {
		event.preventDefault();

		const parent = btnElem.parentElement;
		if ( parent && errorSpanRef && errorSpanRef?.parentNode ) {
			errorSpanRef?.remove();
			errorSpanRef = null;
		}

		btnElem.disabled = true;
		btnElem.style.cursor = 'wait';

		const action = btnElem.dataset.action;
		const currentLabel = btnElem.innerText;

		try {
			btnElem.innerText = __(
				'Hyve is processing the post',
				'hyve-lite'
			);

			let response;
			if ( action === 'delete' ) {
				response = await apiFetch( {
					path: `${ window.hyveAddons.api }/data?id=${ btnElem.dataset.postId }`,
					method: 'DELETE',
				} );
			} else {
				response = await apiFetch( {
					path: `${ window.hyveAddons.api }/data`,
					method: 'POST',
					data: {
						action: 'add',
						data: {
							ID: btnElem.dataset.postId,
						},
					},
				} );
			}
			btnElem.style.cursor = 'pointer';

			if ( response && response.error && parent ) {
				errorSpanRef = createErrorSpan( response.error );
				parent.appendChild( errorSpanRef );
				btnElem.innerHTML = currentLabel;
				btnElem.disabled = false;
			} else if ( response ) {
				if ( action === 'delete' ) {
					btnElem.innerHTML = __( 'Add to Hyve', 'hyve-lite' );
					btnElem.dataset.action = 'add';
					btnElem.classList.remove( 'button-link-delete' );
				} else {
					btnElem.innerHTML = __( 'Remove from Hyve', 'hyve-lite' );
					btnElem.dataset.action = 'delete';
					btnElem.classList.add( 'button-link-delete' );
				}
				btnElem.disabled = false;
			}
		} catch ( e ) {
			if ( parent ) {
				errorSpanRef = createErrorSpan(
					e.message || __( 'Unknown error', 'hyve-lite' )
				);
				parent.appendChild( errorSpanRef );
			}
			btnElem.innerHTML = currentLabel;
			btnElem.disabled = false;
			btnElem.style.cursor = 'pointer';
		}
	} );
}

document.addEventListener( 'DOMContentLoaded', function () {
	document
		.querySelectorAll( '.hyve-row-action-btn' )
		.forEach( ( elem ) =>
			initAddPostButton( /** @type {HTMLButtonElement} */ ( elem ) )
		);
} );
