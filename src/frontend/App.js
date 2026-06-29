// @ts-check

/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';

import { addQueryArgs } from '@wordpress/url';

const pingAudio = new Audio( window.hyveClient.audio.ping );
const { strings } = window.hyveClient;

// Default assistant avatar, reused by the header and the live preview refresh.
const ROBOT_AVATAR_SVG =
	'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 2a2 2 0 0 1 2 2v1h3a3 3 0 0 1 3 3v6a3 3 0 0 1-3 3h-3l-4 3v-3H7a3 3 0 0 1-3-3V8a3 3 0 0 1 3-3h3V4a2 2 0 0 1 2-2Z"/><circle cx="9.5" cy="11.5" r="1" fill="currentColor" stroke="none"/><circle cx="14.5" cy="11.5" r="1" fill="currentColor" stroke="none"/></svg>';

class App {
	constructor() {
		this.isInitialToggle = true;
		this.hasSuggestions = false;
		this.messages = [];
		this.threadID = null;
		this.runID = null;
		this.recordID = null;
		this.isMenuOpen = false;
		this.isInline = false;

		if ( Boolean( window.hyveClient?.canShow ) ) {
			this.initialize();
		}
	}

	initialize() {
		this.restoreStorage();
		this.renderUI();
		this.setupListeners();
		this.restoreMessages();

		// The inline block is always visible, so there's no open action to
		// trigger the greeting — show it on load instead.
		if ( this.isInline ) {
			this.maybeShowWelcome();
		}
	}

	restoreStorage() {
		// In the admin live preview we keep the test conversation ephemeral so it
		// never mixes with the visitor's real saved chat on the same origin.
		if ( this.isPreview() ) {
			return;
		}

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
		if ( this.isPreview() ) {
			return;
		}

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

	add( message, sender, id = null, sound = true ) {
		const time = new Date();

		if ( 'user' === sender ) {
			message = this.sanitize( message );
		}

		message = this.addTargetBlank( message );

		this.messages.push( { time, message, sender, id } );
		this.addMessage( time, message, sender, id, sound );

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

	setRunID( runID ) {
		this.runID = runID;
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

	async getResponse( message ) {
		try {
			const query = {
				thread_id: this.threadID,
				run_id: this.runID,
				// In preview there is no thread record, so send 0 to satisfy the
				// required param; the server skips recording for test chats.
				record_id: this.recordID ?? 0,
				message,
			};

			if ( this.isPreview() ) {
				query.is_test = 1;
			}

			const response = await apiFetch( {
				path: this.addCacheProtection(
					addQueryArgs( `${ window.hyveClient.api }/chat`, query )
				),
				headers: this.getDefaultHeaders(),
			} );

			if ( response.error ) {
				this.add( strings.tryAgain, 'bot' );
				this.removeMessage( this.runID );
				return;
			}

			if (
				'in_progress' === response.status ||
				'queued' === response.status
			) {
				setTimeout( async () => {
					await this.getResponse( message );
				}, 2000 );

				return;
			}

			this.removeMessage( this.runID );

			if ( 'completed' === response.status ) {
				this.add( response.message, 'bot' );
				this.setLoading( false );
			}

			if ( 'failed' === response.status ) {
				this.add( strings.tryAgain, 'bot' );
				this.setLoading( false );
			}
		} catch ( error ) {
			this.add( strings.tryAgain, 'bot' );
			this.setLoading( false );
		}
	}

	async sendRequest( message ) {
		this.setLoading( true );
		this.addPreloaderMessage( 'hyve-preloader' );

		// Try real streaming first; fall back to the poll flow when the host
		// buffers the response or streaming isn't available.
		if ( this.canStream() ) {
			const handled = await this.streamRequest( message );

			if ( handled ) {
				return;
			}
		}

		await this.backgroundRequest( message );
	}

	/**
	 * Whether progressive streaming should be attempted.
	 *
	 * @return {boolean} True when streaming can be attempted.
	 */
	canStream() {
		return (
			Boolean( window.hyveClient?.ajaxUrl ) &&
			Boolean( window.hyveClient?.streamNonce ) &&
			'function' === typeof window.fetch &&
			'undefined' !== typeof window.AbortController &&
			'undefined' !== typeof window.TextDecoder &&
			! this.streamRecentlyUnsupported()
		);
	}

	/**
	 * Whether streaming was marked unsupported within the last 24h.
	 *
	 * The flag is time-bounded so a one-off slow start or transient blip can't
	 * permanently disable streaming for the visitor — it is re-probed after a day.
	 *
	 * @return {boolean} True if streaming should be skipped for now.
	 */
	streamRecentlyUnsupported() {
		try {
			const value = window.localStorage.getItem(
				'hyve-stream-unsupported'
			);

			if ( ! value ) {
				return false;
			}

			const ts = parseInt( value, 10 );
			const DAY = 24 * 60 * 60 * 1000;

			if ( isNaN( ts ) || Date.now() - ts > DAY ) {
				window.localStorage.removeItem( 'hyve-stream-unsupported' );
				return false;
			}

			return true;
		} catch ( error ) {
			return false;
		}
	}

	/**
	 * Remember (with a timestamp) that streaming is unavailable on this host so
	 * later messages skip straight to the poll flow, re-probing after 24h.
	 */
	markStreamUnsupported() {
		try {
			window.localStorage.setItem(
				'hyve-stream-unsupported',
				String( Date.now() )
			);
		} catch ( error ) {}
	}

	/**
	 * Parse a single SSE event block into its event name and JSON payload.
	 *
	 * @param {string} raw The raw event block (lines between blank lines).
	 * @return {{event:string,data:any}} Parsed event.
	 */
	parseSSE( raw ) {
		let event = '';
		const dataLines = [];

		raw.split( '\n' ).forEach( ( line ) => {
			if ( line.startsWith( 'event:' ) ) {
				event = line.slice( 6 ).trim();
			} else if ( line.startsWith( 'data:' ) ) {
				dataLines.push( line.slice( 5 ).replace( /^ /, '' ) );
			}
		} );

		let data = null;

		if ( dataLines.length ) {
			try {
				data = JSON.parse( dataLines.join( '\n' ) );
			} catch ( error ) {
				data = null;
			}
		}

		return { event, data };
	}

	/**
	 * Attempt a streamed reply.
	 *
	 * @param {string} message The user's message.
	 * @return {Promise<boolean>} True if the reply was handled (shown or a real
	 *                            error surfaced); false to fall back to polling.
	 */
	async streamRequest( message ) {
		let token;

		try {
			const setup = await apiFetch( {
				path: this.addCacheProtection(
					`${ window.hyveClient.api }/chat`
				),
				method: 'POST',
				data: {
					message,
					mode: 'stream',
					...( null !== this.threadID
						? { thread_id: this.threadID }
						: {} ),
					...( null !== this.recordID
						? { record_id: this.recordID }
						: {} ),
					...( this.isPreview() ? { is_test: true } : {} ),
				},
				headers: this.getDefaultHeaders(),
			} );

			if ( setup.error ) {
				// A real content/server error (e.g. flagged) — not a transport
				// problem, so surface it instead of falling back.
				this.removeMessage( 'hyve-preloader' );
				this.add( strings.tryAgain, 'bot' );
				this.setLoading( false );
				return true;
			}

			if ( ! setup.stream_token ) {
				return false;
			}

			token = setup.stream_token;

			if ( setup.thread_id && setup.thread_id !== this.threadID ) {
				this.setThreadID( setup.thread_id );
			}

			if (
				undefined !== setup.record_id &&
				setup.record_id !== this.recordID
			) {
				this.setRecordID( setup.record_id );
			}
		} catch ( error ) {
			return false;
		}

		const url = addQueryArgs( window.hyveClient.ajaxUrl, {
			action: 'hyve_stream',
			token,
			nonce: window.hyveClient.streamNonce,
		} );

		const controller = new AbortController();
		const bubbleId = 'hyve-stream';

		let firstEvent = false;
		let timedOut = false;
		let started = false;
		let streamedText = '';

		// Fall back if no real SSE event (delta/done/error) arrives in time. The
		// ': connected' comment is deliberately NOT counted as content, so a proxy
		// that forwards the comment but buffers the body is still detected. 8s is
		// lenient enough for a slow first token; a false trip self-heals after 24h.
		const watchdog = setTimeout( () => {
			if ( ! firstEvent ) {
				timedOut = true;
				controller.abort();
			}
		}, 8000 );

		const renderInto = ( html ) => {
			const node = document.getElementById(
				`hyve-message-${ bubbleId }`
			);

			if ( ! node ) {
				return;
			}

			const inner = node.querySelector( 'div' );

			if ( inner ) {
				inner.innerHTML = html;
			}

			const box = document.getElementById( 'hyve-message-box' );

			if ( box ) {
				box.scrollTop = box.scrollHeight;
			}
		};

		const ensureBubble = () => {
			if ( started ) {
				return;
			}

			this.removeMessage( 'hyve-preloader' );
			this.addMessage( new Date(), '', 'bot', bubbleId, false );
			started = true;
		};

		try {
			const response = await fetch( url, {
				headers: { Accept: 'text/event-stream' },
				credentials: 'same-origin',
				signal: controller.signal,
			} );

			if ( ! response.ok || ! response.body ) {
				clearTimeout( watchdog );
				return false;
			}

			const reader = response.body.getReader();
			const decoder = new TextDecoder();

			let buffer = '';
			let finished = false;
			let reading = true;

			while ( reading ) {
				const { value, done } = await reader.read();

				if ( done ) {
					break;
				}

				buffer += decoder.decode( value, { stream: true } );

				let sep = buffer.indexOf( '\n\n' );

				while ( -1 !== sep ) {
					const rawEvent = buffer.slice( 0, sep );
					buffer = buffer.slice( sep + 2 );
					sep = buffer.indexOf( '\n\n' );

					const { event, data } = this.parseSSE( rawEvent );

					if ( ! event ) {
						continue;
					}

					// First real content event: the pipe is flushing, cancel the
					// fallback watchdog.
					if ( ! firstEvent ) {
						firstEvent = true;
						clearTimeout( watchdog );
					}

					if ( 'delta' === event ) {
						ensureBubble();
						streamedText += data?.text || '';
						renderInto( streamedText );
					} else if ( 'done' === event ) {
						this.finalizeStream( bubbleId, data );
						finished = true;
						reading = false;
						break;
					} else if ( 'error' === event ) {
						// Generation failed and the server recorded nothing.
						// Discard any partial and fall back to the poll flow for a
						// complete, recorded-once answer.
						clearTimeout( watchdog );
						this.removeMessage( bubbleId );
						this.removeMessage( 'hyve-preloader' );
						this.addPreloaderMessage( 'hyve-preloader' );
						return false;
					}
				}
			}

			clearTimeout( watchdog );

			if ( finished ) {
				return true;
			}

			// Stream ended without a terminal event.
			if ( started ) {
				this.finalizeStream( bubbleId, {
					success: true,
					message: streamedText,
				} );
				return true;
			}

			return false;
		} catch ( error ) {
			clearTimeout( watchdog );

			if ( started ) {
				this.finalizeStream( bubbleId, {
					success: true,
					message: streamedText,
				} );
				return true;
			}

			if ( timedOut ) {
				this.markStreamUnsupported();
			}

			return false;
		}
	}

	/**
	 * Replace the streaming placeholder with the final, authoritative reply.
	 *
	 * @param {string} bubbleId The temporary streaming bubble id.
	 * @param {any}    data     The `done` payload ({ success, message }).
	 */
	finalizeStream( bubbleId, data ) {
		this.removeMessage( 'hyve-preloader' );
		this.removeMessage( bubbleId );

		// The streamed turn is recorded server-side only once the reply lands,
		// so adopt the returned record id to keep follow-ups on the same thread.
		if (
			undefined !== data?.record_id &&
			null !== data?.record_id &&
			data.record_id !== this.recordID
		) {
			this.setRecordID( data.record_id );
		}

		const message = data?.message ?? strings.tryAgain;
		this.add( message, 'bot' );
		this.setLoading( false );
	}

	/**
	 * Background reply flow: create a run and poll for the answer. This is the
	 * original, proven path, used as the fallback when streaming is unavailable.
	 *
	 * @param {string} message The user's message.
	 */
	async backgroundRequest( message ) {
		try {
			const response = await apiFetch( {
				path: this.addCacheProtection(
					`${ window.hyveClient.api }/chat`
				),
				method: 'POST',
				data: {
					message,
					...( null !== this.threadID
						? { thread_id: this.threadID }
						: {} ),
					...( null !== this.recordID
						? { record_id: this.recordID }
						: {} ),
					...( this.isPreview() ? { is_test: true } : {} ),
				},
				headers: this.getDefaultHeaders(),
			} );

			this.removeMessage( 'hyve-preloader' );

			if ( response.error ) {
				this.add( strings.tryAgain, 'bot' );
				this.setLoading( false );
				return;
			}

			if ( response.thread_id !== this.threadID ) {
				this.setThreadID( response.thread_id );
			}

			if ( response.record_id !== this.recordID ) {
				this.setRecordID( response.record_id );
			}

			this.setRunID( response.query_run );

			// Keep the animated typing indicator (not a plain "Typing…" text)
			// visible while we poll for the answer.
			this.addPreloaderMessage( response.query_run );

			await this.getResponse( message );
		} catch ( error ) {
			this.removeMessage( 'hyve-preloader' );
			this.add( strings.tryAgain, 'bot' );
			this.setLoading( false );
		}
	}

	addAudioPlayback( audioElement ) {
		if ( ! this.isSoundEnabled() ) {
			return;
		}

		audioElement.play();
	}

	/**
	 * Whether the visitor has muted the chat sound in this browser.
	 *
	 * @return {boolean} True if muted.
	 */
	isMuted() {
		return 'true' === window.localStorage.getItem( 'hyve-sound-muted' );
	}

	/**
	 * Whether sound is enabled globally (by the site admin).
	 *
	 * `wp_localize_script` casts booleans to strings ('1' for true, '' for
	 * false), so we treat an empty string or explicit false as disabled and a
	 * missing value as enabled.
	 *
	 * @return {boolean} True if sound is allowed site-wide.
	 */
	isGlobalSoundEnabled() {
		const value = window.hyveClient?.soundEnabled;
		return false !== value && '' !== value;
	}

	/**
	 * Whether sound should play — enabled globally and not muted by the visitor.
	 *
	 * @return {boolean} True if sound should play.
	 */
	isSoundEnabled() {
		if ( ! this.isGlobalSoundEnabled() ) {
			return false;
		}

		return ! this.isMuted();
	}

	/**
	 * Whether message timestamps should be shown.
	 *
	 * `wp_localize_script` casts booleans to strings ('1' / ''), so an empty
	 * string or explicit false hides the timestamp; a missing value shows it.
	 *
	 * @return {boolean} True if timestamps should render.
	 */
	isTimestampVisible() {
		const value = window.hyveClient?.showTimestamp;
		return false !== value && '' !== value;
	}

	/**
	 * Whether the widget is running inside the admin Appearance live preview.
	 *
	 * @return {boolean} True in preview mode.
	 */
	isPreview() {
		return Boolean( window.hyveClient?.isPreview );
	}

	/**
	 * Markup (icon + label) for the sound toggle menu item, reflecting state.
	 *
	 * @return {string} The inner HTML for the toggle button.
	 */
	soundMenuInner() {
		const icon = this.isMuted()
			? '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16"><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 9.75 19.5 12m0 0 2.25 2.25M19.5 12l2.25-2.25M19.5 12l-2.25 2.25M6.75 8.25l4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z" /></svg>'
			: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16"><path stroke-linecap="round" stroke-linejoin="round" d="M19.114 5.636a9 9 0 0 1 0 12.728M16.463 8.288a5.25 5.25 0 0 1 0 7.424M6.75 8.25l4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z" /></svg>';

		const label = this.isMuted() ? strings.unmuteSound : strings.muteSound;

		return `${ icon } ${ label }`;
	}

	/**
	 * Toggle the per-visitor sound preference and refresh the menu item.
	 *
	 * @return {void}
	 */
	toggleSound() {
		window.localStorage.setItem(
			'hyve-sound-muted',
			this.isMuted() ? 'false' : 'true'
		);

		const soundButton = document.getElementById( 'hyve-toggle-sound' );
		if ( soundButton ) {
			soundButton.innerHTML = this.soundMenuInner();
		}
	}

	addMessage( time, message, sender, id, sound = true ) {
		const chatMessageBox = document.getElementById( 'hyve-message-box' );
		if ( ! chatMessageBox ) {
			return;
		}

		const date = this.formatDate( time );

		let messageHTML = `<div>${ message }</div>`;

		if ( null === id && this.isTimestampVisible() ) {
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
			chatWindow.style.display = 'flex';

			// Trigger the entrance animation on the next frame.
			window.requestAnimationFrame( () => {
				chatWindow.classList.add( 'is-open' );
			} );

			const chatMessageBox =
				document.getElementById( 'hyve-message-box' );
			if ( chatMessageBox ) {
				chatMessageBox.scrollTop = chatMessageBox.scrollHeight;
			}
		} else {
			openButton.style.display = 'block';
			closeButton.style.display = 'none';
			chatWindow.style.display = 'none';
			chatWindow.classList.remove( 'is-open' );
		}

		this.maybeShowWelcome();
	}

	/**
	 * Show the welcome message and suggested questions once, when the chat is
	 * first shown with no prior conversation.
	 *
	 * @return {void}
	 */
	maybeShowWelcome() {
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
		// Clear messages from UI
		const chatMessageBox = document.getElementById( 'hyve-message-box' );
		chatMessageBox.innerHTML = '';

		// Reset state
		this.messages = [];
		this.threadID = null;
		this.runID = null;
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

		const soundButton = document.getElementById( 'hyve-toggle-sound' );
		if ( soundButton ) {
			soundButton.addEventListener( 'click', () => {
				this.toggleSound();
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

	getDefaultHeaders() {
		return {
			'Cache-Control': 'no-cache',
		};
	}

	renderUI() {
		// Whether the widget is anchored to the left side of the screen.
		const isLeft = 'left' === window.hyveClient?.chatPosition;

		const chatOpenButton = this.createElement( 'button', {
			className: 'collapsible open',
			ariaLabel: strings.openChat,
			ariaExpanded: 'true',
		} );

		const chatIcon = window.hyveClient?.chatIcon;
		let useDefaultIcon = true;

		if ( 'media' === chatIcon?.type && chatIcon?.url ) {
			chatOpenButton.appendChild(
				this.createElement( 'img', {
					className: 'hyve-icon-img',
					src: chatIcon.url,
					alt: '',
				} )
			);
			useDefaultIcon = false;
		} else if ( 'svg' === chatIcon?.type ) {
			// SVG markup is inlined server-side so it renders instantly (no fetch).
			const iconMarkup = window.hyveClient?.icons?.[ chatIcon?.value ];
			if ( iconMarkup ) {
				chatOpenButton.innerHTML = iconMarkup;
				useDefaultIcon = false;
			}
		}

		if ( useDefaultIcon ) {
			// Default icon: the bundled chat-bubble SVG (matches the option
			// shown as the default in the admin). Emoji is a last-resort fallback.
			const defaultIcon =
				window.hyveClient?.icons?.[ 'chat-bubble-left-ellipsis' ];

			if ( defaultIcon ) {
				chatOpenButton.innerHTML = defaultIcon;
			} else {
				chatOpenButton.appendChild( document.createTextNode( '💬' ) );
			}
		}

		const chatOpen = this.createElement(
			'div',
			{ className: 'hyve-bar-open', id: 'hyve-open' },
			chatOpenButton
		);

		if ( isLeft ) {
			chatOpen.classList.add( 'is-left' );
		}

		// Adapt the open icon color to the icon background, like the close icon.
		if ( window.hyveClient.colors?.icon_background ) {
			chatOpen.classList.add( 'is-dark' );
		}

		if (
			! window.hyveClient.colors?.icon_background &&
			undefined !== window.hyveClient.colors?.icon_background
		) {
			chatOpen.classList.add( 'is-light' );
		}

		const chatCloseButton = this.createElement( 'button', {
			className: 'collapsible close',
			innerHTML:
				'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="48" height="48" aria-hidden="true" focusable="false"><path d="M12 13.06l3.712 3.713 1.061-1.06L13.061 12l3.712-3.712-1.06-1.06L12 10.938 8.288 7.227l-1.061 1.06L10.939 12l-3.712 3.712 1.06 1.061L12 13.061z"></path></svg>',
			ariaLabel: strings.closeChat,
			ariaExpanded: 'true',
		} );

		const chatClose = this.createElement(
			'div',
			{ className: 'hyve-bar-close', id: 'hyve-close' },
			chatCloseButton
		);

		if ( isLeft ) {
			chatClose.classList.add( 'is-left' );
		}

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

		if ( isLeft ) {
			chatWindow.classList.add( 'is-left' );
		}

		if ( window.hyveClient.colors?.chat_background ) {
			chatWindow.classList.add( 'is-dark' );
		}

		// Create header with assistant info and menu
		const chatHeader = this.createElement( 'div', {
			className: 'hyve-header',
		} );

		const headerAvatar = this.createElement( 'div', {
			className: 'hyve-avatar',
		} );

		if ( 'media' === chatIcon?.type && chatIcon?.url ) {
			headerAvatar.appendChild(
				this.createElement( 'img', {
					className: 'hyve-avatar-img',
					src: chatIcon.url,
					alt: '',
				} )
			);
		} else {
			headerAvatar.innerHTML = ROBOT_AVATAR_SVG;
		}

		const headerTitle = window.hyveClient.chatName?.trim()
			? window.hyveClient.chatName.trim()
			: strings.title ?? '';

		const headerText = this.createElement( 'div', {
			className: 'hyve-header-text',
			innerHTML: `<span class="hyve-title">${ this.sanitize(
				headerTitle
			) }</span><span class="hyve-status"><span class="hyve-status-dot"></span>${
				strings.status ?? ''
			}</span>`,
		} );

		const headerInfo = this.createElement(
			'div',
			{ className: 'hyve-header-info' },
			headerAvatar,
			headerText
		);

		chatHeader.appendChild( headerInfo );

		const menuButtonElement = this.createElement( 'button', {
			className: 'hyve-menu-button',
			id: 'hyve-menu-button',
			innerHTML:
				'<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="hyve-menu-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM12.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM18.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" /></svg>',
		} );

		const clearItem = `<button id="hyve-clear-conversation" class="hyve-menu-item"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg> ${ strings.clearConversation }</button>`;

		// Per-visitor sound toggle — only offered when sound is globally enabled.
		const soundItem = this.isGlobalSoundEnabled()
			? `<button id="hyve-toggle-sound" class="hyve-menu-item">${ this.soundMenuInner() }</button>`
			: '';

		const menuDropdown = this.createElement( 'div', {
			className: 'hyve-menu-dropdown',
			innerHTML: soundItem + clearItem,
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
				ariaLabel: strings.sendMessage,
			} )
		);

		chatWindow.appendChild( chatHeader );

		// In the dashboard test preview, make it obvious this chat is for trying
		// the assistant and isn't a live/recorded conversation.
		if ( this.isPreview() && strings.previewNotice ) {
			const previewNotice = this.createElement( 'div', {
				className: 'hyve-preview-notice',
				innerHTML: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="15" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg><span>${ this.sanitize(
					strings.previewNotice
				) }</span>`,
			} );

			chatWindow.appendChild( previewNotice );
		}

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
			this.isInline = true;
			inlineChat.appendChild( chatWindow );
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

		// Match the bot message contrast so the dots stay visible on a dark
		// assistant background.
		if ( window.hyveClient.colors?.assistant_background ) {
			preloaderDiv.classList.add( 'is-dark' );
		}

		chatMessageBox.appendChild( preloaderDiv );
		chatMessageBox.scrollTop = chatMessageBox.scrollHeight;
	}

	/**
	 * Live-update the widget's appearance from the admin Appearance panel,
	 * without tearing down the current conversation. Only used in preview mode.
	 *
	 * @param {Object}  config                 Appearance values.
	 * @param {string}  [config.chatName]      Header title.
	 * @param {Object}  [config.colors]        Hex color values keyed by slug.
	 * @param {Object}  [config.colorsDark]    is-dark booleans keyed by slug.
	 * @param {Object}  [config.chatIcon]      Icon descriptor: { type, value, url }.
	 * @param {string}  [config.chatPosition]  Screen side: 'left' or 'right'.
	 * @param {boolean} [config.showTimestamp] Whether message timestamps show.
	 *
	 * @return {void}
	 */
	applyPreviewAppearance( config = {} ) {
		const {
			chatName,
			colors,
			colorsDark,
			chatIcon,
			chatPosition,
			showTimestamp,
		} = config;

		// Hex values drive the CSS custom properties the stylesheet reads.
		if ( colors ) {
			Object.entries( colors ).forEach( ( [ key, value ] ) => {
				if ( value ) {
					document.body.style.setProperty( `--${ key }`, value );
				}
			} );
		}

		// Booleans drive the is-dark/is-light contrast classes.
		if ( colorsDark ) {
			window.hyveClient.colors = { ...colorsDark };
			this.refreshColorClasses();
		}

		if ( undefined !== chatName ) {
			window.hyveClient.chatName = chatName;
			const title = document.querySelector( '#hyve-window .hyve-title' );
			if ( title ) {
				title.textContent = chatName.trim()
					? chatName.trim()
					: strings.title ?? '';
			}
		}

		if ( chatIcon ) {
			window.hyveClient.chatIcon = chatIcon;
			this.refreshIcons();
		}

		if ( undefined !== chatPosition ) {
			window.hyveClient.chatPosition = chatPosition;
			const isLeft = 'left' === chatPosition;
			[ 'hyve-window', 'hyve-open', 'hyve-close' ].forEach( ( id ) => {
				document
					.getElementById( id )
					?.classList.toggle( 'is-left', isLeft );
			} );
		}

		if ( undefined !== showTimestamp ) {
			window.hyveClient.showTimestamp = showTimestamp;
			// Toggle any already-rendered timestamps; new messages read the flag.
			document
				.querySelectorAll( '#hyve-window time' )
				.forEach( ( element ) => {
					element.style.display = showTimestamp ? '' : 'none';
				} );
		}
	}

	/**
	 * Re-apply the is-dark/is-light contrast classes to the rendered widget
	 * from the cached color booleans. Mirrors the logic in renderUI/addMessage.
	 *
	 * @return {void}
	 */
	refreshColorClasses() {
		const colors = window.hyveClient.colors || {};

		const setClasses = ( element, isDark, useLight ) => {
			if ( ! element ) {
				return;
			}
			element.classList.toggle( 'is-dark', Boolean( isDark ) );
			if ( useLight ) {
				element.classList.toggle( 'is-light', ! isDark );
			}
		};

		setClasses(
			document.getElementById( 'hyve-window' ),
			colors.chat_background,
			false
		);

		document
			.querySelectorAll( '.hyve-bot-message' )
			.forEach( ( element ) =>
				setClasses( element, colors.assistant_background, false )
			);

		document
			.querySelectorAll( '.hyve-user-message, .hyve-suggestions' )
			.forEach( ( element ) =>
				setClasses( element, colors.user_background, true )
			);

		setClasses(
			document.getElementById( 'hyve-open' ),
			colors.icon_background,
			true
		);
		setClasses(
			document.getElementById( 'hyve-close' ),
			colors.icon_background,
			true
		);
	}

	/**
	 * Rebuild the launcher icon and header avatar from the current chat icon.
	 * Mirrors the icon precedence used in renderUI.
	 *
	 * @return {void}
	 */
	refreshIcons() {
		const chatIcon = window.hyveClient?.chatIcon || {};
		const isMedia = 'media' === chatIcon.type && chatIcon.url;

		const renderInto = ( element, mediaClass, fallback ) => {
			if ( ! element ) {
				return;
			}

			if ( isMedia ) {
				element.innerHTML = '';
				element.appendChild(
					this.createElement( 'img', {
						className: mediaClass,
						src: chatIcon.url,
						alt: '',
					} )
				);
				return;
			}

			element.innerHTML = fallback();
		};

		// Launcher button: custom image, selected built-in SVG, or the default.
		renderInto(
			document.querySelector( '#hyve-open .collapsible.open' ),
			'hyve-icon-img',
			() => {
				if (
					'svg' === chatIcon.type &&
					window.hyveClient?.icons?.[ chatIcon.value ]
				) {
					return window.hyveClient.icons[ chatIcon.value ];
				}

				return (
					window.hyveClient?.icons?.[ 'chat-bubble-left-ellipsis' ] ||
					'💬'
				);
			}
		);

		// Header avatar: custom image or the default robot.
		renderInto(
			document.querySelector( '#hyve-window .hyve-avatar' ),
			'hyve-avatar-img',
			() => ROBOT_AVATAR_SVG
		);
	}
}

export default App;
