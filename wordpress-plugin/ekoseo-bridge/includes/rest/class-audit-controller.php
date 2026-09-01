<?php
/**
 * GET /audit — le journal, pour que JUPITER puisse montrer qui a changé quoi.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Audit_Controller extends EkoSEO_Controller_Base {

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/audit',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'permissions' ],
				'args'                => [
					'post_id' => [ 'default' => 0, 'type' => 'integer' ],
					'limit'   => [ 'default' => 100, 'type' => 'integer' ],
				],
			]
		);
	}

	public function get_items( $req ) {
		return rest_ensure_response(
			EkoSEO_Audit::lister( (int) $req->get_param( 'post_id' ), (int) $req->get_param( 'limit' ) )
		);
	}
}
