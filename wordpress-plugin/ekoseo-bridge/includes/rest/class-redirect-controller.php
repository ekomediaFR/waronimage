<?php
/**
 * GET /redirects · POST /redirect · DELETE /redirect
 *
 * Les deux routes d'écriture suivent la même séquence que /page :
 *   permissions → operation_id → idempotence → validation → dry-run →
 *   snapshot → écriture → journal → réponse.
 *
 * L'instantané porte le registre ENTIER et non la seule entrée touchée :
 * `poser()` peut réécrire d'autres lignes en raccourcissant une chaîne, et un
 * retour en arrière partiel laisserait le registre dans un état que personne
 * n'a jamais décidé.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Redirect_Controller extends EkoSEO_Controller_Base {

	/** Les redirections ne sont attachées à aucune page : la colonne `post_id`
	 *  du journal et des instantanés vaut 0 pour elles. */
	const HORS_PAGE = 0;

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/redirects',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'permissions' ],
			]
		);
		register_rest_route(
			$this->namespace,
			'/redirect',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'permissions' ],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'permissions' ],
				],
			]
		);
	}

	public function get_items( $req ) {
		$liste = EkoSEO_Redirections::lister();
		$servies = 0;
		foreach ( $liste as $e ) {
			$servies += (int) $e['hits'];
		}
		return rest_ensure_response(
			[
				'total'        => count( $liste ),
				'hits_cumules' => $servies,
				'redirections' => $liste,
			]
		);
	}

	public function create_item( $req ) {
		$b = $req->get_json_params();
		if ( ! is_array( $b ) ) {
			return $this->erreur( 'ekoseo_corps_invalide', 'Corps JSON attendu.', 400 );
		}

		$operation_id = isset( $b['operation_id'] ) ? (string) $b['operation_id'] : '';
		if ( '' === $operation_id ) {
			return $this->erreur( 'ekoseo_operation_id_manquant', 'operation_id (UUID v4) obligatoire.', 400 );
		}
		$rejoue = $this->rejouer( $operation_id );
		if ( $rejoue ) {
			return $rejoue;
		}

		$depuis = isset( $b['depuis'] ) ? (string) $b['depuis'] : '';
		$vers   = isset( $b['vers'] ) ? (string) $b['vers'] : '';
		$code   = isset( $b['code'] ) ? (int) $b['code'] : 301;
		if ( '' === trim( $depuis ) || '' === trim( $vers ) ) {
			return $this->erreur( 'ekoseo_redirect_champs', 'depuis et vers sont obligatoires.', 400 );
		}

		// ---- dry-run : mêmes contrôles, aucune écriture, aucune extension prévenue
		if ( ! empty( $b['dry_run'] ) ) {
			$essai = EkoSEO_Redirections::poser( $depuis, $vers, $code, false );
			if ( is_wp_error( $essai ) ) {
				return $this->refus( $essai, $operation_id, 'create', $b );
			}
			$this->journal( $operation_id, 'create', 'skipped', $b, [], 'dry_run', null, null );
			return rest_ensure_response(
				array_merge( [ 'operation_id' => $operation_id ], $essai )
			);
		}

		// L'entrée existe peut-être déjà et pointe ailleurs : le contrôle est
		// refait dans l'écriture réelle, celui-ci évite seulement d'écrire un
		// instantané pour une opération qui va être refusée.
		$essai = EkoSEO_Redirections::poser( $depuis, $vers, $code, false );
		if ( is_wp_error( $essai ) ) {
			return $this->refus( $essai, $operation_id, 'create', $b );
		}

		$snapshot_id = $this->instantane( $operation_id, 'redirect_poser' );
		if ( ! $snapshot_id ) {
			return $this->erreur( 'ekoseo_snapshot', "Le snapshot n'a pas pu être enregistré : écriture annulée.", 500 );
		}

		$pose = EkoSEO_Redirections::poser( $depuis, $vers, $code );
		if ( is_wp_error( $pose ) ) {
			return $this->refus( $pose, $operation_id, 'create', $b, $snapshot_id );
		}

		$champs  = [ 'redirect:' . $pose['depuis'] ];
		$reponse = array_merge(
			[ 'operation_id' => $operation_id, 'snapshot_id' => $snapshot_id ],
			$pose,
			[ 'total' => count( EkoSEO_Redirections::lister() ) ]
		);
		$this->journal( $operation_id, 'create', 'ok', $b, $champs, null, $snapshot_id, $reponse );

		$rep = rest_ensure_response( $reponse );
		$rep->set_status( 201 );
		return $rep;
	}

	public function delete_item( $req ) {
		$b = $req->get_json_params();
		if ( ! is_array( $b ) ) {
			$b = [];
		}
		// Un DELETE traverse parfois des intermédiaires qui jettent le corps :
		// les mêmes champs sont acceptés en paramètres de requête.
		foreach ( [ 'operation_id', 'depuis', 'dry_run' ] as $c ) {
			if ( ! isset( $b[ $c ] ) && null !== $req->get_param( $c ) ) {
				$b[ $c ] = $req->get_param( $c );
			}
		}

		$operation_id = isset( $b['operation_id'] ) ? (string) $b['operation_id'] : '';
		if ( '' === $operation_id ) {
			return $this->erreur( 'ekoseo_operation_id_manquant', 'operation_id (UUID v4) obligatoire.', 400 );
		}
		$rejoue = $this->rejouer( $operation_id );
		if ( $rejoue ) {
			return $rejoue;
		}

		$depuis = isset( $b['depuis'] ) ? (string) $b['depuis'] : '';
		if ( '' === trim( $depuis ) ) {
			return $this->erreur( 'ekoseo_redirect_champs', 'depuis est obligatoire.', 400 );
		}

		if ( ! EkoSEO_Redirections::resoudre( $depuis ) ) {
			return $this->erreur(
				'ekoseo_redirect_introuvable',
				sprintf( 'Aucune redirection enregistrée pour %s.', EkoSEO_Redirections::normaliser( $depuis ) ),
				404
			);
		}

		if ( ! empty( $b['dry_run'] ) ) {
			$essai = EkoSEO_Redirections::retirer( $depuis, false );
			$this->journal( $operation_id, 'update', 'skipped', $b, [], 'dry_run', null, null );
			return rest_ensure_response( array_merge( [ 'operation_id' => $operation_id ], (array) $essai ) );
		}

		// Le retrait est journalisé en `update` et non en `delete` :
		// EkoSEO_Audit::deja_traitee ne reconnaît que create/update/rollback/import,
		// et une action hors de cette liste perdrait son idempotence.
		$snapshot_id = $this->instantane( $operation_id, 'redirect_retirer' );
		if ( ! $snapshot_id ) {
			return $this->erreur( 'ekoseo_snapshot', "Le snapshot n'a pas pu être enregistré : suppression annulée.", 500 );
		}

		$retiree = EkoSEO_Redirections::retirer( $depuis );
		$reponse = array_merge(
			[ 'operation_id' => $operation_id, 'snapshot_id' => $snapshot_id, 'supprimee' => true ],
			(array) $retiree,
			[ 'total' => count( EkoSEO_Redirections::lister() ) ]
		);
		$this->journal(
			$operation_id,
			'update',
			'ok',
			$b,
			[ 'redirect:' . $retiree['depuis'] ],
			'Retrait de la redirection ' . $retiree['depuis'],
			$snapshot_id,
			$reponse
		);
		return rest_ensure_response( $reponse );
	}

	// ---------------------------------------------------------------- outils

	/** Une opération déjà appliquée renvoie sa réponse d'origine. */
	private function rejouer( $operation_id ) {
		$deja = EkoSEO_Audit::deja_traitee( $operation_id );
		if ( ! $deja ) {
			return null;
		}
		$rep = rest_ensure_response(
			array_merge(
				is_array( $deja['response'] ) ? $deja['response'] : [],
				[ 'replayed' => true, 'operation_id' => $operation_id ]
			)
		);
		$rep->set_status( 200 );
		return $rep;
	}

	private function instantane( $operation_id, $action ) {
		return EkoSEO_Audit::snapshot(
			self::HORS_PAGE,
			$operation_id,
			[ 'action' => $action, 'redirections' => EkoSEO_Redirections::lire() ],
			EkoSEO_Redirections::empreinte()
		);
	}

	/** Un refus du registre (boucle, code interdit, racine) est une erreur de
	 *  l'appelant, pas une panne : 422, et la trace reste au journal. */
	private function refus( WP_Error $err, $operation_id, $action, $b, $snapshot_id = null ) {
		$this->journal( $operation_id, $action, 'rejected', $b, [], $err->get_error_message(), $snapshot_id, null );
		return $this->erreur( $err->get_error_code(), $err->get_error_message(), 422 );
	}

	private function journal( $op, $action, $result, $b, $changes, $message, $snapshot_id, $reponse ) {
		EkoSEO_Audit::journaliser(
			[
				'post_id'            => self::HORS_PAGE,
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
