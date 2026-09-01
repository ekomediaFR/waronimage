<?php
/**
 * GET /menus · PUT /menu/{id} · POST /menu/{id}/restaurer
 *
 * Pourquoi cette route existe, et pourquoi elle compte plus que les autres.
 *
 * Relevé du 2026-08-20 sur demenagementgauvin.com : une page émet 185 liens
 * internes en moyenne, dont 131 se retrouvent sur plus de 90 % des pages. Ces
 * 131 liens ne sont pas dans le contenu — les retirer page par page ne change
 * rien, le thème les réinjecte au rendu suivant. Ils viennent du menu et du
 * pied de page. Autrement dit 71 % de la dilution d'autorité du site était hors
 * de portée de tout ce que le pont savait faire jusqu'ici.
 *
 * Le menu se modifie donc à sa source, une fois, pour tout le site. C'est aussi
 * ce qui rend l'opération dangereuse : un menu cassé rend un site inutilisable
 * sur toutes ses pages à la fois. D'où l'instantané systématique et la route de
 * restauration.
 *
 * Le format d'une entrée en lecture est exactement celui attendu en écriture :
 * la charge d'un instantané se rejoue telle quelle, sans traduction.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Menu_Controller extends EkoSEO_Controller_Base {

	/** Au-delà, la requête risque le délai d'exécution PHP : deux passes d'écriture par entrée. */
	const MAX_ENTREES = 300;

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/menus',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'permissions' ],
			]
		);
		register_rest_route(
			$this->namespace,
			'/menu/(?P<id>\d+)',
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
			'/menu/(?P<id>\d+)/restaurer',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'restaurer' ],
				'permission_callback' => [ $this, 'permissions' ],
			]
		);
	}

	// ------------------------------------------------------------- lecture

	public function get_items( $req ) {
		$pages       = $this->pages_du_site();
		$emplacements = get_nav_menu_locations();
		$libelles     = get_registered_nav_menus();

		$menus = [];
		$total = 0;
		foreach ( wp_get_nav_menus() as $m ) {
			$etat = $this->menu_etat( $m );
			$ou   = [];
			foreach ( $emplacements as $slot => $term_id ) {
				if ( (int) $term_id === (int) $m->term_id ) {
					$ou[] = [
						'slot'   => $slot,
						'libelle' => isset( $libelles[ $slot ] ) ? $libelles[ $slot ] : $slot,
					];
				}
			}
			$etat['emplacements'] = $ou;
			$etat['cout']         = $this->cout( count( $etat['entrees'] ), $pages, (bool) $ou );
			if ( $ou ) {
				$total += $etat['cout']['liens_injectes'];
			}
			$menus[] = $etat;
		}

		return rest_ensure_response(
			[
				'pages_du_site'        => $pages,
				'menus'                => $menus,
				'liens_injectes_total' => $total,
			]
		);
	}

	public function get_item( $req ) {
		$menu = wp_get_nav_menu_object( (int) $req['id'] );
		if ( ! $menu ) {
			return $this->erreur( 'ekoseo_menu_introuvable', 'Menu inexistant.', 404 );
		}
		$etat         = $this->menu_etat( $menu );
		$etat['cout'] = $this->cout( count( $etat['entrees'] ), $this->pages_du_site(), null );
		return rest_ensure_response( $etat );
	}

	// ------------------------------------------------------------- écriture

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

		$menu = wp_get_nav_menu_object( $id );
		if ( ! $menu ) {
			return $this->erreur( 'ekoseo_menu_introuvable', 'Menu inexistant.', 404 );
		}

		$etat         = $this->menu_etat( $menu );
		$hash_courant = $etat['hash'];

		$attendu = isset( $b['expected_hash'] ) ? (string) $b['expected_hash'] : '';
		if ( '' === $attendu ) {
			return $this->erreur( 'ekoseo_hash_manquant', 'expected_hash obligatoire.', 400 );
		}
		if ( ! hash_equals( $hash_courant, $attendu ) ) {
			$this->journal( $id, $operation_id, 'update', 'conflict', $b, [], 'Hash divergent', null, null );
			return $this->erreur(
				'ekoseo_conflit',
				"Le menu a été modifié depuis sa lecture. Relire /menu/{$id} et rejouer avec le hash à jour.",
				409,
				[ 'current_hash' => $hash_courant, 'expected_hash' => $attendu ]
			);
		}

		if ( ! isset( $b['entrees'] ) || ! is_array( $b['entrees'] ) ) {
			return $this->erreur( 'ekoseo_entrees_manquantes', 'entrees doit être une liste.', 400 );
		}
		$entrees = array_values( $b['entrees'] );

		// Une liste vide vide le menu — donc supprime la navigation de toutes les
		// pages du site d'un coup. Ça peut se vouloir ; ça ne se fait pas par
		// omission d'un champ dans un appel généré.
		if ( ! $entrees && empty( $b['vider'] ) ) {
			return $this->erreur(
				'ekoseo_menu_vide',
				"Une liste d'entrées vide supprimerait la navigation sur l'ensemble du site. Passer vider: true pour l'assumer.",
				422
			);
		}
		if ( count( $entrees ) > self::MAX_ENTREES ) {
			return $this->erreur(
				'ekoseo_trop_entrees',
				sprintf( '%d entrées : maximum %d par appel.', count( $entrees ), self::MAX_ENTREES ),
				413
			);
		}

		$existants = [];
		foreach ( $etat['entrees'] as $e ) {
			$existants[ (int) $e['id'] ] = $e;
		}

		$voulues = [];
		$erreurs = [];
		$refs    = [];
		foreach ( $entrees as $i => $e ) {
			if ( ! is_array( $e ) ) {
				$erreurs[] = sprintf( 'Entrée %d : objet attendu.', $i );
				continue;
			}
			$n = $this->normaliser_entree( $e, $i );
			if ( is_string( $n ) ) {
				$erreurs[] = sprintf( 'Entrée %d : %s', $i, $n );
				continue;
			}
			// Une référence en double ferait écrire deux entrées au même endroit :
			// la seconde écrase la première, qui disparaît sans que rien ne le dise.
			foreach ( [ (string) $n['id'], $n['cle'] ] as $ref ) {
				if ( '' === $ref || '0' === $ref ) {
					continue;
				}
				if ( isset( $refs[ $ref ] ) ) {
					$erreurs[] = sprintf( 'Entrée %d : référence « %s » déjà utilisée par l\'entrée %d.', $i, $ref, $refs[ $ref ] );
				}
				$refs[ $ref ] = $i;
			}
			$voulues[] = $n;
		}
		if ( $erreurs ) {
			return $this->erreur( 'ekoseo_validation', implode( ' ', $erreurs ), 422, [ 'erreurs' => $erreurs ] );
		}

		$diff = $this->diff( $etat['entrees'], $voulues, $existants );

		if ( ! empty( $b['dry_run'] ) ) {
			$this->journal( $id, $operation_id, 'update', 'skipped', $b, [], 'dry_run', null, null );
			return rest_ensure_response(
				[
					'dry_run'      => true,
					'operation_id' => $operation_id,
					'menu_id'      => $id,
					'current_hash' => $hash_courant,
					'diff'         => $diff,
					'cout'         => $this->cout( count( $voulues ), $this->pages_du_site(), null ),
				]
			);
		}

		if ( ! $diff['ajoutees'] && ! $diff['retirees'] && ! $diff['deplacees'] && ! $diff['modifiees'] ) {
			return rest_ensure_response(
				[
					'operation_id'   => $operation_id,
					'menu_id'        => $id,
					'changed_fields' => [],
					'new_hash'       => $hash_courant,
					'message'        => 'Aucun écart : rien à écrire.',
				]
			);
		}

		// L'instantané porte le menu entier, pas le diff : c'est la seule forme
		// qui se rejoue sans dépendre de l'état courant. post_id = 0, un menu
		// n'appartient à aucune page.
		$snapshot_id = EkoSEO_Audit::snapshot( 0, $operation_id, $etat, $hash_courant );
		if ( ! $snapshot_id ) {
			return $this->erreur( 'ekoseo_snapshot', "L'instantané n'a pas pu être enregistré : écriture annulée.", 500 );
		}

		return $this->ecrire_et_repondre( $id, $operation_id, $b, $voulues, $existants, $snapshot_id, 'update', $diff, null );
	}

	public function restaurer( $req ) {
		$id = (int) $req['id'];
		$b  = $req->get_json_params();
		if ( ! is_array( $b ) ) {
			$b = [];
		}
		$operation_id = isset( $b['operation_id'] ) ? (string) $b['operation_id'] : wp_generate_uuid4();
		$sid          = isset( $b['snapshot_id'] ) ? (int) $b['snapshot_id'] : 0;

		$deja = EkoSEO_Audit::deja_traitee( $operation_id );
		if ( $deja ) {
			return rest_ensure_response(
				array_merge( is_array( $deja['response'] ) ? $deja['response'] : [], [ 'replayed' => true ] )
			);
		}

		$menu = wp_get_nav_menu_object( $id );
		if ( ! $menu ) {
			return $this->erreur( 'ekoseo_menu_introuvable', 'Menu inexistant.', 404 );
		}

		$snap = EkoSEO_Audit::lire_snapshot( $sid );
		if ( ! $snap ) {
			return $this->erreur( 'ekoseo_snapshot_introuvable', 'Instantané inexistant.', 404 );
		}
		$cible = is_array( $snap['payload'] ) ? $snap['payload'] : [];
		// Les instantanés de menu portent tous post_id = 0 : le seul contrôle
		// d'appartenance possible est l'identifiant inscrit dans la charge.
		if ( ! isset( $cible['menu']['id'] ) || (int) $cible['menu']['id'] !== $id ) {
			return $this->erreur( 'ekoseo_snapshot_autre_menu', 'Cet instantané appartient à un autre menu.', 409 );
		}

		$etat = $this->menu_etat( $menu );

		// La restauration est une écriture comme une autre : elle mérite sa
		// simulation. Sans ce point d'arrêt, un client qui demandait un aperçu du
		// retour arrière déclenchait le retour arrière.
		if ( ! empty( $b['dry_run'] ) ) {
			return rest_ensure_response( [
				'dry_run'      => true,
				'operation_id' => $operation_id,
				'menu_id'      => $menu_id,
				'snapshot_id'  => (int) $sid,
				'entrees_actuelles' => count( $etat['entrees'] ),
				'entrees_cible'     => count( (array) $cible['entrees'] ),
				'message'      => sprintf(
					'Le menu passerait de %d à %d entrées, restauré depuis l\'instantané %d.',
					count( $etat['entrees'] ), count( (array) $cible['entrees'] ), (int) $sid
				),
			] );
		}

		$snapshot_id = EkoSEO_Audit::snapshot( 0, $operation_id, $etat, $etat['hash'] );
		// Un instantané qui n'a pas pu être écrit rend la restauration
		// irréversible : elle écraserait l'état courant sans filet. On s'arrête.
		if ( ! $snapshot_id ) {
			return $this->erreur( 'ekoseo_snapshot',
				"L'instantané de l'état actuel n'a pas pu être enregistré : restauration annulée. "
				. 'Sans lui, ce retour arrière ne serait lui-même pas rattrapable.', 500 );
		}

		$existants = [];
		foreach ( $etat['entrees'] as $e ) {
			$existants[ (int) $e['id'] ] = $e;
		}

		$voulues = [];
		foreach ( (array) $cible['entrees'] as $i => $e ) {
			$n = $this->normaliser_entree( (array) $e, $i );
			if ( ! is_string( $n ) ) {
				$voulues[] = $n;
			}
		}

		$diff = $this->diff( $etat['entrees'], $voulues, $existants );

		return $this->ecrire_et_repondre(
			$id, $operation_id, $b, $voulues, $existants, $snapshot_id, 'rollback', $diff,
			[ 'id' => $sid, 'hash' => (string) $snap['hash_before'] ]
		);
	}

	/**
	 * Applique, relit, purge, journalise. Commun au PUT et à la restauration :
	 * une restauration est une écriture comme une autre, avec les mêmes gardes.
	 */
	private function ecrire_et_repondre( $id, $operation_id, array $b, array $voulues, array $existants, $snapshot_id, $action, array $diff, $restaure ) {
		list( $changes, $ecarts ) = $this->appliquer( $id, $voulues, $existants );

		$purges = EkoSEO_Cache::purger( 0 );
		$menu   = wp_get_nav_menu_object( $id );
		$apres  = $menu ? $this->menu_etat( $menu ) : null;

		$reponse = [
			'operation_id'   => $operation_id,
			'menu_id'        => $id,
			'snapshot_id'    => $snapshot_id,
			'new_hash'       => $apres ? $apres['hash'] : null,
			'changed_fields' => $changes,
			'diff'           => $diff,
			'entrees'        => $apres ? count( $apres['entrees'] ) : null,
			'caches_purged'  => $purges,
			'cout'           => $apres ? $this->cout( count( $apres['entrees'] ), $this->pages_du_site(), null ) : null,
		];
		if ( null !== $restaure ) {
			$reponse['restored_from'] = (int) $restaure['id'];
			$reponse['target_hash']   = $restaure['hash'];
		}

		// Écrire puis annoncer sans relire, c'est annoncer un succès qu'on n'a pas
		// vérifié. Le site filtre parfois ce qu'on lui donne ; on le dit au lieu
		// de le découvrir en regardant la page.
		if ( $ecarts ) {
			$reponse['ecarts'] = $ecarts;
			$this->journal( $id, $operation_id, $action, 'error', $b, $changes, 'Relecture divergente', $snapshot_id, null );
			return $this->erreur(
				'ekoseo_menu_divergent',
				sprintf(
					"Le menu relu ne correspond pas à ce qui a été demandé (%d écart(s)). L'instantané %d permet de revenir en arrière.",
					count( $ecarts ),
					(int) $snapshot_id
				),
				422,
				[ 'ecarts' => $ecarts, 'snapshot_id' => $snapshot_id ]
			);
		}

		$this->journal( $id, $operation_id, $action, 'ok', $b, $changes, null, $snapshot_id, $reponse );
		return rest_ensure_response( $reponse );
	}

	// -------------------------------------------------------------- moteur

	/**
	 * Deux passes, et c'est nécessaire : une entrée neuve n'a pas d'identifiant
	 * tant qu'elle n'est pas écrite, donc ses enfants ne peuvent pas la désigner
	 * à la première passe. La seconde ne retouche que les entrées dont le parent
	 * réel diffère de celui posé à la première.
	 *
	 * wp_update_nav_menu_item ne fusionne pas avec l'existant : tout champ non
	 * transmis est remis à sa valeur par défaut. Les arguments sont donc toujours
	 * complets, y compris menu-item-status — omis, il repasserait l'entrée en
	 * brouillon, ce qui la fait disparaître du menu affiché.
	 */
	private function appliquer( $menu_id, array $voulues, array $existants ) {
		$changes = [];
		$map     = [];   // référence fournie (clé ou ancien id) => id réel
		$args    = [];
		$parents_poses = [];

		EkoSEO_Elementor_IO::sans_kses(
			function () use ( $menu_id, $voulues, $existants, &$changes, &$map, &$args, &$parents_poses ) {

				$vus    = [];
				$echecs = [];
				foreach ( $voulues as $rang => $e ) {
					$id_fourni = (int) $e['id'];
					$existe    = $id_fourni && isset( $existants[ $id_fourni ] );

					$parent = 0;
					$p      = (string) $e['parent'];
					if ( ctype_digit( $p ) && (int) $p > 0 && isset( $existants[ (int) $p ] ) ) {
						$parent = (int) $p;
					}

					$position = $e['ordre'] > 0 ? (int) $e['ordre'] : ( $rang + 1 );
					$a        = $this->args_entree( $e, $parent, $position );

					$reel = wp_update_nav_menu_item( $menu_id, $existe ? $id_fourni : 0, $a );
					if ( is_wp_error( $reel ) || ! $reel ) {
						$changes[] = 'echec:' . ( $existe ? $id_fourni : 'nouvelle' );
						// Une entrée qui EXISTE et dont la mise à jour a échoué doit être
						// protégée de la purge qui suit : sans cette ligne, elle n'entre
						// pas dans $vus, se retrouve dans $restants, et un simple échec
						// de mise à jour la supprime définitivement du menu. L'appelant
						// demandait une modification, pas une disparition.
						if ( $existe && isset( $existants[ $id_fourni ] ) ) {
							$vus[ $id_fourni ] = $existants[ $id_fourni ];
							$echecs[]          = (int) $id_fourni;
						}
						continue;
					}
					$reel = (int) $reel;

					if ( '' !== $e['cle'] ) {
						$map[ $e['cle'] ] = $reel;
					}
					if ( $id_fourni ) {
						$map[ (string) $id_fourni ] = $reel;
					}
					$args[ $reel ]          = $a;
					$parents_poses[ $reel ] = $parent;
					$vus[ $reel ]           = $e;

					$changes[] = ( $existe ? 'maj:' : 'creation:' ) . $reel;
				}

				// Passe 2 : les parents que la passe 1 ne pouvait pas connaître.
				foreach ( $vus as $reel => $e ) {
					$p     = (string) $e['parent'];
					$cible = ( '' !== $p && '0' !== $p && isset( $map[ $p ] ) ) ? (int) $map[ $p ] : 0;
					if ( $cible === $parents_poses[ $reel ] ) {
						continue;
					}
					$a                        = $args[ $reel ];
					$a['menu-item-parent-id'] = $cible;
					wp_update_nav_menu_item( $menu_id, $reel, $a );
					$args[ $reel ]            = $a;
					$parents_poses[ $reel ]   = $cible;
					$changes[]                = 'parent:' . $reel;
				}

				// Suppressions : les plus profondes d'abord, sinon un enfant se
				// retrouve orphelin le temps que son parent parte et réapparaît
				// à la racine du menu.
				// Rien n'est supprimé tant qu'une entrée a échoué : un menu à moitié
				// appliqué est plus difficile à rattraper qu'un menu inchangé.
				if ( ! empty( $echecs ) ) {
					$changes[] = 'purge_annulee:' . count( $echecs );
					return true;
				}

				$restants = $existants;
				foreach ( $vus as $reel => $e ) {
					unset( $restants[ $reel ] );
				}
				uasort(
					$restants,
					function ( $x, $y ) {
						return (int) $y['profondeur'] - (int) $x['profondeur'];
					}
				);
				foreach ( $restants as $id_mort => $e ) {
					wp_delete_post( (int) $id_mort, true );
					$changes[] = 'suppression:' . (int) $id_mort;
				}

				return true;
			}
		);

		return [ array_values( array_unique( $changes ) ), $this->ecarts( $menu_id, $args, $parents_poses ) ];
	}

	/**
	 * Relit le menu écrit et le compare aux arguments transmis.
	 *
	 * L'URL n'est comparée que pour les entrées de type `custom` : pour une entrée
	 * liée à un contenu, WordPress ne stocke pas d'URL, il la recalcule au rendu.
	 */
	private function ecarts( $menu_id, array $args, array $parents_poses ) {
		$ecarts = [];
		$items  = wp_get_nav_menu_items( $menu_id, [ 'post_status' => 'publish' ] );
		foreach ( (array) $items as $it ) {
			$id = (int) $it->ID;
			if ( ! isset( $args[ $id ] ) ) {
				continue;
			}
			$a = $args[ $id ];

			$voulu_titre = trim( wp_unslash( (string) $a['menu-item-title'] ) );
			$relu_titre  = trim( (string) $it->post_title );
			if ( $voulu_titre !== $relu_titre ) {
				$ecarts[] = [ 'id' => $id, 'champ' => 'titre', 'voulu' => $voulu_titre, 'relu' => $relu_titre ];
			}

			if ( 'custom' === $a['menu-item-type'] ) {
				$voulu_url = trim( wp_unslash( (string) $a['menu-item-url'] ) );
				$relu_url  = trim( (string) $it->url );
				if ( $voulu_url !== $relu_url ) {
					$ecarts[] = [ 'id' => $id, 'champ' => 'url', 'voulu' => $voulu_url, 'relu' => $relu_url ];
				}
			}

			if ( (int) $parents_poses[ $id ] !== (int) $it->menu_item_parent ) {
				$ecarts[] = [
					'id'    => $id,
					'champ' => 'parent',
					'voulu' => (int) $parents_poses[ $id ],
					'relu'  => (int) $it->menu_item_parent,
				];
			}
		}
		return $ecarts;
	}

	private function args_entree( array $e, $parent_id, $position ) {
		$type = '' !== $e['objet']['type'] ? $e['objet']['type'] : 'custom';
		return [
			'menu-item-object-id'   => 'custom' === $type ? 0 : (int) $e['objet']['objet_id'],
			'menu-item-object'      => 'custom' === $type ? '' : (string) $e['objet']['objet'],
			'menu-item-parent-id'   => (int) $parent_id,
			'menu-item-position'    => (int) $position,
			'menu-item-type'        => $type,
			'menu-item-title'       => wp_slash( (string) $e['titre_brut'] ),
			'menu-item-url'         => 'custom' === $type ? wp_slash( (string) $e['url'] ) : '',
			'menu-item-description' => wp_slash( (string) $e['description'] ),
			'menu-item-attr-title'  => wp_slash( (string) $e['attr_title'] ),
			'menu-item-target'      => (string) $e['cible'],
			'menu-item-classes'     => implode( ' ', array_map( 'sanitize_html_class', (array) $e['classes'] ) ),
			'menu-item-xfn'         => (string) $e['xfn'],
			'menu-item-status'      => 'publish',
		];
	}

	// -------------------------------------------------------------- outils

	/** Retourne une entrée normalisée, ou un message d'erreur en chaîne. */
	private function normaliser_entree( array $e, $rang ) {
		$type = isset( $e['objet']['type'] ) ? sanitize_key( $e['objet']['type'] ) : 'custom';
		if ( ! in_array( $type, [ 'custom', 'post_type', 'taxonomy', 'post_type_archive' ], true ) ) {
			return sprintf( 'type « %s » inconnu.', $type );
		}

		$titre = isset( $e['titre'] ) ? (string) $e['titre'] : '';
		// `titre_brut` vide et transmis n'est pas une erreur : c'est l'entrée qui
		// hérite du titre du contenu lié. Le distinguer d'une absence de clé est
		// donc nécessaire, sinon une réécriture fige le titre hérité.
		$titre_brut = array_key_exists( 'titre_brut', $e ) ? (string) $e['titre_brut'] : $titre;

		$url = isset( $e['url'] ) ? trim( (string) $e['url'] ) : '';
		if ( 'custom' === $type && '' === $url ) {
			return 'une entrée personnalisée sans url ne mène nulle part.';
		}
		if ( 'custom' !== $type && empty( $e['objet']['objet_id'] ) ) {
			return sprintf( 'type « %s » sans objet_id.', $type );
		}
		if ( 'custom' === $type && '' === trim( $titre_brut ) ) {
			return 'une entrée personnalisée sans titre est invisible dans le menu.';
		}

		$cle = isset( $e['cle'] ) ? trim( (string) $e['cle'] ) : '';
		// La table de correspondance parent → identifiant réel est indexée par
		// chaîne : une clé numérique se confondrait avec l'identifiant d'une
		// entrée existante et rattacherait l'enfant au mauvais parent.
		if ( '' !== $cle && ctype_digit( $cle ) ) {
			return sprintf( 'clé « %s » numérique : réservée aux identifiants WordPress.', $cle );
		}

		$parent = '';
		if ( isset( $e['parent'] ) && ! is_array( $e['parent'] ) ) {
			$parent = trim( (string) $e['parent'] );
		}

		return [
			'id'          => isset( $e['id'] ) ? (int) $e['id'] : 0,
			'cle'         => $cle,
			'titre'       => $titre,
			'titre_brut'  => $titre_brut,
			'url'         => $url,
			'objet'       => [
				'type'     => $type,
				'objet'    => isset( $e['objet']['objet'] ) ? (string) $e['objet']['objet'] : '',
				'objet_id' => isset( $e['objet']['objet_id'] ) ? (int) $e['objet']['objet_id'] : 0,
			],
			'parent'      => $parent,
			'ordre'       => isset( $e['ordre'] ) ? (int) $e['ordre'] : ( (int) $rang + 1 ),
			'cible'       => isset( $e['cible'] ) && '_blank' === $e['cible'] ? '_blank' : '',
			'classes'     => isset( $e['classes'] ) ? (array) $e['classes'] : [],
			'xfn'         => isset( $e['xfn'] ) ? (string) $e['xfn'] : '',
			'description' => isset( $e['description'] ) ? (string) $e['description'] : '',
			'attr_title'  => isset( $e['attr_title'] ) ? (string) $e['attr_title'] : '',
		];
	}

	private function menu_etat( $menu ) {
		$entrees = $this->entrees( (int) $menu->term_id );
		return [
			'menu'    => [
				'id'          => (int) $menu->term_id,
				'nom'         => $menu->name,
				'slug'        => $menu->slug,
				'description' => $menu->description,
			],
			'entrees' => $entrees,
			'hash'    => $this->empreinte( $entrees ),
		];
	}

	private function entrees( $menu_id ) {
		$items = wp_get_nav_menu_items( $menu_id, [ 'post_status' => 'publish' ] );
		if ( ! $items ) {
			return [];
		}

		$parents = [];
		foreach ( $items as $it ) {
			$parents[ (int) $it->ID ] = (int) $it->menu_item_parent;
		}

		$out = [];
		foreach ( $items as $it ) {
			$id = (int) $it->ID;
			$out[] = [
				'id'          => $id,
				'cle'         => '',
				'titre'       => (string) $it->title,
				'titre_brut'  => (string) $it->post_title,
				'url'         => (string) $it->url,
				'objet'       => [
					'type'     => (string) $it->type,
					'objet'    => (string) $it->object,
					'objet_id' => (int) $it->object_id,
					'libelle'  => (string) $it->type_label,
				],
				'parent'      => (string) (int) $it->menu_item_parent,
				'ordre'       => (int) $it->menu_order,
				'profondeur'  => $this->profondeur( $id, $parents ),
				'cible'       => (string) $it->target,
				'classes'     => array_values( array_filter( (array) $it->classes ) ),
				'xfn'         => (string) $it->xfn,
				'description' => (string) $it->description,
				'attr_title'  => (string) $it->attr_title,
			];
		}
		return $out;
	}

	private function profondeur( $id, array $parents ) {
		$d     = 0;
		$cur   = $id;
		$garde = 0;
		while ( ! empty( $parents[ $cur ] ) && $garde++ < 12 ) {
			$cur = (int) $parents[ $cur ];
			$d++;
		}
		return $d;
	}

	/**
	 * Empreinte stable du menu : entrées triées par identifiant, concaténation de
	 * id|titre|url|parent|ordre, puis sha256.
	 *
	 * L'ordre de wp_get_nav_menu_items dépend de menu_order, qui est justement ce
	 * qu'on modifie — trier par identifiant est le seul tri qui ne bouge pas avec
	 * le contenu du menu.
	 *
	 * Le titre affiché et l'URL résolue entrent dans l'empreinte, pas les valeurs
	 * stockées : renommer une page ou changer son slug change le lien réellement
	 * émis sur toutes les pages du site. C'est un changement, et le verrou doit
	 * le voir.
	 */
	private function empreinte( array $entrees ) {
		usort(
			$entrees,
			function ( $a, $b ) {
				return (int) $a['id'] - (int) $b['id'];
			}
		);
		$parts = [];
		foreach ( $entrees as $e ) {
			$parts[] = implode(
				'|',
				[
					(int) $e['id'],
					EkoSEO_Hash::normaliser( $e['titre'] ),
					EkoSEO_Hash::normaliser( $e['url'] ),
					(int) $e['parent'],
					(int) $e['ordre'],
				]
			);
		}
		return 'sha256:' . hash( 'sha256', implode( "\n", $parts ) );
	}

	private function diff( array $avant, array $voulues, array $existants ) {
		$d = [ 'ajoutees' => [], 'retirees' => [], 'deplacees' => [], 'modifiees' => [] ];

		$gardes = [];
		foreach ( $voulues as $rang => $e ) {
			$id = (int) $e['id'];
			if ( ! $id || ! isset( $existants[ $id ] ) ) {
				$d['ajoutees'][] = [
					'titre'  => $e['titre_brut'],
					'url'    => $e['url'],
					'parent' => $e['parent'],
					'ordre'  => $e['ordre'] > 0 ? $e['ordre'] : ( $rang + 1 ),
				];
				continue;
			}
			$gardes[ $id ] = true;
			$a             = $existants[ $id ];

			$parent_voulu = ctype_digit( (string) $e['parent'] ) ? (int) $e['parent'] : 0;
			$ordre_voulu  = $e['ordre'] > 0 ? (int) $e['ordre'] : ( $rang + 1 );
			if ( (int) $a['parent'] !== $parent_voulu || (int) $a['ordre'] !== $ordre_voulu ) {
				$d['deplacees'][] = [
					'id'    => $id,
					'titre' => $a['titre'],
					'avant' => [ 'parent' => (int) $a['parent'], 'ordre' => (int) $a['ordre'] ],
					'apres' => [ 'parent' => $parent_voulu, 'ordre' => $ordre_voulu ],
				];
			}
			if ( (string) $a['titre_brut'] !== (string) $e['titre_brut'] || (string) $a['url'] !== (string) $e['url'] ) {
				$d['modifiees'][] = [
					'id'    => $id,
					'avant' => [ 'titre' => $a['titre_brut'], 'url' => $a['url'] ],
					'apres' => [ 'titre' => $e['titre_brut'], 'url' => $e['url'] ],
				];
			}
		}

		foreach ( $avant as $a ) {
			if ( empty( $gardes[ (int) $a['id'] ] ) ) {
				$d['retirees'][] = [
					'id'         => (int) $a['id'],
					'titre'      => $a['titre'],
					'url'        => $a['url'],
					'profondeur' => (int) $a['profondeur'],
				];
			}
		}

		$pages = $this->pages_du_site();
		$d['bilan'] = [
			'entrees_avant'         => count( $avant ),
			'entrees_apres'         => count( $voulues ),
			'liens_injectes_avant'  => count( $avant ) * $pages,
			'liens_injectes_apres'  => count( $voulues ) * $pages,
		];
		return $d;
	}

	/**
	 * Le coût d'un menu, c'est son nombre d'entrées multiplié par le nombre de
	 * pages du site : chaque page rend le menu en entier. C'est ce produit, et
	 * pas le nombre d'entrées, qui rend la décision de couper possible.
	 *
	 * `injecte` à faux ne prouve pas que le menu n'est pas rendu : un modèle
	 * Elementor ou un widget peut appeler un menu qui n'occupe aucun emplacement
	 * déclaré par le thème. C'est une indication, pas un verdict.
	 */
	private function cout( $entrees, $pages, $injecte ) {
		return [
			'entrees'        => (int) $entrees,
			'pages_du_site'  => (int) $pages,
			'liens_injectes' => (int) $entrees * (int) $pages,
			'injecte'        => $injecte,
		];
	}

	/** Seules les pages publiées rendent le menu ; les brouillons ne comptent pas. */
	private function pages_du_site() {
		static $n = null;
		if ( null !== $n ) {
			return $n;
		}
		$n = 0;
		foreach ( get_post_types( [ 'public' => true ], 'names' ) as $pt ) {
			if ( 'attachment' === $pt ) {
				continue;
			}
			$c = wp_count_posts( $pt );
			$n += isset( $c->publish ) ? (int) $c->publish : 0;
		}
		return $n;
	}

	private function journal( $menu_id, $op, $action, $result, $b, $changes, $message, $snapshot_id, $reponse ) {
		EkoSEO_Audit::journaliser(
			[
				'post_id'            => 0,
				'operation_id'       => $op,
				'action'             => $action,
				'reason'             => isset( $b['reason'] ) ? (string) $b['reason'] : null,
				'recommendation_ids' => isset( $b['recommendation_ids'] ) ? (array) $b['recommendation_ids'] : [],
				'changed_fields'     => $changes,
				'result'             => $result,
				'message'            => null === $message ? 'menu ' . (int) $menu_id : $message . ' — menu ' . (int) $menu_id,
				'snapshot_id'        => $snapshot_id,
				'response'           => $reponse,
			]
		);
	}
}
