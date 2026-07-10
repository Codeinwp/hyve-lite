import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { mockGetThreadsResponse, mockStatsResponse } from '../utils';

const NEW_UI = 'admin.php?page=hyve&new=true';

const CHART = {
	legend: {
		messagesLabel: 'User Messages per Day',
		sessionsLabel: 'Active Sessions per Day',
	},
	data: {
		messages: [ 4, 9, 13 ],
		sessions: [ 3, 6, 2 ],
	},
	labels: [ 'Jul 1', 'Jul 2', 'Jul 3' ],
};

test.describe( 'Dashboard', () => {
	test( 'renders the app shell: header, tabs and active state', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage( NEW_UI );

		expect( await page.locator( '#hyve-options' ).count() ).toBe( 1 );

		// Header bar contents.
		const bar = page.locator( '.hyve-next__bar' );
		await expect( bar.getByText( 'Hyve', { exact: true } ) ).toBeVisible();
		await expect( bar.getByText( 'API connected' ) ).toBeVisible();
		await expect( bar.getByRole( 'link', { name: 'Docs' } ) ).toBeVisible();
		await expect(
			bar.getByRole( 'link', { name: 'Upgrade to Pro' } )
		).toBeVisible();

		// Tab navigation with the current page marked.
		const tabs = page.getByRole( 'navigation', { name: 'Hyve sections' } );
		await expect(
			tabs.getByRole( 'button', { name: 'Dashboard' } )
		).toHaveAttribute( 'aria-current', 'page' );
		await expect(
			tabs.getByRole( 'button', { name: 'Knowledge Base' } )
		).toBeVisible();
		await expect(
			tabs.getByRole( 'button', { name: 'Messages' } )
		).toBeVisible();
		await expect(
			tabs.getByRole( 'button', { name: 'Settings' } )
		).toBeVisible();
	} );

	test( 'stat cards show the numbers fetched from the stats endpoint', async ( {
		page,
		admin,
	} ) => {
		await mockStatsResponse( page, {
			threads: 42,
			messages: 99,
			totalChunks: 7,
			chart: CHART,
		} );

		await admin.visitAdminPage( NEW_UI );

		const stats = page.locator( '.hyve-next-stats' );
		await expect( stats ).toBeVisible();

		await expect( stats.getByText( '42', { exact: true } ) ).toBeVisible();
		await expect( stats.getByText( '99', { exact: true } ) ).toBeVisible();
		await expect(
			stats.getByText( 'Sessions', { exact: true } )
		).toBeVisible();
		await expect(
			stats.getByText( 'Messages', { exact: true } )
		).toBeVisible();
		await expect(
			stats.getByText( 'Knowledge Base', { exact: true } )
		).toBeVisible();

		// Free plan: the chunk meter foot shows usage of the local limit.
		await expect(
			stats.getByText( 'of the free limit used.' )
		).toBeVisible();

		// With content indexed, the visibility notice shows instead of the
		// setup checklist. Assert on its action, which is the same whatever
		// display mode the site is in.
		await expect(
			page.getByRole( 'button', { name: 'Manage visibility' } )
		).toBeVisible();
		await expect( page.getByText( "Let's get Hyve running" ) ).toBeHidden();
	} );

	test( 'usage chart renders when data exists', async ( { page, admin } ) => {
		await mockStatsResponse( page, {
			threads: 5,
			messages: 20,
			totalChunks: 7,
			chart: CHART,
		} );

		await admin.visitAdminPage( NEW_UI );

		await expect(
			page.getByRole( 'img', { name: 'Messages and sessions per day' } )
		).toBeVisible();
		await expect( page.getByLabel( 'Show data for' ) ).toBeVisible();
	} );

	test( 'usage chart is replaced by a placeholder without data', async ( {
		page,
		admin,
	} ) => {
		await mockStatsResponse( page, { totalChunks: 7 } );

		await admin.visitAdminPage( NEW_UI );

		await expect(
			page.getByText(
				'Usage data will appear here once visitors start chatting.'
			)
		).toBeVisible();
		await expect(
			page.getByRole( 'img', { name: 'Messages and sessions per day' } )
		).toBeHidden();
	} );

	test( 'setup checklist shows while the knowledge base is empty and links to content', async ( {
		page,
		admin,
	} ) => {
		await mockStatsResponse( page, { totalChunks: 0 } );

		await admin.visitAdminPage( NEW_UI );

		await expect(
			page.getByText( "Let's get Hyve running" )
		).toBeVisible();

		// The API key exists in the test environment, so step 1 counts as done.
		await expect( page.getByText( '1 of 2 steps done' ) ).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Add content', exact: true } )
		).toBeVisible();

		await page
			.getByRole( 'button', { name: 'Add content', exact: true } )
			.click();

		await expect(
			page.getByRole( 'heading', { name: 'Add a source' } )
		).toBeVisible();
	} );

	test( 'recent conversations list mocked threads and View all opens Messages', async ( {
		page,
		admin,
	} ) => {
		await mockStatsResponse( page, { totalChunks: 7, chart: CHART } );
		await mockGetThreadsResponse( page );

		await admin.visitAdminPage( NEW_UI );

		await expect(
			page.getByText( 'How to reset my password?' )
		).toBeVisible();

		await page.getByRole( 'button', { name: 'View all' } ).click();

		await expect(
			page.getByRole( 'heading', { name: 'Messages' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'Conversations' } )
		).toBeVisible();
	} );

	test( 'get started shortcuts navigate to their screens', async ( {
		page,
		admin,
	} ) => {
		await mockStatsResponse( page, { totalChunks: 7, chart: CHART } );

		await admin.visitAdminPage( NEW_UI );

		await page
			.getByRole( 'button', { name: 'Grow the Knowledge Base' } )
			.click();
		await expect(
			page.getByRole( 'heading', { name: 'Add a source' } )
		).toBeVisible();

		await page.goBack();

		await page
			.getByRole( 'button', { name: 'Personalize the chat' } )
			.click();
		await expect(
			page.getByText( 'Where should Hyve appear?' ).first()
		).toBeVisible();
	} );

	test( 'deep links open the right panel and browser back works', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage(
			`${ NEW_UI }&nav=settings&sub=ai-advanced`
		);

		await expect(
			page.getByRole( 'heading', { name: 'Advanced tuning' } )
		).toBeVisible();

		await page.getByRole( 'button', { name: 'Provider & model' } ).click();
		await expect(
			page.getByRole( 'heading', { name: 'OpenAI' } )
		).toBeVisible();

		await page.goBack();
		await expect(
			page.getByRole( 'heading', { name: 'Advanced tuning' } )
		).toBeVisible();
	} );

	test( 'service errors render reactively from the store', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage( NEW_UI );

		await page.evaluate( () => {
			window.wp.data.dispatch( 'hyve' ).setServiceErrors( [
				{
					code: 'invalid_api_key',
					message: 'Incorrect API key provided.',
					date: '2026-07-10T15:01:01+00:00',
					provider: 'OpenAI',
				},
			] );
		} );

		await expect(
			page.getByText(
				'[OpenAI] Service Error: Incorrect API key provided.'
			)
		).toBeVisible();
	} );
} );
