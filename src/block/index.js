/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

import { registerBlockType } from '@wordpress/blocks';

import { useBlockProps } from '@wordpress/block-editor';

import { Placeholder, Button, Notice } from '@wordpress/components';

/**
 * Internal dependencies
 */
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit: () => {
		// eslint-disable-next-line react-hooks/rules-of-hooks
		const blockProps = useBlockProps();

		const isKnowledgeBaseEmpty =
			0 === Number( window.hyveChatBlock.stats.totalChunks ?? '0' );
		const isBlockIgnored = Boolean(
			window.hyveChatBlock?.globalChatEnabled
		);

		return (
			<div { ...blockProps }>
				<Placeholder>
					{ isKnowledgeBaseEmpty && (
						<Notice isDismissible={ false } status="warning">
							<p>
								{ __(
									'Your Knowledge Base is currently empty.',
									'hyve-lite'
								) }{ ' ' }
								{ __(
									'The Chat won’t be able to respond to questions until sources are added.',
									'hyve-lite'
								) }
								<Button
									variant="link"
									style={ { paddingLeft: '3px' } }
									onClick={ ( event ) => {
										event.preventDefault();
										window.open(
											window.hyveChatBlock
												.knowledgeBaseURL,
											'_blank'
										);
									} }
								>
									{ __(
										'Click here to add content.',
										'hyve-lite'
									) }
								</Button>
							</p>
						</Notice>
					) }
					{ isBlockIgnored ? (
						<div
							style={ {
								display: 'flex',
								flexDirection: 'column',
								alignItems: 'flex-start',
								gap: '1rem',
							} }
						>
							{ __(
								'The Hyve Chat is enabled on all pages, and it won’t appear here to avoid conflicts.',
								'hyve-lite'
							) }
							<Button
								variant="secondary"
								onClick={ ( event ) => {
									event.preventDefault();
									window.open(
										window.hyveChatBlock.dashboardURL,
										'_blank'
									);
								} }
							>
								{ __( 'Go to Dashboard', 'hyve-lite' ) }
							</Button>
						</div>
					) : (
						__(
							'Hyve Chatbot will appear here. No further action needed.',
							'hyve-lite'
						)
					) }
				</Placeholder>
			</div>
		);
	},
	save: () => null,
} );
