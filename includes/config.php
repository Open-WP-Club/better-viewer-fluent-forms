<?php
/** Configuration and validation for configured Fluent Forms. */

defined( 'ABSPATH' ) || exit;

class CFS_Config {
	/**
	 * The primary configuration is stored in the WordPress option managed by
	 * the plugin's Settings screen. The filter remains available for deployment
	 * overrides in code when a developer needs it.
	 *
	 * @return array<int, array>
	 */
	public static function forms() {
		$forms = get_option( 'cfs_forms_config', array() );
		$forms = apply_filters( 'cfs_form_config', is_array( $forms ) ? $forms : array() );
		$valid = array();

		if ( ! is_array( $forms ) ) {
			return $valid;
		}

		foreach ( $forms as $form_id => $config ) {
			$form_id = absint( $form_id );
			if ( ! $form_id || ! is_array( $config ) ) {
				continue;
			}

			$columns  = isset( $config['columns'] ) && is_array( $config['columns'] ) ? $config['columns'] : array();
			$sections = isset( $config['sections'] ) && is_array( $config['sections'] ) ? $config['sections'] : array();
			$valid[ $form_id ] = array(
				'id'             => $form_id,
				'title'          => isset( $config['title'] ) ? sanitize_text_field( $config['title'] ) : sprintf( __( 'Form #%d', 'client-fluent-submissions' ), $form_id ),
				'columns'        => self::fields( $columns ),
				'sections'       => self::sections( $sections ),
				'inline_details' => ! empty( $config['inline_details'] ),
			);
		}

		return $valid;
	}

	public static function get( $form_id ) {
		$forms = self::forms();
		return isset( $forms[ absint( $form_id ) ] ) ? $forms[ absint( $form_id ) ] : null;
	}

	/** Sanitize the Settings API payload without storing presentation JSON. */
	public static function sanitize_settings( $input ) {
		$output = array();
		if ( ! is_array( $input ) ) { return $output; }
		foreach ( $input as $config ) {
			if ( ! is_array( $config ) ) { continue; }
			$form_id = absint( $config['id'] ?? 0 );
			if ( ! $form_id ) { continue; }
			$columns  = self::decode_fields( $config['columns_json'] ?? '' );
			$sections = self::decode_sections( $config['sections_json'] ?? '' );
			$output[ $form_id ] = array(
				'title'          => sanitize_text_field( $config['title'] ?? '' ),
				'columns'        => $columns,
				'sections'       => $sections,
				'inline_details' => ! empty( $config['inline_details'] ),
			);
		}
		return $output;
	}

	private static function decode_fields( $json ) {
		$fields = json_decode( wp_unslash( (string) $json ), true );
		return is_array( $fields ) ? self::fields( $fields ) : array();
	}

	private static function decode_sections( $json ) {
		$sections = json_decode( wp_unslash( (string) $json ), true );
		return is_array( $sections ) ? self::sections( $sections ) : array();
	}

	private static function fields( $fields ) {
		$clean = array();
		foreach ( $fields as $key => $label ) {
			// Fluent Forms input names may contain dots or brackets for compound
			// controls (for example, names.first_name or names[first_name]).
			$key   = sanitize_text_field( (string) $key );
			$label = sanitize_text_field( $label );
			if ( $key && $label ) {
				$clean[ $key ] = $label;
			}
		}
		return $clean;
	}

	private static function sections( $sections ) {
		$clean = array();
		foreach ( $sections as $title => $fields ) {
			$title = sanitize_text_field( $title );
			$fields = is_array( $fields ) ? self::fields( $fields ) : array();
			if ( $title && $fields ) {
				$clean[ $title ] = $fields;
			}
		}
		return $clean;
	}
}
