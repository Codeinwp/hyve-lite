import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const HYVE_SETTINGS_ROUTE_PATTERN =
	/.*(?:rest_route=%2Fhyve%2Fv1%2Fsettings|\/wp-json\/hyve\/v1\/settings).*/;

test.describe( 'Chat visibility', () => {
	test( 'select a mode, add a URL rule and save', async ( {
		page,
		admin,
	} ) => {
		let savedData = null;

		await page.route( HYVE_SETTINGS_ROUTE_PATTERN, async ( route ) => {
			const request = route.request();

			if ( 'POST' === request.method() ) {
				savedData = request.postDataJSON();
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( true ),
				} );
				return;
			}

			await route.continue();
		} );

		await admin.visitAdminPage( 'admin.php?page=hyve' );

		// The visibility control renders.
		await expect(
			page.getByText( 'Where should Hyve appear?' )
		).toBeVisible();
		await expect(
			page.getByRole( 'radio', { name: 'Show on all pages' } )
		).toBeVisible();

		// The URL box only shows for include/exclude modes.
		await expect( page.getByText( 'Content URLs' ) ).toBeHidden();

		await page
			.getByRole( 'radio', { name: 'Show only on selected content' } )
			.check();

		await expect( page.getByText( 'Content URLs' ) ).toBeVisible();

		// Add and fill a URL rule.
		await page.getByRole( 'button', { name: 'Add URL rule' } ).click();
		await page.getByPlaceholder( '/example-page/' ).fill( '/shop/' );

		// Save and confirm the payload.
		await page.getByRole( 'button', { name: 'Save visibility' } ).click();
		await expect(
			page.getByText( 'Saved.', { exact: true } )
		).toBeVisible();

		expect( savedData?.data?.display_mode ).toBe( 'include' );
		expect( savedData?.data?.display_rules?.[ 0 ]?.path ).toBe( '/shop/' );
	} );

	test( 'manual mode hides the URL box', async ( { page, admin } ) => {
		await admin.visitAdminPage( 'admin.php?page=hyve' );

		await page
			.getByRole( 'radio', { name: /show automatically/i } )
			.check();

		await expect( page.getByText( 'Content URLs' ) ).toBeHidden();
	} );
} );
