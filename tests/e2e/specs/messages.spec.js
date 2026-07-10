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
			page.getByRole( 'link', { name: 'Unlock with Pro' } )
		).toBeVisible();
	} );
} );
