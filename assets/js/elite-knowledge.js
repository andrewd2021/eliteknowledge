/**
 * Elite Knowledge — front-end interactions.
 *
 * RECONSTRUCTED FILE — the original was lost alongside the rest of this
 * plugin (see docs/testing-guide.html troubleshooting notes / project
 * history). Every hook below is rebuilt directly from the DOM structure,
 * data-attributes, and AJAX action names the PHP side (class-ek-forum.php,
 * class-ek-subscriptions.php, class-ek-faq.php, class-ek-shortcodes.php)
 * already defines and expects — those files survived intact, so this is a
 * faithful rebuild of the wiring even though the original script itself
 * wasn't recovered.
 */
( function () {
	'use strict';

	if ( typeof window.ekForum === 'undefined' ) {
		return;
	}

	var AJAX_URL = window.ekForum.ajaxUrl;
	var NONCES   = window.ekForum.nonces || {};

	// Network/parse failures (offline, timeout, a non-JSON error page from
	// the server) used to reject silently — the caller's .then() never ran,
	// so a disabled button stayed disabled forever with no feedback. Every
	// call site now gets a rejected promise it can catch instead.
	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', NONCES[ action ] || '' );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return fetch( AJAX_URL, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) { return response.json(); } )
			.catch( function () {
				return { success: false, data: { message: 'Network error. Please check your connection and try again.' } };
			} );
	}

	function onClick( selector, handler ) {
		document.addEventListener( 'click', function ( e ) {
			var el = e.target.closest( selector );
			if ( el ) {
				handler( el, e );
			}
		} );
	}

	/* ---------------------------------------------------------- moderator toggles */

	function toggleDiscussionMeta( action, button ) {
		var discussionId = button.getAttribute( 'data-discussion-id' );
		button.disabled = true;
		// A full reload is simplest here: pin/close/solved each affect more
		// than just this button (forum listing order, badges, the reply
		// form's visibility for "closed") — cheaper to let the server
		// re-render all of that than to duplicate its logic in JS.
		post( action, { discussion_id: discussionId } ).then( function ( res ) {
			if ( ! res.success ) {
				button.disabled = false;
				window.alert( res.data && res.data.message ? res.data.message : 'Something went wrong.' );
				return;
			}
			window.location.reload();
		} );
	}

	onClick( '.ek-toggle-pin', function ( el ) { toggleDiscussionMeta( 'ek_toggle_pin', el ); } );
	onClick( '.ek-toggle-close', function ( el ) { toggleDiscussionMeta( 'ek_toggle_close', el ); } );
	onClick( '.ek-toggle-solved', function ( el ) { toggleDiscussionMeta( 'ek_toggle_solved', el ); } );

	onClick( '.ek-mark-best-answer', function ( el ) {
		var commentId = el.getAttribute( 'data-comment-id' );
		el.disabled = true;
		post( 'ek_mark_best_answer', { comment_id: commentId } ).then( function ( res ) {
			el.disabled = false;
			if ( ! res.success ) {
				window.alert( res.data && res.data.message ? res.data.message : 'Something went wrong.' );
				return;
			}
			window.location.reload();
		} );
	} );

	/* ---------------------------------------------------------- reply edit / delete / flag */

	onClick( '.ek-edit-reply', function ( el ) {
		var id = el.getAttribute( 'data-comment-id' );
		var content = document.getElementById( 'ek-reply-content-' + id );
		var form    = document.getElementById( 'ek-reply-edit-' + id );
		if ( content ) { content.hidden = true; }
		if ( form ) { form.hidden = false; }
	} );

	onClick( '.ek-cancel-reply-edit', function ( el ) {
		var id = el.getAttribute( 'data-comment-id' );
		var content = document.getElementById( 'ek-reply-content-' + id );
		var form    = document.getElementById( 'ek-reply-edit-' + id );
		if ( content ) { content.hidden = false; }
		if ( form ) { form.hidden = true; }
	} );

	onClick( '.ek-save-reply-edit', function ( el ) {
		var id       = el.getAttribute( 'data-comment-id' );
		var form     = document.getElementById( 'ek-reply-edit-' + id );
		var textarea = form ? form.querySelector( 'textarea' ) : null;
		if ( ! textarea ) {
			return;
		}
		el.disabled = true;
		post( 'ek_update_reply', { comment_id: id, content: textarea.value } ).then( function ( res ) {
			el.disabled = false;
			if ( ! res.success ) {
				window.alert( res.data && res.data.message ? res.data.message : 'Something went wrong.' );
				return;
			}
			var content = document.getElementById( 'ek-reply-content-' + id );
			var edited  = document.getElementById( 'ek-reply-edited-' + id );
			if ( content ) {
				content.innerHTML = res.data.html;
				content.hidden = false;
			}
			if ( edited ) {
				edited.textContent = res.data.edited;
				edited.hidden = ! res.data.edited;
			}
			form.hidden = true;
		} );
	} );

	onClick( '.ek-delete-reply', function ( el ) {
		var confirmMsg = el.getAttribute( 'data-confirm' );
		if ( confirmMsg && ! window.confirm( confirmMsg ) ) {
			return;
		}
		var id = el.getAttribute( 'data-comment-id' );
		el.disabled = true;
		post( 'ek_delete_reply', { comment_id: id } ).then( function ( res ) {
			if ( ! res.success ) {
				el.disabled = false;
				window.alert( res.data && res.data.message ? res.data.message : 'Something went wrong.' );
				return;
			}
			var li = el.closest( 'li.ek-reply' );
			if ( li ) {
				li.remove();
			} else {
				window.location.reload();
			}
		} );
	} );

	onClick( '.ek-flag-reply', function ( el ) {
		if ( el.disabled ) {
			return;
		}
		var id = el.getAttribute( 'data-comment-id' );
		el.disabled = true;
		post( 'ek_flag_reply', { comment_id: id } ).then( function ( res ) {
			if ( ! res.success ) {
				el.disabled = false;
				window.alert( res.data && res.data.message ? res.data.message : 'Something went wrong.' );
				return;
			}
			el.textContent = 'Reported';
		} );
	} );

	/* ---------------------------------------------------------- discussion delete confirm */

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target.closest( '.ek-owner-delete-form' );
		if ( ! form ) {
			return;
		}
		var button = form.querySelector( '.ek-owner-delete' );
		var confirmMsg = button ? button.getAttribute( 'data-confirm' ) : '';
		if ( confirmMsg && ! window.confirm( confirmMsg ) ) {
			e.preventDefault();
		}
	} );

	/* ---------------------------------------------------------- subscriptions */

	onClick( '.ek-toggle-subscription', function ( el ) {
		var discussionId = el.getAttribute( 'data-discussion-id' );
		el.disabled = true;
		post( 'ek_toggle_subscription', { discussion_id: discussionId } ).then( function ( res ) {
			el.disabled = false;
			if ( ! res.success ) {
				window.alert( res.data && res.data.message ? res.data.message : 'Something went wrong.' );
				return;
			}
			el.setAttribute( 'data-state', res.data.subscribed ? 'on' : 'off' );
			el.textContent = res.data.subscribed ? 'Unsubscribe' : 'Subscribe to replies';
		} );
	} );

	/* ---------------------------------------------------------- FAQ voting */

	onClick( '.ek-faq-vote', function ( el ) {
		var wrap  = el.closest( '.ek-faq-feedback' );
		var faqId = wrap ? wrap.getAttribute( 'data-faq-id' ) : '';
		var vote  = el.getAttribute( 'data-vote' );
		if ( ! wrap || ! faqId ) {
			return;
		}
		wrap.querySelectorAll( '.ek-faq-vote' ).forEach( function ( btn ) { btn.disabled = true; } );
		post( 'ek_faq_feedback', { faq_id: faqId, vote: vote } ).then( function ( res ) {
			if ( ! res.success ) {
				wrap.querySelectorAll( '.ek-faq-vote' ).forEach( function ( btn ) { btn.disabled = false; } );
				window.alert( res.data && res.data.message ? res.data.message : 'Something went wrong.' );
				return;
			}
			wrap.textContent = 'You already voted on this — ' + res.data.yes + ' of ' + res.data.total + ' people found it helpful.';
		} );
	} );

	/* ---------------------------------------------------------- FAQ accordion */

	onClick( '.ek-faq-question', function ( button ) {
		var item   = button.closest( '.ek-faq-item' );
		var answer = item ? item.querySelector( '.ek-faq-answer' ) : null;
		if ( ! answer ) {
			return;
		}
		var expanded = 'true' === button.getAttribute( 'aria-expanded' );
		button.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
		answer.setAttribute( 'aria-hidden', expanded ? 'true' : 'false' );
		if ( expanded ) {
			answer.setAttribute( 'inert', '' );
		} else {
			answer.removeAttribute( 'inert' );
		}
	} );

	/* ---------------------------------------------------------- new-discussion form toggle */

	onClick( '.ek-toggle-new-discussion', function ( button ) {
		var toolbar = button.closest( '.ek-discussions-toolbar' );
		var target  = toolbar ? toolbar.nextElementSibling : null;
		if ( ! target ) {
			return;
		}
		var expanded = 'true' === button.getAttribute( 'aria-expanded' );
		button.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
		target.hidden = expanded;
		button.textContent = expanded
			? button.getAttribute( 'data-label-open' )
			: button.getAttribute( 'data-label-close' );
		if ( ! expanded ) {
			var titleInput = target.querySelector( '#ek_title' );
			if ( titleInput ) {
				titleInput.focus();
			}
		}
	} );

	/* ---------------------------------------------------------- discussion "Manage" dropdown */

	onClick( '.ek-dropdown-toggle', function ( button, e ) {
		e.stopPropagation();
		var menu = button.nextElementSibling;
		var expanded = 'true' === button.getAttribute( 'aria-expanded' );
		button.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
		if ( menu ) {
			menu.hidden = expanded;
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '.ek-dropdown' ) ) {
			return;
		}
		document.querySelectorAll( '.ek-dropdown-toggle[aria-expanded="true"]' ).forEach( function ( button ) {
			button.setAttribute( 'aria-expanded', 'false' );
			var menu = button.nextElementSibling;
			if ( menu ) {
				menu.hidden = true;
			}
		} );
	} );

	/* ---------------------------------------------------------- threaded replies */

	// wp_list_comments() nests reply-to-reply threads in <ol class="children">
	// (WordPress core's own markup), not a <ul> — collapse those on load
	// so the "View N replies" toggle below has something to reveal.
	// Progressive enhancement: if this script fails to run, threads stay
	// fully expanded rather than stuck hidden.
	document.querySelectorAll( '.ek-replies-list li.ek-reply > ol.children, .ek-replies-list li.ek-reply > ul' ).forEach( function ( list ) {
		list.hidden = true;
	} );

	function threadChildren( button ) {
		var li = button.closest( 'li.ek-reply' );
		return li ? li.querySelector( ':scope > ol.children, :scope > ul' ) : null;
	}

	onClick( '.ek-toggle-thread', function ( button ) {
		var children = threadChildren( button );
		var expanded = 'true' === button.getAttribute( 'aria-expanded' );
		button.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
		button.textContent = expanded
			? button.getAttribute( 'data-show-text' )
			: button.getAttribute( 'data-hide-text' );
		if ( children ) {
			children.hidden = expanded;
		}
	} );

	/* ---------------------------------------------------------- reply quote preview */

	onClick( '.comment-reply-link', function ( link ) {
		var preview = document.getElementById( 'ek-reply-quote-preview' );
		if ( ! preview ) {
			return;
		}
		var li = link.closest( 'li.ek-reply' );
		var body = li ? li.querySelector( '.ek-reply-content' ) : null;
		var author = li ? li.querySelector( '.ek-reply-author' ) : null;
		if ( ! body ) {
			preview.innerHTML = '';
			return;
		}
		var snippet = body.textContent.trim();
		if ( snippet.length > 140 ) {
			snippet = snippet.slice( 0, 140 ) + '…';
		}
		preview.innerHTML = '';
		var quote = document.createElement( 'blockquote' );
		quote.className = 'ek-reply-quote';
		var cite = document.createElement( 'cite' );
		cite.textContent = author ? author.textContent : '';
		quote.appendChild( cite );
		quote.appendChild( document.createTextNode( snippet ) );
		preview.appendChild( quote );
	} );

	/* ---------------------------------------------------------- image upload preview */

	// The bare <input type="file"> in EK_Forum::render_image_field() gives
	// no confirmation a selection actually registered beyond whatever the
	// browser's own (often easy-to-miss) native filename text shows —
	// surface a thumbnail + filename explicitly once a file is chosen.
	document.addEventListener( 'change', function ( e ) {
		var input = e.target.closest( '.ek-image-input' );
		if ( ! input ) {
			return;
		}
		var wrap    = input.closest( '.ek-image-field' );
		var preview = wrap ? wrap.querySelector( '.ek-image-preview' ) : null;
		if ( ! preview ) {
			return;
		}

		var file = input.files && input.files[0];
		if ( ! file ) {
			preview.hidden = true;
			preview.innerHTML = '';
			return;
		}

		var img = document.createElement( 'img' );
		img.src = URL.createObjectURL( file );
		img.alt = '';

		var name = document.createElement( 'span' );
		name.className = 'ek-image-preview-name';
		name.textContent = file.name;

		preview.innerHTML = '';
		preview.appendChild( img );
		preview.appendChild( name );
		preview.hidden = false;
	} );
} )();
