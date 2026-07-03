<?php
/**
 * Setup and import wizard.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Admin;

use Emailexpert\Events\Api\Discovery;
use Emailexpert\Events\Options;
use Emailexpert\Events\Sync\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Five-step wizard: connect, choose events, scope, dry-run preview, confirm
 * and sync. Offered via a dismissible activation notice, re-runnable from
 * settings, never forced. It writes straight into the standard settings
 * options and keeps no separate state.
 */
final class Wizard {

	private const SLUG = 'emailexpert-events-setup';

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_notices', [ $this, 'offer_notice' ] );
		add_action( 'admin_post_eex_wizard_save', [ $this, 'save_step' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Register the wizard page (plain submenu; nothing is forced).
	 */
	public function add_menu(): void {
		add_submenu_page(
			'options-general.php',
			__( 'emailexpert Events setup', 'emailexpert-events' ),
			__( 'EEX Setup', 'emailexpert-events' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Wizard assets ride on the shared admin script.
	 *
	 * @param string $hook Current admin screen.
	 */
	public function enqueue( string $hook ): void {
		if ( 'settings_page_' . self::SLUG !== $hook ) {
			return;
		}

		AdminAssets::enqueue();
	}

	/**
	 * Dismissible activation notice offering the wizard.
	 */
	public function offer_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! get_option( 'eex_wizard_notice' ) || get_option( 'eex_wizard_done' ) ) {
			return;
		}

		// Dismiss handling.
		if ( isset( $_GET['eex_dismiss_wizard'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'eex_dismiss_wizard' ) ) {
			delete_option( 'eex_wizard_notice' );

			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s <a class="button button-primary" href="%s">%s</a> <a href="%s">%s</a></p></div>',
			esc_html__( 'emailexpert Events is ready. Connect your HeySummit account and choose what to import.', 'emailexpert-events' ),
			esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ),
			esc_html__( 'Run setup wizard', 'emailexpert-events' ),
			esc_url( wp_nonce_url( add_query_arg( 'eex_dismiss_wizard', 1 ), 'eex_dismiss_wizard' ) ),
			esc_html__( 'Dismiss', 'emailexpert-events' )
		);
	}

	/**
	 * The current step from the query string. Step 0 (choose Full or Lite)
	 * is the entry point until a mode has been chosen.
	 */
	private function step(): int {
		$default = (bool) Options::setting( 'mode_chosen' ) ? 1 : 0;
		$maximum = Options::is_lite() ? 3 : 5;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display routing only.
		return isset( $_GET['step'] ) ? max( 0, min( $maximum, (int) $_GET['step'] ) ) : $default;
	}

	/**
	 * URL for a step.
	 *
	 * @param int $step Step number.
	 */
	private function step_url( int $step ): string {
		return admin_url( 'options-general.php?page=' . self::SLUG . '&step=' . $step );
	}

	/**
	 * Render the wizard.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		$step = $this->step();
		$lite = Options::is_lite();
		?>
		<div class="wrap eex-settings eex-wizard">
			<h1><?php esc_html_e( 'emailexpert Events setup', 'emailexpert-events' ); ?></h1>
			<ol class="eex-wizard-steps" <?php echo 0 === $step ? 'start="0"' : ''; ?>>
				<?php
				$labels = $lite
					? [
						__( 'Connect', 'emailexpert-events' ),
						__( 'Choose events', 'emailexpert-events' ),
						__( 'Done', 'emailexpert-events' ),
					]
					: [
						__( 'Connect', 'emailexpert-events' ),
						__( 'Choose events', 'emailexpert-events' ),
						__( 'Scope', 'emailexpert-events' ),
						__( 'Preview', 'emailexpert-events' ),
						__( 'Import', 'emailexpert-events' ),
					];

				if ( 0 === $step ) {
					array_unshift( $labels, __( 'Mode', 'emailexpert-events' ) );
				}

				foreach ( array_values( $labels ) as $index => $label ) {
					$number = 0 === $step ? $index : $index + 1;
					printf(
						'<li %s>%s</li>',
						$number === $step ? 'aria-current="step" class="eex-step-current"' : '',
						esc_html( $label )
					);
				}
				?>
			</ol>
			<?php
			if ( 0 === $step ) {
				$this->render_mode_choice();
			} elseif ( $lite ) {
				match ( $step ) {
					2       => $this->render_lite_events(),
					3       => $this->render_lite_done(),
					default => $this->render_connect(),
				};
			} else {
				match ( $step ) {
					2       => $this->render_choose_events(),
					3       => $this->render_scope( false ),
					4       => $this->render_preview(),
					5       => $this->render_import(),
					default => $this->render_connect(),
				};
			}
			?>
		</div>
		<?php
	}

	/**
	 * Step 0: choose the operating mode, with a plain comparison of what
	 * Lite gives up and keeps.
	 */
	private function render_mode_choice(): void {
		$this->form_open( 0 );
		?>
		<h2><?php esc_html_e( 'Choose how the plugin should work', 'emailexpert-events' ); ?></h2>

		<p>
			<?php
			esc_html_e(
				'Full mode syncs HeySummit events, sessions and speakers into WordPress as content: you get indexable local pages with Schema.org markup (SEO/GEO), a replays library, webhooks with registration attribution, the MyListing bridge and Elementor dynamic tags. Lite mode displays live HeySummit data without storing any of it: no local pages and therefore no SEO/GEO content, no replays library, no MyListing, no Elementor dynamic tags and no webhooks — but you keep the live display components, calendar downloads, inline schema on the blocks themselves, and the WooCommerce bridge. Lite suits a site that wants a live feed of the next sessions, not a mirror.',
				'emailexpert-events'
			);
			?>
		</p>

		<p>
			<label>
				<input type="radio" name="mode" value="full" checked />
				<strong><?php esc_html_e( 'Full — sync content into WordPress', 'emailexpert-events' ); ?></strong>
			</label>
		</p>
		<p>
			<label>
				<input type="radio" name="mode" value="lite" />
				<strong><?php esc_html_e( 'Lite — display live data only', 'emailexpert-events' ); ?></strong>
			</label>
		</p>

		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Continue', 'emailexpert-events' ); ?></button></p>
		<p class="description"><?php esc_html_e( 'The mode can be changed later in the plugin settings.', 'emailexpert-events' ); ?></p>
		</form>
		<?php
	}

	/**
	 * Lite step 2: pick the HeySummit events the components display.
	 */
	private function render_lite_events(): void {
		$connections = Options::connections();
		$selected    = array_map( 'strval', (array) Options::setting( 'lite_events' ) );
		?>
		<h2><?php esc_html_e( 'Choose the HeySummit events to display', 'emailexpert-events' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Nothing is imported: the display components fetch these events live and cache the responses briefly.', 'emailexpert-events' ); ?></p>

		<?php foreach ( $connections as $connection ) : ?>
			<?php
			$conn_id = (string) ( $connection['id'] ?? '' );
			if ( '' === $conn_id ) {
				continue;
			}
			?>
			<p>
				<button type="button" class="button eex-wizard-load-events" data-connection="<?php echo esc_attr( $conn_id ); ?>"><?php esc_html_e( 'Load events from HeySummit', 'emailexpert-events' ); ?></button>
				<span class="eex-inline-result" aria-live="polite"></span>
			</p>
		<?php endforeach; ?>

		<?php $this->form_open( 2 ); ?>
		<div id="eex-wizard-events">
			<?php
			$available = (array) get_option( 'eex_available_events', [] );
			foreach ( $connections as $connection ) {
				$conn_id = (string) ( $connection['id'] ?? '' );
				foreach ( (array) ( $available[ $conn_id ] ?? [] ) as $event ) {
					$key = $conn_id . '|' . (string) $event['id'];
					printf(
						'<label class="eex-wizard-event"><input type="checkbox" name="lite_events[]" value="%s" %s /> <strong>%s</strong> <code>%s</code> <span class="description">%s</span></label><br />',
						esc_attr( $key ),
						checked( in_array( $key, $selected, true ), true, false ),
						esc_html( (string) ( $event['title'] ?? $event['id'] ) ),
						esc_html( (string) $event['id'] ),
						esc_html( trim( (string) ( $event['dates'] ?? '' ) ) )
					);
				}
			}
			?>
		</div>
		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Finish', 'emailexpert-events' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Lite step 3: done.
	 */
	private function render_lite_done(): void {
		?>
		<h2><?php esc_html_e( 'Lite mode is ready', 'emailexpert-events' ); ?></h2>
		<p><?php esc_html_e( 'Add the blocks or shortcodes to any page; they render live HeySummit data through a short server-side cache. Nothing has been imported and nothing will be.', 'emailexpert-events' ); ?></p>
		<ul>
			<li><a href="<?php echo esc_url( admin_url( 'options-general.php?page=emailexpert-events' ) ); ?>"><?php esc_html_e( 'Plugin settings', 'emailexpert-events' ); ?></a></li>
			<li><a href="https://github.com/id12y/bridge-heysummit-wp/blob/main/README.md#shortcode-and-block-reference"><?php esc_html_e( 'Shortcode and block reference', 'emailexpert-events' ); ?></a></li>
			<?php if ( class_exists( 'WooCommerce' ) ) : ?>
				<li><a href="<?php echo esc_url( admin_url( 'options-general.php?page=emailexpert-events-bridge' ) ); ?>"><?php esc_html_e( 'WooCommerce detected: map products to HeySummit tickets (works in Lite exactly as in Full).', 'emailexpert-events' ); ?></a></li>
			<?php endif; ?>
		</ul>
		<?php
	}

	/**
	 * Step form wrapper.
	 *
	 * @param int $step Step being saved.
	 */
	private function form_open( int $step ): void {
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="eex_wizard_save" />';
		printf( '<input type="hidden" name="wizard_step" value="%d" />', (int) $step );
		wp_nonce_field( 'eex_wizard' );
	}

	/**
	 * Step 1: connect.
	 */
	private function render_connect(): void {
		$connections = Options::connections();
		$connection  = $connections[0] ?? [
			'id'      => '',
			'label'   => __( 'Primary', 'emailexpert-events' ),
			'api_key' => '',
		];
		$has_key     = '' !== (string) ( $connection['api_key'] ?? '' );

		$this->form_open( 1 );
		?>
		<h2><?php esc_html_e( 'Connect to HeySummit', 'emailexpert-events' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Paste your HeySummit API key (Business plan). Additional connections can be added later in the main settings.', 'emailexpert-events' ); ?></p>

		<input type="hidden" name="connection_id" value="<?php echo esc_attr( (string) ( $connection['id'] ?? '' ) ); ?>" />
		<p>
			<label for="eex-wizard-key"><strong><?php esc_html_e( 'API key', 'emailexpert-events' ); ?></strong></label><br />
			<?php if ( ! empty( $connection['from_constant'] ) ) : ?>
				<input type="password" id="eex-wizard-key" disabled value="****" class="regular-text" />
				<span class="description"><?php esc_html_e( 'Defined by EEX_HEYSUMMIT_API_KEY in wp-config.php.', 'emailexpert-events' ); ?></span>
			<?php else : ?>
				<input type="password" id="eex-wizard-key" name="api_key" value="" class="regular-text" autocomplete="new-password"
					placeholder="<?php echo $has_key ? esc_attr__( 'Key saved (leave blank to keep)', 'emailexpert-events' ) : esc_attr__( 'Paste your HeySummit API key', 'emailexpert-events' ); ?>" />
			<?php endif; ?>
		</p>
		<p>
			<button type="submit" class="button"><?php esc_html_e( 'Save key', 'emailexpert-events' ); ?></button>
			<?php if ( '' !== (string) ( $connection['id'] ?? '' ) ) : ?>
				<button type="button" class="button eex-test-connection" data-connection="<?php echo esc_attr( (string) $connection['id'] ); ?>"><?php esc_html_e( 'Test connection', 'emailexpert-events' ); ?></button>
			<?php endif; ?>
			<span class="eex-inline-result" aria-live="polite"></span>
		</p>
		</form>

		<?php
		if ( '' !== (string) ( $connection['id'] ?? '' ) && ! empty( Discovery::stored_report( (string) $connection['id'] ) ) ) {
			$report     = Discovery::stored_report( (string) $connection['id'] );
			$mismatches = 0;
			foreach ( $report as $row ) {
				$mismatches += count( (array) ( $row['missing'] ?? [] ) ) + count( (array) ( $row['type_mismatch'] ?? [] ) );
			}
			printf(
				'<p>%s</p>',
				esc_html(
					0 === $mismatches
						? __( 'Discovery: all expected API fields present.', 'emailexpert-events' )
						: sprintf( /* translators: %d: mismatch count. */ __( 'Discovery: %d shape mismatch(es) — see the diagnostics panel in the main settings.', 'emailexpert-events' ), $mismatches )
				)
			);
			printf(
				'<p><a class="button button-primary" href="%s">%s</a></p>',
				esc_url( $this->step_url( 2 ) ),
				esc_html__( 'Continue: choose events', 'emailexpert-events' )
			);
		}
	}

	/**
	 * Step 2: choose events (title, dates, session counts from the API).
	 */
	private function render_choose_events(): void {
		$connections = Options::connections();
		$configured  = Options::synced_events();
		?>
		<h2><?php esc_html_e( 'Choose the HeySummit events to sync', 'emailexpert-events' ); ?></h2>
		<?php foreach ( $connections as $connection ) : ?>
			<?php
			$conn_id = (string) ( $connection['id'] ?? '' );
			if ( '' === $conn_id ) {
				continue;
			}
			?>
			<p>
				<button type="button" class="button eex-wizard-load-events" data-connection="<?php echo esc_attr( $conn_id ); ?>"><?php esc_html_e( 'Load events from HeySummit', 'emailexpert-events' ); ?></button>
				<span class="eex-inline-result" aria-live="polite"></span>
			</p>
		<?php endforeach; ?>

		<?php $this->form_open( 2 ); ?>
		<div id="eex-wizard-events">
			<?php
			$available = (array) get_option( 'eex_available_events', [] );
			foreach ( $connections as $connection ) {
				$conn_id = (string) ( $connection['id'] ?? '' );
				foreach ( (array) ( $available[ $conn_id ] ?? [] ) as $event ) {
					$key     = $conn_id . '|' . (string) $event['id'];
					$checked = ! empty( $configured[ $key ]['enabled'] );
					printf(
						'<label class="eex-wizard-event"><input type="checkbox" name="events[]" value="%s" %s /> <strong>%s</strong> <code>%s</code> <span class="description">%s %s</span></label><br />',
						esc_attr( $key ),
						checked( $checked, true, false ),
						esc_html( (string) ( $event['title'] ?? $event['id'] ) ),
						esc_html( (string) $event['id'] ),
						esc_html( trim( (string) ( $event['dates'] ?? '' ) ) ),
						'' !== (string) ( $event['sessions'] ?? '' )
							? esc_html( sprintf( /* translators: %s: session count. */ __( '· %s sessions', 'emailexpert-events' ), (string) $event['sessions'] ) )
							: ''
					);
				}
			}
			?>
		</div>
		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Continue: scope', 'emailexpert-events' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Steps 3 and 4 share the scope controls.
	 *
	 * @param bool $preview Render the live dry-run preview alongside.
	 */
	private function render_scope( bool $preview ): void {
		$configured = Options::synced_events();
		$enabled    = array_filter( $configured, static fn( $c ) => ! empty( $c['enabled'] ) );

		if ( empty( $enabled ) ) {
			printf( '<p>%s <a href="%s">%s</a></p>', esc_html__( 'No events selected yet.', 'emailexpert-events' ), esc_url( $this->step_url( 2 ) ), esc_html__( 'Choose events first.', 'emailexpert-events' ) );

			return;
		}

		$this->form_open( $preview ? 4 : 3 );

		echo '<h2>' . ( $preview ? esc_html__( 'Preview the import', 'emailexpert-events' ) : esc_html__( 'Scope each event', 'emailexpert-events' ) ) . '</h2>';

		if ( $preview ) {
			echo '<p class="description">' . esc_html__( 'Counts come from a GET-only dry run and update as you adjust the scope. Nothing is written until you confirm.', 'emailexpert-events' ) . '</p>';
		}

		$categories = (array) get_option( 'eex_available_categories', [] );

		foreach ( $enabled as $key => $raw_config ) {
			$config                 = Options::normalise_event_config( (array) $raw_config );
			[ $conn_id, $event_id ] = array_pad( explode( '|', (string) $key, 2 ), 2, '' );
			$field                  = 'scope[' . $key . ']';
			$cats                   = (array) ( $categories[ $key ] ?? [] );
			?>
			<div class="eex-event-row card eex-wizard-scope" data-connection="<?php echo esc_attr( $conn_id ); ?>" data-event="<?php echo esc_attr( $event_id ); ?>">
				<h3><?php echo esc_html( (string) ( $config['title'] ?: $event_id ) ); ?></h3>
				<div class="eex-event-options">
					<label>
						<?php esc_html_e( 'Future sessions:', 'emailexpert-events' ); ?>
						<select name="<?php echo esc_attr( $field ); ?>[future_mode]" class="eex-scope-input">
							<option value="all" <?php selected( $config['future_mode'], 'all' ); ?>><?php esc_html_e( 'All', 'emailexpert-events' ); ?></option>
							<option value="none" <?php selected( $config['future_mode'], 'none' ); ?>><?php esc_html_e( 'None', 'emailexpert-events' ); ?></option>
						</select>
					</label>
					<label>
						<?php esc_html_e( 'Past sessions:', 'emailexpert-events' ); ?>
						<select name="<?php echo esc_attr( $field ); ?>[past_mode]" class="eex-scope-input">
							<option value="all" <?php selected( $config['past_mode'], 'all' ); ?>><?php esc_html_e( 'All', 'emailexpert-events' ); ?></option>
							<option value="none" <?php selected( $config['past_mode'], 'none' ); ?>><?php esc_html_e( 'None', 'emailexpert-events' ); ?></option>
							<option value="recent" <?php selected( $config['past_mode'], 'recent' ); ?>><?php esc_html_e( 'Most recent N', 'emailexpert-events' ); ?></option>
							<option value="since" <?php selected( $config['past_mode'], 'since' ); ?>><?php esc_html_e( 'Since a date', 'emailexpert-events' ); ?></option>
						</select>
						<input type="number" min="0" class="eex-scope-input" name="<?php echo esc_attr( $field ); ?>[past_n]" value="<?php echo esc_attr( (string) (int) $config['past_n'] ); ?>" aria-label="<?php esc_attr_e( 'Most recent N', 'emailexpert-events' ); ?>" />
						<input type="date" class="eex-scope-input" name="<?php echo esc_attr( $field ); ?>[past_since]" value="<?php echo esc_attr( (string) $config['past_since'] ); ?>" aria-label="<?php esc_attr_e( 'Since date', 'emailexpert-events' ); ?>" />
					</label>
					<label>
						<?php esc_html_e( 'Category filter:', 'emailexpert-events' ); ?>
						<select name="<?php echo esc_attr( $field ); ?>[cat_filter_mode]" class="eex-scope-input">
							<option value="" <?php selected( $config['cat_filter_mode'], '' ); ?>><?php esc_html_e( 'All categories', 'emailexpert-events' ); ?></option>
							<option value="include" <?php selected( $config['cat_filter_mode'], 'include' ); ?>><?php esc_html_e( 'Only these', 'emailexpert-events' ); ?></option>
							<option value="exclude" <?php selected( $config['cat_filter_mode'], 'exclude' ); ?>><?php esc_html_e( 'All except these', 'emailexpert-events' ); ?></option>
						</select>
					</label>
					<button type="button" class="button-link eex-load-categories" data-connection="<?php echo esc_attr( $conn_id ); ?>" data-event="<?php echo esc_attr( $event_id ); ?>"><?php esc_html_e( 'Refresh categories', 'emailexpert-events' ); ?></button>
					<?php if ( ! empty( $cats ) ) : ?>
						<fieldset class="eex-cat-filter">
							<legend class="screen-reader-text"><?php esc_html_e( 'Categories', 'emailexpert-events' ); ?></legend>
							<?php foreach ( $cats as $cat ) : ?>
								<label>
									<input type="checkbox" class="eex-scope-input" name="<?php echo esc_attr( $field ); ?>[cat_filter][]" value="<?php echo esc_attr( (string) ( $cat['id'] ?? '' ) ); ?>"
										<?php checked( in_array( (string) ( $cat['id'] ?? '' ), array_map( 'strval', (array) $config['cat_filter'] ), true ) ); ?> />
									<?php echo esc_html( (string) ( $cat['title'] ?? '' ) ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
					<?php endif; ?>
					<label>
						<?php esc_html_e( 'New posts:', 'emailexpert-events' ); ?>
						<select name="<?php echo esc_attr( $field ); ?>[import_status]" class="eex-scope-input">
							<option value="publish" <?php selected( $config['import_status'], 'publish' ); ?>><?php esc_html_e( 'Publish immediately', 'emailexpert-events' ); ?></option>
							<option value="pending" <?php selected( $config['import_status'], 'pending' ); ?>><?php esc_html_e( 'Pending review', 'emailexpert-events' ); ?></option>
						</select>
					</label>
					<label><input type="checkbox" class="eex-scope-input" name="<?php echo esc_attr( $field ); ?>[photos]" value="1" <?php checked( ! empty( $config['photos'] ) ); ?> /> <?php esc_html_e( 'Sideload photos', 'emailexpert-events' ); ?></label>
				</div>
				<?php if ( $preview ) : ?>
					<p class="eex-wizard-preview" aria-live="polite"><em><?php esc_html_e( 'Calculating…', 'emailexpert-events' ); ?></em></p>
				<?php endif; ?>
			</div>
			<?php
		}
		?>
		<p>
			<button type="submit" class="button button-primary">
				<?php echo $preview ? esc_html__( 'Confirm and import', 'emailexpert-events' ) : esc_html__( 'Continue: preview', 'emailexpert-events' ); ?>
			</button>
		</p>
		</form>
		<?php
	}

	/**
	 * Step 4: scope with live dry-run preview.
	 */
	private function render_preview(): void {
		$this->render_scope( true );
	}

	/**
	 * Step 5: run the initial sync with progress from the sync log.
	 */
	private function render_import(): void {
		?>
		<h2><?php esc_html_e( 'Importing', 'emailexpert-events' ); ?></h2>
		<p class="description"><?php esc_html_e( 'The initial sync is running in the background. Progress below comes from the sync log.', 'emailexpert-events' ); ?></p>
		<div id="eex-wizard-progress" data-eex-progress="1" aria-live="polite"><em><?php esc_html_e( 'Waiting for the first log entry…', 'emailexpert-events' ); ?></em></div>

		<div id="eex-wizard-complete" hidden>
			<h2><?php esc_html_e( 'All done', 'emailexpert-events' ); ?></h2>
			<ul>
				<li><a href="<?php echo esc_url( admin_url( 'options-general.php?page=emailexpert-events' ) ); ?>"><?php esc_html_e( 'Plugin settings', 'emailexpert-events' ); ?></a></li>
				<li><a href="https://github.com/id12y/bridge-heysummit-wp/blob/main/README.md#shortcode-and-block-reference"><?php esc_html_e( 'Shortcode and block reference', 'emailexpert-events' ); ?></a></li>
				<?php if ( did_action( 'elementor/loaded' ) || defined( 'ELEMENTOR_VERSION' ) ) : ?>
					<li><?php esc_html_e( 'Elementor detected: widgets are in the "emailexpert Events" category.', 'emailexpert-events' ); ?></li>
				<?php endif; ?>
				<?php if ( \Emailexpert\Events\MyListing\Module::detected() ) : ?>
					<li><a href="<?php echo esc_url( admin_url( 'options-general.php?page=emailexpert-events-bridge' ) ); ?>"><?php esc_html_e( 'MyListing detected: configure the listings bridge.', 'emailexpert-events' ); ?></a></li>
				<?php endif; ?>
				<?php if ( class_exists( 'WooCommerce' ) ) : ?>
					<li><a href="<?php echo esc_url( admin_url( 'options-general.php?page=emailexpert-events-bridge' ) ); ?>"><?php esc_html_e( 'WooCommerce detected: map products to HeySummit tickets.', 'emailexpert-events' ); ?></a></li>
				<?php endif; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Save a wizard step and advance.
	 */
	public function save_step(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		check_admin_referer( 'eex_wizard' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above; sanitised field-by-field.
		$step = isset( $_POST['wizard_step'] ) ? (int) $_POST['wizard_step'] : 1;
		$lite = Options::is_lite();

		switch ( $step ) {
			case 0:
				$next = $this->save_mode_choice();
				break;

			case 1:
				$this->save_connection();
				$next = 1; // Stay: the operator tests the connection, then continues.
				break;

			case 2:
				if ( $lite ) {
					$this->save_lite_events();
					$next = 3;
				} else {
					$this->save_selected_events();
					$next = 3;
				}
				break;

			case 3:
			case 4:
				if ( $lite ) {
					$next = 3;
					break;
				}
				$this->save_scopes();
				$next = $step + 1;
				break;

			default:
				$next = $lite ? 3 : 5;
		}

		if ( ! $lite && 5 === $next && 4 === $step ) {
			// Confirmed: schedule cron state and kick the initial sync.
			Scheduler::sync_schedule_state();
			Scheduler::dispatch_async_run( false );
			update_option( 'eex_wizard_started_at', gmdate( 'Y-m-d\TH:i:s\Z' ), false );
			update_option( 'eex_wizard_done', 1, false );
			delete_option( 'eex_wizard_notice' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		wp_safe_redirect( $this->step_url( $next ) );
		exit;
	}

	/**
	 * Step 0 write: the mode choice. Switching an existing Full site with
	 * synced content to Lite goes through the settings confirmation screen
	 * instead (keep or trash must be an explicit decision).
	 *
	 * @return int Next step.
	 */
	private function save_mode_choice(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in save_step().
		$mode = isset( $_POST['mode'] ) && 'lite' === $_POST['mode'] ? 'lite' : 'full';

		if ( 'lite' === $mode && ! Options::is_lite() && \Emailexpert\Events\Install\Mode::has_content() ) {
			wp_safe_redirect( admin_url( 'options-general.php?page=emailexpert-events&eex_mode_confirm=lite' ) );
			exit;
		}

		\Emailexpert\Events\Install\Mode::choose( $mode );

		return 1;
	}

	/**
	 * Lite step 2 write: events to display, inside the settings option.
	 */
	private function save_lite_events(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in save_step(); sanitised below.
		$selected = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['lite_events'] ?? [] ) );

		Options::update_settings( [ 'lite_events' => array_values( array_filter( $selected ) ) ] );
	}

	/**
	 * Step 1 write: first connection key.
	 */
	private function save_connection(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in save_step().
		$key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$id  = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
		// phpcs:enable

		$connections = (array) get_option( Options::CONNECTIONS, [] );

		if ( '' === $id ) {
			$id            = 'c' . substr( md5( uniqid( 'eex', true ) ), 0, 8 );
			$connections[] = [
				'id'      => $id,
				'label'   => __( 'Primary', 'emailexpert-events' ),
				'api_key' => $key,
			];
		} else {
			foreach ( $connections as &$connection ) {
				if ( ( $connection['id'] ?? '' ) === $id && '' !== $key ) {
					$connection['api_key'] = $key;
				}
			}
			unset( $connection );
		}

		update_option( Options::CONNECTIONS, array_values( $connections ), false );
	}

	/**
	 * Step 2 write: enable the selected events, disable unselected ones.
	 */
	private function save_selected_events(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in save_step(); sanitised below.
		$selected   = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['events'] ?? [] ) );
		$configured = Options::synced_events();
		$available  = (array) get_option( 'eex_available_events', [] );

		// Titles from the availability cache.
		$titles = [];
		foreach ( $available as $conn_id => $events ) {
			foreach ( (array) $events as $event ) {
				$titles[ $conn_id . '|' . (string) $event['id'] ] = (string) ( $event['title'] ?? '' );
			}
		}

		foreach ( array_keys( $configured ) as $key ) {
			$configured[ $key ]['enabled'] = in_array( (string) $key, $selected, true ) ? 1 : 0;
		}
		foreach ( $selected as $key ) {
			if ( ! isset( $configured[ $key ] ) ) {
				$configured[ $key ] = Options::normalise_event_config(
					[
						'enabled' => 1,
						'title'   => $titles[ $key ] ?? '',
					]
				);
			}
		}

		update_option( Options::SYNCED_EVENTS, $configured, false );
	}

	/**
	 * Steps 3/4 write: per-event scope.
	 */
	private function save_scopes(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in save_step(); sanitised field-by-field below.
		$posted     = isset( $_POST['scope'] ) && is_array( $_POST['scope'] ) ? wp_unslash( $_POST['scope'] ) : [];
		$configured = Options::synced_events();

		foreach ( $posted as $key => $row ) {
			$key = sanitize_text_field( (string) $key );
			if ( ! isset( $configured[ $key ] ) || ! is_array( $row ) ) {
				continue;
			}

			$configured[ $key ] = array_merge(
				$configured[ $key ],
				self::sanitise_scope( $row )
			);
		}

		update_option( Options::SYNCED_EVENTS, $configured, false );
	}

	/**
	 * Sanitise a posted scope row (shared with the dry-run AJAX endpoint).
	 *
	 * @param array<string,mixed> $row Posted values.
	 * @return array<string,mixed>
	 */
	public static function sanitise_scope( array $row ): array {
		return [
			'future_mode'     => in_array( $row['future_mode'] ?? 'all', [ 'all', 'none' ], true ) ? $row['future_mode'] : 'all',
			'past_mode'       => in_array( $row['past_mode'] ?? 'all', [ 'all', 'none', 'recent', 'since' ], true ) ? $row['past_mode'] : 'all',
			'past_n'          => max( 0, (int) ( $row['past_n'] ?? 20 ) ),
			'past_since'      => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $row['past_since'] ?? '' ) ) ? (string) $row['past_since'] : '',
			'cat_filter_mode' => in_array( $row['cat_filter_mode'] ?? '', [ '', 'include', 'exclude' ], true ) ? $row['cat_filter_mode'] : '',
			'cat_filter'      => array_map( 'sanitize_text_field', (array) ( $row['cat_filter'] ?? [] ) ),
			'import_status'   => in_array( $row['import_status'] ?? 'publish', [ 'publish', 'pending' ], true ) ? $row['import_status'] : 'publish',
			'photos'          => empty( $row['photos'] ) ? 0 : 1,
		];
	}
}
