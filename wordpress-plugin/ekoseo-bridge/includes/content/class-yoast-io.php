<?php
/**
 * Lecture et écriture des métadonnées Yoast.
 *
 * Les clés sont celles de Yoast, y compris pour Premium. `focuskeywords`
 * (mots-clés secondaires) est une chaîne JSON d'objets `{keyword, score}` ;
 * `keywordsynonyms` un tableau JSON de chaînes. Écrire un format différent ne
 * casse rien visuellement mais rend l'analyse Yoast muette dans l'éditeur.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Yoast_IO {

	const TITLE     = '_yoast_wpseo_title';
	const DESC      = '_yoast_wpseo_metadesc';
	const FOCUS     = '_yoast_wpseo_focuskw';
	const SYNONYMS  = '_yoast_wpseo_keywordsynonyms';
	const EXTRA     = '_yoast_wpseo_focuskeywords';

	/**
	 * Tout le reste de Yoast, que le pont ne savait pas toucher.
	 *
	 * Ces champs se rangent en trois familles, et la distinction compte pour qui
	 * décide de les écrire :
	 *
	 * — Ce qui change ce que Google AFFICHE : canonique, robots, fil d'Ariane.
	 *   Une erreur ici se paie en visibilité, pas en apparence.
	 * — Ce qui change ce que les réseaux affichent : Open Graph, Twitter. Une
	 *   erreur ne coûte que la vignette d'un partage.
	 * — Ce qui change la façon dont Yoast COMPREND la page : type de schéma,
	 *   contenu pilier. Aucun effet direct sur Google, un effet réel sur les
	 *   données structurées émises.
	 *
	 * `linkdex` et `content_score` ne figurent pas dans les champs écrivables :
	 * ce sont les notes calculées par Yoast. Les écrire nous-mêmes reviendrait à
	 * fabriquer un bulletin — et masquerait précisément ce qu'on cherche à mesurer.
	 */
	const CHAMPS = [
		'title'          => '_yoast_wpseo_title',
		'metadesc'       => '_yoast_wpseo_metadesc',
		'focus_kw'       => '_yoast_wpseo_focuskw',
		'canonical'      => '_yoast_wpseo_canonical',
		'bctitle'        => '_yoast_wpseo_bctitle',
		'noindex'        => '_yoast_wpseo_meta-robots-noindex',
		'nofollow'       => '_yoast_wpseo_meta-robots-nofollow',
		'robots_adv'     => '_yoast_wpseo_meta-robots-adv',
		'cornerstone'    => '_yoast_wpseo_is_cornerstone',
		'og_titre'       => '_yoast_wpseo_opengraph-title',
		'og_description' => '_yoast_wpseo_opengraph-description',
		'og_image'       => '_yoast_wpseo_opengraph-image',
		'tw_titre'       => '_yoast_wpseo_twitter-title',
		'tw_description' => '_yoast_wpseo_twitter-description',
		'tw_image'       => '_yoast_wpseo_twitter-image',
		'schema_page'    => '_yoast_wpseo_schema_page_type',
		'schema_article' => '_yoast_wpseo_schema_article_type',
	];

	/** Les notes calculées par Yoast : lues, jamais écrites. */
	const NOTES = [
		'note_seo'      => '_yoast_wpseo_linkdex',
		'note_lecture'  => '_yoast_wpseo_content_score',
		'kw_precedents' => '_yoast_wpseo_focuskw_text_input',
	];

	public static function actif() {
		return defined( 'WPSEO_VERSION' );
	}

	public static function premium() {
		return defined( 'WPSEO_PREMIUM_FILE' ) || defined( 'WPSEO_PREMIUM_VERSION' );
	}

	public static function version() {
		return defined( 'WPSEO_VERSION' ) ? WPSEO_VERSION : null;
	}

	public static function lire( $post_id ) {
		$syn = get_post_meta( $post_id, self::SYNONYMS, true );
		$ext = get_post_meta( $post_id, self::EXTRA, true );
		$out = [
			'synonyms' => self::liste( $syn ),
			'extra_kw' => self::mots_extra( $ext ),
		];
		foreach ( self::CHAMPS as $cle => $meta ) {
			$out[ $cle ] = (string) get_post_meta( $post_id, $meta, true );
		}
		// Les notes de Yoast sont rendues sous une clé à part : elles se lisent,
		// elles ne s'écrivent pas, et les mêler aux champs modifiables inviterait
		// à essayer.
		$notes = [];
		foreach ( self::NOTES as $cle => $meta ) {
			$v = get_post_meta( $post_id, $meta, true );
			if ( '' !== $v && null !== $v ) {
				$notes[ $cle ] = is_numeric( $v ) ? (int) $v : (string) $v;
			}
		}
		$out['notes'] = $notes;
		return $out;
	}


	private static function liste( $v ) {
		if ( is_array( $v ) ) {
			return array_values( array_filter( array_map( 'strval', $v ) ) );
		}
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return [];
		}
		$j = json_decode( $v, true );
		if ( is_array( $j ) ) {
			return array_values( array_filter( array_map( 'strval', $j ) ) );
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $v ) ) ) );
	}

	private static function mots_extra( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return [];
		}
		$j = json_decode( $v, true );
		if ( ! is_array( $j ) ) {
			return [];
		}
		$out = [];
		foreach ( $j as $x ) {
			if ( is_array( $x ) && isset( $x['keyword'] ) ) {
				$out[] = (string) $x['keyword'];
			} elseif ( is_string( $x ) ) {
				$out[] = $x;
			}
		}
		return array_values( array_filter( $out ) );
	}

	/** Retourne la liste des champs réellement modifiés. */
	public static function ecrire( $post_id, array $seo ) {
		$avant   = self::lire( $post_id );
		$changes = [];

		// Tous les champs simples, y compris ceux ajoutés le 2026-08-23 : canonique,
		// robots, fil d'Ariane, contenu pilier, Open Graph, Twitter, types de schéma.
		// Un champ absent du corps envoyé n'est pas touché — écrire une chaîne vide
		// et ne rien envoyer sont deux intentions différentes.
		foreach ( self::CHAMPS as $cle => $meta ) {
			if ( ! array_key_exists( $cle, $seo ) ) {
				continue;
			}
			$val = (string) $seo[ $cle ];
			if ( $val !== $avant[ $cle ] ) {
				update_post_meta( $post_id, $meta, $val );
				$changes[] = 'seo.' . $cle;
			}
		}

		if ( array_key_exists( 'synonyms', $seo ) ) {
			$val = array_values( array_filter( array_map( 'strval', (array) $seo['synonyms'] ) ) );
			if ( $val !== $avant['synonyms'] ) {
				update_post_meta( $post_id, self::SYNONYMS, wp_json_encode( $val, JSON_UNESCAPED_UNICODE ) );
				$changes[] = 'seo.synonyms';
			}
		}

		if ( array_key_exists( 'extra_kw', $seo ) ) {
			// Une chaîne à virgules devient une liste : envoyée telle quelle,
			// elle faisait UNE related keyphrase de 15 mots (31/08/2026).
			$brut = $seo['extra_kw'];
			if ( is_string( $brut ) ) {
				$brut = array_map( 'trim', explode( ',', $brut ) );
			}
			$val = array_values( array_filter( array_map( 'strval', (array) $brut ) ) );
			if ( $val !== $avant['extra_kw'] ) {
				$objets = [];
				foreach ( $val as $k ) {
					$objets[] = [ 'keyword' => $k, 'score' => 0 ];
				}
				update_post_meta( $post_id, self::EXTRA, wp_json_encode( $objets, JSON_UNESCAPED_UNICODE ) );
				$changes[] = 'seo.extra_kw';
			}
		}
		return $changes;
	}
}
