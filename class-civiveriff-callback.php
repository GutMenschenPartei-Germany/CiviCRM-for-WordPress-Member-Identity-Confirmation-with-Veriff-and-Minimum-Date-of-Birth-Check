<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rendert die Seite, zu der Veriff den Browser nach Abschluss des
 * Verifizierungs-Flows schickt (die "callback"-URL aus
 * CiviVeriff_API::create_session()).
 *
 * Läuft als eigenständige Mini-Seite, OHNE dass eine echte WordPress-Seite
 * mit diesem Slug angelegt werden muss - wir klinken uns per
 * "template_redirect" vor die normale WordPress-Seitenauflösung.
 *
 * Verhalten:
 * - Wenn es ein echtes Popup war (window.opener vorhanden): Fenster automatisch
 *   schließen, damit im ursprünglichen Tab die Status-Abfrage übernimmt.
 * - Wenn es KEIN Popup war (v.a. mobile Browser wandeln window.open() nach
 *   einem asynchronen AJAX-Call oft in eine normale Navigation im selben Tab
 *   um): Button anzeigen, der zurück zur ursprünglichen Formular-Seite führt.
 *   HINWEIS: In diesem Fall gehen bereits eingegebene Formulardaten verloren,
 *   da es sich um eine echte Seiten-Navigation handelt - das lässt sich mit
 *   diesem Popup-Ansatz nicht vollständig vermeiden (siehe README, Abschnitt
 *   "Bekannte Einschränkung auf Mobilgeräten").
 */
class CiviVeriff_Callback {

	const SLUG = 'veriff-verification-complete';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
	}

	public function maybe_render() {
		$path = trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
		if ( self::SLUG !== $path && untrailingslashit( $path ) !== self::SLUG ) {
			return;
		}

		$token     = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$return_to = isset( $_GET['return_to'] ) ? esc_url_raw( rawurldecode( wp_unslash( $_GET['return_to'] ) ) ) : home_url( '/' );

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );

		echo $this->render_html( $token, $return_to ); // phpcs:ignore -- eigenes, kontrolliertes Markup.
		exit;
	}

	private function render_html( $token, $return_to ) {
		ob_start();
		?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Identitätsprüfung abgeschlossen</title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f9fafb; color: #101828; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 1.5em; text-align: center; }
		.civiveriff-callback-box { max-width: 420px; }
		.civiveriff-callback-box h1 { font-size: 1.3em; margin-bottom: 0.4em; }
		.civiveriff-callback-box p { color: #475467; line-height: 1.5; }
		.civiveriff-callback-btn { display: inline-block; margin-top: 1em; padding: 0.7em 1.4em; background: #155eef; color: #fff; text-decoration: none; border-radius: 4px; font-weight: 600; }
	</style>
</head>
<body>
	<div class="civiveriff-callback-box">
		<h1>Identitätsprüfung abgeschlossen</h1>
		<p id="civiveriff-msg-popup">Dieses Fenster schließt sich gleich automatisch. Falls nicht, kannst du es einfach manuell schließen und zum Formular zurückkehren.</p>
		<p id="civiveriff-msg-fallback" style="display:none;">Du kannst jetzt zu deiner Mitgliedsanmeldung zurückkehren, um sie abzuschließen.</p>
		<a id="civiveriff-back-link" class="civiveriff-callback-btn" style="display:none;" href="<?php echo esc_url( $return_to ); ?>">Zurück zur Anmeldung</a>
	</div>
	<script>
		( function () {
			var isPopup = !! ( window.opener && ! window.opener.closed );
			if ( isPopup ) {
				try {
					window.opener.postMessage( { civiveriff: true, token: <?php echo wp_json_encode( $token ); ?> }, '*' );
				} catch ( e ) {}
				window.close();
				// Falls der Browser das automatische Schließen verweigert
				// (z.B. weil das Fenster nicht per Skript geöffnet wurde),
				// nach kurzer Wartezeit den Fallback-Button zeigen.
				setTimeout( showFallback, 1200 );
			} else {
				// Kein echtes Popup - vermutlich mobiler Browser, der
				// window.open() als normale Navigation im selben Tab
				// behandelt hat. Direkt den Rückkehr-Button zeigen.
				showFallback();
			}

			function showFallback() {
				document.getElementById( 'civiveriff-msg-popup' ).style.display = 'none';
				document.getElementById( 'civiveriff-msg-fallback' ).style.display = 'block';
				document.getElementById( 'civiveriff-back-link' ).style.display = 'inline-block';
			}
		} )();
	</script>
</body>
</html>
		<?php
		return ob_get_clean();
	}
}
