<?php
/**
 * Settings export and import.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Admin;

use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Exports all plugin settings as JSON — minus API keys and secrets, which
 * are never exported — and imports with a diff preview before applying.
 * Built for staging-to-production moves.
 */
final class ExportImport {

	/**
	 * The option keys that travel (values sanitised on import).
	 */
	private const EXPORTED_OPTIONS = [ 'eex_settings', 'eex_synced_events', 'eex_mylisting' ];

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'admin_post_eex_export_settings', [ $this, 'export' ] );
		add_action( 'admin_post_eex_import_preview', [ $this, 'preview' ] );
		add_action( 'admin_post_eex_import_apply', [ $this, 'apply' ] );
	}

	/**
	 * The exportable snapshot. No key or secret material, ever.
	 *
	 * @return array<string,mixed>
	 */
	public static function snapshot(): array {
		$data = [
			'plugin'  => 'emailexpert-events',
			'version' => EEX_VERSION,
		];

		foreach ( self::EXPORTED_OPTIONS as $option ) {
			$data['options'][ $option ] = get_option( $option, [] );
		}

		// Connections travel as id + label only — keys never leave the site.
		$data['options']['eex_connections'] = array_map(
			static fn( array $connection ): array => [
				'id'    => (string) ( $connection['id'] ?? '' ),
				'label' => (string) ( $connection['label'] ?? '' ),
			],
			Options::connections()
		);

		// Relay targets travel without their shared secrets.
		$data['options']['eex_relay_urls'] = array_map(
			static fn( array $row ): array => [
				'url'     => (string) ( $row['url'] ?? '' ),
				'actions' => array_values( array_map( 'strval', (array) ( $row['actions'] ?? [] ) ) ),
			],
			array_filter( (array) get_option( 'eex_relay_urls', [] ), 'is_array' )
		);

		return $data;
	}

	/**
	 * Stream the export.
	 */
	public function export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		check_admin_referer( 'eex_export_settings' );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="emailexpert-events-settings.json"' );

		echo wp_json_encode( self::snapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}

	/**
	 * Parse an uploaded file, stash it, show the diff.
	 */
	public function preview(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		check_admin_referer( 'eex_import_settings' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- file upload, validated below.
		$file = $_FILES['eex_import_file'] ?? null;

		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			wp_die( esc_html__( 'No import file received.', 'emailexpert-events' ) );
		}

		$decoded = json_decode( (string) file_get_contents( $file['tmp_name'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.

		if ( ! is_array( $decoded ) || 'emailexpert-events' !== ( $decoded['plugin'] ?? '' ) || ! is_array( $decoded['options'] ?? null ) ) {
			wp_die( esc_html__( 'That file is not an emailexpert Events settings export.', 'emailexpert-events' ) );
		}

		$token = wp_generate_password( 20, false, false );
		set_transient( 'eex_import_' . $token, $decoded, HOUR_IN_SECONDS );

		$this->render_diff( $decoded, $token );
		exit;
	}

	/**
	 * The diff preview screen.
	 *
	 * @param array<string,mixed> $incoming Parsed import.
	 * @param string              $token    Stash token.
	 */
	private function render_diff( array $incoming, string $token ): void {
		$current = self::snapshot();
		?>
		<div class="wrap eex-settings">
			<h1><?php esc_html_e( 'Import settings: preview', 'emailexpert-events' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Nothing has been changed yet. Review the differences, then apply. API keys and secrets are never part of an export; existing keys on this site are kept.', 'emailexpert-events' ); ?></p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Setting group', 'emailexpert-events' ); ?></th>
						<th><?php esc_html_e( 'Change', 'emailexpert-events' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( (array) $incoming['options'] as $option => $value ) : ?>
						<?php
						$existing  = $current['options'][ $option ] ?? null;
						$unchanged = wp_json_encode( $existing ) === wp_json_encode( $value );
						?>
						<tr>
							<td><code><?php echo esc_html( (string) $option ); ?></code></td>
							<td>
								<?php if ( $unchanged ) : ?>
									<?php esc_html_e( 'Unchanged', 'emailexpert-events' ); ?>
								<?php else : ?>
									<details>
										<summary><?php esc_html_e( 'Will change — view values', 'emailexpert-events' ); ?></summary>
										<p><strong><?php esc_html_e( 'Current', 'emailexpert-events' ); ?></strong></p>
										<pre><?php echo esc_html( (string) wp_json_encode( $existing, JSON_PRETTY_PRINT ) ); ?></pre>
										<p><strong><?php esc_html_e( 'Incoming', 'emailexpert-events' ); ?></strong></p>
										<pre><?php echo esc_html( (string) wp_json_encode( $value, JSON_PRETTY_PRINT ) ); ?></pre>
									</details>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eex_import_apply" />
				<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>" />
				<?php wp_nonce_field( 'eex_import_apply' ); ?>
				<?php submit_button( __( 'Apply import', 'emailexpert-events' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Apply a previewed import.
	 */
	public function apply(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		check_admin_referer( 'eex_import_apply' );

		$token   = isset( $_POST['token'] ) ? sanitize_key( $_POST['token'] ) : '';
		$stashed = get_transient( 'eex_import_' . $token );

		if ( ! is_array( $stashed ) ) {
			wp_die( esc_html__( 'Import expired; upload the file again.', 'emailexpert-events' ) );
		}

		delete_transient( 'eex_import_' . $token );

		self::apply_snapshot( $stashed );

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'options-general.php?page=emailexpert-events' ) ) );
		exit;
	}

	/**
	 * Apply an import snapshot to this site's options. Keys and secrets on
	 * this site are always preserved.
	 *
	 * @param array<string,mixed> $snapshot Parsed export.
	 */
	public static function apply_snapshot( array $snapshot ): void {
		$options = (array) ( $snapshot['options'] ?? [] );

		if ( isset( $options['eex_settings'] ) && is_array( $options['eex_settings'] ) ) {
			// Only known keys, coerced against defaults.
			$clean = array_intersect_key( $options['eex_settings'], Options::defaults() );
			Options::update_settings( $clean );
		}

		if ( isset( $options['eex_synced_events'] ) && is_array( $options['eex_synced_events'] ) ) {
			update_option( Options::SYNCED_EVENTS, $options['eex_synced_events'], false );
			\Emailexpert\Events\Sync\Scheduler::sync_schedule_state();
		}

		if ( isset( $options['eex_mylisting'] ) && is_array( $options['eex_mylisting'] ) ) {
			update_option( 'eex_mylisting', $options['eex_mylisting'], false );
		}

		// Connections: merge by ID, keeping every existing key; imported
		// connections without a local counterpart arrive keyless.
		if ( isset( $options['eex_connections'] ) && is_array( $options['eex_connections'] ) ) {
			$existing = (array) get_option( Options::CONNECTIONS, [] );
			$by_id    = [];
			foreach ( $existing as $row ) {
				if ( is_array( $row ) && ! empty( $row['id'] ) ) {
					$by_id[ (string) $row['id'] ] = $row;
				}
			}

			$merged = [];
			foreach ( $options['eex_connections'] as $row ) {
				if ( ! is_array( $row ) || '' === (string) ( $row['id'] ?? '' ) ) {
					continue;
				}
				$id       = sanitize_key( (string) $row['id'] );
				$merged[] = [
					'id'      => $id,
					'label'   => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
					'api_key' => (string) ( $by_id[ $id ]['api_key'] ?? '' ),
				];
			}
			update_option( Options::CONNECTIONS, $merged, false );
		}

		// Relay URLs: merge secrets from any matching local URL.
		if ( isset( $options['eex_relay_urls'] ) && is_array( $options['eex_relay_urls'] ) ) {
			$existing_secrets = [];
			foreach ( (array) get_option( 'eex_relay_urls', [] ) as $row ) {
				if ( is_array( $row ) && '' !== (string) ( $row['url'] ?? '' ) ) {
					$existing_secrets[ (string) $row['url'] ] = (string) ( $row['secret'] ?? '' );
				}
			}

			$merged = [];
			foreach ( $options['eex_relay_urls'] as $row ) {
				if ( ! is_array( $row ) || '' === (string) ( $row['url'] ?? '' ) ) {
					continue;
				}
				$url      = esc_url_raw( (string) $row['url'] );
				$merged[] = [
					'url'     => $url,
					'secret'  => $existing_secrets[ $url ] ?? '',
					'actions' => array_values( array_map( 'sanitize_key', (array) ( $row['actions'] ?? [] ) ) ),
				];
			}
			update_option( 'eex_relay_urls', $merged, false );
		}
	}
}
