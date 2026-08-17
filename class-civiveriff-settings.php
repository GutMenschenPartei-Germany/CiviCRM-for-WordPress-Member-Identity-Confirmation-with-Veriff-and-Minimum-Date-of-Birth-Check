<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CiviVeriff_Settings {

	const OPTION_KEY = 'civiveriff_settings';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'maybe_clear_log' ) );
	}

	public function maybe_clear_log() {
		if ( isset( $_POST['civiveriff_clear_log'] ) && current_user_can( 'manage_options' ) && check_admin_referer( 'civiveriff_clear_log' ) ) {
			CiviVeriff_Logger::clear();
			wp_safe_redirect( admin_url( 'options-general.php?page=civiveriff-settings&cleared=1' ) );
			exit;
		}
	}

	public static function get( $key, $default = '' ) {
		$options = get_option( self::OPTION_KEY, array() );
		return isset( $options[ $key ] ) ? $options[ $key ] : $default;
	}

	public function add_settings_page() {
		add_options_page(
			'CiviCRM Veriff Verification',
			'CiviCRM Veriff',
			'manage_options',
			'civiveriff-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'civiveriff_settings_group', self::OPTION_KEY, array( $this, 'sanitize' ) );
	}

	public function sanitize( $input ) {
		$output                     = array();
		$output['api_key']          = sanitize_text_field( $input['api_key'] ?? '' );
		$output['shared_secret']    = sanitize_text_field( $input['shared_secret'] ?? '' );
		$output['base_url']         = untrailingslashit( sanitize_text_field( $input['base_url'] ?? 'https://stationapi.veriff.com' ) );
		$output['form_classes']     = sanitize_text_field( $input['form_classes'] ?? '' );
		$output['require_exact_match'] = ! empty( $input['require_exact_match'] ) ? 1 : 0;
		$output['debug_mode']       = ! empty( $input['debug_mode'] ) ? 1 : 0;
		$output['require_min_age'] = ! empty( $input['require_min_age'] ) ? 1 : 0;
		$output['minimum_age']     = isset( $input['minimum_age'] ) ? max( 0, (int) $input['minimum_age'] ) : 18;
		return $output;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$api_key       = self::get( 'api_key' );
		$shared_secret = self::get( 'shared_secret' );
		$base_url      = self::get( 'base_url', 'https://stationapi.veriff.com' );
		$form_classes  = self::get( 'form_classes', 'CRM_Contribute_Form_Contribution_Main' );
		$require_exact = self::get( 'require_exact_match', 1 );
		$webhook_url   = rest_url( 'civiveriff/v1/webhook' );
		?>
		<div class="wrap">
			<h1>CiviCRM Veriff Membership Verification</h1>
			<p>Trage hier deine Veriff-Zugangsdaten ein (Veriff Customer Portal &rarr; Integrations). Die API-Basis-URL ist stationspezifisch (z.B. <code>https://stationapi.veriff.com</code>).</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'civiveriff_settings_group' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="api_key">Veriff API Key (X-AUTH-CLIENT)</label></th>
						<td><input type="text" id="api_key" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_key]" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="shared_secret">Veriff Shared Secret (für HMAC-Signatur)</label></th>
						<td><input type="text" id="shared_secret" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[shared_secret]" value="<?php echo esc_attr( $shared_secret ); ?>" class="regular-text" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="base_url">Veriff API Basis-URL</label></th>
						<td><input type="text" id="base_url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[base_url]" value="<?php echo esc_attr( $base_url ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="form_classes">CiviCRM-Formularklassen</label></th>
						<td>
							<input type="text" id="form_classes" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[form_classes]" value="<?php echo esc_attr( $form_classes ); ?>" class="regular-text">
							<p class="description">Kommagetrennte Liste der CiviCRM-Formular-Klassen, auf denen die Verifizierung eingeblendet werden soll, z.B.<br>
							<code>CRM_Contribute_Form_Contribution_Main</code> (Contribution-Page mit Membership-Block) oder<br>
							<code>CRM_Member_Form_Membership</code> (Backend) – für dein Frontend-Signup i.d.R. die Contribution-Page-Klasse.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Exakte Namensübereinstimmung verlangen</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[require_exact_match]" value="1" <?php checked( $require_exact, 1 ); ?>>
								Formular nur freigeben, wenn der von Veriff aus dem Ausweisdokument extrahierte Name mit dem im CiviCRM-Formular eingegebenen Namen übereinstimmt (statt nur "Identität grundsätzlich verifiziert").
							</label>
						</td>
					</tr>
					<tr>
					<th scope="row">Mindestalter verlangen</th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[require_min_age]" value="1" <?php checked( self::get( 'require_min_age', 1 ), 1 ); ?>>
							Formular nur freigeben, wenn das von Veriff aus dem Ausweisdokument gelesene Geburtsdatum mindestens
							<input type="number" min="0" max="120" style="width:4em;" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[minimum_age]" value="<?php echo esc_attr( self::get( 'minimum_age', 18 ) ); ?>">
							Jahre alt ist.
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">Debug-Modus</th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[debug_mode]" value="1" <?php checked( self::get( 'debug_mode', 0 ), 1 ); ?>>
							Protokolliert eingehende Webhook-Aufrufe und Session-Erstellungen in <code>debug.log</code> (setzt <code>WP_DEBUG_LOG</code> voraus). Nur zur Fehlersuche aktivieren, danach wieder ausschalten.
						</label>
					</td>
				</tr>
			</table>
				<?php submit_button(); ?>
			</form>

			<hr>
			<h2>Webhook-URL (in Veriff Customer Portal eintragen)</h2>
			<p><code><?php echo esc_html( $webhook_url ); ?></code></p>
			<p class="description">Trage diese URL im Veriff Customer Portal unter "Decision Webhook" ein. Veriff signiert jeden Aufruf mit deinem Shared Secret (Header <code>X-HMAC-SIGNATURE</code>) – das Plugin prüft diese Signatur.</p>

			<hr>
			<h2>Debug-Log</h2>
			<p class="description">
				Funktioniert unabhängig von <code>WP_DEBUG_LOG</code>/Hosting-Konfiguration. Wird nur befüllt, solange der Debug-Modus oben aktiviert ist.
				Enthält ggf. personenbezogene Daten (Namen aus dem Veriff-Webhook) – nach der Fehlersuche bitte leeren.
			</p>
			<form method="post" style="margin-bottom: 0.8em;">
				<?php wp_nonce_field( 'civiveriff_clear_log' ); ?>
				<button type="submit" name="civiveriff_clear_log" value="1" class="button">Log leeren</button>
			</form>
			<textarea readonly rows="20" style="width:100%; max-width:900px; font-family:monospace; font-size:12px;"><?php echo esc_textarea( CiviVeriff_Logger::tail( 300 ) ? CiviVeriff_Logger::tail( 300 ) : '(noch keine Einträge)' ); ?></textarea>
		</div>
		<?php
	}
}
