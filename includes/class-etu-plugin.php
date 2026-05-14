<?php
/**
 * Main plugin wiring for editor UI and REST conversion endpoint.
 *
 * @package EnfoldToUCLA
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETU_Plugin {

	/**
	 * @var ETU_Layout_Converter
	 */
	private $converter;

	/**
	 * @return void
	 */
	public static function init() {
		$plugin = new self( new ETU_Layout_Converter( new ETU_Layout_Parser() ) );
		$plugin->register_hooks();
	}

	/**
	 * @param ETU_Layout_Converter $converter Converter service.
	 */
	public function __construct( ETU_Layout_Converter $converter ) {
		$this->converter = $converter;
	}

	/**
	 * @return void
	 */
	private function register_hooks() {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * @return void
	 */
	public function enqueue_editor_assets() {
		$asset_file = ETU_PLUGIN_DIR . 'assets/editor.js';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		wp_enqueue_script(
			'etu-editor',
			ETU_PLUGIN_URL . 'assets/editor.js',
			array( 'wp-api-fetch', 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-data', 'wp-element', 'wp-edit-post', 'wp-i18n', 'wp-plugins' ),
			ETU_PLUGIN_VERSION,
			true
		);

		wp_localize_script(
			'etu-editor',
			'etuConfig',
			array(
				'route' => '/enfold-to-ucla/v1/convert-layout',
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			'enfold-to-ucla/v1',
			'/convert-layout',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_convert_layout' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'content' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function rest_convert_layout( WP_REST_Request $request ) {
		$content = (string) $request->get_param( 'content' );
		$result  = $this->converter->convert( $content );

		return new WP_REST_Response( $result, 200 );
	}
}
