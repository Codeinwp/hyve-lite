import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const STREAMED_REPLY =
	'<p>A &amp; B</p><img src="x" onerror="window.hyveMarkupRan = true">';

test.describe( 'Chat replies shown as text', () => {
	let postId;

	test.beforeAll( async ( { requestUtils } ) => {
		const post = await requestUtils.createPost( {
			title: 'Chat Stream Post',
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

	/**
	 * Open the post, answer the stream setup request with a token and serve
	 * the stream from a ReadableStream the test feeds through
	 * `window.hyveTestStream`.
	 *
	 * @param {import('@playwright/test').Page} page The page.
	 */
	async function openWithControlledStream( page ) {
		await page.goto( `?p=${ postId }` );
		await page.waitForFunction( () => window.hyveApp && window.hyveClient );

		await page.route(
			/.*(?:rest_route=%2Fhyve%2Fv1%2Fchat|\/wp-json\/hyve\/v1\/chat).*/,
			( route ) =>
				route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						stream_token: 'e2e-token',
						thread_id: 'th-e2e',
						record_id: 1,
					} ),
				} )
		);

		await page.evaluate( () => {
			const originalFetch = window.fetch.bind( window );

			window.fetch = ( input, init ) => {
				if ( ! String( input ).includes( 'action=hyve_stream' ) ) {
					return originalFetch( input, init );
				}

				const body = new window.ReadableStream( {
					start( controller ) {
						window.hyveTestStream = controller;
					},
				} );

				return Promise.resolve(
					new window.Response( body, {
						status: 200,
						headers: { 'Content-Type': 'text/event-stream' },
					} )
				);
			};
		} );

		expect(
			await page.evaluate( () => window.hyveApp.canStream() )
		).toBeTruthy();
	}

	/**
	 * Send a message and stream the reply as two delta frames, the second one
	 * cut inside a tag, with no terminal event.
	 *
	 * @param {import('@playwright/test').Page} page The page.
	 */
	async function streamUnfinishedReply( page ) {
		await page
			.getByRole( 'textbox', { name: 'Write a reply…' } )
			.fill( 'Hello' );
		await page
			.locator( '#hyve-send-button' )
			.getByRole( 'button' )
			.click( { force: true } );

		await page.waitForFunction( () => window.hyveTestStream );
		await page.evaluate(
			( frames ) => {
				const encoder = new window.TextEncoder();

				frames.forEach( ( text ) =>
					window.hyveTestStream.enqueue(
						encoder.encode(
							`event: delta\ndata: ${ JSON.stringify( {
								text,
							} ) }\n\n`
						)
					)
				);
			},
			[ STREAMED_REPLY, '<img src="y" onerror="alert(1)"' ]
		);
	}

	/**
	 * The preview and the final bubble show the decoded text and no markup.
	 *
	 * @param {import('@playwright/test').Page} page The page.
	 * @param {Function}                        end  Ends the stream in the page.
	 */
	async function expectTextOnly( page, end ) {
		const box = page.locator( '#hyve-message-box' );

		await expect( page.locator( '#hyve-message-hyve-stream' ) ).toHaveText(
			'A & B'
		);
		await expect( box.locator( 'img[src="x"], img[src="y"]' ) ).toHaveCount(
			0
		);

		await page.evaluate( end );

		await expect( page.locator( '#hyve-message-hyve-stream' ) ).toHaveCount(
			0
		);
		await expect(
			box.locator( '.hyve-bot-message' ).filter( { hasText: 'A & B' } )
		).toHaveCount( 1 );
		await expect( box ).not.toContainText( '&amp;' );
		await expect( box.locator( 'img[src="x"], img[src="y"]' ) ).toHaveCount(
			0
		);
		expect( await page.evaluate( () => window.hyveMarkupRan ) ).toBeFalsy();
	}

	test( 'a stream that ends without its final event keeps plain text', async ( {
		page,
	} ) => {
		await openWithControlledStream( page );
		await streamUnfinishedReply( page );
		await expectTextOnly( page, () => window.hyveTestStream.close() );
	} );

	test( 'a stream that fails while reading keeps plain text', async ( {
		page,
	} ) => {
		await openWithControlledStream( page );
		await streamUnfinishedReply( page );
		await expectTextOnly( page, () =>
			window.hyveTestStream.error( new Error( 'network' ) )
		);
	} );

	test( 'a conversation saved by an earlier version is not restored', async ( {
		page,
	} ) => {
		await page.goto( `?p=${ postId }` );
		await page.evaluate( ( message ) => {
			window.localStorage.setItem(
				'hyve-chat',
				JSON.stringify( {
					timestamp: new Date(),
					threadID: 'th-legacy',
					recordID: 1,
					messages: [
						{
							time: new Date(),
							message,
							sender: 'bot',
							id: null,
						},
					],
				} )
			);
		}, STREAMED_REPLY );

		await page.reload();
		await page.waitForFunction( () => window.hyveApp && window.hyveClient );

		const box = page.locator( '#hyve-message-box' );

		await expect( box.locator( 'img[src="x"]' ) ).toHaveCount( 0 );
		await expect( box ).not.toContainText( 'A & B' );
		expect(
			await page.evaluate( () =>
				window.localStorage.getItem( 'hyve-chat' )
			)
		).toBeNull();
		expect( await page.evaluate( () => window.hyveMarkupRan ) ).toBeFalsy();
	} );
} );
