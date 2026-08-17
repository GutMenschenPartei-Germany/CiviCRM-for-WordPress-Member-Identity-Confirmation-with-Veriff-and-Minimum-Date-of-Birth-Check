<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dünner Wrapper um die Veriff Sessions-API.
 * Doku: https://devdocs.veriff.com/
 */
class CiviVeriff_API {

	/**
	 * Erstellt eine neue Veriff-Verifizierungs-Session.
	 *
	 * Wir übergeben firstName/lastName als "person"-Daten (initData). Wenn im
	 * Veriff-Account "InitData Matching" aktiviert ist, vergleicht Veriff diese
	 * Werte automatisch mit den aus dem Ausweisdokument extrahierten Daten und
	 * liefert das Ergebnis im Decision-Webhook als "matchingResults" mit. Falls
	 * das Feature nicht aktiviert ist, vergleichen wir den Namen zusätzlich
	 * selbst serverseitig (siehe CiviVeriff_REST::handle_decision).
	 *
	 * @param string $reference  CiviCRM contact_id (falls bekannt) oder sonst das Token.
	 * @param string $first_name Vorname laut CiviCRM-Formular.
	 * @param string $last_name  Nachname laut CiviCRM-Formular.
	 * @param string $token      Einmal-Token des Formularaufrufs (für die Rücksprung-URL).
	 * @param string $return_url Ursprüngliche Formular-URL, zu der zurückgesprungen werden soll.
	 * @return array|WP_Error
	 */
	public static function create_session( $reference, $first_name, $last_name, $token = '', $return_url = '' ) {
		$base_url = CiviVeriff_Settings::get( 'base_url', 'https://stationapi.veriff.com' );
		$api_key  = CiviVeriff_Settings::get( 'api_key' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'civiveriff_no_api_key', 'Veriff API Key ist nicht konfiguriert.' );
		}

		$callback_url = home_url( '/veriff-verification-complete/' );
		if ( $token || $return_url ) {
			$callback_url = add_query_arg(
				array(
					'token'      => $token,
					'return_to'  => rawurlencode( $return_url ),
				),
				$callback_url
			);
		}

		$body = array(
			'verification' => array(
				'callback'   => $callback_url,
				'vendorData' => (string) $reference,
				'endUserId'  => (string) $reference,
				'person'     => array(
					'firstName' => $first_name,
					'lastName'  => $last_name,
				),
			),
		);

		$response = wp_remote_post(
			$base_url . '/v1/sessions',
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'X-AUTH-CLIENT' => $api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $data['verification']['id'] ) ) {
			return new WP_Error(
				'civiveriff_session_failed',
				'Veriff-Session konnte nicht erstellt werden.',
				array( 'status' => $code, 'response' => $data )
			);
		}

		return array(
			'session_id' => $data['verification']['id'],
			'url'        => $data['verification']['url'],
		);
	}

	/**
	 * Holt (falls nötig, z.B. als Fallback zum Webhook) die Decision zu einer Session.
	 */
	public static function get_decision( $session_id ) {
		$base_url = CiviVeriff_Settings::get( 'base_url', 'https://stationapi.veriff.com' );
		$api_key  = CiviVeriff_Settings::get( 'api_key' );

		$response = wp_remote_get(
			$base_url . '/v1/sessions/' . rawurlencode( $session_id ) . '/decision',
			array(
				'timeout' => 20,
				'headers' => array(
					'X-AUTH-CLIENT' => $api_key,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	/**
	 * Berechnet die erwartete HMAC-SHA256-Signatur für einen Body. Getrennt von
	 * verify_webhook_signature(), damit sie bei Bedarf zu Debug-Zwecken geloggt
	 * werden kann (der Hash selbst ist unkritisch, das Secret bleibt geheim).
	 */
	public static function compute_signature( $raw_body ) {
		$shared_secret = CiviVeriff_Settings::get( 'shared_secret' );
		if ( empty( $shared_secret ) ) {
			return '';
		}
		return hash_hmac( 'sha256', $raw_body, $shared_secret );
	}

	/**
	 * Berechnet das Alter (in vollen Jahren) aus einem Geburtsdatum im Format
	 * "YYYY-MM-DD" (so liefert Veriff es). Gibt null zurück, wenn das Datum
	 * nicht geparst werden konnte.
	 */
	public static function calculate_age_from_dob( $dob ) {
		if ( empty( $dob ) ) {
			return null;
		}
		try {
			$birth_date = new DateTime( $dob );
			$today      = new DateTime( 'today' );
			if ( $birth_date > $today ) {
				return null; // Datum in der Zukunft - unplausibel, nicht werten.
			}
			return (int) $birth_date->diff( $today )->y;
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Normalisiert einen Namen für tolerante Vergleiche (Groß-/Kleinschreibung,
	 * mehrfache Leerzeichen). Wird sowohl beim initialen Webhook-Abgleich als
	 * auch beim finalen serverseitigen Re-Check in validateForm() genutzt
	 * (siehe CiviVeriff_CiviCRM::validateForm).
	 */
	public static function normalize_name( $value ) {
		$value = mb_strtolower( trim( (string) $value ), 'UTF-8' );
		return preg_replace( '/\s+/', ' ', $value );
	}

	/**
	 * Liefert eine maskierte Darstellung des konfigurierten Shared Secret
	 * (nur Länge + erste/letzte 2 Zeichen), damit man beim Debuggen erkennen
	 * kann, ob z.B. versehentlich das falsche Secret, ein Leerzeichen oder ein
	 * Zeilenumbruch mitkopiert wurde - ohne das Secret selbst offenzulegen.
	 */
	public static function masked_secret_info() {
		$secret = CiviVeriff_Settings::get( 'shared_secret' );
		if ( empty( $secret ) ) {
			return '(kein Secret konfiguriert)';
		}
		$len = strlen( $secret );
		if ( $len <= 4 ) {
			return "Länge: {$len} Zeichen (sehr kurz - stimmt das?)";
		}
		return sprintf(
			'Länge: %d Zeichen, beginnt mit "%s", endet mit "%s"',
			$len,
			substr( $secret, 0, 2 ),
			substr( $secret, -2 )
		);
	}

	/**
	 * Prüft die HMAC-SHA256-Signatur eines eingehenden Veriff-Webhooks.
	 *
	 * @param string $raw_body      Roher Request-Body.
	 * @param string $signature     Wert aus dem Header X-HMAC-SIGNATURE / X-SIGNATURE.
	 * @return bool
	 */
	public static function verify_webhook_signature( $raw_body, $signature ) {
		$shared_secret = CiviVeriff_Settings::get( 'shared_secret' );
		if ( empty( $shared_secret ) || empty( $signature ) ) {
			return false;
		}
		$expected = self::compute_signature( $raw_body );
		return hash_equals( $expected, strtolower( trim( $signature ) ) );
	}
}
