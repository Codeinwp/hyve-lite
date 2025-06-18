import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Chat', () => {
	test( 'chat bubble rendering on page', async ( {
		page,
		admin,
		editor,
	} ) => {
		await admin.createNewPost( { title: 'Dummy Post' } );

		await editor.insertBlock( {
			name: 'hyve/chat',
			attributes: {
				variant: 'floating',
			},
		} );

		const postId = await editor.publishPost();

		await page.goto( `?p=${ postId }` );

		// Open chat window
		await page
			.getByRole( 'button', { name: '💬' } )
			.click( { force: true } );
		await expect( page.locator( '#hyve-window' ) ).toBeVisible();
		await expect( page.locator( '#hyve-message-box' ) ).toBeVisible();
		await expect(
			page.locator( '#hyve-send-button' ).getByRole( 'button' )
		).toBeVisible();

		// Close chat window
		await page
			.locator( '#hyve-close' )
			.getByRole( 'button' )
			.click( { force: true } );
		await expect( page.locator( '#hyve-window' ) ).toBeHidden();
	} );

	test( 'chat bubble interaction', async ( { page, admin, editor } ) => {
		await admin.createNewPost( { title: 'Dummy Post' } );

		await editor.insertBlock( {
			name: 'hyve/chat',
			attributes: {
				variant: 'floating',
			},
		} );

		const postId = await editor.publishPost();

		await page.goto( `?p=${ postId }` );

		await page.route(
			/.*rest_route=%2Fhyve%2Fv1%2Fchat.*/,
			async ( route ) => {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						status: 'completed',
						success: true,
						message: '<p>Hello! How can I assist you today?</p>',
					} ),
				} );
			}
		);

		await page
			.getByRole( 'button', { name: '💬' } )
			.click( { force: true } );

		await page.waitForSelector( '.hyve-bot-message' );

		await page
			.getByRole( 'textbox', { name: 'Write a reply…' } )
			.fill( 'Hello' );
		await page
			.locator( '#hyve-send-button' )
			.getByRole( 'button' )
			.click( { force: true } );

		// The reply from endpoint should be visible.
		await expect(
			page.getByText( 'Hello! How can I assist you' )
		).toBeVisible();
	} );

	test( 'chat inline interaction', async ( { page, admin, editor } ) => {
		await admin.createNewPost( { title: 'Dummy Post' } );

		await editor.insertBlock( {
			name: 'hyve/chat',
			attributes: {},
		} );

		const postId = await editor.publishPost();

		await page.goto( `?p=${ postId }` );

		await page.route(
			/.*rest_route=%2Fhyve%2Fv1%2Fchat.*/,
			async ( route ) => {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						status: 'completed',
						success: true,
						message: '<p>Hello! How can I assist you today?</p>',
					} ),
				} );
			}
		);

		await page
			.getByRole( 'textbox', { name: 'Write a reply…' } )
			.fill( 'Hello' );
		await page
			.locator( '#hyve-send-button' )
			.getByRole( 'button' )
			.click( { force: true } );

		// The reply from endpoint should be visible.
		await expect(
			page.getByText( 'Hello! How can I assist you' )
		).toBeVisible();
	} );

	test( 'clear conversation', async ( { page, admin, editor } ) => {
		await admin.createNewPost( { title: 'Dummy Post' } );

		await editor.insertBlock( {
			name: 'hyve/chat',
			attributes: {
				variant: 'floating',
			},
		} );

		const postId = await editor.publishPost();

		await page.goto( `?p=${ postId }` );

		await page.route(
			/.*rest_route=%2Fhyve%2Fv1%2Fchat.*/,
			async ( route ) => {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						status: 'completed',
						success: true,
						message: '<p>Hello! How can I assist you today?</p>',
					} ),
				} );
			}
		);

		await page
			.getByRole( 'button', { name: '💬' } )
			.click( { force: true } );

		await page.waitForSelector( '.hyve-bot-message' );

		await page
			.getByRole( 'textbox', { name: 'Write a reply…' } )
			.fill( 'This message should be deleted.' );
		await page
			.locator( '#hyve-send-button' )
			.getByRole( 'button' )
			.click( { force: true } );

		await expect(
			page.getByText( 'This message should be' )
		).toBeVisible();
		await page.locator( '#hyve-menu-button' ).click( { force: true } );
		await page
			.getByRole( 'button', { name: 'Clear Conversation' } )
			.click( { force: true } );
		await expect( page.getByText( 'This message should be' ) ).toBeHidden();
	} );
} );
