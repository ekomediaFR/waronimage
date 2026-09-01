<?php
/**
 * /media — la médiathèque, exposée pour War on Image Manager.
 *
 * Quatre routes, même clé JUPITER que le reste du pont :
 *   GET  /media                 la liste paginée, avec TOUTES les infos d'image
 *                               (URL, type, dimensions, poids, ALT, titre,
 *                               légende, description, tailles intermédiaires)
 *   GET  /media/{id}            une image
 *   POST /media/{id}            écriture des métadonnées SEO (alt, titre,
 *                               légende, description) — rien d'autre
 *   POST /media                 upload en base64 avec métadonnées SEO, et
 *                               image à la une en option
 *   POST /media/{id}/featured   pose l'image {id} à la une d'un contenu
 *
 * Comme /tree : tableau nu + totaux dans les en-têtes X-WP-Total(Pages).
 * Chaque écriture passe par le journal d'audit — la médiathèque d'un site
 * client n'est pas un bac à sable.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Media_Controller extends EkoSEO_Controller_Base {

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/media',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'permissions' ],
					'args'                => [
						'page'     => [ 'default' => 1, 'type' => 'integer' ],
						'per_page' => [ 'default' => 50, 'type' => 'integer' ],
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'permissions' ],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/media/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'permissions' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'permissions' ],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/media/(?P<id>\d+)/featured',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'set_featured' ],
				'permission_callback' => [ $this, 'permissions' ],
			]
		);
	}

	/** Toutes les infos d'une image — le contrat avec War on Image Manager. */
	private function image( $p ) {
		$id   = (int) $p->ID;
		$file = get_attached_file( $id );
		$meta = wp_get_attachment_metadata( $id );

		$sizes = [];
		foreach ( [ 'thumbnail', 'medium', 'large', 'full' ] as $taille ) {
			$src = wp_get_attachment_image_src( $id, $taille );
			if ( $src ) {
				$sizes[ $taille ] = [
					'url'    => $src[0],
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
				];
			}
		}

		return [
			'id'          => $id,
			'url'         => wp_get_attachment_url( $id ),
			'filename'    => $file ? basename( $file ) : '',
			'mime_type'   => get_post_mime_type( $id ),
			'extension'   => $file ? strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) : '',
			'width'       => isset( $meta['width'] ) ? (int) $meta['width'] : null,
			'height'      => isset( $meta['height'] ) ? (int) $meta['height'] : null,
			'filesize'    => ( $file && file_exists( $file ) ) ? filesize( $file ) : null,
			'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'title'       => $p->post_title,
			'caption'     => $p->post_excerpt,
			'description' => $p->post_content,
			'date'        => $p->post_date_gmt,
			'attached_to' => $p->post_parent ? (int) $p->post_parent : null,
			'sizes'       => $sizes,
		];
	}

	public function get_items( $req ) {
		$per  = max( 1, min( 100, (int) $req->get_param( 'per_page' ) ) );
		$page = max( 1, (int) $req->get_param( 'page' ) );

		$q = new WP_Query(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => $per,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => false,
			]
		);

		$items = [];
		foreach ( $q->posts as $p ) {
			$items[] = $this->image( $p );
		}

		$rep = rest_ensure_response( $items );
		$rep->header( 'X-WP-Total', (int) $q->found_posts );
		$rep->header( 'X-WP-TotalPages', (int) $q->max_num_pages );
		return $rep;
	}

	public function get_item( $req ) {
		$p = get_post( (int) $req['id'] );
		if ( ! $p || 'attachment' !== $p->post_type ) {
			return $this->erreur( 'ekoseo_media_introuvable', 'Image inexistante.', 404 );
		}
		return rest_ensure_response( $this->image( $p ) );
	}

	/** Écriture FERMÉE aux quatre champs SEO — le fichier lui-même n'est pas touché. */
	public function update_item( $req ) {
		$id = (int) $req['id'];
		$p  = get_post( $id );
		if ( ! $p || 'attachment' !== $p->post_type ) {
			return $this->erreur( 'ekoseo_media_introuvable', 'Image inexistante.', 404 );
		}
		$corps  = (array) $req->get_json_params();
		$update = [ 'ID' => $id ];

		if ( array_key_exists( 'title', $corps ) ) {
			$update['post_title'] = sanitize_text_field( $corps['title'] );
		}
		if ( array_key_exists( 'caption', $corps ) ) {
			$update['post_excerpt'] = sanitize_text_field( $corps['caption'] );
		}
		if ( array_key_exists( 'description', $corps ) ) {
			$update['post_content'] = sanitize_textarea_field( $corps['description'] );
		}
		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}
		if ( array_key_exists( 'alt', $corps ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $corps['alt'] ) );
		}

		EkoSEO_Audit::journaliser(
			[
				'post_id' => $id,
				'action'  => 'media:meta',
				'result'  => 'ok',
				'message' => sprintf( 'Métadonnées SEO de « %s » mises à jour.', basename( (string) get_attached_file( $id ) ) ),
			]
		);

		return rest_ensure_response( $this->image( get_post( $id ) ) );
	}

	/**
	 * Upload : { filename, base64, alt?, title?, caption?, description?,
	 *            attach_to?, set_featured? }. Le binaire arrive en base64 —
	 * pas de multipart, pour rester derrière le même correctif Authorization.
	 */
	public function create_item( $req ) {
		$corps    = (array) $req->get_json_params();
		$filename = sanitize_file_name( isset( $corps['filename'] ) ? $corps['filename'] : 'image.webp' );
		$b64      = isset( $corps['base64'] ) ? $corps['base64'] : '';

		if ( '' === $b64 ) {
			return $this->erreur( 'ekoseo_media_vide', 'Le champ base64 est requis.', 400 );
		}
		$binaire = base64_decode( $b64, true );
		if ( false === $binaire ) {
			return $this->erreur( 'ekoseo_media_illisible', 'Le base64 ne se décode pas.', 400 );
		}

		$upload = wp_upload_bits( $filename, null, $binaire );
		if ( ! empty( $upload['error'] ) ) {
			return $this->erreur( 'ekoseo_media_upload', $upload['error'], 500 );
		}

		$type      = wp_check_filetype( $upload['file'] );
		$attach_to = isset( $corps['attach_to'] ) ? (int) $corps['attach_to'] : 0;

		$media_id = wp_insert_attachment(
			[
				'post_mime_type' => $type['type'],
				'post_title'     => sanitize_text_field(
					isset( $corps['title'] ) && '' !== $corps['title']
						? $corps['title']
						: preg_replace( '/\.[^.]+$/', '', $filename )
				),
				'post_excerpt'   => sanitize_text_field( isset( $corps['caption'] ) ? $corps['caption'] : '' ),
				'post_content'   => sanitize_textarea_field( isset( $corps['description'] ) ? $corps['description'] : '' ),
				'post_status'    => 'inherit',
			],
			$upload['file'],
			$attach_to
		);
		if ( is_wp_error( $media_id ) ) {
			return $media_id;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $media_id, wp_generate_attachment_metadata( $media_id, $upload['file'] ) );

		if ( ! empty( $corps['alt'] ) ) {
			update_post_meta( $media_id, '_wp_attachment_image_alt', sanitize_text_field( $corps['alt'] ) );
		}
		if ( $attach_to && ! empty( $corps['set_featured'] ) ) {
			set_post_thumbnail( $attach_to, $media_id );
		}

		EkoSEO_Audit::journaliser(
			[
				'post_id' => (int) $media_id,
				'action'  => 'media:upload',
				'result'  => 'ok',
				'message' => sprintf(
					'« %s » ajouté à la médiathèque%s.',
					$filename,
					$attach_to && ! empty( $corps['set_featured'] ) ? sprintf( ', à la une du contenu %d', $attach_to ) : ''
				),
			]
		);

		return rest_ensure_response( $this->image( get_post( $media_id ) ) );
	}

	/** POST /media/{id}/featured — corps : { post_id }. */
	public function set_featured( $req ) {
		$media_id = (int) $req['id'];
		$corps    = (array) $req->get_json_params();
		$post_id  = isset( $corps['post_id'] ) ? (int) $corps['post_id'] : 0;

		if ( ! wp_attachment_is_image( $media_id ) ) {
			return $this->erreur( 'ekoseo_media_introuvable', 'Cette image n\'existe pas.', 404 );
		}
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return $this->erreur( 'ekoseo_contenu_introuvable', 'Le champ post_id doit désigner un contenu existant.', 400 );
		}

		set_post_thumbnail( $post_id, $media_id );

		EkoSEO_Audit::journaliser(
			[
				'post_id' => $post_id,
				'action'  => 'media:featured',
				'result'  => 'ok',
				'message' => sprintf( 'Image %d posée à la une.', $media_id ),
			]
		);

		return rest_ensure_response(
			[
				'post_id'  => $post_id,
				'media_id' => $media_id,
				'featured' => (int) get_post_thumbnail_id( $post_id ) === $media_id,
			]
		);
	}
}
