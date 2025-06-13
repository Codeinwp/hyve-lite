import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Block Editor', () => {
	test( 'empty knowledge base warning', async ( { page, admin, editor } ) => {
		await admin.createNewPost( { title: 'Dummy Post' } );

		await editor.insertBlock( {
			name: 'hyve/chat',
			attributes: {
				variant: 'floating',
			},
		} );

		await expect(
			page
				.locator( 'iframe[name="editor-canvas"]' )
				.contentFrame()
				.getByText( 'Your Knowledge Base is' )
		).toBeVisible();

		await expect(
			page
				.locator( 'iframe[name="editor-canvas"]' )
				.contentFrame()
				.getByRole( 'link', { name: 'Click here to add content.' } )
		).toBeVisible();
	} );
} );
