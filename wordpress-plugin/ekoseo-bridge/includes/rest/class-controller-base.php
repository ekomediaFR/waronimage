<?php
/**
 * Base commune des contrôleurs.
 *
 * `permissions()` est la seule porte d'entrée. Aucune route de ce plugin ne doit
 * utiliser `__return_true` : une route publique en écriture sur un site client
 * est une faille, pas un raccourci.
 */

defined( 'ABSPATH' ) || exit;

abstract class EkoSEO_Controller_Base extends WP_REST_Controller {

	protected $namespace = EKOSEO_BRIDGE_NS;

	public function permissions( WP_REST_Request $req ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'ekoseo_unauthenticated',
				"Authentification requise. Utiliser un mot de passe d'application sur le compte " . EKOSEO_BRIDGE_BOT . ".",
				[ 'status' => 401 ]
			);
		}
		if ( ! current_user_can( EKOSEO_BRIDGE_CAP ) ) {
			return new WP_Error(
				'ekoseo_forbidden',
				'Capacité ' . EKOSEO_BRIDGE_CAP . ' requise.',
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	protected function erreur( $code, $message, $status, $data = [] ) {
		return new WP_Error( $code, $message, array_merge( [ 'status' => $status ], $data ) );
	}

	/** État normalisé d'une page — sert au hash, au diff et au snapshot. */
	protected function etat( $post_id ) {
		$p = get_post( $post_id );
		if ( ! $p ) {
			return null;
		}
		list( $html, $source ) = EkoSEO_Elementor_IO::lire_corps( $post_id );

		$parent = null;
		if ( $p->post_parent ) {
			$pp = get_post( $p->post_parent );
			if ( $pp ) {
				$parent = [ 'id' => (int) $pp->ID, 'slug' => $pp->post_name ];
			}
		}

		return [
			'wp_post_id'     => (int) $p->ID,
			'cpt'            => $p->post_type,
			'slug'           => $p->post_name,
			'permalink'      => get_permalink( $p ),
			'status'         => $p->post_status,
			'title'          => $p->post_title,
			'parent'         => $parent,
			'path'           => $this->chemin( $p ),
			'seo'            => EkoSEO_Yoast_IO::lire( $post_id ),
			'meta'           => $this->metas_suivies( $post_id ),
			'content_source' => $source,
			'html'           => $html,
			'modified'       => get_post_modified_time( 'c', true, $p ),
		];
	}

	/** Metas éditoriales suivies. Volontairement restreint : tout suivre rendrait
	 *  le hash instable au moindre champ technique touché par une extension. */
	/**
	 * Les métadonnées propres au site que le pont a le droit de lire et d'écrire.
	 *
	 * La liste est volontairement FERMÉE. Ouvrir l'écriture à toutes les métas
	 * d'un post reviendrait à laisser le pont modifier n'importe quoi — y compris
	 * les champs internes d'extensions qui ne s'attendent pas à être touchés de
	 * l'extérieur. Une méta non déclarée est ignorée en silence à l'écriture, et
	 * c'est voulu.
	 *
	 * `hero_subtitle` et `html` sont les champs JetEngine des sites du groupe :
	 * le sous-titre du héros et, sur les gabarits qui l'utilisent, le corps de la
	 * page. Le second a été ajouté le 2026-08-23 — sans lui, une page dont le
	 * contenu vit dans ce champ n'était modifiable par aucune route.
	 *
	 * Un site qui en aurait d'autres les ajoute par le filtre, sans toucher au code.
	 */
	protected function metas_suivies( $post_id ) {
		$cles = self::metas_autorisees();
		$out  = [];
		foreach ( $cles as $c ) {
			$out[ $c ] = (string) get_post_meta( $post_id, $c, true );
		}
		return $out;
	}

	public static function metas_autorisees() {
		return apply_filters( 'ekoseo_metas_suivies', [ 'hero_subtitle', 'html' ] );
	}


	protected function chemin( $p ) {
		$path = [ $p->post_name ];
		$cur  = $p;
		$garde = 0;
		while ( $cur->post_parent && $garde++ < 12 ) {
			$cur = get_post( $cur->post_parent );
			if ( ! $cur ) {
				break;
			}
			array_unshift( $path, $cur->post_name );
		}
		return $path;
	}

	/** Le hash ne porte que sur les champs éditoriaux, pas sur l'URL ni les dates. */
	protected function hash( array $etat ) {
		return EkoSEO_Hash::calculer(
			[
				'title'  => $etat['title'],
				'slug'   => $etat['slug'],
				'status' => $etat['status'],
				'parent' => $etat['parent'] ? $etat['parent']['slug'] : '',
				'seo'    => $etat['seo'],
				'meta'   => $etat['meta'],
				'html'   => $etat['html'],
			]
		);
	}
}
