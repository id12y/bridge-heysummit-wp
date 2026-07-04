<?php
/**
 * Gravity Forms adapter.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Forms\Adapters;

use Emailexpert\Events\Forms\Capture;

defined( 'ABSPATH' ) || exit;

/**
 * gform_after_submission → Capture. Field keys are Gravity Forms field IDs
 * as they appear in the entry array — plain ('3') for simple fields, dotted
 * ('1.3', '1.6') for multi-input fields such as Name; the mapping's field
 * IDs use the same notation.
 */
final class GravityForms {

	/**
	 * Submission hook.
	 *
	 * @param mixed $entry Entry array.
	 * @param mixed $form  Form array.
	 */
	public static function on_submission( $entry, $form ): void {
		$values = [];

		foreach ( (array) $entry as $key => $value ) {
			$values[ (string) $key ] = $value;
		}

		Capture::handle( 'gravity', (string) ( ( (array) $form )['id'] ?? '' ), $values );
	}
}
