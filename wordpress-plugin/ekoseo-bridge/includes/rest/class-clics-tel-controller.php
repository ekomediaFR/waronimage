<?php
/**
 * Traçabilité des appels — le déclencheur côté site (31/08/2026).
 *
 * Chaque clic sur un lien `tel:` du site est consigné : heure, page, numéro
 * cliqué, adresse IP, navigateur. But : croiser ensuite ces clics avec les
 * journaux d'appels du standard OVH (le numéro y est hébergé, les appels
 * enregistrés avec le numéro appelant) pour distinguer l'appel RÉEL du simple
 * clic — la colonne `rapproche` accueillera ce rapprochement.
 *
 * Vie privée : l'adresse IP est une donnée personnelle. Elle ne sert qu'au
 * rapprochement des appels ; rétention 90 jours, purge quotidienne (cron).
 *
 * La route d'ingestion est PUBLIQUE par nature (l'événement vient du visiteur,
 * qui n'a aucune authentification) — son `permission_callback` est néanmoins
 * RÉEL et applique une politique : POST uniquement, même origine quand le
 * navigateur l'annonce, cadence plafonnée par IP. La lecture, elle, exige
 * `ekoseo_manage` comme toutes les routes du pont.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_ClicsTel_Controller extends EkoSEO_Controller_Base {

	const TABLE           = 'ekoseo_clics_tel';
	const VERSION_SCHEMA  = '1';
	const RETENTION_JOURS = 90;
	const CADENCE_HEURE   = 40;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/** Crée la table si absente ou ancienne — idempotent, piloté par option. */
	public static function maybe_installer() {
		if ( get_option( 'ekoseo_clics_tel_v' ) === self::VERSION_SCHEMA ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ts DATETIME NOT NULL,
			page VARCHAR(255) NOT NULL DEFAULT '',
			tel VARCHAR(32) NOT NULL DEFAULT '',
			ip VARCHAR(45) NOT NULL DEFAULT '',
			ua VARCHAR(160) NOT NULL DEFAULT '',
			rapproche VARCHAR(32) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY ts (ts)
		) " . $wpdb->get_charset_collate() . ';'
		);
		update_option( 'ekoseo_clics_tel_v', self::VERSION_SCHEMA, false );
		if ( ! wp_next_scheduled( 'ekoseo_clics_tel_purge' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'ekoseo_clics_tel_purge' );
		}
	}

	/** Rétention : rien au-delà de 90 jours (l'IP est une donnée personnelle). */
	public static function purger() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			'DELETE FROM ' . self::table() . ' WHERE ts < %s', // phpcs:ignore WordPress.DB.PreparedSQL
			gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_JOURS * DAY_IN_SECONDS )
		) );
	}

	/**
	 * Le déclencheur visiteur, imprimé dans le pied de chaque page : délégation
	 * sur tout lien tel:, envoi en beacon (part même si la page se ferme —
	 * c'est le cas typique : le clic OUVRE l'application téléphone).
	 * text/plain : seul type que sendBeacon accepte sans condition.
	 */
	public static function script() {
		$url = esc_url( rest_url( EKOSEO_BRIDGE_NS . '/clic-tel' ) );
		echo '<script id="ekoseo-clic-tel">(function(){var v={};'
			. 'document.addEventListener("click",function(e){'
			. 'var a=e.target&&e.target.closest?e.target.closest(\'a[href^="tel:"]\'):null;'
			. 'if(!a)return;var t=(a.getAttribute("href")||"").slice(4);'
			. 'var k=t+"|"+location.pathname;if(v[k])return;v[k]=1;'
			. 'var c=JSON.stringify({tel:t,page:location.pathname});'
			. 'if(navigator.sendBeacon){navigator.sendBeacon("' . $url . '",new Blob([c],{type:"text/plain"}));}'
			. 'else{try{fetch("' . $url . '",{method:"POST",body:c,keepalive:true});}catch(x){}}'
			. '},true);})();</script>' . "\n";
	}

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/clic-tel',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'recevoir' ],
				'permission_callback' => [ $this, 'autoriser_public' ],
			]
		);
		register_rest_route(
			$this->namespace,
			'/clics-tel',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'lister' ],
				'permission_callback' => [ $this, 'permissions' ],
			]
		);
	}

	/**
	 * Politique de la route publique — un vrai contrôle, pas un laissez-passer :
	 * POST seul, même origine quand Origin/Referer sont annoncés, et au plus
	 * CADENCE_HEURE clics par IP et par heure (un humain n'en fait pas 5).
	 */
	public function autoriser_public( $req ) {
		if ( 'POST' !== $req->get_method() ) {
			return false;
		}
		$hote = wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( [ $req->get_header( 'origin' ), $req->get_header( 'referer' ) ] as $h ) {
			if ( $h && wp_parse_url( $h, PHP_URL_HOST ) !== $hote ) {
				return false;
			}
		}
		$cle = 'ekoseo_ct_' . md5( self::ip_client() );
		$n   = (int) get_transient( $cle );
		if ( $n >= self::CADENCE_HEURE ) {
			return false;
		}
		set_transient( $cle, $n + 1, HOUR_IN_SECONDS );
		return true;
	}

	/** L'IP réelle du visiteur : REMOTE_ADDR, ou la première X-Forwarded-For
	 *  quand le serveur est derrière un proxy (REMOTE_ADDR privée). */
	private static function ip_client() {
		$ra  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$xff = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] )
			? trim( explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] ) : '';
		if ( $xff && filter_var( $xff, FILTER_VALIDATE_IP )
			&& ! filter_var( $ra, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return $xff;
		}
		return $ra;
	}

	/** Ingestion d'un clic. Corps en text/plain (beacon) : décodé à la main. */
	public function recevoir( $req ) {
		$b = json_decode( (string) $req->get_body(), true );
		if ( ! is_array( $b ) ) {
			return $this->erreur( 'ekoseo_corps_invalide', 'Corps JSON attendu.', 400 );
		}
		$tel = isset( $b['tel'] ) ? preg_replace( '/[^0-9+]/', '', (string) $b['tel'] ) : '';
		$page = isset( $b['page'] ) ? (string) $b['page'] : '';
		$page = '/' . ltrim( (string) wp_parse_url( $page, PHP_URL_PATH ), '/' );
		if ( strlen( $tel ) < 4 || strlen( $tel ) > 31 || strlen( $page ) > 255 ) {
			return $this->erreur( 'ekoseo_clic_invalide', 'Numéro ou page invalide.', 422 );
		}
		global $wpdb;
		$wpdb->insert(
			self::table(),
			[
				'ts'   => current_time( 'mysql' ),
				'page' => $page,
				'tel'  => $tel,
				'ip'   => substr( self::ip_client(), 0, 45 ),
				'ua'   => substr( (string) $req->get_header( 'user_agent' ), 0, 160 ),
			],
			[ '%s', '%s', '%s', '%s', '%s' ]
		);
		return rest_ensure_response( [ 'ok' => true ] );
	}

	/**
	 * Lecture (authentifiée) : les clics, du plus récent au plus ancien, avec
	 * pagination — et l'agrégat par page (le « count » des pages d'où viennent
	 * les appels). `depuis` (Y-m-d) borne la période.
	 */
	public function lister( $req ) {
		global $wpdb;
		$par    = min( 500, max( 1, (int) ( $req['par'] ?: 100 ) ) );
		$page   = max( 0, (int) $req['page'] );
		$depuis = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $req['depuis'] )
			? (string) $req['depuis'] . ' 00:00:00' : '1970-01-01 00:00:00';
		$t = self::table();
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE ts >= %s", $depuis ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$lignes = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, ts, page, tel, ip, ua, rapproche FROM {$t}
			 WHERE ts >= %s ORDER BY ts DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL
			$depuis, $par, $page * $par ), ARRAY_A );
		$par_page = $wpdb->get_results( $wpdb->prepare(
			"SELECT page, COUNT(*) AS n FROM {$t}
			 WHERE ts >= %s GROUP BY page ORDER BY n DESC LIMIT 60", // phpcs:ignore WordPress.DB.PreparedSQL
			$depuis ), ARRAY_A );
		return rest_ensure_response(
			[
				'total'     => $total,
				'par'       => $par,
				'page'      => $page,
				'lignes'    => $lignes,
				'par_pages' => $par_page,
				'retention' => self::RETENTION_JOURS,
			]
		);
	}
}
