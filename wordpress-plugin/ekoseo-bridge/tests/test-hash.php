<?php
/**
 * Tests du hash canonique — les deux propriétés qui font tenir le système.
 * Exécution : php tests/test-hash.php
 */

define( 'ABSPATH', __DIR__ );
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); }
}
require_once __DIR__ . '/../includes/class-hash.php';

$echecs = 0;
function verifier( $nom, $condition ) {
	global $echecs;
	if ( $condition ) {
		echo "  ok    {$nom}\n";
	} else {
		echo "  ÉCHEC {$nom}\n";
		$echecs++;
	}
}

$base = [
	'title'  => 'Déménagement Paris 15',
	'slug'   => '15eme',
	'status' => 'publish',
	'parent' => 'paris',
	'seo'    => [ 'title' => 'T', 'metadesc' => 'D', 'focus_kw' => 'k',
	              'synonyms' => [ 'a', 'b' ], 'extra_kw' => [] ],
	'meta'   => [ 'hero_subtitle' => 'Sous-titre' ],
	'html'   => "<section id=\"a\">  Texte   avec   espaces </section>",
];

$h1 = EkoSEO_Hash::calculer( $base );
$h2 = EkoSEO_Hash::calculer( $base );
verifier( 'stable : deux calculs identiques donnent le même hash', $h1 === $h2 );

$ordre = $base;
$ordre['seo'] = array_reverse( $base['seo'], true );
verifier( "stable : l'ordre des clés SEO n'influe pas",
	EkoSEO_Hash::calculer( $ordre ) === $h1 );

$espaces = $base;
$espaces['html'] = "<section id=\"a\">\n\n\tTexte avec espaces\n</section>";
verifier( "stable : l'indentation du HTML n'influe pas",
	EkoSEO_Hash::calculer( $espaces ) === $h1 );

$mod = $base; $mod['seo']['metadesc'] = 'Autre description';
verifier( 'sensible : changer la meta description change le hash',
	EkoSEO_Hash::calculer( $mod ) !== $h1 );

$mod2 = $base; $mod2['title'] = 'Déménagement Paris 13';
verifier( 'sensible : changer le titre change le hash',
	EkoSEO_Hash::calculer( $mod2 ) !== $h1 );

$mod3 = $base; $mod3['html'] = '<section id="a">Texte différent</section>';
verifier( 'sensible : changer le corps change le hash',
	EkoSEO_Hash::calculer( $mod3 ) !== $h1 );

$mod4 = $base; $mod4['seo']['synonyms'] = [ 'b', 'a' ];
verifier( "sensible : l'ordre d'une liste compte (il porte du sens)",
	EkoSEO_Hash::calculer( $mod4 ) !== $h1 );

$vide = $base; $vide['meta']['hero_subtitle'] = null;
$vide2 = $base; $vide2['meta']['hero_subtitle'] = '';
verifier( 'null et chaîne vide sont équivalents',
	EkoSEO_Hash::calculer( $vide ) === EkoSEO_Hash::calculer( $vide2 ) );

verifier( 'le hash porte son préfixe', 0 === strpos( $h1, 'sha256:' ) );

echo $echecs ? "\n{$echecs} échec(s)\n" : "\nTous les tests passent.\n";
exit( $echecs ? 1 : 0 );
