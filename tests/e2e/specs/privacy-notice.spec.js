import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { mockChatResponse } from '../utils';

const PRIVACY_URL = 'https://example.com/privacy-policy';

test.describe( 'Privacy notice', () => {
	/**
	 * Publish a post with an inline chat block and open it on the front end.
	 *
	 * The inline variant renders the chat window straight away, so there's no
	 * launcher to click before the notice is in the DOM.
	 *
	 * @param {Object}                                                fixtures        Playwright fixtures.
	 * @param {import('@wordpress/e2e-test-utils-playwright').Admin}  fixtures.admin
	 * @param {import('@wordpress/e2e-test-utils-playwright').Editor} fixtures.editor
	 * @param {import('@playwright/test').Page}                       fixtures.page
	 */
	async function openInlineChat( { admin, editor, page } ) {
		await admin.createNewPost( { title: 'Privacy Notice Post' } );
		await editor.insertBlock( { name: 'hyve/chat', attributes: {} } );
		const postId = await editor.publishPost();

		await page.goto( `?p=${ postId }` );
		await page.waitForFunction( () => window.hyveApp && window.hyveClient );
	}

	/**
	 * Seed the localized privacy-notice config and (re)render the widget.
	 *
	 * The widget may already have auto-rendered on load (when a knowledge base
	 * exists), so the inline container is cleared first to guarantee a single,
	 * freshly-configured instance.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @param {{enabled: boolean, url: string}} notice
	 */
	async function renderWithNotice( page, notice ) {
		await page.evaluate( ( config ) => {
			window.hyveClient.privacyNotice = config;
			const inline = document.querySelector( '#hyve-inline-chat' );
			if ( inline ) {
				inline.innerHTML = '';
			}
			window.hyveApp.initialize();
		}, notice );
	}

	test( 'renders with a link when enabled and a policy URL exists', async ( {
		page,
		admin,
		editor,
	} ) => {
		await openInlineChat( { admin, editor, page } );
		await renderWithNotice( page, { enabled: true, url: PRIVACY_URL } );

		const notice = page.locator( '.hyve-privacy-notice' );

		await expect( notice ).toBeVisible();
		await expect( notice ).toContainText( 'By chatting, you agree to our' );

		const link = notice.locator( '.hyve-privacy-notice__link' );
		await expect( link ).toHaveText( 'Privacy Policy' );
		await expect( link ).toHaveAttribute( 'href', PRIVACY_URL );
		await expect( link ).toHaveAttribute( 'target', '_blank' );
	} );

	test( 'does not render without a policy URL', async ( {
		page,
		admin,
		editor,
	} ) => {
		await openInlineChat( { admin, editor, page } );
		await renderWithNotice( page, { enabled: true, url: '' } );

		await expect( page.locator( '.hyve-privacy-notice' ) ).toHaveCount( 0 );
	} );

	test( 'does not render when disabled', async ( {
		page,
		admin,
		editor,
	} ) => {
		await openInlineChat( { admin, editor, page } );
		await renderWithNotice( page, { enabled: false, url: PRIVACY_URL } );

		await expect( page.locator( '.hyve-privacy-notice' ) ).toHaveCount( 0 );
	} );

	test( 'dismiss button removes it and remembers the choice', async ( {
		page,
		admin,
		editor,
	} ) => {
		await openInlineChat( { admin, editor, page } );
		await renderWithNotice( page, { enabled: true, url: PRIVACY_URL } );

		const notice = page.locator( '.hyve-privacy-notice' );
		await expect( notice ).toBeVisible();

		await notice.locator( '.hyve-privacy-notice__dismiss' ).click();
		await expect( notice ).toHaveCount( 0 );

		const dismissed = await page.evaluate( () =>
			window.localStorage.getItem( 'hyve-privacy-dismissed' )
		);
		expect( dismissed ).toBe( 'true' );

		// The choice sticks across reloads.
		await page.reload();
		await page.waitForFunction( () => window.hyveApp && window.hyveClient );
		await renderWithNotice( page, { enabled: true, url: PRIVACY_URL } );

		await expect( page.locator( '.hyve-privacy-notice' ) ).toHaveCount( 0 );
	} );

	test( 'sending a message dismisses the notice', async ( {
		page,
		admin,
		editor,
	} ) => {
		await openInlineChat( { admin, editor, page } );
		await renderWithNotice( page, { enabled: true, url: PRIVACY_URL } );
		await mockChatResponse( page );

		await expect( page.locator( '.hyve-privacy-notice' ) ).toBeVisible();

		await page
			.getByRole( 'textbox', { name: 'Write a reply…' } )
			.fill( 'Hello' );
		await page
			.locator( '#hyve-send-button' )
			.getByRole( 'button' )
			.click( { force: true } );

		await expect( page.locator( '.hyve-privacy-notice' ) ).toHaveCount( 0 );

		const dismissed = await page.evaluate( () =>
			window.localStorage.getItem( 'hyve-privacy-dismissed' )
		);
		expect( dismissed ).toBe( 'true' );
	} );
} );
