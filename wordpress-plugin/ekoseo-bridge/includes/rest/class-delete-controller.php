<?php
/**
 * DELETE /page/{id} · POST /page/{id}/restaurer
 *
 * Le plan d'action sait détecter la cannibalisation — deux pages se disputent la
 * même requête, aucune n'accumule de signaux — et la résolution est toujours la
 * même : garder la plus forte, supprimer l'autre, rediriger. Le système savait
 * le DIRE sans savoir le FAIRE. C'est ce que cette route ajoute.
 *
 * L'ordre d'exécution reprend celui du PUT, avec une contrainte de plus : la
 * redirection est posée AVANT la suppression. Entre les deux appels la page
 * répond encore ; dans l'ordre inverse elle répondrait 404 le temps que la
 * redirection arrive, et un robot passé pendant cette fenêtre repartirait avec
 * une 404 qu'il faudra des semaines à effacer.
 *
 * La corbeille est le défaut : elle se vide quand on l'a décidé, une suppression
 * définitive ne se rattrape pas.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Delete_Controller extends EkoSEO_Controller_Base {

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/page/(?P<id>\d+)',
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'supprimer' ],
				'permission_callback' => [ $this, 'permissions' ],
			]
		);
		register_rest_route(
			$this->namespace,
			'/page/(?P<id>\d+)/restaurer',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'restaurer' ],
				'permission_callback' => [ $this, 'permissions' ],
			]
		);
	}

	// ------------------------------------------------------------ suppression

	public function supprimer( $req ) {
		$id = (int) $req['id'];
		$b  = $this->parametres( $req );

		$operation_id = isset( $b['operation_id'] ) ? (string) $b['operation_id'] : '';
		if ( '' === $operation_id ) {
			return $this->erreur( 'ekoseo_operation_id_manquant', 'operation_id (UUID v4) obligatoire.', 400 );
		}

		// ---- idempotence : une suppression rejouée ne supprime pas deux fois
		$deja = EkoSEO_Audit::deja_traitee( $operation_id );
		if ( $deja ) {
			return rest_ensure_response(
				array_merge(
					is_array( $deja['response'] ) ? $deja['response'] : [],
					[ 'replayed' => true, 'operation_id' => $operation_id ]
				)
			);
		}

		$etat = $this->etat( $id );
		if ( ! $etat ) {
			return $this->erreur( 'ekoseo_introuvable', 'Page inexistante.', 404 );
		}
		$hash_courant = $this->hash( $etat );

		// ---- verrou : on ne supprime jamais une page qui a bougé depuis la lecture
		$attendu = isset( $b['expected_hash'] ) ? (string) $b['expected_hash'] : '';
		if ( '' === $attendu ) {
			return $this->erreur( 'ekoseo_hash_manquant', 'expected_hash obligatoire.', 400 );
		}
		if ( ! hash_equals( $hash_courant, $attendu ) ) {
			$this->journal( $id, $operation_id, 'delete', 'conflict', $b, [], 'Hash divergent', null, null );
			return $this->erreur(
				'ekoseo_conflit',
				"La page a été modifiée depuis sa lecture. Relire /page/{$id} et rejouer avec le hash à jour.",
				409,
				[ 'current_hash' => $hash_courant, 'expected_hash' => $attendu ]
			);
		}

		$definitif = ! empty( $b['definitif'] ) && rest_sanitize_boolean( $b['definitif'] );
		$dry       = ! empty( $b['dry_run'] ) && rest_sanitize_boolean( $b['dry_run'] );
		$vers      = isset( $b['rediriger_vers'] ) ? trim( (string) $b['rediriger_vers'] ) : '';

		// Supprimer définitivement une page indexée sans rediriger fabrique une 404
		// et jette ses liens entrants. Ce refus n'est pas contournable : la
		// corbeille, elle, laisse le temps de décider.
		if ( $definitif && '' === $vers ) {
			return $this->erreur(
				'ekoseo_redirection_obligatoire',
				'Une suppression définitive exige rediriger_vers : sans redirection, les liens '
				. "entrants et l'historique Search Console de " . $etat['permalink'] . ' finissent en 404. '
				. 'Sans destination, utiliser la corbeille (definitif absent ou faux).',
				422,
				[ 'permalink' => $etat['permalink'] ]
			);
		}

		if ( ! $definitif && 'trash' === $etat['status'] ) {
			return $this->erreur(
				'ekoseo_deja_corbeille',
				'Page déjà à la corbeille. Pour la supprimer sans retour possible : definitif=true '
				. 'avec rediriger_vers ; pour la remettre en ligne : POST /page/' . $id . '/restaurer.',
				409
			);
		}

		$refus = $this->refus_structurel( $id, $definitif );
		if ( $refus ) {
			return $refus;
		}

		if ( '' !== $vers ) {
			$cible = $this->normaliser_cible( $vers, $etat['permalink'] );
			if ( is_wp_error( $cible ) ) {
				return $cible;
			}
			$vers = $cible;
		}

		$enfants        = $this->enfants( $id );
		$avertissements = $this->avertissements( $etat, $vers, $definitif, $enfants );

		// ---- dry_run : on décrit, on n'écrit rien
		if ( $dry ) {
			$this->journal( $id, $operation_id, 'delete', 'skipped', $b, [], 'dry_run', null, null );
			return rest_ensure_response(
				[
					'dry_run'        => true,
					'operation_id'   => $operation_id,
					'action'         => $definitif ? 'supprimee' : 'corbeille',
					'post_id'        => $id,
					'permalink'      => $etat['permalink'],
					'current_hash'   => $hash_courant,
					'redirection'    => [
						'prevue' => '' !== $vers,
						'vers'   => '' !== $vers ? $vers : null,
						'code'   => 301,
					],
					'enfants'        => count( $enfants ),
					'avertissements' => $avertissements,
				]
			);
		}

		// ---- instantané AVANT toute chose
		$snapshot_id = EkoSEO_Audit::snapshot( $id, $operation_id, $etat, $hash_courant );
		if ( ! $snapshot_id ) {
			return $this->erreur(
				'ekoseo_snapshot',
				"L'instantané n'a pas pu être enregistré : suppression annulée.",
				500
			);
		}

		// ---- l'URL RÉELLEMENT indexée, pas celle que WordPress rend aujourd'hui
		//
		// get_permalink() d'un post en corbeille renvoie une adresse dégradée du
		// type ?p=123 ou /mon-slug__trashed/ : poser la 301 dessus laisserait la
		// vraie URL publique, /mon-slug/, en 404 — alors que la réponse annoncerait
		// une redirection posée. On reconstruit donc l'adresse d'origine à partir
		// du chemin de la page, qui ne dépend pas de son statut.
		$source = $this->url_publique( $id, $etat );

		// ---- redirection d'abord : voir l'en-tête de fichier
		$redirection = $this->poser_redirection( $source, $vers );

		// Une suppression définitive dont la redirection n'a pas pu être posée
		// produirait exactement ce que le refus plus haut cherche à éviter. On
		// s'arrête : l'instantané reste, la page aussi.
		if ( $definitif && '' !== $vers && ! $redirection['posee'] ) {
			$this->journal( $id, $operation_id, 'delete', 'error', $b, [], $redirection['message'], $snapshot_id, null );
			return $this->erreur(
				'ekoseo_redirection_impossible',
				$redirection['message'] . ' La suppression définitive est annulée : la page est intacte.',
				503,
				[ 'snapshot_id' => $snapshot_id, 'redirection' => $redirection ]
			);
		}

		// ---- suppression
		if ( $definitif ) {
			$fait   = wp_delete_post( $id, true );
			$action = 'supprimee';
		} else {
			$fait   = wp_trash_post( $id );
			$action = 'corbeille';
		}
		if ( ! $fait ) {
			// La 301 est déjà posée. Si on s'arrête là, la page existe toujours et
			// répond — mais le hook de redirection l'envoie ailleurs : du contenu
			// vivant devient inatteignable. On retire donc la redirection avant de
			// rendre l'erreur, pour que l'échec laisse le site exactement comme il
			// était.
			$retire = false;
			if ( ! empty( $redirection['posee'] ) && class_exists( 'EkoSEO_Redirections' )
				&& method_exists( 'EkoSEO_Redirections', 'retirer' ) ) {
				$r = EkoSEO_Redirections::retirer( $source );
				$retire = ! is_wp_error( $r );
			}
			$msg = 'WordPress a refusé la suppression de la page ' . $id . '. Rien n\'a été supprimé.'
				. ( ! empty( $redirection['posee'] )
					? ( $retire
						? ' La redirection posée juste avant a été retirée : le site est dans son état initial.'
						: ' ⚠ La redirection posée juste avant n\'a PAS pu être retirée : la page répond encore'
							. ' mais sera redirigée. La retirer à la main depuis DELETE /redirect.' )
					: '' );
			$this->journal( $id, $operation_id, 'delete', 'error', $b, [], $msg, $snapshot_id, null );
			return $this->erreur( 'ekoseo_suppression_refusee', $msg, 500,
				[ 'snapshot_id' => $snapshot_id, 'redirection_retiree' => $retire ] );
		}

		$purges = EkoSEO_Cache::purger( $id );

		$reponse = [
			'operation_id'   => $operation_id,
			'action'         => $action,
			'post_id'        => $id,
			'permalink'      => $etat['permalink'],
			'slug'           => $etat['slug'],
			'title'          => $etat['title'],
			'snapshot_id'    => $snapshot_id,
			'redirection'    => $redirection,
			'caches_purged'  => $purges,
			'enfants'        => count( $enfants ),
			'restaurable'    => ! $definitif,
			'avertissements' => $avertissements,
		];

		$this->journal( $id, $operation_id, 'delete', 'ok', $b, [ $action ], null, $snapshot_id, $reponse );

		return rest_ensure_response( $reponse );
	}

	// ------------------------------------------------------------ restauration

	/**
	 * Sortie de corbeille. Sans elle, la suppression n'est réversible que depuis
	 * wp-admin : l'application saurait défaire ce qu'elle a fait uniquement en
	 * demandant à un humain d'ouvrir un navigateur.
	 */
	public function restaurer( $req ) {
		$id = (int) $req['id'];
		$b  = $this->parametres( $req );

		$operation_id = isset( $b['operation_id'] ) ? (string) $b['operation_id'] : wp_generate_uuid4();

		$deja = EkoSEO_Audit::deja_traitee( $operation_id );
		if ( $deja ) {
			return rest_ensure_response(
				array_merge(
					is_array( $deja['response'] ) ? $deja['response'] : [],
					[ 'replayed' => true, 'operation_id' => $operation_id ]
				)
			);
		}

		$etat = $this->etat( $id );
		if ( ! $etat ) {
			return $this->erreur(
				'ekoseo_introuvable',
				'Page inexistante. Une suppression définitive ne se restaure pas : '
				. "l'instantané garde le contenu, pas l'identifiant ni l'URL.",
				404
			);
		}
		if ( 'trash' !== $etat['status'] ) {
			return $this->erreur(
				'ekoseo_pas_en_corbeille',
				sprintf( 'La page %d est en statut « %s » : il n\'y a rien à restaurer.', $id, $etat['status'] ),
				409,
				[ 'status' => $etat['status'] ]
			);
		}

		// Facultatif ici : restaurer n'écrase rien. Honoré s'il est fourni.
		$attendu = isset( $b['expected_hash'] ) ? (string) $b['expected_hash'] : '';
		$hash_courant = $this->hash( $etat );
		if ( '' !== $attendu && ! hash_equals( $hash_courant, $attendu ) ) {
			return $this->erreur(
				'ekoseo_conflit',
				"La page a été modifiée depuis sa lecture. Relire /page/{$id} et rejouer avec le hash à jour.",
				409,
				[ 'current_hash' => $hash_courant, 'expected_hash' => $attendu ]
			);
		}

		if ( ! empty( $b['dry_run'] ) && rest_sanitize_boolean( $b['dry_run'] ) ) {
			return rest_ensure_response(
				[
					'dry_run'        => true,
					'operation_id'   => $operation_id,
					'action'         => 'restauree',
					'post_id'        => $id,
					'avertissements' => [ $this->avertissement_301() ],
				]
			);
		}

		$snapshot_id = EkoSEO_Audit::snapshot( $id, $operation_id, $etat, $hash_courant );
		if ( ! $snapshot_id ) {
			return $this->erreur(
				'ekoseo_snapshot',
				"L'instantané n'a pas pu être enregistré : restauration annulée.",
				500
			);
		}

		// Depuis WordPress 5.6, wp_untrash_post rend à la page son statut d'avant
		// la corbeille — et non plus « brouillon » systématiquement. Une page
		// publiée redevient donc publiée. On relit pour l'affirmer, pas pour le
		// supposer.
		$fait = wp_untrash_post( $id );
		if ( ! $fait ) {
			$this->journal( $id, $operation_id, 'untrash', 'error', $b, [], 'WordPress a refusé la restauration.', $snapshot_id, null );
			return $this->erreur(
				'ekoseo_restauration_refusee',
				'WordPress a refusé la restauration de la page ' . $id . '.',
				500,
				[ 'snapshot_id' => $snapshot_id ]
			);
		}

		$purges = EkoSEO_Cache::purger( $id );
		$apres  = $this->etat( $id );

		$reponse = [
			'operation_id'   => $operation_id,
			'action'         => 'restauree',
			'post_id'        => $id,
			'status'         => $apres ? $apres['status'] : null,
			'slug'           => $apres ? $apres['slug'] : null,
			'permalink'      => $apres ? $apres['permalink'] : null,
			'new_hash'       => $apres ? $this->hash( $apres ) : null,
			'snapshot_id'    => $snapshot_id,
			'caches_purged'  => $purges,
			'avertissements' => [ $this->avertissement_301() ],
		];

		$this->journal( $id, $operation_id, 'untrash', 'ok', $b, [ 'status' ], null, $snapshot_id, $reponse );

		return rest_ensure_response( $reponse );
	}

	// ------------------------------------------------------------------ outils

	/**
	 * Corps JSON complété par la chaîne de requête.
	 *
	 * Un DELETE avec un corps est légal mais mal servi : plusieurs clients HTTP
	 * et certains proxys le suppriment en chemin. Les mêmes paramètres sont donc
	 * acceptés en query string, le corps JSON restant prioritaire.
	 */
	private function parametres( WP_REST_Request $req ) {
		$json = $req->get_json_params();
		$json = is_array( $json ) ? $json : [];
		return array_merge( $req->get_query_params(), $json );
	}

	/** Pages dont la suppression casserait le site quoi qu'il arrive. */
	private function refus_structurel( $id, $definitif ) {
		$accueil = (int) get_option( 'page_on_front' );
		$blog    = (int) get_option( 'page_for_posts' );

		if ( $accueil && $accueil === $id ) {
			return $this->erreur(
				'ekoseo_page_accueil',
				"Cette page est la page d'accueil du site (page_on_front). La supprimer laisserait "
				. "le site sans racine. Désigner une autre page d'accueil d'abord.",
				422
			);
		}
		if ( $blog && $blog === $id ) {
			return $this->erreur(
				'ekoseo_page_articles',
				'Cette page est la page des articles (page_for_posts). Désigner une autre page avant '
				. 'de la supprimer.',
				422
			);
		}
		// EMPTY_TRASH_DAYS à 0 désactive la corbeille : wp_trash_post appelle alors
		// wp_delete_post et supprime pour de bon. Le mot « corbeille » de la réponse
		// serait un mensonge, et la suppression annoncée réversible ne le serait pas.
		if ( ! $definitif && ! EMPTY_TRASH_DAYS ) {
			return $this->erreur(
				'ekoseo_corbeille_desactivee',
				'La corbeille est désactivée sur ce site (EMPTY_TRASH_DAYS = 0) : WordPress '
				. 'supprimerait la page définitivement sous couvert de la mettre à la corbeille. '
				. 'Passer definitif=true avec rediriger_vers, en sachant que le retour arrière '
				. "se limitera à l'instantané.",
				409
			);
		}

		return null;
	}

	/** Identifiants des enfants directs — leur parent va disparaître. */
	private function enfants( $id ) {
		$q = new WP_Query(
			[
				'post_parent'    => (int) $id,
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
		return $q->posts;
	}

	/**
	 * Vérifie la destination et la ramène à une forme utilisable.
	 *
	 * Refuse la boucle : rediriger une page vers elle-même produit une chaîne
	 * infinie que le serveur coupe en erreur, pas en page.
	 */
	private function normaliser_cible( $vers, $permalink_origine ) {
		$vers = trim( (string) $vers );

		if ( '/' === substr( $vers, 0, 1 ) ) {
			$cible = $vers;
		} else {
			$url = esc_url_raw( $vers );
			if ( ! $url || ! wp_parse_url( $url, PHP_URL_HOST ) ) {
				return $this->erreur(
					'ekoseo_cible_invalide',
					'rediriger_vers attend un chemin commençant par « / » ou une URL complète. '
					. 'Reçu : ' . $vers,
					422
				);
			}
			$cible = $url;
		}

		$depuis_chemin = wp_parse_url( $permalink_origine, PHP_URL_PATH );
		$cible_chemin  = wp_parse_url( $cible, PHP_URL_PATH );
		if ( $depuis_chemin && $cible_chemin && untrailingslashit( $depuis_chemin ) === untrailingslashit( $cible_chemin ) ) {
			return $this->erreur(
				'ekoseo_redirection_boucle',
				'La destination est la page elle-même : la redirection bouclerait sur place. '
				. 'Choisir la page qui garde la requête.',
				422,
				[ 'chemin' => $depuis_chemin ]
			);
		}

		return $cible;
	}

	/**
	 * Confie la 301 à EkoSEO_Redirections quand elle est là.
	 *
	 * La classe est livrée à part : si elle manque, on ne fabrique pas une
	 * redirection au jugé — on supprime quand même (corbeille) et on dit, dans la
	 * réponse, que la redirection reste à poser.
	 */
	private function poser_redirection( $permalink_origine, $vers ) {
		if ( '' === $vers ) {
			return [
				'posee'   => false,
				'vers'    => null,
				'code'    => null,
				'message' => 'Aucune redirection demandée.',
			];
		}

		$depuis = wp_parse_url( $permalink_origine, PHP_URL_PATH );
		if ( ! $depuis ) {
			return [
				'posee'   => false,
				'vers'    => $vers,
				'code'    => 301,
				'message' => "L'URL d'origine n'a pas de chemin exploitable : redirection non posée.",
			];
		}

		if ( ! class_exists( 'EkoSEO_Redirections' ) || ! method_exists( 'EkoSEO_Redirections', 'poser' ) ) {
			return [
				'posee'   => false,
				'vers'    => $vers,
				'code'    => 301,
				'message' => 'EkoSEO_Redirections absente de cette installation : la 301 ' . $depuis
					. ' → ' . $vers . ' reste à poser à la main.',
			];
		}

		// `poser()` rend un tableau en cas de succès, un WP_Error en cas de refus.
		// Un WP_Error converti en booléen vaut VRAI : le tester ainsi faisait passer
		// tout refus pour un succès, et une suppression définitive partait alors sans
		// redirection — précisément ce que le refus « définitif sans redirection »
		// existe pour empêcher.
		$r = EkoSEO_Redirections::poser( $depuis, $vers, 301 );

		if ( is_wp_error( $r ) ) {
			return [
				'posee'   => false,
				'vers'    => $vers,
				'code'    => 301,
				'depuis'  => $depuis,
				'erreur'  => $r->get_error_code(),
				'message' => 'La 301 ' . $depuis . ' → ' . $vers . ' a été refusée : '
					. $r->get_error_message(),
			];
		}

		return [
			'posee'   => true,
			'vers'    => isset( $r['vers'] ) ? $r['vers'] : $vers,
			'code'    => isset( $r['code'] ) ? (int) $r['code'] : 301,
			'depuis'  => $depuis,
			'chaine_resolue' => ! empty( $r['chaine_resolue'] ),
			'remplace'       => ! empty( $r['remplace'] ),
			'message' => 'Redirection 301 posée : ' . $depuis . ' → '
				. ( isset( $r['vers'] ) ? $r['vers'] : $vers ),
		];
	}

	/**
	 * L'adresse publique d'une page, indépendante de son statut.
	 *
	 * Reconstruite depuis le chemin des slugs plutôt que lue par get_permalink() :
	 * cette dernière dégrade l'URL dès que le post quitte l'état publié, et c'est
	 * précisément dans ce cas qu'on en a besoin.
	 */
	private function url_publique( $id, array $etat ) {
		$chemin = isset( $etat['path'] ) && is_array( $etat['path'] ) ? $etat['path'] : [];
		if ( $chemin ) {
			return home_url( '/' . implode( '/', array_filter( $chemin ) ) . '/' );
		}
		$p = get_post( $id );
		if ( $p && $p->post_name ) {
			return home_url( '/' . $p->post_name . '/' );
		}
		return isset( $etat['permalink'] ) ? $etat['permalink'] : '';
	}

	private function avertissements( array $etat, $vers, $definitif, array $enfants ) {
		$a = [];

		if ( '' === $vers ) {
			$a[] = 'Aucune redirection prévue : les liens entrants et l\'historique Search Console de '
				. $etat['permalink'] . ' aboutiront en 404.';
		}
		if ( $definitif ) {
			$a[] = "Suppression définitive : ni la corbeille ni /restaurer ne la rattraperont. "
				. "L'instantané conserve le contenu, pas l'identifiant ni l'URL.";
		}
		if ( $enfants ) {
			$a[] = sprintf(
				'%d page(s) enfant(s) — %s. Une page enfant dont le parent disparaît voit son '
				. 'URL changer ou pointer dans le vide : les traiter avant.',
				count( $enfants ),
				implode( ', ', array_map( 'intval', array_slice( $enfants, 0, 20 ) ) )
			);
		}
		return $a;
	}

	private function avertissement_301() {
		return 'Si une 301 a été posée lors de la suppression, elle masque désormais la page '
			. 'restaurée : la retirer avant de vérifier l\'URL.';
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
