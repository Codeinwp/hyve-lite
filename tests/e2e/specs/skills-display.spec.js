import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { mockChatResponse } from '../utils';

test.describe( 'Skills display', () => {
	/**
	 * Initialize the Chat App when nothing auto-rendered (see chat.spec.js).
	 *
	 * @param {import('@playwright/test').Page} page
	 */
	async function initializeChatApp( page ) {
		await page.evaluate( () => {
			if (
				document.querySelector(
					'#hyve-open, #hyve-window, .hyve-input-text'
				)
			) {
				return;
			}

			window?.hyveApp?.initialize();
		} );
	}

	/**
	 * Publish a post with an inline chat block and open it.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @param {Object}                          admin
	 * @param {Object}                          editor
	 */
	async function openChatPost( page, admin, editor ) {
		await admin.createNewPost( { title: 'Dummy Post' } );

		await editor.insertBlock( {
			name: 'hyve/chat',
			attributes: {},
		} );

		const postId = await editor.publishPost();

		await page.goto( `?p=${ postId }` );
		await initializeChatApp( page );
	}

	/**
	 * Send a message through the widget.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @param {string}                          message
	 */
	async function sendMessage( page, message ) {
		await page
			.getByRole( 'textbox', { name: 'Write a reply…' } )
			.fill( message );
		await page
			.locator( '#hyve-send-button' )
			.getByRole( 'button' )
			.click( { force: true } );
	}

	test( 'a display list renders as linked cards under the reply', async ( {
		page,
		admin,
		editor,
	} ) => {
		await openChatPost( page, admin, editor );

		await mockChatResponse( page, {
			message: '<p>Here are a few options:</p>',
			display: {
				type: 'list',
				items: [
					{
						label: 'Hoodie with Zipper',
						description: 'A warm hoodie.',
						meta: '$45.00',
						url: 'https://example.com/hoodie',
					},
					{
						label: 'Beanie',
						meta: '$18.00',
						url: 'https://example.com/beanie',
					},
				],
			},
		} );

		await sendMessage( page, 'Show me some hoodies' );

		await expect(
			page.getByText( 'Here are a few options:' )
		).toBeVisible();

		const cards = page.locator( '.hyve-display--list .hyve-display__item' );
		await expect( cards ).toHaveCount( 2 );

		const first = cards.first();
		await expect( first ).toHaveAttribute(
			'href',
			'https://example.com/hoodie'
		);
		await expect( first.locator( '.hyve-display__title' ) ).toHaveText(
			'Hoodie with Zipper'
		);
		await expect( first.locator( '.hyve-display__meta' ) ).toHaveText(
			'$45.00'
		);
	} );

	test( 'a card without a URL sends its label as the next message', async ( {
		page,
		admin,
		editor,
	} ) => {
		await openChatPost( page, admin, editor );

		await mockChatResponse( page, {
			message: '<p>Which one did you mean?</p>',
			display: {
				type: 'list',
				items: [
					{
						label: 'Order #3010',
					},
				],
			},
		} );

		await sendMessage( page, 'Where is my order?' );

		const card = page.locator( 'button.hyve-display__item' );
		await expect( card ).toBeVisible();

		await card.click();

		await expect(
			page.locator( '.hyve-user-message', { hasText: 'Order #3010' } )
		).toBeVisible();
	} );

	test( 'action pills open links or send prompts', async ( {
		page,
		admin,
		editor,
	} ) => {
		await openChatPost( page, admin, editor );

		await mockChatResponse( page, {
			message: '<p>Your latest order:</p>',
			display: {
				type: 'item',
				items: [
					{
						label: 'Order #3010',
						meta: 'Processing',
						actions: [
							{
								label: 'View order',
								url: 'https://example.com/order/3010',
							},
							{
								label: 'Where is it?',
								message: 'Where is my order #3010?',
							},
						],
					},
				],
			},
		} );

		await sendMessage( page, 'What is my order status?' );

		// With actions the card itself is inert; the pills carry the behavior.
		await expect( page.locator( 'div.hyve-display__item' ) ).toBeVisible();

		const link = page.locator( 'a.hyve-display__action' );
		await expect( link ).toHaveAttribute(
			'href',
			'https://example.com/order/3010'
		);

		await page
			.locator( 'button.hyve-display__action', {
				hasText: 'Where is it?',
			} )
			.click();

		await expect(
			page.locator( '.hyve-user-message', {
				hasText: 'Where is my order #3010?',
			} )
		).toBeVisible();
	} );

	test( 'cards replay with conversation history after a reload', async ( {
		page,
		admin,
		editor,
	} ) => {
		await openChatPost( page, admin, editor );

		await mockChatResponse( page, {
			message: '<p>Here are a few options:</p>',
			threadId: 'thread_e2e',
			display: {
				type: 'list',
				items: [
					{
						label: 'Hoodie with Zipper',
						url: 'https://example.com/hoodie',
					},
				],
			},
		} );

		await sendMessage( page, 'Show me some hoodies' );

		await expect( page.locator( '.hyve-display__item' ) ).toBeVisible();

		await page.reload();
		await initializeChatApp( page );

		// The stored conversation replays with its cards.
		await expect(
			page
				.locator( '.hyve-display__item .hyve-display__title' )
				.filter( { hasText: 'Hoodie with Zipper' } )
		).toBeVisible();
	} );
} );
