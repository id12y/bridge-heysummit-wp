<?php
/**
 * Editorial controls: sync mode and manual fields.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\PostTypes;

use Emailexpert\Events\Sync\Upserter;

defined( 'ABSPATH' ) || exit;

/**
 * Meta boxes and quick edit for `_eex_sync_mode` on synced post types, plus
 * the manual editor-owned fields (venue, hero override, replay URL). Detached
 * and excluded are permanent until an editor changes them; sync never resets
 * them, and excluding a post drafts it immediately.
 */
final class SyncModeUi {

	private const MODES = [
		Upserter::MODE_SYNCED,
		Upserter::MODE_DETACHED,
		Upserter::MODE_EXCLUDED,
	];

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_boxes' ] );
		add_action( 'save_post', [ $this, 'save' ], 10, 2 );

		foreach ( PostTypes::SYNCED as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", [ $this, 'add_column' ] );
			add_action( "manage_{$post_type}_posts_custom_column", [ $this, 'render_column' ], 10, 2 );
		}

		add_action( 'quick_edit_custom_box', [ $this, 'quick_edit_field' ], 10, 2 );
	}

	/**
	 * Register meta boxes.
	 */
	public function add_boxes(): void {
		foreach ( PostTypes::SYNCED as $post_type ) {
			add_meta_box(
				'eex-sync-mode',
				__( 'HeySummit sync', 'emailexpert-events' ),
				[ $this, 'render_sync_box' ],
				$post_type,
				'side'
			);
		}

		add_meta_box(
			'eex-venue',
			__( 'Venue (manual, never overwritten by sync)', 'emailexpert-events' ),
			[ $this, 'render_venue_box' ],
			PostTypes::EVENT,
			'normal'
		);

		add_meta_box(
			'eex-replay',
			__( 'Replay URL (manual value wins over synced)', 'emailexpert-events' ),
			[ $this, 'render_replay_box' ],
			PostTypes::TALK,
			'side'
		);
	}

	/**
	 * Sync mode meta box.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_sync_box( $post ): void {
		$mode  = (string) get_post_meta( $post->ID, '_eex_sync_mode', true ) ?: Upserter::MODE_SYNCED;
		$hs_id = (string) get_post_meta( $post->ID, '_eex_heysummit_id', true );
		$last  = (string) get_post_meta( $post->ID, '_eex_last_synced', true );

		wp_nonce_field( 'eex_editorial', 'eex_editorial_nonce' );
		?>
		<p>
			<label for="eex-sync-mode-select"><strong><?php esc_html_e( 'Sync mode', 'emailexpert-events' ); ?></strong></label><br />
			<select name="eex_sync_mode" id="eex-sync-mode-select">
				<option value="synced" <?php selected( $mode, 'synced' ); ?>><?php esc_html_e( 'Synced (updates flow in)', 'emailexpert-events' ); ?></option>
				<option value="detached" <?php selected( $mode, 'detached' ); ?>><?php esc_html_e( 'Detached (kept, never overwritten)', 'emailexpert-events' ); ?></option>
				<option value="excluded" <?php selected( $mode, 'excluded' ); ?>><?php esc_html_e( 'Excluded (drafted and skipped)', 'emailexpert-events' ); ?></option>
			</select>
		</p>
		<?php if ( '' !== $hs_id ) : ?>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: HeySummit ID, 2: last synced timestamp. */
						__( 'HeySummit ID %1$s. Last synced %2$s.', 'emailexpert-events' ),
						$hs_id,
						$last ?: __( 'never', 'emailexpert-events' )
					)
				);
				?>
			</p>
			<?php
		endif;
	}

	/**
	 * Venue meta box (events).
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_venue_box( $post ): void {
		$fields = [
			'_eex_venue_name'     => __( 'Venue name', 'emailexpert-events' ),
			'_eex_venue_street'   => __( 'Street address', 'emailexpert-events' ),
			'_eex_venue_locality' => __( 'Town or city', 'emailexpert-events' ),
			'_eex_venue_postcode' => __( 'Postcode', 'emailexpert-events' ),
			'_eex_venue_country'  => __( 'Country', 'emailexpert-events' ),
		];

		wp_nonce_field( 'eex_editorial', 'eex_editorial_nonce' );

		foreach ( $fields as $key => $label ) {
			printf(
				'<p><label for="%1$s">%2$s</label><br /><input type="text" class="widefat" id="%1$s" name="%1$s" value="%3$s" /></p>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( (string) get_post_meta( $post->ID, $key, true ) )
			);
		}
	}

	/**
	 * Replay URL meta box (talks).
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_replay_box( $post ): void {
		$manual = (string) get_post_meta( $post->ID, '_eex_replay_url', true );
		$synced = (string) get_post_meta( $post->ID, '_eex_replay_url_synced', true );

		wp_nonce_field( 'eex_editorial', 'eex_editorial_nonce' );
		?>
		<p>
			<input type="url" class="widefat" name="_eex_replay_url" value="<?php echo esc_attr( $manual ); ?>" placeholder="https://" />
		</p>
		<?php if ( '' !== $synced ) : ?>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: synced replay URL. */
						__( 'Synced from HeySummit: %s (used when the field above is empty).', 'emailexpert-events' ),
						$synced
					)
				);
				?>
			</p>
			<?php
		endif;
	}

	/**
	 * Persist editorial fields.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function save( $post_id, $post ): void {
		if ( ! in_array( $post->post_type ?? '', array_merge( PostTypes::SYNCED, [ PostTypes::SPONSOR ] ), true ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['eex_editorial_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['eex_editorial_nonce'] ), 'eex_editorial' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['eex_sync_mode'] ) ) {
			$mode = sanitize_key( $_POST['eex_sync_mode'] );

			if ( in_array( $mode, self::MODES, true ) ) {
				$previous = (string) get_post_meta( $post_id, '_eex_sync_mode', true );
				update_post_meta( $post_id, '_eex_sync_mode', $mode );

				// Excluding drafts the post immediately (and keeps it drafted:
				// sync skips excluded posts entirely on every future run).
				if ( Upserter::MODE_EXCLUDED === $mode && Upserter::MODE_EXCLUDED !== $previous && 'draft' !== get_post_status( $post_id ) ) {
					remove_action( 'save_post', [ $this, 'save' ], 10 );
					wp_update_post(
						[
							'ID'          => $post_id,
							'post_status' => 'draft',
						]
					);
					add_action( 'save_post', [ $this, 'save' ], 10, 2 );
				}
			}
		}

		$text_fields = [ '_eex_venue_name', '_eex_venue_street', '_eex_venue_locality', '_eex_venue_postcode', '_eex_venue_country' ];
		foreach ( $text_fields as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
			}
		}

		if ( isset( $_POST['_eex_replay_url'] ) ) {
			update_post_meta( $post_id, '_eex_replay_url', esc_url_raw( wp_unslash( $_POST['_eex_replay_url'] ) ) );
		}
	}

	/**
	 * Add the sync mode list table column.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function add_column( array $columns ): array {
		$columns['eex_sync_mode'] = __( 'Sync', 'emailexpert-events' );

		return $columns;
	}

	/**
	 * Render the sync mode column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( 'eex_sync_mode' !== $column ) {
			return;
		}

		$mode = (string) get_post_meta( $post_id, '_eex_sync_mode', true ) ?: Upserter::MODE_SYNCED;

		if ( get_post_meta( $post_id, '_eex_orphaned', true ) ) {
			$mode .= ' · ' . __( 'orphaned', 'emailexpert-events' );
		}

		printf( '<span data-eex-mode="%1$s">%1$s</span>', esc_html( $mode ) );
	}

	/**
	 * Quick edit control for sync mode.
	 *
	 * @param string $column    Column key.
	 * @param string $post_type Post type.
	 */
	public function quick_edit_field( string $column, string $post_type ): void {
		if ( 'eex_sync_mode' !== $column || ! in_array( $post_type, PostTypes::SYNCED, true ) ) {
			return;
		}

		wp_nonce_field( 'eex_editorial', 'eex_editorial_nonce' );
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label>
					<span class="title"><?php esc_html_e( 'Sync mode', 'emailexpert-events' ); ?></span>
					<select name="eex_sync_mode">
						<option value="synced"><?php esc_html_e( 'Synced', 'emailexpert-events' ); ?></option>
						<option value="detached"><?php esc_html_e( 'Detached', 'emailexpert-events' ); ?></option>
						<option value="excluded"><?php esc_html_e( 'Excluded', 'emailexpert-events' ); ?></option>
					</select>
				</label>
			</div>
		</fieldset>
		<?php
	}
}
