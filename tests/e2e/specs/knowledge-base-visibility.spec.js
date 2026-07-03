import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { HYVE_DATA_API_ROUTE_PATTERN } from '../utils';

/**
 * Posts returned by the mocked Add Data listing, one per visibility type.
 * Order matters: the per-row "Add" buttons are matched by index below.
 */
const MOCK_POSTS = [
	{ ID: 201, title: 'Pickleball Rules', visibility: 'public' },
	{ ID: 202, title: 'Internal Refund Policy', visibility: 'private' },
	{ ID: 203, title: 'Partner Discount Codes', visibility: 'password' },
];

/**
 * Mock the Add Data listing and capture any add (POST) requests.
 *
 * @param {import("@playwright/test").Page} page  The page.
 * @param {number[]}                        added IDs of posts that were sent to be added.
 */
async function mockAddData( page, added ) {
	await page.route( HYVE_DATA_API_ROUTE_PATTERN, async ( route ) => {
		const request = route.request();

		// An add action: record the post ID and report success.
		if ( 'POST' === request.method() ) {
			const body = request.postDataJSON();
			added.push( body?.data?.ID );

			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( true ),
			} );
			return;
		}

		const url = request.url();

		// The Add Data listing fetch (no status filter).
		if ( url.includes( 'offset=0' ) && ! url.includes( 'status=' ) ) {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					posts: MOCK_POSTS,
					more: false,
					totalChunks: '0',
				} ),
			} );
			return;
		}

		await route.continue();
	} );
}

/**
 * Open Knowledge Base → WordPress Import → Add Posts.
 *
 * @param {import("@playwright/test").Page}                      page  The page.
 * @param {import("@wordpress/e2e-test-utils-playwright").Admin} admin The admin utils.
 */
async function openAddData( page, admin ) {
	await admin.visitAdminPage( 'admin.php?page=hyve' );

	await page
		.getByRole( 'button', { name: 'Knowledge Base', exact: true } )
		.click( { force: true } );
	await page
		.getByRole( 'button', { name: 'WordPress → Import your' } )
		.click( { force: true } );
	await page
		.getByRole( 'button', { name: 'Add Posts' } )
		.click( { force: true } );

	// Wait for the (debounced) listing to render.
	await expect( page.getByText( 'Pickleball Rules' ) ).toBeVisible();
}

test.describe( 'Knowledge Base — private/password content', () => {
	test( 'public post is added without confirmation', async ( {
		page,
		admin,
	} ) => {
		const added = [];
		await mockAddData( page, added );
		await openAddData( page, admin );

		await page
			.getByRole( 'button', { name: 'Add', exact: true } )
			.nth( 0 )
			.click( { force: true } );

		// No confirmation dialog for public content.
		await expect(
			page.getByRole( 'heading', { name: 'Add restricted content?' } )
		).toBeHidden();

		// The add request went through.
		await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();
		expect( added ).toEqual( [ 201 ] );
	} );

	test( 'private post asks for confirmation and Cancel aborts', async ( {
		page,
		admin,
	} ) => {
		const added = [];
		await mockAddData( page, added );
		await openAddData( page, admin );

		await page
			.getByRole( 'button', { name: 'Add', exact: true } )
			.nth( 1 )
			.click( { force: true } );

		// The confirmation dialog explains the private-content exposure.
		await expect(
			page.getByRole( 'heading', { name: 'Add restricted content?' } )
		).toBeVisible();
		await expect(
			page.getByText( 'This is a private post.' )
		).toBeVisible();

		await page
			.getByRole( 'button', { name: 'Cancel' } )
			.click( { force: true } );

		await expect(
			page.getByRole( 'heading', { name: 'Add restricted content?' } )
		).toBeHidden();

		// Nothing was added.
		expect( added ).toEqual( [] );
	} );

	test( 'private post is added after confirming', async ( {
		page,
		admin,
	} ) => {
		const added = [];
		await mockAddData( page, added );
		await openAddData( page, admin );

		await page
			.getByRole( 'button', { name: 'Add', exact: true } )
			.nth( 1 )
			.click( { force: true } );

		await page
			.getByRole( 'button', { name: 'Add anyway' } )
			.click( { force: true } );

		await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();
		expect( added ).toEqual( [ 202 ] );
	} );

	test( 'password-protected post shows a password-specific warning', async ( {
		page,
		admin,
	} ) => {
		const added = [];
		await mockAddData( page, added );
		await openAddData( page, admin );

		await page
			.getByRole( 'button', { name: 'Add', exact: true } )
			.nth( 2 )
			.click( { force: true } );

		await expect(
			page.getByRole( 'heading', { name: 'Add restricted content?' } )
		).toBeVisible();
		await expect(
			page.getByText( 'This is a password-protected post.' )
		).toBeVisible();
	} );
} );
