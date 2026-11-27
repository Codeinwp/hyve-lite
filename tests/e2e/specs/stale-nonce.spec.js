import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const STALE = 'stale12345';
const LEADS_ROUTE =
	/.*(?:rest_route=%2Fhyve%2Fv1%2Fleads|\/wp-json\/hyve\/v1\/leads)(?!%2F|\/).*/;
const LINK_ROUTE =
	/.*(?:rest_route=%2Fhyve%2Fv1%2Fleads%2Flink|\/wp-json\/hyve\/v1\/leads\/link).*/;
const CHAT_ROUTE =
	/.*(?:rest_route=%2Fhyve%2Fv1%2Fchat|\/wp-json\/hyve\/v1\/chat).*/;

/**
 * Make apiFetch reject a refused nonce the way WordPress before 6.9 does:
 * with core's failed `rest-nonce` response instead of the original error.
 *
 * @param {import('@playwright/test').Page} page              The page.
 * @param {{upperHost?: boolean}}           options           Options.
 * @param {boolean}                         options.upperHost Print the renewal URL with the
 *                                                            host in capitals, as a `siteurl`
 *                                                            stored that way would.
 */
async function rejectLikeOlderCore( page, { upperHost = false } = {} ) {
	await page.evaluate( ( upper ) => {
		if ( upper ) {
			const endpoint = new URL( window.wp.apiFetch.nonceEndpoint );

			window.wp.apiFetch.nonceEndpoint =
				window.wp.apiFetch.nonceEndpoint.replace(
					endpoint.host,
					endpoint.host.toUpperCase()
				);
		}

		window.wp.apiFetch.use( ( options, next ) =>
			next( options ).catch( async ( error ) => {
				if ( 'rest_cookie_invalid_nonce' !== error?.code ) {
					throw error;
				}

				throw await window.fetch( window.wp.apiFetch.nonceEndpoint );
			} )
		);
	}, upperHost );
}

/**
 * A cached page can outlive the nonces it embeds. The widget renews them
 * through `hyve_refresh_nonce` and retries once, also for visitors, whom
 * core's `rest-nonce` renewal does not serve.
 */
test.describe( 'Chat with a stale nonce', () => {
	const loggedOut = { cookies: [], origins: [] };

	test.use( { storageState: loggedOut } );

	let postId;

	test.beforeAll( async ( { requestUtils } ) => {
		const post = await requestUtils.createPost( {
			title: 'Stale Nonce Post',
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
	 * Open the post as a visitor and record the chat and refresh traffic.
	 *
	 * The e2e knowledge base is empty, which hides the widget from visitors,
	 * so it is shown by hand.
	 *
	 * @param {import('@playwright/test').Page} page           The page.
	 * @param {{stream: boolean}}               options        Options.
	 * @param {boolean}                         options.stream Whether to allow streaming.
	 * @return {Promise<{chat: Array<{method: string, nonce: string}>, stream: Array<string|null>, refreshes: Array<import('@playwright/test').Request>}>} The recorded traffic.
	 */
	async function open( page, { stream } ) {
		const traffic = { chat: [], stream: [], refreshes: [] };

		page.on( 'request', ( request ) => {
			const url = request.url();

			if ( CHAT_ROUTE.test( url ) ) {
				traffic.chat.push( {
					method: request.method(),
					nonce: request.headers()[ 'x-wp-nonce' ],
				} );
			} else if ( url.includes( 'action=hyve_stream' ) ) {
				traffic.stream.push(
					new URL( url ).searchParams.get( 'nonce' )
				);
			} else if (
				url.includes( 'admin-ajax.php' ) &&
				( request.postData() || '' ).includes( 'hyve_refresh_nonce' )
			) {
				traffic.refreshes.push( request );
			}
		} );

		await page.goto( `?p=${ postId }` );
		await page.waitForFunction( () => window.hyveApp && window.hyveClient );

		await page.evaluate( ( allowStream ) => {
			if ( ! allowStream ) {
				window.localStorage.setItem(
					'hyve-stream-unsupported',
					String( Date.now() )
				);
			}

			window.hyveClient.canShow = true;

			if ( ! document.querySelector( '.hyve-input-text' ) ) {
				window.hyveApp.initialize();
			}
		}, stream );

		return traffic;
	}

	/**
	 * Send a message from the widget.
	 *
	 * @param {import('@playwright/test').Page} page The page.
	 */
	async function send( page ) {
		const toggle = page.locator( '#hyve-open' ).getByRole( 'button' );

		if ( await toggle.isVisible() ) {
			await toggle.click( { force: true } );
		}

		await page
			.getByRole( 'textbox', { name: 'Write a reply…' } )
			.fill( 'Hello' );
		await page
			.locator( '#hyve-send-button' )
			.getByRole( 'button' )
			.click( { force: true } );
	}

	const coreVariants = [
		{ label: '', olderCore: null },
		{
			label: ' (WordPress < 6.9)',
			olderCore: { upperHost: false },
		},
		{
			label: ' (WordPress < 6.9, host in capitals)',
			olderCore: { upperHost: true },
		},
	];

	coreVariants.forEach( ( { label, olderCore } ) => {
		test( `the poll flow renews the nonce and retries once${ label }`, async ( {
			page,
		} ) => {
			const traffic = await open( page, { stream: false } );

			if ( olderCore ) {
				await rejectLikeOlderCore( page, olderCore );
			}

			// The stale nonce reaches the server, which refuses it; the retry is answered here.
			await page.route( CHAT_ROUTE, ( route ) => {
				const request = route.request();

				if ( STALE === request.headers()[ 'x-wp-nonce' ] ) {
					return route.continue();
				}

				return route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify(
						'POST' === request.method()
							? {
									thread_id: 'th-e2e',
									query_run: 'run-e2e',
									record_id: null,
							  }
							: { status: 'completed', message: 'Fresh reply' }
					),
				} );
			} );

			await page.evaluate( ( stale ) => {
				window.wp.apiFetch.nonceMiddleware.nonce = stale;
			}, STALE );

			const refused = page.waitForResponse(
				( response ) =>
					CHAT_ROUTE.test( response.url() ) &&
					403 === response.status()
			);
			const refreshed = page.waitForResponse( ( response ) =>
				( response.request().postData() || '' ).includes(
					'hyve_refresh_nonce'
				)
			);

			await send( page );

			expect( ( await ( await refused ).json() ).code ).toBe(
				'rest_cookie_invalid_nonce'
			);

			const refresh = await refreshed;
			const nonces = await refresh.json();

			expect( refresh.status() ).toBe( 200 );
			expect( refresh.request().method() ).toBe( 'POST' );
			expect( refresh.headers()[ 'cache-control' ] ).toContain(
				'no-store'
			);
			expect( nonces.rest ).toBeTruthy();
			expect( nonces.rest ).not.toBe( STALE );

			const box = page.locator( '#hyve-message-box' );

			await expect( box.getByText( 'Fresh reply' ) ).toBeVisible();
			await expect( box ).not.toContainText( 'not able to process' );
			await expect(
				box
					.locator( '.hyve-user-message' )
					.filter( { hasText: 'Hello' } )
			).toHaveCount( 1 );

			const posts = traffic.chat.filter( ( r ) => 'POST' === r.method );

			expect( traffic.refreshes ).toHaveLength( 1 );
			expect( posts.map( ( r ) => r.nonce ) ).toEqual( [
				STALE,
				nonces.rest,
			] );
			expect(
				await page.evaluate(
					() => window.wp.apiFetch.nonceMiddleware.nonce
				)
			).toBe( nonces.rest );
		} );
	} );

	test( 'the stream retries once with a renewed stream nonce', async ( {
		page,
	} ) => {
		const traffic = await open( page, { stream: true } );

		await page.route( CHAT_ROUTE, ( route ) =>
			route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					stream_token: 'e2e-token',
					thread_id: 'th-e2e',
					record_id: null,
				} ),
			} )
		);

		// The stale nonce reaches the server, which refuses it; the retry is answered here.
		await page.route( /action=hyve_stream/, ( route ) => {
			const nonce = new URL( route.request().url() ).searchParams.get(
				'nonce'
			);

			if ( STALE === nonce ) {
				return route.continue();
			}

			return route.fulfill( {
				status: 200,
				contentType: 'text/event-stream',
				body: `event: done\ndata: ${ JSON.stringify( {
					success: true,
					message: 'Streamed reply',
				} ) }\n\n`,
			} );
		} );

		await page.evaluate( ( stale ) => {
			window.hyveClient.streamNonce = stale;
		}, STALE );

		await send( page );

		const box = page.locator( '#hyve-message-box' );

		await expect( box.getByText( 'Streamed reply' ) ).toBeVisible();
		await expect( box ).not.toContainText( 'not able to process' );

		const fresh = await page.evaluate(
			() => window.hyveClient.streamNonce
		);

		expect( fresh ).not.toBe( STALE );
		expect( traffic.refreshes ).toHaveLength( 1 );
		expect( traffic.stream ).toEqual( [ STALE, fresh ] );
		// No fall back to the poll flow, so the turn was sent once.
		expect( traffic.chat ).toHaveLength( 1 );
	} );

	test( 'a stalled renewal falls back to the poll flow', async ( {
		page,
	} ) => {
		const traffic = await open( page, { stream: true } );

		let posts = 0;

		// The first POST sets up the stream, the second is the poll fallback.
		await page.route( CHAT_ROUTE, ( route ) => {
			let body = { status: 'completed', message: 'Polled reply' };

			if ( 'POST' === route.request().method() ) {
				posts += 1;
				body =
					1 === posts
						? { stream_token: 'e2e-token', thread_id: 'th-e2e' }
						: {
								thread_id: 'th-e2e',
								query_run: 'run-e2e',
								record_id: null,
						  };
			}

			return route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( body ),
			} );
		} );

		// The stream refuses the nonce and the renewal never answers.
		await page.route( /action=hyve_stream/, ( route ) =>
			route.fulfill( { status: 403, body: '' } )
		);
		await page.route( /admin-ajax\.php/, ( route ) =>
			( route.request().postData() || '' ).includes(
				'hyve_refresh_nonce'
			)
				? undefined
				: route.fallback()
		);

		await send( page );

		await expect(
			page.locator( '#hyve-message-box' ).getByText( 'Polled reply' )
		).toBeVisible( { timeout: 20000 } );

		expect( traffic.refreshes ).toHaveLength( 1 );
		expect( traffic.stream ).toHaveLength( 1 );
		// The renewal timing out is not the host buffering the stream.
		expect(
			await page.evaluate( () =>
				window.localStorage.getItem( 'hyve-stream-unsupported' )
			)
		).toBeNull();
	} );

	test( 'a nonce still refused after renewal is retried only once', async ( {
		page,
	} ) => {
		const traffic = await open( page, { stream: false } );

		await page.route( CHAT_ROUTE, ( route ) =>
			route.fulfill( {
				status: 403,
				contentType: 'application/json',
				body: JSON.stringify( {
					code: 'rest_cookie_invalid_nonce',
					message: 'Cookie check failed',
					data: { status: 403 },
				} ),
			} )
		);

		await send( page );

		await expect(
			page
				.locator( '#hyve-message-box' )
				.getByText( 'not able to process', {
					exact: false,
				} )
		).toBeVisible();

		// Give any stray retry time to show up.
		await page.waitForTimeout( 1000 );

		expect( traffic.refreshes ).toHaveLength( 1 );
		expect( traffic.chat ).toHaveLength( 2 );
	} );

	[
		{ status: 500, body: '' },
		// A proxy or security plugin answering 200 without the nonces.
		{ status: 200, contentType: 'application/json', body: '{}' },
	].forEach( ( reply ) => {
		test( `a failed renewal shows the retry message without looping (${
			reply.status
		} ${ reply.body || 'empty' })`, async ( { page } ) => {
			const traffic = await open( page, { stream: false } );

			await page.route( /admin-ajax\.php/, ( route ) =>
				( route.request().postData() || '' ).includes(
					'hyve_refresh_nonce'
				)
					? route.fulfill( reply )
					: route.continue()
			);

			await page.evaluate( ( stale ) => {
				window.wp.apiFetch.nonceMiddleware.nonce = stale;
			}, STALE );

			await send( page );

			await expect(
				page
					.locator( '#hyve-message-box' )
					.getByText( 'not able to process', {
						exact: false,
					} )
			).toBeVisible();

			// Give any stray retry time to show up.
			await page.waitForTimeout( 1000 );

			expect( traffic.refreshes ).toHaveLength( 1 );
			expect( traffic.chat ).toHaveLength( 1 );
		} );
	} );

	/**
	 * Show the lead form Pro injects, with a stale REST nonce, and record the
	 * `/leads` and `/leads/link` nonces.
	 *
	 * @param {import('@playwright/test').Page} page The page.
	 * @return {Promise<{traffic: Object, leads: Array<string>, links: Array<string>}>} The recorded traffic.
	 */
	async function openLeadForm( page ) {
		const traffic = await open( page, { stream: false } );
		const leads = [];
		const links = [];

		page.on( 'request', ( request ) => {
			const nonce = request.headers()[ 'x-wp-nonce' ];

			if ( LINK_ROUTE.test( request.url() ) ) {
				links.push( nonce );
			} else if ( LEADS_ROUTE.test( request.url() ) ) {
				leads.push( nonce );
			}
		} );

		const toggle = page.locator( '#hyve-open' ).getByRole( 'button' );

		if ( await toggle.isVisible() ) {
			await toggle.click( { force: true } );
		}

		await page.evaluate( ( stale ) => {
			window.hyveClient.leadForm = {
				fields: [
					{
						id: 'email',
						type: 'email',
						label: 'Email',
						required: true,
					},
				],
			};
			window.wp.apiFetch.nonceMiddleware.nonce = stale;
			window.hyveApp.renderLeadForm( { dismissible: true } );
		}, STALE );

		return { traffic, leads, links };
	}

	/**
	 * Fill in and submit the lead form.
	 *
	 * @param {import('@playwright/test').Page} page The page.
	 */
	async function submitLead( page ) {
		const form = page.locator( '.hyve-lead-form' );

		await form
			.locator( 'input[type="email"]' )
			.fill( 'visitor@example.com' );
		await form.locator( '.hyve-lead-form__submit' ).click();
	}

	[ false, true ].forEach( ( olderCore ) => {
		test( `a lead is stored once after renewing a stale nonce${
			olderCore ? ' (WordPress < 6.9)' : ''
		}`, async ( { page } ) => {
			const { traffic, leads } = await openLeadForm( page );

			if ( olderCore ) {
				await rejectLikeOlderCore( page );
			}

			// Lite has no /leads route, but core refuses the stale nonce before
			// routing, so the first attempt gets the real 403.
			await page.route( LEADS_ROUTE, ( route ) =>
				STALE === route.request().headers()[ 'x-wp-nonce' ]
					? route.continue()
					: route.fulfill( {
							status: 200,
							contentType: 'application/json',
							body: JSON.stringify( { lead_token: 'tok-e2e' } ),
					  } )
			);

			await submitLead( page );

			const box = page.locator( '#hyve-message-box' );

			await expect(
				box.getByText( 'Thanks! Your details' )
			).toBeVisible();
			await expect( page.locator( '.hyve-lead-form' ) ).toHaveCount( 0 );

			const fresh = await page.evaluate(
				() => window.wp.apiFetch.nonceMiddleware.nonce
			);

			expect( traffic.refreshes ).toHaveLength( 1 );
			expect( leads ).toEqual( [ STALE, fresh ] );
			expect(
				await page.evaluate( () => window.hyveApp.leadToken() )
			).toBe( 'tok-e2e' );
		} );
	} );

	[ false, true ].forEach( ( olderCore ) => {
		test( `a pending lead links after renewing a stale nonce${
			olderCore ? ' (WordPress < 6.9)' : ''
		}`, async ( { page } ) => {
			const { traffic, links } = await openLeadForm( page );

			if ( olderCore ) {
				await rejectLikeOlderCore( page );
			}

			await page.route( LINK_ROUTE, ( route ) =>
				STALE === route.request().headers()[ 'x-wp-nonce' ]
					? route.continue()
					: route.fulfill( {
							status: 200,
							contentType: 'application/json',
							body: JSON.stringify( { success: true } ),
					  } )
			);

			await page.evaluate( async () => {
				window.hyveApp.setLeadFormStatus( 'submitted', 'tok-e2e' );
				window.hyveApp.recordID = 1;
				window.hyveApp.threadID = 'th-e2e';
				await window.hyveApp.maybeLinkLead();
			} );

			const fresh = await page.evaluate(
				() => window.wp.apiFetch.nonceMiddleware.nonce
			);

			expect( traffic.refreshes ).toHaveLength( 1 );
			expect( links ).toEqual( [ STALE, fresh ] );
			expect(
				await page.evaluate( () => window.hyveApp.leadToken() )
			).toBeNull();
		} );
	} );

	[
		{
			status: 429,
			code: 'hyve_leads_throttled',
			message: 'Too many submissions. Please try again later.',
		},
		{
			status: 400,
			code: 'rest_invalid_param',
			message: 'Please enter a valid email address.',
		},
	].forEach( ( { status, code, message } ) => {
		test( `a ${ status } ${ code } lead error is shown, not retried`, async ( {
			page,
		} ) => {
			const { traffic, leads } = await openLeadForm( page );

			const data = { status };

			await page.route( LEADS_ROUTE, ( route ) =>
				route.fulfill( {
					status,
					contentType: 'application/json',
					body: JSON.stringify( { code, message, data } ),
				} )
			);

			await submitLead( page );

			await expect( page.locator( '.hyve-lead-form__error' ) ).toHaveText(
				message
			);

			await page.waitForTimeout( 1000 );

			expect( traffic.refreshes ).toHaveLength( 0 );
			expect( leads ).toHaveLength( 1 );
		} );
	} );

	test( 'a failed renewal keeps the lead form open with the retry message', async ( {
		page,
	} ) => {
		const { traffic, leads } = await openLeadForm( page );

		await page.route( /admin-ajax\.php/, ( route ) =>
			( route.request().postData() || '' ).includes(
				'hyve_refresh_nonce'
			)
				? route.fulfill( { status: 500, body: '' } )
				: route.fallback()
		);

		await submitLead( page );

		const form = page.locator( '.hyve-lead-form' );

		await expect( form.locator( '.hyve-lead-form__error' ) ).toContainText(
			'not able to process'
		);
		await expect( form.locator( '.hyve-lead-form__submit' ) ).toBeEnabled();

		expect( traffic.refreshes ).toHaveLength( 1 );
		expect( leads ).toEqual( [ STALE ] );
	} );
} );
