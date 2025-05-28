import { test, expect } from '@wordpress/e2e-test-utils-playwright';

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
			page.getByText( 'Enable ChatEnableDisable' )
		).toBeVisible();
		await expect(
			page.getByRole( 'textbox', { name: 'Welcome Message' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'textbox', { name: 'Default Message' } )
		).toBeVisible();

		await page
			.getByRole( 'button', { name: 'Assistant' } )
			.click( { force: true } );
		await expect( page.getByLabel( 'Model' ) ).toBeVisible();
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
								content: 'Test checkout content',
							},
							{
								ID: 94,
								title: 'Portofolio',
								content: 'Test portfolio content',
							},
							{
								ID: 1,
								title: 'Hello world!',
								content: 'Test hello world content',
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
		await page.route(
			/.*rest_route=%2Fhyve%2Fv1%2Fthreads.*offset=0.*/,
			async ( route ) => {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						posts: [
							{
								ID: 150,
								title: 'How to reset my password?',
								date: '2025-05-26T13:02:20+00:00',
								thread: [
									{
										time: 1748264540,
										sender: 'user',
										message: 'How do I reset my password?',
									},
									{
										time: 1748264544,
										sender: 'bot',
										message:
											'<p>You can reset your password by going to the login page and clicking "Forgot Password". Enter your email address and follow the instructions sent to your inbox.</p>',
									},
									{
										time: 1748265203,
										sender: 'user',
										message: 'I did not receive the email',
									},
									{
										time: 1748265207,
										sender: 'bot',
										message:
											'<p>Please check your spam folder. If you still do not see it, make sure you entered the correct email address associated with your account.</p>',
									},
									{
										time: 1748265217,
										sender: 'user',
										message: 'Thanks, found it in spam!',
									},
									{
										time: 1748267412,
										sender: 'bot',
										message:
											'<p>Great! Is there anything else I can help you with?</p>',
									},
								],
								thread_id: 'thread_S1sTWm3SoQFa5D0LxzNpY9mE',
							},
							{
								ID: 149,
								title: 'Order status inquiry',
								date: '2025-05-26T13:02:11+00:00',
								thread: [
									{
										time: 1748264531,
										sender: 'user',
										message:
											'What is the status of my order #12345?',
									},
									{
										time: 1748264535,
										sender: 'bot',
										message:
											'<p>Your order #12345 has been shipped and should arrive within 2-3 business days. You can track it using the tracking number sent to your email.</p>',
									},
								],
								thread_id: 'thread_omANmzE1oYI6FFDfc3j3pCQB',
							},
							{
								ID: 148,
								title: 'Product availability',
								date: '2025-05-26T13:02:06+00:00',
								thread: [
									{
										time: 1748264526,
										sender: 'user',
										message:
											'Is the red t-shirt in size M available?',
									},
									{
										time: 1748264530,
										sender: 'bot',
										message:
											'<p>Yes, the red t-shirt in size M is currently in stock. Would you like me to help you add it to your cart?</p>',
									},
								],
								thread_id: 'thread_lFSy430KC6bJ3kxsAr8SNZrv',
							},
							{
								ID: 147,
								title: 'Return policy question',
								date: '2025-05-26T13:01:37+00:00',
								thread: [
									{
										time: 1748264497,
										sender: 'user',
										message: 'What is your return policy?',
									},
									{
										time: 1748264501,
										sender: 'bot',
										message:
											'<p>We offer a 30-day return policy for unused items in original packaging. You can initiate a return from your account dashboard.</p>',
									},
								],
								thread_id: 'thread_QG9UaCmeYI5ItcTP7Gzgz40D',
							},
						],
						more: false,
					} ),
				} );
			}
		);

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
} );
