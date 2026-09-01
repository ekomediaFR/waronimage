<?php
/**
 * POST /import/wxr — le format d'export WordPress, mais en MISE À JOUR.
 *
 * Pourquoi cette route existe alors que `/import` fait déjà le travail : le
 * format WXR est celui que la chaîne de production connaît, produit et sait
 * relire. On garde donc le transport, et on change la seule chose qui posait
 * problème — la sémantique.
 *
 * `WP_Import` **crée**. Il remappe les identifiants et saute les éléments dont
 * le slug existe déjà : rejoué sur des pages en ligne, il ne les met pas à jour.
 * Ici, chaque `<item>` est retrouvé par (type de contenu, slug) et **réécrit en
 * place** : `post_id`, slug et URL inchangés, donc historique préservé.
 *
 * ⚠️ Un malentendu à lever une fois pour toutes : ce n'est PAS le format XML qui
 * préserve les `<script>` et les `<style>`. C'est le fait que l'importateur
 * WordPress tourne sous un compte administrateur, qui possède `unfiltered_html`.
 * Le compte `ekoseo_bot` ne l'a pas — et ne doit pas l'avoir. L'écriture passe
 * donc par `EkoSEO_Elementor_IO::sans_kses()`, qui lève le filtre le temps de
 * l'opération, après que le validateur a vérifié que chaque script entrant
 * existait déjà dans la page. Sans cela, le XML donnerait le même résultat que
 * l'écriture directe : balises retirées, contenu affiché en clair.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Wxr_Controller extends EkoSEO_Controller_Base {

	const MAX_ITEMS  = 40;
	const MAX_OCTETS = 12582912;      // 12 Mo par appel ; le client découpe

	/** Les métas qu'un WXR a le droit de poser. Tout le reste est ignoré. */
	public static function metas_admises() {
		return apply_filters(
			'ekoseo_wxr_metas',
			array_merge(
				array_values( EkoSEO_Yoast_IO::CHAMPS ),
				[ '_yoast_wpseo_keywordsynonyms', '_yoast_wpseo_focuskeywords' ],
				EkoSEO_Controller_Base::metas_autorisees(),
				[ '_elementor_data', '_elementor_edit_mode', '_elementor_template_type',
				  '_elementor_version', '_elementor_page_settings' ]
			)
		);
	}

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/import/wxr',
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
			return $this->erreur( 'ekoseo_corps_invalide', 'Corps JSON attendu, avec la clé « xml ».', 400 );
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

		$doc = isset( $b['xml'] ) ? (string) $b['xml'] : '';
		if ( '' === trim( $doc ) ) {
			return $this->erreur( 'ekoseo_xml_vide', 'Aucun document XML fourni.', 400 );
		}
		if ( strlen( $doc ) > self::MAX_OCTETS ) {
			return $this->erreur( 'ekoseo_xml_trop_gros',
				sprintf( 'Document de %d octets : maximum %d par appel. Découper en plusieurs envois.',
					strlen( $doc ), self::MAX_OCTETS ), 413 );
		}

		$items = $this->lire_wxr( $doc );
		if ( is_wp_error( $items ) ) {
			return $this->erreur( 'ekoseo_xml_illisible', $items->get_error_message(), 422 );
		}
		if ( ! $items ) {
			return $this->erreur( 'ekoseo_xml_sans_item', "Le document ne contient aucun <item>.", 422 );
		}
		if ( count( $items ) > self::MAX_ITEMS ) {
			return $this->erreur( 'ekoseo_trop_items',
				sprintf( '%d éléments : maximum %d par appel.', count( $items ), self::MAX_ITEMS ), 413 );
		}

		$dry      = ! empty( $b['dry_run'] );
		$creer    = ! empty( $b['creer_si_absent'] );     // par défaut : NON. On met à jour, on ne crée pas.
		$force    = ! empty( $b['force_geo'] );
		$preserver = ! empty( $b['preserver_arbre'] );
		$resultats = [];
		foreach ( $items as $it ) {
			$resultats[] = $this->un_item( $it, $dry, $creer, $force, $operation_id, $preserver );
		}

		$compte = [ 'maj' => 0, 'cree' => 0, 'absente' => 0, 'refuse' => 0 ];
		foreach ( $resultats as $r ) {
			$k = isset( $compte[ $r['action'] ] ) ? $r['action'] : 'refuse';
			$compte[ $k ]++;
		}
		$reponse = [
			'operation_id' => $operation_id,
			'dry_run'      => $dry,
			'resume'       => $compte,
			'items'        => $resultats,
		];
		EkoSEO_Audit::journaliser( [
			'operation_id'   => $operation_id,
			'action'         => 'import',
			'result'         => $dry ? 'skipped' : 'ok',
			'changed_fields' => array_keys( $compte ),
			'message'        => sprintf( 'WXR : %d mises à jour, %d créées, %d introuvables, %d refusées',
				$compte['maj'], $compte['cree'], $compte['absente'], $compte['refuse'] ),
			'response'       => $reponse,
		] );
		return rest_ensure_response( $reponse );
	}

	// ------------------------------------------------------------------ lecture

	/**
	 * Lit le WXR. Les entités externes sont refusées : un document XML venu de
	 * l'extérieur ne doit pas pouvoir faire lire des fichiers au serveur.
	 */
	private function lire_wxr( $doc ) {
		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $doc, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
		if ( false === $xml ) {
			$e = libxml_get_last_error();
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );
			return new WP_Error( 'xml', 'XML illisible : ' . ( $e ? trim( $e->message ) : 'erreur inconnue' ) );
		}
		libxml_use_internal_errors( $prev );

		$ns = $xml->getNamespaces( true );
		if ( empty( $ns['wp'] ) ) {
			return new WP_Error( 'xml', "Ce document n'est pas un export WordPress : l'espace de noms « wp » est absent." );
		}
		$out = [];
		$noeuds = isset( $xml->channel->item ) ? $xml->channel->item : [];
		foreach ( $noeuds as $item ) {
			$wp = $item->children( $ns['wp'] );
			$co = isset( $ns['content'] ) ? $item->children( $ns['content'] ) : null;
			$metas = [];
			if ( isset( $wp->postmeta ) ) {
				foreach ( $wp->postmeta as $m ) {
					$metas[ (string) $m->meta_key ] = (string) $m->meta_value;
				}
			}
			$out[] = [
				'slug'    => sanitize_title( (string) $wp->post_name ),
				'type'    => sanitize_key( (string) $wp->post_type ),
				'statut'  => (string) $wp->status,
				'titre'   => (string) $item->title,
				'html'    => $co ? (string) $co->encoded : '',
				'metas'   => $metas,
			];
		}
		return $out;
	}

	// ------------------------------------------------------------------ un item

	private function un_item( $it, $dry, $creer, $force, $operation_id, $preserver = false ) {
		$base = [ 'slug' => $it['slug'], 'type' => $it['type'], 'action' => 'refuse', 'post_id' => null ];
		if ( ! $it['slug'] || ! $it['type'] ) {
			$base['message'] = 'post_name ou post_type manquant.';
			return $base;
		}
		if ( ! post_type_exists( $it['type'] ) ) {
			$base['message'] = sprintf( 'Type de contenu « %s » inexistant sur ce site.', $it['type'] );
			return $base;
		}

		$html = $it['html'];
		if ( '' === trim( $html ) && isset( $it['metas']['_elementor_data'] ) ) {
			$html = EkoSEO_Elementor_Build::html_de_arbre( $it['metas']['_elementor_data'] );
		}
		if ( '' === trim( $html ) ) {
			$base['message'] = "Aucun contenu : ni content:encoded, ni widget HTML dans _elementor_data.";
			return $base;
		}

		$existant = get_posts( [
			'post_type'   => $it['type'],
			'name'        => $it['slug'],
			'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			'numberposts' => 1,
		] );
		$post = $existant ? $existant[0] : null;

		if ( ! $post && ! $creer ) {
			$base['action']  = 'absente';
			$base['message'] = "Aucune page de ce type ne porte ce slug. Rien créé : cet import met à jour "
				. "l'existant. Passer `creer_si_absent` pour autoriser la création.";
			return $base;
		}

		// ---- garde géographique, sur le chemin réel de la page
		$indice = $post ? implode( '/', $this->chemin( $post ) ) : $it['slug'];
		$geo = EkoSEO_Geo_Guard::verifier( $html, $indice );
		if ( $geo['contamine'] && ! $force ) {
			$base['message'] = EkoSEO_Geo_Guard::message( $geo );
			$base['geo']     = $geo;
			return $base;
		}

		// ---- structure, avec la page actuelle en référence pour les scripts
		$reference = $post ? ( EkoSEO_Elementor_IO::lire_corps( $post->ID )[0] ) : null;
		$err = EkoSEO_Html_Parser::valider( $html, 2097152, $reference );
		if ( $err ) {
			$base['message'] = implode( ' ', $err );
			return $base;
		}

		$base['post_id'] = $post ? (int) $post->ID : null;
		$base['action']  = $post ? 'maj' : 'cree';
		$base['cible']   = $geo['cible'];

		if ( $dry ) {
			$base['message'] = $post
				? sprintf( 'Mise à jour de #%d — %s', $post->ID, get_permalink( $post ) )
				: 'Création (autorisée par creer_si_absent)';
			$base['metas_a_poser'] = array_values( array_intersect(
				array_keys( $it['metas'] ), self::metas_admises() ) );
			return $base;
		}

		if ( $post ) {
			$id   = (int) $post->ID;
			$etat = $this->etat( $id );
			$snap = EkoSEO_Audit::snapshot( $id, $operation_id, $etat, $this->hash( $etat ) );
			if ( ! $snap ) {
				$base['action']  = 'refuse';
				$base['message'] = "Instantané impossible : écriture annulée.";
				return $base;
			}
			$base['snapshot_id'] = $snap;
			if ( '' !== trim( $it['titre'] ) && $it['titre'] !== $post->post_title ) {
				wp_update_post( [ 'ID' => $id, 'post_title' => wp_strip_all_tags( $it['titre'] ) ] );
			}
		} else {
			$id = wp_insert_post( [
				'post_type'      => $it['type'],
				'post_name'      => $it['slug'],
				'post_title'     => wp_strip_all_tags( $it['titre'] ? $it['titre'] : $it['slug'] ),
				'post_status'    => in_array( $it['statut'], [ 'publish', 'draft', 'pending', 'private' ], true )
					? $it['statut'] : 'draft',
				'post_content'   => '',
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

		// ---- le contenu
		//
		// Quand le document apporte son propre `_elementor_data` — c'est le cas de
		// tout WXR produit par la chaîne — on le pose EN BLOC, exactement comme
		// l'importateur WordPress. C'est la technique qui fonctionne en production
		// depuis des mois, et elle ne se discute pas : elle ne cherche pas à
		// deviner quel widget porte quoi, elle écrit l'arbre et laisse Elementor
		// rendre ce qu'il trouve.
		//
		// `preserver_arbre` bascule sur l'autre comportement — ne remplacer que le
		// widget HTML, en gardant les blocs ajoutés à la main depuis l'import.
		$arbre_doc = isset( $it['metas']['_elementor_data'] ) ? $it['metas']['_elementor_data'] : '';
		if ( $arbre_doc && ! $preserver ) {
			list( $ok, $ou ) = EkoSEO_Elementor_Build::poser_arbre( $id, $arbre_doc, $html, $it['metas'] );
		} else {
			list( $ok, $ou ) = EkoSEO_Elementor_Build::appliquer(
				$id, $html,
				isset( $it['metas']['_ekoseo_hero_template'] ) ? $it['metas']['_ekoseo_hero_template'] : '',
				isset( $it['metas']['_elementor_version'] ) ? $it['metas']['_elementor_version'] : ''
			);
		}
		if ( ! $ok ) {
			$base['action']  = 'refuse';
			$base['message'] = $ou;
			return $base;
		}
		$base['contenu'] = $ou;

		// ---- les métas déclarées, et seulement celles-là
		$admises = self::metas_admises();
		$posees  = [];
		EkoSEO_Elementor_IO::sans_kses( function () use ( $id, $it, $admises, &$posees ) {
			foreach ( $it['metas'] as $k => $v ) {
				if ( ! in_array( $k, $admises, true ) ) {
					continue;
				}
				if ( '_elementor_data' === $k ) {
					continue;    // déjà posé par appliquer(), qui préserve les autres widgets
				}
				if ( '_elementor_page_settings' === $k && is_string( $v )
					&& 0 === strpos( $v, 'a:' ) ) {
					$d = maybe_unserialize( $v );
					update_post_meta( $id, $k, is_array( $d ) ? $d : $v );
				} else {
					update_post_meta( $id, $k, $v );
				}
				$posees[] = $k;
			}
		} );

		$base['metas']         = $posees;
		$base['caches_purged'] = EkoSEO_Cache::purger( $id );
		$base['permalink']     = get_permalink( $id );
		$base['message']       = $post ? 'Mise à jour en place.' : 'Créée.';
		return $base;
	}
}
