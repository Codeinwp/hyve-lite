import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const HYVE_ADMIN = 'admin.php?page=hyve';

test.describe( 'Hyve Connect', () => {
	test( 'nav: Hyve Connect sits in the AI group, not Integrations', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage( `${ HYVE_ADMIN }&nav=settings` );

		const nav = page.getByRole( 'navigation', {
			name: 'Settings sections',
		} );

		// Vertical order encodes the grouping: the Hyve Connect entry must fall
		// between the "AI" heading and the "Integrations" heading.
		const aiY = (
			await nav.getByText( 'AI', { exact: true } ).boundingBox()
		).y;
		const integrationsY = (
			await nav.getByText( 'Integrations' ).boundingBox()
		).y;
		const connectY = (
			await nav
				.getByRole( 'button', { name: 'Hyve Connect' } )
				.boundingBox()
		).y;

		expect( connectY ).toBeGreaterThan( aiY );
		expect( connectY ).toBeLessThan( integrationsY );
	} );

	test( 'not connected: renders the value prop and the switch CTA', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage(
			`${ HYVE_ADMIN }&nav=settings&sub=hyve-connect`
		);

		await expect(
			page.getByRole( 'heading', { name: 'Hyve Connect' } )
		).toBeVisible();
		await expect(
			page.getByText( 'Not connected', { exact: true } )
		).toBeVisible();
		await expect(
			page.getByText( 'We host the AI; you just switch it on.' )
		).toBeVisible();

		// The test site carries a dummy key, so the CTA offers to switch; a
		// key-less site would read "Enable Hyve Connect".
		await expect(
			page.getByRole( 'button', {
				name: /Switch to Hyve Connect|Enable Hyve Connect/,
			} )
		).toBeEnabled();
	} );

	test( 'connected: a paused sync swaps the status chip and shows the reason', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage(
			`${ HYVE_ADMIN }&nav=settings&sub=hyve-connect`
		);

		// Drive the store the way the stats route does, so the panel reacts
		// without the hosted platform.
		const reason = "Hyve Connect rejected this site's credentials.";
		await page.evaluate( ( message ) => {
			const store = window.wp.data.dispatch( 'hyve' );
			store.setAiMode( 'hyve_connect' );
			store.setConnect( { service: 'ok' } );
			store.setConnectSync( {
				blocked: true,
				quota: { kind: 'auth' },
				message,
			} );
		}, reason );

		await expect(
			page.getByText( 'Sync paused', { exact: true } ).first()
		).toBeVisible();
		await expect(
			page.getByText( 'Connected', { exact: true } )
		).toHaveCount( 0 );
		await expect( page.getByText( reason ) ).toBeVisible();

		// An outage pause also fails the stats call; the pause still shows.
		await page.evaluate( () => {
			const store = window.wp.data.dispatch( 'hyve' );
			store.setConnect( { service: 'error' } );
			store.setConnectSync( {
				blocked: true,
				quota: { kind: 'unavailable' },
				message: 'Hyve Connect could not be reached.',
			} );
		} );

		await expect(
			page.getByText( 'Sync paused', { exact: true } ).first()
		).toBeVisible();
		await expect(
			page.getByText( 'Offline', { exact: true } )
		).toHaveCount( 0 );

		// A plan block is not a pause: the connection chip stays.
		await page.evaluate( () => {
			const store = window.wp.data.dispatch( 'hyve' );
			store.setConnect( { service: 'ok' } );
			store.setConnectSync( {
				blocked: true,
				quota: { kind: 'storage', limit: 100, used: 100 },
			} );
		} );

		await expect(
			page.getByText( 'Connected', { exact: true } ).first()
		).toBeVisible();
		await expect(
			page.getByText( 'Sync paused', { exact: true } )
		).toHaveCount( 0 );
	} );

	test( 'provider settings lock while Connect handles AI', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage(
			`${ HYVE_ADMIN }&nav=settings&sub=ai-provider`
		);

		// Drive the store into Connect mode the same way enabling Connect does,
		// so the panel reacts without needing the hosted platform.
		await page.evaluate( () =>
			window.wp.data.dispatch( 'hyve' ).setAiMode( 'hyve_connect' )
		);

		await expect(
			page.getByText( 'Hyve Connect is handling AI.' )
		).toBeVisible();

		// The model does nothing under Connect, so it locks.
		await expect( page.getByLabel( 'Model' ) ).toBeDisabled();

		// The API key is editable under Connect only when one is already stored
		// (so it can be removed); a key-less site cannot add one here.
		const hasStoredKey = await page.evaluate( () =>
			Boolean( window.hyve?.hasAPIKey )
		);
		const apiKey = page.getByLabel( 'API key' );

		if ( hasStoredKey ) {
			await expect( apiKey ).toBeEnabled();
		} else {
			await expect( apiKey ).toBeDisabled();
		}

		// Retrieval tuning shares the Provider & model panel and also does
		// nothing under Connect, so its slider locks too.
		await expect(
			page.getByRole( 'slider', { name: 'Similarity threshold' } ).first()
		).toBeDisabled();
	} );
} );
