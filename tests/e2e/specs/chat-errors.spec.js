import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Chat errors', () => {
	let postId;

	test.beforeAll( async ( { requestUtils } ) => {
		const post = await requestUtils.createPost( {
			title: 'Chat Errors Post',
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

	test( 'a run that fails while polled leaves the input usable', async ( {
		page,
	} ) => {
		await page.goto( `?p=${ postId }` );
		await page.waitForFunction( () => window.hyveApp && window.hyveClient );
		await page.evaluate( () => {
			window.hyveClient.streamNonce = '';
		} );

		// The run starts, then fails when polled, as OpenAI reports a
		// conversation past the model's context window.
		await page.route(
			/.*(?:rest_route=%2Fhyve%2Fv1%2Fchat|\/wp-json\/hyve\/v1\/chat).*/,
			( route ) =>
				route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify(
						'POST' === route.request().method()
							? {
									thread_id: 'conv_e2e',
									query_run: 'resp_e2e',
									record_id: null,
							  }
							: {
									error: 'Your input exceeds the context window of this model.',
							  }
					),
				} )
		);

		const input = page.getByRole( 'textbox', { name: 'Write a reply…' } );

		await input.fill( 'Hello' );
		await page
			.locator( '#hyve-send-button' )
			.getByRole( 'button' )
			.click( { force: true } );

		await expect(
			page.getByText( 'Sorry, I am not able to process your request' )
		).toBeVisible();
		await expect( input ).toBeEnabled();
		await expect(
			page.locator( '#hyve-send-button' ).getByRole( 'button' )
		).toBeEnabled();
	} );
} );
