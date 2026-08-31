import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import {
	mockConfirmDeleteThreadResponse,
	mockGetThreadsResponse,
} from '../utils';

const HYVE_ADMIN = 'admin.php?page=hyve';

test.describe( 'Messages', () => {
	test( 'lists conversations with message count and snippet', async ( {
		page,
		admin,
	} ) => {
		await mockGetThreadsResponse( page );

		await admin.visitAdminPage( `${ HYVE_ADMIN }&nav=messages` );

		await expect(
			page.getByRole( 'heading', { name: 'Conversations' } )
		).toBeVisible();

		// Table headers.
		await expect(
			page.getByRole( 'columnheader', { name: 'Conversation' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'columnheader', { name: 'Messages' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'columnheader', { name: 'Date' } )
		).toBeVisible();

		// A row shows its title, snippet of the last message, and count.
		await expect(
			page.getByText( 'How to reset my password?' )
		).toBeVisible();
		await expect(
			page.getByText( 'Is there anything else I can help you with?' )
		).toBeVisible();

		const firstRow = page
			.locator( 'tr', { hasText: 'How to reset my password?' } )
			.first();
		await expect( firstRow.locator( 'td' ).nth( 1 ) ).toHaveText( '6' );
	} );

	test( 'export CSV is locked on the free plan', async ( {
		page,
		admin,
	} ) => {
		await mockGetThreadsResponse( page );

		await admin.visitAdminPage( `${ HYVE_ADMIN }&nav=messages` );

		await expect(
			page.getByRole( 'button', { name: 'Export CSV' } )
		).toBeDisabled();
	} );

	test( 'viewing a conversation renders the thread with bubbles and meta', async ( {
		page,
		admin,
	} ) => {
		await mockGetThreadsResponse( page );

		await admin.visitAdminPage( `${ HYVE_ADMIN }&nav=messages` );

		await page.getByRole( 'button', { name: 'View' } ).first().click();

		await expect(
			page.getByRole( 'heading', { name: 'How to reset my password?' } )
		).toBeVisible();

		// Meta line: message count and the OpenAI thread id.
		await expect( page.getByText( '6 messages' ) ).toBeVisible();
		await expect(
			page.getByText( 'S1sTWm3SoQFa5D0LxzNpY9mE' )
		).toBeVisible();

		// User and bot bubbles render.
		await expect(
			page.getByText( 'I did not receive the email' )
		).toBeVisible();
		await expect(
			page.getByText( 'Please check your spam folder' )
		).toBeVisible();

		// The back link returns to the list.
		await page
			.getByRole( 'button', { name: '← All conversations' } )
			.click();
		await expect(
			page.getByRole( 'heading', { name: 'Conversations' } )
		).toBeVisible();
	} );

	test( 'deleting a conversation asks for confirmation first', async ( {
		page,
		admin,
	} ) => {
		await mockGetThreadsResponse( page );
		await mockConfirmDeleteThreadResponse( page );

		await admin.visitAdminPage( `${ HYVE_ADMIN }&nav=messages` );

		await page.getByRole( 'button', { name: 'View' } ).first().click();
		await page
			.getByRole( 'button', { name: 'Delete conversation' } )
			.click();

		await expect(
			page.getByRole( 'heading', { name: 'Delete this conversation?' } )
		).toBeVisible();

		// Cancel keeps the thread open.
		await page.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect(
			page.getByRole( 'heading', { name: 'How to reset my password?' } )
		).toBeVisible();

		// Confirming deletes and returns to the list.
		await page
			.getByRole( 'button', { name: 'Delete conversation' } )
			.click();
		await page
			.locator( '.hyve-next-modal' )
			.getByRole( 'button', { name: 'Delete conversation' } )
			.click();

		await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'Conversations' } )
		).toBeVisible();
	} );

	test( 'a bot reply with a trace shows the debug ledger', async ( {
		page,
		admin,
	} ) => {
		await page.route(
			/.*(?:rest_route=%2Fhyve%2Fv1%2Fthreads|\/wp-json\/hyve\/v1\/threads).*/,
			async ( route ) => {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						posts: [
							{
								ID: 7,
								title: 'Can I book overnight care?',
								date: '2026-08-17T10:00:00+00:00',
								thread_id: 'conv_demo123',
								thread: [
									{
										time: 1755424800,
										sender: 'user',
										message: 'Can I book overnight care?',
									},
									{
										time: 1755424806,
										sender: 'bot',
										message:
											"Sorry, I'm not able to help with that.",
										debug: {
											answered: false,
											mode: 'self_hosted',
											transport: 'poll',
											model: 'gpt-5.4-nano',
											duration_ms: 6240,
											usage: {
												input: 4211,
												output: 187,
											},
											threshold: 0.25,
											tools_used: true,
											tool_iterations: 1,
											skills: [ 'hyve/search-content' ],
											skill_calls: [
												{
													name: 'hyve/search-content',
													args: '{"query":"overnight"}',
													output: '{"count":1}',
													error: false,
												},
											],
											page: 'https://example.com/booking/',
											page_context: true,
											follow_ups: [
												'How far ahead should I book?',
											],
											context: [
												{
													post_id: 5,
													title: 'Services &#038; Rates',
													score: 0.7143,
													tokens: 119,
												},
												{
													post_id: 14,
													title: 'Booking Guide',
													score: 0.6821,
													tokens: 573,
												},
												{
													post_id: 15,
													title: 'Billing',
													score: 0.3122,
													tokens: 97,
													included: false,
												},
											],
											raw_reply:
												'The booking lookup timed out before I could confirm a slot.',
											query: 'Can I book overnight care?\nBooking Guide',
										},
									},
									{
										time: 1755424860,
										sender: 'event',
										message: 'moderation_flagged',
										debug: {
											code: 'content_flagged',
											categories: [ 'harassment' ],
											detail: 'harassment',
										},
									},
									{
										time: 1755424920,
										sender: 'event',
										message: 'rate_limited',
									},
								],
							},
						],
						more: false,
						total: 1,
						per_page: 3,
					} ),
				} );
			}
		);

		await admin.visitAdminPage( `${ HYVE_ADMIN }&nav=messages` );

		await page.getByRole( 'button', { name: 'View' } ).first().click();

		// The timestamp line summarizes the turn: status, included-source
		// count (the dropped one is not counted) and the toggle.
		await expect(
			page.getByText( 'Not answered', { exact: true } )
		).toBeVisible();
		await expect( page.getByText( '2 sources' ) ).toBeVisible();

		await page.getByRole( 'button', { name: 'Show trace' } ).click();

		// Ledger facts.
		await expect( page.getByText( 'Self-hosted · poll' ) ).toBeVisible();
		await expect( page.getByText( 'gpt-5.4-nano' ) ).toBeVisible();
		await expect( page.getByText( '6.2s' ) ).toBeVisible();
		await expect( page.getByText( '4,211 in · 187 out' ) ).toBeVisible();
		await expect( page.getByText( '0.25', { exact: true } ) ).toBeVisible();

		// Skills: name, round trips and the executed call.
		await expect(
			page.getByText( 'hyve/search-content · 1 round trip' )
		).toBeVisible();
		await expect(
			page.getByText( 'hyve/search-content({"query":"overnight"})' )
		).toBeVisible();

		// Page row links to the visitor's page and notes the injected context.
		await expect(
			page.getByRole( 'link', { name: '/booking/' } )
		).toBeVisible();
		await expect(
			page.getByText( 'content included as context' )
		).toBeVisible();

		// Follow-ups offered to the visitor.
		await expect(
			page.getByText( 'How far ahead should I book?' )
		).toBeVisible();

		// Sources: entity-decoded title, and the budget-drop footnote.
		await expect( page.getByText( 'Services & Rates' ) ).toBeVisible();
		await expect(
			page.getByText( 'the context budget was full', { exact: false } )
		).toBeVisible();

		// The raw model reply and the retrieval query.
		await expect(
			page.getByText(
				'The booking lookup timed out before I could confirm a slot.'
			)
		).toBeVisible();
		await expect(
			page.getByText( 'Can I book overnight care?\nBooking Guide' )
		).toBeVisible();

		// The toggle collapses the ledger again.
		await page.getByRole( 'button', { name: 'Hide trace' } ).click();
		await expect( page.getByText( 'gpt-5.4-nano' ) ).toBeHidden();
		await expect(
			page.getByRole( 'button', { name: 'Show trace' } )
		).toBeVisible();

		// Failed turns render as event dividers, not bot bubbles: the
		// moderation event carries its flagged category as a detail.
		await expect(
			page.getByText(
				'Message was flagged by moderation — no reply was sent · harassment'
			)
		).toBeVisible();
		await expect(
			page.getByText(
				'Visitor hit the rate limit — the message was not answered'
			)
		).toBeVisible();
	} );

	test( 'an unknown deep-linked conversation shows the not-found state', async ( {
		page,
		admin,
	} ) => {
		await mockGetThreadsResponse( page );

		await admin.visitAdminPage(
			`${ HYVE_ADMIN }&nav=messages&sub=thread&item=99999`
		);

		await expect(
			page.getByRole( 'heading', { name: 'Conversation not found' } )
		).toBeVisible();
	} );

	test( 'free plan shows the history upsell when more pages exist', async ( {
		page,
		admin,
	} ) => {
		await page.route(
			/.*(?:rest_route=%2Fhyve%2Fv1%2Fthreads|\/wp-json\/hyve\/v1\/threads).*/,
			async ( route ) => {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						posts: [
							{
								ID: 1,
								title: 'First conversation',
								date: '2026-07-01T10:00:00+00:00',
								thread: [
									{
										time: 1751364000,
										sender: 'user',
										message: 'Hi',
									},
								],
							},
						],
						more: true,
						total: 12,
						per_page: 3,
					} ),
				} );
			}
		);

		await admin.visitAdminPage( `${ HYVE_ADMIN }&nav=messages` );

		await expect(
			page.getByText( 'Read every conversation' )
		).toBeVisible();
		await expect(
			page
				.locator( '.hyve-next-act__upsell' )
				.getByRole( 'link', { name: 'Upgrade to Pro' } )
		).toBeVisible();
	} );
} );
