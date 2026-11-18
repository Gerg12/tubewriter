<?php
/**
 * Muse connector for UploadWriter (lean).
 *
 * Base:   {site}/wp-json/uw/v1
 * Header: x-uw-muse-key: {key}
 * Key:    UploadWriter → Muse, or UW_MUSE_KEY in wp-config.
 *
 * Muse may create/update draft pages only. Nothing publishes from these routes.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const UW_MUSE_OPTION_KEY = 'uw_muse_key';
const UW_MUSE_HEADER     = 'x-uw-muse-key';
const UW_MUSE_META_EXT   = '_uw_muse_external_id';

function uw_muse_key() {
	if ( defined( 'UW_MUSE_KEY' ) && UW_MUSE_KEY ) {
		return (string) UW_MUSE_KEY;
	}
	return (string) get_option( UW_MUSE_OPTION_KEY, '' );
}

function uw_muse_has_key() {
	return '' !== uw_muse_key();
}

function uw_muse_base_url() {
	return home_url( '/wp-json/uw/v1' );
}

/**
 * @param WP_REST_Request $request Request.
 * @return true|WP_Error
 */
function uw_muse_permission( WP_REST_Request $request ) {
	$expected = uw_muse_key();
	if ( '' === $expected ) {
		return new WP_Error( 'uw_muse_no_key', 'Muse key is not configured.', array( 'status' => 503 ) );
	}
	$sent = $request->get_header( UW_MUSE_HEADER );
	if ( ! $sent ) {
		$auth = (string) $request->get_header( 'authorization' );
		if ( preg_match( '/^Bearer\s+(.+)$/i', $auth, $m ) ) {
			$sent = trim( $m[1] );
		}
	}
	$sent = is_string( $sent ) ? trim( $sent ) : '';
	if ( '' === $sent || ! hash_equals( $expected, $sent ) ) {
		return new WP_Error( 'uw_muse_unauthorized', 'Missing or invalid Muse key.', array( 'status' => 401 ) );
	}
	return true;
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'uw/v1',
			'/muse/schema',
			array(
				'methods'             => 'GET',
				'callback'            => 'uw_muse_rest_schema',
				'permission_callback' => 'uw_muse_permission',
			)
		);
		register_rest_route(
			'uw/v1',
			'/muse/pages',
			array(
				'methods'             => 'POST',
				'callback'            => 'uw_muse_rest_pages',
				'permission_callback' => 'uw_muse_permission',
			)
		);
	}
);

function uw_muse_rest_schema() {
	$base = uw_muse_base_url();
	return rest_ensure_response(
		array(
			'auth_header'  => UW_MUSE_HEADER,
			'base'         => $base,
			'target_types' => array( 'page' ),
			'endpoints'    => array(
				'schema' => $base . '/muse/schema',
				'pages'  => $base . '/muse/pages',
			),
			'notes'        => array(
				'Always send ' . UW_MUSE_HEADER . ' and Content-Type: application/json.',
				'POST /muse/pages saves drafts only. Nothing publishes.',
				'Use external_id. Pass update:true to rewrite an existing draft.',
			),
		)
	);
}

function uw_muse_find_by_external( $external_id ) {
	$external_id = sanitize_text_field( (string) $external_id );
	if ( '' === $external_id ) {
		return 0;
	}
	$q = new WP_Query(
		array(
			'post_type'      => 'page',
			'post_status'    => array( 'draft', 'pending', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => UW_MUSE_META_EXT,
			'meta_value'     => $external_id,
			'no_found_rows'  => true,
		)
	);
	return ! empty( $q->posts[0] ) ? (int) $q->posts[0] : 0;
}

/**
 * @param array $item Page payload.
 * @return array|WP_Error
 */
function uw_muse_save_page( array $item ) {
	$external_id = sanitize_text_field( (string) ( $item['external_id'] ?? '' ) );
	if ( '' === $external_id ) {
		return new WP_Error( 'uw_muse_external_id', 'external_id is required.', array( 'status' => 400 ) );
	}

	$update   = ! empty( $item['update'] ) || ! empty( $item['upsert'] );
	$existing = uw_muse_find_by_external( $external_id );
	$title    = isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : '';
	$content  = isset( $item['content_html'] ) ? (string) $item['content_html'] : (string) ( $item['description_html'] ?? '' );
	$slug     = isset( $item['slug'] ) ? sanitize_title( (string) $item['slug'] ) : '';

	if ( $existing && ! $update ) {
		return new WP_Error( 'uw_muse_duplicate', 'Draft exists. Pass update:true to rewrite it.', array( 'status' => 409 ) );
	}
	if ( ! $existing && '' === $title ) {
		return new WP_Error( 'uw_muse_title', 'title is required when creating.', array( 'status' => 400 ) );
	}

	$postarr = array(
		'post_type'   => 'page',
		'post_status' => 'draft',
	);
	if ( '' !== $title ) {
		$postarr['post_title'] = $title;
	}
	if ( array_key_exists( 'content_html', $item ) || array_key_exists( 'description_html', $item ) ) {
		$postarr['post_content'] = wp_kses_post( $content );
	}
	if ( '' !== $slug ) {
		$postarr['post_name'] = $slug;
	}

	if ( $existing ) {
		$postarr['ID'] = $existing;
		$result          = wp_update_post( $postarr, true );
	} else {
		$result = wp_insert_post( $postarr, true );
	}
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$post_id = (int) $result;
	update_post_meta( $post_id, UW_MUSE_META_EXT, $external_id );

	return array(
		'id'          => $post_id,
		'external_id' => $external_id,
		'status'      => 'draft',
		'updated'     => (bool) $existing,
		'edit_url'    => get_edit_post_link( $post_id, 'raw' ),
	);
}

function uw_muse_rest_pages( WP_REST_Request $request ) {
	$body  = $request->get_json_params();
	$body  = is_array( $body ) ? $body : array();
	$items = isset( $body['items'] ) && is_array( $body['items'] ) ? $body['items'] : array( $body );
	$global_update = ! empty( $body['update'] ) || ! empty( $body['upsert'] );

	$created = 0;
	$updated = 0;
	$skipped = 0;
	$ids     = array();
	$warnings = array();

	foreach ( $items as $i => $item ) {
		if ( ! is_array( $item ) ) {
			$skipped++;
			$warnings[] = "Item {$i}: not an object.";
			continue;
		}
		if ( $global_update ) {
			$item['update'] = true;
		}
		$result = uw_muse_save_page( $item );
		if ( is_wp_error( $result ) ) {
			$skipped++;
			$warnings[] = "Item {$i}: " . $result->get_error_message();
			continue;
		}
		$ids[] = $result['id'];
		if ( $result['updated'] ) {
			$updated++;
		} else {
			$created++;
		}
	}

	return rest_ensure_response(
		array(
			'created'   => $created,
			'updated'   => $updated,
			'skipped'   => $skipped,
			'item_ids'  => $ids,
			'warnings'  => $warnings,
			'admin_url' => admin_url( 'edit.php?post_type=page&post_status=draft' ),
		)
	);
}

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'tubewriter', 'Muse', 'Muse', 'manage_options', 'uploadwriter-muse', 'uw_muse_settings_page' );
	},
	30
);

add_action(
	'admin_init',
	function () {
		if ( empty( $_POST['uw_muse_settings_nonce'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'uw_muse_settings', 'uw_muse_settings_nonce' );

		if ( ! empty( $_POST['uw_muse_clear_key'] ) ) {
			delete_option( UW_MUSE_OPTION_KEY );
		} else {
			$new = isset( $_POST['uw_muse_key'] ) ? trim( wp_unslash( (string) $_POST['uw_muse_key'] ) ) : '';
			if ( '' !== $new ) {
				update_option( UW_MUSE_OPTION_KEY, $new, false );
			}
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'uploadwriter-muse', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
);

function uw_muse_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$has_key  = uw_muse_has_key();
	$from_cfg = defined( 'UW_MUSE_KEY' ) && UW_MUSE_KEY;
	$base     = uw_muse_base_url();
	?>
	<div class="wrap">
		<h1>Muse</h1>
		<?php if ( ! empty( $_GET['updated'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>Saved.</p></div>
		<?php endif; ?>
		<p>Muse calls UploadWriter with a shared key. It can only write <strong>draft pages</strong>.</p>
		<form method="post">
			<?php wp_nonce_field( 'uw_muse_settings', 'uw_muse_settings_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="uw_muse_key">Muse API key</label></th>
					<td>
						<input type="password" class="regular-text code" name="uw_muse_key" id="uw_muse_key" value="" autocomplete="new-password" placeholder="<?php echo $has_key ? esc_attr( 'Saved. Leave blank to keep it.' ) : ''; ?>" <?php disabled( $from_cfg ); ?>>
						<p class="description">
							Key status: <strong><?php echo $has_key ? 'yes' : 'no'; ?></strong>.
							<?php if ( $from_cfg ) : ?>
								Set via <code>UW_MUSE_KEY</code> in wp-config.
							<?php else : ?>
								Muse sends this as <code><?php echo esc_html( UW_MUSE_HEADER ); ?></code>.
							<?php endif; ?>
						</p>
						<?php if ( $has_key && ! $from_cfg ) : ?>
							<label><input type="checkbox" name="uw_muse_clear_key" value="1"> Clear stored Muse key</label>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save' ); ?>
		</form>
		<h2>Endpoints</h2>
		<ul>
			<li><code><?php echo esc_html( $base ); ?>/muse/schema</code></li>
			<li><code><?php echo esc_html( $base ); ?>/muse/pages</code> — page drafts</li>
		</ul>
		<p class="description">Header: <code><?php echo esc_html( UW_MUSE_HEADER ); ?>: {key}</code>. Do not open these in a browser.</p>
	</div>
	<?php
}
