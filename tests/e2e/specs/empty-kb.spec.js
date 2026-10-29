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
