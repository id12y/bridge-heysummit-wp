<?php
/**
 * Full-mode event meta box: homepage and landing-page presentation.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Admin;

use Emailexpert\Events\Data\EventPresentation;
use Emailexpert\Events\PostTypes\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * The per-event presentation settings on the event edit screen (Full
 * mode). Saving flushes the display cache through EventPresentation, so a
 * changed promotion level or status shows immediately. Lite mode edits the
 * same fields under Settings → Live display; both surfaces share
 * PresentationFields.
 */
final class PresentationMetaBox {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_box' ] );
		add_action( 'save_post', [ $this, 'save' ], 10, 2 );
	}

	/**
	 * Register the meta box on event posts.
	 */
	public function add_box(): void {
		add_meta_box(
			'eex-presentation',
			__( 'Homepage and landing-page presentation', 'emailexpert-events' ),
			[ $this, 'render' ],
			PostTypes::EVENT,
			'normal',
			'default'
		);
	}

	/**
	 * Render the fields.
	 *
	 * @param \WP_Post|object $post Event post.
	 */
	public function render( $post ): void {
		wp_nonce_field( 'eex_presentation', 'eex_presentation_nonce' );

		$stored = get_post_meta( (int) $post->ID, EventPresentation::META_KEY, true );

		PresentationFields::render( 'eex_presentation', is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Save on event post save.
	 *
	 * @param int             $post_id Post ID.
	 * @param \WP_Post|object $post    Post object.
	 */
	public function save( $post_id, $post ): void {
		if ( ! isset( $_POST['eex_presentation_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eex_presentation_nonce'] ) ), 'eex_presentation' ) ) {
			return;
		}

		if ( PostTypes::EVENT !== (string) ( $post->post_type ?? '' ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return;
		}

		$posted = isset( $_POST['eex_presentation'] ) && is_array( $_POST['eex_presentation'] )
			? map_deep( wp_unslash( $_POST['eex_presentation'] ), 'sanitize_textarea_field' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised on this line and field-by-field in EventPresentation::sanitise().
			: [];

		EventPresentation::save_full( (int) $post_id, PresentationFields::from_post( $posted ) );
	}
}
