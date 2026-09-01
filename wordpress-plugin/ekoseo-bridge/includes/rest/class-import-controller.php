<?php
/**
 * POST /import — l'import WXR, mais chirurgical.
 *
 * L'importateur WordPress crée. Il remappe les identifiants et saute ce dont le
 * slug existe déjà : rejouer un export sur des pages en ligne ne les met pas à
 * jour, il les ignore ou les duplique. Le post_id change, l'URL change, et avec
 * eux l'historique Search Console et les liens entrants.
 *
 * Cette route fait le même travail — même arbre Elementor, mêmes champs Yoast,
 * même hero_subtitle — mais elle regarde d'abord si la page existe, au bon
 * niveau, dans le bon type de contenu :
 *
 *   trouvée  → MISE À JOUR en place. post_id, slug et URL inchangés.
 *   absente  → CRÉATION sous le bon parent.
 *
 * Chaque écriture est précédée d'un instantané, passe la garde géographique, et
 * peut être annulée. `dry_run` dit ce qui se passerait sans rien toucher.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Import_Controller extends EkoSEO_Controller_Base {

	/** Au-delà, la requête risque le délai d'exécution PHP. Le client découpe. */
	const MAX_ITEMS = 40;

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/import',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'importer' ],
				'permission_callback' => [ $this, 'permissions' ],
			]
		);
	}

	public function importer( $req ) {
		$b = $req->get_json_params();
		if ( ! is_array( $b ) ) {
			return $this->erreur( 'ekoseo_corps_invalide', 'Corps JSON attendu.', 400 );
		}
		$operation_id = isset( $b['operation_id'] ) ? (string) $b['operation_id'] : '';
		if ( '' === $operation_id ) {
			return $this->erreur( 'ekoseo_operation_id_manquant', 'operation_id (UUID v4) obligatoire.', 400 );
		}
		$deja = EkoSEO_Audit::deja_traitee( $operation_id );
		if ( $deja ) {
			return rest_ensure_response( array_merge(
				is_array( $deja['response'] ) ? $deja['response'] : [],
				[ 'replayed' => true, 'operation_id' => $operation_id ]
			) );
		}

		$cpt = isset( $b['cpt'] ) ? sanitize_key( $b['cpt'] ) : '';
		if ( ! $cpt || ! post_type_exists( $cpt ) ) {
			return $this->erreur( 'ekoseo_cpt_inconnu',
				sprintf( 'Type de contenu « %s » inexistant sur ce site.', $cpt ), 404 );
		}
		$items = isset( $b['items'] ) && is_array( $b['items'] ) ? $b['items'] : [];
		if ( ! $items ) {
			return $this->erreur( 'ekoseo_items_vides', 'Aucun élément à importer.', 400 );
		}
		if ( count( $items ) > self::MAX_ITEMS ) {
			return $this->erreur( 'ekoseo_trop_items',
				sprintf( '%d éléments : maximum %d par appel, sinon PHP dépasse son temps d\'exécution.',
					count( $items ), self::MAX_ITEMS ), 413 );
		}

		$dry     = ! empty( $b['dry_run'] );
		$hero    = isset( $b['hero_template_id'] ) ? (string) $b['hero_template_id'] : '';
		$version = isset( $b['elementor_version'] ) ? (string) $b['elementor_version'] : '';
		$statut_neuf = isset( $b['status_nouveau'] ) ? (string) $b['status_nouveau'] : 'draft';
		if ( ! in_array( $statut_neuf, [ 'publish', 'draft', 'pending', 'private' ], true ) ) {
			$statut_neuf = 'draft';
		}
		$force_geo = ! empty( $b['force_geo'] );

		$resultats = [];
		foreach ( $items as $it ) {
			$resultats[] = $this->un_item( $it, $cpt, $dry, $hero, $version, $statut_neuf,
			                               $force_geo, $operation_id );
		}

		$compte = [ 'cree' => 0, 'maj' => 0, 'inchange' => 0, 'refuse' => 0 ];
		foreach ( $resultats as $r ) {
			$k = isset( $compte[ $r['action'] ] ) ? $r['action'] : 'refuse';
			$compte[ $k ]++;
		}

		$reponse = [
			'operation_id' => $operation_id,
			'dry_run'      => $dry,
			'cpt'          => $cpt,
			'resume'       => $compte,
			'items'        => $resultats,
		];
		EkoSEO_Audit::journaliser( [
			'operation_id'   => $operation_id,
			'action'         => 'import',
			'result'         => $dry ? 'skipped' : 'ok',
			'changed_fields' => array_keys( $compte ),
			'message'        => sprintf( '%s : %d créées, %d mises à jour, %d refusées',
				$cpt, $compte['cree'], $compte['maj'], $compte['refuse'] ),
			'response'       => $reponse,
		] );
		return rest_ensure_response( $reponse );
	}

	// ------------------------------------------------------------------ un item

	private function un_item( $it, $cpt, $dry, $hero, $version, $statut_neuf, $force_geo, $operation_id ) {
		$slug = isset( $it['slug'] ) ? sanitize_title( (string) $it['slug'] ) : '';
		$base = [ 'slug' => $slug, 'action' => 'refuse', 'post_id' => null ];
		if ( ! $slug ) {
			$base['message'] = 'Slug manquant.';
			return $base;
		}
		$html = isset( $it['html'] ) ? (string) $it['html'] : '';
		if ( '' === trim( $html ) ) {
			$base['message'] = 'Contenu vide : refusé, une page vide efface la précédente.';
			return $base;
		}

		// ---- le parent, par identifiant ou par chemin de slugs
		list( $parent, $chemin, $manquant ) = $this->resoudre_parent( $it, $cpt );
		$base['parent'] = $parent;
		if ( '' !== $manquant && empty( $it['parent_facultatif'] ) ) {
			// 31/08/2026 : une page a atterri à la RACINE du site parce que son
			// chemin ne se résolvait pas. Un chemin demandé mais introuvable
			// est désormais un REFUS — jamais un atterrissage approximatif.
			$base['message'] = sprintf(
				'Chemin parent introuvable à partir de « %s » (type %s, chemin %s) : page non créée. '
				. 'Vérifier la branche, ou envoyer parent_facultatif pour accepter le niveau connu.',
				$manquant, $cpt, implode( '/', $chemin )
			);
			return $base;
		}

		// ---- la page existe-t-elle déjà, à ce niveau, dans ce type ?
		$existant = get_posts( [
			'post_type'        => $cpt,
			'name'             => $slug,
			'post_parent'      => $parent,
			'post_status'      => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			'numberposts'      => 1,
			'suppress_filters' => false,
		] );
		$post = $existant ? $existant[0] : null;
		$base['action'] = $post ? 'maj' : 'cree';
		$base['post_id'] = $post ? (int) $post->ID : null;

		// ---- la garde géographique, avant tout
		$indice = implode( '/', array_merge( $chemin, [ $slug ] ) );
		$geo = EkoSEO_Geo_Guard::verifier( $html, $indice );
		if ( $geo['contamine'] && ! $force_geo ) {
			$base['action']  = 'refuse';
			$base['message'] = EkoSEO_Geo_Guard::message( $geo );
			$base['geo']     = $geo;
			return $base;
		}

		// ---- la structure, avec la page actuelle en référence pour les scripts
		$reference = $post ? ( EkoSEO_Elementor_IO::lire_corps( $post->ID )[0] ) : null;
		$err = EkoSEO_Html_Parser::valider( $html, 2097152, $reference );
		if ( $err ) {
			$base['action']  = 'refuse';
			$base['message'] = implode( ' ', $err );
			return $base;
		}

		$base['mentions_cible'] = $geo['mentions_cible'];
		$base['cible']          = $geo['cible'];

		if ( $dry ) {
			$base['message'] = $post
				? sprintf( 'Mise à jour de #%d — %s', $post->ID, get_permalink( $post ) )
				: sprintf( 'Création sous le parent #%d, statut %s', $parent, $statut_neuf );
			return $base;
		}

		// ---- écriture
		if ( $post ) {
			$etat = $this->etat( $post->ID );
			$snap = EkoSEO_Audit::snapshot( $post->ID, $operation_id, $etat, $this->hash( $etat ) );
			if ( ! $snap ) {
				$base['action']  = 'refuse';
				$base['message'] = "Instantané impossible : écriture annulée.";
				return $base;
			}
			$base['snapshot_id'] = $snap;
			$id = (int) $post->ID;
			if ( isset( $it['title'] ) && '' !== trim( (string) $it['title'] )
				&& (string) $it['title'] !== $post->post_title ) {
				wp_update_post( [ 'ID' => $id, 'post_title' => wp_strip_all_tags( (string) $it['title'] ) ] );
			}
		} else {
			$id = wp_insert_post( [
				'post_type'    => $cpt,
				'post_name'    => $slug,
				'post_title'   => isset( $it['title'] ) ? wp_strip_all_tags( (string) $it['title'] ) : $slug,
				'post_status'  => isset( $it['status'] ) ? (string) $it['status'] : $statut_neuf,
				'post_parent'  => $parent,
				'post_content' => '',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			], true );
			if ( is_wp_error( $id ) ) {
				$base['action']  = 'refuse';
				$base['message'] = $id->get_error_message();
				return $base;
			}
			$base['post_id'] = (int) $id;
		}

		list( $ok, $ou ) = EkoSEO_Elementor_Build::appliquer( $id, $html, $hero, $version );
		if ( ! $ok ) {
			$base['action']  = 'refuse';
			$base['message'] = $ou;
			return $base;
		}
		$base['contenu'] = $ou;

		// Le héros manquant d'une page EXISTANTE : appliquer() ne touche que le
		// corps — on l'insère ici, idempotent (déjà présent = rien).
		if ( '' !== (string) $hero ) {
			list( $h_chg, $h_msg ) = EkoSEO_Elementor_Build::inserer_hero( $id, $hero );
			if ( $h_chg ) {
				$changes_hero = 'hero:' . $h_msg;
			}
		}

		$changes = [ 'html' ];
		if ( isset( $changes_hero ) ) {
			$changes[] = $changes_hero;
		}
		if ( isset( $it['seo'] ) && is_array( $it['seo'] ) ) {
			$changes = array_merge( $changes, EkoSEO_Yoast_IO::ecrire( $id, $it['seo'] ) );
		}
		if ( isset( $it['meta'] ) && is_array( $it['meta'] ) ) {
			$autorisees = self::metas_autorisees();
			foreach ( $it['meta'] as $k => $v ) {
				if ( in_array( $k, $autorisees, true ) ) {
					update_post_meta( $id, $k, (string) $v );
					$changes[] = 'meta.' . $k;
				}
			}
		}

		// ---- l'image du hero par défaut : à la CRÉATION seulement, jamais en
		// écrasement. L'URL doit désigner un média DÉJÀ dans la médiathèque —
		// le pont ne téléverse rien (31/08/2026, hero dynamique des CPT).
		if ( ! empty( $it['image_defaut'] ) ) {
			$att = attachment_url_to_postid( esc_url_raw( (string) $it['image_defaut'] ) );
			if ( $att ) {
				if ( ! has_post_thumbnail( $id ) ) {
					set_post_thumbnail( $id, $att );
					$changes[] = 'image.featured';
				}
				if ( '' === (string) get_post_meta( $id, 'gallery', true ) ) {
					update_post_meta( $id, 'gallery', (string) $att );
					$changes[] = 'meta.gallery';
				}
			} else {
				$base['image_defaut'] = 'introuvable dans la médiathèque : ' . (string) $it['image_defaut'];
			}
		}

		$base['changed_fields'] = array_values( array_unique( $changes ) );
		$base['caches_purged']  = EkoSEO_Cache::purger( $id );
		$base['permalink']      = get_permalink( $id );
		$base['message']        = $post ? 'Mis à jour en place.' : 'Créé.';
		return $base;
	}

	/**
	 * Le parent : un identifiant explicite, ou un chemin de slugs remonté depuis
	 * la racine. Renvoie [parent, chemin, manquant] : « manquant » est le premier
	 * segment qui ne se résout pas ('' si tout est résolu) — l'appelant REFUSE
	 * alors la création, au lieu de poser la page au dernier niveau connu.
	 */
	private function resoudre_parent( $it, $cpt ) {
		if ( isset( $it['parent_id'] ) && (int) $it['parent_id'] > 0 ) {
			return [ (int) $it['parent_id'], [], '' ];
		}
		$chemin = isset( $it['parent_path'] ) && is_array( $it['parent_path'] )
			? array_values( array_filter( array_map( 'sanitize_title', $it['parent_path'] ) ) )
			: [];
		$parent = 0;
		foreach ( $chemin as $seg ) {
			$p = get_posts( [
				'post_type'   => $cpt,
				'name'        => $seg,
				'post_parent' => $parent,
				'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'numberposts' => 1,
			] );
			if ( ! $p ) {
				return [ $parent, $chemin, $seg ];
			}
			$parent = (int) $p[0]->ID;
		}
		return [ $parent, $chemin, '' ];
	}
}
