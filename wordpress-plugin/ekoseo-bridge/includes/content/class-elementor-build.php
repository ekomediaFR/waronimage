<?php
/**
 * Construit l'arbre Elementor — la structure qui fait réellement rendre la page.
 *
 * Elle vient du convertisseur Apps Script V3, qui tourne en production depuis
 * des mois : un conteneur portant le widget `template` du héros, puis un
 * conteneur portant le widget `html` avec tout le contenu. Ne pas en dévier :
 * c'est ce que le thème et le template de héros attendent.
 *
 * Deux cas, et la distinction est ce qui rend l'opération chirurgicale :
 *
 * — La page a DÉJÀ un arbre Elementor avec un widget HTML : on remplace le
 *   contenu de ce widget, et rien d'autre. Tous les autres blocs — sections
 *   ajoutées à la main, formulaires, avis — survivent intacts.
 * — La page n'a pas d'arbre (création, ou page classique) : on pose l'arbre
 *   canonique complet.
 *
 * Reconstruire l'arbre entier dans le premier cas effacerait sans le dire tout
 * ce qui a été ajouté depuis l'import initial.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Elementor_Build {

	/** Identifiant Elementor : 7 caractères hexadécimaux. */
	public static function id() {
		$c = 'abcdef0123456789';
		$s = '';
		for ( $i = 0; $i < 7; $i++ ) {
			$s .= $c[ wp_rand( 0, 15 ) ];
		}
		return $s;
	}

	private static function conteneur( array $enfants ) {
		return [
			'id'       => self::id(),
			'elType'   => 'container',
			'settings' => [
				'flex_direction' => 'column',
				'content_width'  => 'full',
				'padding'        => [ 'unit' => 'px', 'top' => '0', 'right' => '0',
				                      'bottom' => '0', 'left' => '0', 'isLinked' => false ],
			],
			'elements' => $enfants,
			'isInner'  => false,
		];
	}

	/** L'arbre canonique : héros optionnel, puis le contenu. */
	public static function arbre( $html, $hero_template_id = '' ) {
		$arbre = [];
		if ( '' !== (string) $hero_template_id ) {
			$arbre[] = self::conteneur( [ [
				'id'         => self::id(),
				'elType'     => 'widget',
				'settings'   => [ 'template_id' => (string) $hero_template_id ],
				'elements'   => [],
				'widgetType' => 'template',
			] ] );
		}
		$arbre[] = self::conteneur( [ [
			'id'         => self::id(),
			'elType'     => 'widget',
			'settings'   => [ 'html' => (string) $html ],
			'elements'   => [],
			'widgetType' => 'html',
		] ] );
		return $arbre;
	}

	/**
	 * Insère le template héros EN TÊTE d'un arbre existant s'il n'y est pas
	 * déjà — pour compléter les pages créées sans lui (31/08/2026). Idempotent :
	 * un héros présent (même template_id) laisse la page intacte.
	 */
	public static function inserer_hero( $post_id, $hero_template_id ) {
		$tpl = (string) $hero_template_id;
		if ( '' === $tpl ) {
			return [ false, 'aucun template demandé' ];
		}
		$data = EkoSEO_Elementor_IO::donnees( $post_id );
		if ( ! is_array( $data ) || ! $data ) {
			return [ false, 'arbre Elementor absent' ];
		}
		$present = false;
		EkoSEO_Elementor_IO::parcourir(
			$data,
			function ( $n ) use ( &$present, $tpl ) {
				if ( ! empty( $n['widgetType'] ) && 'template' === $n['widgetType']
					&& isset( $n['settings']['template_id'] )
					&& (string) $n['settings']['template_id'] === $tpl ) {
					$present = true;
				}
			}
		);
		if ( $present ) {
			return [ false, 'héros déjà présent' ];
		}
		array_unshift( $data, self::conteneur( [ [
			'id'         => self::id(),
			'elType'     => 'widget',
			'settings'   => [ 'template_id' => $tpl ],
			'elements'   => [],
			'widgetType' => 'template',
		] ] ) );
		EkoSEO_Elementor_IO::sans_kses(
			function () use ( $post_id, $data ) {
				update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
			}
		);
		delete_post_meta( $post_id, '_elementor_css' );
		return [ true, 'héros ' . $tpl . ' inséré en tête' ];
	}

	/**
	 * Le contenu d'un arbre Elementor sérialisé — le HTML de ses widgets `html`.
	 *
	 * Un WXR produit par le convertisseur porte le contenu à deux endroits :
	 * `content:encoded` et `_elementor_data`. Quand le premier est vide, on va
	 * chercher dans le second plutôt que de refuser un document parfaitement
	 * exploitable.
	 */
	public static function html_de_arbre( $json ) {
		$d = json_decode( (string) $json, true );
		if ( ! is_array( $d ) ) {
			$d = json_decode( stripslashes( (string) $json ), true );
		}
		if ( ! is_array( $d ) ) {
			return '';
		}
		$parts = [];
		$parcourir = function ( $noeuds ) use ( &$parcourir, &$parts ) {
			foreach ( $noeuds as $n ) {
				if ( ! empty( $n['widgetType'] ) && 'html' === $n['widgetType']
					&& ! empty( $n['settings']['html'] ) ) {
					$parts[] = (string) $n['settings']['html'];
				}
				if ( ! empty( $n['elements'] ) && is_array( $n['elements'] ) ) {
					$parcourir( $n['elements'] );
				}
			}
		};
		$parcourir( $d );
		return implode( "\n", $parts );
	}

	/**
	 * Pose l'arbre Elementor ENTIER, tel que le porte le WXR.
	 *
	 * C'est ce que fait l'importateur WordPress, et c'est la raison pour laquelle
	 * la chaîne manuelle fonctionne depuis des mois : il n'essaie pas de deviner
	 * quel widget porte quoi — il écrit `_elementor_data` en bloc, et Elementor
	 * rend ce qu'il trouve.
	 *
	 * `appliquer()` fait l'inverse : il ne touche qu'au widget HTML pour préserver
	 * les autres blocs. C'est plus prudent, mais ce n'est pas la même opération —
	 * et sur une page dont l'arbre est incohérent, ça ne donne pas le même
	 * résultat. Quand le document apporte un arbre complet, on le respecte.
	 *
	 * Trois voies successives, comme ailleurs : écriture normale avec la capacité
	 * accordée, écriture directe en base, puis aveu d'échec. Jamais de succès
	 * annoncé sans relecture.
	 */
	public static function poser_arbre( $post_id, $json, $html, $metas = [] ) {
		$verifier = function () use ( $post_id, $html ) {
			list( $relu ) = EkoSEO_Elementor_IO::lire_corps( $post_id );
			$a = EkoSEO_Elementor_IO::empreinte_fragile( $html );
			$b = EkoSEO_Elementor_IO::empreinte_fragile( $relu );
			return ( $b['script'] < $a['script'] || $b['style'] < $a['style'] )
				? [ max( 0, $a['script'] - $b['script'] ), max( 0, $a['style'] - $b['style'] ) ]
				: null;
		};

		EkoSEO_Elementor_IO::sans_kses(
			function () use ( $post_id, $json, $html, $metas ) {
				update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
				update_post_meta( $post_id, '_elementor_edit_mode',
					isset( $metas['_elementor_edit_mode'] ) ? $metas['_elementor_edit_mode'] : 'builder' );
				update_post_meta( $post_id, '_elementor_template_type',
					isset( $metas['_elementor_template_type'] ) ? $metas['_elementor_template_type'] : 'wp-post' );
				if ( isset( $metas['_elementor_version'] ) ) {
					update_post_meta( $post_id, '_elementor_version', $metas['_elementor_version'] );
				}
				// le contenu classique reste renseigné : Elementor désactivé un jour,
				// la page ne devient pas blanche
				wp_update_post( [ 'ID' => $post_id, 'post_content' => $html ] );
			}
		);
		delete_post_meta( $post_id, '_elementor_css' );

		$p = $verifier();
		if ( $p ) {
			EkoSEO_Elementor_IO::meta_directe( $post_id, '_elementor_data', $json );
			delete_post_meta( $post_id, '_elementor_css' );
			$p = $verifier();
		}
		if ( $p ) {
			return [ false, sprintf(
				'Écriture filtrée par le site : %d balises <script> et %d <style> ont disparu, '
				. "malgré l'écriture directe. Deux voies essayées.", $p[0], $p[1]
			) ];
		}
		return [ true, "arbre Elementor complet posé, comme le ferait l'importateur" ];
	}

	/**
	 * Pose le contenu là où il doit aller. Retourne [ ok, description ].
	 *
	 * `$hero_template_id` n'est utilisé qu'à la création de l'arbre : sur une page
	 * qui en a déjà un, on ne touche pas au héros existant.
	 */
	public static function appliquer( $post_id, $html, $hero_template_id = '', $version = '' ) {
		$src = EkoSEO_Elementor_IO::source( $post_id );

		if ( 'elementor_html_widget' === $src ) {
			list( $ok, $ou ) = EkoSEO_Elementor_IO::ecrire_corps( $post_id, $html );
			return [ $ok, $ok ? 'widget existant mis à jour (' . $ou . ')' : $ou ];
		}
		if ( 'jetengine_meta_html' === $src ) {
			list( $ok, $ou ) = EkoSEO_Elementor_IO::ecrire_corps( $post_id, $html );
			return [ $ok, $ok ? 'champ JetEngine mis à jour' : $ou ];
		}

		// Pas d'arbre exploitable : on pose la structure canonique.
		// Comme partout ailleurs, l'écriture se fait hors `wp_kses` — sans quoi
		// les <script> du contenu perdraient leur balise et s'afficheraient en
		// clair — et le résultat est relu pour s'en assurer.
		$arbre = self::arbre( $html, $hero_template_id );
		EkoSEO_Elementor_IO::sans_kses(
			function () use ( $post_id, $arbre, $html, $version ) {
				update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $arbre ) ) );
				update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
				update_post_meta( $post_id, '_elementor_template_type', 'wp-post' );
				update_post_meta( $post_id, '_elementor_version', $version ? $version : EkoSEO_Elementor_IO::version() );
				update_post_meta( $post_id, '_elementor_page_settings', [ 'hide_title' => 'yes' ] );
				// le contenu classique reste renseigné : si Elementor est désactivé
				// un jour, la page ne devient pas blanche
				wp_update_post( [ 'ID' => $post_id, 'post_content' => $html ] );
			}
		);
		delete_post_meta( $post_id, '_elementor_css' );

		$perdu = function () use ( $post_id, $html ) {
			list( $relu ) = EkoSEO_Elementor_IO::lire_corps( $post_id );
			$av = EkoSEO_Elementor_IO::empreinte_fragile( $html );
			$ap = EkoSEO_Elementor_IO::empreinte_fragile( $relu );
			return ( $ap['script'] < $av['script'] || $ap['style'] < $av['style'] )
				? [ max( 0, $av['script'] - $ap['script'] ), max( 0, $av['style'] - $ap['style'] ) ]
				: null;
		};
		$p = $perdu();
		if ( $p ) {
			// Nettoyé malgré la capacité accordée : on écrit la ligne directement,
			// puis on revérifie. Deux voies, et un aveu si aucune ne passe.
			EkoSEO_Elementor_IO::meta_directe( $post_id, '_elementor_data', wp_json_encode( $arbre ) );
			delete_post_meta( $post_id, '_elementor_css' );
			$p = $perdu();
		}
		if ( $p ) {
			return [ false, sprintf(
				'Écriture filtrée par le site : %d balises <script> et %d <style> ont disparu, '
				. "malgré l'écriture directe. Leur contenu s'afficherait en clair sur la page.",
				$p[0], $p[1]
			) ];
		}
		return [ true, '' === (string) $hero_template_id
			? 'arbre Elementor créé (contenu seul)'
			: 'arbre Elementor créé (héros ' . $hero_template_id . ' + contenu)' ];
	}
}
