<?php
/**
 * Plugin Name:       CiviCRM Veriff Membership Verification
 * Plugin URI:         https://example.com
 * Description:        Verifiziert bei CiviCRM-Mitgliedschafts-Abschlüssen (Stripe) den echten Namen des Nutzers per Veriff-Identitätsprüfung, bevor die Mitgliedschaft/Subscription abgeschlossen werden kann.
 * Version:            1.0.0
 * Requires PHP:       7.4
 * Author:              —
 * Text Domain:        civiveriff
 *
 * WICHTIGER HINWEIS:
 * Dieses Plugin ist ein solides Gerüst ("starter kit"), kein fertiges, für jede
 * CiviCRM-Konfiguration blind einsatzbereites Produkt. CiviCRM-Formulare
 * (Contribution Page mit Membership-Block vs. eigenständiges Membership-Signup-
 * Formular) unterscheiden sich je nach Setup. Die Stellen, die du an deine
 * konkrete Formular-Konfiguration anpassen musst, sind mit "ANPASSEN:" markiert.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CIVIVERIFF_VERSION', '1.1.1' );
define( 'CIVIVERIFF_PLUGIN_FILE', __FILE__ );
define( 'CIVIVERIFF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CIVIVERIFF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once CIVIVERIFF_PLUGIN_DIR . 'includes/class-civiveriff-db.php';
require_once CIVIVERIFF_PLUGIN_DIR . 'includes/class-civiveriff-logger.php';
require_once CIVIVERIFF_PLUGIN_DIR . 'includes/class-civiveriff-settings.php';
require_once CIVIVERIFF_PLUGIN_DIR . 'includes/class-civiveriff-api.php';
require_once CIVIVERIFF_PLUGIN_DIR . 'includes/class-civiveriff-rest.php';
require_once CIVIVERIFF_PLUGIN_DIR . 'includes/class-civiveriff-civicrm.php';
require_once CIVIVERIFF_PLUGIN_DIR . 'includes/class-civiveriff-callback.php';

/**
 * Aktivierung: DB-Tabelle für Verifizierungs-Sessions anlegen.
 */
function civiveriff_activate() {
	CiviVeriff_DB::maybe_create_table();
}
register_activation_hook( __FILE__, 'civiveriff_activate' );

/**
 * Plugin initialisieren.
 */
function civiveriff_init() {
	// Automatische Schema-Migration: läuft bei jeder Versionsänderung einmalig,
	// damit spätere Plugin-Updates (z.B. neue DB-Spalten) auch ohne manuelles
	// Deaktivieren/Aktivieren übernommen werden.
	if ( get_option( 'civiveriff_db_version' ) !== CIVIVERIFF_VERSION ) {
		CiviVeriff_DB::maybe_create_table();
		update_option( 'civiveriff_db_version', CIVIVERIFF_VERSION );
	}

	CiviVeriff_Settings::instance();
	CiviVeriff_REST::instance();
	CiviVeriff_Callback::instance();

	// CiviCRM-Hooks nur registrieren, wenn CiviCRM tatsächlich geladen ist.
	if ( function_exists( 'civicrm_initialize' ) ) {
		CiviVeriff_CiviCRM::instance();
	}
}
add_action( 'plugins_loaded', 'civiveriff_init' );
