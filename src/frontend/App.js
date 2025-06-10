// @ts-check

/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';

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
		this.runID = null;
		this.recordID = null;
		this.isMenuOpen = false;

		this.initialize();
	}

	async initialize() {
		await this.renderUI();
		this.setupListeners();
		this.restoreStorage();
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
			const response = await apiFetch( {
				path: this.addCacheProtection(
					addQueryArgs( `${ window.hyveClient.api }/chat`, {
						thread_id: this.threadID,
						run_id: this.runID,
						record_id: this.recordID,
						message,
					} )
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
		try {
			this.setLoading( true );

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
				},
				headers: this.getDefaultHeaders(),
			} );

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

			this.add( strings.typing, 'bot', response.query_run );

			await this.getResponse( message );
		} catch ( error ) {
			this.add( strings.tryAgain, 'bot' );
			this.setLoading( false );
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
			this.isInitialToggle
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

	getDefaultHeaders() {
		return {
			'Cache-Control': 'no-cache',
		};
	}

	async renderUI() {
		const chatOpenButton = this.createElement( 'button', {
			className: 'collapsible open',
		} );

		let useDefaultIcon = true;
		if ( 'svg' === window.hyve?.chatIcon?.type ) {
			/**
			 * NOTE: Download the SVG to that we can use the styling via CSS.
			 */
			const iconURL =
				window.hyve?.icons?.[ window.hyve?.chatIcon?.value ];
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
			'dv',
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
}

export default App;
