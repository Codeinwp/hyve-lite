/**
 * Mock the response the API for `/threads`.
 *
 * @param {import("@playwright/test").Page} page The page.
 */
export async function mockGetThreadsResponse( page ) {
	await page.route(
		/.*rest_route=%2Fhyve%2Fv1%2Fthreads.*offset=0.*/,
		async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					posts: [
						{
							ID: 150,
							title: 'How to reset my password?',
							date: '2025-05-26T13:02:20+00:00',
							thread: [
								{
									time: 1748264540,
									sender: 'user',
									message: 'How do I reset my password?',
								},
								{
									time: 1748264544,
									sender: 'bot',
									message:
										'<p>You can reset your password by going to the login page and clicking "Forgot Password". Enter your email address and follow the instructions sent to your inbox.</p>',
								},
								{
									time: 1748265203,
									sender: 'user',
									message: 'I did not receive the email',
								},
								{
									time: 1748265207,
									sender: 'bot',
									message:
										'<p>Please check your spam folder. If you still do not see it, make sure you entered the correct email address associated with your account.</p>',
								},
								{
									time: 1748265217,
									sender: 'user',
									message: 'Thanks, found it in spam!',
								},
								{
									time: 1748267412,
									sender: 'bot',
									message:
										'<p>Great! Is there anything else I can help you with?</p>',
								},
							],
							thread_id: 'thread_S1sTWm3SoQFa5D0LxzNpY9mE',
						},
						{
							ID: 149,
							title: 'Order status inquiry',
							date: '2025-05-26T13:02:11+00:00',
							thread: [
								{
									time: 1748264531,
									sender: 'user',
									message:
										'What is the status of my order #12345?',
								},
								{
									time: 1748264535,
									sender: 'bot',
									message:
										'<p>Your order #12345 has been shipped and should arrive within 2-3 business days. You can track it using the tracking number sent to your email.</p>',
								},
							],
							thread_id: 'thread_omANmzE1oYI6FFDfc3j3pCQB',
						},
						{
							ID: 148,
							title: 'Product availability',
							date: '2025-05-26T13:02:06+00:00',
							thread: [
								{
									time: 1748264526,
									sender: 'user',
									message:
										'Is the red t-shirt in size M available?',
								},
								{
									time: 1748264530,
									sender: 'bot',
									message:
										'<p>Yes, the red t-shirt in size M is currently in stock. Would you like me to help you add it to your cart?</p>',
								},
							],
							thread_id: 'thread_lFSy430KC6bJ3kxsAr8SNZrv',
						},
						{
							ID: 147,
							title: 'Return policy question',
							date: '2025-05-26T13:01:37+00:00',
							thread: [
								{
									time: 1748264497,
									sender: 'user',
									message: 'What is your return policy?',
								},
								{
									time: 1748264501,
									sender: 'bot',
									message:
										'<p>We offer a 30-day return policy for unused items in original packaging. You can initiate a return from your account dashboard.</p>',
								},
							],
							thread_id: 'thread_QG9UaCmeYI5ItcTP7Gzgz40D',
						},
					],
					more: false,
				} ),
			} );
		}
	);
}

export async function mockConfirmDeleteThreadResponse( page ) {
	await page.route(
		/.*rest_route=%2Fhyve%2Fv1%2Fthreads.*id=\d+.*/,
		async ( route ) => {
			const request = route.request();
			// Check if this is a DELETE request (via POST with method override)
			if (
				request.method() === 'POST' &&
				request.headers()[ 'x-http-method-override' ] === 'DELETE'
			) {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						success: true,
						data: 'Thread removed from local storage. It remains accessible via the OpenAI API.',
					} ),
				} );
			} else {
				await route.continue();
			}
		}
	);
}
