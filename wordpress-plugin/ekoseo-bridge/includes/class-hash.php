<?php
/**
 * Le hash canonique d'une page — pivot de tout le système.
 *
 * Deux propriétés à tenir, sans quoi le verrou anti-écrasement ne vaut rien :
 *
 *   1. STABLE   — deux lectures sans modification donnent le même hash.
 *      Interdit donc : ordre de tableau dépendant de la base, dates de lecture,
 *      compteurs, sérialisation PHP (dont l'ordre des clés varie).
 *   2. SENSIBLE — toute modification d'un champ suivi change le hash.
 *
 * La normalisation compacte les espaces et trie les clés. Elle ne touche pas au
 * fond : deux HTML qui ne diffèrent que par l'indentation donnent le même hash,
 * ce qui est voulu — l'indentation n'est pas un changement éditorial.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Hash {

	/** Champs entrant dans le hash, dans un ordre figé. */
	const CHAMPS = [ 'title', 'slug', 'status', 'parent', 'seo', 'meta', 'html' ];

	public static function canonique( array $etat ) {
		$out = [];
		foreach ( self::CHAMPS as $c ) {
			$out[ $c ] = self::normaliser( isset( $etat[ $c ] ) ? $etat[ $c ] : null );
		}
		return $out;
	}

	/**
	 * Normalise récursivement : les tableaux associatifs sont triés par clé,
	 * les listes conservent leur ordre (il porte du sens), les chaînes sont
	 * compactées.
	 */
	public static function normaliser( $v ) {
		if ( is_array( $v ) ) {
			$assoc = array_keys( $v ) !== range( 0, count( $v ) - 1 );
			$out   = [];
			foreach ( $v as $k => $x ) {
				$out[ $k ] = self::normaliser( $x );
			}
			if ( $assoc ) {
				ksort( $out, SORT_STRING );
			}
			return $out;
		}
		if ( is_bool( $v ) || is_int( $v ) || is_float( $v ) ) {
			return $v;
		}
		if ( null === $v ) {
			return '';
		}
		$s = (string) $v;
		// Toute suite d'espaces, tabulations et retours à la ligne devient UN espace :
		// l'indentation d'un HTML n'est pas un changement éditorial, et un générateur
		// qui reformate son rendu ne doit pas déclencher un faux conflit.
		$s = preg_replace( '/\s+/u', ' ', $s );
		return trim( (string) $s );
	}

	public static function calculer( array $etat ) {
		$json = wp_json_encode(
			self::canonique( $etat ),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		return 'sha256:' . hash( 'sha256', (string) $json );
	}
}
