<?php
/** Admin UI and authenticated status AJAX endpoint. */

defined( 'ABSPATH' ) || exit;

class CFS_Admin_Page {
	const SLUG = 'client-fluent-submissions';

	private static $hooks = array();

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'dependency_notice' ) );
		add_action( 'admin_footer', array( __CLASS__, 'hide_ff_status_column' ) );
		add_action( 'wp_ajax_cfs_update_status', array( __CLASS__, 'ajax_update_status' ) );
		add_action( 'wp_ajax_cfs_form_fields', array( __CLASS__, 'ajax_form_fields' ) );
		add_action( 'wp_ajax_cfs_submission_detail', array( __CLASS__, 'ajax_submission_detail' ) );
		add_action( 'admin_post_cfs_export_csv', array( __CLASS__, 'export_csv' ) );
		add_action( 'admin_post_cfs_bulk_action', array( __CLASS__, 'bulk_action' ) );
		add_action( 'admin_post_cfs_add_note', array( __CLASS__, 'add_note' ) );
		add_action( 'fluentform/submission_inserted', array( __CLASS__, 'notify_new_submission' ), 20, 3 );
	}

	public static function activate() {
		$role = get_role( 'administrator' );
		if ( $role ) { $role->add_cap( 'cfs_view_submissions' ); $role->add_cap( 'cfs_manage_submissions' ); }
	}

	public static function menu() {
		// WordPress derives submenu hook suffixes from the sanitized parent menu
		// *title*, not its slug, so the resulting hook cannot be reconstructed
		// from self::SLUG alone. Capture what WordPress actually returns instead.
		$count = 0;
		if ( CFS_Submissions::available() ) { foreach ( CFS_Config::forms() as $form ) { if ( CFS_Submissions::user_can_view( $form['id'] ) ) { $count += CFS_Submissions::unread_count( $form['id'] ); } } }
		$menu_title = __( 'Enquiries', 'client-fluent-submissions' );
		if ( $count ) { $menu_title .= ' <span class="awaiting-mod count-' . absint( $count ) . '"><span class="pending-count">' . absint( $count ) . '</span></span>'; }
		self::$hooks[] = add_menu_page( __( 'Enquiries', 'client-fluent-submissions' ), $menu_title, 'cfs_view_submissions', self::SLUG, array( __CLASS__, 'render' ), 'dashicons-email-alt2', 26 );
		self::$hooks[] = add_submenu_page( self::SLUG, __( 'Enquiry settings', 'client-fluent-submissions' ), __( 'Settings', 'client-fluent-submissions' ), 'cfs_manage_submissions', self::SLUG . '-settings', array( __CLASS__, 'settings' ) );
	}

	public static function register_settings() {
		register_setting( 'cfs_settings', 'cfs_forms_config', array( 'sanitize_callback' => array( 'CFS_Config', 'sanitize_settings' ) ) );
		register_setting( 'cfs_settings', 'cfs_notify_email', array( 'sanitize_callback' => static function( $value ) { return $value ? '1' : ''; }, 'default' => '1' ) );
	}

	public static function assets( $hook ) {
		if ( ! in_array( $hook, self::$hooks, true ) ) { return; }
		wp_enqueue_style( 'cfs-admin', CFS_URL . 'assets/admin.css', array(), CFS_VERSION );
		wp_enqueue_script( 'cfs-admin', CFS_URL . 'assets/admin.js', array(), CFS_VERSION, true );
		wp_localize_script( 'cfs-admin', 'cfsAdmin', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'cfs_update_status' ), 'fieldsNonce' => wp_create_nonce( 'cfs_form_fields' ), 'detailNonce' => wp_create_nonce( 'cfs_submission_detail' ), 'error' => __( 'The status could not be saved. Please try again.', 'client-fluent-submissions' ), 'fieldsError' => __( 'The fields could not be loaded for this form.', 'client-fluent-submissions' ), 'detailError' => __( 'The submission could not be loaded. Please try again.', 'client-fluent-submissions' ), 'loading' => __( 'Loading…', 'client-fluent-submissions' ), 'view' => __( 'View', 'client-fluent-submissions' ), 'close' => __( 'Close', 'client-fluent-submissions' ), 'unsaved' => __( 'You have unsaved changes.', 'client-fluent-submissions' ), 'pickerHeadings' => array( __( 'Fluent Forms field', 'client-fluent-submissions' ), __( 'Client label', 'client-fluent-submissions' ), __( 'In table', 'client-fluent-submissions' ), __( 'In detail', 'client-fluent-submissions' ), __( 'Section', 'client-fluent-submissions' ) ), 'toggleAll' => __( 'Select all fields', 'client-fluent-submissions' ), 'defaultSection' => __( 'Information', 'client-fluent-submissions' ), 'sectionPlaceholder' => __( 'e.g. Contact', 'client-fluent-submissions' ) ) );
	}

	/**
	 * Fluent Forms' own "All Entries" screen is a client-rendered app with no
	 * PHP column filter, so the Status column is trimmed via a text-matched
	 * DOM tweak scoped to that screen only.
	 */
	public static function hide_ff_status_column() {
		$page  = sanitize_key( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$route = sanitize_key( $_GET['route'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'entries' !== $route || false === strpos( $page, 'fluent_form' ) ) { return; }
		?>
		<script>
		( function() {
			var labels = [ 'status', 'статус' ];
			function hideColumn() {
				document.querySelectorAll( 'table' ).forEach( function( table ) {
					var headerRow = table.querySelector( 'thead tr' );
					if ( ! headerRow ) { return; }
					var cells = Array.prototype.slice.call( headerRow.children );
					var index = cells.findIndex( function( cell ) {
						return labels.indexOf( cell.textContent.trim().toLowerCase() ) !== -1;
					} );
					if ( index === -1 ) { return; }
					table.querySelectorAll( 'tr' ).forEach( function( row ) {
						var cell = row.children[ index ];
						if ( cell ) { cell.style.display = 'none'; }
					} );
				} );
			}
			hideColumn();
			new MutationObserver( hideColumn ).observe( document.body, { childList: true, subtree: true } );
		} )();
		</script>
		<?php
	}

	public static function dependency_notice() {
		if ( ! current_user_can( 'cfs_view_submissions' ) || CFS_Submissions::available() ) { return; }
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Client Fluent Submissions requires Fluent Forms to be active.', 'client-fluent-submissions' ) . '</p></div>';
	}

	public static function render() {
		if ( ! current_user_can( 'cfs_view_submissions' ) ) { wp_die( esc_html__( 'You do not have permission to access this page.', 'client-fluent-submissions' ) ); }
		if ( ! CFS_Submissions::available() ) { echo '<div class="wrap"><h1>' . esc_html__( 'Enquiries', 'client-fluent-submissions' ) . '</h1><p>' . esc_html__( 'Fluent Forms is not active.', 'client-fluent-submissions' ) . '</p></div>'; return; }
		$forms = array_filter( CFS_Config::forms(), static function( $form ) { return CFS_Submissions::user_can_view( $form['id'] ); } );
		if ( ! $forms ) { echo '<div class="wrap"><h1>' . esc_html__( 'Enquiries', 'client-fluent-submissions' ) . '</h1><p>' . esc_html__( 'There are no configured forms that you can access.', 'client-fluent-submissions' ) . '</p></div>'; return; }
		$form_id = absint( $_GET['form_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $forms[ $form_id ] ) ) { $form_id = (int) array_key_first( $forms ); }
		$config = $forms[ $form_id ];
		if ( absint( $_GET['submission_id'] ?? 0 ) ) { self::detail( $config, absint( $_GET['submission_id'] ) ); return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::listing( $forms, $config );
	}

	/** Render the small Settings API screen used to configure forms and labels. */
	public static function settings() {
		if ( ! current_user_can( 'cfs_manage_submissions' ) ) { wp_die( esc_html__( 'You do not have permission to access this page.', 'client-fluent-submissions' ) ); }
		$forms = get_option( 'cfs_forms_config', array() );
		$forms = is_array( $forms ) ? $forms : array();
		$available_forms = self::available_forms();
		echo '<div class="wrap cfs-wrap"><h1>' . esc_html__( 'Enquiry settings', 'client-fluent-submissions' ) . '</h1>';
		echo '<p>' . esc_html__( 'Choose one of the available Fluent Forms forms and select what the client sees. No form ID or JSON editing is needed.', 'client-fluent-submissions' ) . '</p>';
		echo '<div class="notice notice-warning inline cfs-unsaved-notice" hidden><p><strong>' . esc_html__( 'You have unsaved changes.', 'client-fluent-submissions' ) . '</strong> ' . esc_html__( 'Save the settings before leaving this page.', 'client-fluent-submissions' ) . '</p></div>';
		if ( ! $available_forms ) { echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No available Fluent Forms forms were found.', 'client-fluent-submissions' ) . '</p></div>'; }
		echo '<form method="post" action="options.php">'; settings_fields( 'cfs_settings' );
		echo '<p><label><input type="checkbox" name="cfs_notify_email" value="1" ' . checked( get_option( 'cfs_notify_email', '1' ), '1', false ) . '> ' . esc_html__( 'Send an email notification when a new enquiry is submitted', 'client-fluent-submissions' ) . '</label></p>';
		echo '<div id="cfs-form-configs">';
		foreach ( $forms as $id => $config ) { self::settings_row( $id, $config, $available_forms ); }
		echo '</div><p><button type="button" class="button" id="cfs-add-form" ' . disabled( empty( $available_forms ), true, false ) . '>' . esc_html__( 'Add form', 'client-fluent-submissions' ) . '</button></p>';
		submit_button( __( 'Save settings', 'client-fluent-submissions' ) );
		echo '</form><template id="cfs-form-template">'; self::settings_row( '__INDEX__', array(), $available_forms ); echo '</template>';
		echo '</div>';
	}

	private static function available_forms() {
		if ( ! CFS_Submissions::available() || ! class_exists( '\\FluentForm\\App\\Models\\Form' ) ) { return array(); }
		$forms = \FluentForm\App\Models\Form::orderBy( 'title', 'ASC' )->get();
		$available = array();
		foreach ( $forms as $form ) {
			$form_id = absint( $form->id ?? 0 );
			if ( $form_id && CFS_Submissions::user_can_view( $form_id ) ) { $available[ $form_id ] = sanitize_text_field( $form->title ?? '' ); }
		}
		return $available;
	}

	private static function settings_row( $id, $config, $available_forms ) {
		$title = is_array( $config ) ? ( $config['title'] ?? '' ) : '';
		$columns = is_array( $config ) && isset( $config['columns'] ) ? $config['columns'] : array();
		$sections = is_array( $config ) && isset( $config['sections'] ) ? $config['sections'] : array();
		$inline_details = is_array( $config ) && ! empty( $config['inline_details'] );
		$selected_id = '__INDEX__' === (string) $id ? 0 : absint( $id );
		$name = 'cfs_forms_config[' . $id . ']';
		echo '<section class="cfs-config-card"><div class="cfs-config-actions"><button type="button" class="button cfs-move-form-up" aria-label="' . esc_attr__( 'Move form up', 'client-fluent-submissions' ) . '" title="' . esc_attr__( 'Move form up', 'client-fluent-submissions' ) . '">↑</button><button type="button" class="button cfs-move-form-down" aria-label="' . esc_attr__( 'Move form down', 'client-fluent-submissions' ) . '" title="' . esc_attr__( 'Move form down', 'client-fluent-submissions' ) . '">↓</button><button type="button" class="button-link-delete cfs-remove-form">' . esc_html__( 'Remove', 'client-fluent-submissions' ) . '</button></div>';
		echo '<p><label>' . esc_html__( 'Fluent Form', 'client-fluent-submissions' ) . '<br><select required class="cfs-form-id" name="' . esc_attr( $name ) . '[id]"><option value="">' . esc_html__( 'Select a form', 'client-fluent-submissions' ) . '</option>';
		foreach ( $available_forms as $form_id => $form_title ) { echo '<option value="' . esc_attr( $form_id ) . '" data-title="' . esc_attr( $form_title ) . '" ' . selected( $selected_id, $form_id, false ) . '>' . esc_html( $form_title . ' (#' . $form_id . ')' ) . '</option>'; }
		echo '</select></label></p>';
		echo '<p><label>' . esc_html__( 'Title', 'client-fluent-submissions' ) . '<br><input class="regular-text" type="text" name="' . esc_attr( $name ) . '[title]" value="' . esc_attr( $title ) . '"></label></p>';
		echo '<p><label><input type="checkbox" name="' . esc_attr( $name ) . '[inline_details]" value="1" ' . checked( $inline_details, true, false ) . '> ' . esc_html__( 'Open submission details below the clicked table row', 'client-fluent-submissions' ) . '</label></p>';
		echo '<input class="cfs-columns-json" type="hidden" name="' . esc_attr( $name ) . '[columns_json]" value="' . esc_attr( wp_json_encode( $columns ) ) . '"><input class="cfs-sections-json" type="hidden" name="' . esc_attr( $name ) . '[sections_json]" value="' . esc_attr( wp_json_encode( $sections ) ) . '">';
		echo '<div class="cfs-field-picker">'; self::field_picker( absint( $id ), $columns, $sections ); echo '</div></section>';
	}

	private static function form_fields( $form_id ) {
		if ( ! CFS_Submissions::available() || ! class_exists( '\\FluentForm\\App\\Models\\Form' ) || ! class_exists( '\\FluentForm\\App\\Modules\\Form\\FormFieldsParser' ) ) { return array(); }
		$form = \FluentForm\App\Models\Form::find( absint( $form_id ) );
		if ( ! $form ) { return array(); }
		$inputs = \FluentForm\App\Modules\Form\FormFieldsParser::getEntryInputs( $form, array( 'admin_label' ) );
		$labels = \FluentForm\App\Modules\Form\FormFieldsParser::getAdminLabels( $form, $inputs );
		$fields = array();
		foreach ( $inputs as $key => $input ) {
			$key = sanitize_text_field( (string) $key );
			if ( ! $key ) { continue; }
			$label = $labels[ $key ] ?? $key;
			if ( is_array( $label ) ) { $label = implode( ' / ', array_filter( array_map( 'sanitize_text_field', $label ) ) ); }
			$label = sanitize_text_field( $label );
			$fields[] = array( 'key' => $key, 'label' => $label );
		}
		return $fields;
	}

	private static function field_picker( $form_id, $columns, $sections ) {
		$fields = self::form_fields( $form_id );
		if ( ! $fields ) { echo '<p class="description cfs-picker-empty">' . esc_html__( 'Select a form to load its fields.', 'client-fluent-submissions' ) . '</p>'; return; }
		$detail = array();
		foreach ( $sections as $section => $section_fields ) { foreach ( $section_fields as $key => $label ) { $detail[ $key ] = array( 'section' => $section, 'label' => $label ); } }
		$field_keys = array_column( $fields, 'key' );
		$all_table = count( array_intersect( $field_keys, array_keys( $columns ) ) ) === count( $field_keys );
		$all_detail = count( array_intersect( $field_keys, array_keys( $detail ) ) ) === count( $field_keys );
		echo '<table class="widefat striped cfs-picker-table"><thead><tr><th>' . esc_html__( 'Fluent Forms field', 'client-fluent-submissions' ) . '</th><th>' . esc_html__( 'Client label', 'client-fluent-submissions' ) . '</th><th>' . esc_html__( 'In table', 'client-fluent-submissions' ) . '<br><input type="checkbox" class="cfs-toggle-column" data-target=".cfs-field-table" aria-label="' . esc_attr__( 'Select all fields', 'client-fluent-submissions' ) . '" ' . checked( $all_table, true, false ) . '></th><th>' . esc_html__( 'In detail', 'client-fluent-submissions' ) . '<br><input type="checkbox" class="cfs-toggle-column" data-target=".cfs-field-detail" aria-label="' . esc_attr__( 'Select all fields', 'client-fluent-submissions' ) . '" ' . checked( $all_detail, true, false ) . '></th><th>' . esc_html__( 'Section', 'client-fluent-submissions' ) . '</th></tr></thead><tbody>';
		foreach ( $fields as $field ) {
			$key = $field['key']; $saved = $detail[ $key ] ?? array(); $label = $columns[ $key ] ?? ( $saved['label'] ?? $field['label'] ); $section = $saved['section'] ?? __( 'Information', 'client-fluent-submissions' );
			echo '<tr data-key="' . esc_attr( $key ) . '"><td><strong>' . esc_html( $field['label'] ) . '</strong><br><code>' . esc_html( $key ) . '</code></td><td><input class="regular-text cfs-client-label" type="text" value="' . esc_attr( $label ) . '"></td><td><input class="cfs-field-table" type="checkbox" ' . checked( isset( $columns[ $key ] ), true, false ) . '></td><td><input class="cfs-field-detail" type="checkbox" ' . checked( isset( $detail[ $key ] ), true, false ) . '></td><td><input class="cfs-field-section" type="text" value="' . esc_attr( $section ) . '" placeholder="' . esc_attr__( 'e.g. Contact', 'client-fluent-submissions' ) . '"></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function request_args() {
		$args = array(
			'search'       => sanitize_text_field( $_GET['s'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'search_field' => sanitize_text_field( $_GET['search_field'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'status'       => sanitize_key( $_GET['status'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'read'         => sanitize_key( $_GET['read'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'date_preset'  => sanitize_key( $_GET['date_preset'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'date_from'    => sanitize_text_field( $_GET['date_from'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'date_to'      => sanitize_text_field( $_GET['date_to'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'page'         => max( 1, absint( $_GET['paged'] ?? 1 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'per_page'     => 20,
		);
		if ( in_array( $args['date_preset'], array( 'today', '7days', '30days' ), true ) ) {
			$today = current_datetime();
			$days = 'today' === $args['date_preset'] ? 0 : ( '7days' === $args['date_preset'] ? 6 : 29 );
			$args['date_to'] = $today->format( 'Y-m-d' );
			$args['date_from'] = $today->modify( '-' . $days . ' days' )->format( 'Y-m-d' );
		}
		return $args;
	}

	private static function listing( $forms, $config ) {
		$args = self::request_args();
		$data = CFS_Submissions::list( $config['id'], $args );
		$total_pages = max( 1, (int) ceil( $data['total'] / $args['per_page'] ) );
		$fields = self::form_fields( $config['id'] );
		$can_manage = CFS_Submissions::user_can_manage( $config['id'] );
		echo '<div class="wrap cfs-wrap"><h1>' . esc_html__( 'Enquiries', 'client-fluent-submissions' ) . '</h1>';
		self::form_tabs( $forms, $config['id'] );
		$stats = CFS_Submissions::stats( $config['id'] );
		echo '<div class="cfs-dashboard">';
		foreach ( array( 'total' => __( 'Total', 'client-fluent-submissions' ), 'unread' => __( 'Unread', 'client-fluent-submissions' ), 'new' => __( 'New', 'client-fluent-submissions' ), 'in_progress' => __( 'In progress', 'client-fluent-submissions' ), 'completed' => __( 'Completed', 'client-fluent-submissions' ) ) as $key => $label ) { echo '<div class="cfs-stat"><span>' . esc_html( $label ) . '</span><strong>' . absint( $stats[ $key ] ) . '</strong></div>'; }
		echo '</div>';
		echo '<form class="cfs-filters" method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="form_id" value="' . esc_attr( $config['id'] ) . '">';
		echo '<select name="search_field"><option value="">' . esc_html__( 'All fields', 'client-fluent-submissions' ) . '</option>'; foreach ( $fields as $field ) { echo '<option value="' . esc_attr( $field['key'] ) . '" ' . selected( $args['search_field'], $field['key'], false ) . '>' . esc_html( $field['label'] ) . '</option>'; } echo '</select>';
		echo '<input type="search" name="s" value="' . esc_attr( $args['search'] ) . '" placeholder="' . esc_attr__( 'Search submissions…', 'client-fluent-submissions' ) . '">';
		echo '<select name="status"><option value="">' . esc_html__( 'All statuses', 'client-fluent-submissions' ) . '</option>';
		foreach ( CFS_Submissions::statuses() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $args['status'], $key, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select><select name="read"><option value="">' . esc_html__( 'Read and unread', 'client-fluent-submissions' ) . '</option><option value="unread" ' . selected( $args['read'], 'unread', false ) . '>' . esc_html__( 'Unread', 'client-fluent-submissions' ) . '</option><option value="read" ' . selected( $args['read'], 'read', false ) . '>' . esc_html__( 'Read', 'client-fluent-submissions' ) . '</option></select>';
		echo '<select name="date_preset"><option value="">' . esc_html__( 'Custom period', 'client-fluent-submissions' ) . '</option><option value="today" ' . selected( $args['date_preset'], 'today', false ) . '>' . esc_html__( 'Today', 'client-fluent-submissions' ) . '</option><option value="7days" ' . selected( $args['date_preset'], '7days', false ) . '>' . esc_html__( 'Last 7 days', 'client-fluent-submissions' ) . '</option><option value="30days" ' . selected( $args['date_preset'], '30days', false ) . '>' . esc_html__( 'Last 30 days', 'client-fluent-submissions' ) . '</option></select>';
		echo '<input type="date" name="date_from" value="' . esc_attr( $args['date_from'] ) . '"><input type="date" name="date_to" value="' . esc_attr( $args['date_to'] ) . '"><button class="button button-primary">' . esc_html__( 'Filter', 'client-fluent-submissions' ) . '</button></form>';
		$export_args = array( 'action' => 'cfs_export_csv', 'form_id' => $config['id'], 's' => $args['search'], 'search_field' => $args['search_field'], 'status' => $args['status'], 'read' => $args['read'], 'date_from' => $args['date_from'], 'date_to' => $args['date_to'] );
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( $export_args, admin_url( 'admin-post.php' ) ), 'cfs_export_' . $config['id'] ) ) . '">' . esc_html__( 'Export CSV', 'client-fluent-submissions' ) . '</a></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cfs_bulk_action"><input type="hidden" name="form_id" value="' . esc_attr( $config['id'] ) . '">'; wp_nonce_field( 'cfs_bulk' );
		if ( $can_manage ) { echo '<div class="cfs-bulk"><select name="bulk_action" required><option value="">' . esc_html__( 'Bulk actions', 'client-fluent-submissions' ) . '</option><option value="read">' . esc_html__( 'Mark as read', 'client-fluent-submissions' ) . '</option><option value="unread">' . esc_html__( 'Mark as unread', 'client-fluent-submissions' ) . '</option>'; foreach ( CFS_Submissions::statuses() as $key => $label ) { echo '<option value="status_' . esc_attr( $key ) . '">' . esc_html( sprintf( __( 'Set status: %s', 'client-fluent-submissions' ), $label ) ) . '</option>'; } echo '</select> <button class="button">' . esc_html__( 'Apply', 'client-fluent-submissions' ) . '</button></div>'; }
		if ( ! $data['items'] ) {
			$has_filters = $args['search'] || $args['status'] || $args['read'] || $args['date_from'] || $args['date_to'];
			$empty_text = $has_filters ? __( 'There are no submissions matching the selected filters.', 'client-fluent-submissions' ) : __( 'No enquiries have been submitted yet.', 'client-fluent-submissions' );
			echo '<div class="cfs-empty-state"><span class="dashicons dashicons-email-alt"></span><h2>' . esc_html__( 'No submitted enquiries', 'client-fluent-submissions' ) . '</h2><p>' . esc_html( $empty_text ) . '</p></div></form></div>';
			return;
		}
		$inline_details = ! empty( $config['inline_details'] );
		$colspan = count( $config['columns'] ) + 3 + ( $can_manage ? 1 : 0 );
		echo '<div class="cfs-card"><table class="wp-list-table widefat fixed striped cfs-table"><thead><tr>';
		if ( $can_manage ) { echo '<td class="manage-column check-column"><input type="checkbox" class="cfs-select-all"></td>'; }
		echo '<th>ID</th>';
		foreach ( $config['columns'] as $label ) { echo '<th>' . esc_html( $label ) . '</th>'; }
		echo '<th>' . esc_html__( 'Date', 'client-fluent-submissions' ) . '</th><th>' . esc_html__( 'Actions', 'client-fluent-submissions' ) . '</th></tr></thead><tbody>';
		foreach ( $data['items'] as $item ) {
			$url = add_query_arg( array( 'page' => self::SLUG, 'form_id' => $config['id'], 'submission_id' => $item->id ), admin_url( 'admin.php' ) );
			$row_classes = array_filter( array( $item->cfs_is_read ? '' : 'cfs-row-unread', $inline_details ? 'cfs-submission-row' : '' ) );
			echo '<tr class="' . esc_attr( implode( ' ', $row_classes ) ) . '"' . ( $inline_details ? ' data-form="' . esc_attr( $config['id'] ) . '" data-submission="' . esc_attr( $item->id ) . '" data-colspan="' . esc_attr( $colspan ) . '" tabindex="0" aria-expanded="false"' : '' ) . '>';
			if ( $can_manage ) { echo '<th class="check-column"><input type="checkbox" name="submission_ids[]" value="' . esc_attr( $item->id ) . '"></th>'; }
			echo '<td>#' . esc_html( $item->id ) . '</td>';
			foreach ( $config['columns'] as $key => $label ) { echo '<td>' . self::short_value( self::field_value( $item->cfs_response, $key ) ) . '</td>'; }
			echo '<td>' . esc_html( self::date_display( $item->created_at ) ) . '</td><td><a class="button button-secondary' . ( $inline_details ? ' cfs-inline-toggle' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $inline_details ? ' aria-expanded="false"' : '' ) . '>' . esc_html__( 'View', 'client-fluent-submissions' ) . '</a></td></tr>';
		}
		echo '</tbody></table></div></form>';
		if ( $total_pages > 1 ) { echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => max( 1, $args['page'] ), 'total' => $total_pages ) ) ) . '</div></div>'; }
		echo '</div>';
	}

	private static function detail( $config, $submission_id ) {
		$item = CFS_Submissions::find( $submission_id, $config['id'] );
		if ( ! $item ) { wp_die( esc_html__( 'The enquiry was not found.', 'client-fluent-submissions' ) ); }
		if ( ! $item->cfs_is_read ) { CFS_Submissions::set_read( $submission_id, $config['id'], true ); $item->cfs_is_read = true; }
		$back = add_query_arg( array( 'page' => self::SLUG, 'form_id' => $config['id'] ), admin_url( 'admin.php' ) );
		echo '<div class="wrap cfs-wrap"><a class="page-title-action" href="' . esc_url( $back ) . '">← ' . esc_html__( 'Back to list', 'client-fluent-submissions' ) . '</a><h1>' . esc_html( $config['title'] ) . ' <span class="cfs-muted">#' . esc_html( $item->id ) . '</span></h1>';
		self::detail_content( $config, $item, true );
		echo '</div>';
	}

	private static function detail_content( $config, $item, $allow_note_form = true ) {
		$contacts = self::contact_values( $item->cfs_response );
		echo '<div class="cfs-detail-toolbar"><div class="cfs-meta"><span>' . esc_html__( 'Submitted:', 'client-fluent-submissions' ) . ' ' . esc_html( self::date_display( $item->created_at, true ) ) . '</span>' . self::badge( $item->cfs_status ) . '</div>';
		if ( CFS_Submissions::user_can_manage( $config['id'] ) ) { echo '<div class="cfs-status-actions" data-submission="' . esc_attr( $item->id ) . '" data-form="' . esc_attr( $config['id'] ) . '">'; foreach ( CFS_Submissions::statuses() as $key => $label ) { echo '<button type="button" class="button ' . ( $key === $item->cfs_status ? 'button-primary' : '' ) . '" data-status="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</button>'; } echo '</div>'; }
		if ( $contacts['emails'] || $contacts['phones'] ) {
			echo '<div class="cfs-contact-actions">';
			foreach ( $contacts['emails'] as $email ) { echo '<a class="button" href="mailto:' . esc_attr( $email ) . '"><span class="dashicons dashicons-email"></span>' . esc_html( $email ) . '</a>'; }
			foreach ( $contacts['phones'] as $phone ) { $dial = preg_replace( '/[^\d+]/', '', $phone ); echo '<a class="button" href="tel:' . esc_attr( $dial ) . '"><span class="dashicons dashicons-phone"></span>' . esc_html( $phone ) . '</a><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( 'https://wa.me/' . ltrim( preg_replace( '/\D/', '', $phone ), '0' ) ) . '">WhatsApp</a>'; }
			echo '</div>';
		}
		echo '</div>';
		$duplicate = 0;
		foreach ( array_merge( $contacts['emails'], $contacts['phones'] ) as $contact ) { $duplicate += CFS_Submissions::duplicate_count( $config['id'], $item->id, $contact ); }
		$link_count = preg_match_all( '~https?://~i', wp_json_encode( $item->cfs_response ) );
		if ( $duplicate || $link_count >= 3 ) { echo '<div class="notice notice-warning inline cfs-risk"><p>'; if ( $duplicate ) { echo esc_html__( 'Possible duplicate: the same contact details appear in another submission.', 'client-fluent-submissions' ) . ' '; } if ( $link_count >= 3 ) { echo esc_html__( 'Possible spam: this submission contains several links.', 'client-fluent-submissions' ); } echo '</p></div>'; }
		$shown = array();
		foreach ( $config['sections'] as $title => $fields ) { self::section( $title, $fields, $item->cfs_response, $shown ); }

		// Show every remaining form field after the deliberately arranged sections.
		$remaining = array();
		$form_roots = array();
		foreach ( self::form_fields( $config['id'] ) as $field ) {
			$key = $field['key'];
			$form_roots[ self::field_root( $key ) ] = true;
			if ( ! isset( $shown[ $key ] ) && null !== self::field_value( $item->cfs_response, $key, null ) ) { $remaining[ $key ] = $field['label']; }
		}
		// Preserve useful response values even when Fluent Forms no longer exposes
		// their field definition (for example, after a field was removed).
		foreach ( $item->cfs_response as $key => $value ) {
			$key = sanitize_text_field( (string) $key );
			if ( $key && ! isset( $shown[ $key ] ) && ! isset( $remaining[ $key ] ) && ! isset( $form_roots[ $key ] ) ) { $remaining[ $key ] = self::humanize_key( $key ); }
		}
		if ( $remaining ) { self::section( __( 'Additional information', 'client-fluent-submissions' ), $remaining, $item->cfs_response, $shown ); }
		self::notes_box( $config['id'], $item->id, $allow_note_form );
	}

	private static function contact_values( $response ) {
		$contacts = array( 'emails' => array(), 'phones' => array() );
		array_walk_recursive( $response, static function( $value ) use ( &$contacts ) {
			$value = trim( (string) $value );
			if ( is_email( $value ) ) { $contacts['emails'][] = sanitize_email( $value ); }
			elseif ( preg_match( '/^\+?[\d\s().-]{7,20}$/', $value ) && strlen( preg_replace( '/\D/', '', $value ) ) >= 7 ) { $contacts['phones'][] = $value; }
		} );
		$contacts['emails'] = array_values( array_unique( $contacts['emails'] ) );
		$contacts['phones'] = array_values( array_unique( $contacts['phones'] ) );
		return $contacts;
	}

	private static function notes_box( $form_id, $submission_id, $allow_form = true ) {
		echo '<section class="cfs-detail-card cfs-notes"><h2>' . esc_html__( 'Internal notes', 'client-fluent-submissions' ) . '</h2>';
		$notes = CFS_Submissions::notes( $submission_id );
		if ( ! $notes ) { echo '<p class="cfs-muted">' . esc_html__( 'No internal notes yet.', 'client-fluent-submissions' ) . '</p>'; }
		foreach ( array_reverse( $notes ) as $note ) { $user = get_userdata( absint( $note['user_id'] ?? 0 ) ); echo '<article class="cfs-note"><p>' . nl2br( esc_html( $note['text'] ?? '' ) ) . '</p><small>' . esc_html( $user ? $user->display_name : __( 'Unknown user', 'client-fluent-submissions' ) ) . ' · ' . esc_html( self::date_display( $note['created_at'] ?? '', true ) ) . '</small></article>'; }
		if ( $allow_form && CFS_Submissions::user_can_manage( $form_id ) ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cfs_add_note"><input type="hidden" name="form_id" value="' . esc_attr( $form_id ) . '"><input type="hidden" name="submission_id" value="' . esc_attr( $submission_id ) . '">'; wp_nonce_field( 'cfs_add_note_' . $submission_id ); echo '<textarea name="note" rows="4" required placeholder="' . esc_attr__( 'Write an internal note…', 'client-fluent-submissions' ) . '"></textarea><p><button class="button button-primary">' . esc_html__( 'Add note', 'client-fluent-submissions' ) . '</button></p></form>'; }
		echo '</section>';
	}

	private static function section( $title, $fields, $response, &$shown ) {
		$values = array();
		foreach ( $fields as $key => $label ) { $value = self::field_value( $response, $key, null ); if ( null !== $value ) { $values[ $key ] = array( 'label' => $label, 'value' => $value ); } }
		if ( ! $values ) { return; }
		echo '<section class="cfs-detail-card"><h2>' . esc_html( $title ) . '</h2><dl>';
		foreach ( $values as $key => $field ) { $shown[ $key ] = true; echo '<div><dt>' . esc_html( $field['label'] ) . '</dt><dd>' . self::full_value( $field['value'] ) . '</dd></div>'; }
		echo '</dl></section>';
	}

	private static function field_root( $key ) {
		$path = preg_replace( '/\[([^\]]+)\]/', '.$1', (string) $key );
		return (string) strtok( $path, '.' );
	}

	private static function humanize_key( $key ) {
		$label = preg_replace( '/[_\-]+/', ' ', (string) $key );
		return ucwords( trim( $label ) );
	}

	private static function short_value( $value ) { $value = self::scalar_value( $value ); return esc_html( wp_html_excerpt( $value, 80, '…' ) ); }
	private static function field_value( $response, $key, $default = '' ) {
		if ( array_key_exists( $key, $response ) ) { return $response[ $key ]; }
		// Support compound Fluent Forms values configured as `name.first_name`
		// or `name[first_name]`, while retaining support for ordinary field keys.
		$path = preg_replace( '/\[([^\]]+)\]/', '.$1', $key );
		$value = $response;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) { return $default; }
			$value = $value[ $segment ];
		}
		return $value;
	}
	private static function full_value( $value ) {
		if ( is_array( $value ) ) { $parts = array(); foreach ( $value as $item ) { $parts[] = self::full_value( $item ); } return implode( '<br>', $parts ); }
		$value = (string) $value;
		if ( filter_var( $value, FILTER_VALIDATE_URL ) ) { return '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( basename( wp_parse_url( $value, PHP_URL_PATH ) ?: $value ) ) . '</a>'; }
		return nl2br( esc_html( $value ) );
	}
	private static function scalar_value( $value ) { return is_array( $value ) ? implode( ', ', array_map( array( __CLASS__, 'scalar_value' ), $value ) ) : (string) $value; }
	private static function csv_value( $value ) { $value = (string) $value; return preg_match( '/^[=+\-@]/', $value ) ? "'" . $value : $value; }
	private static function date_display( $date, $time = false ) { $format = $time ? get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) : get_option( 'date_format' ); return mysql2date( $format, $date ); }
	private static function badge( $status ) { $labels = CFS_Submissions::statuses(); return '<span class="cfs-badge cfs-status-' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ?? $labels['new'] ) . '</span>'; }
	private static function form_tabs( $forms, $active ) { if ( count( $forms ) < 2 ) { return; } echo '<nav class="nav-tab-wrapper cfs-tabs">'; foreach ( $forms as $form ) { $url = add_query_arg( array( 'page' => self::SLUG, 'form_id' => $form['id'] ), admin_url( 'admin.php' ) ); echo '<a class="nav-tab ' . ( $active === $form['id'] ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $form['title'] ) . '</a>'; } echo '</nav>'; }

	public static function ajax_submission_detail() {
		check_ajax_referer( 'cfs_submission_detail', 'nonce' );
		$form_id = absint( $_POST['form_id'] ?? 0 );
		$submission_id = absint( $_POST['submission_id'] ?? 0 );
		$config = CFS_Config::get( $form_id );
		if ( ! $config || ! CFS_Submissions::user_can_view( $form_id ) ) { wp_send_json_error( array( 'message' => __( 'You do not have permission for this action.', 'client-fluent-submissions' ) ), 403 ); }
		$item = CFS_Submissions::find( $submission_id, $form_id );
		if ( ! $item ) { wp_send_json_error( array( 'message' => __( 'The enquiry was not found.', 'client-fluent-submissions' ) ), 404 ); }
		if ( ! $item->cfs_is_read ) { CFS_Submissions::set_read( $submission_id, $form_id, true ); $item->cfs_is_read = true; }
		ob_start();
		self::detail_content( $config, $item, false );
		$full_url = add_query_arg( array( 'page' => self::SLUG, 'form_id' => $form_id, 'submission_id' => $submission_id ), admin_url( 'admin.php' ) );
		echo '<p class="cfs-inline-full-view"><a class="button" href="' . esc_url( $full_url ) . '">' . esc_html__( 'Open full view', 'client-fluent-submissions' ) . '</a></p>';
		$html = ob_get_clean();
		wp_send_json_success( array( 'html' => $html ) );
	}

	public static function ajax_update_status() {
		check_ajax_referer( 'cfs_update_status', 'nonce' );
		$form_id = absint( $_POST['form_id'] ?? 0 ); $submission_id = absint( $_POST['submission_id'] ?? 0 ); $status = sanitize_key( $_POST['status'] ?? '' );
		if ( ! CFS_Config::get( $form_id ) || ! CFS_Submissions::user_can_manage( $form_id ) ) { wp_send_json_error( array( 'message' => __( 'You do not have permission for this action.', 'client-fluent-submissions' ) ), 403 ); }
		if ( ! CFS_Submissions::update_status( $submission_id, $form_id, $status ) ) { wp_send_json_error( array( 'message' => __( 'Invalid enquiry or status.', 'client-fluent-submissions' ) ), 400 ); }
		wp_send_json_success( array( 'label' => CFS_Submissions::statuses()[ $status ] ) );
	}

	public static function bulk_action() {
		check_admin_referer( 'cfs_bulk' );
		$form_id = absint( $_POST['form_id'] ?? 0 );
		if ( ! CFS_Config::get( $form_id ) || ! CFS_Submissions::user_can_manage( $form_id ) ) { wp_die( esc_html__( 'You do not have permission for this action.', 'client-fluent-submissions' ) ); }
		$action = sanitize_key( $_POST['bulk_action'] ?? '' );
		$ids = array_unique( array_filter( array_map( 'absint', (array) ( $_POST['submission_ids'] ?? array() ) ) ) );
		foreach ( $ids as $submission_id ) {
			if ( 'read' === $action ) { CFS_Submissions::set_read( $submission_id, $form_id, true ); }
			elseif ( 'unread' === $action ) { CFS_Submissions::set_read( $submission_id, $form_id, false ); }
			elseif ( 0 === strpos( $action, 'status_' ) ) { CFS_Submissions::update_status( $submission_id, $form_id, substr( $action, 7 ) ); }
		}
		wp_safe_redirect( wp_get_referer() ?: add_query_arg( array( 'page' => self::SLUG, 'form_id' => $form_id ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function add_note() {
		$form_id = absint( $_POST['form_id'] ?? 0 );
		$submission_id = absint( $_POST['submission_id'] ?? 0 );
		check_admin_referer( 'cfs_add_note_' . $submission_id );
		if ( ! CFS_Config::get( $form_id ) || ! CFS_Submissions::user_can_manage( $form_id ) ) { wp_die( esc_html__( 'You do not have permission for this action.', 'client-fluent-submissions' ) ); }
		$note = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );
		if ( $note ) { CFS_Submissions::add_note( $submission_id, $form_id, $note ); }
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'form_id' => $form_id, 'submission_id' => $submission_id ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function notify_new_submission( $entry_id, $form_data, $form ) {
		if ( ! get_option( 'cfs_notify_email', '1' ) ) { return; }
		$form_id = absint( is_object( $form ) ? ( $form->id ?? 0 ) : ( is_array( $form ) ? ( $form['id'] ?? 0 ) : 0 ) );
		$config = CFS_Config::get( $form_id );
		if ( ! $config ) { return; }
		$recipient = sanitize_email( get_option( 'admin_email' ) );
		if ( ! $recipient ) { return; }
		$url = add_query_arg( array( 'page' => self::SLUG, 'form_id' => $form_id, 'submission_id' => absint( $entry_id ) ), admin_url( 'admin.php' ) );
		$subject = sprintf( __( 'New enquiry: %s', 'client-fluent-submissions' ), $config['title'] );
		$message = sprintf( __( "A new enquiry #%1\$d was submitted through %2\$s.\n\nOpen it: %3\$s", 'client-fluent-submissions' ), absint( $entry_id ), $config['title'], $url );
		wp_mail( $recipient, $subject, $message );
	}

	public static function export_csv() {
		$form_id = absint( $_GET['form_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'cfs_export_' . $form_id );
		$config = CFS_Config::get( $form_id );
		if ( ! $config || ! CFS_Submissions::user_can_view( $form_id ) ) { wp_die( esc_html__( 'You do not have permission for this action.', 'client-fluent-submissions' ) ); }
		$args = self::request_args();
		$args['per_page'] = 100;
		$args['page'] = 1;
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="submissions-form-' . $form_id . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$output = fopen( 'php://output', 'w' );
		fwrite( $output, "\xEF\xBB\xBF" );
		$headings = array_merge( array( 'ID', __( 'Read', 'client-fluent-submissions' ) ), array_values( $config['columns'] ), array( __( 'Date', 'client-fluent-submissions' ), __( 'Status', 'client-fluent-submissions' ) ) );
		fputcsv( $output, array_map( array( __CLASS__, 'csv_value' ), $headings ) );
		do {
			$data = CFS_Submissions::list( $form_id, $args );
			foreach ( $data['items'] as $item ) {
				$row = array( $item->id, $item->cfs_is_read ? __( 'Read', 'client-fluent-submissions' ) : __( 'Unread', 'client-fluent-submissions' ) );
				foreach ( $config['columns'] as $key => $label ) { $row[] = self::scalar_value( self::field_value( $item->cfs_response, $key ) ); }
				$row[] = self::date_display( $item->created_at, true );
				$row[] = CFS_Submissions::statuses()[ $item->cfs_status ];
				fputcsv( $output, array_map( array( __CLASS__, 'csv_value' ), $row ) );
			}
			$args['page']++;
		} while ( $data['items'] && ( ( $args['page'] - 1 ) * $args['per_page'] < $data['total'] ) );
		fclose( $output );
		exit;
	}

	public static function ajax_form_fields() {
		check_ajax_referer( 'cfs_form_fields', 'nonce' );
		$form_id = absint( $_POST['form_id'] ?? 0 );
		if ( ! current_user_can( 'cfs_manage_submissions' ) || ! CFS_Submissions::user_can_view( $form_id ) ) { wp_send_json_error( array( 'message' => __( 'You do not have permission for this action.', 'client-fluent-submissions' ) ), 403 ); }
		$fields = self::form_fields( $form_id );
		if ( ! $fields ) { wp_send_json_error( array( 'message' => __( 'The form has no available fields or was not found.', 'client-fluent-submissions' ) ), 404 ); }
		wp_send_json_success( array( 'fields' => $fields ) );
	}
}
