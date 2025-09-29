// @ts-check

/**
 * WordPress dependencies.
 */
import { addQueryArgs } from '@wordpress/url';

const clickAudio = new Audio( window.hyveClient.audio.click );
const pingAudio = new Audio( window.hyveClient.audio.ping );
const { strings } = window.hyveClient;

class App {
	constructor() {
		this.isInitialToggle = true;
		this.hasSuggestions = false;
		this.messages = [];
		this.threadID = null;
		this.recordID = null;
		this.isMenuOpen = false;
		this.currentEventSource = null;

		if ( Boolean( window.hyveClient?.canShow ) ) {
			this.initialize();
		}
	}

	async initialize() {
		this.restoreStorage();
		await this.renderUI();
		this.setupListeners();
		this.restoreMessages();
	}

	restoreStorage() {
		const storageData = window.localStorage.getItem( 'hyve-chat' );

		if ( null === storageData ) {
			return;
		}

		const storage = JSON.parse( storageData );

		if ( ! storage.timestamp ) {
			return;
		}

		if ( this.isStorageExpired( storage ) ) {
			window.localStorage.removeItem( 'hyve-chat' );
			return;
		}

		this.messages = storage.messages;
		this.threadID = storage.threadID;
		this.recordID = storage.recordID;
		this.isInitialToggle = false;
	}

	restoreMessages() {
		this.messages.forEach( ( message ) => {
			this.addMessage(
				message.time,
				message.message,
				message.sender,
				message.id,
				false
			);
		} );
	}

	/**
	 * Check if storage data has expired or is invalid
	 * @param {Object} storage - The parsed storage data
	 * @return {boolean} True if storage should be cleared
	 */
	isStorageExpired( storage ) {
		const EXPIRY_TIME_MS = 24 * 60 * 60 * 1000; // 24 hours in milliseconds

		if ( ! storage.threadID ) {
			return true;
		}

		const storageTime = new Date( storage.timestamp ).getTime();

		// Check if timestamp is valid
		if ( isNaN( storageTime ) ) {
			return true;
		}

		const currentTime = Date.now();

		return currentTime - storageTime > EXPIRY_TIME_MS;
	}

	updateStorage() {
		const messages = this.messages
			.filter( ( message ) => null === message.id )
			.slice( -20 );

		window.localStorage.setItem(
			'hyve-chat',
			JSON.stringify( {
				timestamp: new Date(),
				messages,
				threadID: this.threadID,
				recordID: this.recordID,
			} )
		);
	}

	add( message, sender, id = null ) {
		const time = new Date();

		if ( 'user' === sender ) {
			message = this.sanitize( message );
		}

		message = this.addTargetBlank( message );

		this.messages.push( { time, message, sender, id } );
		this.addMessage( time, message, sender, id );

		this.updateStorage();

		if ( 'user' !== sender ) {
			return;
		}

		this.sendRequest( message );

		if ( this.hasSuggestions ) {
			this.removeSuggestions();
		}
	}

	sanitize( input ) {
		const tempDiv = document.createElement( 'div' );
		tempDiv.textContent = input;
		return tempDiv.innerHTML;
	}

	addTargetBlank( message ) {
		const tempDiv = document.createElement( 'div' );
		tempDiv.innerHTML = message;

		const links = tempDiv.querySelectorAll( 'a' );

		links.forEach( ( link ) => {
			link.target = '_blank';
		} );

		const images = tempDiv.querySelectorAll( 'img' );

		images.forEach( ( image ) => {
			const anchor = document.createElement( 'a' );
			anchor.href = image.src;
			anchor.target = '_blank';
			anchor.appendChild( image.cloneNode( true ) );
			image.parentNode?.replaceChild( anchor, image );
		} );

		return tempDiv.innerHTML;
	}

	formatDate( date ) {
		const options = {
			day: '2-digit',
			month: '2-digit',
			year: 'numeric',
			hour: '2-digit',
			minute: '2-digit',
			hour12: false,
		};

		return new Intl.DateTimeFormat( 'en-GB', options )
			.format( new Date( date ) )
			.replace( ',', '' );
	}

	setThreadID( threadID ) {
		this.threadID = threadID;
	}

	setRecordID( recordID ) {
		this.recordID = recordID;
	}

	setLoading( isLoading ) {
		const chatInputText = document.querySelector( '#hyve-text-input' );
		const chatSendButton = document.querySelector(
			'.hyve-send-button button'
		);

		if ( ! chatInputText || ! chatSendButton ) {
			return;
		}

		chatInputText.disabled = isLoading;
		chatSendButton.disabled = isLoading;
	}

	/**
	 * Handle streaming response using fetch with streaming
	 * @param {string} message The user's message
	 */
	async handleStreamingResponse( message ) {
		// Close any existing connection (for future compatibility)
		if ( this.currentEventSource ) {
			this.currentEventSource.close();
			this.currentEventSource = null;
		}

		try {
			// Create AbortController for timeout handling
			const controller = new AbortController();
			const timeoutId = setTimeout( () => {
				controller.abort();
			}, 60000 ); // 60 second timeout

			try {
				const response = await fetch(
					this.addCacheProtection( window.hyveClient.api ),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': window.hyveClient.nonce,
						},
						body: JSON.stringify( {
							message,
							...( this.threadID
								? { thread_id: this.threadID }
								: {} ),
						} ),
						signal: controller.signal,
					}
				);

				// Clear timeout on successful response
				clearTimeout( timeoutId );

				if ( ! response.ok ) {
					throw new Error(
						`HTTP error! status: ${ response.status }`
					);
				}

				if ( ! response.body ) {
					throw new Error( 'Response body is null' );
				}

				const reader = response.body.getReader();
				const decoder = new TextDecoder();
				let buffer = '';
				let currentEventType = null;

				while ( true ) {
					const { done, value } = await reader.read();

					if ( done ) {
						break;
					}

					// Decode the chunk and add to buffer
					buffer += decoder.decode( value, { stream: true } );

					// Process complete Server-Sent Event messages
					const lines = buffer.split( '\n' );
					buffer = lines.pop() || ''; // Keep incomplete line in buffer

					for ( const line of lines ) {
						if ( line.trim() === '' ) {
							// Empty line indicates end of event, reset currentEventType
							currentEventType = null;
							continue;
						}

						// Parse Server-Sent Event format
						if ( line.startsWith( 'event: ' ) ) {
							currentEventType = line.substring( 7 ); // Remove 'event: '
							continue;
						}

						if ( line.startsWith( 'data: ' ) ) {
							const eventData = line.substring( 6 ); // Remove 'data: '

							try {
								const data = JSON.parse( eventData );
								// Add the event type to the data object
								data.type = currentEventType;
								this.handleStreamEvent( data );
							} catch ( parseError ) {
								// SSE parsing failed - continue processing other events
							}
						}
					}
				}

				this.setLoading( false );
			} catch ( fetchError ) {
				// Clear timeout on error
				clearTimeout( timeoutId );

				// Handle AbortError from timeout differently
				if ( fetchError.name === 'AbortError' ) {
					throw new Error( 'Request timeout - please try again' );
				}
				throw fetchError;
			}
		} catch ( error ) {
			// Enhanced error handling with user-friendly messages
			this.removeMessage( 'streaming-response' );
			this.handleStreamingError( error );
			this.setLoading( false );
		}
	}

	/**
	 * Handle individual stream events
	 * @param {Object} data The event data
	 */
	handleStreamEvent( data ) {
		if ( data.type === 'init' ) {
			// Update thread_id if provided
			if ( data.thread_id && data.thread_id !== this.threadID ) {
				this.setThreadID( data.thread_id );
			}

			// Remove the typing indicator and add streaming message
			this.removeMessage( 'streaming-preloader' );
			this.add(
				strings.typing + ' (streaming)',
				'bot',
				'streaming-response'
			);
		} else if ( data.type === 'response.output_text.delta' ) {
			// Handle OpenAI streaming text delta
			const streamingMessage = this.messages.find(
				( message ) => message.id === 'streaming-response'
			);
			const streamingElement = document.getElementById(
				'hyve-message-streaming-response'
			);

			if ( streamingMessage && streamingElement ) {
				// Initialize fullText if not exists
				if ( ! streamingMessage.fullText ) {
					streamingMessage.fullText = '';
				}

				// Accumulate all deltas to build the complete JSON response
				streamingMessage.fullText += data.delta;

				// Try to extract and display the response content as it builds
				let displayText = strings.typing + '...';

				// Look for the start of the response content
				const responseStartMatch =
					streamingMessage.fullText.match( /"response":"/ );
				if ( responseStartMatch ) {
					// Find where the response content starts
					const contentStart =
						responseStartMatch.index +
						responseStartMatch[ 0 ].length;
					let responseContent =
						streamingMessage.fullText.substring( contentStart );

					// Try to find the end of the response field (look for the closing quote before success)
					// We need to be careful with escaped quotes in HTML content
					let contentEnd = responseContent.length;

					// Look for the pattern ","success" which indicates end of response field
					const successMatch = responseContent.match( /","success"/ );
					if ( successMatch ) {
						contentEnd = successMatch.index;
					}

					// Extract the content (still may have escaped characters)
					responseContent = responseContent.substring(
						0,
						contentEnd
					);

					// Try to decode JSON escapes for display
					try {
						// Attempt to parse the JSON-escaped string
						displayText = JSON.parse( '"' + responseContent + '"' );

						// If we got valid content, process it for streaming display
						if ( displayText && displayText.trim() ) {
							displayText =
								this.processStreamingHTML( displayText );
						} else {
							displayText = strings.typing + '...';
						}
					} catch ( e ) {
						// If JSON parsing fails, show raw content (still building)
						if ( responseContent && responseContent.length > 0 ) {
							// Clean up escaped characters for display
							const rawContent = responseContent
								.replace( /\\"/g, '"' )
								.replace( /\\\//g, '/' );

							displayText =
								this.processStreamingHTML( rawContent );
						} else {
							displayText = strings.typing + '...';
						}
					}
				}

				// Update the streaming message display
				streamingMessage.message = displayText;
				streamingElement.querySelector( 'div' ).innerHTML = displayText;

				// Auto-scroll to bottom during streaming
				this.scrollToBottom();

				// Add subtle typing animation class for smoother feel
				const messageDiv = streamingElement.querySelector( 'div' );
				if ( messageDiv ) {
					messageDiv.classList.add( 'hyve-typing-animation' );
					// Remove animation class briefly to retrigger it
					setTimeout( () => {
						if ( messageDiv ) {
							messageDiv.classList.remove(
								'hyve-typing-animation'
							);
						}
					}, 500 );
				}
			}
		} else if ( data.type === 'response.completed' ) {
			// Handle OpenAI response completion
			const streamingMessage = this.messages.find(
				( message ) => message.id === 'streaming-response'
			);

			if ( streamingMessage && streamingMessage.fullText ) {
				try {
					const parsed = JSON.parse( streamingMessage.fullText );

					// Remove streaming message and add final response
					this.removeMessage( 'streaming-response' );

					if ( parsed.success && parsed.response ) {
						// Successful response with content
						this.add( parsed.response, 'bot' );
					} else if ( parsed.success === false ) {
						// OpenAI couldn't answer - show helpful message
						this.add(
							"<p><em>I don't have enough information to answer that question. Please provide more context or ask about something else.</em></p>",
							'bot'
						);
					} else {
						// Fallback - show whatever we have
						this.add(
							parsed.response || 'No response available',
							'bot'
						);
					}
				} catch ( e ) {
					// JSON parsing failed - show raw text
					this.removeMessage( 'streaming-response' );
					this.add(
						streamingMessage.fullText || 'Error parsing response',
						'bot'
					);
				}
			} else {
				// No streaming message found
				this.removeMessage( 'streaming-response' );
				this.add( 'Response completed but no content received', 'bot' );
			}
		} else if ( data.type === 'data' ) {
			// Legacy handling for non-OpenAI streaming
			this.removeMessage( 'streaming-response' );
			this.add( data.message || JSON.stringify( data ), 'bot' );
		} else if ( data.type === 'error' ) {
			this.removeMessage( 'streaming-response' );
			this.add( data.error || strings.tryAgain, 'bot' );
		} else if ( data.type === 'end' ) {
			// Stream completed successfully
			// Stream completed successfully
		}
	}

	/**
	 * Process HTML content for streaming display, handling incomplete tags gracefully
	 * @param {string} content The HTML content to process
	 * @return {string} Processed content safe for streaming display
	 */
	processStreamingHTML( content ) {
		if ( ! content || ! content.trim() ) {
			return strings.typing + '...';
		}

		// Find all complete HTML tags and text content
		const parts = [];
		const currentPos = 0;
		let insideTag = false;
		let tagStart = -1;
		let textBuffer = '';

		for ( let i = 0; i < content.length; i++ ) {
			const char = content[ i ];

			if ( char === '<' && ! insideTag ) {
				// Starting a new tag - save any accumulated text first
				if ( textBuffer ) {
					parts.push( { type: 'text', content: textBuffer } );
					textBuffer = '';
				}
				insideTag = true;
				tagStart = i;
			} else if ( char === '>' && insideTag ) {
				// Ending a tag - check if it's complete
				const tagContent = content.substring( tagStart, i + 1 );

				// Check if it's a complete, valid tag
				if ( this.isCompleteHTMLTag( tagContent ) ) {
					parts.push( { type: 'tag', content: tagContent } );
				} else {
					// Incomplete or invalid tag - treat as text for now
					textBuffer += tagContent;
				}

				insideTag = false;
				tagStart = -1;
			} else if ( ! insideTag ) {
				// Regular text content
				textBuffer += char;
			}
			// If we're inside a tag, we wait until it's complete
		}

		// Handle any remaining content
		if ( insideTag && tagStart !== -1 ) {
			// We have an incomplete tag at the end - don't show it
			const incompleteTag = content.substring( tagStart );
			// Only show if it looks like it might be text that was mistaken for a tag
			if (
				incompleteTag.length > 10 &&
				! incompleteTag.match( /^<[a-zA-Z]/ )
			) {
				textBuffer += incompleteTag;
			}
		} else if ( textBuffer ) {
			parts.push( { type: 'text', content: textBuffer } );
		}

		// Rebuild the content with complete elements only
		let result = '';
		const openTags = [];

		for ( const part of parts ) {
			if ( part.type === 'text' ) {
				result += part.content;
			} else if ( part.type === 'tag' ) {
				const tag = part.content;

				// Check if it's a closing tag
				if ( tag.startsWith( '</' ) ) {
					const tagName = tag.match( /^<\/([a-zA-Z]+)/ );
					if ( tagName && openTags.includes( tagName[ 1 ] ) ) {
						result += tag;
						// Remove from open tags
						const index = openTags.lastIndexOf( tagName[ 1 ] );
						if ( index !== -1 ) {
							openTags.splice( index, 1 );
						}
					}
				} else {
					// Opening tag or self-closing tag
					const tagMatch = tag.match(
						/^<([a-zA-Z]+)([^>]*?)(\s*\/?)>/
					);
					if ( tagMatch ) {
						const tagName = tagMatch[ 1 ];
						const isSelfClosing =
							tagMatch[ 3 ] === '/' ||
							[
								'br',
								'hr',
								'img',
								'input',
								'meta',
								'link',
							].includes( tagName.toLowerCase() );

						result += tag;

						if ( ! isSelfClosing ) {
							openTags.push( tagName );
						}
					}
				}
			}
		}

		// If we have no result yet, show typing indicator
		if ( ! result.trim() ) {
			return strings.typing + '...';
		}

		return result;
	}

	/**
	 * Check if an HTML tag is complete and valid
	 * @param {string} tag The tag to check
	 * @return {boolean} True if the tag is complete and valid
	 */
	isCompleteHTMLTag( tag ) {
		if ( ! tag.startsWith( '<' ) || ! tag.endsWith( '>' ) ) {
			return false;
		}

		// Basic validation for common HTML tags
		const tagPattern = /^<\/?[a-zA-Z][a-zA-Z0-9]*([^<>]*?)\/?>$/;
		return tagPattern.test( tag );
	}

	async sendRequest( message ) {
		try {
			this.setLoading( true );

			// Clean up any previous streaming state before starting new request
			this.removeMessage( 'streaming-response' );
			this.removeMessage( 'streaming-preloader' );

			// Close any existing streaming connections
			if ( this.currentEventSource ) {
				this.currentEventSource.close();
				this.currentEventSource = null;
			}

			// Always use streaming response
			const preloaderId = 'streaming-preloader';
			this.addPreloaderMessage( preloaderId );
			await this.handleStreamingResponse( message );
		} catch ( error ) {
			this.removeMessage( 'streaming-preloader' );
			this.removeMessage( 'streaming-response' );
			this.add( strings.tryAgain, 'bot' );
			// Error handled - consider implementing error reporting service
			this.setLoading( false );
		}
	}

	/**
	 * Enhanced error handling for streaming requests
	 * @param {Error} error The error object
	 */
	handleStreamingError( error ) {
		let errorMessage = strings.tryAgain;

		// Provide user-friendly error messages based on error type
		if ( error.name === 'TypeError' && error.message.includes( 'fetch' ) ) {
			errorMessage =
				'Connection failed. Please check your internet connection and try again.';
		} else if ( error.message.includes( 'HTTP error! status: 429' ) ) {
			errorMessage =
				'Too many requests. Please wait a moment and try again.';
		} else if ( error.message.includes( 'HTTP error! status: 401' ) ) {
			errorMessage =
				'Authentication failed. Please refresh the page and try again.';
		} else if ( error.message.includes( 'HTTP error! status: 500' ) ) {
			errorMessage = 'Server error. Please try again in a few moments.';
		} else if ( error.message.includes( 'timeout' ) ) {
			errorMessage =
				'Request timed out. The server may be busy. Please try again.';
		} else if ( error.message.includes( 'Response body is null' ) ) {
			errorMessage =
				'No response received. Please check your connection and try again.';
		}

		this.add( errorMessage, 'bot' );
	}

	/**
	 * Smoothly scroll chat to bottom
	 */
	scrollToBottom() {
		const chatMessageBox = document.getElementById( 'hyve-message-box' );
		if ( chatMessageBox ) {
			// Use smooth scrolling if supported, instant fallback
			chatMessageBox.scrollTo( {
				top: chatMessageBox.scrollHeight,
				behavior: 'smooth',
			} );
		}
	}

	addAudioPlayback( audioElement ) {
		audioElement.play();
	}

	addMessage( time, message, sender, id, sound = true ) {
		const chatMessageBox = document.getElementById( 'hyve-message-box' );
		if ( ! chatMessageBox ) {
			return;
		}

		const date = this.formatDate( time );

		let messageHTML = `<div>${ message }</div>`;

		if ( null === id ) {
			messageHTML += `<time datetime="${ time }">${ date }</time>`;
		}

		const messageDiv = this.createElement( 'div', {
			className: `hyve-${ sender }-message`,
			innerHTML: messageHTML,
		} );

		if (
			'bot' === sender &&
			window.hyveClient.colors?.assistant_background
		) {
			messageDiv.classList.add( 'is-dark' );
		}

		if ( 'user' === sender && window.hyveClient.colors?.user_background ) {
			messageDiv.classList.add( 'is-dark' );
		}

		if (
			'user' === sender &&
			! window.hyveClient.colors?.user_background &&
			undefined !== window.hyveClient.colors?.user_background
		) {
			messageDiv.classList.add( 'is-light' );
		}

		this.setMenuVisibility( 1 < this.messages.length );

		if ( null !== id ) {
			messageDiv.id = `hyve-message-${ id }`;
		}

		chatMessageBox.appendChild( messageDiv );
		chatMessageBox.scrollTop = chatMessageBox.scrollHeight;

		if ( ! sound ) {
			return;
		}

		this.addAudioPlayback( pingAudio );
	}

	removeMessage( id ) {
		const message = document.getElementById( `hyve-message-${ id }` );
		if ( message ) {
			message.remove();
		}

		// Remove from messages array
		const messageIndex = this.messages.findIndex(
			( msg ) => msg.id === id
		);
		if ( messageIndex !== -1 ) {
			this.messages.splice( messageIndex, 1 );
		}

		// Update storage after removing message
		this.updateStorage();
	}

	toggleChatWindow( isOpen ) {
		const openButton = document.getElementById( 'hyve-open' );
		const closeButton = document.getElementById( 'hyve-close' );
		const chatWindow = document.getElementById( 'hyve-window' );

		if ( ! openButton || ! closeButton || ! chatWindow ) {
			return;
		}

		if ( isOpen ) {
			openButton.style.display = 'none';
			closeButton.style.display = 'block';
			chatWindow.style.display = 'block';

			const chatMessageBox =
				document.getElementById( 'hyve-message-box' );
			if ( chatMessageBox ) {
				chatMessageBox.scrollTop = chatMessageBox.scrollHeight;
			}
		} else {
			openButton.style.display = 'block';
			closeButton.style.display = 'none';
			chatWindow.style.display = 'none';
		}

		this.addAudioPlayback( clickAudio );

		if (
			window.hyveClient.welcome &&
			'' !== window.hyveClient.welcome &&
			this.isInitialToggle &&
			! this.hasUserMessages()
		) {
			this.isInitialToggle = false;
			const welcomeMessage = window.hyveClient.welcome;

			setTimeout( () => {
				this.add( welcomeMessage, 'bot' );
				this.addSuggestions();
			}, 1000 );
		}
	}

	addSuggestions() {
		const questions = window.hyveClient?.predefinedQuestions;

		if ( ! Array.isArray( questions ) ) {
			return;
		}

		const filteredQuestions = questions.filter(
			( question ) => '' !== question.trim()
		);

		if ( 0 === filteredQuestions.length ) {
			return;
		}

		const chatMessageBox = document.getElementById( 'hyve-message-box' );

		const suggestions = [ `<span>${ strings.suggestions }</span>` ];

		filteredQuestions.forEach( ( question ) => {
			suggestions.push( `<button>${ question }</button>` );
		} );

		const messageDiv = this.createElement( 'div', {
			className: 'hyve-suggestions',
			innerHTML: suggestions.join( '' ),
		} );

		if ( window.hyveClient.colors?.user_background ) {
			messageDiv.classList.add( 'is-dark' );
		}

		if (
			! window.hyveClient.colors?.user_background &&
			undefined !== window.hyveClient.colors?.user_background
		) {
			messageDiv.classList.add( 'is-light' );
		}

		const suggestionButtons = messageDiv.querySelectorAll( 'button' );

		suggestionButtons.forEach( ( button ) => {
			button.addEventListener( 'click', () => {
				this.add( button.textContent, 'user' );
			} );
		} );

		chatMessageBox?.appendChild( messageDiv );

		this.hasSuggestions = true;
	}

	removeSuggestions() {
		const suggestions = document.querySelector( '.hyve-suggestions' );

		if ( suggestions ) {
			suggestions.remove();
			this.hasSuggestions = false;
		}
	}

	clearConversation() {
		// Close any active streaming connection
		if ( this.currentEventSource ) {
			if ( typeof this.currentEventSource.close === 'function' ) {
				this.currentEventSource.close();
			}
			this.currentEventSource = null;
		}

		// Clear messages from UI
		const chatMessageBox = document.getElementById( 'hyve-message-box' );
		chatMessageBox.innerHTML = '';

		// Reset state
		this.messages = [];
		this.threadID = null;
		this.recordID = null;
		this.hasSuggestions = false;
		this.isInitialToggle = true;

		// Clear local storage
		window.localStorage.removeItem( 'hyve-chat' );

		// Close menu
		this.toggleMenu( false );
		this.setMenuVisibility( false );

		// Add welcome message if configured
		if ( window.hyveClient.welcome && '' !== window.hyveClient.welcome ) {
			setTimeout( () => {
				this.add( window.hyveClient.welcome, 'bot' );
				this.addSuggestions();
			}, 500 );
		}
	}

	toggleMenu( isOpen ) {
		this.isMenuOpen = isOpen ?? ! this.isMenuOpen;
		const menu = document.querySelector( '.hyve-menu-dropdown' );

		if ( menu ) {
			menu.style.display = this.isMenuOpen ? 'block' : 'none';
		}
	}

	setupListeners() {
		const chatInputText =
			/** @type {HTMLInputElement|null} */
			( document.getElementById( 'hyve-text-input' ) );
		const chatSendButton = document.getElementById( 'hyve-send-button' );

		if ( ! chatInputText || ! chatSendButton ) {
			return;
		}

		const chatOpen = document.getElementById( 'hyve-open' );
		const chatClose = document.getElementById( 'hyve-close' );

		if ( chatOpen && chatClose ) {
			chatOpen.addEventListener( 'click', () =>
				this.toggleChatWindow( true )
			);
			chatClose.addEventListener( 'click', () =>
				this.toggleChatWindow( false )
			);
		}

		// Add menu listeners
		const menuButtonElem = document.getElementById( 'hyve-menu-button' );
		if ( menuButtonElem ) {
			menuButtonElem.addEventListener( 'click', ( event ) => {
				event.stopPropagation();
				this.toggleMenu();
			} );
		}

		const clearButton = document.getElementById(
			'hyve-clear-conversation'
		);
		if ( clearButton ) {
			clearButton.addEventListener( 'click', () => {
				this.clearConversation();
			} );
		}

		// Close menu when clicking outside
		document.addEventListener( 'click', ( event ) => {
			const menu = document.querySelector( '.hyve-menu-dropdown' );
			const menuButton = document.getElementById( 'hyve-menu-button' );

			if (
				menu &&
				menuButton &&
				! menu.contains( event.target ) &&
				! menuButton.contains( event.target )
			) {
				this.toggleMenu( false );
			}
		} );

		chatInputText.addEventListener( 'keydown', ( event ) => {
			if ( 13 === event.keyCode ) {
				if ( '' !== chatInputText.value.trim() ) {
					this.add( chatInputText.value, 'user' );
					chatInputText.value = '';
				}
			}
		} );

		chatSendButton.addEventListener( 'click', () => {
			if ( '' !== chatInputText.value.trim() ) {
				this.add( chatInputText.value, 'user' );
				chatInputText.value = '';
			}
		} );
	}

	createElement( tag, props, ...children ) {
		const element = document.createElement( tag );
		Object.assign( element, props );
		children.forEach( ( child ) => {
			if ( 'string' === typeof child ) {
				element.appendChild( document.createTextNode( child ) );
			} else {
				element.appendChild( child );
			}
		} );
		return element;
	}

	addCacheProtection( url ) {
		return addQueryArgs( url, {
			t: Date.now(),
		} );
	}

	async renderUI() {
		const chatOpenButton = this.createElement( 'button', {
			className: 'collapsible open',
		} );

		let useDefaultIcon = true;
		if ( 'svg' === window.hyveClient?.chatIcon?.type ) {
			/**
			 * NOTE: Download the SVG to that we can use the styling via CSS.
			 */
			const iconURL =
				window.hyveClient?.icons?.[
					window.hyveClient?.chatIcon?.value
				];
			if ( iconURL ) {
				const svg = await this.fetchSVG( iconURL );
				chatOpenButton.innerHTML = svg;
				useDefaultIcon = false;
			}
		}

		if ( useDefaultIcon ) {
			chatOpenButton.appendChild( document.createTextNode( '💬' ) );
		}

		const chatOpen = this.createElement(
			'div',
			{ className: 'hyve-bar-open', id: 'hyve-open' },
			chatOpenButton
		);

		const chatCloseButton = this.createElement( 'button', {
			className: 'collapsible close',
			innerHTML:
				'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="48" height="48" aria-hidden="true" focusable="false"><path d="M12 13.06l3.712 3.713 1.061-1.06L13.061 12l3.712-3.712-1.06-1.06L12 10.938 8.288 7.227l-1.061 1.06L10.939 12l-3.712 3.712 1.06 1.061L12 13.061z"></path></svg>',
		} );

		const chatClose = this.createElement(
			'div',
			{ className: 'hyve-bar-close', id: 'hyve-close' },
			chatCloseButton
		);

		if ( window.hyveClient.colors?.icon_background ) {
			chatClose.classList.add( 'is-dark' );
		}

		if (
			! window.hyveClient.colors?.icon_background &&
			undefined !== window.hyveClient.colors?.icon_background
		) {
			chatClose.classList.add( 'is-light' );
		}

		const chatWindow = this.createElement( 'div', {
			className: 'hyve-window',
			id: 'hyve-window',
		} );

		if ( window.hyveClient.colors?.chat_background ) {
			chatWindow.classList.add( 'is-dark' );
		}

		// Create header with menu
		const chatHeader = this.createElement( 'div', {
			className: 'hyve-header',
		} );

		const menuButtonElement = this.createElement( 'button', {
			className: 'hyve-menu-button',
			id: 'hyve-menu-button',
			innerHTML:
				'<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="hyve-menu-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM12.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM18.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" /></svg>',
		} );

		const menuDropdown = this.createElement( 'div', {
			className: 'hyve-menu-dropdown',
			innerHTML: `<button id="hyve-clear-conversation" class="hyve-menu-item"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg> ${ strings.clearConversation }</button>`,
		} );

		const menuContainer = this.createElement(
			'div',
			{ className: 'hyve-menu-container' },
			menuButtonElement,
			menuDropdown
		);

		chatHeader.appendChild( menuContainer );

		const chatMessageBox = this.createElement( 'div', {
			className: 'hyve-message-box',
			id: 'hyve-message-box',
		} );

		const chatInputBox = this.createElement( 'div', {
			className: 'hyve-input-box',
		} );
		const chatWrite = this.createElement( 'div', {
			className: 'hyve-write',
		} );

		const chatInputText = this.createElement( 'input', {
			className: 'hyve-input-text',
			type: 'text',
			id: 'hyve-text-input',
			placeholder: strings.reply,
		} );

		const chatSendButton = this.createElement(
			'div',
			{
				className: 'hyve-send-button',
				id: 'hyve-send-button',
			},
			this.createElement( 'button', {
				className: 'hyve-send-message',
				innerHTML:
					'<svg viewBox="0 0 32 32" version="1.1" xmlns="http://www.w3.org/2000/svg"><path d="M31.083 16.589c0.105-0.167 0.167-0.371 0.167-0.589s-0.062-0.421-0.17-0.593l0.003 0.005c-0.030-0.051-0.059-0.094-0.091-0.135l0.002 0.003c-0.1-0.137-0.223-0.251-0.366-0.336l-0.006-0.003c-0.025-0.015-0.037-0.045-0.064-0.058l-28-14c-0.163-0.083-0.355-0.132-0.558-0.132-0.691 0-1.25 0.56-1.25 1.25 0 0.178 0.037 0.347 0.104 0.5l-0.003-0.008 5.789 13.508-5.789 13.508c-0.064 0.145-0.101 0.314-0.101 0.492 0 0.69 0.56 1.25 1.25 1.25 0 0 0 0 0.001 0h-0c0.001 0 0.002 0 0.003 0 0.203 0 0.394-0.049 0.563-0.136l-0.007 0.003 28-13.999c0.027-0.013 0.038-0.043 0.064-0.058 0.148-0.088 0.272-0.202 0.369-0.336l0.002-0.004c0.030-0.038 0.060-0.082 0.086-0.127l0.003-0.006zM4.493 4.645l20.212 10.105h-15.88zM8.825 17.25h15.88l-20.212 10.105z"></path></svg>',
			} )
		);

		chatWindow.appendChild( chatHeader );
		chatWindow.appendChild( chatMessageBox );
		chatWrite.appendChild( chatInputText );
		chatInputBox.appendChild( chatWrite );
		chatInputBox.appendChild( chatSendButton );
		chatWindow.appendChild( chatInputBox );

		const chatExists = document.querySelectorAll( '#hyve-chat' );

		if (
			true === Boolean( window?.hyveClient?.isEnabled ) ||
			0 < chatExists.length
		) {
			chatExists.forEach( ( element ) => {
				element.remove();
			} );

			document.body.appendChild( chatWindow );
			document.body.appendChild( chatOpen );
			document.body.appendChild( chatClose );

			return;
		}

		const inlineChat = document.querySelector( '#hyve-inline-chat' );

		if ( inlineChat ) {
			inlineChat.appendChild( chatWindow );
		}
	}

	async fetchSVG( url ) {
		let svgBody = '';
		try {
			const response = await fetch( url );
			if ( 200 === response.status ) {
				svgBody = await response.text();
			}
		} catch ( e ) {
		} finally {
			return svgBody;
		}
	}

	hasUserMessages() {
		return this.messages?.some( ( { sender } ) => 'user' === sender );
	}

	setMenuVisibility( isVisible ) {
		window.document
			.getElementById( 'hyve-menu-button' )
			?.classList.toggle( 'is-visible', isVisible );
	}

	addPreloaderMessage( id ) {
		const chatMessageBox = document.getElementById( 'hyve-message-box' );
		if ( ! chatMessageBox ) {
			return;
		}

		const preloaderDiv = this.createElement( 'div', {
			className: 'hyve-bot-message hyve-preloader',
			id: `hyve-message-${ id }`,
			innerHTML: `
				<span class="hyve-preloader-dots">
					<span>.</span><span>.</span><span>.</span>
				</span>
			`,
		} );
		chatMessageBox.appendChild( preloaderDiv );
		chatMessageBox.scrollTop = chatMessageBox.scrollHeight;
	}
}
export default App;
