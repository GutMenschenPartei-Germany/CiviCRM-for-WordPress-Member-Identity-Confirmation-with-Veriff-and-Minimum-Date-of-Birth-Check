<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CiviVeriff_REST {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'civiveriff/v1',
			'/create-session',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_create_session' ),
				'permission_callback' => '__return_true', // Öffentlich (Frontend-Formular), Absicherung über CiviCRM-Kontakt + Nonce.
				'args'                => array(
					'token'      => array( 'required' => true ),
					'contact_id' => array( 'required' => false ), // 0/leer = noch kein CiviCRM-Kontakt vorhanden (Neuanmeldung).
					'first_name' => array( 'required' => true ),
					'last_name'  => array( 'required' => true ),
					'return_url' => array( 'required' => false ),
					'nonce'      => array( 'required' => true ),
				),
			)
		);

		register_rest_route(
			'civiveriff/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'session_id' => array( 'required' => true ),
				),
			)
		);

		register_rest_route(
			'civiveriff/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_decision' ),
				'permission_callback' => '__return_true', // Absicherung über HMAC-Signatur, nicht über WP-Auth.
			)
		);
	}

	/**
	 * Frontend fordert eine neue Veriff-Session für den aktuellen Formular-Kontakt an.
	 */
	public function handle_create_session( WP_REST_Request $request ) {
		$token      = sanitize_text_field( $request->get_param( 'token' ) );
		$contact_id = absint( $request->get_param( 'contact_id' ) ); // kann 0 sein - neuer Kontakt, existiert erst nach Submit.
		$first_name = sanitize_text_field( $request->get_param( 'first_name' ) );
		$last_name  = sanitize_text_field( $request->get_param( 'last_name' ) );
		$return_url = esc_url_raw( (string) $request->get_param( 'return_url' ) );
		$nonce      = $request->get_param( 'nonce' );

		if ( empty( $token ) || ! wp_verify_nonce( $nonce, 'civiveriff_frontend_' . $token ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_nonce' ), 403 );
		}

		if ( empty( $first_name ) || empty( $last_name ) ) {
			return new WP_REST_Response( array( 'error' => 'missing_fields' ), 400 );
		}

		// vendorData/endUserId in der Veriff-Session: solange kein Kontakt
		// existiert, nutzen wir das Token als Referenz statt der Kontakt-ID.
		$reference = $contact_id ? (string) $contact_id : $token;
		$result    = CiviVeriff_API::create_session( $reference, $first_name, $last_name, $token, $return_url );

		if ( (bool) CiviVeriff_Settings::get( 'debug_mode', 0 ) ) {
			CiviVeriff_Logger::log( 'CIVIVERIFF create-session result: ' . wp_json_encode( is_wp_error( $result ) ? $result->get_error_message() : $result ) );
		}

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 500 );
		}

		CiviVeriff_DB::insert_session( $result['session_id'], $token, $contact_id, $first_name, $last_name );

		return new WP_REST_Response(
			array(
				'session_id' => $result['session_id'],
				'url'        => $result['url'],
			),
			200
		);
	}

	/**
	 * Frontend pollt diesen Endpunkt, um zu erfahren, ob die Verifizierung
	 * abgeschlossen wurde (Ergebnis kommt asynchron per Webhook rein).
	 */
	public function handle_status( WP_REST_Request $request ) {
		$session_id = sanitize_text_field( $request->get_param( 'session_id' ) );
		$row        = CiviVeriff_DB::get_by_session_id( $session_id );

		if ( ! $row ) {
			return new WP_REST_Response( array( 'status' => 'unknown' ), 404 );
		}

		return new WP_REST_Response(
			array(
				'status'     => $row['status'], // created | approved | declined | resubmission_requested | expired | abandoned
				'name_match' => (bool) $row['name_match'],
				'age_ok'     => (bool) $row['age_ok'],
			),
			200
		);
	}

	/**
	 * Empfängt den Veriff Decision-Webhook. Muss serverseitig verlässlich sein,
	 * da das Frontend-Polling nur UX ist – die eigentliche Freigabe der
	 * Mitgliedschaft prüft CiviVeriff_CiviCRM anhand dieser Tabelle.
	 */
	public function handle_decision( WP_REST_Request $request ) {
		$raw_body  = $request->get_body();
		$signature = $request->get_header( 'x-hmac-signature' );
		if ( ! $signature ) {
			$signature = $request->get_header( 'x-signature' ); // ältere Integrationen nutzen ggf. diesen Header
		}

		$debug = (bool) CiviVeriff_Settings::get( 'debug_mode', 0 );
		if ( $debug ) {
			CiviVeriff_Logger::log( 'CIVIVERIFF webhook received. Headers: ' . wp_json_encode( $request->get_headers() ) );
			CiviVeriff_Logger::log( 'CIVIVERIFF webhook signature header value: ' . ( $signature ? $signature : '(leer/fehlt)' ) );
			CiviVeriff_Logger::log( 'CIVIVERIFF webhook raw body: ' . $raw_body );
		}

		if ( ! CiviVeriff_API::verify_webhook_signature( $raw_body, $signature ) ) {
			if ( $debug ) {
				CiviVeriff_Logger::log( 'CIVIVERIFF webhook signature INVALID. Empfangen (X-HMAC-SIGNATURE): ' . $signature );
				CiviVeriff_Logger::log( 'CIVIVERIFF webhook signature INVALID. Erwartet (aus Shared Secret + Body berechnet): ' . CiviVeriff_API::compute_signature( $raw_body ) );
				CiviVeriff_Logger::log( 'CIVIVERIFF webhook signature INVALID. Konfiguriertes Secret (maskiert): ' . CiviVeriff_API::masked_secret_info() );
				CiviVeriff_Logger::log( 'CIVIVERIFF webhook signature INVALID. Stimmen die beiden Werte oben NICHT überein, ist entweder das Shared Secret in den Plugin-Einstellungen falsch, oder der Body wurde unterwegs verändert (z.B. durch ein Sicherheits-Plugin/CDN).' );
			}
			return new WP_REST_Response( array( 'error' => 'invalid_signature' ), 401 );
		}

		if ( $debug ) {
			CiviVeriff_Logger::log( 'CIVIVERIFF webhook signature OK.' );
		}

		$payload = json_decode( $raw_body, true );

		// Veriff nutzt je nach Plan/Konfiguration unterschiedliche Payload-
		// Formen. Bei euch (Full Auto Plan) sieht die Struktur so aus:
		// { "sessionId": "...", "data": { "verification": { "decision": "...", "person": { "firstName": {"value": "..."} , ... } } } }
		// Der "klassische" Decision-Webhook sieht dagegen so aus:
		// { "verification": { "id": "...", "status": "...", "person": { "firstName": "...", ... } } }
		$verification_data = null;
		$session_id         = '';
		$status             = 'unknown';

		if ( ! empty( $payload['data']['verification'] ) ) {
			// Full-Auto-Format.
			$verification_data = $payload['data']['verification'];
			$session_id        = $payload['sessionId'] ?? '';
			$status             = $verification_data['decision'] ?? 'unknown'; // approved | declined | review | resubmission_requested ...
		} elseif ( ! empty( $payload['verification'] ) ) {
			// Standard Decision-Webhook-Format.
			$verification_data = $payload['verification'];
			$session_id        = $verification_data['id'] ?? '';
			$status             = $verification_data['status'] ?? 'unknown';
		}

		if ( empty( $verification_data ) ) {
			if ( $debug ) {
				CiviVeriff_Logger::log( 'CIVIVERIFF webhook payload unerwartet (weder "data.verification" noch "verification"-Key gefunden).' );
			}
			return new WP_REST_Response( array( 'error' => 'unexpected_payload' ), 400 );
		}

		$row = CiviVeriff_DB::get_by_session_id( $session_id );

		if ( ! $row ) {
			if ( $debug ) {
				CiviVeriff_Logger::log( "CIVIVERIFF webhook: keine lokale Session zu id={$session_id} gefunden (unbekannte Session)." );
			}
			// Unbekannte Session – trotzdem 200 zurückgeben, damit Veriff nicht wiederholt zustellt.
			return new WP_REST_Response( array( 'ok' => true, 'note' => 'unknown_session' ), 200 );
		}

		// Namen können entweder als einfacher String oder (Full-Auto-Format)
		// als Objekt { value, confidenceCategory, sources } vorliegen.
		$verified_first = $this->extract_person_value( $verification_data, 'firstName' );
		$verified_last  = $this->extract_person_value( $verification_data, 'lastName' );
		$verified_dob   = $this->extract_person_value( $verification_data, 'dateOfBirth' );

		$name_match = $this->determine_name_match( $verification_data, $row['expected_first_name'], $row['expected_last_name'], $verified_first, $verified_last );

		$require_min_age = (bool) CiviVeriff_Settings::get( 'require_min_age', 1 );
		$minimum_age      = (int) CiviVeriff_Settings::get( 'minimum_age', 18 );
		$age              = CiviVeriff_API::calculate_age_from_dob( $verified_dob );
		// Ohne Mindestalter-Pflicht ODER wenn kein Geburtsdatum ermittelt werden
		// konnte, NICHT automatisch durchwinken - sicherer Default ist "nicht
		// bestätigt", außer die Prüfung ist bewusst deaktiviert.
		$age_ok = $require_min_age ? ( null !== $age && $age >= $minimum_age ) : true;

		if ( $debug ) {
			CiviVeriff_Logger::log( "CIVIVERIFF webhook Altersprüfung: dob='{$verified_dob}', berechnetes Alter=" . ( null === $age ? 'unbekannt' : $age ) . ", Mindestalter={$minimum_age}, Ergebnis=" . ( $age_ok ? 'OK' : 'NICHT erfüllt' ) );
		}

		CiviVeriff_DB::update_decision( $session_id, $status, $verified_first, $verified_last, $name_match, $verified_dob, $age_ok, $payload );

		// Bei Neuanmeldungen existiert der CiviCRM-Kontakt zum Zeitpunkt des
		// Webhooks meist noch nicht (Nutzer füllt das Formular ggf. noch aus).
		// Das Audit-Log wird dann erst nach dem Submit über den
		// civicrm_postProcess-Hook nachgetragen (siehe CiviVeriff_CiviCRM).
		if ( function_exists( 'civicrm_initialize' ) && ! empty( $row['contact_id'] ) ) {
			CiviVeriff_CiviCRM::log_verification_activity( (int) $row['contact_id'], $status, $name_match, $verified_first, $verified_last );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Liest ein person-Feld (z.B. firstName) aus den Veriff-Verifizierungsdaten
	 * aus - unabhängig davon, ob es als einfacher String oder als Objekt
	 * { value, confidenceCategory, sources } vorliegt (Full-Auto-Format).
	 */
	private function extract_person_value( $verification_data, $field ) {
		$value = $verification_data['person'][ $field ] ?? '';
		if ( is_array( $value ) ) {
			return $value['value'] ?? '';
		}
		return (string) $value;
	}

	/**
	 * Bestimmt, ob der verifizierte Name mit dem im Formular angegebenen Namen
	 * übereinstimmt. Nutzt bevorzugt Veriffs eigenes InitData-Matching
	 * (matchingResults), falls vorhanden, sonst einen einfachen eigenen Vergleich.
	 */
	private function determine_name_match( $verification, $expected_first, $expected_last, $verified_first, $verified_last ) {
		if ( ! empty( $verification['matchingResults'] ) && is_array( $verification['matchingResults'] ) ) {
			$all_matched = true;
			$found_name_field = false;
			foreach ( $verification['matchingResults'] as $result ) {
				if ( isset( $result['type'] ) && in_array( $result['type'], array( 'person.firstName', 'person.lastName' ), true ) ) {
					$found_name_field = true;
					if ( empty( $result['match'] ) ) {
						$all_matched = false;
					}
				}
			}
			if ( $found_name_field ) {
				return $all_matched;
			}
		}

		// Fallback: eigener, toleranter String-Vergleich (Groß-/Kleinschreibung, Whitespace).
		return CiviVeriff_API::normalize_name( $expected_first ) === CiviVeriff_API::normalize_name( $verified_first )
			&& CiviVeriff_API::normalize_name( $expected_last ) === CiviVeriff_API::normalize_name( $verified_last );
	}
}
