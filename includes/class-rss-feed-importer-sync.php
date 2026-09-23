<?php
/**
 * Feed retrieval and post import.
 *
 * @package RSS_Feed_Importer
 */

defined( 'ABSPATH' ) || exit;

final class RSS_Feed_Importer_Sync {
	public function __construct() {
		add_action( RSS_Feed_Importer::CRON_HOOK, array( $this, 'sync_feeds' ) );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	public function sync_feeds() {
		$feeds = get_option( RSS_Feed_Importer::OPTION_NAME, array() );
		$this->clear_log();
		$this->log( __( 'Scheduled sync started.', 'rss-feed-importer' ) );
		$result = array(
			'imported' => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'pruned'   => 0,
			'errors'   => 0,
		);

		foreach ( (array) $feeds as $feed ) {
			$feed = is_string( $feed ) ? array( 'url' => $feed, 'enabled' => 1 ) : $feed;
			if ( empty( $feed['url'] ) || ( isset( $feed['enabled'] ) && ! $feed['enabled'] ) ) {
				continue;
			}
			$this->log( sprintf( __( 'Fetching %s...', 'rss-feed-importer' ), $this->get_feed_label( $feed ) ) );
			$feed_result = $this->sync_feed( $feed );
			$result['imported'] += $feed_result['imported'];
			$result['updated']  += $feed_result['updated'];
			$result['skipped']  += $feed_result['skipped'];
			$result['pruned']   += $feed_result['pruned'];
			$result['errors']   += $feed_result['errors'];
		}

		$result['time'] = current_time( 'mysql' );
		update_option( RSS_Feed_Importer::LAST_SYNC_OPTION, $result, false );
		$this->log( sprintf( __( 'Sync finished. Imported: %1$d, updated: %2$d, skipped: %3$d, pruned: %4$d, errors: %5$d.', 'rss-feed-importer' ), $result['imported'], $result['updated'], $result['skipped'], $result['pruned'], $result['errors'] ) );
		return $result;
	}

	public function start_async_sync() {
		$feeds = array_values( (array) get_option( RSS_Feed_Importer::OPTION_NAME, array() ) );
		$this->clear_log();
		$this->log( __( 'Manual sync queued.', 'rss-feed-importer' ) );
		update_option(
			RSS_Feed_Importer::SYNC_STATE_OPTION,
			array(
				'feeds'   => $feeds,
				'index'   => 0,
				'result'  => array( 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'pruned' => 0, 'errors' => 0 ),
				'started' => current_time( 'mysql' ),
			),
			false
		);
		return array( 'started' => true );
	}

	public function process_async_step() {
		$state = get_option( RSS_Feed_Importer::SYNC_STATE_OPTION, array() );
		if ( empty( $state['feeds'] ) ) {
			return array( 'complete' => true, 'result' => get_option( RSS_Feed_Importer::LAST_SYNC_OPTION, array() ) );
		}
		if ( $state['index'] >= count( $state['feeds'] ) ) {
			$state['result']['time'] = current_time( 'mysql' );
			update_option( RSS_Feed_Importer::LAST_SYNC_OPTION, $state['result'], false );
			delete_option( RSS_Feed_Importer::SYNC_STATE_OPTION );
			$this->log( sprintf( __( 'Sync finished. Imported: %1$d, updated: %2$d, skipped: %3$d, pruned: %4$d, errors: %5$d.', 'rss-feed-importer' ), $state['result']['imported'], $state['result']['updated'], $state['result']['skipped'], $state['result']['pruned'], $state['result']['errors'] ) );
			return array( 'complete' => true, 'result' => $state['result'] );
		}

		$feed = $state['feeds'][ $state['index']++ ];
		if ( is_string( $feed ) ) {
			$feed = array( 'url' => $feed, 'enabled' => 1 );
		}
		if ( empty( $feed['url'] ) || ( isset( $feed['enabled'] ) && ! $feed['enabled'] ) ) {
			update_option( RSS_Feed_Importer::SYNC_STATE_OPTION, $state, false );
			return array( 'complete' => false, 'result' => $state['result'] );
		}

		$this->log( sprintf( __( 'Fetching %s...', 'rss-feed-importer' ), $this->get_feed_label( $feed ) ) );
		$feed_result = $this->sync_feed( $feed );
		foreach ( array( 'imported', 'updated', 'skipped', 'pruned', 'errors' ) as $key ) {
			$state['result'][ $key ] += $feed_result[ $key ];
		}
		update_option( RSS_Feed_Importer::SYNC_STATE_OPTION, $state, false );
		$this->log( sprintf( __( '%1$s done: %2$d imported, %3$d updated, %4$d skipped, %5$d pruned, %6$d errors.', 'rss-feed-importer' ), $this->get_feed_label( $feed ), $feed_result['imported'], $feed_result['updated'], $feed_result['skipped'], $feed_result['pruned'], $feed_result['errors'] ) );
		return array( 'complete' => false, 'result' => $state['result'] );
	}

	public function get_logs() {
		return (array) get_option( RSS_Feed_Importer::LOG_OPTION, array() );
	}

	public function preview_feed( $url ) {
		$response = wp_remote_get( $url, array( 'timeout' => 20, 'redirection' => 3, 'user-agent' => 'RSS Feed Importer/' . RSS_FEED_IMPORTER_VERSION ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'feed_request_failed', __( 'The feed could not be fetched.', 'rss-feed-importer' ) );
		}
		$xml = simplexml_load_string( wp_remote_retrieve_body( $response ), 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		if ( false === $xml ) {
			return new WP_Error( 'feed_parse_failed', __( 'The feed XML could not be parsed.', 'rss-feed-importer' ) );
		}
		$items = isset( $xml->channel->item ) ? $xml->channel->item : $xml->entry;
		$preview = array();
		foreach ( $items as $item ) {
			$excerpt = (string) ( $item->description ?: $item->summary );
			$preview[] = array(
				'title'   => sanitize_text_field( (string) $item->title ),
				'excerpt' => wp_trim_words( wp_strip_all_tags( $excerpt ), 28 ),
				'date'    => $this->get_post_date( (string) ( $item->pubDate ?: $item->published ?: $item->updated ) ),
			);
			if ( count( $preview ) >= 5 ) {
				break;
			}
		}
		return array( 'count' => count( $items ), 'items' => $preview, 'title' => sanitize_text_field( (string) $xml->channel->title ) );
	}

	private function sync_feed( $feed ) {
		$result = array(
			'imported' => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'pruned'   => 0,
			'errors'   => 0,
		);
		$feed_url = esc_url_raw( $feed['url'] );
		$response = wp_remote_get(
			$feed_url,
			array(
				'timeout'     => 20,
				'redirection' => 3,
				'user-agent'  => 'RSS Feed Importer/' . RSS_FEED_IMPORTER_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$result['errors']++;
			return $result;
		}

		$xml = simplexml_load_string( wp_remote_retrieve_body( $response ), 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		if ( false === $xml ) {
			$result['errors']++;
			return $result;
		}

		$items = isset( $xml->channel->item ) ? $xml->channel->item : $xml->entry;
		$existing_posts = $this->get_existing_feed_posts( $feed_url );
		foreach ( $items as $item ) {
			$status = $this->import_item( $item, $feed, $xml, $existing_posts );
			if ( 'imported' === $status || 'updated' === $status || 'skipped' === $status || 'error' === $status ) {
				$result[ 'error' === $status ? 'errors' : $status ]++;
			}
		}
		if ( ! empty( $feed['retain_20'] ) ) {
			$result['pruned'] = $this->prune_old_posts( $feed_url );
		}
		return $result;
	}

	private function import_item( $item, $feed, $xml, $existing_posts ) {
		$title = sanitize_text_field( (string) $item->title );
		$link  = $this->sanitize_source_url( (string) ( $item->link['href'] ?? $item->link ) );
		$guid  = (string) $item->guid;
		$guid  = $guid ? $guid : $link;
		$date  = (string) ( $item->pubDate ?: $item->published ?: $item->updated );

		if ( ! $title || ! $guid || ! $link ) {
			return 'error';
		}

		$timestamp = $this->get_timestamp( $date );
		$import_from = ! empty( $feed['import_from'] ) ? $feed['import_from'] : '';
		if ( $import_from && ( ! $timestamp || $timestamp < strtotime( $import_from . ' 00:00:00' ) ) ) {
			return 'skipped';
		}

		$post_id = isset( $existing_posts[ $guid ] ) ? (int) $existing_posts[ $guid ] : 0;
		if ( ! $post_id ) {
			$existing = new WP_Query(
				array(
					'post_type'      => 'post',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'   => true,
					'meta_query'     => array(
						array(
							'key'   => '_rss_item_guid',
							'value' => sanitize_text_field( $guid ),
						),
					),
				)
			);
			$post_id = $existing->have_posts() ? (int) $existing->posts[0] : 0;
		}

		$content = (string) ( $item->children( 'content', true )->encoded ?: $item->description );
		$dc        = $item->children( 'http://purl.org/dc/elements/1.1/' );
		$feed_name = ! empty( $feed['name'] ) ? $feed['name'] : (string) $xml->channel->title;
		$author    = ! empty( $feed['author'] ) ? $feed['author'] : (string) $dc->creator;
		$category  = ! empty( $feed['category'] ) ? $this->get_category_id( $feed['category'] ) : 0;
		$image_url = $this->get_item_image_url( $item );
		$item_hash = md5( wp_json_encode( array( $title, $content, $date, $author, $category, $image_url, $feed['language'] ?? '' ) ) );
		if ( $post_id && get_post_meta( $post_id, '_rss_item_hash', true ) === $item_hash ) {
			return 'skipped';
		}
		$post_data = array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => wp_kses_post( $content ),
				'post_excerpt' => wp_trim_words( wp_strip_all_tags( $content ), 55 ),
				'post_date'    => $this->get_post_date( $date ),
				'post_category' => $category ? array( $category ) : array(),
		);
		$is_update = (bool) $post_id;
		$post_id = wp_insert_post( $is_update ? array_merge( $post_data, array( 'ID' => $post_id ) ) : $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return 'error';
		}
		if ( ! empty( $feed['language'] ) && function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $post_id, sanitize_key( $feed['language'] ) );
		}
		$this->set_featured_image( $post_id, $item, $title );
		wp_set_post_categories( $post_id, $category ? array( $category ) : array() );

		update_post_meta( $post_id, '_rss_item_guid', sanitize_text_field( $guid ) );
		update_post_meta( $post_id, '_external_source_url', esc_url_raw( $link ) );
		update_post_meta( $post_id, '_rss_feed_name', sanitize_text_field( $feed_name ) );
		update_post_meta( $post_id, '_rss_feed_author_name', sanitize_text_field( $author ) );
		update_post_meta( $post_id, '_rss_feed_url', $feed['url'] );
		update_post_meta( $post_id, '_rss_item_hash', $item_hash );
		if ( $category ) {
			update_post_meta( $post_id, '_rss_feed_category', sanitize_text_field( $feed['category'] ) );
		} else {
			delete_post_meta( $post_id, '_rss_feed_category' );
		}
		return $is_update ? 'updated' : 'imported';
	}

	private function get_existing_feed_posts( $feed_url ) {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'   => true,
				'meta_key'       => '_rss_feed_url',
				'meta_value'     => $feed_url,
			)
		);
		$existing = array();
		foreach ( $posts as $post_id ) {
			$guid = get_post_meta( $post_id, '_rss_item_guid', true );
			if ( $guid ) {
				$existing[ $guid ] = $post_id;
			}
		}
		return $existing;
	}

	private function prune_old_posts( $feed_url ) {
		$old_posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'offset'         => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'   => true,
				'meta_key'       => '_rss_feed_url',
				'meta_value'     => $feed_url,
			)
		);
		$deleted = 0;
		foreach ( $old_posts as $post_id ) {
			if ( $this->delete_post_with_media( $post_id ) ) {
				$deleted++;
			}
		}
		return $deleted;
	}

	private function delete_post_with_media( $post_id ) {
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( $thumbnail_id ) {
			wp_delete_attachment( $thumbnail_id, true );
		}
		return wp_delete_post( $post_id, true );
	}

	private function set_featured_image( $post_id, $item, $title ) {
		$image_url = $this->get_item_image_url( $item );
		if ( ! $image_url || $image_url === get_post_meta( $post_id, '_rss_image_url', true ) ) {
			return;
		}

		$attachment_id = media_sideload_image( $image_url, $post_id, $title, 'id' );
		if ( ! is_wp_error( $attachment_id ) && $attachment_id ) {
			set_post_thumbnail( $post_id, $attachment_id );
			update_post_meta( $post_id, '_rss_image_url', $image_url );
		}
	}

	private function get_item_image_url( $item ) {
		$image_url = isset( $item->enclosure['url'] ) ? (string) $item->enclosure['url'] : '';
		if ( ! $image_url ) {
			$media = $item->children( 'media', true );
			$image_url = isset( $media->content['url'] ) ? (string) $media->content['url'] : '';
		}
		return $this->sanitize_source_url( $image_url );
	}

	private function get_category_id( $category_name ) {
		if ( is_numeric( $category_name ) && absint( $category_name ) ) {
			$term = get_term( absint( $category_name ), 'category' );
			return $term && ! is_wp_error( $term ) ? (int) $term->term_id : 0;
		}

		$category_name = sanitize_text_field( $category_name );
		if ( ! $category_name ) {
			return 0;
		}

		$term = term_exists( $category_name, 'category' );
		if ( $term ) {
			return (int) ( is_array( $term ) ? $term['term_id'] : $term );
		}

		$term = wp_insert_term( $category_name, 'category' );
		return is_wp_error( $term ) ? 0 : (int) $term['term_id'];
	}

	private function clear_log() {
		update_option( RSS_Feed_Importer::LOG_OPTION, array(), false );
	}

	private function log( $message, $level = 'info' ) {
		$logs   = $this->get_logs();
		$logs[] = array(
			'time'    => current_time( 'mysql' ),
			'level'   => sanitize_key( $level ),
			'message' => wp_strip_all_tags( $message ),
		);
		update_option( RSS_Feed_Importer::LOG_OPTION, array_slice( $logs, -200 ), false );
	}

	private function get_feed_label( $feed ) {
		return ! empty( $feed['name'] ) ? sanitize_text_field( $feed['name'] ) : esc_url_raw( $feed['url'] );
	}

	private function get_post_date( $date ) {
		$timestamp = $this->get_timestamp( $date );
		return $timestamp ? wp_date( 'Y-m-d H:i:s', $timestamp ) : current_time( 'mysql' );
	}

	private function get_timestamp( $date ) {
		return $date ? strtotime( $date ) : false;
	}

	private function sanitize_source_url( $url ) {
		$url    = esc_url_raw( trim( $url ) );
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		return $url && wp_http_validate_url( $url ) && in_array( $scheme, array( 'http', 'https' ), true ) ? $url : '';
	}
}