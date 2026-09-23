<?php
/**
 * External URL handling for imported posts.
 *
 * @package RSS_Feed_Importer
 */

defined( 'ABSPATH' ) || exit;

final class RSS_Feed_Importer_Frontend {
	public function __construct() {
		add_filter( 'post_link', array( $this, 'filter_permalink' ), 10, 2 );
		add_filter( 'post_type_link', array( $this, 'filter_permalink' ), 10, 2 );
		add_filter( 'get_the_author_display_name', array( $this, 'filter_author_name' ), 10, 3 );
		add_filter( 'the_author', array( $this, 'filter_author_output' ) );
		add_action( 'template_redirect', array( $this, 'redirect_single' ) );
	}

	public function filter_permalink( $permalink, $post ) {
		$external_url = get_post_meta( $post->ID, '_external_source_url', true );
		return $external_url ? esc_url( $external_url ) : $permalink;
	}

	public function filter_author_name( $display_name, $user_id, $original_user_id ) {
		$feed_author = $this->get_current_feed_author();

		return $feed_author ? $feed_author : $display_name;
	}

	public function filter_author_output( $author ) {
		$feed_author = $this->get_current_feed_author();
		return $feed_author ? esc_html( $feed_author ) : $author;
	}

	private function get_current_feed_author() {
		$post_id = get_the_ID();
		if ( ! $post_id && isset( $GLOBALS['post']->ID ) ) {
			$post_id = (int) $GLOBALS['post']->ID;
		}
		if ( ! $post_id && get_queried_object_id() ) {
			$post_id = get_queried_object_id();
		}
		return $post_id ? get_post_meta( $post_id, '_rss_feed_author_name', true ) : '';
	}

	public function redirect_single() {
		if ( ! is_singular( 'post' ) || is_admin() ) {
			return;
		}

		$external_url = get_post_meta( get_queried_object_id(), '_external_source_url', true );
		$scheme = wp_parse_url( $external_url, PHP_URL_SCHEME );
		if ( ! $external_url || ! wp_http_validate_url( $external_url ) || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return;
		}

		wp_redirect( $external_url, 301 );
		exit;
	}
}