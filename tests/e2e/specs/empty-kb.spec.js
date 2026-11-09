import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Empty knowledge base', () => {
	let postId;

	test.beforeAll( async ( { requestUtils } ) => {
		const post = await requestUtils.createPost( {
			title: 'Empty KB Post',
			status: 'publish',
			content: '<!-- wp:hyve/chat /-->',
		} );
		postId = post.id;
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/posts/${ postId }`,
			params: { force: true },
		} );
	} );

	test( 'shows admins a setup notice linking to the knowledge base', async ( {
		page,
	} ) => {
		await page.goto( `?p=${ postId }` );
		await page.waitForFunction( () => window.hyveApp && window.hyveClient );

		const notice = page.locator( '.hyve-empty-kb-notice' );

		await expect( notice ).toBeVisible();
		await expect( notice ).toContainText( 'visitors cannot see it' );
		await expect(
			notice.locator( '.hyve-empty-kb-notice__link' )
		).toHaveAttribute( 'href', /page=hyve&nav=kb/ );
	} );

	/**
	 * Answer every chat request as the server does for an empty knowledge
	 * base, counting the requests.
	 *
	 * @param {import('@playwright/test').Page} page The page.
	 * @return {{ count: number }} The request counter.
	 */
	async function mockKbEmpty( page ) {
		const requests = { count: 0 };

		await page.route(
			/.*(?:rest_route=%2Fhyve%2Fv1%2Fchat|\/wp-json\/hyve\/v1\/chat).*/,
			async ( route ) => {
				requests.count++;
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						error: 'Nothing indexed yet, sorry.',
						code: 'kb_empty',
					} ),
				} );
			}
		);

		return requests;
	}

	/**
	 * Send a message from the inline widget.
	 *
	 * @param {import('@playwright/test').Page} page The page.
	 */
	async function send( page ) {
		await page
			.getByRole( 'textbox', { name: 'Write a reply…' } )
			.fill( 'Hello' );
		await page
			.locator( '#hyve-send-button' )
			.getByRole( 'button' )
			.click( { force: true } );
	}

	test( 'a streamed turn shows the site fallback without retrying', async ( {
		page,
	} ) => {
		await page.goto( `?p=${ postId }` );
		await page.waitForFunction( () => window.hyveApp && window.hyveClient );
		expect(
			await page.evaluate( () => window.hyveApp.canStream() )
		).toBeTruthy();

		const requests = await mockKbEmpty( page );

		await send( page );

		await expect(
			page.getByText( 'Nothing indexed yet, sorry.' )
		).toBeVisible();
		expect( requests.count ).toBe( 1 );
	} );

	test( 'a polled turn shows the site fallback', async ( { page } ) => {
		await page.goto( `?p=${ postId }` );
		await page.waitForFunction( () => window.hyveApp && window.hyveClient );
		await page.evaluate( () => {
			window.hyveClient.streamNonce = '';
		} );

		await mockKbEmpty( page );

		await send( page );

		await expect(
			page.getByText( 'Nothing indexed yet, sorry.' )
		).toBeVisible();
	} );

	test.describe( 'as a visitor', () => {
		const loggedOut = { cookies: [], origins: [] };

		test.use( { storageState: loggedOut } );

		test( 'does not load the chat widget', async ( { page } ) => {
			await page.goto( `?p=${ postId }` );

			await expect( page.locator( '.hyve-empty-kb-notice' ) ).toHaveCount(
				0
			);
			await expect( page.locator( '.hyve-input-box' ) ).toHaveCount( 0 );
			expect(
				await page.evaluate( () => window.hyveClient?.canShow )
			).toBeFalsy();
		} );
	} );
} );
