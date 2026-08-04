import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { HYVE_DATA_API_ROUTE_PATTERN, isDataCountsRequest } from '../utils';

const HYVE_ADMIN = 'admin.php?page=hyve';

/**
 * Posts returned by the mocked WordPress drill listing, one per visibility
 * type. Order matters: the per-row "Add" buttons are matched by index below.
 */
const MOCK_POSTS = [
	{ ID: 201, title: 'Pickleball Rules', type: 'post', visibility: 'public' },
	{
		ID: 202,
		title: 'Internal Refund Policy',
		type: 'post',
		visibility: 'private',
	},
	{
		ID: 203,
		title: 'Partner Discount Codes',
		type: 'post',
		visibility: 'password',
	},
];

/**
 * Build indexed-content rows for the unified listing mock.
 *
 * @param {number} count  How many rows to build.
 * @param {number} offset Starting index for IDs and titles.
 * @return {Object[]} Rows shaped like the `/data?status=included` response.
 */
const indexedRows = ( count, offset = 0 ) =>
	Array.from( { length: count }, ( _, index ) => ( {
		ID: 1000 + offset + index,
		title: `Indexed Post ${ offset + index + 1 }`,
		type: 'post',
		chunks: 1,
	} ) );

/**
 * Mock every `/data` request the Knowledge Base screen makes.
 *
 * @param {import("@playwright/test").Page} page     The page.
 * @param {Object}                          handlers Response overrides keyed by request kind.
 */
async function mockDataApi( page, handlers = {} ) {
	await page.route( HYVE_DATA_API_ROUTE_PATTERN, async ( route ) => {
		const request = route.request();
		const url = request.url();

		if ( isDataCountsRequest( url ) ) {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(
					handlers.counts ?? { pending: 0, moderation: 0 }
				),
			} );
			return;
		}

		// apiFetch tunnels DELETE as POST with a method-override header.
		const method =
			request.headers()[ 'x-http-method-override' ] ?? request.method();

		if ( 'DELETE' === method ) {
			if ( handlers.onDelete ) {
				handlers.onDelete( url );
			}

			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( true ),
			} );
			return;
		}

		if ( 'POST' === method ) {
			if ( handlers.onAdd ) {
				handlers.onAdd( request.postDataJSON() );
			}

			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( true ),
			} );
			return;
		}

		if ( url.includes( 'status=included' ) && handlers.included ) {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( handlers.included( url ) ),
			} );
			return;
		}

		if ( url.includes( 'status=pending' ) && handlers.pending ) {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( handlers.pending ),
			} );
			return;
		}

		if ( url.includes( 'status=moderation' ) && handlers.moderation ) {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( handlers.moderation ),
			} );
			return;
		}

		// The WordPress drill listing carries no status filter.
		if ( ! url.includes( 'status=' ) && handlers.listing ) {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( handlers.listing ),
			} );
			return;
		}

		await route.continue();
	} );
}

const openWordPressDrill = async ( page, admin ) => {
	await admin.visitAdminPage( `${ HYVE_ADMIN }&nav=kb&sub=source-wordpress` );

	// Wait for the (debounced) listing to render.
	await expect( page.getByText( 'Pickleball Rules' ) ).toBeVisible();
};

test.describe( 'Knowledge Base', () => {
	test( 'all sources grid shows WordPress and pro-locked cards', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage( `${ HYVE_ADMIN }&nav=kb` );

		const grid = page.locator( '.hyve-next-src' );

		await expect(
			grid.getByRole( 'button', { name: /WordPress/ } )
		).toBeVisible();
		await expect(
			grid.getByRole( 'button', { name: /Custom Data/ } )
		).toBeVisible();
		await expect(
			grid.getByRole( 'button', { name: /Website URL/ } )
		).toBeVisible();
		await expect(
			grid.getByRole( 'button', { name: /Sitemap/ } )
		).toBeVisible();
		await expect(
			grid.getByRole( 'button', { name: /Documents/ } )
		).toBeVisible();

		// Every pro source is badged on the free plan.
		await expect( grid.getByText( 'Pro', { exact: true } ) ).toHaveCount(
			4
		);

		// FAQ is a Pro-badged subnav entry.
		await expect(
			page.getByRole( 'button', { name: 'FAQ' } )
		).toBeVisible();
	} );

	test( 'locked sources show a dummy preview and an upsell', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage( `${ HYVE_ADMIN }&nav=kb` );

		await page.getByRole( 'button', { name: /Custom Data/ } ).click();

		await expect(
			page.getByText( 'Custom Data is a Premium feature' )
		).toBeVisible();
		await expect(
			page.getByText( 'Halloween Limited Time Deal Information' )
		).toBeVisible();
		await expect(
			page
				.locator( '.hyve-next-act__upsell' )
				.getByRole( 'link', { name: 'Upgrade to Pro' } )
		).toBeVisible();

		// The back link returns to the sources grid.
		await page.getByRole( 'button', { name: '← All sources' } ).click();
		await expect(
			page.getByRole( 'heading', { name: 'Add a source' } )
		).toBeVisible();

		// Spot-check a second source for its own copy.
		await page.getByRole( 'button', { name: /Sitemap/ } ).click();
		await expect(
			page.getByText( 'Sitemap Crawling is a Premium feature' )
		).toBeVisible();
	} );

	test( 'locked FAQ shows the preview questions and upsell', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage( `${ HYVE_ADMIN }&nav=kb&sub=faq` );

		await expect(
			page.getByText( 'FAQ is a Premium feature' )
		).toBeVisible();
		await expect(
			page.getByText( 'How do I reset my password?' )
		).toBeVisible();
		await expect(
			page
				.locator( '.hyve-next-act__upsell' )
				.getByRole( 'link', { name: 'Upgrade to Pro' } )
		).toBeVisible();
	} );

	test( 'indexed content lists rows with count, chunks chip and pagination', async ( {
		page,
		admin,
	} ) => {
		await mockDataApi( page, {
			included: ( url ) =>
				url.includes( 'offset=20' )
					? {
							posts: indexedRows( 5, 20 ),
							more: false,
							total: 25,
							per_page: 20,
							totalChunks: '123',
					  }
					: {
							posts: indexedRows( 20 ),
							more: true,
							total: 25,
							per_page: 20,
							totalChunks: '123',
					  },
		} );

		await admin.visitAdminPage( `${ HYVE_ADMIN }&nav=kb` );

		await expect(
			page.getByText( 'Indexed Post 1', { exact: true } )
		).toBeVisible();
		await expect( page.getByText( '25 results' ) ).toBeVisible();
		await expect( page.getByText( '123 chunks' ) ).toBeVisible();
		await expect( page.getByText( 'Page 1 of 2' ) ).toBeVisible();

		await page.getByRole( 'button', { name: 'Next' } ).click();

		await expect( page.getByText( 'Indexed Post 21' ) ).toBeVisible();
		await expect( page.getByText( 'Page 2 of 2' ) ).toBeVisible();

		await page.getByRole( 'button', { name: 'Previous' } ).click();
		await expect( page.getByText( 'Page 1 of 2' ) ).toBeVisible();
	} );

	test( 'indexing failures surface a warn chip and the stored error', async ( {
		page,
		admin,
	} ) => {
		await mockDataApi( page, {
			included: () => ( {
				posts: [
					{
						ID: 1001,
						title: 'Broken Post',
						type: 'post',
						chunks: 0,
						error: 'The provider rejected the request.',
					},
				],
				more: false,
				total: 1,
				per_page: 20,
				totalChunks: '3',
			} ),
		} );

		await admin.visitAdminPage( `${ HYVE_ADMIN }&nav=kb` );

		await expect(
			page.getByText( 'Indexing failed', { exact: true } )
		).toBeVisible();
		await expect(
			page.getByText(
				'Indexing failed: The provider rejected the request.'
			)
		).toBeVisible();
	} );

	test( 'removing indexed content asks for confirmation first', async ( {
		page,
		admin,
	} ) => {
		const deleted = [];

		await mockDataApi( page, {
			included: () => ( {
				posts: [
					{
						ID: 1001,
						title: 'Old Landing Page',
						type: 'post',
						chunks: 2,
					},
				],
				more: false,
				total: 1,
				per_page: 20,
				totalChunks: '2',
			} ),
			onDelete: ( url ) => deleted.push( url ),
		} );

		await admin.visitAdminPage( `${ HYVE_ADMIN }&nav=kb` );

		await page.getByRole( 'button', { name: 'Remove' } ).click();

		await expect(
			page.getByRole( 'heading', {
				name: 'Remove from the Knowledge Base?',
			} )
		).toBeVisible();
		await expect(
			page.getByText( 'The content itself stays on your site' )
		).toBeVisible();

		// Cancel aborts without a request.
		await page.getByRole( 'button', { name: 'Cancel' } ).click();
		expect( deleted ).toHaveLength( 0 );

		// Confirming fires the DELETE.
		await page.getByRole( 'button', { name: 'Remove' } ).first().click();
		await page
			.locator( '.hyve-next-modal' )
			.getByRole( 'button', { name: 'Remove' } )
			.click();

		await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();
		expect( deleted ).toHaveLength( 1 );
		expect( deleted[ 0 ] ).toContain( 'id=1001' );
	} );

	test( 'public post is added without confirmation', async ( {
		page,
		admin,
	} ) => {
		const added = [];
		await mockDataApi( page, {
			listing: {
				posts: MOCK_POSTS,
				more: false,
				total: 3,
				per_page: 20,
				totalChunks: '0',
			},
			onAdd: ( body ) => added.push( body?.data?.ID ),
		} );
		await openWordPressDrill( page, admin );

		await page
			.getByRole( 'button', { name: 'Add', exact: true } )
			.nth( 0 )
			.click();

		await expect(
			page.getByRole( 'heading', { name: 'Add restricted content?' } )
		).toBeHidden();
		await expect(
			page.getByText( 'Added', { exact: true } )
		).toBeVisible();
		expect( added ).toEqual( [ 201 ] );
	} );

	test( 'private post asks for confirmation and Cancel aborts', async ( {
		page,
		admin,
	} ) => {
		const added = [];
		await mockDataApi( page, {
			listing: {
				posts: MOCK_POSTS,
				more: false,
				total: 3,
				per_page: 20,
				totalChunks: '0',
			},
			onAdd: ( body ) => added.push( body?.data?.ID ),
		} );
		await openWordPressDrill( page, admin );

		await page
			.getByRole( 'button', { name: 'Add', exact: true } )
			.nth( 1 )
			.click();

		await expect(
			page.getByRole( 'heading', { name: 'Add restricted content?' } )
		).toBeVisible();
		await expect(
			page.getByText( 'This is a private post.' )
		).toBeVisible();

		await page.getByRole( 'button', { name: 'Cancel' } ).click();

		await expect(
			page.getByRole( 'heading', { name: 'Add restricted content?' } )
		).toBeHidden();
		expect( added ).toEqual( [] );
	} );

	test( 'private post is added after confirming', async ( {
		page,
		admin,
	} ) => {
		const added = [];
		await mockDataApi( page, {
			listing: {
				posts: MOCK_POSTS,
				more: false,
				total: 3,
				per_page: 20,
				totalChunks: '0',
			},
			onAdd: ( body ) => added.push( body?.data?.ID ),
		} );
		await openWordPressDrill( page, admin );

		await page
			.getByRole( 'button', { name: 'Add', exact: true } )
			.nth( 1 )
			.click();
		await page.getByRole( 'button', { name: 'Add anyway' } ).click();

		await expect(
			page.getByText( 'Added', { exact: true } )
		).toBeVisible();
		expect( added ).toEqual( [ 202 ] );
	} );

	test( 'password-protected post shows a password-specific warning', async ( {
		page,
		admin,
	} ) => {
		await mockDataApi( page, {
			listing: {
				posts: MOCK_POSTS,
				more: false,
				total: 3,
				per_page: 20,
				totalChunks: '0',
			},
		} );
		await openWordPressDrill( page, admin );

		await page
			.getByRole( 'button', { name: 'Add', exact: true } )
			.nth( 2 )
			.click();

		await expect(
			page.getByRole( 'heading', { name: 'Add restricted content?' } )
		).toBeVisible();
		await expect(
			page.getByText( 'This is a password-protected post.' )
		).toBeVisible();
	} );

	test( 'bulk add with restricted items offers to skip them', async ( {
		page,
		admin,
	} ) => {
		const added = [];
		await mockDataApi( page, {
			listing: {
				posts: MOCK_POSTS,
				more: false,
				total: 3,
				per_page: 20,
				totalChunks: '0',
			},
			onAdd: ( body ) => added.push( body?.data?.ID ),
		} );
		await openWordPressDrill( page, admin );

		await page.getByLabel( 'Select all on this page' ).check();

		await expect( page.getByText( '3 items selected' ) ).toBeVisible();

		await page
			.getByRole( 'button', { name: 'Add 3 to the Knowledge Base' } )
			.click();

		await expect(
			page.getByRole( 'heading', { name: 'Add restricted content?' } )
		).toBeVisible();
		await expect(
			page.getByText(
				'2 selected items are private or password protected'
			)
		).toBeVisible();

		await page.getByRole( 'button', { name: 'Skip them' } ).click();

		await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();
		expect( added ).toEqual( [ 201 ] );
	} );

	test( 'chunk limit disables adding and shows the notice', async ( {
		page,
		admin,
	} ) => {
		await mockDataApi( page, {
			listing: {
				posts: MOCK_POSTS,
				more: false,
				total: 3,
				per_page: 20,
				totalChunks: '500',
			},
		} );
		await openWordPressDrill( page, admin );

		await expect(
			page.getByText( 'You have reached the limit of posts' )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Add', exact: true } ).nth( 0 )
		).toBeDisabled();
	} );

	test( 'needs attention lists both queues and Review opens the moderation modal', async ( {
		page,
		admin,
	} ) => {
		await mockDataApi( page, {
			counts: { pending: 1, moderation: 1 },
			pending: {
				posts: [ { ID: 301, title: 'Edited Post', type: 'post' } ],
				more: false,
				totalChunks: '5',
			},
			moderation: {
				posts: [
					{
						ID: 302,
						title: 'Flagged Post',
						type: 'post',
						content: 'Flagged content',
						review: { hate: 0.5 },
					},
				],
				more: false,
				totalChunks: '5',
			},
		} );

		await admin.visitAdminPage( `${ HYVE_ADMIN }&nav=kb&sub=attention` );

		await expect( page.getByText( 'Edited Post' ) ).toBeVisible();
		await expect( page.getByText( 'Flagged Post' ) ).toBeVisible();
		await expect( page.getByText( 'Edited since indexing' ) ).toBeVisible();
		await expect(
			page.getByText( 'Failed moderation', { exact: true } )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Update all' } )
		).toBeVisible();

		// The badge on the subnav reflects the merged count.
		await expect( page.locator( '.hyve-next__subbadge' ) ).toHaveText(
			'2'
		);

		await page.getByRole( 'button', { name: 'Review' } ).click();

		await expect(
			page.getByRole( 'heading', {
				name: 'Failed Moderation: Flagged Post',
			} )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Override moderation' } )
		).toBeVisible();
	} );
} );
