<?php
/** Admin UI and authenticated status AJAX endpoint. */

defined( 'ABSPATH' ) || exit;

class CFS_Admin_Page {
	const SLUG = 'client-fluent-submissions';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'dependency_notice' ) );
		add_action( 'wp_ajax_cfs_update_status', array( __CLASS__, 'ajax_update_status' ) );
		add_action( 'wp_ajax_cfs_form_fields', array( __CLASS__, 'ajax_form_fields' ) );
	}

	public static function activate() {
		$role = get_role( 'administrator' );
		if ( $role ) { $role->add_cap( 'cfs_view_submissions' ); $role->add_cap( 'cfs_manage_submissions' ); }
	}

	public static function menu() {
		add_menu_page( __( 'Enquiries', 'client-fluent-submissions' ), __( 'Enquiries', 'client-fluent-submissions' ), 'cfs_view_submissions', self::SLUG, array( __CLASS__, 'render' ), 'dashicons-email-alt2', 26 );
		add_submenu_page( self::SLUG, __( 'Enquiry settings', 'client-fluent-submissions' ), __( 'Settings', 'client-fluent-submissions' ), 'cfs_manage_submissions', self::SLUG . '-settings', array( __CLASS__, 'settings' ) );
	}

	public static function register_settings() {
		register_setting( 'cfs_settings', 'cfs_forms_config', array( 'sanitize_callback' => array( 'CFS_Config', 'sanitize_settings' ) ) );
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook && self::SLUG . '_page_' . self::SLUG . '-settings' !== $hook ) { return; }
		wp_enqueue_style( 'cfs-admin', CFS_URL . 'assets/admin.css', array(), CFS_VERSION );
		wp_enqueue_script( 'cfs-admin', CFS_URL . 'assets/admin.js', array(), CFS_VERSION, true );
		wp_localize_script( 'cfs-admin', 'cfsAdmin', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'cfs_update_status' ), 'fieldsNonce' => wp_create_nonce( 'cfs_form_fields' ), 'error' => __( 'The status could not be saved. Please try again.', 'client-fluent-submissions' ), 'fieldsError' => __( 'The fields could not be loaded. Check the Form ID.', 'client-fluent-submissions' ), 'pickerHeadings' => array( __( 'Fluent Forms field', 'client-fluent-submissions' ), __( 'Client label', 'client-fluent-submissions' ), __( 'In table', 'client-fluent-submissions' ), __( 'In detail', 'client-fluent-submissions' ), __( 'Section', 'client-fluent-submissions' ) ), 'defaultSection' => __( 'Information', 'client-fluent-submissions' ), 'sectionPlaceholder' => __( 'e.g. Contact', 'client-fluent-submissions' ) ) );
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
		echo '<div class="wrap cfs-wrap"><h1>' . esc_html__( 'Enquiry settings', 'client-fluent-submissions' ) . '</h1>';
		echo '<p>' . esc_html__( 'Add a Fluent Forms form, load its fields, and choose what the client sees. No JSON editing is needed.', 'client-fluent-submissions' ) . '</p>';
		echo '<form method="post" action="options.php">'; settings_fields( 'cfs_settings' ); echo '<div id="cfs-form-configs">';
		foreach ( $forms as $id => $config ) { self::settings_row( $id, $config ); }
		echo '</div><p><button type="button" class="button" id="cfs-add-form">' . esc_html__( 'Add form', 'client-fluent-submissions' ) . '</button></p>';
		submit_button( __( 'Save settings', 'client-fluent-submissions' ) );
		echo '</form><template id="cfs-form-template">'; self::settings_row( '__INDEX__', array() ); echo '</template>';
		echo '</div>';
	}

	private static function settings_row( $id, $config ) {
		$title = is_array( $config ) ? ( $config['title'] ?? '' ) : '';
		$columns = is_array( $config ) && isset( $config['columns'] ) ? $config['columns'] : array();
		$sections = is_array( $config ) && isset( $config['sections'] ) ? $config['sections'] : array();
		$name = 'cfs_forms_config[' . $id . ']';
		echo '<section class="cfs-config-card"><button type="button" class="button-link-delete cfs-remove-form">' . esc_html__( 'Remove', 'client-fluent-submissions' ) . '</button>';
		echo '<p><label>' . esc_html__( 'Fluent Form ID', 'client-fluent-submissions' ) . '<br><input required class="cfs-form-id" type="number" min="1" name="' . esc_attr( $name ) . '[id]" value="' . esc_attr( '__INDEX__' === (string) $id ? '' : $id ) . '"></label> <button type="button" class="button cfs-load-fields">' . esc_html__( 'Load fields', 'client-fluent-submissions' ) . '</button></p>';
		echo '<p><label>' . esc_html__( 'Title', 'client-fluent-submissions' ) . '<br><input class="regular-text" type="text" name="' . esc_attr( $name ) . '[title]" value="' . esc_attr( $title ) . '"></label></p>';
		echo '<input class="cfs-columns-json" type="hidden" name="' . esc_attr( $name ) . '[columns_json]" value="' . esc_attr( wp_json_encode( $columns ) ) . '"><input class="cfs-sections-json" type="hidden" name="' . esc_attr( $name ) . '[sections_json]" value="' . esc_attr( wp_json_encode( $sections ) ) . '">';
		echo '<div class="cfs-field-picker">'; self::field_picker( absint( $id ), $columns, $sections ); echo '</div></section>';
	}

	private static function form_fields( $form_id ) {
		if ( ! CFS_Submissions::available() || ! class_exists( '\\FluentForm\\App\\Models\\Form' ) || ! class_exists( '\\FluentForm\\App\\Modules\\Form\\FormFieldsParser' ) ) { return array(); }
		$form = \FluentForm\App\Models\Form::find( absint( $form_id ) );
		if ( ! $form ) { return array(); }
		$inputs = \FluentForm\App\Modules\Form\FormFieldsParser::getEntryInputs( $form, array( 'admin_label' ) );
		$fields = array();
		foreach ( $inputs as $key => $input ) {
			$key = sanitize_text_field( (string) $key );
			if ( ! $key ) { continue; }
			$label = is_array( $input ) && ! empty( $input['admin_label'] ) ? sanitize_text_field( $input['admin_label'] ) : $key;
			$fields[] = array( 'key' => $key, 'label' => $label );
		}
		return $fields;
	}

	private static function field_picker( $form_id, $columns, $sections ) {
		$fields = self::form_fields( $form_id );
		if ( ! $fields ) { echo '<p class="description cfs-picker-empty">' . esc_html__( 'Enter a Form ID and click “Load fields”.', 'client-fluent-submissions' ) . '</p>'; return; }
		$detail = array();
		foreach ( $sections as $section => $section_fields ) { foreach ( $section_fields as $key => $label ) { $detail[ $key ] = array( 'section' => $section, 'label' => $label ); } }
		echo '<table class="widefat striped cfs-picker-table"><thead><tr><th>' . esc_html__( 'Fluent Forms field', 'client-fluent-submissions' ) . '</th><th>' . esc_html__( 'Client label', 'client-fluent-submissions' ) . '</th><th>' . esc_html__( 'In table', 'client-fluent-submissions' ) . '</th><th>' . esc_html__( 'In detail', 'client-fluent-submissions' ) . '</th><th>' . esc_html__( 'Section', 'client-fluent-submissions' ) . '</th></tr></thead><tbody>';
		foreach ( $fields as $field ) {
			$key = $field['key']; $saved = $detail[ $key ] ?? array(); $label = $columns[ $key ] ?? ( $saved['label'] ?? $field['label'] ); $section = $saved['section'] ?? __( 'Information', 'client-fluent-submissions' );
			echo '<tr data-key="' . esc_attr( $key ) . '"><td><strong>' . esc_html( $field['label'] ) . '</strong><br><code>' . esc_html( $key ) . '</code></td><td><input class="regular-text cfs-client-label" type="text" value="' . esc_attr( $label ) . '"></td><td><input class="cfs-field-table" type="checkbox" ' . checked( isset( $columns[ $key ] ), true, false ) . '></td><td><input class="cfs-field-detail" type="checkbox" ' . checked( isset( $detail[ $key ] ), true, false ) . '></td><td><input class="cfs-field-section" type="text" value="' . esc_attr( $section ) . '" placeholder="' . esc_attr__( 'e.g. Contact', 'client-fluent-submissions' ) . '"></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function listing( $forms, $config ) {
		$args = array( 'search' => sanitize_text_field( $_GET['s'] ?? '' ), 'status' => sanitize_key( $_GET['status'] ?? '' ), 'date_from' => sanitize_text_field( $_GET['date_from'] ?? '' ), 'date_to' => sanitize_text_field( $_GET['date_to'] ?? '' ), 'page' => absint( $_GET['paged'] ?? 1 ), 'per_page' => 20 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$data = CFS_Submissions::list( $config['id'], $args );
		$total_pages = max( 1, (int) ceil( $data['total'] / $args['per_page'] ) );
		echo '<div class="wrap cfs-wrap"><h1>' . esc_html__( 'Enquiries', 'client-fluent-submissions' ) . '</h1>';
		self::form_tabs( $forms, $config['id'] );
		echo '<form class="cfs-filters" method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="form_id" value="' . esc_attr( $config['id'] ) . '">';
		echo '<input type="search" name="s" value="' . esc_attr( $args['search'] ) . '" placeholder="' . esc_attr__( 'Search by name, email or phone…', 'client-fluent-submissions' ) . '">';
		echo '<select name="status"><option value="">' . esc_html__( 'All statuses', 'client-fluent-submissions' ) . '</option>';
		foreach ( CFS_Submissions::statuses() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $args['status'], $key, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select><input type="date" name="date_from" value="' . esc_attr( $args['date_from'] ) . '"><input type="date" name="date_to" value="' . esc_attr( $args['date_to'] ) . '"><button class="button button-primary">' . esc_html__( 'Filter', 'client-fluent-submissions' ) . '</button></form>';
		echo '<div class="cfs-card"><table class="wp-list-table widefat fixed striped cfs-table"><thead><tr><th>ID</th>';
		foreach ( $config['columns'] as $label ) { echo '<th>' . esc_html( $label ) . '</th>'; }
		echo '<th>' . esc_html__( 'Date', 'client-fluent-submissions' ) . '</th><th>' . esc_html__( 'Status', 'client-fluent-submissions' ) . '</th><th>' . esc_html__( 'Actions', 'client-fluent-submissions' ) . '</th></tr></thead><tbody>';
		if ( ! $data['items'] ) { echo '<tr><td colspan="99">' . esc_html__( 'No enquiries found.', 'client-fluent-submissions' ) . '</td></tr>'; }
		foreach ( $data['items'] as $item ) {
			$url = add_query_arg( array( 'page' => self::SLUG, 'form_id' => $config['id'], 'submission_id' => $item->id ), admin_url( 'admin.php' ) );
			echo '<tr><td>#' . esc_html( $item->id ) . '</td>';
			foreach ( $config['columns'] as $key => $label ) { echo '<td>' . self::short_value( self::field_value( $item->cfs_response, $key ) ) . '</td>'; }
			echo '<td>' . esc_html( self::date_display( $item->created_at ) ) . '</td><td>' . self::badge( $item->cfs_status ) . '</td><td><a class="button button-secondary" href="' . esc_url( $url ) . '">' . esc_html__( 'View', 'client-fluent-submissions' ) . '</a></td></tr>';
		}
		echo '</tbody></table></div>';
		if ( $total_pages > 1 ) { echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => max( 1, $args['page'] ), 'total' => $total_pages ) ) ) . '</div></div>'; }
		echo '</div>';
	}

	private static function detail( $config, $submission_id ) {
		$item = CFS_Submissions::find( $submission_id, $config['id'] );
		if ( ! $item ) { wp_die( esc_html__( 'The enquiry was not found.', 'client-fluent-submissions' ) ); }
		$back = add_query_arg( array( 'page' => self::SLUG, 'form_id' => $config['id'] ), admin_url( 'admin.php' ) );
		echo '<div class="wrap cfs-wrap"><a class="page-title-action" href="' . esc_url( $back ) . '">← ' . esc_html__( 'Back to list', 'client-fluent-submissions' ) . '</a><h1>' . esc_html( $config['title'] ) . ' <span class="cfs-muted">#' . esc_html( $item->id ) . '</span></h1><div class="cfs-meta"><span>' . esc_html__( 'Submitted:', 'client-fluent-submissions' ) . ' ' . esc_html( self::date_display( $item->created_at, true ) ) . '</span>' . self::badge( $item->cfs_status ) . '</div>';
		if ( CFS_Submissions::user_can_manage( $config['id'] ) ) { echo '<div class="cfs-status-actions" data-submission="' . esc_attr( $item->id ) . '" data-form="' . esc_attr( $config['id'] ) . '">'; foreach ( CFS_Submissions::statuses() as $key => $label ) { echo '<button class="button ' . ( $key === $item->cfs_status ? 'button-primary' : '' ) . '" data-status="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</button>'; } echo '</div>'; }
		$shown = array();
		foreach ( $config['sections'] as $title => $fields ) { self::section( $title, $fields, $item->cfs_response, $shown ); }
		// Deliberately only show configured labels: no raw Fluent Forms input IDs leak into the client view.
		echo '</div>';
	}

	private static function section( $title, $fields, $response, &$shown ) {
		echo '<section class="cfs-detail-card"><h2>' . esc_html( $title ) . '</h2><dl>';
		foreach ( $fields as $key => $label ) { $value = self::field_value( $response, $key, null ); if ( null === $value ) { continue; } $shown[] = $key; echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . self::full_value( $value ) . '</dd></div>'; }
		echo '</dl></section>';
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
	private static function date_display( $date, $time = false ) { $format = $time ? get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) : get_option( 'date_format' ); return mysql2date( $format, $date ); }
	private static function badge( $status ) { $labels = CFS_Submissions::statuses(); return '<span class="cfs-badge cfs-status-' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ?? $labels['new'] ) . '</span>'; }
	private static function form_tabs( $forms, $active ) { if ( count( $forms ) < 2 ) { return; } echo '<nav class="nav-tab-wrapper cfs-tabs">'; foreach ( $forms as $form ) { $url = add_query_arg( array( 'page' => self::SLUG, 'form_id' => $form['id'] ), admin_url( 'admin.php' ) ); echo '<a class="nav-tab ' . ( $active === $form['id'] ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $form['title'] ) . '</a>'; } echo '</nav>'; }

	public static function ajax_update_status() {
		check_ajax_referer( 'cfs_update_status', 'nonce' );
		$form_id = absint( $_POST['form_id'] ?? 0 ); $submission_id = absint( $_POST['submission_id'] ?? 0 ); $status = sanitize_key( $_POST['status'] ?? '' );
		if ( ! CFS_Config::get( $form_id ) || ! CFS_Submissions::user_can_manage( $form_id ) ) { wp_send_json_error( array( 'message' => __( 'You do not have permission for this action.', 'client-fluent-submissions' ) ), 403 ); }
		if ( ! CFS_Submissions::update_status( $submission_id, $form_id, $status ) ) { wp_send_json_error( array( 'message' => __( 'Invalid enquiry or status.', 'client-fluent-submissions' ) ), 400 ); }
		wp_send_json_success( array( 'label' => CFS_Submissions::statuses()[ $status ] ) );
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
