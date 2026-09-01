<?php
/**
 * GET /scan — tout ce qu'on va aujourd'hui chercher en parcourant le site page
 * par page, lu directement en base.
 *
 * Pourquoi ça change tout : le relevé externe demande une requête HTTP par page
 * (dix minutes pour mille pages, et le serveur refuse au-delà d'un certain
 * rythme). Ici le contenu est déjà là. On lit, on analyse, on renvoie — et on
 * couvre l'intégralité du site au lieu d'un échantillon.
 *
 * Les analyses restent identiques à celles du scanner externe pour que les
 * chiffres soient comparables d'une source à l'autre.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Scan_Controller extends EkoSEO_Controller_Base {

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/scan',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'permissions' ],
				'args'                => [
					'cpt'      => [ 'default' => '', 'type' => 'string' ],
					'page'     => [ 'default' => 1, 'type' => 'integer' ],
					'per_page' => [ 'default' => 50, 'type' => 'integer' ],
					'links'    => [ 'default' => true, 'type' => 'boolean' ],
					'status'   => [ 'default' => 'publish', 'type' => 'string' ],
				],
			]
		);
		register_rest_route(
			$this->namespace,
			'/redirects',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'redirects' ],
				'permission_callback' => [ $this, 'permissions' ],
			]
		);
	}

	public function get_items( $req ) {
		$cpt  = sanitize_key( (string) $req->get_param( 'cpt' ) );
		$per  = max( 1, min( 200, (int) $req->get_param( 'per_page' ) ) );
		$page = max( 1, (int) $req->get_param( 'page' ) );
		$liens = (bool) $req->get_param( 'links' );
		$statut = (string) $req->get_param( 'status' );

		$types = $cpt ? [ $cpt ] : array_values( get_post_types( [ 'public' => true ], 'names' ) );
		$types = array_diff( $types, [ 'attachment' ] );
		if ( $cpt && ! post_type_exists( $cpt ) ) {
			return $this->erreur( 'ekoseo_cpt_inconnu', sprintf( 'Type « %s » inexistant.', $cpt ), 404 );
		}

		$q = new WP_Query(
			[
				'post_type'      => $types,
				'post_status'    => 'any' === $statut ? [ 'publish', 'draft', 'pending', 'private' ] : $statut,
				'posts_per_page' => $per,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);

		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$items = [];
		foreach ( $q->posts as $p ) {
			list( $html, $source ) = EkoSEO_Elementor_IO::lire_corps( $p->ID );
			$rendu = $this->analyser( $html, $host, $liens );
			$seo   = EkoSEO_Yoast_IO::lire( $p->ID );

			$items[] = array_merge(
				[
					'id'             => (int) $p->ID,
					'cpt'            => $p->post_type,
					'slug'           => $p->post_name,
					'chemin'         => $this->chemin_url( $p ),
					'title'          => $p->post_title,
					'status'         => $p->post_status,
					'post_parent'    => (int) $p->post_parent,
					'modified'       => get_post_modified_time( 'c', true, $p ),
					'content_source' => $source,
					'seo'            => [
						'title'    => $seo['title'],
						'metadesc' => $seo['metadesc'],
						'focus_kw' => $seo['focus_kw'],
						'lt'       => mb_strlen( $seo['title'] ),
						'lm'       => mb_strlen( $seo['metadesc'] ),
					],
					'meta'           => $this->metas_suivies( $p->ID ),
				],
				$rendu
			);
		}

		$rep = rest_ensure_response( $items );
		$rep->header( 'X-WP-Total', (int) $q->found_posts );
		$rep->header( 'X-WP-TotalPages', (int) $q->max_num_pages );
		return $rep;
	}

	/** Même grille d'analyse que le scanner externe, pour que les chiffres se comparent. */
	private function analyser( $html, $host, $avec_liens ) {
		list( $doc ) = EkoSEO_Html_Parser::charger( (string) $html );
		if ( ! $doc ) {
			return [ 'mots' => 0, 'h1' => [], 'h2' => [], 'n_h1' => 0, 'n_h2' => 0, 'n_h3' => 0,
				'liens_internes' => 0, 'liens_externes' => 0, 'images' => 0, 'sans_alt' => 0,
				'schemas' => [], 'liens' => [] ];
		}
		$xp = new DOMXPath( $doc );

		$texte = '';
		foreach ( $xp->query( '//text()' ) as $t ) {
			$par = $t->parentNode ? strtolower( $t->parentNode->nodeName ) : '';
			if ( in_array( $par, [ 'script', 'style' ], true ) ) {
				continue;
			}
			$texte .= ' ' . $t->nodeValue;
		}
		$mots = preg_split( '/\s+/u', trim( preg_replace( '/\s+/u', ' ', $texte ) ) );

		$titres = function ( $n ) use ( $xp ) {
			$out = [];
			foreach ( $xp->query( '//h' . $n ) as $h ) {
				$out[] = trim( preg_replace( '/\s+/u', ' ', $h->textContent ) );
			}
			return $out;
		};
		$h1 = $titres( 1 );
		$h2 = $titres( 2 );
		$h3 = $titres( 3 );

		$imgs = $xp->query( '//img' );
		$sans = 0;
		foreach ( $imgs as $i ) {
			if ( '' === trim( $i->getAttribute( 'alt' ) ) ) {
				$sans++;
			}
		}

		$internes = [];
		$externes = 0;
		foreach ( $xp->query( '//a[@href]' ) as $a ) {
			$h = trim( $a->getAttribute( 'href' ) );
			if ( '' === $h || 0 === strpos( $h, '#' ) || 0 === strpos( $h, 'mailto:' ) || 0 === strpos( $h, 'tel:' ) ) {
				continue;
			}
			$u = wp_parse_url( $h );
			$hote = isset( $u['host'] ) ? $u['host'] : '';
			if ( $hote && false === strpos( $hote, (string) $host ) ) {
				$externes++;
				continue;
			}
			$c = isset( $u['path'] ) ? $u['path'] : '/';
			$c = rtrim( $c, '/' );
			$c = '' === $c ? '/' : $c;
			if ( preg_match( '/\.(jpe?g|png|gif|webp|svg|pdf|zip|css|js|ico)$/i', $c ) ) {
				continue;
			}
			$internes[ $c ] = isset( $internes[ $c ] ) ? $internes[ $c ] + 1 : 1;
		}

		$schemas = [];
		foreach ( $xp->query( '//script[@type="application/ld+json"]' ) as $s ) {
			$j = json_decode( trim( $s->textContent ), true );
			foreach ( (array) $j as $k => $v ) {
				if ( '@type' === $k && is_string( $v ) ) {
					$schemas[] = $v;
				} elseif ( is_array( $v ) && isset( $v['@type'] ) && is_string( $v['@type'] ) ) {
					$schemas[] = $v['@type'];
				}
			}
		}

		$out = [
			'mots'           => count( array_filter( $mots ) ),
			'h1'             => array_slice( $h1, 0, 3 ),
			'h2'             => array_slice( $h2, 0, 25 ),
			'n_h1'           => count( $h1 ),
			'n_h2'           => count( $h2 ),
			'n_h3'           => count( $h3 ),
			'liens_internes' => count( $internes ),
			'liens_externes' => $externes,
			'images'         => $imgs->length,
			'sans_alt'       => $sans,
			'schemas'        => array_values( array_unique( $schemas ) ),
		];
		if ( $avec_liens ) {
			$out['liens'] = array_keys( $internes );
		}
		return $out;
	}

	private function chemin_url( $p ) {
		$u = get_permalink( $p );
		$c = wp_parse_url( $u, PHP_URL_PATH );
		$c = rtrim( (string) $c, '/' );
		return '' === $c ? '/' : $c;
	}

	/**
	 * Les redirections en place, si une extension connue les gère.
	 * Une 301 oubliée après un changement de slug est invisible autrement.
	 */
	public function redirects( $req ) {
		global $wpdb;
		$out = [ 'source' => null, 'items' => [] ];

		$t = $wpdb->prefix . 'redirection_items';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			$out['source'] = 'redirection';
			$rows = $wpdb->get_results( "SELECT url, action_data, action_code, status FROM {$t} LIMIT 2000", ARRAY_A ); // phpcs:ignore
			foreach ( (array) $rows as $r ) {
				$out['items'][] = [
					'depuis' => $r['url'],
					'vers'   => $r['action_data'],
					'code'   => (int) $r['action_code'],
					'actif'  => 'enabled' === $r['status'],
				];
			}
			return rest_ensure_response( $out );
		}

		$t = $wpdb->prefix . 'yoast_indexable';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			$out['source'] = 'yoast_premium';
		}
		return rest_ensure_response( $out );
	}
}
