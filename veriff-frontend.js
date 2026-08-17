/* global CiviVeriffConfig, jQuery */
( function ( $ ) {
	'use strict';

	if ( typeof CiviVeriffConfig === 'undefined' ) {
		return;
	}

	var cfg = CiviVeriffConfig;
	var pollTimer = null;
	var currentSessionId = null;
	var verifiedOk = false;

	function findForm() {
		// Robust: das Formular über unser EIGENES, garantiert vorhandenes
		// verstecktes Feld ermitteln, statt über CSS-Klassen zu raten. Das
		// funktioniert unabhängig davon, wie das Formular heißt (Main,
		// Confirm, ...) und auch dann, wenn noch andere <form>-Elemente auf
		// der Seite existieren (z.B. ein Newsletter-Anmeldeformular).
		var $viaOwnField = $( 'input[name="civiveriff_token"]' ).first().closest( 'form' );
		if ( $viaOwnField.length ) {
			return $viaOwnField;
		}
		// Fallback für den unwahrscheinlichen Fall, dass das PHP-seitig
		// gerenderte Feld noch nicht im DOM ist.
		return $( 'form.CRM_Contribute_Form_Contribution_Main, #crm-container form' ).first();
	}

	function currentFieldValue( namePatterns ) {
		for ( var i = 0; i < namePatterns.length; i++ ) {
			var $el = findForm().find( 'input[name*="' + namePatterns[ i ] + '"]' ).first();
			if ( $el.length && $el.val() ) {
				return $el.val();
			}
		}
		return '';
	}

	function buildContainer() {
		var $form = findForm();

		// civiveriff_token und civiveriff_verified werden bereits serverseitig
		// von CiviVeriff_CiviCRM::get_or_create_token() als verstecktes Feld
		// ins Formular gerendert - hier nur referenzieren, nicht neu anlegen,
		// sonst würde beim Submit ein doppeltes/leeres Feld gewinnen.
		var $hidden = $form.find( 'input[name="civiveriff_verified"]' ).first();
		if ( ! $hidden.length ) {
			// Fallback, falls das Formular-Snippet die Felder aus irgendeinem
			// Grund nicht enthält (z.B. Theme überschreibt das Template).
			$hidden = $( '<input type="hidden" name="civiveriff_verified" value="0">' ).appendTo( $form );
		}

		var $container = $( '<div id="civiveriff-box" class="civiveriff-box"></div>' );
		var $button = $(
			'<button type="button" id="civiveriff-btn" class="civiveriff-btn">' +
				cfg.i18n.buttonLabel +
			'</button>'
		);
		var $status = $( '<div id="civiveriff-status" class="civiveriff-status" aria-live="polite"></div>' );

		$container.append( $button ).append( $status );

		var $submit = $form.find( 'input[type="submit"], button[type="submit"]' ).first();
		if ( $submit.length ) {
			$submit.before( $container );
		} else {
			$form.prepend( $container );
		}

		return { button: $button, status: $status, hidden: $hidden, form: $form };
	}

	function setStatus( el, text, cssClass ) {
		el.status.removeClass( 'is-pending is-ok is-error' ).addClass( cssClass ).text( text );
		// Inline-Style MIT "!important" setzen (nicht über jQuery .css(), das
		// setzt es ohne !important) - das gewinnt zuverlässig auch gegen
		// Theme-CSS-Regeln, die selbst !important verwenden.
		var node = el.status.get( 0 );
		if ( node ) {
			if ( 'is-error' === cssClass ) {
				node.style.setProperty( 'color', '#b42318', 'important' );
			} else if ( 'is-ok' === cssClass ) {
				node.style.setProperty( 'color', '#067647', 'important' );
			} else if ( 'is-pending' === cssClass ) {
				node.style.setProperty( 'color', '#333399', 'important' );
			} else {
				node.style.removeProperty( 'color' );
			}
		}
	}

	function startVerification( el ) {
		var firstName = currentFieldValue( [ 'first_name' ] ) || cfg.firstName;
		var lastName  = currentFieldValue( [ 'last_name' ] ) || cfg.lastName;

		el.button.prop( 'disabled', true );
		setStatus( el, cfg.i18n.pending, 'is-pending' );

		$.ajax( {
			url: cfg.createSessionUrl,
			method: 'POST',
			data: {
				token: cfg.token,
				contact_id: cfg.contactId,
				first_name: firstName,
				last_name: lastName,
				return_url: window.location.href,
				nonce: cfg.nonce,
			},
		} ).done( function ( resp ) {
			if ( ! resp || ! resp.url ) {
				setStatus( el, cfg.i18n.declined, 'is-error' );
				el.button.prop( 'disabled', false );
				return;
			}
			currentSessionId = resp.session_id;
			// Eindeutiger Fenstername pro Versuch, damit bei einem erneuten
			// Klick kein bereits gescrolltes/altes Fenster wiederverwendet
			// wird, sondern immer frisch von oben geladen wird. Größeres
			// Fenster, damit möglichst wenig Inhalt der Veriff-Seite von
			// vornherein außerhalb des sichtbaren Bereichs liegt.
			var popupName = 'civiveriff_' + Date.now();
			var popup = window.open(
				resp.url,
				popupName,
				'width=520,height=820,resizable=yes,scrollbars=yes'
			);
			// Bestmöglicher Versuch, ganz nach oben zu scrollen - funktioniert
			// nur, solange das Fenster noch nicht zu saas.veriff.com navigiert
			// hat (Cross-Origin-Beschränkung greift danach). Rein defensiv,
			// kein Ersatz für eine echte Lösung.
			try {
				if ( popup ) {
					popup.scrollTo( 0, 0 );
				}
			} catch ( e ) {}
			pollStatus( el );
		} ).fail( function () {
			setStatus( el, cfg.i18n.declined, 'is-error' );
			el.button.prop( 'disabled', false );
		} );
	}

	function pollStatus( el ) {
		clearTimeout( pollTimer );
		if ( ! currentSessionId ) {
			return;
		}
		$.ajax( {
			url: cfg.statusUrl,
			method: 'GET',
			data: { session_id: currentSessionId },
		} ).done( function ( resp ) {
			if ( 'approved' === resp.status ) {
				if ( ! resp.age_ok ) {
					verifiedOk = false;
					setStatus( el, cfg.i18n.tooYoung, 'is-error' );
					el.hidden.val( '0' );
					el.button.prop( 'disabled', false );
					return;
				}
				if ( cfg.requireMatch && ! resp.name_match ) {
					verifiedOk = false;
					setStatus( el, cfg.i18n.mismatch, 'is-error' );
					el.hidden.val( '0' );
					el.button.prop( 'disabled', false );
					return;
				}
				verifiedOk = true;
				el.hidden.val( '1' );
				setStatus( el, cfg.i18n.approved, 'is-ok' );
				el.button.prop( 'disabled', true ).text( cfg.i18n.approved );
				return;
			}
			if ( [ 'declined', 'expired', 'abandoned' ].indexOf( resp.status ) !== -1 ) {
				verifiedOk = false;
				el.hidden.val( '0' );
				setStatus( el, cfg.i18n.declined, 'is-error' );
				el.button.prop( 'disabled', false );
				return;
			}
			// Noch keine Entscheidung (created / resubmission_requested) -> weiter pollen.
			pollTimer = setTimeout( function () {
				pollStatus( el );
			}, 3000 );
		} ).fail( function () {
			pollTimer = setTimeout( function () {
				pollStatus( el );
			}, 5000 );
		} );
	}

	function bindNameChangeReset( el ) {
		var $first = el.form.find( 'input[name*="first_name"]' ).first();
		var $last  = el.form.find( 'input[name*="last_name"]' ).first();

		function handleNameChange() {
			if ( ! verifiedOk ) {
				return; // Noch nicht verifiziert - nichts zurückzusetzen.
			}
			verifiedOk = false;
			el.hidden.val( '0' );
			el.button.prop( 'disabled', false ).text( cfg.i18n.buttonLabel );
			setStatus( el, cfg.i18n.nameChanged, 'is-error' );
		}

		$first.on( 'input change', handleNameChange );
		$last.on( 'input change', handleNameChange );
	}

	$( function () {
		var el = buildContainer();

		// Falls civiveriff_verified bereits "1" ist (vom vorherigen
		// Formular-Schritt übernommen, z.B. Main -> Confirm), muss der Nutzer
		// nicht nochmal verifizieren.
		if ( '1' === el.hidden.val() ) {
			verifiedOk = true;
			setStatus( el, cfg.i18n.approved, 'is-ok' );
			el.button.prop( 'disabled', true ).text( cfg.i18n.approved );
		}

		bindNameChangeReset( el );

		el.button.on( 'click', function () {
			startVerification( el );
		} );

		el.form.on( 'submit', function ( e ) {
			if ( ! verifiedOk ) {
				e.preventDefault();
				window.alert( cfg.i18n.blockedSubmit );
				return false;
			}
		} );

		// Das Bestätigungsfenster (siehe class-civiveriff-callback.php) sendet
		// eine postMessage, sobald der Veriff-Flow abgeschlossen ist. Damit
		// muss nicht auf das nächste Poll-Intervall gewartet werden.
		window.addEventListener( 'message', function ( event ) {
			if ( event && event.data && event.data.civiveriff && currentSessionId ) {
				pollStatus( el );
			}
		} );
	} );
} )( jQuery );
