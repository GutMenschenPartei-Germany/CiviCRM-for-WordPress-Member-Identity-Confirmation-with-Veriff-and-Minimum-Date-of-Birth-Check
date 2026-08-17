<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schreibt Debug-Meldungen in eine eigene Log-Datei innerhalb von
 * wp-content/uploads/ - unabhängig davon, ob WP_DEBUG_LOG auf dem jeweiligen
 * Hosting tatsächlich funktioniert (viele Hoster überschreiben oder ignorieren
 * das). Die Datei ist per .htaccess/index.php gegen direkten Web-Zugriff
 * abgesichert und lässt sich zusätzlich direkt im WP-Backend einsehen
 * (Einstellungen -> CiviCRM Veriff), damit kein Datei-/FTP-Zugriff nötig ist.
 */
class CiviVeriff_Logger {

	public static function log_dir() {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'civiveriff-logs';
	}

	public static function log_file() {
		return self::log_dir() . '/debug.log';
	}

	private static function ensure_dir_protected() {
		$dir = self::log_dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\ndeny from all\n" ); // phpcs:ignore -- lokale Schutzdatei, kein Nutzer-Input.
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore
		}
	}

	public static function log( $message ) {
		if ( ! (bool) CiviVeriff_Settings::get( 'debug_mode', 0 ) ) {
			return;
		}
		self::ensure_dir_protected();
		$line = '[' . current_time( 'mysql' ) . '] ' . ( is_string( $message ) ? $message : wp_json_encode( $message ) ) . "\n";
		file_put_contents( self::log_file(), $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore
	}

	/**
	 * Liefert die letzten $lines Zeilen des Logs (für die Anzeige im Backend).
	 */
	public static function tail( $lines = 200 ) {
		$file = self::log_file();
		if ( ! file_exists( $file ) ) {
			return '';
		}
		$content = file_get_contents( $file ); // phpcs:ignore
		$all     = explode( "\n", trim( $content ) );
		$slice   = array_slice( $all, -1 * $lines );
		return implode( "\n", $slice );
	}

	public static function clear() {
		$file = self::log_file();
		if ( file_exists( $file ) ) {
			unlink( $file ); // phpcs:ignore
		}
	}
}
