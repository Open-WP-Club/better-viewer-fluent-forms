<?php
/** Fluent Forms data access. */

defined( 'ABSPATH' ) || exit;

class CFS_Submissions {
	const STATUS_META_KEY = '_cfs_client_status';

	public static function statuses() {
		return array(
			'new'         => __( 'New', 'client-fluent-submissions' ),
			'in_progress' => __( 'In progress', 'client-fluent-submissions' ),
			'completed'   => __( 'Completed', 'client-fluent-submissions' ),
			'archived'    => __( 'Archived', 'client-fluent-submissions' ),
		);
	}

	public static function available() {
		return defined( 'FLUENTFORM' ) && class_exists( '\\FluentForm\\App\\Models\\Submission' ) && class_exists( '\\FluentForm\\App\\Models\\SubmissionMeta' );
	}

	public static function user_can_view( $form_id ) {
		if ( ! current_user_can( 'cfs_view_submissions' ) || ! self::available() ) {
			return false;
		}
		return self::fluent_can( 'fluentform_entries_viewer', $form_id );
	}

	public static function user_can_manage( $form_id ) {
		return current_user_can( 'cfs_manage_submissions' ) && self::user_can_view( $form_id ) && self::fluent_can( 'fluentform_manage_entries', $form_id );
	}

	private static function fluent_can( $permission, $form_id ) {
		if ( ! class_exists( '\\FluentForm\\App\\Modules\\Acl\\Acl' ) ) {
			return false;
		}
		return \FluentForm\App\Modules\Acl\Acl::hasPermission( $permission, absint( $form_id ) );
	}

	/** @return array{items: array, total: int} */
	public static function list( $form_id, $args ) {
		$form_id = absint( $form_id );
		$page    = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = min( 100, max( 10, absint( $args['per_page'] ?? 20 ) ) );
		// This intentionally includes both Fluent Forms `read` and `unread`
		// submissions. Only entries moved to the trash are omitted.
		$query = \FluentForm\App\Models\Submission::where( 'form_id', $form_id )
			->where( 'status', '!=', 'trashed' );

		$start = self::date( $args['date_from'] ?? '' );
		$end   = self::date( $args['date_to'] ?? '' );
		if ( $start ) { $query->where( 'created_at', '>=', $start . ' 00:00:00' ); }
		if ( $end ) { $query->where( 'created_at', '<=', $end . ' 23:59:59' ); }

		$search = sanitize_text_field( $args['search'] ?? '' );
		if ( '' !== $search ) {
			global $wpdb;
			$like = $wpdb->esc_like( $search );
			// Fluent Forms stores submitted values in its response JSON. This keeps
			// search portable across MySQL/MariaDB versions and uses its model query.
			$field = sanitize_text_field( $args['search_field'] ?? '' );
			if ( $field ) {
				$field_like = $wpdb->esc_like( '"' . $field . '"' );
				$query->where( 'response', 'LIKE', '%' . $field_like . '%' . $like . '%' );
			} else {
				$query->where( 'response', 'LIKE', '%' . $like . '%' );
			}
		}

		$read = sanitize_key( $args['read'] ?? '' );
		if ( in_array( $read, array( 'read', 'unread' ), true ) ) { $query->where( 'status', $read ); }

		$status = sanitize_key( $args['status'] ?? '' );
		if ( $status && isset( self::statuses()[ $status ] ) ) {
			if ( 'new' === $status ) {
				// Existing submissions have no client-meta row until someone changes
				// their status; that absence is intentionally treated as “Ново”.
				$query->where( static function( $q ) use ( $status ) {
					$q->whereDoesntHave( 'submissionMeta', static function( $meta ) {
						$meta->where( 'meta_key', self::STATUS_META_KEY );
					} )->orWhereHas( 'submissionMeta', static function( $meta ) use ( $status ) {
						$meta->where( 'meta_key', self::STATUS_META_KEY )->where( 'value', $status );
					} );
				} );
			} else {
				$query->whereHas( 'submissionMeta', static function( $meta ) use ( $status ) {
					$meta->where( 'meta_key', self::STATUS_META_KEY )->where( 'value', $status );
				} );
			}
		}

		$total = (int) $query->count();
		$items = $query->orderBy( 'created_at', 'DESC' )->orderBy( 'id', 'DESC' )
			->limit( $per_page )->offset( ( $page - 1 ) * $per_page )->get();
		$items = self::attach_statuses( $items );

		return array( 'items' => $items, 'total' => $total );
	}

	public static function find( $submission_id, $form_id ) {
		$submission = \FluentForm\App\Models\Submission::where( 'id', absint( $submission_id ) )
			->where( 'form_id', absint( $form_id ) )->first();
		if ( ! $submission || 'trashed' === $submission->status ) { return null; }
		$items = self::attach_statuses( array( $submission ) );
		return reset( $items );
	}

	public static function update_status( $submission_id, $form_id, $status ) {
		$status = sanitize_key( $status );
		if ( ! isset( self::statuses()[ $status ] ) || ! self::find( $submission_id, $form_id ) ) { return false; }
		// Use Fluent Forms' metadata model rather than a custom table. `persist`
		// updates an existing row or creates one when the client status is new.
		\FluentForm\App\Models\SubmissionMeta::persist( absint( $submission_id ), self::STATUS_META_KEY, $status, absint( $form_id ) );
		return true;
	}

	public static function set_read( $submission_id, $form_id, $read = true ) {
		if ( ! self::find( $submission_id, $form_id ) ) { return false; }
		return false !== \FluentForm\App\Models\Submission::where( 'id', absint( $submission_id ) )
			->where( 'form_id', absint( $form_id ) )->update( array( 'status' => $read ? 'read' : 'unread' ) );
	}

	public static function unread_count( $form_id ) {
		return (int) \FluentForm\App\Models\Submission::where( 'form_id', absint( $form_id ) )
			->where( 'status', 'unread' )
			->count();
	}

	private static function attach_statuses( $items ) {
		$ids = array();
		foreach ( $items as $item ) { $ids[] = absint( $item->id ); }
		$values = array();
		if ( $ids ) {
			$meta = \FluentForm\App\Models\SubmissionMeta::whereIn( 'response_id', $ids )
				->where( 'meta_key', self::STATUS_META_KEY )->get();
			foreach ( $meta as $row ) {
				$values[ absint( $row->response_id ) ] = sanitize_key( $row->value );
			}
		}
		foreach ( $items as $item ) {
			$item->cfs_status = isset( $values[ $item->id ], self::statuses()[ $values[ $item->id ] ] ) ? $values[ $item->id ] : 'new';
			$item->cfs_is_read = 'read' === $item->status;
			$item->cfs_response = json_decode( $item->response, true );
			if ( ! is_array( $item->cfs_response ) ) { $item->cfs_response = array(); }
		}
		return $items;
	}

	private static function date( $value ) {
		$value = sanitize_text_field( $value );
		$dt = DateTime::createFromFormat( 'Y-m-d', $value );
		return $dt && $dt->format( 'Y-m-d' ) === $value ? $value : '';
	}
}
