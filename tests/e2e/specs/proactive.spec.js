import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * The proactive teaser's scroll trigger. Pro supplies the config through
 * `window.hyveClient.proactive`; injecting it here exercises the same lite
 * code path a Pro site runs.
 */
test.describe( 'Proactive teaser (scroll trigger)', () => {
	/**
	 * Publish a post with the floating chat block, open it, and make the
	 * page tall enough to scroll.
	 *
	 * @param {import('@playwright/test').Page}                       page
	 * @param {import('@wordpress/e2e-test-utils-playwright').Admin}  admin
	 * @param {import('@wordpress/e2e-test-utils-playwright').Editor} editor
	 */
	async function openTallPageWithChat( page, admin, editor ) {
		await admin.createNewPost( { title: 'Proactive Scroll Page' } );

		await editor.insertBlock( {
			name: 'hyve/chat',
			attributes: { variant: 'floating' },
		} );

		const postId = await editor.publishPost();

		await page.goto( `?p=${ postId }` );

		await page.evaluate( () => {
			if (
				! document.querySelector(
					'#hyve-open, #hyve-window, .hyve-input-text'
				)
			) {
				window?.hyveApp?.initialize();
			}

			// Make the page scrollable well past any viewport.
			const spacer = document.createElement( 'div' );
			spacer.style.height = '5000px';
			document.body.appendChild( spacer );
		} );

		await expect( page.locator( '#hyve-open' ) ).toBeVisible();
	}

	/**
	 * Arm the scroll trigger with the given config, as Pro would on load.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @param {number}                          scrollDepth
	 */
	async function armScrollTeaser( page, scrollDepth ) {
		await page.evaluate( ( depth ) => {
			window.hyveClient.proactive = {
				trigger: 'scroll',
				message: 'Need a hand?',
				scrollDepth: depth,
			};
			window.hyveApp.setupProactive();
		}, scrollDepth );
	}

	test( 'appears when the visitor scrolls past the configured depth', async ( {
		page,
		admin,
		editor,
	} ) => {
		await openTallPageWithChat( page, admin, editor );
		await armScrollTeaser( page, 50 );

		// Above the depth: no teaser yet.
		await expect( page.locator( '#hyve-teaser' ) ).toBeHidden();

		await page.evaluate( () =>
			window.scrollTo(
				0,
				document.documentElement.scrollHeight - window.innerHeight
			)
		);

		await expect( page.locator( '#hyve-teaser' ) ).toBeVisible();
		await expect( page.locator( '#hyve-teaser' ) ).toContainText(
			'Need a hand?'
		);
	} );

	test( 'appears immediately when the visitor is already past the depth', async ( {
		page,
		admin,
		editor,
	} ) => {
		await openTallPageWithChat( page, admin, editor );

		// Scroll first (anchor link / restored scroll position), then arm:
		// no further scroll event will fire, the arm-time check must show it.
		await page.evaluate( () =>
			window.scrollTo(
				0,
				document.documentElement.scrollHeight - window.innerHeight
			)
		);

		await armScrollTeaser( page, 50 );

		await expect( page.locator( '#hyve-teaser' ) ).toBeVisible();
	} );

	test( 'stays hidden below the configured depth', async ( {
		page,
		admin,
		editor,
	} ) => {
		await openTallPageWithChat( page, admin, editor );
		await armScrollTeaser( page, 90 );

		// Scroll to roughly half the page: under the 90% depth.
		await page.evaluate( () =>
			window.scrollTo(
				0,
				( document.documentElement.scrollHeight - window.innerHeight ) *
					0.5
			)
		);

		// Give any scroll handler a chance to run before asserting.
		await page.waitForTimeout( 250 );
		await expect( page.locator( '#hyve-teaser' ) ).toBeHidden();
	} );
} );
