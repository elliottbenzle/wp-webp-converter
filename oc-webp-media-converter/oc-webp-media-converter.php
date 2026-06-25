<?php
/**
 * Plugin Name: OC WebP Media Converter
 * Description: Converts JPG/JPEG/PNG Media Library attachments to WebP, updates attachment records, regenerates image sizes, optionally updates database references, and optionally removes original files.
 * Version: 0.2.0
 * Author: TriAd/ChatGPT
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OC_WebP_Media_Converter {
	const PAGE_SLUG        = 'oc-webp-media-converter';
	const NONCE_ACTION     = 'oc_webp_media_converter_run';
	const ERROR_NONCE      = 'oc_webp_media_converter_error_log';
	const DB_VERSION       = '1.0';
	const DB_VERSION_OPTION = 'oc_webp_media_converter_db_version';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade_db' ) );
	}

	public static function activate() {
		self::create_error_log_table();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public function maybe_upgrade_db() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION || ! $this->table_exists( self::get_error_log_table_name() ) ) {
			self::create_error_log_table();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	public static function get_error_log_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'oc_webp_error_log';
	}

	private static function create_error_log_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::get_error_log_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			old_file text NULL,
			old_relative varchar(512) NOT NULL DEFAULT '',
			target_relative varchar(512) NOT NULL DEFAULT '',
			error_message text NOT NULL,
			occurrences bigint(20) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			last_seen_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_id (attachment_id),
			KEY last_seen_at (last_seen_at),
			KEY target_relative (target_relative(191))
		) {$charset_collate};";

		dbDelta( $sql );
	}

	public function add_admin_page() {
		add_management_page(
			'OC WebP Media Converter',
			'OC WebP Converter',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'oc-webp-media-converter' ) );
		}

		$this->maybe_upgrade_db();

		$defaults = array(
			'batch_size'       => 5,
			'quality'          => 82,
			'max_width'        => 0,
			'update_refs'      => 1,
			'delete_originals' => 0,
			'backup_originals' => 1,
		);

		$options     = $defaults;
		$results     = array();
		$notice      = '';
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'converter';
		if ( ! in_array( $current_tab, array( 'converter', 'error-log' ), true ) ) {
			$current_tab = 'converter';
		}

		if ( isset( $_POST['ocwc_delete_error'] ) ) {
			check_admin_referer( self::ERROR_NONCE, 'ocwc_error_nonce' );

			$error_id = absint( $_POST['error_id'] ?? 0 );
			if ( $error_id && $this->delete_error_entry( $error_id ) ) {
				$notice = 'Error log entry deleted. That attachment can be processed again in a future batch.';
			} else {
				$notice = 'Could not delete that error log entry.';
			}

			$current_tab = 'error-log';
		}

		if ( isset( $_POST['ocwc_run_batch'] ) ) {
			check_admin_referer( self::NONCE_ACTION, 'ocwc_nonce' );

			$options = array(
				'batch_size'       => max( 1, min( 25, absint( $_POST['batch_size'] ?? $defaults['batch_size'] ) ) ),
				'quality'          => max( 1, min( 100, absint( $_POST['quality'] ?? $defaults['quality'] ) ) ),
				'max_width'        => max( 0, absint( $_POST['max_width'] ?? $defaults['max_width'] ) ),
				'update_refs'      => ! empty( $_POST['update_refs'] ) ? 1 : 0,
				'delete_originals' => ! empty( $_POST['delete_originals'] ) ? 1 : 0,
				'backup_originals' => ! empty( $_POST['backup_originals'] ) ? 1 : 0,
			);

			$results     = $this->run_batch( $options );
			$current_tab = 'converter';
		}

		$remaining      = $this->get_remaining_count();
		$error_count    = $this->get_error_log_count();
		$webp_supported = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );

		?>
		<div class="wrap">
			<h1>OC WebP Media Converter</h1>

			<?php $this->render_tabs( $current_tab ); ?>

			<?php if ( ! empty( $notice ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<?php if ( 'error-log' === $current_tab ) : ?>
				<?php $this->render_error_log_tab(); ?>
			<?php else : ?>
				<?php if ( ! $webp_supported ) : ?>
					<div class="notice notice-error"><p><strong>WebP is not supported by the current server image editor.</strong> Your GD/Imagick setup must support WebP before conversion can run.</p></div>
				<?php endif; ?>

				<div class="notice notice-warning">
					<p><strong>Make a full file and database backup before using this.</strong> This plugin can rewrite database references and delete original image files.</p>
					<p>Run a small batch first, confirm the site still looks correct, then continue.</p>
				</div>

				<p><strong>Remaining processable JPG/PNG attachments:</strong> <?php echo esc_html( number_format_i18n( $remaining ) ); ?></p>
				<p><strong>Attachments currently skipped because they are in the error log:</strong> <?php echo esc_html( number_format_i18n( $error_count ) ); ?>. Delete an error entry from the Error Log tab if you want that attachment to be attempted again.</p>

				<form method="post">
					<?php wp_nonce_field( self::NONCE_ACTION, 'ocwc_nonce' ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="batch_size">Batch size</label></th>
							<td><input name="batch_size" id="batch_size" type="number" min="1" max="25" value="<?php echo esc_attr( $options['batch_size'] ); ?>" /> <p class="description">Start with 1–5. Larger batches can time out on shared hosting.</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="quality">WebP quality</label></th>
							<td><input name="quality" id="quality" type="number" min="1" max="100" value="<?php echo esc_attr( $options['quality'] ); ?>" /> <p class="description">82 is a good starting point.</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="max_width">Maximum width</label></th>
							<td><input name="max_width" id="max_width" type="number" min="0" value="<?php echo esc_attr( $options['max_width'] ); ?>" /> <p class="description">Use 0 to keep original dimensions. Example: 1800 resizes only images wider than 1800px.</p></td>
						</tr>
						<tr>
							<th scope="row">Database references</th>
							<td><label><input name="update_refs" type="checkbox" value="1" <?php checked( $options['update_refs'], 1 ); ?> /> Replace JPG/PNG URLs and upload paths in posts, postmeta, options, termmeta, usermeta, and commentmeta.</label></td>
						</tr>
						<tr>
							<th scope="row">Original files</th>
							<td>
								<label><input name="backup_originals" type="checkbox" value="1" <?php checked( $options['backup_originals'], 1 ); ?> /> Back up originals before deletion to <code>wp-content/uploads/oc-webp-backup/</code>.</label><br />
								<label><input name="delete_originals" type="checkbox" value="1" <?php checked( $options['delete_originals'], 1 ); ?> /> Delete original JPG/PNG files and their old generated sizes after conversion.</label>
							</td>
						</tr>
					</table>

					<p><button class="button button-primary" name="ocwc_run_batch" value="1" <?php disabled( ! $webp_supported ); ?>>Run Next Batch</button></p>
				</form>

				<?php if ( ! empty( $results ) ) : ?>
					<h2>Batch Results</h2>
					<table class="widefat striped">
						<thead><tr><th>Attachment ID</th><th>Status</th><th>Message</th></tr></thead>
						<tbody>
						<?php foreach ( $results as $result ) : ?>
							<tr>
								<td><?php echo esc_html( $result['id'] ); ?></td>
								<td><?php echo $result['success'] ? '<span style="color:green;">Converted</span>' : '<span style="color:#b32d2e;">Skipped/Error</span>'; ?></td>
								<td><?php echo esc_html( $result['message'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_tabs( $current_tab ) {
		$converter_url = add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
			),
			admin_url( 'tools.php' )
		);

		$error_url = add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'tab'  => 'error-log',
			),
			admin_url( 'tools.php' )
		);
		?>
		<h2 class="nav-tab-wrapper">
			<a href="<?php echo esc_url( $converter_url ); ?>" class="nav-tab <?php echo 'converter' === $current_tab ? 'nav-tab-active' : ''; ?>">Converter</a>
			<a href="<?php echo esc_url( $error_url ); ?>" class="nav-tab <?php echo 'error-log' === $current_tab ? 'nav-tab-active' : ''; ?>">Error Log</a>
		</h2>
		<?php
	}

	private function render_error_log_tab() {
		$per_page = 50;
		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$total    = $this->get_error_log_count();
		$entries  = $this->get_error_log_entries( $paged, $per_page );
		?>
		<h2>Error Log</h2>
		<p>Attachments listed here are skipped during future batches. Delete an entry only when you want the converter to try that attachment again.</p>

		<?php if ( empty( $entries ) ) : ?>
			<p>No error log entries yet.</p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>Attachment ID</th>
						<th>Current File</th>
						<th>Target WebP Path</th>
						<th>Error Message</th>
						<th>Occurrences</th>
						<th>First Logged</th>
						<th>Last Seen</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<?php
					$edit_link = get_edit_post_link( (int) $entry->attachment_id );
					$title     = get_the_title( (int) $entry->attachment_id );
					$label     = $title ? $entry->attachment_id . ' - ' . $title : $entry->attachment_id;
					?>
					<tr>
						<td>
							<?php if ( $edit_link ) : ?>
								<a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $label ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $label ); ?>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $entry->old_relative ); ?></code></td>
						<td><code><?php echo esc_html( $entry->target_relative ); ?></code></td>
						<td><?php echo esc_html( $entry->error_message ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $entry->occurrences ) ); ?></td>
						<td><?php echo esc_html( $entry->created_at ); ?></td>
						<td><?php echo esc_html( $entry->last_seen_at ); ?></td>
						<td>
							<form method="post" style="display:inline;">
								<?php wp_nonce_field( self::ERROR_NONCE, 'ocwc_error_nonce' ); ?>
								<input type="hidden" name="error_id" value="<?php echo esc_attr( (int) $entry->id ); ?>" />
								<button type="submit" class="button button-small" name="ocwc_delete_error" value="1" onclick="return confirm('Delete this error log entry? This attachment will be eligible for processing again.');">Delete</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$total_pages = (int) ceil( $total / $per_page );
			if ( $total_pages > 1 ) {
				$pagination = paginate_links(
					array(
						'base'      => add_query_arg(
							array(
								'page'  => self::PAGE_SLUG,
								'tab'   => 'error-log',
								'paged' => '%#%',
							),
							admin_url( 'tools.php' )
						),
						'format'    => '',
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
						'total'     => $total_pages,
						'current'   => $paged,
					)
				);

				if ( $pagination ) {
					echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $pagination ) . '</div></div>';
				}
			}
			?>
		<?php endif; ?>
		<?php
	}

	private function get_remaining_count() {
		global $wpdb;

		$table = self::get_error_log_table_name();

		$sql = "SELECT COUNT(1)
			FROM {$wpdb->posts} p
			LEFT JOIN `{$table}` e ON e.attachment_id = p.ID
			WHERE p.post_type = 'attachment'
			AND p.post_status = 'inherit'
			AND p.post_mime_type IN ('image/jpeg', 'image/png')
			AND e.attachment_id IS NULL";

		return (int) $wpdb->get_var( $sql );
	}

	private function get_candidate_attachment_ids( $limit ) {
		global $wpdb;

		$table = self::get_error_log_table_name();
		$limit = max( 1, (int) $limit );

		$sql = $wpdb->prepare(
			"SELECT p.ID
			FROM {$wpdb->posts} p
			LEFT JOIN `{$table}` e ON e.attachment_id = p.ID
			WHERE p.post_type = 'attachment'
			AND p.post_status = 'inherit'
			AND p.post_mime_type IN ('image/jpeg', 'image/png')
			AND e.attachment_id IS NULL
			ORDER BY p.ID ASC
			LIMIT %d",
			$limit
		);

		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	private function get_error_log_count() {
		global $wpdb;

		$table = self::get_error_log_table_name();
		return (int) $wpdb->get_var( "SELECT COUNT(1) FROM `{$table}`" );
	}

	private function get_error_log_entries( $paged, $per_page ) {
		global $wpdb;

		$table  = self::get_error_log_table_name();
		$offset = max( 0, ( (int) $paged - 1 ) * (int) $per_page );

		$sql = $wpdb->prepare(
			"SELECT * FROM `{$table}` ORDER BY last_seen_at DESC, id DESC LIMIT %d OFFSET %d",
			(int) $per_page,
			(int) $offset
		);

		return (array) $wpdb->get_results( $sql );
	}

	private function delete_error_entry( $error_id ) {
		global $wpdb;

		$table  = self::get_error_log_table_name();
		$result = $wpdb->delete(
			$table,
			array( 'id' => (int) $error_id ),
			array( '%d' )
		);

		return false !== $result && $result > 0;
	}

	private function run_batch( $options ) {
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return array(
				array(
					'id'      => '-',
					'success' => false,
					'message' => 'This server image editor does not support WebP output.',
				),
			);
		}

		$attachment_ids = $this->get_candidate_attachment_ids( (int) $options['batch_size'] );

		if ( empty( $attachment_ids ) ) {
			return array(
				array(
					'id'      => '-',
					'success' => true,
					'message' => 'No processable JPG or PNG attachments remain. Attachments in the error log are skipped until their error entries are deleted.',
				),
			);
		}

		$results = array();
		foreach ( $attachment_ids as $attachment_id ) {
			$results[] = $this->convert_attachment( (int) $attachment_id, $options );
		}

		return $results;
	}

	private function convert_attachment( $attachment_id, $options ) {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$old_file = get_attached_file( $attachment_id );
		if ( empty( $old_file ) || ! file_exists( $old_file ) ) {
			return $this->result( $attachment_id, false, 'Original file is missing on the server.' );
		}

		$old_file = wp_normalize_path( $old_file );
		$ext      = strtolower( pathinfo( $old_file, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			return $this->result( $attachment_id, false, 'Not a JPG, JPEG, or PNG file.' );
		}

		$new_file = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $old_file );
		$new_rel  = $this->absolute_to_upload_relative( $new_file );
		$old_rel  = get_post_meta( $attachment_id, '_wp_attached_file', true );

		if ( empty( $new_rel ) || empty( $old_rel ) ) {
			return $this->result( $attachment_id, false, 'Could not resolve upload paths.' );
		}

		$existing_attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s AND post_id <> %d LIMIT 1",
				$new_rel,
				$attachment_id
			)
		);

		if ( $existing_attachment_id ) {
			return $this->result( $attachment_id, false, 'Skipped because another attachment already uses the target WebP path: ' . $new_rel );
		}

		$old_url = wp_get_attachment_url( $attachment_id );
		$new_url = $this->relative_upload_to_url( $new_rel );

		$old_meta = wp_get_attachment_metadata( $attachment_id );

		$editor = wp_get_image_editor( $old_file );
		if ( is_wp_error( $editor ) ) {
			return $this->result( $attachment_id, false, 'Image editor error: ' . $editor->get_error_message() );
		}

		$editor->set_quality( (int) $options['quality'] );

		$max_width = (int) $options['max_width'];
		if ( $max_width > 0 ) {
			$size = $editor->get_size();
			if ( ! empty( $size['width'] ) && (int) $size['width'] > $max_width ) {
				$resized = $editor->resize( $max_width, null, false );
				if ( is_wp_error( $resized ) ) {
					return $this->result( $attachment_id, false, 'Resize error: ' . $resized->get_error_message() );
				}
			}
		}

		$saved = $editor->save( $new_file, 'image/webp' );
		if ( is_wp_error( $saved ) ) {
			return $this->result( $attachment_id, false, 'WebP save error: ' . $saved->get_error_message() );
		}

		if ( empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
			return $this->result( $attachment_id, false, 'WebP file was not created.' );
		}

		update_attached_file( $attachment_id, $new_file );

		wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => 'image/webp',
				'guid'           => $new_url,
			)
		);

		$new_meta = wp_generate_attachment_metadata( $attachment_id, $new_file );
		if ( is_array( $new_meta ) && ! empty( $new_meta ) ) {
			wp_update_attachment_metadata( $attachment_id, $new_meta );
		}

		$replacement_count = 0;
		if ( ! empty( $options['update_refs'] ) ) {
			$replacement_count = $this->replace_database_references( $old_url, $new_url, $old_rel, $new_rel );
		}

		$deleted_count = 0;
		if ( ! empty( $options['delete_originals'] ) ) {
			$deleted_count = $this->delete_old_files( $old_file, $old_meta, ! empty( $options['backup_originals'] ) );
		}

		$message = 'Converted to ' . basename( $new_file ) . '. DB rows updated: ' . (int) $replacement_count . '. Old files deleted: ' . (int) $deleted_count . '.';

		return $this->result( $attachment_id, true, $message );
	}

	private function result( $id, $success, $message ) {
		if ( ! $success && is_numeric( $id ) && (int) $id > 0 ) {
			$this->log_error( (int) $id, $message );
		}

		return array(
			'id'      => $id,
			'success' => (bool) $success,
			'message' => $message,
		);
	}

	private function log_error( $attachment_id, $message ) {
		global $wpdb;

		$this->maybe_upgrade_db();

		$table           = self::get_error_log_table_name();
		$old_file        = get_attached_file( $attachment_id );
		$old_file        = $old_file ? wp_normalize_path( $old_file ) : '';
		$old_relative    = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$target_relative = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $old_relative );
		if ( $target_relative === $old_relative ) {
			$target_relative = '';
		}

		$now = current_time( 'mysql' );

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE attachment_id = %d LIMIT 1",
				$attachment_id
			)
		);

		if ( $existing_id ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$table}`
					SET old_file = %s,
						old_relative = %s,
						target_relative = %s,
						error_message = %s,
						occurrences = occurrences + 1,
						last_seen_at = %s
					WHERE id = %d",
					$old_file,
					$old_relative,
					$target_relative,
					$message,
					$now,
					(int) $existing_id
				)
			);
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'attachment_id'   => $attachment_id,
				'old_file'        => $old_file,
				'old_relative'    => $old_relative,
				'target_relative' => $target_relative,
				'error_message'   => $message,
				'occurrences'     => 1,
				'created_at'      => $now,
				'last_seen_at'    => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	private function replace_database_references( $old_url, $new_url, $old_rel, $new_rel ) {
		global $wpdb;

		$old_url_http  = preg_replace( '/^https:/i', 'http:', $old_url );
		$old_url_https = preg_replace( '/^http:/i', 'https:', $old_url );
		$new_url_http  = preg_replace( '/^https:/i', 'http:', $new_url );
		$new_url_https = preg_replace( '/^http:/i', 'https:', $new_url );

		$replacements = array(
			$old_url       => $new_url,
			$old_url_http  => $new_url_http,
			$old_url_https => $new_url_https,
			$old_rel       => $new_rel,
			str_replace( '/', '\\/', $old_url )       => str_replace( '/', '\\/', $new_url ),
			str_replace( '/', '\\/', $old_url_http )  => str_replace( '/', '\\/', $new_url_http ),
			str_replace( '/', '\\/', $old_url_https ) => str_replace( '/', '\\/', $new_url_https ),
			str_replace( '/', '\\/', $old_rel )       => str_replace( '/', '\\/', $new_rel ),
		);

		$replacements = array_filter(
			$replacements,
			function ( $value, $key ) {
				return is_string( $key ) && '' !== $key && is_string( $value ) && '' !== $value && $key !== $value;
			},
			ARRAY_FILTER_USE_BOTH
		);

		$updated_rows = 0;

		$updated_rows += $this->replace_in_table_column( $wpdb->posts, 'ID', 'post_content', $replacements );
		$updated_rows += $this->replace_in_table_column( $wpdb->posts, 'ID', 'post_excerpt', $replacements );
		$updated_rows += $this->replace_in_table_column( $wpdb->posts, 'ID', 'post_content_filtered', $replacements );
		$updated_rows += $this->replace_in_table_column( $wpdb->postmeta, 'meta_id', 'meta_value', $replacements );
		$updated_rows += $this->replace_in_table_column( $wpdb->options, 'option_id', 'option_value', $replacements );

		if ( isset( $wpdb->termmeta ) ) {
			$updated_rows += $this->replace_in_table_column( $wpdb->termmeta, 'meta_id', 'meta_value', $replacements );
		}
		if ( isset( $wpdb->usermeta ) ) {
			$updated_rows += $this->replace_in_table_column( $wpdb->usermeta, 'umeta_id', 'meta_value', $replacements );
		}
		if ( isset( $wpdb->commentmeta ) ) {
			$updated_rows += $this->replace_in_table_column( $wpdb->commentmeta, 'meta_id', 'meta_value', $replacements );
		}

		return $updated_rows;
	}

	private function replace_in_table_column( $table, $id_column, $value_column, $replacements ) {
		global $wpdb;

		if ( empty( $table ) || empty( $replacements ) || ! $this->table_exists( $table ) ) {
			return 0;
		}

		$where_parts = array();
		$where_args  = array();

		foreach ( array_keys( $replacements ) as $needle ) {
			$where_parts[] = "`{$value_column}` LIKE %s";
			$where_args[]  = '%' . $wpdb->esc_like( $needle ) . '%';
		}

		$sql  = "SELECT `{$id_column}` AS row_id, `{$value_column}` AS row_value FROM `{$table}` WHERE " . implode( ' OR ', $where_parts );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $where_args ) );

		if ( empty( $rows ) ) {
			return 0;
		}

		$updated = 0;
		foreach ( $rows as $row ) {
			$old_value = $row->row_value;
			$new_value = $this->replace_value_preserving_serialization( $old_value, $replacements );

			if ( $new_value !== $old_value ) {
				$result = $wpdb->update(
					$table,
					array( $value_column => $new_value ),
					array( $id_column => $row->row_id ),
					array( '%s' ),
					array( is_numeric( $row->row_id ) ? '%d' : '%s' )
				);

				if ( false !== $result ) {
					$updated++;
				}
			}
		}

		return $updated;
	}

	private function replace_value_preserving_serialization( $value, $replacements ) {
		if ( ! is_serialized( $value ) ) {
			return str_replace( array_keys( $replacements ), array_values( $replacements ), $value );
		}

		$data     = maybe_unserialize( $value );
		$new_data = $this->replace_in_mixed_value( $data, $replacements );

		return maybe_serialize( $new_data );
	}

	private function replace_in_mixed_value( $data, $replacements ) {
		if ( is_string( $data ) ) {
			return str_replace( array_keys( $replacements ), array_values( $replacements ), $data );
		}

		if ( is_array( $data ) ) {
			$new = array();
			foreach ( $data as $key => $value ) {
				$new_key         = is_string( $key ) ? str_replace( array_keys( $replacements ), array_values( $replacements ), $key ) : $key;
				$new[ $new_key ] = $this->replace_in_mixed_value( $value, $replacements );
			}
			return $new;
		}

		if ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $key => $value ) {
				$data->{$key} = $this->replace_in_mixed_value( $value, $replacements );
			}
			return $data;
		}

		return $data;
	}

	private function delete_old_files( $old_file, $old_meta, $backup_first ) {
		$files = array( $old_file );
		$dir   = dirname( $old_file );

		if ( is_array( $old_meta ) ) {
			if ( ! empty( $old_meta['sizes'] ) && is_array( $old_meta['sizes'] ) ) {
				foreach ( $old_meta['sizes'] as $size ) {
					if ( ! empty( $size['file'] ) ) {
						$files[] = wp_normalize_path( trailingslashit( $dir ) . $size['file'] );
					}
				}
			}

			if ( ! empty( $old_meta['original_image'] ) ) {
				$files[] = wp_normalize_path( trailingslashit( $dir ) . $old_meta['original_image'] );
			}
		}

		$files = array_unique( array_filter( $files ) );
		$count = 0;

		foreach ( $files as $file ) {
			$file = wp_normalize_path( $file );
			if ( ! file_exists( $file ) || ! $this->is_inside_uploads( $file ) ) {
				continue;
			}

			if ( $backup_first ) {
				$this->backup_file( $file );
			}

			if ( @unlink( $file ) ) {
				$count++;
			}
		}

		return $count;
	}

	private function backup_file( $file ) {
		$uploads  = wp_upload_dir();
		$basedir  = wp_normalize_path( $uploads['basedir'] );
		$file     = wp_normalize_path( $file );
		$relative = ltrim( str_replace( trailingslashit( $basedir ), '', $file ), '/' );
		$backup   = wp_normalize_path( trailingslashit( $basedir ) . 'oc-webp-backup/' . $relative );

		wp_mkdir_p( dirname( $backup ) );
		@copy( $file, $backup );
	}

	private function is_inside_uploads( $file ) {
		$uploads = wp_upload_dir();
		$basedir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		$file    = wp_normalize_path( $file );

		return 0 === strpos( $file, $basedir );
	}

	private function absolute_to_upload_relative( $file ) {
		$uploads = wp_upload_dir();
		$basedir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		$file    = wp_normalize_path( $file );

		if ( 0 !== strpos( $file, $basedir ) ) {
			return '';
		}

		return ltrim( str_replace( $basedir, '', $file ), '/' );
	}

	private function relative_upload_to_url( $relative ) {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['baseurl'] ) . ltrim( str_replace( '\\', '/', $relative ), '/' );
	}

	private function table_exists( $table ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}
}

register_activation_hook( __FILE__, array( 'OC_WebP_Media_Converter', 'activate' ) );

new OC_WebP_Media_Converter();
