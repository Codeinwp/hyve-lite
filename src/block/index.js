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
	edit: ( { attributes } ) => {
		// eslint-disable-next-line react-hooks/rules-of-hooks
		const blockProps = useBlockProps();

		const isKnowledgeBaseEmpty =
			0 === Number( window.hyveChatBlock.stats.totalChunks ?? '0' );
		const isGlobalChat = Boolean( window.hyveChatBlock?.globalChatEnabled );
		const isFloatingVariant = 'floating' === attributes?.variant;
		let placeholderText = __(
			'Hyve Chatbot will appear here. No further action needed.',
			'hyve-lite'
		);

		if ( isFloatingVariant ) {
			placeholderText = __(
				'Hyve Chatbot bubble will appear on this page. No further action needed.',
				'hyve-lite'
			);
		}

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
					{ isGlobalChat && ! isFloatingVariant && (
						<Notice isDismissible={ false } status="info">
							<p>
								{ __(
									'Hyve Chat is set to appear on all pages. This inline block will be shown here in place of the floating bubble.',
									'hyve-lite'
								) }
							</p>
						</Notice>
					) }
					{ isGlobalChat && isFloatingVariant && (
						<Notice isDismissible={ false } status="info">
							<p>
								{ __(
									'Hyve Chat already appears on all pages, so this floating block is not needed here.',
									'hyve-lite'
								) }
							</p>
						</Notice>
					) }
					{ placeholderText }
				</Placeholder>
			</div>
		);
	},
	save: () => null,
} );
