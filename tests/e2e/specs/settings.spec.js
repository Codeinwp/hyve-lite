import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { HYVE_SETTINGS_API_ROUTE_PATTERN } from '../utils';

const NEW_UI = 'admin.php?page=hyve&new=true';

/**
 * Capture settings saves; GET requests pass through to the real endpoint.
 *
 * @param {import("@playwright/test").Page} page  The page.
 * @param {Object[]}                        saves Captured POST payloads.
 */
async function captureSettingsSaves( page, saves ) {
	await page.route( HYVE_SETTINGS_API_ROUTE_PATTERN, async ( route ) => {
		const request = route.request();

		if ( 'POST' === request.method() ) {
			saves.push( request.postDataJSON() );
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					success: 'Settings saved.',
				} ),
			} );
			return;
		}

		await route.continue();
	} );
}

test.describe( 'Settings', () => {
	test( 'side nav renders grouped panels with the default active', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage( `${ NEW_UI }&nav=settings` );

		const nav = page.getByRole( 'navigation', {
			name: 'Settings sections',
		} );

		// Group headings.
		await expect( nav.getByText( 'Chat', { exact: true } ) ).toBeVisible();
		await expect( nav.getByText( 'AI', { exact: true } ) ).toBeVisible();
		await expect( nav.getByText( 'Integrations' ) ).toBeVisible();
		await expect( nav.getByText( 'Plugin' ) ).toBeVisible();

		// Behavior is the default panel.
		await expect(
			nav.getByRole( 'button', { name: 'Behavior' } )
		).toHaveAttribute( 'aria-current', 'page' );
		await expect(
			page.getByText( 'Where should Hyve appear?' ).first()
		).toBeVisible();
	} );

	test( 'visibility: select a mode, add a URL rule and save', async ( {
		page,
		admin,
	} ) => {
		const saves = [];
		await captureSettingsSaves( page, saves );

		await admin.visitAdminPage( `${ NEW_UI }&nav=settings` );

		await expect(
			page.getByRole( 'radio', { name: 'Show on all pages' } )
		).toBeVisible();

		// The URL box only shows for include/exclude modes.
		await expect( page.getByText( 'Content URLs' ) ).toBeHidden();

		await page
			.getByRole( 'radio', { name: 'Only on selected content' } )
			.check();

		await expect( page.getByText( 'Content URLs' ) ).toBeVisible();

		await page.getByRole( 'button', { name: 'Add URL rule' } ).click();
		await page.getByPlaceholder( '/example-page/' ).fill( '/shop/' );

		await page
			.getByRole( 'button', { name: 'Save changes' } )
			.first()
			.click();

		await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();

		expect( saves[ 0 ]?.data?.display_mode ).toBe( 'include' );
		expect( saves[ 0 ]?.data?.display_rules?.[ 0 ]?.path ).toBe( '/shop/' );
	} );

	test( 'visibility: manual mode hides the URL box', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage( `${ NEW_UI }&nav=settings` );

		await page
			.getByRole( 'radio', { name: "Don't show automatically" } )
			.check();

		await expect( page.getByText( 'Content URLs' ) ).toBeHidden();
	} );

	test( 'behavior: suggestions are locked on the free plan', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage( `${ NEW_UI }&nav=settings` );

		await expect(
			page.getByText( 'Greet visitors with ready-made questions' )
		).toBeVisible();
		await expect(
			page.getByLabel( 'Suggested question 1' )
		).toBeDisabled();
	} );

	test( 'behavior: privacy notice warns when no policy page is set', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage( `${ NEW_UI }&nav=settings` );

		const row = page
			.locator( '.hyve-next-field' )
			.filter( { hasText: 'Privacy notice' } );

		// Enabling the toggle surfaces the missing-page warning (the test
		// site has no Privacy Policy page configured).
		await row.getByRole( 'checkbox' ).check();

		await expect(
			page.getByText( 'No Privacy Policy page is set' )
		).toBeVisible();
	} );

	test( 'appearance: pro fields are locked and the position toggle works', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage(
			`${ NEW_UI }&nav=settings&sub=chat-appearance`
		);

		await expect(
			page.getByRole( 'heading', { name: 'Widget' } )
		).toBeVisible();
		await expect(
			page.getByText(
				'A custom name, icon and colors are part of Hyve Pro.'
			)
		).toBeVisible();
		await expect( page.getByLabel( 'Assistant name' ) ).toBeDisabled();

		// Position is a free control.
		await expect(
			page.getByRole( 'radio', { name: 'Left' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'radio', { name: 'Right' } )
		).toBeVisible();
	} );

	test( 'provider: API key status flips to Unsaved while editing, model saves', async ( {
		page,
		admin,
	} ) => {
		const saves = [];
		await captureSettingsSaves( page, saves );

		await admin.visitAdminPage(
			`${ NEW_UI }&nav=settings&sub=ai-provider`
		);

		await expect(
			page.getByRole( 'heading', { name: 'OpenAI' } )
		).toBeVisible();

		// The dummy test key counts as connected until an API error is stored.
		await expect(
			page.getByText( 'Connected', { exact: true } )
		).toBeVisible();

		await page.getByLabel( 'API key' ).fill( 'sk_new_key' );
		await expect( page.getByText( 'Unsaved' ) ).toBeVisible();

		await page.getByLabel( 'Model' ).selectOption( 'gpt-4.1-nano' );
		await page.getByRole( 'button', { name: 'Save changes' } ).click();

		await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();
		expect( saves[ 0 ]?.data?.api_key ).toBe( 'sk_new_key' );
		expect( saves[ 0 ]?.data?.chat_model ).toBe( 'gpt-4.1-nano' );
	} );

	test( 'advanced: sliders render and reset restores the defaults', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage(
			`${ NEW_UI }&nav=settings&sub=ai-advanced`
		);

		await expect(
			page.getByRole( 'heading', { name: 'Advanced tuning' } )
		).toBeVisible();

		const temperature = page
			.getByRole( 'slider', { name: 'Temperature' } )
			.first();
		await expect( temperature ).toBeVisible();
		await expect(
			page.getByRole( 'slider', { name: 'Top P' } ).first()
		).toBeVisible();
		await expect(
			page.getByRole( 'slider', { name: 'Similarity threshold' } ).first()
		).toBeVisible();

		await temperature.fill( '1.8' );
		await page.getByRole( 'button', { name: 'Reset to defaults' } ).click();

		await expect( temperature ).toHaveValue( '1' );
	} );

	test( 'general: toggles save automatically', async ( { page, admin } ) => {
		const saves = [];
		await captureSettingsSaves( page, saves );

		await admin.visitAdminPage( `${ NEW_UI }&nav=settings&sub=general` );

		await expect(
			page.getByRole( 'heading', { name: 'Site integration' } )
		).toBeVisible();

		const telemetryRow = page
			.locator( '.hyve-next-field' )
			.filter( { hasText: 'Telemetry' } );

		await telemetryRow.getByRole( 'checkbox' ).check();

		await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();
		expect( saves[ 0 ]?.data?.telemetry_enabled ).toBe( true );
	} );

	test( 'qdrant: connect form renders with its status chip', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage( `${ NEW_UI }&nav=settings&sub=qdrant` );

		await expect(
			page.getByRole( 'heading', { name: 'Qdrant' } )
		).toBeVisible();
		await expect( page.getByText( 'Not connected' ) ).toBeVisible();
		await expect( page.getByLabel( 'Qdrant API key' ) ).toBeVisible();
		await expect( page.getByLabel( 'Qdrant endpoint' ) ).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Connect' } )
		).toBeVisible();
	} );

	test( 'api access: free plan sees the upsell and the curl example', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage( `${ NEW_UI }&nav=settings&sub=api-access` );

		await expect(
			page.getByRole( 'heading', { name: 'API access' } )
		).toBeVisible();
		await expect( page.locator( '.hyve-next-code' ) ).toBeVisible();
		await expect(
			page.getByText( 'Search your Knowledge Base from anywhere' )
		).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: 'Unlock with Pro' } )
		).toBeVisible();
	} );
} );
