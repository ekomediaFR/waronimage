<?php
/**
 * Analyse du HTML — DOMDocument uniquement.
 *
 * Jamais de regex pour parser du HTML : le corps de ces pages contient du CSS,
 * du JavaScript, des accents et des entités. Une expression régulière y trouve
 * toujours de quoi se tromper, et l'erreur ne se voit qu'en production.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Html_Parser {

	const SCRIPTS_AUTORISES = [ 'application/ld+json' ];

	/** Charge un fragment HTML sans que DOMDocument y ajoute html/body/doctype. */
	public static function charger( $html ) {
		$doc = new DOMDocument( '1.0', 'UTF-8' );
		$prev = libxml_use_internal_errors( true );
		$ok = $doc->loadHTML(
			'<?xml encoding="UTF-8">' . $html,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOWARNING | LIBXML_NOERROR
		);
		$erreurs = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $ok ? [ $doc, $erreurs ] : [ null, $erreurs ];
	}

	/**
	 * Vérifie qu'un corps de page est acceptable avant écriture.
	 * Retourne un tableau d'erreurs — vide si tout va bien.
	 *
	 * `$reference` est le corps ACTUEL de la page. Quand il est fourni, un
	 * <script> qui s'y trouve déjà **à l'identique** est accepté.
	 *
	 * La raison tient en une phrase : ce pont existe pour relire une page, la
	 * corriger et la réécrire. Refuser de réécrire un script qui était déjà là
	 * rendrait cette boucle impossible sur toute page qui en contient un — sans
	 * rien protéger, puisque le script est déjà en ligne. Ce qui doit rester
	 * interdit, c'est d'en INTRODUIRE un nouveau, ou d'en modifier un existant.
	 * La comparaison est faite sur une empreinte du type, de la source et du
	 * contenu normalisé ; le compte est tenu, pour qu'un script présent une fois
	 * ne puisse pas revenir trois.
	 */
	public static function valider( $html, $max_octets = 2097152, $reference = null ) {
		$err = [];
		if ( '' === trim( (string) $html ) ) {
			return [ 'Le corps est vide.' ];
		}
		if ( strlen( $html ) > $max_octets ) {
			$err[] = sprintf( 'Corps de %d octets, au-delà du plafond de %d.', strlen( $html ), $max_octets );
		}
		list( $doc ) = self::charger( $html );
		if ( ! $doc ) {
			return [ 'HTML non analysable par DOMDocument.' ];
		}
		$xp = new DOMXPath( $doc );

		/*
		 * v1.3.0 — même règle pour les identifiants que pour les scripts : un
		 * doublon qui existe DÉJÀ dans la page actuelle est un héritage du
		 * gabarit (deux SVG « Layer_1 » exportés d'Illustrator, typiquement),
		 * pas une faute de notre écriture — le refuser rendait la page
		 * irréparable par le pont sans rien protéger, puisque le doublon est
		 * déjà en ligne. Ce qui reste interdit : en INTRODUIRE un nouveau, ou
		 * aggraver le compte d'un doublon existant. Le compte est tenu.
		 */
		$herites = [];
		if ( null !== $reference && '' !== trim( (string) $reference ) ) {
			list( $doc_ref ) = self::charger( (string) $reference );
			if ( $doc_ref ) {
				$xr      = new DOMXPath( $doc_ref );
				$vus_ref = [];
				foreach ( $xr->query( '//*[@id]' ) as $n ) {
					$id = $n->getAttribute( 'id' );
					if ( isset( $vus_ref[ $id ] ) ) {
						$herites[ $id ] = isset( $herites[ $id ] ) ? $herites[ $id ] + 1 : 1;
					}
					$vus_ref[ $id ] = true;
				}
			}
		}

		$vus = [];
		foreach ( $xp->query( '//*[@id]' ) as $n ) {
			$id = $n->getAttribute( 'id' );
			if ( isset( $vus[ $id ] ) ) {
				if ( ! empty( $herites[ $id ] ) ) {
					$herites[ $id ]--;   // doublon déjà en ligne : il repart tel quel
				} else {
					$err[] = sprintf( 'Identifiant HTML en double : « %s ».', $id );
				}
			}
			$vus[ $id ] = true;
		}

		$connus = self::empreintes_scripts( $reference );
		foreach ( $xp->query( '//script' ) as $s ) {
			$type = strtolower( trim( $s->getAttribute( 'type' ) ) );
			if ( in_array( $type, self::SCRIPTS_AUTORISES, true ) ) {
				continue;
			}
			$emp = self::empreinte_script( $s );
			if ( ! empty( $connus[ $emp ] ) ) {
				$connus[ $emp ]--;      // déjà présent dans la page : il repart tel quel
				continue;
			}
			$err[] = sprintf(
				'Balise <script> de type « %s » absente de la page actuelle : le pont '
					. "n'introduit ni ne modifie de script. Seuls %s peuvent être écrits librement ; "
					. 'un script existant est réécrit à l\'identique. Corriger ce bloc dans Elementor.',
				$type ? $type : '(aucun)',
				implode( ', ', self::SCRIPTS_AUTORISES )
			);
		}
		return $err;
	}

	/** Empreinte stable d'un <script> : son type, sa source, son contenu normalisé. */
	private static function empreinte_script( $node ) {
		$type = strtolower( trim( $node->getAttribute( 'type' ) ) );
		$src  = trim( $node->getAttribute( 'src' ) );
		$txt  = preg_replace( '/\s+/u', ' ', (string) $node->textContent );
		return sha1( $type . '|' . $src . '|' . trim( (string) $txt ) );
	}

	/** Les scripts déjà présents dans un corps, comptés par empreinte. */
	private static function empreintes_scripts( $html ) {
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return [];
		}
		list( $doc ) = self::charger( $html );
		if ( ! $doc ) {
			return [];
		}
		$out = [];
		foreach ( ( new DOMXPath( $doc ) )->query( '//script' ) as $s ) {
			$e = self::empreinte_script( $s );
			$out[ $e ] = isset( $out[ $e ] ) ? $out[ $e ] + 1 : 1;
		}
		return $out;
	}

	/** Statistiques descriptives — servent au diff et au rapport, pas au hash. */
	public static function stats( $html ) {
		list( $doc ) = self::charger( (string) $html );
		if ( ! $doc ) {
			return [ 'words' => 0, 'h2' => 0, 'h3' => 0, 'internal_links' => 0,
				'images' => 0, 'images_without_alt' => 0 ];
		}
		$xp    = new DOMXPath( $doc );
		$texte = '';
		foreach ( $xp->query( '//text()' ) as $t ) {
			$p = $t->parentNode ? strtolower( $t->parentNode->nodeName ) : '';
			if ( in_array( $p, [ 'script', 'style' ], true ) ) {
				continue;
			}
			$texte .= ' ' . $t->nodeValue;
		}
		$mots = preg_split( '/\s+/u', trim( preg_replace( '/\s+/u', ' ', $texte ) ) );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		$imgs = $xp->query( '//img' );
		$sans = 0;
		foreach ( $imgs as $i ) {
			if ( '' === trim( $i->getAttribute( 'alt' ) ) ) {
				$sans++;
			}
		}
		$internes = 0;
		foreach ( $xp->query( '//a[@href]' ) as $a ) {
			$h = $a->getAttribute( 'href' );
			if ( '' === $h || 0 === strpos( $h, '#' ) || 0 === strpos( $h, 'mailto:' ) || 0 === strpos( $h, 'tel:' ) ) {
				continue;
			}
			if ( 0 === strpos( $h, '/' ) || false !== strpos( $h, (string) $host ) ) {
				$internes++;
			}
		}
		return [
			'words'              => count( array_filter( $mots ) ),
			'h2'                 => $xp->query( '//h2' )->length,
			'h3'                 => $xp->query( '//h3' )->length,
			'internal_links'     => $internes,
			'images'             => $imgs->length,
			'images_without_alt' => $sans,
		];
	}

	/** Découpe en blocs `data-eko-block` — base du diff bloc par bloc. */
	public static function blocs( $html ) {
		list( $doc ) = self::charger( (string) $html );
		if ( ! $doc ) {
			return [];
		}
		$xp  = new DOMXPath( $doc );
		$out = [];
		foreach ( $xp->query( '//*[@data-eko-block]' ) as $n ) {
			$frag = $doc->saveHTML( $n );
			$out[] = [
				'id'    => $n->getAttribute( 'data-eko-block' ),
				'chars' => strlen( $frag ),
				'hash'  => substr( hash( 'sha256', EkoSEO_Hash::normaliser( $frag ) ), 0, 32 ),
			];
		}
		return $out;
	}
}
