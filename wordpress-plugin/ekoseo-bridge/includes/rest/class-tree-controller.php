<?php
/**
 * GET /tree — l'arborescence d'un CPT.
 *
 * Le hash renvoyé ici doit être IDENTIQUE à celui de /page/{id}, sinon JUPITER
 * verrait des divergences fantômes à chaque synchronisation.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Tree_Controller extends EkoSEO_Controller_Base {

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/tree',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'permissions' ],
				'args'                => [
					'cpt'      => [ 'required' => true, 'type' => 'string' ],
					'page'     => [ 'default' => 1, 'type' => 'integer' ],
					'per_page' => [ 'default' => 200, 'type' => 'integer' ],
					'light'    => [ 'default' => false, 'type' => 'boolean' ],
				],
			]
		);
	}

	public function get_items( $req ) {
		$cpt = sanitize_key( $req->get_param( 'cpt' ) );
		if ( ! post_type_exists( $cpt ) ) {
			return $this->erreur( 'ekoseo_cpt_inconnu', sprintf( 'Type de contenu « %s » inexistant.', $cpt ), 404 );
		}
		$per  = max( 1, min( 500, (int) $req->get_param( 'per_page' ) ) );
		$page = max( 1, (int) $req->get_param( 'page' ) );
		$light = (bool) $req->get_param( 'light' );

		$q = new WP_Query(
			[
				'post_type'      => $cpt,
				'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'posts_per_page' => $per,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => false,
			]
		);

		$items = [];
		foreach ( $q->posts as $p ) {
			$ligne = [
				'id'          => (int) $p->ID,
				'slug'        => $p->post_name,
				'title'       => $p->post_title,
				'status'      => $p->post_status,
				'post_parent' => (int) $p->post_parent,
				'menu_order'  => (int) $p->menu_order,
				'permalink'   => get_permalink( $p ),
				'modified'    => get_post_modified_time( 'c', true, $p ),
			];
			if ( ! $light ) {
				$etat          = $this->etat( $p->ID );
				$ligne['hash'] = $etat ? $this->hash( $etat ) : null;
				$ligne['path'] = $etat ? $etat['path'] : [];
			}
			$items[] = $ligne;
		}

		$rep = rest_ensure_response( $items );
		$rep->header( 'X-WP-Total', (int) $q->found_posts );
		$rep->header( 'X-WP-TotalPages', (int) $q->max_num_pages );
		return $rep;
	}
}
