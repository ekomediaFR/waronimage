<?php
/**
 * Plugin Name: War on Image Connect
 * Description: Connects this WordPress site to War on Image Manager — exposes the media library with full image metadata (URL, type, ALT, title, caption, description, dimensions, size) and secure endpoints to upload SEO images, update metadata and set featured images.
 * Version: 1.0.0
 * Author: War on Image
 * Author URI: https://waronimage.vercel.app
 * License: GPL-2.0-or-later
 * Text Domain: war-on-image-connect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WOI_CONNECT_VERSION', '1.0.0' );
define( 'WOI_CONNECT_OPTION_KEY', 'waronimage_api_key' );

/* -------------------------------------------------------------------------
 * API key
 * ---------------------------------------------------------------------- */

function woi_connect_activate() {
	if ( ! get_option( WOI_CONNECT_OPTION_KEY ) ) {
		add_option( WOI_CONNECT_OPTION_KEY, wp_generate_password( 40, false, false ) );
	}
}
register_activation_hook( __FILE__, 'woi_connect_activate' );

function woi_connect_get_key() {
	return (string) get_option( WOI_CONNECT_OPTION_KEY, '' );
}

/**
 * Permission callback: X-Waronimage-Key header or Authorization: Bearer <key>.
 */
function woi_connect_permission( $request ) {
	$key = woi_connect_get_key();
	if ( '' === $key ) {
		return new WP_Error( 'woi_no_key', 'API key not configured — reactivate the plugin.', array( 'status' => 403 ) );
	}
	$provided = $request->get_header( 'x-waronimage-key' );
	if ( ! $provided ) {
		$auth = $request->get_header( 'authorization' );
		if ( $auth && 0 === stripos( $auth, 'bearer ' ) ) {
			$provided = trim( substr( $auth, 7 ) );
		}
	}
	if ( $provided && hash_equals( $key, $provided ) ) {
		return true;
	}
	return new WP_Error( 'woi_bad_key', 'Invalid or missing API key.', array( 'status' => 401 ) );
}

/* -------------------------------------------------------------------------
 * Serializers
 * ---------------------------------------------------------------------- */

/**
 * Full image info for one attachment: URL, type, ALT, description — everything.
 */
function woi_connect_image_data( $attachment ) {
	$id   = $attachment->ID;
	$file = get_attached_file( $id );
	$meta = wp_get_attachment_metadata( $id );

	$sizes = array();
	foreach ( array( 'thumbnail', 'medium', 'large', 'full' ) as $size ) {
		$src = wp_get_attachment_image_src( $id, $size );
		if ( $src ) {
			$sizes[ $size ] = array(
				'url'    => $src[0],
				'width'  => (int) $src[1],
				'height' => (int) $src[2],
			);
		}
	}

	return array(
		'id'          => $id,
		'url'         => wp_get_attachment_url( $id ),
		'filename'    => $file ? basename( $file ) : '',
		'mime_type'   => get_post_mime_type( $id ),
		'extension'   => $file ? strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) : '',
		'width'       => isset( $meta['width'] ) ? (int) $meta['width'] : null,
		'height'      => isset( $meta['height'] ) ? (int) $meta['height'] : null,
		'filesize'    => ( $file && file_exists( $file ) ) ? filesize( $file ) : null,
		'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		'title'       => $attachment->post_title,
		'caption'     => $attachment->post_excerpt,
		'description' => $attachment->post_content,
		'date'        => $attachment->post_date_gmt,
		'attached_to' => $attachment->post_parent ? (int) $attachment->post_parent : null,
		'sizes'       => $sizes,
	);
}

function woi_connect_content_data( $post ) {
	$thumb_id = get_post_thumbnail_id( $post->ID );
	return array(
		'id'             => $post->ID,
		'type'           => $post->post_type,
		'url'            => get_permalink( $post ),
		'slug'           => $post->post_name,
		'title'          => get_the_title( $post ),
		'status'         => $post->post_status,
		'modified'       => $post->post_modified_gmt,
		'featured_media' => $thumb_id ? (int) $thumb_id : null,
		'featured_url'   => $thumb_id ? wp_get_attachment_url( $thumb_id ) : null,
		'featured_alt'   => $thumb_id ? (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) : null,
	);
}

/* -------------------------------------------------------------------------
 * REST routes — namespace waronimage/v1
 * ---------------------------------------------------------------------- */

add_action( 'rest_api_init', function () {
	$ns   = 'waronimage/v1';
	$perm = 'woi_connect_permission';

	register_rest_route( $ns, '/info', array(
		'methods'             => 'GET',
		'callback'            => 'woi_connect_info',
		'permission_callback' => $perm,
	) );

	register_rest_route( $ns, '/images', array(
		array(
			'methods'             => 'GET',
			'callback'            => 'woi_connect_list_images',
			'permission_callback' => $perm,
		),
		array(
			'methods'             => 'POST',
			'callback'            => 'woi_connect_upload_image',
			'permission_callback' => $perm,
		),
	) );

	register_rest_route( $ns, '/images/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'callback'            => 'woi_connect_get_image',
			'permission_callback' => $perm,
		),
		array(
			'methods'             => 'POST',
			'callback'            => 'woi_connect_update_image',
			'permission_callback' => $perm,
		),
	) );

	register_rest_route( $ns, '/content', array(
		'methods'             => 'GET',
		'callback'            => 'woi_connect_list_content',
		'permission_callback' => $perm,
	) );

	register_rest_route( $ns, '/content/(?P<id>\d+)/featured', array(
		'methods'             => 'POST',
		'callback'            => 'woi_connect_set_featured',
		'permission_callback' => $perm,
	) );
} );

function woi_connect_info() {
	$counts = wp_count_attachments( 'image' );
	$images = 0;
	foreach ( (array) $counts as $n ) {
		$images += (int) $n;
	}
	$types = get_post_types( array( 'public' => true ), 'names' );
	unset( $types['attachment'] );

	return rest_ensure_response( array(
		'plugin'         => 'war-on-image-connect',
		'plugin_version' => WOI_CONNECT_VERSION,
		'wp_version'     => get_bloginfo( 'version' ),
		'site_name'      => get_bloginfo( 'name' ),
		'site_url'       => home_url(),
		'image_count'    => $images,
		'post_types'     => array_values( $types ),
	) );
}

function woi_connect_list_images( $request ) {
	$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 50 ) ) );
	$page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );

	$query = new WP_Query( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'post_mime_type' => 'image',
		'posts_per_page' => $per_page,
		'paged'          => $page,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );

	$images = array();
	foreach ( $query->posts as $attachment ) {
		$images[] = woi_connect_image_data( $attachment );
	}

	return rest_ensure_response( array(
		'total'       => (int) $query->found_posts,
		'total_pages' => (int) $query->max_num_pages,
		'page'        => $page,
		'per_page'    => $per_page,
		'images'      => $images,
	) );
}

function woi_connect_get_image( $request ) {
	$attachment = get_post( (int) $request['id'] );
	if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
		return new WP_Error( 'woi_not_found', 'Image not found.', array( 'status' => 404 ) );
	}
	return rest_ensure_response( woi_connect_image_data( $attachment ) );
}

function woi_connect_update_image( $request ) {
	$id         = (int) $request['id'];
	$attachment = get_post( $id );
	if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
		return new WP_Error( 'woi_not_found', 'Image not found.', array( 'status' => 404 ) );
	}
	$p      = $request->get_json_params();
	$update = array( 'ID' => $id );

	if ( isset( $p['title'] ) ) {
		$update['post_title'] = sanitize_text_field( $p['title'] );
	}
	if ( isset( $p['caption'] ) ) {
		$update['post_excerpt'] = sanitize_text_field( $p['caption'] );
	}
	if ( isset( $p['description'] ) ) {
		$update['post_content'] = sanitize_textarea_field( $p['description'] );
	}
	if ( count( $update ) > 1 ) {
		wp_update_post( $update );
	}
	if ( isset( $p['alt'] ) ) {
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $p['alt'] ) );
	}
	return rest_ensure_response( woi_connect_image_data( get_post( $id ) ) );
}

/**
 * Upload an image (base64 JSON body) with full SEO metadata.
 * Body: { filename, base64, alt?, title?, caption?, description?, attach_to?, set_featured? }
 */
function woi_connect_upload_image( $request ) {
	$p        = $request->get_json_params();
	$filename = sanitize_file_name( isset( $p['filename'] ) ? $p['filename'] : 'image.webp' );
	$b64      = isset( $p['base64'] ) ? $p['base64'] : '';

	if ( '' === $b64 ) {
		return new WP_Error( 'woi_no_data', 'base64 is required.', array( 'status' => 400 ) );
	}
	$binary = base64_decode( $b64, true );
	if ( false === $binary ) {
		return new WP_Error( 'woi_bad_data', 'base64 payload could not be decoded.', array( 'status' => 400 ) );
	}

	$upload = wp_upload_bits( $filename, null, $binary );
	if ( ! empty( $upload['error'] ) ) {
		return new WP_Error( 'woi_upload_failed', $upload['error'], array( 'status' => 500 ) );
	}

	$filetype  = wp_check_filetype( $upload['file'] );
	$attach_to = isset( $p['attach_to'] ) ? (int) $p['attach_to'] : 0;

	$attachment_id = wp_insert_attachment( array(
		'post_mime_type' => $filetype['type'],
		'post_title'     => sanitize_text_field( isset( $p['title'] ) && '' !== $p['title'] ? $p['title'] : preg_replace( '/\.[^.]+$/', '', $filename ) ),
		'post_excerpt'   => sanitize_text_field( isset( $p['caption'] ) ? $p['caption'] : '' ),
		'post_content'   => sanitize_textarea_field( isset( $p['description'] ) ? $p['description'] : '' ),
		'post_status'    => 'inherit',
	), $upload['file'], $attach_to );

	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

	if ( ! empty( $p['alt'] ) ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $p['alt'] ) );
	}
	if ( $attach_to && ! empty( $p['set_featured'] ) ) {
		set_post_thumbnail( $attach_to, $attachment_id );
	}

	return rest_ensure_response( woi_connect_image_data( get_post( $attachment_id ) ) );
}

function woi_connect_list_content( $request ) {
	$per_page = min( 500, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 200 ) ) );
	$page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );

	$types = get_post_types( array( 'public' => true ), 'names' );
	unset( $types['attachment'] );

	$query = new WP_Query( array(
		'post_type'      => array_values( $types ),
		'post_status'    => 'publish',
		'posts_per_page' => $per_page,
		'paged'          => $page,
		'orderby'        => 'ID',
		'order'          => 'ASC',
	) );

	$items = array();
	foreach ( $query->posts as $post ) {
		$items[] = woi_connect_content_data( $post );
	}

	return rest_ensure_response( array(
		'total'       => (int) $query->found_posts,
		'total_pages' => (int) $query->max_num_pages,
		'page'        => $page,
		'per_page'    => $per_page,
		'items'       => $items,
	) );
}

function woi_connect_set_featured( $request ) {
	$post_id  = (int) $request['id'];
	$p        = $request->get_json_params();
	$media_id = isset( $p['media_id'] ) ? (int) $p['media_id'] : 0;

	if ( ! get_post( $post_id ) ) {
		return new WP_Error( 'woi_not_found', 'Content not found.', array( 'status' => 404 ) );
	}
	if ( ! $media_id || ! wp_attachment_is_image( $media_id ) ) {
		return new WP_Error( 'woi_bad_media', 'media_id must be an existing image attachment.', array( 'status' => 400 ) );
	}
	set_post_thumbnail( $post_id, $media_id );
	return rest_ensure_response( woi_connect_content_data( get_post( $post_id ) ) );
}

/* -------------------------------------------------------------------------
 * Admin settings page — Settings → War on Image
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
	add_options_page( 'War on Image', 'War on Image', 'manage_options', 'war-on-image-connect', 'woi_connect_settings_page' );
} );

function woi_connect_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_POST['woi_regenerate'] ) && check_admin_referer( 'woi_regenerate_key' ) ) {
		update_option( WOI_CONNECT_OPTION_KEY, wp_generate_password( 40, false, false ) );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'New API key generated. Update it in War on Image Manager.', 'war-on-image-connect' ) . '</p></div>';
	}

	$key = woi_connect_get_key();
	if ( '' === $key ) {
		woi_connect_activate();
		$key = woi_connect_get_key();
	}
	$rest_base = rest_url( 'waronimage/v1' );
	?>
	<div class="wrap">
		<h1>War on Image Connect</h1>
		<p><?php esc_html_e( 'This site is ready to be linked to War on Image Manager. Copy the API key below into the site settings of the app.', 'war-on-image-connect' ); ?></p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'API key', 'war-on-image-connect' ); ?></th>
				<td>
					<code style="font-size:14px;padding:6px 10px;display:inline-block;background:#f0f0f1;"><?php echo esc_html( $key ); ?></code>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'REST base', 'war-on-image-connect' ); ?></th>
				<td><code><?php echo esc_html( $rest_base ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Auth header', 'war-on-image-connect' ); ?></th>
				<td><code>X-Waronimage-Key: &lt;api key&gt;</code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Endpoints', 'war-on-image-connect' ); ?></th>
				<td>
					<code>GET /info</code> · <code>GET /images</code> · <code>GET|POST /images/{id}</code> · <code>POST /images</code> (base64 upload) · <code>GET /content</code> · <code>POST /content/{id}/featured</code>
				</td>
			</tr>
		</table>

		<form method="post">
			<?php wp_nonce_field( 'woi_regenerate_key' ); ?>
			<p class="submit">
				<button type="submit" name="woi_regenerate" class="button button-secondary"
					onclick="return confirm('<?php echo esc_js( __( 'Regenerate the key? The app will need the new key.', 'war-on-image-connect' ) ); ?>');">
					<?php esc_html_e( 'Regenerate API key', 'war-on-image-connect' ); ?>
				</button>
			</p>
		</form>

		<p>
			<a href="https://waronimage.vercel.app" target="_blank" rel="noreferrer">War on Image Manager ↗</a>
		</p>
	</div>
	<?php
}
