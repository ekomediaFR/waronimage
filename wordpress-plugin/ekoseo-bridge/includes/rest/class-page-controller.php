<?php
/**
 * GET /page/{id} · PUT /page/{id} · POST /page/{id}/rollback
 *
 * L'ordre d'exécution du PUT n'est pas négociable :
 *   permissions → idempotence → verrou → version → validation → dry-run →
 *   snapshot → écriture → purge → journal → réponse.
 *
 * Chaque étape peut arrêter la séquence. Aucune écriture n'a lieu avant que le
 * snapshot soit en base : si l'écriture casse quelque chose, on peut revenir.
 */

defined( 'ABSPATH' ) || exit;

/** Une écriture que le site a filtrée : on remonte, on n'improvise pas. */
class EkoSEO_Ecriture_Refusee extends Exception {}

class EkoSEO_Page_Controller extends EkoSEO_Controller_Base {

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/page/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'permissions' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'permissions' ],
				],
			]
		);
		register_rest_route(
			$this->namespace,
			'/page/(?P<id>\d+)/rollback',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rollback' ],
				'permission_callback' => [ $this, 'permissions' ],
			]
		);
		register_rest_route(
			$this->namespace,
			'/page',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => [ $this, 'permissions' ],
			]
		);
	}

	public function get_item( $req ) {
		$id   = (int) $req['id'];
		$etat = $this->etat( $id );
		if ( ! $etat ) {
			return $this->erreur( 'ekoseo_introuvable', 'Page inexistante.', 404 );
		}
		$sortie                = $etat;
		$sortie['hash']        = $this->hash( $etat );
		$sortie['blocks']      = EkoSEO_Html_Parser::blocs( $etat['html'] );
		$sortie['stats']       = EkoSEO_Html_Parser::stats( $etat['html'] );
		$sortie['html_length'] = strlen( (string) $etat['html'] );
		unset( $sortie['html'] );   // le corps complet se demande explicitement
		if ( $req->get_param( 'with_html' ) ) {
			$sortie['html'] = $etat['html'];
		}
		return rest_ensure_response( $sortie );
	}

	public function create_item( $req ) {
		return $this->erreur(
			'ekoseo_non_implemente',
			"La création de page arrive au sprint suivant. Ce sprint valide la chaîne d'écriture sur des champs réversibles.",
			501
		);
	}

	public function update_item( $req ) {
		$id = (int) $req['id'];
		$b  = $req->get_json_params();
		if ( ! is_array( $b ) ) {
			return $this->erreur( 'ekoseo_corps_invalide', 'Corps JSON attendu.', 400 );
		}

		$operation_id = isset( $b['operation_id'] ) ? (string) $b['operation_id'] : '';
		if ( '' === $operation_id ) {
			return $this->erreur( 'ekoseo_operation_id_manquant', 'operation_id (UUID v4) obligatoire.', 400 );
		}

		// ---- 2. idempotence : ne jamais réappliquer une opération réussie
		$deja = EkoSEO_Audit::deja_traitee( $operation_id );
		if ( $deja ) {
			$rep = rest_ensure_response(
				array_merge(
					is_array( $deja['response'] ) ? $deja['response'] : [],
					[ 'replayed' => true, 'operation_id' => $operation_id ]
				)
			);
			$rep->set_status( 200 );
			return $rep;
		}

		$etat = $this->etat( $id );
		if ( ! $etat ) {
			return $this->erreur( 'ekoseo_introuvable', 'Page inexistante.', 404 );
		}
		$hash_courant = $this->hash( $etat );

		// ---- 3. verrou : on n'écrit jamais sur une page qui a bougé depuis la lecture
		$attendu = isset( $b['expected_hash'] ) ? (string) $b['expected_hash'] : '';
		if ( '' === $attendu ) {
			return $this->erreur( 'ekoseo_hash_manquant', 'expected_hash obligatoire.', 400 );
		}
		if ( ! hash_equals( $hash_courant, $attendu ) ) {
			$this->journal( $id, $operation_id, 'update', 'conflict', $b, [], 'Hash divergent', null, null );
			return $this->erreur(
				'ekoseo_conflit',
				"La page a été modifiée depuis sa lecture. Relire /page/{$id} et rejouer avec le hash à jour.",
				409,
				[ 'current_hash' => $hash_courant, 'expected_hash' => $attendu ]
			);
		}

		// ---- 4. version : la structure Elementor n'est pas garantie entre majeures
		$epingle = EkoSEO_Installer::pinned_elementor_major();
		$actuel  = EkoSEO_Installer::elementor_major();
		if ( null !== $epingle && null !== $actuel && $epingle !== $actuel ) {
			return $this->erreur(
				'ekoseo_version_elementor',
				sprintf( 'Elementor est passé de la majeure %d à %d. Revalider le pont avant d\'écrire.', $epingle, $actuel ),
				412,
				[ 'pinned' => $epingle, 'current' => $actuel ]
			);
		}

		// ---- 4 bis. corps HTML : structure, puis géographie
		if ( array_key_exists( 'html', $b ) ) {
			if ( '' === trim( (string) $b['html'] ) ) {
				return $this->erreur(
					'ekoseo_html_vide',
					"Un corps vide effacerait la page. Pour la dépublier, passer par status.",
					422
				);
			}
			// la page ACTUELLE sert de référence : un script déjà là repart tel quel
			$err = EkoSEO_Html_Parser::valider( (string) $b['html'], 2097152, (string) $etat['html'] );
			if ( $err ) {
				return $this->erreur( 'ekoseo_html_invalide', implode( ' ', $err ), 422, [ 'validation' => $err ] );
			}

			// La garde géographique. Elle est ici, à la dernière porte, et non
			// dans le générateur : un générateur se remplace, cette porte reste.
			$indice = isset( $b['content_locality'] ) && '' !== $b['content_locality']
				? (string) $b['content_locality']
				: (string) $etat['path'];
			$geo = EkoSEO_Geo_Guard::verifier( (string) $b['html'], $indice );
			if ( $geo['contamine'] && empty( $b['force_geo'] ) ) {
				$this->journal( $id, $operation_id, 'update', 'rejected', $b, [], 'Contamination géographique', null, null );
				return $this->erreur(
					'ekoseo_contamination_geo',
					EkoSEO_Geo_Guard::message( $geo ),
					422,
					[ 'geo' => $geo, 'contournable' => 'force_geo' ]
				);
			}
			$b['_geo'] = $geo;
		}

		// ---- 5. validation des champs acceptés
		$erreurs = $this->valider( $b );
		if ( $erreurs ) {
			return $this->erreur( 'ekoseo_validation', implode( ' ', $erreurs ), 422, [ 'erreurs' => $erreurs ] );
		}

		$diff = $this->diff( $etat, $b );

		// ---- 6. dry-run : on s'arrête là, rien n'est écrit
		if ( ! empty( $b['dry_run'] ) ) {
			$this->journal( $id, $operation_id, 'update', 'skipped', $b, array_keys( $diff ), 'dry_run', null, null );
			return rest_ensure_response(
				[
					'dry_run'        => true,
					'operation_id'   => $operation_id,
					'current_hash'   => $hash_courant,
					'changed_fields' => array_keys( $diff ),
					'diff'           => $diff,
				]
			);
		}

		// hero_template_id / image_defaut sont des intentions hors diff (elles se
		// jugent sur la page, pas sur les champs) : elles forcent le passage.
		$intentions = ! empty( $b['hero_template_id'] ) || ! empty( $b['image_defaut'] );
		if ( ! $diff && ! $intentions ) {
			return rest_ensure_response(
				[ 'operation_id' => $operation_id, 'changed_fields' => [], 'new_hash' => $hash_courant,
				  'message' => 'Aucun écart : rien à écrire.' ]
			);
		}

		// ---- 7. snapshot AVANT toute écriture
		$snapshot_id = EkoSEO_Audit::snapshot( $id, $operation_id, $etat, $hash_courant );
		if ( ! $snapshot_id ) {
			return $this->erreur( 'ekoseo_snapshot', "Le snapshot n'a pas pu être enregistré : écriture annulée.", 500 );
		}

		// ---- 8. écriture
		try {
			$changes = $this->ecrire( $id, $b, $etat );
		} catch ( EkoSEO_Ecriture_Refusee $e ) {
			$this->journal( $id, $operation_id, 'update', 'error', $b, [], $e->getMessage(), $snapshot_id, null );
			return $this->erreur( 'ekoseo_ecriture_filtree', $e->getMessage(), 422,
				[ 'snapshot_id' => $snapshot_id ] );
		}

		// ---- 9. purge
		$purges = EkoSEO_Cache::purger( $id );

		$apres    = $this->etat( $id );
		$new_hash = $apres ? $this->hash( $apres ) : null;
		$revision = 0;
		$revs     = wp_get_post_revisions( $id, [ 'numberposts' => 1 ] );
		if ( $revs ) {
			$revision = (int) array_key_first( $revs );
		}

		$reponse = [
			'operation_id'   => $operation_id,
			'revision_id'    => $revision,
			'snapshot_id'    => $snapshot_id,
			'new_hash'       => $new_hash,
			'changed_fields' => $changes,
			'caches_purged'  => $purges,
			'permalink'      => $apres ? $apres['permalink'] : null,
		];

		// ---- 10. journal
		$this->journal( $id, $operation_id, 'update', 'ok', $b, $changes, null, $snapshot_id, $reponse );

		return rest_ensure_response( $reponse );
	}

	public function rollback( $req ) {
		$id = (int) $req['id'];
		$b  = $req->get_json_params();
		$sid = isset( $b['snapshot_id'] ) ? (int) $b['snapshot_id'] : 0;
		$operation_id = isset( $b['operation_id'] ) ? (string) $b['operation_id'] : wp_generate_uuid4();

		$deja = EkoSEO_Audit::deja_traitee( $operation_id );
		if ( $deja ) {
			return rest_ensure_response( array_merge( (array) $deja['response'], [ 'replayed' => true ] ) );
		}

		$snap = EkoSEO_Audit::lire_snapshot( $sid );
		if ( ! $snap ) {
			return $this->erreur( 'ekoseo_snapshot_introuvable', 'Snapshot inexistant.', 404 );
		}
		if ( (int) $snap['post_id'] !== $id ) {
			return $this->erreur( 'ekoseo_snapshot_autre_page', 'Ce snapshot appartient à une autre page.', 409 );
		}

		$avant   = $this->etat( $id );
		$courant = $avant ? $this->hash( $avant ) : null;
		$cible   = $snap['payload'];

		// le rollback est une écriture : il a droit à son propre snapshot
		$snapshot_id = EkoSEO_Audit::snapshot( $id, $operation_id, $avant, $courant );

		$corps = [
			'title'  => $cible['title'],
			'slug'   => $cible['slug'],
			'status' => $cible['status'],
			'seo'    => $cible['seo'],
			'meta'   => $cible['meta'],
		];
		// Le snapshot porte le corps : une restauration qui l'oublierait laisserait
		// la page à moitié revenue en arrière — le pire des deux états.
		if ( isset( $cible['html'] ) && '' !== trim( (string) $cible['html'] ) ) {
			$corps['html'] = $cible['html'];
		}
		$changes = $this->ecrire( $id, $corps, $avant );
		$purges  = EkoSEO_Cache::purger( $id );
		$apres   = $this->etat( $id );

		$reponse = [
			'operation_id'    => $operation_id,
			'restored_from'   => $sid,
			'snapshot_id'     => $snapshot_id,
			'new_hash'        => $apres ? $this->hash( $apres ) : null,
			'target_hash'     => $snap['hash_before'],
			'changed_fields'  => $changes,
			'caches_purged'   => $purges,
		];
		$this->journal( $id, $operation_id, 'rollback', 'ok', $b, $changes, 'Restauration du snapshot ' . $sid, $snapshot_id, $reponse );
		return rest_ensure_response( $reponse );
	}

	// ---------------------------------------------------------------- outils

	private function valider( array $b ) {
		$e = [];
		if ( isset( $b['title'] ) && '' === trim( (string) $b['title'] ) ) {
			$e[] = 'Le titre ne peut pas être vide.';
		}
		if ( isset( $b['slug'] ) && ! preg_match( '/^[a-z0-9\-]+$/', (string) $b['slug'] ) ) {
			$e[] = 'Slug invalide : minuscules, chiffres et tirets uniquement.';
		}
		if ( isset( $b['status'] ) && ! in_array( $b['status'], [ 'publish', 'draft', 'pending', 'private' ], true ) ) {
			$e[] = 'Statut non autorisé.';
		}
		if ( isset( $b['seo'] ) && ! is_array( $b['seo'] ) ) {
			$e[] = 'seo doit être un objet.';
		}
		if ( isset( $b['meta'] ) && ! is_array( $b['meta'] ) ) {
			$e[] = 'meta doit être un objet.';
		}
		if ( isset( $b['seo']['metadesc'] ) && mb_strlen( (string) $b['seo']['metadesc'] ) > 320 ) {
			$e[] = 'Meta description au-delà de 320 caractères.';
		}

		// Les champs Yoast ouverts le 2026-08-23. Chacun a une forme attendue, et
		// une valeur mal formée ne casse rien visuellement — elle rend simplement
		// le champ inopérant, ce qui est pire : on croit avoir agi.
		if ( isset( $b['seo'] ) && is_array( $b['seo'] ) ) {
			$s = $b['seo'];
			if ( isset( $s['canonical'] ) && '' !== trim( (string) $s['canonical'] )
				&& ! filter_var( $s['canonical'], FILTER_VALIDATE_URL ) ) {
				$e[] = 'La canonique doit être une URL absolue.';
			}
			foreach ( [ 'noindex', 'nofollow' ] as $k ) {
				if ( isset( $s[ $k ] ) && ! in_array( (string) $s[ $k ], [ '', '0', '1', '2' ], true ) ) {
					$e[] = sprintf( '%s attend une valeur vide, 0, 1 ou 2 — pas « %s ».', $k, $s[ $k ] );
				}
			}
			if ( isset( $s['cornerstone'] ) && ! in_array( (string) $s['cornerstone'], [ '', '0', '1' ], true ) ) {
				$e[] = 'cornerstone attend 1 ou une valeur vide.';
			}
			if ( isset( $s['synonyms'] ) && ! is_array( $s['synonyms'] ) ) {
				$e[] = 'seo.synonyms doit être un tableau de chaînes.';
			}
			if ( isset( $s['extra_kw'] ) && ! is_array( $s['extra_kw'] ) ) {
				$e[] = 'seo.extra_kw doit être un tableau de chaînes.';
			}
			if ( isset( $s['focus_kw'] ) && mb_strlen( (string) $s['focus_kw'] ) > 191 ) {
				$e[] = 'Mot-clé principal au-delà de 191 caractères.';
			}
			$connus = array_merge( array_keys( EkoSEO_Yoast_IO::CHAMPS ), [ 'synonyms', 'extra_kw' ] );
			foreach ( array_keys( $s ) as $k ) {
				if ( ! in_array( $k, $connus, true ) ) {
					$e[] = sprintf( 'Champ SEO inconnu : « %s ». Connus : %s.',
						$k, implode( ', ', $connus ) );
				}
			}
		}
		return $e;
	}


	private function diff( array $etat, array $b ) {
		$d = [];
		foreach ( [ 'title', 'slug', 'status' ] as $c ) {
			if ( array_key_exists( $c, $b ) && (string) $b[ $c ] !== (string) $etat[ $c ] ) {
				$d[ $c ] = [ 'avant' => $etat[ $c ], 'apres' => $b[ $c ] ];
			}
		}
		if ( array_key_exists( 'html', $b ) ) {
			$av = EkoSEO_Html_Parser::stats( (string) $etat['html'] );
			$ap = EkoSEO_Html_Parser::stats( (string) $b['html'] );
			if ( trim( (string) $b['html'] ) !== trim( (string) $etat['html'] ) ) {
				$d['html'] = [
					'avant' => array_merge( $av, [ 'octets' => strlen( (string) $etat['html'] ) ] ),
					'apres' => array_merge( $ap, [ 'octets' => strlen( (string) $b['html'] ) ] ),
					'geo'   => isset( $b['_geo'] ) ? [
						'cible'          => $b['_geo']['cible'],
						'mentions_cible' => $b['_geo']['mentions_cible'],
						'intrus'         => $b['_geo']['intrus'],
					] : null,
				];
			}
		}
		if ( isset( $b['seo'] ) && is_array( $b['seo'] ) ) {
			foreach ( $b['seo'] as $k => $v ) {
				$av = isset( $etat['seo'][ $k ] ) ? $etat['seo'][ $k ] : null;
				if ( is_array( $v ) || is_array( $av ) ) {
					if ( array_values( (array) $v ) !== array_values( (array) $av ) ) {
						$d[ 'seo.' . $k ] = [ 'avant' => $av, 'apres' => $v ];
					}
				} elseif ( (string) $v !== (string) $av ) {
					$d[ 'seo.' . $k ] = [ 'avant' => $av, 'apres' => $v ];
				}
			}
		}
		if ( isset( $b['meta'] ) && is_array( $b['meta'] ) ) {
			foreach ( $b['meta'] as $k => $v ) {
				$av = isset( $etat['meta'][ $k ] ) ? $etat['meta'][ $k ] : '';
				if ( (string) $v !== (string) $av ) {
					$d[ 'meta.' . $k ] = [ 'avant' => $av, 'apres' => $v ];
				}
			}
		}
		return $d;
	}

	private function ecrire( $id, array $b, array $etat ) {
		$changes = [];
		$post    = [];

		if ( array_key_exists( 'html', $b ) && trim( (string) $b['html'] ) !== trim( (string) $etat['html'] ) ) {
			list( $ok, $ou ) = EkoSEO_Elementor_IO::ecrire_corps( $id, (string) $b['html'] );
			if ( ! $ok ) {
				// Une écriture filtrée n'est pas un détail à consigner : c'est un
				// échec. La laisser passer annoncerait un succès sur une page cassée.
				throw new EkoSEO_Ecriture_Refusee( $ou );
			}
			$changes[] = 'html:' . $ou;
		}

		if ( array_key_exists( 'title', $b ) && (string) $b['title'] !== (string) $etat['title'] ) {
			$post['post_title'] = wp_strip_all_tags( (string) $b['title'] );
			$changes[]          = 'title';
		}
		if ( array_key_exists( 'status', $b ) && (string) $b['status'] !== (string) $etat['status'] ) {
			$post['post_status'] = (string) $b['status'];
			$changes[]           = 'status';
		}

		$ancien_permalink = null;
		if ( array_key_exists( 'slug', $b ) && (string) $b['slug'] !== (string) $etat['slug'] ) {
			$ancien_permalink   = $etat['permalink'];
			$post['post_name']  = sanitize_title( (string) $b['slug'] );
			$changes[]          = 'slug';
		}

		if ( $post ) {
			$post['ID'] = $id;
			wp_update_post( $post );
		}

		if ( isset( $b['seo'] ) && is_array( $b['seo'] ) ) {
			$changes = array_merge( $changes, EkoSEO_Yoast_IO::ecrire( $id, $b['seo'] ) );
		}

		// ---- compléter le HERO d'une page qui en manque (31/08/2026) : le
		// template dynamique en tête d'arbre (idempotent), l'image mise en
		// avant par défaut (jamais en écrasement).
		if ( ! empty( $b['hero_template_id'] ) ) {
			list( $h_chg, $h_msg ) = EkoSEO_Elementor_Build::inserer_hero( $id, (string) $b['hero_template_id'] );
			if ( $h_chg ) {
				$changes[] = 'hero:' . $h_msg;
			}
		}
		if ( ! empty( $b['image_defaut'] ) && ! has_post_thumbnail( $id ) ) {
			$att = attachment_url_to_postid( esc_url_raw( (string) $b['image_defaut'] ) );
			if ( $att ) {
				set_post_thumbnail( $id, $att );
				$changes[] = 'image.featured';
				if ( '' === (string) get_post_meta( $id, 'gallery', true ) ) {
					update_post_meta( $id, 'gallery', (string) $att );
					$changes[] = 'meta.gallery';
				}
			}
		}

		if ( isset( $b['meta'] ) && is_array( $b['meta'] ) ) {
			$autorisees = self::metas_autorisees();
			foreach ( $b['meta'] as $k => $v ) {
				if ( ! in_array( $k, $autorisees, true ) ) {
					continue;       // une méta non déclarée ne s'écrit pas par surprise
				}
				if ( (string) $v !== (string) get_post_meta( $id, $k, true ) ) {
					update_post_meta( $id, $k, (string) $v );
					$changes[] = 'meta.' . $k;
				}
			}
		}

		// Un slug qui change sans redirection casse les liens entrants et
		// l'historique Search Console. On la pose, ou on le dit.
		if ( $ancien_permalink ) {
			$fait = $this->redirection( $ancien_permalink, get_permalink( $id ) );
			$changes[] = $fait ? 'redirect_301' : 'redirect_301:manquante';
		}

		return array_values( array_unique( $changes ) );
	}

	/** Pose une 301 si une extension de redirection connue est présente. */
	private function redirection( $depuis, $vers ) {
		$depuis = wp_parse_url( $depuis, PHP_URL_PATH );
		if ( ! $depuis || $depuis === wp_parse_url( $vers, PHP_URL_PATH ) ) {
			return false;
		}
		if ( class_exists( 'Red_Item' ) && method_exists( 'Red_Item', 'create' ) ) {
			Red_Item::create(
				[ 'url' => $depuis, 'action_data' => [ 'url' => $vers ], 'action_type' => 'url',
				  'action_code' => 301, 'group_id' => 1, 'match_type' => 'url' ]
			);
			return true;
		}
		if ( function_exists( 'YoastSEO' ) && class_exists( '\Yoast\WP\SEO\Premium\Repositories\Redirect_Repository' ) ) {
			do_action( 'wpseo_premium_create_redirect', $depuis, $vers, 301 );
			return true;
		}
		return false;
	}

	private function journal( $id, $op, $action, $result, $b, $changes, $message, $snapshot_id, $reponse ) {
		EkoSEO_Audit::journaliser(
			[
				'post_id'            => $id,
				'operation_id'       => $op,
				'action'             => $action,
				'reason'             => isset( $b['reason'] ) ? (string) $b['reason'] : null,
				'recommendation_ids' => isset( $b['recommendation_ids'] ) ? (array) $b['recommendation_ids'] : [],
				'changed_fields'     => $changes,
				'result'             => $result,
				'message'            => $message,
				'snapshot_id'        => $snapshot_id,
				'response'           => $reponse,
			]
		);
	}
}
