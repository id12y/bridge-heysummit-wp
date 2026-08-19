<?php
/**
 * Shared form fields for per-event presentation settings.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Admin;

use Emailexpert\Events\Data\EventPresentation;

defined( 'ABSPATH' ) || exit;

/**
 * One renderer and one POST reader for the presentation fields, shared by
 * the Full-mode event meta box and the Lite-mode Live display settings —
 * so the two surfaces can never drift apart (the 1.49.1 lesson: a control
 * added in one mode's save path only fails silently in the other).
 */
final class PresentationFields {

	/**
	 * Render the fields.
	 *
	 * @param string              $prefix Input name prefix, e.g. "eex_presentation".
	 * @param array<string,mixed> $values Sanitised current values.
	 */
	public static function render( string $prefix, array $values ): void {
		$values = EventPresentation::sanitise( $values );

		$name = static fn( string $field ): string => $prefix . '[' . $field . ']';

		// Stored UTC; shown as datetime-local in the site timezone.
		$local = static function ( string $iso ): string {
			$ts = EventPresentation::timestamp( $iso );

			if ( 0 === $ts ) {
				return '';
			}

			return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i' );
		};
		?>
		<p>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $name( 'feature_eligible' ) ); ?>" value="1" <?php checked( ! empty( $values['feature_eligible'] ) ); ?> />
				<?php esc_html_e( 'Eligible for the automatic homepage feature', 'emailexpert-events' ); ?>
			</label>
			<br />
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $name( 'more_eligible' ) ); ?>" value="1" <?php checked( ! empty( $values['more_eligible'] ) ); ?> />
				<?php esc_html_e( 'Eligible for More Events lists', 'emailexpert-events' ); ?>
			</label>
		</p>
		<p>
			<label for="<?php echo esc_attr( $name( 'level' ) ); ?>"><strong><?php esc_html_e( 'Promotion level', 'emailexpert-events' ); ?></strong></label><br />
			<select id="<?php echo esc_attr( $name( 'level' ) ); ?>" name="<?php echo esc_attr( $name( 'level' ) ); ?>">
				<option value="normal" <?php selected( 'normal', $values['level'] ); ?>><?php esc_html_e( 'Normal', 'emailexpert-events' ); ?></option>
				<option value="featured" <?php selected( 'featured', $values['level'] ); ?>><?php esc_html_e( 'Featured', 'emailexpert-events' ); ?></option>
				<option value="flagship" <?php selected( 'flagship', $values['level'] ); ?>><?php esc_html_e( 'Flagship', 'emailexpert-events' ); ?></option>
				<option value="none" <?php selected( 'none', $values['level'] ); ?>><?php esc_html_e( 'Do not promote', 'emailexpert-events' ); ?></option>
			</select>
		</p>
		<p>
			<label for="<?php echo esc_attr( $name( 'promo_start' ) ); ?>"><?php esc_html_e( 'Promotion window start (site time; empty = always)', 'emailexpert-events' ); ?></label><br />
			<input type="datetime-local" id="<?php echo esc_attr( $name( 'promo_start' ) ); ?>" name="<?php echo esc_attr( $name( 'promo_start' ) ); ?>" value="<?php echo esc_attr( $local( (string) $values['promo_start'] ) ); ?>" />
			<label for="<?php echo esc_attr( $name( 'promo_end' ) ); ?>"><?php esc_html_e( 'end', 'emailexpert-events' ); ?></label>
			<input type="datetime-local" id="<?php echo esc_attr( $name( 'promo_end' ) ); ?>" name="<?php echo esc_attr( $name( 'promo_end' ) ); ?>" value="<?php echo esc_attr( $local( (string) $values['promo_end'] ) ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $name( 'status' ) ); ?>"><strong><?php esc_html_e( 'Public status override', 'emailexpert-events' ); ?></strong></label><br />
			<select id="<?php echo esc_attr( $name( 'status' ) ); ?>" name="<?php echo esc_attr( $name( 'status' ) ); ?>">
				<option value="auto" <?php selected( 'auto', $values['status'] ); ?>><?php esc_html_e( 'Automatic', 'emailexpert-events' ); ?></option>
				<option value="scheduled" <?php selected( 'scheduled', $values['status'] ); ?>><?php esc_html_e( 'Scheduled (with notice)', 'emailexpert-events' ); ?></option>
				<option value="postponed" <?php selected( 'postponed', $values['status'] ); ?>><?php esc_html_e( 'Postponed', 'emailexpert-events' ); ?></option>
				<option value="cancelled" <?php selected( 'cancelled', $values['status'] ); ?>><?php esc_html_e( 'Cancelled', 'emailexpert-events' ); ?></option>
			</select>
		</p>
		<p>
			<label for="<?php echo esc_attr( $name( 'status_message' ) ); ?>"><?php esc_html_e( 'Public status message (shown with the notice)', 'emailexpert-events' ); ?></label><br />
			<input type="text" class="widefat" id="<?php echo esc_attr( $name( 'status_message' ) ); ?>" name="<?php echo esc_attr( $name( 'status_message' ) ); ?>" value="<?php echo esc_attr( (string) $values['status_message'] ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $name( 'intro' ) ); ?>"><?php esc_html_e( 'Short promotional introduction (homepage card and landing page)', 'emailexpert-events' ); ?></label><br />
			<textarea class="widefat" rows="2" id="<?php echo esc_attr( $name( 'intro' ) ); ?>" name="<?php echo esc_attr( $name( 'intro' ) ); ?>"><?php echo esc_textarea( (string) $values['intro'] ); ?></textarea>
		</p>
		<p>
			<label for="<?php echo esc_attr( $name( 'hero_media' ) ); ?>"><?php esc_html_e( 'Hero media override (image URL or media ID)', 'emailexpert-events' ); ?></label><br />
			<input type="text" class="widefat" id="<?php echo esc_attr( $name( 'hero_media' ) ); ?>" name="<?php echo esc_attr( $name( 'hero_media' ) ); ?>" value="<?php echo esc_attr( (string) $values['hero_media'] ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $name( 'cta_label' ) ); ?>"><?php esc_html_e( 'CTA override — label', 'emailexpert-events' ); ?></label>
			<input type="text" id="<?php echo esc_attr( $name( 'cta_label' ) ); ?>" name="<?php echo esc_attr( $name( 'cta_label' ) ); ?>" value="<?php echo esc_attr( (string) $values['cta_label'] ); ?>" />
			<label for="<?php echo esc_attr( $name( 'cta_url' ) ); ?>"><?php esc_html_e( 'destination URL', 'emailexpert-events' ); ?></label>
			<input type="url" id="<?php echo esc_attr( $name( 'cta_url' ) ); ?>" name="<?php echo esc_attr( $name( 'cta_url' ) ); ?>" value="<?php echo esc_attr( (string) $values['cta_url'] ); ?>" size="30" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $name( 'format' ) ); ?>"><strong><?php esc_html_e( 'Format', 'emailexpert-events' ); ?></strong></label><br />
			<select id="<?php echo esc_attr( $name( 'format' ) ); ?>" name="<?php echo esc_attr( $name( 'format' ) ); ?>">
				<option value="auto" <?php selected( 'auto', $values['format'] ); ?>><?php esc_html_e( 'Auto (from the session and event data)', 'emailexpert-events' ); ?></option>
				<option value="online" <?php selected( 'online', $values['format'] ); ?>><?php esc_html_e( 'Online', 'emailexpert-events' ); ?></option>
				<option value="inperson" <?php selected( 'inperson', $values['format'] ); ?>><?php esc_html_e( 'In person', 'emailexpert-events' ); ?></option>
				<option value="hybrid" <?php selected( 'hybrid', $values['format'] ); ?>><?php esc_html_e( 'Hybrid', 'emailexpert-events' ); ?></option>
			</select>
			<span class="description"><?php esc_html_e( 'Format is an editorial fact about the gathering — URLs, checkout types and registration mechanisms never influence it.', 'emailexpert-events' ); ?></span>
		</p>
		<p>
			<label for="<?php echo esc_attr( $name( 'details_url' ) ); ?>"><?php esc_html_e( 'Details destination override (a good public landing page; empty = the default)', 'emailexpert-events' ); ?></label><br />
			<input type="url" class="widefat" id="<?php echo esc_attr( $name( 'details_url' ) ); ?>" name="<?php echo esc_attr( $name( 'details_url' ) ); ?>" value="<?php echo esc_attr( (string) $values['details_url'] ); ?>" />
		</p>
		<?php
	}

	/**
	 * Render one per-session speaker relationship row (Lite settings and the
	 * Full-mode talk meta box share this markup, so the two surfaces can
	 * never drift).
	 *
	 * @param string                          $prefix    Input name prefix.
	 * @param array<string,mixed>             $relation  { source, refs } (sanitised).
	 * @param array<int,array<string,string>> $catalogue Choices: [{ value, label }].
	 */
	public static function render_speaker_relation( string $prefix, array $relation, array $catalogue ): void {
		$relation = EventPresentation::sanitise_talk( $relation );
		?>
		<p>
			<label for="<?php echo esc_attr( $prefix . '[source]' ); ?>"><strong><?php esc_html_e( 'Speaker source', 'emailexpert-events' ); ?></strong></label><br />
			<select id="<?php echo esc_attr( $prefix . '[source]' ); ?>" name="<?php echo esc_attr( $prefix . '[source]' ); ?>">
				<option value="auto" <?php selected( 'auto', $relation['source'] ); ?>><?php esc_html_e( 'Auto (local assignment first, else HeySummit)', 'emailexpert-events' ); ?></option>
				<option value="heysummit" <?php selected( 'heysummit', $relation['source'] ); ?>><?php esc_html_e( 'HeySummit only', 'emailexpert-events' ); ?></option>
				<option value="local" <?php selected( 'local', $relation['source'] ); ?>><?php esc_html_e( 'Local assignment only', 'emailexpert-events' ); ?></option>
				<option value="none" <?php selected( 'none', $relation['source'] ); ?>><?php esc_html_e( 'None (suppress speakers)', 'emailexpert-events' ); ?></option>
			</select>
		</p>
		<p>
			<label for="<?php echo esc_attr( $prefix . '[refs]' ); ?>"><?php esc_html_e( 'Locally assigned speakers (references to the existing speaker records — their names, portraits and roles stay canonical)', 'emailexpert-events' ); ?></label><br />
			<select id="<?php echo esc_attr( $prefix . '[refs]' ); ?>" name="<?php echo esc_attr( $prefix . '[refs][]' ); ?>" multiple size="5" class="widefat">
				<?php foreach ( $catalogue as $choice ) : ?>
					<option value="<?php echo esc_attr( (string) $choice['value'] ); ?>" <?php selected( in_array( (string) $choice['value'], $relation['refs'], true ) ); ?>><?php echo esc_html( (string) $choice['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * Read submitted fields (unslashed caller-side by wp_unslash on the
	 * whole array) into the shape EventPresentation::sanitise() expects.
	 * Checkboxes are explicit here: an absent box means off.
	 *
	 * @param array<string,mixed> $posted The submitted prefix array.
	 * @return array<string,mixed>
	 */
	public static function from_post( array $posted ): array {
		return [
			'feature_eligible' => ! empty( $posted['feature_eligible'] ) ? 1 : 0,
			'more_eligible'    => ! empty( $posted['more_eligible'] ) ? 1 : 0,
			'level'            => (string) ( $posted['level'] ?? 'normal' ),
			'promo_start'      => (string) ( $posted['promo_start'] ?? '' ),
			'promo_end'        => (string) ( $posted['promo_end'] ?? '' ),
			'status'           => (string) ( $posted['status'] ?? 'auto' ),
			'status_message'   => (string) ( $posted['status_message'] ?? '' ),
			'intro'            => (string) ( $posted['intro'] ?? '' ),
			'hero_media'       => (string) ( $posted['hero_media'] ?? '' ),
			'cta_label'        => (string) ( $posted['cta_label'] ?? '' ),
			'cta_url'          => (string) ( $posted['cta_url'] ?? '' ),
			'format'           => (string) ( $posted['format'] ?? 'auto' ),
			'details_url'      => (string) ( $posted['details_url'] ?? '' ),
			'session_speakers' => array_filter( (array) ( $posted['session_speakers'] ?? [] ), 'is_array' ),
		];
	}
}
