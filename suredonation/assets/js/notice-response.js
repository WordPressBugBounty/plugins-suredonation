/* global suredonationNoticeResponse */
( function () {
	const notices = {
		'sd-review-donation': {
			primary: 'rate_suredonation',
			snooze: 'maybe_later',
			dismiss: 'dismissed',
		},
		'sd-review-gateway': {
			primary: 'rate_suredonation',
			snooze: 'maybe_later',
			dismiss: 'dismissed',
		},
		'sd-setup-gateway': {
			primary: 'configure_gateway',
			snooze: 'maybe_later',
			dismiss: 'dismissed',
		},
	};

	function getAction( el, noticeId ) {
		const config = notices[ noticeId ];
		if ( ! config ) {
			return null;
		}

		if (
			el.classList.contains( 'button-primary' ) ||
			( el.classList.contains( 'astra-notice-close' ) &&
				el.getAttribute( 'target' ) === '_blank' )
		) {
			return config.primary;
		}
		if ( el.hasAttribute( 'data-repeat-notice-after' ) ) {
			return config.snooze;
		}
		if ( el.classList.contains( 'astra-notice-close' ) ) {
			return config.dismiss;
		}
		return null;
	}

	function sendResponse( noticeId, button ) {
		const body = new FormData();
		body.append( 'action', 'suredonation_notice_response' );
		body.append( 'nonce', suredonationNoticeResponse.nonce );
		body.append( 'notice_id', noticeId );
		body.append( 'button', button );

		fetch( suredonationNoticeResponse.ajaxurl, {
			method: 'POST',
			body,
		} ).catch( () => {} );
	}

	Object.keys( notices ).forEach( function ( noticeId ) {
		const container = document.getElementById( noticeId );
		if ( ! container ) {
			return;
		}

		container.addEventListener( 'click', function ( e ) {
			// WordPress core dismiss button (the top-right "x").
			if ( e.target.closest( 'button.notice-dismiss' ) ) {
				sendResponse( noticeId, notices[ noticeId ].dismiss );
				return;
			}

			const link = e.target.closest( 'a' );
			if ( ! link ) {
				return;
			}

			const action = getAction( link, noticeId );
			if ( action ) {
				sendResponse( noticeId, action );
			}
		} );
	} );
}() );
