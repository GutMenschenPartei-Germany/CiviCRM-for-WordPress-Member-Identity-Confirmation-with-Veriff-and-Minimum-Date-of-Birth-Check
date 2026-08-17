<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kapselt die eigene DB-Tabelle, in der Veriff-Sessions <-> CiviCRM-Kontakte
 * zugeordnet und Ergebnisse gespeichert werden. Eine eigene Tabelle ist nötig,
 * weil der Decision-Webhook von Veriff asynchron eintrifft (oft erst nach dem
 * eigentlichen HTTP-Request des Nutzers) und wir den Status serverseitig
 * abfragen/erzwingen müssen.
 */
class CiviVeriff_DB {

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'civiveriff_sessions';
	}

	public static function maybe_create_table() {
		global $wpdb;
		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id VARCHAR(64) NOT NULL,
			token VARCHAR(64) NOT NULL DEFAULT '',
			contact_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			expected_first_name VARCHAR(191) NOT NULL DEFAULT '',
			expected_last_name VARCHAR(191) NOT NULL DEFAULT '',
			verified_first_name VARCHAR(191) NOT NULL DEFAULT '',
			verified_last_name VARCHAR(191) NOT NULL DEFAULT '',
			verified_dob VARCHAR(20) NOT NULL DEFAULT '',
			age_ok TINYINT(1) NOT NULL DEFAULT 0,
			status VARCHAR(32) NOT NULL DEFAULT 'created',
			name_match TINYINT(1) NOT NULL DEFAULT 0,
			raw_decision LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY session_id (session_id),
			KEY token (token),
			KEY contact_id (contact_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function insert_session( $session_id, $token, $contact_id, $first_name, $last_name ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			self::table_name(),
			array(
				'session_id'           => $session_id,
				'token'                => $token,
				'contact_id'           => (int) $contact_id,
				'expected_first_name'  => $first_name,
				'expected_last_name'   => $last_name,
				'status'               => 'created',
				'created_at'           => $now,
				'updated_at'           => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		return $wpdb->insert_id;
	}

	public static function get_latest_for_token( $token ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE token = %s ORDER BY id DESC LIMIT 1",
				$token
			),
			ARRAY_A
		);
	}

	/**
	 * Verknüpft eine per Token gefundene Session nachträglich mit der echten
	 * CiviCRM-Kontakt-ID, sobald der Kontakt nach dem Formular-Submit angelegt
	 * bzw. bekannt ist (siehe civicrm_postProcess-Hook).
	 */
	public static function attach_contact_to_token( $token, $contact_id ) {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array( 'contact_id' => (int) $contact_id, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'token' => $token ),
			array( '%d', '%s' ),
			array( '%s' )
		);
	}

	public static function get_by_session_id( $session_id ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %s", $session_id ),
			ARRAY_A
		);
	}

	public static function get_latest_for_contact( $contact_id ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE contact_id = %d ORDER BY id DESC LIMIT 1",
				$contact_id
			),
			ARRAY_A
		);
	}

	public static function update_decision( $session_id, $status, $verified_first_name, $verified_last_name, $name_match, $verified_dob, $age_ok, $raw_decision ) {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array(
				'status'              => $status,
				'verified_first_name' => $verified_first_name,
				'verified_last_name'  => $verified_last_name,
				'verified_dob'        => $verified_dob,
				'age_ok'              => $age_ok ? 1 : 0,
				'name_match'          => $name_match ? 1 : 0,
				'raw_decision'        => wp_json_encode( $raw_decision ),
				'updated_at'          => current_time( 'mysql', true ),
			),
			array( 'session_id' => $session_id ),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ),
			array( '%s' )
		);
	}
}
