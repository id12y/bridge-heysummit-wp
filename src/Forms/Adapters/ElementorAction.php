<?php
/**
 * Elementor Pro Forms action.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Forms\Adapters;

use Emailexpert\Events\Forms\Capture;
use Emailexpert\Events\Forms\Mappings;

defined( 'ABSPATH' ) || exit;

/**
 * The "HeySummit registration" action offered in an Elementor Pro form's
 * Actions After Submit. The operator picks one of the mappings defined on
 * the Bridges screen; field values are read by the custom field IDs the
 * form designer set, and the shared Capture gates (consent, suppression,
 * dedupe) apply exactly as for every other source.
 *
 * Only ever loaded after a class_exists guard on the parent — see the
 * Elementor registrar class.
 */
class ElementorAction extends \ElementorPro\Modules\Forms\Classes\Action_Base {

	/**
	 * Action slug.
	 */
	public function get_name(): string {
		return 'eex_heysummit';
	}

	/**
	 * Action label.
	 */
	public function get_label(): string {
		return __( 'HeySummit registration', 'emailexpert-events' );
	}

	/**
	 * The action's settings section on the form widget: a mapping picker.
	 *
	 * @param object $widget Form widget.
	 */
	public function register_settings_section( $widget ): void {
		$options = [ '' => __( 'Choose a mapping…', 'emailexpert-events' ) ];

		foreach ( Mappings::all() as $mapping ) {
			if ( 'elementor' !== (string) ( $mapping['source'] ?? '' ) ) {
				continue;
			}

			$options[ (string) $mapping['id'] ] = (string) ( $mapping['label'] ?: $mapping['id'] );
		}

		$widget->start_controls_section(
			'eex_heysummit_section',
			[
				'label'     => $this->get_label(),
				'condition' => [ 'submit_actions' => $this->get_name() ],
			]
		);

		$widget->add_control(
			'eex_hs_mapping',
			[
				'label'       => __( 'Form mapping', 'emailexpert-events' ),
				'type'        => 'select',
				'options'     => $options,
				'default'     => '',
				'description' => __( 'Mappings are defined under Settings → EEX Bridges → Forms. The mapping names the event, ticket and which field IDs hold the email, name and consent.', 'emailexpert-events' ),
			]
		);

		$widget->end_controls_section();
	}

	/**
	 * Run on submit.
	 *
	 * @param object $record       Submission record.
	 * @param object $ajax_handler Ajax handler (unused: capture never blocks the visitor).
	 */
	public function run( $record, $ajax_handler ): void {
		$mapping = Mappings::get( (string) $record->get_form_settings( 'eex_hs_mapping' ) );

		if ( null === $mapping || 'elementor' !== (string) ( $mapping['source'] ?? '' ) ) {
			return;
		}

		$values = [];
		foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
			$values[ (string) $id ] = is_array( $field ) ? ( $field['value'] ?? '' ) : $field;
		}

		Capture::capture( $mapping, $values );
	}

	/**
	 * Strip the mapping ID on export: it is site-specific.
	 *
	 * @param array<string,mixed> $element Exported element.
	 * @return array<string,mixed>
	 */
	public function on_export( $element ) {
		unset( $element['settings']['eex_hs_mapping'] );

		return $element;
	}
}
