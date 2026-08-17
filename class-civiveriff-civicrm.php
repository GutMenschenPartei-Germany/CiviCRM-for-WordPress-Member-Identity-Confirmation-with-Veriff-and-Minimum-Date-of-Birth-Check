<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bindet die Veriff-Verifizierung in das CiviCRM-Mitgliedschafts-/
 * Contribution-Formular ein.
 *
 * CiviCRMs WordPress-Hook-Bridge stellt die Standard-CiviCRM-Hooks
 * (siehe https://docs.civicrm.org/dev/en/latest/hooks/) auch als
 * WordPress-Actions/Filter unter demselben Namen zur Verfügung, inkl.
 * Referenz-Semantik für Objekte/Arrays. Deshalb reicht hier add_action().
 */
class CiviVeriff_CiviCRM {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'civicrm_buildForm', array( $this, 'buildForm' ), 10, 2 );
		add_action( 'civicrm_validateForm', array( $this, 'validateForm' ), 10, 5 );
		add_action( 'civicrm_postProcess', array( $this, 'postProcess' ), 10, 2 );
	}

	private function configured_form_classes() {
		$raw = CiviVeriff_Settings::get( 'form_classes', 'CRM_Contribute_Form_Contribution_Main' );
		$list = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		return $list;
	}

	/**
	 * Fügt dem Formular die Verifizierungs-UI hinzu (Button, versteckte Felder, JS).
	 *
	 * @param string $formName
	 * @param object &$form
	 */
	/**
	 * Aktueller Formularname, nur für Debug-Logging gesetzt (siehe buildForm/get_or_create_token).
	 */
	private $current_form_name = '';

	public function buildForm( $formName, &$form ) {
		if ( is_admin() ) {
			return; // Nur Frontend-Signup betreffen.
		}
		if ( ! in_array( $formName, $this->configured_form_classes(), true ) ) {
			return;
		}

		$this->current_form_name = $formName;

		// Bei einer Neuanmeldung (der Normalfall auf einer öffentlichen
		// Mitgliedschafts-Signup-Seite) existiert zu diesem Zeitpunkt noch
		// KEIN CiviCRM-Kontakt. Wir verlangen die contact_id deshalb NICHT
		// mehr - stattdessen bekommt jeder Formularaufruf ein einmaliges
		// Token, über das Session <-> Formular-Submit zugeordnet werden.
		// Ist der Nutzer bereits ein bekannter/eingeloggter Kontakt (z.B.
		// Verlängerung), wird die contact_id zusätzlich mitgegeben.
		$contact_id = $this->get_form_contact_id( $form );
		$token      = $this->get_or_create_token( $form );

		list( $first_name, $last_name ) = $this->get_expected_names( $form, $contact_id );

		wp_enqueue_script(
			'civiveriff-frontend',
			CIVIVERIFF_PLUGIN_URL . 'assets/js/veriff-frontend.js',
			array( 'jquery' ),
			CIVIVERIFF_VERSION,
			true
		);
		wp_enqueue_style(
			'civiveriff-frontend',
			CIVIVERIFF_PLUGIN_URL . 'assets/css/veriff-frontend.css',
			array(),
			CIVIVERIFF_VERSION
		);

		wp_localize_script(
			'civiveriff-frontend',
			'CiviVeriffConfig',
			array(
				'createSessionUrl' => rest_url( 'civiveriff/v1/create-session' ),
				'statusUrl'        => rest_url( 'civiveriff/v1/status' ),
				'token'            => $token,
				'contactId'        => $contact_id, // kann 0 sein
				'firstName'        => $first_name,
				'lastName'         => $last_name,
				'nonce'            => wp_create_nonce( 'civiveriff_frontend_' . $token ),
				'requireMatch'     => (bool) CiviVeriff_Settings::get( 'require_exact_match', 1 ),
				'i18n'             => array(
					'buttonLabel'    => 'Identität mit Veriff verifizieren',
					'pending'        => 'Verifizierung läuft … bitte das geöffnete Fenster abschließen.',
					'approved'       => 'Identität erfolgreich verifiziert.',
					'mismatch'       => 'Der verifizierte Name stimmt nicht mit den Formularangaben überein. Bitte korrigiere deine Angaben oder kontaktiere uns.',
					'declined'       => 'Die Identitätsprüfung war nicht erfolgreich. Bitte versuche es erneut oder kontaktiere uns.',
					'blockedSubmit'  => 'Bitte schließe zuerst die Identitätsprüfung ab, bevor du fortfährst.',
					'nameChanged'    => 'Du hast deinen Namen geändert - bitte verifiziere deine Identität erneut.',
					'tooYoung'       => 'Du erfüllst das Mindestalter für eine Mitgliedschaft nicht.',
				),
			)
		);
	}

	/**
	 * Sucht einen Wert (z.B. unser Token) in den bereits von früheren
	 * Formular-Schritten übermittelten Werten, die CiviCRM serverseitig im
	 * Controller-Container hält (funktioniert - anders als $_POST - auch
	 * dann noch, wenn zwischen den Schritten ein Redirect stattfindet).
	 */
	private function find_value_in_controller( &$form, $key ) {
		if ( empty( $form->controller ) || ! method_exists( $form->controller, 'container' ) ) {
			return '';
		}
		$container = $form->controller->container();
		if ( empty( $container['values'] ) || ! is_array( $container['values'] ) ) {
			return '';
		}
		foreach ( $container['values'] as $page_values ) {
			if ( is_array( $page_values ) && ! empty( $page_values[ $key ] ) ) {
				return $page_values[ $key ];
			}
		}
		return '';
	}

	/**
	 * Erzeugt (oder liest ein bereits vorhandenes) einmaliges Token für
	 * diesen Formularaufruf und hängt es als verstecktes Feld ins Formular,
	 * damit es beim Submit in $fields (validateForm) verfügbar ist.
	 *
	 * WICHTIG bei mehrseitigen Formularen (z.B. Main -> Confirm): CiviCRM
	 * erzeugt für jeden Schritt ein NEUES Form-Objekt, $form->getVar() greift
	 * also nicht über Schritte hinweg. Wir schauen deshalb zusätzlich in
	 * CiviCRMs Controller-Container (überlebt Redirects) und, als letzten
	 * Fallback, in $_POST nach - bevor wir ein neues Token erzeugen und damit
	 * eine bereits erfolgreiche Verifizierung "vergessen".
	 */
	private function get_or_create_token( &$form ) {
		$existing = $form->getVar( '_civiveriffToken' );

		if ( empty( $existing ) ) {
			$existing = $this->find_value_in_controller( $form, 'civiveriff_token' );
		}

		if ( empty( $existing ) && isset( $_POST['civiveriff_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- Wert wird unten nur als Kennung verwendet, die eigentliche Sicherheitsprüfung läuft serverseitig gegen die DB (validateForm).
			$existing = sanitize_text_field( wp_unslash( $_POST['civiveriff_token'] ) );
		}

		if ( (bool) CiviVeriff_Settings::get( 'debug_mode', 0 ) ) {
			CiviVeriff_Logger::log( "CIVIVERIFF buildForm [{$this->current_form_name}] token: " . ( $existing ? "wiederverwendet ({$existing})" : 'neu erzeugt' ) );
		}

		if ( ! empty( $existing ) ) {
			$form->setVar( '_civiveriffToken', $existing );
			if ( ! $form->elementExists( 'civiveriff_token' ) ) {
				$form->addElement( 'hidden', 'civiveriff_token', $existing );
			}
			$this->ensure_verified_hidden_field( $form );
			return $existing;
		}

		$token = wp_generate_uuid4();
		$form->setVar( '_civiveriffToken', $token );
		$form->addElement( 'hidden', 'civiveriff_token', $token );
		$this->ensure_verified_hidden_field( $form );
		return $token;
	}

	/**
	 * Legt das versteckte "civiveriff_verified"-Feld an - übernimmt dabei
	 * einen bereits aus dem vorherigen Formular-Schritt übertragenen Wert
	 * (z.B. "1", wenn im Schritt zuvor schon erfolgreich verifiziert wurde),
	 * damit der Nutzer nicht ein zweites Mal verifizieren muss.
	 */
	private function ensure_verified_hidden_field( &$form ) {
		if ( $form->elementExists( 'civiveriff_verified' ) ) {
			if ( (bool) CiviVeriff_Settings::get( 'debug_mode', 0 ) ) {
				CiviVeriff_Logger::log( "CIVIVERIFF buildForm [{$this->current_form_name}] civiveriff_verified: Feld existiert bereits auf diesem Form-Objekt, überspringe." );
			}
			return;
		}
		$carried_over = $this->find_value_in_controller( $form, 'civiveriff_verified' );
		$source        = 'controller-container';
		if ( empty( $carried_over ) && isset( $_POST['civiveriff_verified'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- nur UX-Vorbelegung, serverseitige Prüfung erfolgt unabhängig davon in validateForm gegen die DB.
			$carried_over = sanitize_text_field( wp_unslash( $_POST['civiveriff_verified'] ) );
			$source        = '$_POST';
		}
		if ( (bool) CiviVeriff_Settings::get( 'debug_mode', 0 ) ) {
			CiviVeriff_Logger::log( "CIVIVERIFF buildForm [{$this->current_form_name}] civiveriff_verified übernommener Wert: '" . $carried_over . "' (Quelle: {$source})" );
		}
		$form->addElement( 'hidden', 'civiveriff_verified', ( '1' === $carried_over ) ? '1' : '0' );
	}

	/**
	 * Serverseitige Durchsetzung: Formular darf nur abgeschickt werden, wenn
	 * für den Kontakt eine positive, namensgleiche Veriff-Entscheidung vorliegt.
	 * Das ist die eigentliche Sicherheitsgrenze – die UI/JS-Prüfung ist nur UX.
	 */
	public function validateForm( $formName, &$fields, &$files, &$form, &$errors ) {
		if ( is_admin() ) {
			return;
		}
		if ( ! in_array( $formName, $this->configured_form_classes(), true ) ) {
			return;
		}

		$token = isset( $fields['civiveriff_token'] ) ? sanitize_text_field( $fields['civiveriff_token'] ) : '';
		if ( empty( $token ) ) {
			// Sollte nicht vorkommen, wenn buildForm gelaufen ist - im Zweifel
			// blockieren statt versehentlich durchzulassen.
			$errors['_qf_default'] = 'Identitätsprüfung konnte nicht ermittelt werden. Bitte lade die Seite neu.';
			return;
		}

		$row = CiviVeriff_DB::get_latest_for_token( $token );

		$ok = $row && 'approved' === $row['status'];

		if ( $ok ) {
			// WICHTIG (Sicherheit): Mindestalter anhand des von Veriff aus dem
			// Ausweisdokument gelesenen Geburtsdatums prüfen - unabhängig
			// davon, was im Formular selbst (falls vorhanden) an Geburtsdatum
			// eingetragen wurde.
			$require_min_age = (bool) CiviVeriff_Settings::get( 'require_min_age', 1 );
			if ( $require_min_age && empty( $row['age_ok'] ) ) {
				$ok = false;
				if ( (bool) CiviVeriff_Settings::get( 'debug_mode', 0 ) ) {
					CiviVeriff_Logger::log( "CIVIVERIFF validateForm [{$formName}]: Mindestalter nicht erfüllt (verifiziertes Geburtsdatum: '{$row['verified_dob']}') - blockiere." );
				}
			}
		}

		if ( $ok ) {
			// WICHTIG (Sicherheit): Wir prüfen hier den AKTUELL im Formular
			// eingetragenen Namen erneut live gegen den von Veriff aus dem
			// Ausweisdokument gelesenen Namen - nicht nur, ob überhaupt
			// irgendwann erfolgreich verifiziert wurde. Ohne diesen Re-Check
			// könnte jemand mit dem eigenen Ausweis verifizieren und den Namen
			// im Formular danach auf eine andere Person ändern.
			$submitted_first = $this->find_submitted_field( $fields, 'first_name' );
			$submitted_last  = $this->find_submitted_field( $fields, 'last_name' );

			$require_match = (bool) CiviVeriff_Settings::get( 'require_exact_match', 1 );
			if ( $require_match ) {
				$name_ok = CiviVeriff_API::normalize_name( $submitted_first ) === CiviVeriff_API::normalize_name( $row['verified_first_name'] )
					&& CiviVeriff_API::normalize_name( $submitted_last ) === CiviVeriff_API::normalize_name( $row['verified_last_name'] );

				if ( ! $name_ok ) {
					$ok = false;
					if ( (bool) CiviVeriff_Settings::get( 'debug_mode', 0 ) ) {
						CiviVeriff_Logger::log(
							sprintf(
								'CIVIVERIFF validateForm [%s]: Name im Formular ("%s %s") weicht vom verifizierten Namen ("%s %s") ab - blockiere.',
								$formName,
								$submitted_first,
								$submitted_last,
								$row['verified_first_name'],
								$row['verified_last_name']
							)
						);
					}
				}
			}
		}

		if ( ! $ok ) {
			$errors['_qf_default'] = 'Die Identitätsprüfung (Veriff) wurde noch nicht erfolgreich abgeschlossen, der eingegebene Name stimmt nicht mit dem verifizierten Namen überein, oder das Mindestalter wird nicht erfüllt. Bitte verifiziere deine Identität (erneut).';
		}
	}

	/**
	 * Sucht ein Feld anhand eines Teilstrings im Feldnamen in den übermittelten
	 * Formularwerten (analog zur JS-Logik, die ebenfalls über "name*=" sucht),
	 * da CiviCRM-Feldnamen je nach Profil-/Formularkonfiguration variieren
	 * können (nicht immer exakt "first_name"/"last_name").
	 */
	private function find_submitted_field( $fields, $needle ) {
		if ( isset( $fields[ $needle ] ) && ! is_array( $fields[ $needle ] ) ) {
			return (string) $fields[ $needle ];
		}
		foreach ( $fields as $key => $value ) {
			if ( ! is_array( $value ) && false !== strpos( $key, $needle ) ) {
				return (string) $value;
			}
		}
		return '';
	}

	/**
	 * Läuft nach erfolgreicher Verarbeitung des Formulars (Kontakt/Mitgliedschaft
	 * wurde soeben angelegt). Verknüpft die per Token gefundene Veriff-Session
	 * nachträglich mit der jetzt bekannten echten contact_id und trägt das
	 * Audit-Log in CiviCRM nach.
	 */
	public function postProcess( $formName, &$form ) {
		if ( ! in_array( $formName, $this->configured_form_classes(), true ) ) {
			return;
		}

		$token = $form->getVar( '_civiveriffToken' );
		if ( empty( $token ) ) {
			// Manche Multi-Page-Flows bauen ein neues Form-Objekt pro Step -
			// dann kommt der Wert stattdessen aus dem geposteten Feld.
			$submitted = $form->exportValues();
			$token     = $submitted['civiveriff_token'] ?? '';
		}
		if ( empty( $token ) ) {
			return;
		}

		$contact_id = $this->get_form_contact_id( $form );
		if ( empty( $contact_id ) ) {
			return; // contact_id auf diesem Formular-Step noch nicht verfügbar.
		}

		CiviVeriff_DB::attach_contact_to_token( $token, $contact_id );

		$row = CiviVeriff_DB::get_latest_for_token( $token );
		if ( $row && function_exists( 'civicrm_initialize' ) ) {
			self::log_verification_activity(
				$contact_id,
				$row['status'],
				(bool) $row['name_match'],
				$row['verified_first_name'],
				$row['verified_last_name']
			);
		}
	}

	/**
	 * Versucht die aktuelle CiviCRM-Kontakt-ID aus dem Formular zu ermitteln.
	 * ANPASSEN: je nach Formulartyp/Flow kann die richtige Quelle variieren.
	 */
	private function get_form_contact_id( $form ) {
		if ( ! empty( $form->_contactID ) ) {
			return (int) $form->_contactID;
		}
		if ( method_exists( $form, 'getContactID' ) ) {
			$cid = $form->getContactID();
			if ( ! empty( $cid ) ) {
				return (int) $cid;
			}
		}
		if ( function_exists( 'CRM_Core_Session' ) ) {
			$session = CRM_Core_Session::singleton();
			$cid     = $session->get( 'userID' );
			if ( ! empty( $cid ) ) {
				return (int) $cid;
			}
		}
		return 0;
	}

	/**
	 * Ermittelt Vor-/Nachname, gegen die Veriff die Ausweisdaten abgleichen soll.
	 * Nutzt vorhandene CiviCRM-Kontaktdaten als Ausgangswert (der Nutzer kann
	 * diese im Formular ggf. noch überschreiben – das JS liest die aktuellen
	 * Feldwerte zur Laufzeit aus dem Formular, siehe veriff-frontend.js).
	 */
	private function get_expected_names( $form, $contact_id ) {
		$first_name = '';
		$last_name  = '';
		try {
			$contact = civicrm_api3(
				'Contact',
				'getsingle',
				array(
					'id'     => $contact_id,
					'return' => array( 'first_name', 'last_name' ),
				)
			);
			$first_name = $contact['first_name'] ?? '';
			$last_name  = $contact['last_name'] ?? '';
		} catch ( Exception $e ) {
			// Kontakt evtl. noch nicht vollständig angelegt - JS nutzt dann
			// die Live-Formularwerte für first_name/last_name Felder.
		}
		return array( $first_name, $last_name );
	}

	/**
	 * Legt eine CiviCRM-Aktivität als Audit-Trail für die Verifizierung an.
	 * ANPASSEN: Activity Type "Identity Verification" muss vorher unter
	 * Administer > Option Lists > Activity Types angelegt werden (oder hier
	 * per API angelegt, falls noch nicht vorhanden).
	 */
	public static function log_verification_activity( $contact_id, $status, $name_match, $verified_first, $verified_last ) {
		try {
			civicrm_api3(
				'Activity',
				'create',
				array(
					'activity_type_id' => 'Identity Verification', // ANPASSEN: exakter Label/ID-Wert.
					'source_contact_id' => $contact_id,
					'target_contact_id' => $contact_id,
					'subject'           => sprintf(
						'Veriff-Ergebnis: %s (Namensabgleich: %s)',
						$status,
						$name_match ? 'ok' : 'Abweichung'
					),
					'details'           => sprintf(
						'Verifizierter Name laut Ausweisdokument: %s %s',
						$verified_first,
						$verified_last
					),
					'status_id'         => 'Completed',
				)
			);
		} catch ( Exception $e ) {
			// Aktivitätstyp evtl. noch nicht angelegt - Verifizierung selbst
			// ist davon unabhängig und bleibt in der Plugin-eigenen Tabelle erhalten.
		}
	}
}
