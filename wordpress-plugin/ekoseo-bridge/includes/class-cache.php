<?php
/**
 * Purge des caches.
 *
 * L'oubli le plus courant sur ce type d'intégration : la modification est bien
 * en base, et le front continue de servir l'ancienne version. Elementor garde
 * un CSS compilé par page ; il faut le vider AVANT le cache de page, sinon ce
 * dernier remet en cache une page encore construite avec l'ancien CSS.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Cache {

	public static function purger( $post_id = 0 ) {
		$faits = [];

		if ( class_exists( '\Elementor\Plugin' ) ) {
			$p = \Elementor\Plugin::$instance;
			if ( isset( $p->files_manager ) ) {
				$p->files_manager->clear_cache();
				$faits[] = 'elementor';
			}
		}

		if ( $post_id ) {
			clean_post_cache( $post_id );
			$faits[] = 'post_cache';
		}

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$faits[] = 'wp-rocket';
		}
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
			$faits[] = 'litespeed';
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$faits[] = 'wp-super-cache';
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$faits[] = 'w3-total-cache';
		}
		if ( class_exists( '\WpeCommon' ) && method_exists( '\WpeCommon', 'purge_varnish_cache' ) ) {
			\WpeCommon::purge_varnish_cache();
			$faits[] = 'wpengine';
		}

		if ( function_exists( 'opcache_reset' ) && ini_get( 'opcache.enable' ) ) {
			// Volontairement PAS appelé : réinitialiser l'opcache d'un site en
			// production provoque un pic de compilation. Le contenu vit en base,
			// pas dans l'opcache.
			$faits[] = 'opcache:ignoré';
		}

		return $faits;
	}

	public static function detecter() {
		$l = [];
		if ( function_exists( 'rocket_clean_domain' ) ) { $l[] = 'wp-rocket'; }
		if ( defined( 'LSCWP_V' ) ) { $l[] = 'litespeed'; }
		if ( function_exists( 'wp_cache_clear_cache' ) ) { $l[] = 'wp-super-cache'; }
		if ( function_exists( 'w3tc_flush_all' ) ) { $l[] = 'w3-total-cache'; }
		if ( class_exists( '\WpeCommon' ) ) { $l[] = 'wpengine'; }
		return $l;
	}
}
