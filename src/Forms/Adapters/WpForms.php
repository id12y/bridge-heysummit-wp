<?php
/**
 * WPForms adapter.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Forms\Adapters;

use Emailexpert\Events\Forms\Capture;

defined( 'ABSPATH' ) || exit;

/**
 * wpforms_process_complete → Capture. Field keys are WPForms numeric field
 * IDs (shown next to each field in the builder); each processed field
 * carries its final value under 'value'.
 */
final class WpForms {

	/**
	 * Completion hook.
	 *
	 * @param mixed $fields    Processed fields.
	 * @param mixed $entry     Raw entry.
	 * @param mixed $form_data Form settings/data.
	 * @param mixed $entry_id  Entry ID.
	 */
	public static function on_complete( $fields, $entry = [], $form_data = [], $entry_id = 0 ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the full hook signature, documented.
		$values = [];

		foreach ( (array) $fields as $id => $field ) {
			$values[ (string) $id ] = is_array( $field ) ? ( $field['value'] ?? '' ) : $field;
		}

		Capture::handle( 'wpforms', (string) ( ( (array) $form_data )['id'] ?? '' ), $values );
	}
}
