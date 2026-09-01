<?php
/**
 * Garde géographique — refuse de publier un contenu qui parle d'ailleurs.
 *
 * Le 20/08/2026, 205 pages du groupe ont été publiées avec le corps d'un
 * gabarit non substitué : le `<head>` annonçait Arcueil ou Paris 15, le texte
 * parlait de Paris 13, de la Place d'Italie et de la Butte-aux-Cailles. Chaque
 * page se contredisait, et renforçait l'arrondissement du modèle au lieu du sien.
 *
 * Corriger le générateur ne suffit pas : n'importe quel outil, aujourd'hui ou
 * dans deux ans, peut refaire la même erreur. La garde est donc posée à la
 * DERNIÈRE porte — celle du site. Tant qu'elle est là, aucun contenu contaminé
 * ne peut entrer, quelle que soit sa provenance.
 *
 * Ce qu'elle refuse, et rien d'autre : une localité étrangère citée **au moins
 * trois fois** ET **au moins aussi souvent que la cible de la page**. C'est la
 * signature d'une substitution ratée, jamais celle d'une mention légitime — une
 * page du 15e qui dit « à la limite du 14e » le dit une ou deux fois, et parle
 * du 15e vingt fois. Un renvoi normal passe donc sans rien signaler.
 *
 * `force: true` passe outre, et la raison est journalisée : la garde protège,
 * elle ne prend pas le pouvoir.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Geo_Guard {

	/** Au-dessous, c'est une mention de voisinage, pas une contamination. */
	const PLANCHER = 3;

	/**
	 * Quartiers administratifs de Paris, par arrondissement. Fait public et
	 * stable (découpage de 1860). Sert à repérer un contenu qui décrit le
	 * terrain d'un autre arrondissement sans jamais le nommer.
	 */
	public static function quartiers() {
		return [
			1  => [ 'Saint-Germain-l\'Auxerrois', 'Les Halles', 'Palais-Royal', 'Place Vendôme' ],
			2  => [ 'Gaillon', 'Vivienne', 'Mail', 'Bonne-Nouvelle' ],
			3  => [ 'Arts-et-Métiers', 'Enfants-Rouges', 'Archives', 'Sainte-Avoye' ],
			4  => [ 'Saint-Merri', 'Saint-Gervais', 'Arsenal', 'Notre-Dame' ],
			5  => [ 'Saint-Victor', 'Jardin des Plantes', 'Val-de-Grâce', 'Sorbonne' ],
			6  => [ 'Monnaie', 'Odéon', 'Saint-Germain-des-Prés', 'Notre-Dame-des-Champs' ],
			7  => [ 'Saint-Thomas-d\'Aquin', 'Invalides', 'École-Militaire', 'Gros-Caillou' ],
			8  => [ 'Champs-Élysées', 'Faubourg-du-Roule', 'Madeleine', 'Europe' ],
			9  => [ 'Saint-Georges', 'Chaussée-d\'Antin', 'Faubourg-Montmartre', 'Rochechouart' ],
			10 => [ 'Saint-Vincent-de-Paul', 'Porte-Saint-Denis', 'Porte-Saint-Martin', 'Hôpital-Saint-Louis' ],
			11 => [ 'Folie-Méricourt', 'Saint-Ambroise', 'Roquette', 'Sainte-Marguerite' ],
			12 => [ 'Bel-Air', 'Picpus', 'Bercy', 'Quinze-Vingts' ],
			13 => [ 'Salpêtrière', 'Gare', 'Maison-Blanche', 'Croulebarbe', 'Butte-aux-Cailles', 'Place d\'Italie', 'Tolbiac', 'Gobelins', 'Bibliothèque' ],
			14 => [ 'Montparnasse', 'Parc-de-Montsouris', 'Petit-Montrouge', 'Plaisance', 'Denfert-Rochereau' ],
			15 => [ 'Saint-Lambert', 'Necker', 'Grenelle', 'Javel', 'Beaugrenelle', 'Convention', 'Vaugirard' ],
			16 => [ 'Auteuil', 'Muette', 'Porte-Dauphine', 'Chaillot', 'Passy', 'Trocadéro' ],
			17 => [ 'Ternes', 'Plaine-de-Monceaux', 'Batignolles', 'Épinettes', 'Villiers' ],
			18 => [ 'Grandes-Carrières', 'Clignancourt', 'Goutte-d\'Or', 'Chapelle', 'Montmartre' ],
			19 => [ 'La Villette', 'Pont-de-Flandre', 'Amérique', 'Combat', 'Buttes-Chaumont' ],
			20 => [ 'Belleville', 'Saint-Fargeau', 'Père-Lachaise', 'Charonne', 'Gambetta' ],
		];
	}

	/** Le texte visible, sans balises ni scripts. */
	public static function texte( $html ) {
		$h = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', (string) $html );
		$h = wp_strip_all_tags( (string) $h );
		return preg_replace( '/\s+/u', ' ', html_entity_decode( $h, ENT_QUOTES, 'UTF-8' ) );
	}

	/** Combien de fois chaque arrondissement est nommé dans ce texte. */
	public static function arrondissements( $texte ) {
		$occ = [];
		// « Paris 13 », « Paris 13e », « Paris 13ème », « 13e arrondissement »
		$motifs = [
			'/\bparis\s*[-–]?\s*(\d{1,2})\s*(?:e|er|ème|eme|è)?\b/iu',
			'/\b(\d{1,2})\s*(?:e|er|ème|eme|è)\s*arrondissement\b/iu',
			'/\b750(\d{2})\b/u',
		];
		foreach ( $motifs as $m ) {
			if ( preg_match_all( $m, $texte, $r ) ) {
				foreach ( $r[1] as $n ) {
					$n = (int) $n;
					if ( $n >= 1 && $n <= 20 ) {
						$occ[ $n ] = isset( $occ[ $n ] ) ? $occ[ $n ] + 1 : 1;
					}
				}
			}
		}
		return $occ;
	}

	/** Les quartiers cités, regroupés par arrondissement d'appartenance. */
	public static function quartiers_cites( $texte ) {
		$occ = [];
		foreach ( self::quartiers() as $arr => $noms ) {
			foreach ( $noms as $q ) {
				$n = preg_match_all( '/' . preg_quote( $q, '/' ) . '/iu', $texte );
				if ( $n ) {
					$occ[ $arr ] = ( isset( $occ[ $arr ] ) ? $occ[ $arr ] : 0 ) + $n;
				}
			}
		}
		return $occ;
	}

	/**
	 * Déduit la cible d'une page : un numéro d'arrondissement, ou un nom de
	 * commune. `$indice` est le slug ou le chemin de la page.
	 */
	public static function cible( $indice ) {
		$s = strtolower( (string) $indice );
		if ( preg_match( '/(?:^|[^0-9])(\d{1,2})\s*(?:eme|ème|er|e)(?:$|[^0-9a-z])/u', $s, $m ) ) {
			$n = (int) $m[1];
			if ( $n >= 1 && $n <= 20 ) {
				return [ 'type' => 'arrondissement', 'valeur' => $n, 'libelle' => 'Paris ' . $n ];
			}
		}
		$seg = array_values( array_filter( explode( '/', trim( $s, '/' ) ) ) );
		$der = $seg ? end( $seg ) : '';

		// « paris-9 », « paris-13 » : un arrondissement écrit sans ordinal.
		if ( preg_match( '/^paris[-\s]?(\d{1,2})$/', $der, $m ) ) {
			$n = (int) $m[1];
			if ( $n >= 1 && $n <= 20 ) {
				return [ 'type' => 'arrondissement', 'valeur' => $n, 'libelle' => 'Paris ' . $n ];
			}
		}

		$brut = $der;
		$der  = preg_replace( '/-\d{2,3}$/', '', $der );         // « arcueil-94 » → « arcueil »
		if ( '' === $der ) {
			return [ 'type' => 'inconnue', 'valeur' => null, 'libelle' => '' ];
		}

		/*
		 * v1.2.0 — un slug n'est une commune que si le CHEMIN le dit.
		 *
		 * « /tarif/tarif-de-demenagement-coffre-fort » donnait la « commune
		 * Tarif De Demenagement Coffre Fort » : la page en parle (c'est son
		 * sujet), la règle « zéro mention → cible fausse » de la 1.1.0 ne
		 * s'appliquait donc pas, et la garde refusait face aux vrais lieux
		 * du texte. Même sort pour « /demenagement-prestige », ou pour un
		 * corridor « /corridors/paris-brest » qui cite légitimement deux
		 * villes.
		 *
		 * Une commune ne se déduit du slug QUE quand :
		 *   a) le segment est lui-même un lieu structurant (région, ville
		 *      pivot du groupe) ;
		 *   b) le segment porte un code de département (« arcueil-94 ») ;
		 *   c) le segment parent est géographique — lieu structurant ou
		 *      département codé (« yvelines-78/chatou »).
		 * Tout le reste — page service, tarif, corridor — est « inconnue » :
		 * on ne juge pas la géographie d'une page dont le chemin ne déclare
		 * aucun lieu.
		 */
		// Un slug qui parle métier n'est jamais une commune, code postal ou pas
		// (« meilleur-demenageur-de-paris-75 » n'est pas la commune « Meilleur
		// Demenageur De Paris »).
		if ( preg_match( '/(?:^|-)(?:demenagements?|demenageurs?|garde|meubles?|tarifs?|prix|devis|stockage|transport|meilleurs?)(?:-|$)/', $brut ) ) {
			return [ 'type' => 'inconnue', 'valeur' => null, 'libelle' => '' ];
		}

		$structurants = [
			'paris', 'ile-de-france', 'lyon', 'rhone', 'auvergne-rhone-alpes',
			'var', 'provence-alpes-cote-d-azur', 'marseille', 'toulon',
		];
		$parent     = count( $seg ) >= 2 ? $seg[ count( $seg ) - 2 ] : '';
		$parent_geo = in_array( $parent, $structurants, true )
			|| (bool) preg_match( '/-\d{2,3}$/', $parent );
		$code_dept  = (bool) preg_match( '/-\d{2,3}$/', $brut );
		if ( ! in_array( $der, $structurants, true ) && ! $code_dept && ! $parent_geo ) {
			return [ 'type' => 'inconnue', 'valeur' => null, 'libelle' => '' ];
		}

		return [
			'type'    => 'commune',
			'valeur'  => $der,
			'libelle' => ucwords( str_replace( '-', ' ', $der ) ),
		];
	}

	/**
	 * Le verdict. Retourne toujours le relevé complet — c'est lui qui permet à
	 * l'appelant d'expliquer, pas seulement de refuser.
	 */
	public static function verifier( $html, $indice ) {
		$texte  = self::texte( $html );
		$cible  = self::cible( $indice );
		$arr    = self::arrondissements( $texte );
		$quart  = self::quartiers_cites( $texte );

		// Ce que la page dit d'elle-même.
		if ( 'arrondissement' === $cible['type'] ) {
			$propre = isset( $arr[ $cible['valeur'] ] ) ? $arr[ $cible['valeur'] ] : 0;
			$propre += isset( $quart[ $cible['valeur'] ] ) ? $quart[ $cible['valeur'] ] : 0;
		} elseif ( 'commune' === $cible['type'] ) {
			$propre = preg_match_all( '/\b' . self::motif_commune( $cible['valeur'] ) . '\b/iu', $texte );

			/*
			 * Un slug n'est pas une commune parce qu'il est en dernier.
			 * `/services/demenagement-bibliotheque` donnait la « commune
			 * Demenagement-Bibliotheque » : une page qui n'en parle JAMAIS,
			 * puisque ce lieu n'existe pas. La garde comparait alors les
			 * mentions d'un arrondissement réel à zéro, et refusait toute
			 * écriture — sur un envoi réel de 272 pages le 24/08/2026,
			 * environ 30 des 46 refus venaient de là.
			 *
			 * Zéro mention de sa propre cible signifie que la cible est
			 * fausse, pas que la page est contaminée. On ne juge pas la
			 * géographie d'une page dont on ne sait pas de quel lieu elle
			 * parle : le type retombe à `inconnue`, et rien ne bloque.
			 */
			if ( 0 === $propre ) {
				$cible = [ 'type' => 'inconnue', 'valeur' => null, 'libelle' => '' ];
			}
		} else {
			$propre = 0;
		}

		$intrus = [];
		foreach ( $arr as $n => $c ) {
			if ( 'arrondissement' === $cible['type'] && $n === $cible['valeur'] ) {
				continue;
			}
			$total = $c + ( isset( $quart[ $n ] ) ? $quart[ $n ] : 0 );
			if ( $total >= self::PLANCHER && $total >= $propre ) {
				$intrus[] = [
					'localite'  => 'Paris ' . $n,
					'mentions'  => $total,
					'nommee'    => $c,
					'quartiers' => isset( $quart[ $n ] ) ? $quart[ $n ] : 0,
				];
			}
		}
		// un quartier peut trahir un arrondissement jamais nommé
		foreach ( $quart as $n => $c ) {
			if ( 'arrondissement' === $cible['type'] && $n === $cible['valeur'] ) {
				continue;
			}
			if ( isset( $arr[ $n ] ) ) {
				continue;                       // déjà compté au-dessus
			}
			if ( $c >= self::PLANCHER && $c >= $propre ) {
				$intrus[] = [
					'localite'  => 'Paris ' . $n,
					'mentions'  => $c,
					'nommee'    => 0,
					'quartiers' => $c,
				];
			}
		}

		usort( $intrus, function ( $a, $b ) { return $b['mentions'] - $a['mentions']; } );

		return [
			'cible'            => $cible['libelle'],
			'cible_type'       => $cible['type'],
			'mentions_cible'   => (int) $propre,
			'intrus'           => $intrus,
			/*
			 * v1.3.1 — LE verdict manquant. Depuis la 1.1.0, une cible douteuse
			 * retombe à « inconnue »… mais ce champ-ci restait
			 * `! empty( $intrus )` : avec $propre à 0, le moindre lieu cité
			 * trois fois devenait intrus et la page était refusée quand même —
			 * « /tarif/…-studio-paris » a été refusée 8 fois APRÈS les
			 * correctifs de cible (constaté le 28/08/2026, plugin 1.3.0 en
			 * place). On ne juge pas la géographie d'une page dont le chemin
			 * ne déclare aucun lieu : type inconnu ⇒ jamais contaminée.
			 */
			'contamine'        => 'inconnue' !== $cible['type'] && ! empty( $intrus ),
			'arrondissements'  => $arr,
			'quartiers'        => $quart,
		];
	}

	/**
	 * Le motif d'un nom de commune, tolérant sur le séparateur.
	 *
	 * ⚠️ Ne PAS écrire `str_replace( '-', '[ -]', preg_quote( $slug ) )` : en PHP,
	 * `preg_quote` échappe le trait d'union, donc le remplacement opère sur `\-`
	 * et produit `\[ -]` — un motif qui cherche un crochet littéral et ne peut
	 * jamais correspondre. Toute commune à trait d'union comptait alors zéro
	 * mention d'elle-même, et la garde la refusait quel que soit son contenu.
	 * Constaté le 20/08/2026 sur Issy-les-Moulineaux.
	 *
	 * On découpe d'abord, on échappe chaque morceau, on rejoint ensuite.
	 */
	private static function motif_commune( $slug ) {
		$parts = array_filter( explode( '-', (string) $slug ), 'strlen' );
		$parts = array_map(
			function ( $x ) {
				return preg_quote( $x, '/' );
			},
			$parts
		);
		return implode( '[ -]', $parts );
	}

	/** Une phrase lisible pour l'utilisateur, pas un code d'erreur. */
	public static function message( array $v ) {
		if ( empty( $v['intrus'] ) ) {
			return '';
		}
		$i = $v['intrus'][0];
		return sprintf(
			'Ce contenu cite %s %d fois, contre %d mention%s de %s — sa propre cible. '
			. "C'est la signature d'une substitution de gabarit inachevée : le corps "
			. "de la page est resté celui du modèle. Publier en l'état ferait dire à "
			. "cette page qu'elle parle d'ailleurs.",
			$i['localite'],
			$i['mentions'],
			$v['mentions_cible'],
			$v['mentions_cible'] > 1 ? 's' : '',
			$v['cible'] ? $v['cible'] : 'sa localité'
		);
	}
}
