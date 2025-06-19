/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { Button, Panel, PanelRow } from '@wordpress/components';
import { Fragment } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';

/**
 * Internal dependencies.
 */
import { setUtm } from '../../utils';

export const ExternalSearch = () => {
	return (
		<div className="col-span-6 xl:col-span-4">
			<Panel header={ __( 'Semantic Search', 'hyve-lite' ) }>
				<PanelRow>
					<p>
						{ __(
							'Enable external services to search your Knowledge Base using advanced semantic search powered by Retrieval-Augmented Generation (RAG) and OpenAI embeddings.',
							'hyve-lite'
						) }{ ' ' }
						{ __(
							'Integrate automation tools (Zapier, n8n, etc.) with a secure API endpoint to perform intelligent content searches via simple API requests.',
							'hyve-lite'
						) }
					</p>
					<p>
						{ __(
							'With an Access Token, you can securely search your content from any location using the API.',
							'hyve-lite'
						) }
					</p>
					<p className="monospace bg-gray-100 p-2 rounded whitespace-pre">
						{ [
							'curl --request POST',
							`  --url ${ window.hyve.rest_url }/search-knowledge-base`,
							`  --header 'authorization: Bearer hyve_sk_MozAlDXXXXXXXXXXXXXXXX'`,
							"  --header 'content-type: application/json'",
							"  --data '{",
							'        "query": "What is the cost of the phone?"',
							"}'",
						].map( ( line, idx ) => (
							<Fragment key={ idx }>
								{ line }
								<br />
							</Fragment>
						) ) }
					</p>
				</PanelRow>
			</Panel>
			<br />
			{ applyFilters( 'hyve.tokens-management', <TokensManagement /> ) }
		</div>
	);
};

const TokensManagement = () => {
	return (
		<Panel header={ __( 'Access Tokens', 'hyve-lite' ) }>
			<PanelRow>
				<p>
					{ __(
						'Upgrade to Pro to unlock advanced access management: generate and manage secure API tokens, and control who can access your Knowledge Base via external integrations. Empower your team and automate workflows with confidence and security.',
						'hyve-lite'
					) }
				</p>
				<Button
					variant="primary"
					className="mt-2"
					target="_blank"
					href={ setUtm( window?.hyve?.pro, 'api-search' ) }
				>
					{ __( 'Get Hyve Pro!', 'hyve-lite' ) }
				</Button>
			</PanelRow>
		</Panel>
	);
};
