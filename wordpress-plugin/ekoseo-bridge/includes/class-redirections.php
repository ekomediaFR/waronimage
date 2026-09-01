<?php
/**
 * Registre des redirections 301.
 *
 * Une fusion de pages sans redirection perd les liens entrants et l'historique
 * Search Console. Le plugin savait déjà déléguer à une extension tierce ; quand
 * aucune n'est installée, la redirection n'était simplement pas posée — et
 * l'appelant croyait qu'elle l'était.
 *
 * Stockage en option et non en table : quelques centaines d'entrées au plus.
 * Une option en autoload est déjà en mémoire quand la requête atteint
 * `template_redirect` ; une table coûterait une requête SQL sur chaque 404.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Redirections {

	const OPTION = 'ekoseo_redirections';

	/**
	 * Les compteurs vivent à part, et ce n'est pas un détail de rangement.
	 *
	 * Les incrémenter dans le registre obligeait à relire et réécrire TOUT le
	 * registre à chaque 404, depuis une requête anonyme du front. Deux effets,
	 * tous deux constatés à la relecture du 2026-08-23 :
	 *
	 * — une écriture concurrente pouvait être écrasée : un `POST /redirect` qui
	 *   ajoutait une entrée pendant qu'une visite comptait un passage disparaissait
	 *   sans bruit ;
	 * — l'empreinte du registre changeait toute seule au fil du trafic, si bien
	 *   que le verrou `expected_hash` refusait des écritures parfaitement légitimes.
	 *
	 * Séparés, une collision ne coûte qu'un passage non compté.
	 */
	const OPTION_HITS = 'ekoseo_redirections_hits';

	/** Google abandonne une chaîne au-delà de quelques sauts, et chaque saut
	 *  consomme du budget de crawl. `poser()` n'écrit donc que des cibles
	 *  finales ; cette garde ne protège que du registre déjà bouclé — écrit à la
	 *  main, ou importé d'ailleurs. */
	const PROFONDEUR_MAX = 10;

	/** Une page déplacée se signale par une 301 ; 302 et 307 servent le
	 *  provisoire, 308 la 301 qui préserve la méthode HTTP. Les autres codes de
	 *  redirection ne décrivent pas un déplacement de page. */
	const CODES = [ 301, 302, 307, 308 ];

	public static function init() {
		// Priorité 1 : avant `redirect_canonical` (priorité 10), qui sur certaines
		// configurations transforme une 404 en devinette de slug approchant.
		add_action( 'template_redirect', [ __CLASS__, 'intercepter' ], 1 );
	}

	// ------------------------------------------------------------ normalisation

	/**
	 * Réduit une URL au chemin servi. Deux écritures du même endroit
	 * (`https://site.fr/paris/`, `/paris`, `/paris/`) doivent donner la même clé,
	 * sinon le registre contient des doublons qui ne se déclenchent jamais.
	 */
	public static function normaliser( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$chemin = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $chemin ) || '' === $chemin ) {
			return '/';
		}
		$chemin = rawurldecode( $chemin );
		$chemin = preg_replace( '#/+#', '/', $chemin );
		if ( '/' !== substr( $chemin, 0, 1 ) ) {
			$chemin = '/' . $chemin;
		}
		if ( strlen( $chemin ) > 1 ) {
			$chemin = rtrim( $chemin, '/' );
		}
		return '' === $chemin ? '/' : $chemin;
	}

	/**
	 * Une cible peut sortir du site : après un regroupement de domaines, la page
	 * d'arrivée n'est plus chez nous. La réduire à son chemin enverrait le
	 * visiteur sur une adresse locale qui n'existe pas — on garde l'URL absolue.
	 */
	public static function normaliser_cible( $url ) {
		$url  = trim( (string) $url );
		$hote = wp_parse_url( $url, PHP_URL_HOST );
		if ( $hote && strtolower( $hote ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return $url;
		}
		return self::normaliser( $url );
	}

	/** L'adresse complète vers laquelle envoyer le visiteur. */
	public static function url( $cible ) {
		$cible = (string) $cible;
		return preg_match( '#^https?://#i', $cible ) ? $cible : home_url( $cible );
	}

	// ----------------------------------------------------------------- registre

	public static function lire() {
		$r = get_option( self::OPTION, [] );
		return is_array( $r ) ? $r : [];
	}

	private static function ecrire( array $registre ) {
		update_option( self::OPTION, $registre, true );
	}

	/**
	 * Hash du registre — sert de `hash_before` à l'instantané d'écriture.
	 * EkoSEO_Hash ne retient que ses champs canoniques : le registre voyage donc
	 * dans `meta`, dont la normalisation récursive trie les clés. Sans ce
	 * détour, `calculer()` hasherait un état vide et deux registres différents
	 * auraient la même empreinte.
	 */
	public static function empreinte() {
		return EkoSEO_Hash::calculer( [ 'meta' => self::lire() ] );
	}

	public static function lister() {
		$out = [];
		foreach ( self::lire() as $depuis => $e ) {
			$out[] = self::exposer( (string) $depuis, is_array( $e ) ? $e : [] );
		}
		usort(
			$out,
			function ( $a, $b ) {
				if ( $a['hits'] === $b['hits'] ) {
					return strcmp( $a['depuis'], $b['depuis'] );
				}
				return $b['hits'] - $a['hits'];
			}
		);
		return $out;
	}

	/** Forme stable d'une entrée : un registre écrit par une version antérieure
	 *  peut manquer des clés récentes, l'API ne doit pas s'en apercevoir. */
	private static function exposer( $depuis, array $e ) {
		return [
			'depuis'  => $depuis,
			'vers'    => isset( $e['vers'] ) ? (string) $e['vers'] : '',
			'code'    => isset( $e['code'] ) ? (int) $e['code'] : 301,
			'date'    => isset( $e['date'] ) ? $e['date'] : null,
			'hits'    => (int) self::hits( $depuis )['hits'],
			'dernier' => self::hits( $depuis )['dernier'],
			'delegue' => isset( $e['delegue'] ) ? (array) $e['delegue'] : [],
		];
	}

	/**
	 * Suit la chaîne depuis un chemin et renvoie l'entrée avec sa cible finale,
	 * ou null si le chemin n'est source d'aucune redirection.
	 */
	public static function resoudre( $chemin ) {
		$chemin   = self::normaliser( $chemin );
		$registre = self::lire();
		if ( '' === $chemin || ! isset( $registre[ $chemin ] ) || ! is_array( $registre[ $chemin ] ) ) {
			return null;
		}
		$entree = self::exposer( $chemin, $registre[ $chemin ] );
		$vers   = $entree['vers'];
		$vus    = [ $chemin => true, $vers => true ];
		$sauts  = 0;
		while ( isset( $registre[ $vers ]['vers'] ) && $sauts++ < self::PROFONDEUR_MAX ) {
			$suivant = (string) $registre[ $vers ]['vers'];
			if ( isset( $vus[ $suivant ] ) ) {
				break;
			}
			$vus[ $suivant ] = true;
			$vers            = $suivant;
		}
		$entree['vers'] = $vers;
		return $entree;
	}

	// -------------------------------------------------------------- écriture

	/**
	 * Enregistre une redirection.
	 *
	 * `$appliquer` à faux exécute tous les contrôles sans rien écrire ni
	 * prévenir aucune extension : c'est le `dry_run` du contrôleur, et il doit
	 * emprunter exactement le même chemin de validation que l'écriture réelle,
	 * sinon il valide autre chose que ce qui sera fait.
	 *
	 * Retourne l'entrée posée, ou un WP_Error.
	 */
	public static function poser( $depuis, $vers, $code = 301, $appliquer = true ) {
		$saisi  = self::normaliser_cible( $vers );
		$depuis = self::normaliser( $depuis );
		$vers   = $saisi;
		$code   = (int) $code;

		if ( '' === $depuis ) {
			return new WP_Error( 'ekoseo_redirect_source', 'Chemin source illisible.' );
		}
		if ( '/' === $depuis ) {
			return new WP_Error(
				'ekoseo_redirect_source',
				"La racine du site ne se redirige pas depuis ici : ce serait rendre le site inaccessible."
			);
		}
		if ( '' === $vers ) {
			return new WP_Error( 'ekoseo_redirect_cible', 'Chemin cible vide.' );
		}
		if ( ! in_array( $code, self::CODES, true ) ) {
			return new WP_Error(
				'ekoseo_redirect_code',
				'Code non autorisé. Attendu : ' . implode( ', ', self::CODES ) . '.'
			);
		}
		if ( $depuis === $vers ) {
			return new WP_Error( 'ekoseo_redirect_boucle', 'Source et cible identiques.' );
		}

		$registre = self::lire();

		// La cible est peut-être elle-même déplacée : on va jusqu'au bout plutôt
		// que d'empiler un saut de plus.
		$final = $vers;
		$vus   = [ $vers => true ];
		$sauts = 0;
		while ( isset( $registre[ $final ]['vers'] ) ) {
			if ( $sauts++ >= self::PROFONDEUR_MAX ) {
				return new WP_Error(
					'ekoseo_redirect_profondeur',
					sprintf( 'Chaîne de plus de %d sauts depuis %s : registre à vérifier avant d\'ajouter.', self::PROFONDEUR_MAX, $vers )
				);
			}
			$suivant = (string) $registre[ $final ]['vers'];
			if ( $suivant === $depuis || isset( $vus[ $suivant ] ) ) {
				return new WP_Error(
					'ekoseo_redirect_boucle',
					sprintf( '%s ramène à %s : la redirection tournerait en rond.', $vers, $suivant )
				);
			}
			$vus[ $suivant ] = true;
			$final           = $suivant;
		}

		$existant = isset( $registre[ $depuis ] ) && is_array( $registre[ $depuis ] )
			? self::exposer( $depuis, $registre[ $depuis ] )
			: null;

		$entree = [
			'vers'    => $final,
			'code'    => $code,
			// La date de première pose survit à une modification de la cible :
			// elle dit depuis quand cette URL n'existe plus.
			'date'    => $existant ? $existant['date'] : current_time( 'mysql', true ),
			'hits'    => $existant ? $existant['hits'] : 0,
			'dernier' => $existant ? $existant['dernier'] : null,
			'delegue' => [],
		];

		if ( ! $appliquer ) {
			return array_merge(
				[ 'depuis' => $depuis, 'dry_run' => true, 'chaine_resolue' => $final !== $saisi, 'remplace' => (bool) $existant ],
				$entree
			);
		}

		$entree['delegue']   = self::deleguer( $depuis, $final, $code );
		$registre[ $depuis ] = $entree;

		// Les entrées qui pointaient sur $depuis pointent maintenant sur la cible
		// finale : sans ce rattrapage, poser B→C après A→B recrée exactement la
		// chaîne que la résolution ci-dessus vient d'éviter.
		foreach ( $registre as $src => $e ) {
			if ( $src !== $depuis && isset( $e['vers'] ) && (string) $e['vers'] === $depuis ) {
				$registre[ $src ]['vers'] = $final;
			}
		}

		self::ecrire( $registre );

		return array_merge(
			self::exposer( $depuis, $entree ),
			[ 'chaine_resolue' => $final !== $saisi, 'remplace' => (bool) $existant ]
		);
	}

	/**
	 * Retire une redirection du registre.
	 *
	 * Rien n'est retiré côté extension tierce : ni Redirection ni Yoast Premium
	 * n'exposent de suppression stable par URL. L'entrée renvoyée porte
	 * `delegue` — l'appelant sait alors qu'une règle jumelle survit ailleurs et
	 * qu'elle se retire à la main.
	 *
	 * Retourne l'entrée supprimée, ou null si elle n'existait pas.
	 */
	public static function retirer( $depuis, $appliquer = true ) {
		$depuis   = self::normaliser( $depuis );
		$registre = self::lire();
		if ( '' === $depuis || ! isset( $registre[ $depuis ] ) ) {
			return null;
		}
		$entree = self::exposer( $depuis, (array) $registre[ $depuis ] );
		if ( ! $appliquer ) {
			return array_merge( $entree, [ 'dry_run' => true ] );
		}
		unset( $registre[ $depuis ] );
		self::ecrire( $registre );
		return $entree;
	}

	/**
	 * Prévient les extensions de redirection présentes.
	 *
	 * On écrit dans notre registre dans tous les cas : `lister()` doit dire la
	 * vérité même quand l'extension a refusé l'entrée en silence. Il n'y a pas
	 * de double redirection à craindre — notre interception ne se déclenche que
	 * sur une 404 avérée, donc jamais si l'extension a déjà fait le travail.
	 */
	private static function deleguer( $depuis, $vers, $code ) {
		$faits = [];
		if ( class_exists( 'Red_Item' ) && method_exists( 'Red_Item', 'create' ) ) {
			Red_Item::create(
				[
					'url'         => $depuis,
					'action_data' => [ 'url' => self::url( $vers ) ],
					'action_type' => 'url',
					'action_code' => (int) $code,
					'group_id'    => 1,
					'match_type'  => 'url',
				]
			);
			$faits[] = 'redirection';
		}
		if ( function_exists( 'YoastSEO' ) && class_exists( '\Yoast\WP\SEO\Premium\Repositories\Redirect_Repository' ) ) {
			do_action( 'wpseo_premium_create_redirect', $depuis, self::url( $vers ), (int) $code );
			$faits[] = 'yoast-premium';
		}
		return $faits;
	}

	// ---------------------------------------------------------- interception

	/**
	 * Sur une 404 seulement.
	 *
	 * Une redirection qui s'appliquerait à une requête ayant trouvé sa page
	 * masquerait du contenu publié — et le masquerait silencieusement, puisque
	 * le visiteur atterrirait ailleurs sans que rien ne l'indique. `is_404()`
	 * est fiable ici : `template_redirect` passe après la résolution de la
	 * requête principale.
	 */
	public static function intercepter() {
		if ( is_admin() || wp_doing_ajax() || ! is_404() ) {
			return;
		}
		$demande = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$chemin  = self::normaliser( $demande );
		$entree  = self::resoudre( $chemin );
		if ( ! $entree || '' === $entree['vers'] ) {
			return;
		}

		self::compter( $chemin );

		$cible = self::url( $entree['vers'] );
		// La chaîne de requête suit : un lien entrant marqué `utm_*` doit arriver
		// marqué sur la nouvelle page, sinon la source du trafic est perdue.
		$query = wp_parse_url( $demande, PHP_URL_QUERY );
		if ( $query && ! wp_parse_url( $cible, PHP_URL_QUERY ) ) {
			$cible .= '?' . $query;
		}

		// wp_redirect et non wp_safe_redirect : une cible hors du site est un cas
		// prévu (regroupement de domaines), et wp_safe_redirect la remplacerait
		// par l'accueil sans rien dire. La cible vient du registre, écrit par une
		// route authentifiée — pas d'une entrée visiteur.
		wp_redirect( $cible, (int) $entree['code'] ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/** Compte le passage sur le chemin DEMANDÉ, pas sur la cible finale : c'est
	 *  la vieille URL dont on veut savoir si elle sert encore. */
	private static function compter( $chemin ) {
		$h = get_option( self::OPTION_HITS, [] );
		if ( ! is_array( $h ) ) {
			$h = [];
		}
		$h[ $chemin ] = [
			'hits'    => ( isset( $h[ $chemin ]['hits'] ) ? (int) $h[ $chemin ]['hits'] : 0 ) + 1,
			'dernier' => current_time( 'mysql', true ),
		];
		// `autoload` à faux : ces compteurs ne servent qu'au rapport, jamais au
		// rendu d'une page. Les charger à chaque requête serait du poids gratuit.
		update_option( self::OPTION_HITS, $h, false );
	}

	/** Ce qu'on sait des passages, lu séparément du registre. */
	public static function hits( $chemin = null ) {
		$h = get_option( self::OPTION_HITS, [] );
		if ( ! is_array( $h ) ) {
			$h = [];
		}
		if ( null === $chemin ) {
			return $h;
		}
		return isset( $h[ $chemin ] ) ? $h[ $chemin ] : [ 'hits' => 0, 'dernier' => null ];
	}
}
