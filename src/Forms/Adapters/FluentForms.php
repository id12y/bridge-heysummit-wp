<?php
/**
 * Fluent Forms adapter.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Forms\Adapters;

use Emailexpert\Events\Forms\Capture;

defined( 'ABSPATH' ) || exit;

/**
 * fluentform/submission_inserted → Capture. Field keys are the input names
 * set in the Fluent Forms editor (e.g. 'email', 'names', 'my_checkbox').
 */
final class FluentForms {

	/**
	 * Insertion hook.
	 *
	 * @param mixed $entry_id  Entry ID.
	 * @param mixed $form_data Submitted values keyed by input name.
	 * @param mixed $form      Form object/array.
	 */
	public static function on_inserted( $entry_id, $form_data = [], $form = null ): void {
		$values = [];

		foreach ( (array) $form_data as $key => $value ) {
			$values[ (string) $key ] = $value;
		}

		$form_id = is_object( $form ) ? (string) ( $form->id ?? '' ) : (string) ( ( (array) $form )['id'] ?? '' );

		Capture::handle( 'fluent', $form_id, $values );
	}
}
