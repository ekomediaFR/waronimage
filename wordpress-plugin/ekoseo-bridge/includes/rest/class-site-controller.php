<?php
/**
 * GET /site — diagnostic de connexion. Aucune écriture.
 * C'est le premier appel que fait JUPITER, et celui qui dit pourquoi ça ne
 * marche pas quand ça ne marche pas.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Site_Controller extends EkoSEO_Controller_Base {

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/site',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => [ $this, 'permissions' ],
			]
		);
	}

	public function get_item( $req ) {
		global $wp_version;

		list( $source, $repartition ) = EkoSEO_Elementor_IO::source_site();

		$cpts = [];
		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $pt ) {
			if ( in_array( $pt->name, [ 'attachment' ], true ) ) {
				continue;
			}
			$n = wp_count_posts( $pt->name );
			$cpts[] = [
				'slug'         => $pt->name,
				'label'        => $pt->label,
				'hierarchical' => (bool) $pt->hierarchical,
				'count'        => isset( $n->publish ) ? (int) $n->publish : 0,
				'meta_fields'  => $this->metas_du_cpt( $pt->name ),
			];
		}

		return rest_ensure_response(
			[
				'bridge_version' => EKOSEO_BRIDGE_VERSION,
				'wordpress'      => $wp_version,
				'php'            => PHP_VERSION,
				'site_url'       => home_url(),
				'elementor'      => [
					'active'       => EkoSEO_Elementor_IO::actif(),
					'version'      => EkoSEO_Elementor_IO::version(),
					'pro'          => EkoSEO_Elementor_IO::pro(),
					'major_pinned' => EkoSEO_Installer::pinned_elementor_major(),
				],
				'yoast'          => [
					'active'  => EkoSEO_Yoast_IO::actif(),
					'version' => EkoSEO_Yoast_IO::version(),
					'premium' => EkoSEO_Yoast_IO::premium(),
				],
				'jetengine'      => [
					'active'  => defined( 'JET_ENGINE_VERSION' ),
					'version' => defined( 'JET_ENGINE_VERSION' ) ? JET_ENGINE_VERSION : null,
				],
				'cache_plugins'  => EkoSEO_Cache::detecter(),
				'htaccess'       => EkoSEO_Htaccess::etat(),
				'post_types'     => $cpts,
				'content_source' => $source,
				'content_source_sample' => $repartition,
				'capabilities'   => [
					'read_tree'    => true,
					'read_page'    => true,
					'write_seo'    => true,
					'write_html'   => true,   // corps de page : depuis la 0.6.0
					'create_page'  => true,   // par /import, avec parent et instantané
					'import'       => true,   // import chirurgical : met à jour, ne duplique pas
					'import_wxr'   => true,   // WXR en MISE À JOUR, jamais en création aveugle
					'geo_guard'    => true,   // refuse un contenu qui parle d'une autre localité
					'rollback'     => true,
					'scan'         => true,   // lecture en masse : contenu, maillage, SEO
					'menus'        => true,   // menus : la source des liens injectés à l'échelle du site
					'redirects'    => true,
					'write_yoast_complet' => true,  // canonique, robots, pilier, OG, Twitter, schéma
					'write_meta'   => true,   // champs JetEngine déclarés : hero_subtitle, html
					'delete_page'  => true,   // corbeille par défaut, définitif seulement avec redirection
					'write_redirects' => true,   // 301 autonomes, sans dépendre d'une extension tierce
					'write_menus'  => true,   // là où vivent 71 % des liens internes
					'read_media'   => true,   // médiathèque complète : URL, type, ALT, description… (1.5.0)
					'write_media_meta' => true,   // alt, titre, légende, description — champs SEO seulement
					'upload_media' => true,   // upload base64 avec métadonnées, pour War on Image Manager
					'set_featured' => true,   // image à la une
				],
			]
		);
	}

	/** Échantillonne les clés de méta d'un CPT, en écartant les clés techniques. */
	private function metas_du_cpt( $cpt ) {
		global $wpdb;
		$ids = get_posts(
			[ 'post_type' => $cpt, 'post_status' => 'publish', 'numberposts' => 5, 'fields' => 'ids' ]
		);
		if ( ! $ids ) {
			return [];
		}
		$in   = implode( ',', array_map( 'intval', $ids ) );
		$rows = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" ); // phpcs:ignore
		$out  = [];
		foreach ( (array) $rows as $k ) {
			if ( 0 === strpos( $k, '_' ) ) {
				continue;   // metas protégées : hors périmètre éditorial
			}
			$out[] = $k;
		}
		sort( $out );
		return $out;
	}
}
