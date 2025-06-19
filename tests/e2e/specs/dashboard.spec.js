import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import {
	mockConfirmDeleteThreadResponse,
	mockGetThreadsResponse,
} from '../utils';

test.describe( 'Dashboard', () => {
	test.beforeEach( async ( { admin } ) => {
		await admin.visitAdminPage( 'admin.php?page=hyve' );
	} );

	test( 'check dashboard URL', async ( { page } ) => {
		expect( await page.locator( '#hyve-options' ).count() ).toBe( 1 );
	} );

	test( 'check shortcuts', async ( { page } ) => {
		await page
			.getByRole( 'button', { name: 'Knowledge Base → In Knowledge' } )
			.click( { force: true } );

		await expect(
			page.getByRole( 'heading', { name: 'Knowledge Base' } )
		).toBeVisible();

		await page
			.getByRole( 'button', { name: 'Dashboard' } )
			.click( { force: true } );

		await page
			.getByRole( 'button', { name: 'Personalize → Customize Hyve' } )
			.click( { force: true } );

		await expect(
			page.getByRole( 'heading', { name: 'General Settings' } )
		).toBeVisible();
	} );

	/**
	 * Navigate through the dashboard tabs and check for rendering issues.
	 */
	test( 'check tabs', async ( { page } ) => {
		await expect(
			page.getByText( 'Enable Chat on all the pages' )
		).toBeVisible();

		await page
			.getByRole( 'button', { name: 'Knowledge Base', exact: true } )
			.click( { force: true } );

		await page
			.getByRole( 'button', { name: 'WordPress → Import your' } )
			.click( { force: true } );

		await page
			.getByRole( 'button', { name: 'Add Posts' } )
			.click( { force: true } );

		await expect( page.getByLabel( 'Post Type' ) ).toBeVisible();
		await expect(
			page.getByRole( 'searchbox', { name: 'Search for Posts' } )
		).toBeVisible();

		await page
			.getByRole( 'button', { name: 'Requires Update' } )
			.click( { force: true } );
		await expect(
			page.locator( 'div' ).filter( { hasText: /^Updated$/ } )
		).toBeVisible();

		await page
			.getByRole( 'button', { name: 'Failed Moderation' } )
			.click( { force: true } );
		await expect(
			page.getByRole( 'heading', { name: 'Failed Moderation' } )
		).toBeVisible();

		await page
			.getByRole( 'button', { name: 'Messages' } )
			.click( { force: true } );
		await expect(
			page.getByRole( 'heading', { name: 'Messages' } )
		).toBeVisible();

		await page
			.getByRole( 'button', { name: 'Integrations' } )
			.click( { force: true } );
		await expect(
			page.getByRole( 'textbox', { name: 'API Key' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'textbox', { name: 'API Endpoint' } )
		).toBeVisible();

		await page
			.getByRole( 'button', { name: 'Settings' } )
			.click( { force: true } );
		await expect(
			page.getByRole( 'textbox', { name: 'Welcome Message' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'textbox', { name: 'Default Message' } )
		).toBeVisible();

		await page
			.getByRole( 'button', { name: 'Assistant' } )
			.click( { force: true } );
		await expect(
			page.getByText( 'Model', { exact: true } )
		).toBeVisible();
		await expect(
			page.getByRole( 'slider', { name: 'Temperature' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'slider', { name: 'Top P' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Save' } )
		).toBeVisible();

		await page
			.getByRole( 'button', { name: 'Moderation' } )
			.click( { force: true } );
		await expect(
			page.getByRole( 'heading', { name: 'Moderation Settings' } )
		).toBeVisible();
		expect(
			await page.locator( '.components-range-control__slider' ).count()
		).toBeGreaterThan( 0 );
		await expect(
			page.getByRole( 'button', { name: 'Save' } )
		).toBeVisible();

		await page
			.getByRole( 'button', { name: 'Advanced' } )
			.click( { force: true } );
		await expect(
			page.getByRole( 'textbox', { name: 'API Key' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Save' } )
		).toBeVisible();
	} );

	test( 'check posts list rendering on Knowledge Base > WordPress Import', async ( {
		page,
	} ) => {
		await page.route(
			/.*rest_route=%2Fhyve%2Fv1%2Fdata.*offset=0.*status=included.*/,
			async ( route ) => {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						posts: [
							{ ID: 121, title: 'Shop', content: '' },
							{
								ID: 123,
								title: 'Checkout',
							},
							{
								ID: 94,
								title: 'Portofolio',
							},
							{
								ID: 1,
								title: 'Hello world!',
							},
						],
						more: false,
						totalChunks: '4',
					} ),
				} );
			}
		);

		await page
			.getByRole( 'button', { name: 'Knowledge Base', exact: true } )
			.click( { force: true } );

		await page
			.getByRole( 'button', { name: 'WordPress → Import your' } )
			.click( { force: true } );

		await expect( page.getByText( 'Checkout' ) ).toBeVisible();
	} );

	test( 'check message history rendering', async ( { page } ) => {
		await mockGetThreadsResponse( page );

		await page
			.getByRole( 'button', { name: 'Messages', exact: true } )
			.click( { force: true } );

		await page
			.getByRole( 'button', { name: 'How to reset my password?' } )
			.click( { force: true } );

		// Check Thread ID.
		await expect(
			page.getByText( 'S1sTWm3SoQFa5D0LxzNpY9mE' )
		).toBeVisible();

		// Check message rendering from user.
		await expect(
			page.getByText( 'I did not receive the email' )
		).toBeVisible();

		// Check message rendering from bot.
		await expect(
			page.getByText( 'Please check your spam folder' )
		).toBeVisible();
	} );

	test( 'check posts list rendering on Knowledge Base > Failed Moderation', async ( {
		page,
	} ) => {
		await page.route(
			/.*rest_route=%2Fhyve%2Fv1%2Fdata.*offset=0.*status=moderation.*/,
			async ( route ) => {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						posts: [
							{
								ID: 121,
								title: 'Shop',
								content: '',
								review: {
									hate: 0.5,
								},
							},
							{
								ID: 123,
								title: 'Checkout',
								content: 'Test checkout content',
								review: {
									hate: 0.5,
								},
							},
							{
								ID: 94,
								title: 'Portofolio',
								content: 'Test portfolio content',
								review: {
									hate: 0.5,
								},
							},
							{
								ID: 1,
								title: 'Hello world!',
								content: 'Test hello world content',
								review: {
									hate: 0.5,
								},
							},
						],
						more: false,
						totalChunks: '4',
					} ),
				} );
			}
		);

		await page
			.getByRole( 'button', { name: 'Knowledge Base', exact: true } )
			.click( { force: true } );

		await page
			.getByRole( 'button', { name: 'Failed Moderation' } )
			.click( { force: true } );

		await expect( page.getByText( '123' ) ).toBeVisible();
		await expect( page.getByText( 'Checkout' ) ).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'More Info' } ).nth( 1 )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Update' } ).nth( 2 )
		).toBeVisible();

		// Check Review Modal.
		await page
			.getByRole( 'button', { name: 'More Info' } )
			.nth( 1 )
			.click();

		await expect(
			page.getByRole( 'heading', { name: 'Failed Moderation: Checkout' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'Hate Speech' } )
		).toBeVisible();
		await expect(
			page
				.locator( 'div' )
				.filter( { hasText: /^Hate Speech50%$/ } )
				.locator( 'div' )
				.nth( 3 )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Override Moderation' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Close' } )
		).toBeVisible();
	} );

	test( 'check route change by Dashboard URL', async ( { page, admin } ) => {
		await admin.visitAdminPage( 'admin.php' ); // NOTE: this negate the first redirect from `beforeEach`.
		await admin.visitAdminPage( 'admin.php?page=hyve&nav=advanced' );

		await expect(
			page.getByRole( 'textbox', { name: 'API Key' } )
		).toBeVisible();

		// Check if the navigation is not blocked.
		await page
			.getByRole( 'button', { name: 'Assistant' } )
			.click( { force: true } );
		await expect(
			page.getByRole( 'heading', { name: 'Assistant Settings' } )
		).toBeVisible();
	} );

	test( 'choose assistant model', async ( { page } ) => {
		await page
			.getByRole( 'button', { name: 'Settings' } )
			.click( { force: true } );
		await page
			.getByRole( 'button', { name: 'Assistant' } )
			.click( { force: true } );

		await page
			.getByRole( 'radio', { name: 'GPT-4.1 nano' } )
			.click( { force: true } );
		await expect(
			page.getByRole( 'radio', { name: 'GPT-4.1 nano' } )
		).toBeChecked();
	} );

	test( 'delete conversation/thread', async ( { page } ) => {
		await mockGetThreadsResponse( page );

		await page
			.getByRole( 'button', { name: 'Messages', exact: true } )
			.click( { force: true } );
		await page
			.getByRole( 'button', { name: 'How to reset my password?' } )
			.click( { force: true } );

		await mockConfirmDeleteThreadResponse( page );
		await page
			.getByRole( 'button', { name: 'Delete conversation' } )
			.click( { force: true } );
		await expect(
			page.getByRole( 'button', { name: 'How to reset my password?' } )
		).toBeHidden();
		await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();
	} );

	test( 'check chart rendering', async ( { page } ) => {
		await page.evaluate( () => {
			window.hyve = window.hyve || {};
			window.hyve.chart = {
				legend: {
					messagesLabel: 'User messages',
					sessionsLabel: 'Sessions',
				},
				data: {
					messages: [ 0, 0, 0 ],
					sessions: [ 1, 2, 3 ],
				},
				labels: [ 'Mar 20', 'Mar 21', 'Mar 22' ],
			};
		} );

		await page
			.getByRole( 'button', { name: 'Knowledge Base', exact: true } )
			.click( { force: true } );
		await page
			.getByRole( 'button', { name: 'Dashboard' } )
			.click( { force: true } );
		await page.locator( '#messages-chart' ).scrollIntoViewIfNeeded();
		await page.locator( '#sessions-chart' ).scrollIntoViewIfNeeded();

		await expect( page.locator( '#messages-chart' ) ).toBeVisible();
		await expect( page.locator( '#sessions-chart' ) ).toBeVisible();
		await expect( page.getByText( 'Show data for' ) ).toBeVisible();
		await expect( page.getByLabel( 'Show data for' ) ).toBeVisible();
	} );

	test( 'check chart hiding when the data is empty', async ( { page } ) => {
		await page.evaluate( () => {
			window.hyve = window.hyve || {};
			window.hyve.chart = {
				legend: {
					messagesLabel: 'User messages',
					sessionsLabel: 'Sessions',
				},
				data: {
					messages: [],
					sessions: [],
				},
				labels: [],
			};
		} );

		await page
			.getByRole( 'button', { name: 'Knowledge Base', exact: true } )
			.click( { force: true } );
		await page
			.getByRole( 'button', { name: 'Dashboard' } )
			.click( { force: true } );

		await expect( page.locator( '#messages-chart' ) ).toBeHidden();
		await expect( page.locator( '#sessions-chart' ) ).toBeHidden();
		await expect( page.getByText( 'Show data for' ) ).toBeHidden();
		await expect( page.getByLabel( 'Show data for' ) ).toBeHidden();
	} );

	test( 'empty knowledge base warning', async ( { page } ) => {
		await expect(
			page.getByText( 'Your Knowledge Base is' )
		).toBeVisible();

		await page
			.getByRole( 'button', { name: 'Click here to add content.' } )
			.click( { force: true } );
		await expect(
			page.getByRole( 'button', { name: 'WordPress → Import your' } )
		).toBeVisible();
	} );
} );
