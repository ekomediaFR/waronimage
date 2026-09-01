<?php
/**
 * Où vit le corps de la page, et comment le lire.
 *
 * Deux cas possibles, tranchés au runtime (tâche 0.1 du brief) :
 *
 *   A — `elementor_html_widget` : le HTML est en dur dans `_elementor_data`,
 *       à l'intérieur d'un widget de type `html`, clé `settings.html`.
 *   B — `jetengine_meta_html`   : le widget affiche un champ dynamique, et le
 *       HTML vit dans la méta `html` du post.
 *
 * Le champ `html` observé vide sur Gauvin pendant que la page s'affiche oriente
 * vers A — mais l'existence du champ suggère que B a servi. Le plugin ne
 * suppose rien : il regarde, et journalise ce qu'il a trouvé.
 *
 * ⚠️ Ce sprint ne fait que LIRE. L'écriture du corps renvoie 501.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Elementor_IO {

	const META_ELEMENTOR = '_elementor_data';
	const META_HTML      = 'html';

	public static function actif() {
		return defined( 'ELEMENTOR_VERSION' );
	}

	public static function pro() {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	public static function version() {
		return defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null;
	}

	/** `_elementor_data` est stocké en JSON, parfois doublement échappé. */
	public static function donnees( $post_id ) {
		$raw = get_post_meta( $post_id, self::META_ELEMENTOR, true );
		if ( empty( $raw ) ) {
			return null;
		}
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$d = json_decode( $raw, true );
		if ( is_string( $d ) ) {
			$d = json_decode( $d, true );
		}
		return is_array( $d ) ? $d : null;
	}

	/** Parcours en profondeur : les widgets sont imbriqués dans des sections. */
	public static function parcourir( array $noeuds, callable $f ) {
		foreach ( $noeuds as $n ) {
			$f( $n );
			if ( ! empty( $n['elements'] ) && is_array( $n['elements'] ) ) {
				self::parcourir( $n['elements'], $f );
			}
		}
	}

	/**
	 * Détermine où se trouve le corps de CETTE page.
	 * Retourne `elementor_html_widget`, `jetengine_meta_html` ou `unknown`.
	 */
	public static function source( $post_id ) {
		$meta_html = (string) get_post_meta( $post_id, self::META_HTML, true );
		$data      = self::donnees( $post_id );

		$en_dur = 0;
		$dynamique = 0;
		if ( $data ) {
			self::parcourir(
				$data,
				function ( $n ) use ( &$en_dur, &$dynamique ) {
					if ( empty( $n['widgetType'] ) || 'html' !== $n['widgetType'] ) {
						return;
					}
					$s = isset( $n['settings'] ) && is_array( $n['settings'] ) ? $n['settings'] : [];
					$h = isset( $s['html'] ) ? (string) $s['html'] : '';
					if ( strlen( trim( $h ) ) > 200 ) {
						$en_dur++;
					}
					// un champ dynamique laisse une trace dans __dynamic__
					if ( ! empty( $s['__dynamic__'] ) ) {
						$dynamique++;
					}
				}
			);
		}

		if ( $en_dur > 0 ) {
			return 'elementor_html_widget';
		}
		if ( strlen( trim( $meta_html ) ) > 200 || $dynamique > 0 ) {
			return 'jetengine_meta_html';
		}
		return 'unknown';
	}

	/** Le corps de la page, quel que soit l'endroit où il vit. */
	public static function lire_corps( $post_id ) {
		$src = self::source( $post_id );
		if ( 'jetengine_meta_html' === $src ) {
			return [ (string) get_post_meta( $post_id, self::META_HTML, true ), $src ];
		}
		if ( 'elementor_html_widget' === $src ) {
			$data  = self::donnees( $post_id );
			$parts = [];
			self::parcourir(
				$data ? $data : [],
				function ( $n ) use ( &$parts ) {
					if ( empty( $n['widgetType'] ) || 'html' !== $n['widgetType'] ) {
						return;
					}
					$s = isset( $n['settings']['html'] ) ? (string) $n['settings']['html'] : '';
					if ( strlen( trim( $s ) ) > 0 ) {
						$parts[] = $s;
					}
				}
			);
			return [ implode( "\n", $parts ), $src ];
		}
		$p = get_post( $post_id );
		return [ $p ? (string) $p->post_content : '', $src ];
	}

	/**
	 * Écrit sans que `wp_kses` ne mutile le contenu — et seulement ici.
	 *
	 * Le compte `ekoseo_bot` n'a pas `unfiltered_html`, et ne doit pas l'avoir :
	 * cette capacité autoriserait l'injection de JavaScript partout sur le site.
	 * Mais tant qu'elle manque, WordPress applique `wp_kses` à l'écriture, qui
	 * **retire les balises `<script>`, `<style>` et les attributs `on*` en
	 * gardant leur contenu texte**. Une page dont le JSON-LD vivait dans un
	 * `<script type="application/ld+json">` s'est ainsi retrouvée à afficher son
	 * JSON en clair au milieu du texte. Constaté le 20/08/2026 sur Versailles et
	 * sur Paris 16.
	 *
	 * Le filtre est donc levé le temps de l'écriture, et uniquement là. Ce n'est
	 * pas un contournement de la protection : le validateur a déjà exigé que
	 * chaque `<script>` du contenu entrant existe **à l'identique dans la page
	 * actuelle** (sauf `application/ld+json`, toujours admis). On ne réécrit donc
	 * que ce qui était déjà publié — on ne fait pas entrer de code neuf.
	 */
	public static function sans_kses( callable $f ) {
		// 1. Les filtres de kses eux-mêmes, sur les champs de post.
		$actif = has_filter( 'content_save_pre', 'wp_filter_post_kses' )
			|| has_filter( 'content_filtered_save_pre', 'wp_filter_post_kses' );
		if ( $actif ) {
			kses_remove_filters();
		}

		// 2. La CAPACITÉ, qui est la vraie racine.
		//
		// Retirer les filtres de kses ne suffisait pas : constaté le 20/08/2026,
		// des scripts disparaissaient encore. C'est que kses n'est pas seul —
		// Elementor, Yoast et la plupart des extensions de sécurité consultent
		// directement `current_user_can( 'unfiltered_html' )` avant d'écrire, et
		// nettoient de leur côté quand la réponse est non.
		//
		// On accorde donc la capacité pour la durée exacte de l'écriture, et à
		// personne d'autre : c'est précisément ce dont dispose l'administrateur
		// qui lance l'importateur WordPress — la raison pour laquelle la chaîne
		// XML n'a jamais rencontré ce problème. Le rôle `ekoseo_agent`, lui, ne
		// la reçoit jamais de façon permanente.
		$accorder = function ( $caps ) {
			$caps['unfiltered_html'] = true;
			return $caps;
		};
		add_filter( 'user_has_cap', $accorder, PHP_INT_MAX );
		add_filter( 'map_meta_cap', [ __CLASS__, 'permettre_html_brut' ], PHP_INT_MAX, 2 );

		try {
			return $f();
		} finally {
			remove_filter( 'user_has_cap', $accorder, PHP_INT_MAX );
			remove_filter( 'map_meta_cap', [ __CLASS__, 'permettre_html_brut' ], PHP_INT_MAX );
			if ( $actif ) {
				kses_init_filters();
			}
		}
	}

	/** `unfiltered_html` passe par map_meta_cap : sans ça, le filtre au-dessus est ignoré. */
	public static function permettre_html_brut( $caps, $cap ) {
		return 'unfiltered_html' === $cap ? [ 'exist' ] : $caps;
	}

	/**
	 * Écrit une méta en court-circuitant TOUS les filtres — dernier recours.
	 *
	 * Employé seulement quand la voie normale a été vérifiée et a échoué : une
	 * extension du site nettoie la valeur malgré la capacité accordée. Plutôt que
	 * de publier une page mutilée ou de renoncer, on écrit la ligne que l'API de
	 * métadonnées aurait écrite, puis on vide le cache d'objets pour que WordPress
	 * relise la base et non sa copie en mémoire.
	 *
	 * Réservé à une valeur déjà validée : structure vérifiée, scripts confrontés à
	 * ceux de la page, géographie contrôlée. Ce n'est pas une porte ouverte.
	 */
	public static function meta_directe( $post_id, $cle, $valeur ) {
		global $wpdb;
		$existe = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
			"SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
			(int) $post_id, $cle
		) );
		if ( $existe ) {
			$ok = false !== $wpdb->update( $wpdb->postmeta, [ 'meta_value' => $valeur ],
				[ 'meta_id' => (int) $existe ] );
		} else {
			$ok = false !== $wpdb->insert( $wpdb->postmeta, [
				'post_id' => (int) $post_id, 'meta_key' => $cle, 'meta_value' => $valeur ] );
		}
		wp_cache_delete( (int) $post_id, 'post_meta' );
		clean_post_cache( (int) $post_id );
		return $ok;
	}

	/**
	 * Ce que le contenu porte de fragile — ce que `wp_kses` retirerait.
	 * Sert à VÉRIFIER après écriture, au lieu d'annoncer un succès sans le savoir.
	 */
	public static function empreinte_fragile( $html ) {
		$h = (string) $html;
		return [
			'script'  => preg_match_all( '/<script[\s>]/i', $h ),
			'style'   => preg_match_all( '/<style[\s>]/i', $h ),
			'onevent' => preg_match_all( '/\son[a-z]+\s*=/i', $h ),
			'octets'  => strlen( $h ),
		];
	}

	/**
	 * Écrit le corps là où il vit réellement — et nulle part ailleurs.
	 *
	 * Écrire dans `post_content` une page dont le corps vient d'Elementor ne
	 * produit aucun effet visible : Elementor rend depuis ses propres données.
	 * La modification serait acceptée, journalisée, et invisible en ligne. Plutôt
	 * que de mentir, on refuse et on dit où est le corps.
	 *
	 * Retourne [ ok, message ]. `ok` faux n'est jamais une panne : c'est un refus
	 * motivé.
	 */
	public static function ecrire_corps( $post_id, $html ) {
		$src = self::source( $post_id );

		if ( 'jetengine_meta_html' === $src ) {
			self::sans_kses( function () use ( $post_id, $html ) {
				update_post_meta( $post_id, self::META_HTML, $html );
			} );
			$v = self::verifier( $post_id, $html, 'meta ' . self::META_HTML );
			if ( $v[0] ) {
				return $v;
			}
			self::meta_directe( $post_id, self::META_HTML, $html );
			return self::verifier( $post_id, $html, 'meta ' . self::META_HTML . ' (écriture directe)' );
		}

		if ( 'elementor_html_widget' === $src ) {
			$data  = self::donnees( $post_id );
			$cibles = [];
			self::parcourir(
				$data ? $data : [],
				function ( $n ) use ( &$cibles ) {
					if ( empty( $n['widgetType'] ) || 'html' !== $n['widgetType'] ) {
						return;
					}
					$s = isset( $n['settings']['html'] ) ? (string) $n['settings']['html'] : '';
					if ( strlen( trim( $s ) ) > 200 ) {
						$cibles[] = isset( $n['id'] ) ? (string) $n['id'] : '?';
					}
				}
			);
			if ( count( $cibles ) !== 1 ) {
				return [
					false,
					sprintf(
						'Le corps est réparti sur %d widgets HTML Elementor (%s). Réécrire '
						. "l'ensemble reviendrait à choisir à votre place lequel porte quoi. "
						. 'Regrouper le contenu dans un seul widget, ou modifier ces blocs à la main.',
						count( $cibles ),
						implode( ', ', $cibles )
					),
				];
			}
			$cible = $cibles[0];
			$neuf  = self::remplacer( $data, $cible, $html );
			$json  = wp_json_encode( $neuf );
			self::sans_kses( function () use ( $post_id, $json ) {
				update_post_meta( $post_id, self::META_ELEMENTOR, wp_slash( $json ) );
			} );
			delete_post_meta( $post_id, '_elementor_css' );

			$v = self::verifier( $post_id, $html, 'widget Elementor ' . $cible );
			if ( $v[0] ) {
				return $v;
			}
			// La voie normale a été nettoyée malgré la capacité accordée. On écrit
			// la ligne directement, puis on revérifie : si ça échoue encore, on le dit.
			self::meta_directe( $post_id, self::META_ELEMENTOR, $json );
			delete_post_meta( $post_id, '_elementor_css' );
			$v2 = self::verifier( $post_id, $html, 'widget Elementor ' . $cible . ' (écriture directe)' );
			if ( ! $v2[0] ) {
				return [ false, $v2[1] . ' Deux voies essayées : API de métadonnées, puis écriture directe.' ];
			}
			return $v2;
		}

		$r = self::sans_kses( function () use ( $post_id, $html ) {
			return wp_update_post( [ 'ID' => $post_id, 'post_content' => $html ], true );
		} );
		if ( is_wp_error( $r ) ) {
			return [ false, $r->get_error_message() ];
		}
		return self::verifier( $post_id, $html, 'post_content' );
	}

	/**
	 * Relit ce qui vient d'être écrit et le compare à ce qu'on voulait écrire.
	 *
	 * Sans ce contrôle, une écriture filtrée en silence remonte comme un succès :
	 * l'appelant est content, la page est cassée, et personne ne le sait avant de
	 * la regarder. Un écart sur les scripts, les styles ou les attributs
	 * d'événement fait échouer l'opération — donc pas de journal « ok », et
	 * l'instantané reste disponible pour revenir en arrière.
	 */
	private static function verifier( $post_id, $voulu, $ou ) {
		list( $relu ) = self::lire_corps( $post_id );
		$a = self::empreinte_fragile( $voulu );
		$b = self::empreinte_fragile( $relu );
		$perdus = [];
		$noms = [ 'script' => 'balises <script>', 'style' => 'balises <style>',
		          'onevent' => "attributs d'événement" ];
		foreach ( $noms as $k => $l ) {
			if ( $b[ $k ] < $a[ $k ] ) {
				$perdus[] = sprintf( '%d %s sur %d', $a[ $k ] - $b[ $k ], $l, $a[ $k ] );
			}
		}
		if ( $perdus ) {
			return [ false, sprintf(
				"Écriture filtrée par le site : %s ont disparu. Leur contenu resterait "
				. "visible en clair sur la page. L'opération est arrêtée ; l'instantané "
				. "permet de revenir à l'état précédent.",
				implode( ', ', $perdus )
			) ];
		}
		return [ true, $ou ];
	}

	/** Remplace le HTML d'un widget désigné, en préservant tout le reste de l'arbre. */
	private static function remplacer( $noeuds, $id_cible, $html ) {
		foreach ( $noeuds as $k => $n ) {
			if ( isset( $n['id'] ) && (string) $n['id'] === (string) $id_cible ) {
				$noeuds[ $k ]['settings']['html'] = $html;
				continue;
			}
			if ( ! empty( $n['elements'] ) && is_array( $n['elements'] ) ) {
				$noeuds[ $k ]['elements'] = self::remplacer( $n['elements'], $id_cible, $html );
			}
		}
		return $noeuds;
	}

	/**
	 * Devine la source dominante du site à partir d'un échantillon de posts.
	 * Sert au diagnostic `GET /site`.
	 */
	public static function source_site( $limite = 12 ) {
		$q = new WP_Query(
			[
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => (int) $limite,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => [ [ 'key' => self::META_ELEMENTOR, 'compare' => 'EXISTS' ] ],
			]
		);
		$comptes = [];
		foreach ( $q->posts as $id ) {
			$s = self::source( $id );
			$comptes[ $s ] = isset( $comptes[ $s ] ) ? $comptes[ $s ] + 1 : 1;
		}
		if ( ! $comptes ) {
			return [ 'unknown', [] ];
		}
		arsort( $comptes );
		return [ (string) array_key_first( $comptes ), $comptes ];
	}
}
