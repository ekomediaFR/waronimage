<?php
/**
 * Journal des opérations et snapshots.
 *
 * Toute écriture laisse deux traces : l'état AVANT (snapshot, pour revenir en
 * arrière) et le fait de l'écriture (audit, pour savoir qui a changé quoi et
 * pourquoi). L'idempotence se lit dans l'audit : un `operation_id` déjà traité
 * renvoie sa réponse d'origine au lieu de réappliquer.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Audit {

	public static function snapshot( $post_id, $operation_id, array $etat, $hash_before ) {
		global $wpdb;
		$wpdb->insert(
			EkoSEO_Installer::snapshots_table(),
			[
				'post_id'      => (int) $post_id,
				'operation_id' => (string) $operation_id,
				'payload'      => wp_json_encode( $etat, JSON_UNESCAPED_UNICODE ),
				'hash_before'  => (string) $hash_before,
				'created_at'   => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	public static function lire_snapshot( $id ) {
		global $wpdb;
		$t   = EkoSEO_Installer::snapshots_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) {
			return null;
		}
		$row['payload'] = json_decode( $row['payload'], true );
		return $row;
	}

	public static function journaliser( array $l ) {
		global $wpdb;
		$user = wp_get_current_user();
		$wpdb->insert(
			EkoSEO_Installer::audit_table(),
			[
				'post_id'            => isset( $l['post_id'] ) ? (int) $l['post_id'] : null,
				'operation_id'       => isset( $l['operation_id'] ) ? (string) $l['operation_id'] : null,
				'action'             => (string) $l['action'],
				'reason'             => isset( $l['reason'] ) ? (string) $l['reason'] : null,
				'recommendation_ids' => isset( $l['recommendation_ids'] ) ? wp_json_encode( $l['recommendation_ids'] ) : null,
				'changed_fields'     => isset( $l['changed_fields'] ) ? wp_json_encode( $l['changed_fields'] ) : null,
				'result'             => (string) $l['result'],
				'message'            => isset( $l['message'] ) ? (string) $l['message'] : null,
				'user_login'         => $user ? $user->user_login : null,
				'snapshot_id'        => isset( $l['snapshot_id'] ) ? (int) $l['snapshot_id'] : null,
				'response'           => isset( $l['response'] ) ? wp_json_encode( $l['response'], JSON_UNESCAPED_UNICODE ) : null,
				'created_at'         => current_time( 'mysql', true ),
			]
		);
		return (int) $wpdb->insert_id;
	}

	/** Une opération déjà appliquée : on renvoie sa réponse, on ne rejoue pas. */
	public static function deja_traitee( $operation_id ) {
		global $wpdb;
		$t   = EkoSEO_Installer::audit_table();
		$row = $wpdb->get_row( // phpcs:ignore
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE operation_id = %s AND result = 'ok'
				 AND action IN ('update','create','rollback','import','delete','untrash','menu','redirect') ORDER BY id ASC LIMIT 1",
				(string) $operation_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$row['response'] = json_decode( (string) $row['response'], true );
		return $row;
	}

	public static function lister( $post_id = 0, $limit = 100 ) {
		global $wpdb;
		$t = EkoSEO_Installer::audit_table();
		$limit = max( 1, min( 500, (int) $limit ) );
		if ( $post_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE post_id = %d ORDER BY id DESC LIMIT %d", (int) $post_id, $limit ), ARRAY_A ); // phpcs:ignore
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore
		}
		foreach ( $rows as &$r ) {
			$r['recommendation_ids'] = json_decode( (string) $r['recommendation_ids'], true );
			$r['changed_fields']     = json_decode( (string) $r['changed_fields'], true );
			unset( $r['response'] );
		}
		return $rows;
	}
}
