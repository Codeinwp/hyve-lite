import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Dashboard', () => {
	test.beforeEach( async ( { admin } ) => {
		await admin.visitAdminPage( 'admin.php?page=hyve' );
	} );

	test( 'check dashboard URL', async ( { page, admin } ) => {
		expect( await page.locator( '#hyve-options' ).count() ).toBe( 1 );
	} );

	test( 'check shortcuts', async ( { page, admin } ) => {
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
	test( 'check tabs', async ( { page, admin } ) => {
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
} );
