<?php
/**
 * Plugin bootstrap and lifecycle hooks.
 *
 * @package RSS_Feed_Importer
 */

defined( 'ABSPATH' ) || exit;

final class RSS_Feed_Importer {
	const CRON_HOOK = 'rss_feed_importer_hourly_sync';
	const OPTION_NAME = 'rss_feed_importer_feeds';
	const IMPORT_FROM_OPTION = 'rss_feed_importer_import_from';
	const LAST_SYNC_OPTION = 'rss_feed_importer_last_sync';
	const LOG_OPTION = 'rss_feed_importer_sync_log';
	const SYNC_STATE_OPTION = 'rss_feed_importer_sync_state';

	public static function init() {
		self::migrate_legacy_import_from();
		new RSS_Feed_Importer_Admin();
		self::$sync = new RSS_Feed_Importer_Sync();
		new RSS_Feed_Importer_Frontend();
	}

	private static function migrate_legacy_import_from() {
		$legacy_value = get_option( self::IMPORT_FROM_OPTION, false );
		if ( false === $legacy_value ) {
			return;
		}
		if ( $legacy_value ) {
			$feeds   = (array) get_option( self::OPTION_NAME, array() );
			$changed = false;
			foreach ( $feeds as &$feed ) {
				if ( is_string( $feed ) ) {
					$feed = array( 'url' => $feed );
				}
				if ( empty( $feed['import_from'] ) ) {
					$feed['import_from'] = $legacy_value;
					$changed             = true;
				}
			}
			unset( $feed );
			if ( $changed ) {
				update_option( self::OPTION_NAME, $feeds );
			}
		}
		delete_option( self::IMPORT_FROM_OPTION );
	}

	private static $sync;

	public static function sync_now() {
		if ( ! self::$sync ) {
			self::$sync = new RSS_Feed_Importer_Sync();
		}

		return self::$sync->sync_feeds();
	}

	public static function start_async_sync() {
		return self::get_sync()->start_async_sync();
	}

	public static function process_async_step() {
		return self::get_sync()->process_async_step();
	}

	public static function get_logs() {
		return self::get_sync()->get_logs();
	}

	public static function preview_feed( $url ) {
		return self::get_sync()->preview_feed( $url );
	}

	private static function get_sync() {
		if ( ! self::$sync ) {
			self::$sync = new RSS_Feed_Importer_Sync();
		}
		return self::$sync;
	}

	public static function activate() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}