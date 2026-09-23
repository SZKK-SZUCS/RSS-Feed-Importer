<?php
/**
 * Plugin Name: RSS Feed Importer
 * Description: Imports configured RSS feeds as standard WordPress posts and routes them to their original source URLs.
 * Version: 1.1.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Szurofka Márton, MFÜI
 * License: GPL-2.0-or-later
 * Text Domain: rss-feed-importer
 *
 * @package RSS_Feed_Importer
 */

defined( 'ABSPATH' ) || exit;

function rss_feed_importer_translate( $translation, $text, $domain ) {
	if ( 'rss-feed-importer' !== $domain ) {
		return $translation;
	}
	$locale   = is_admin() && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
	$language = is_admin() ? substr( $locale, 0, 2 ) : ( function_exists( 'pll_current_language' ) ? pll_current_language() : substr( $locale, 0, 2 ) );
	if ( 'hu' !== $language ) {
		return $translation;
	}
	$translations = array(
		'RSS Feed Importer' => 'RSS Feed Importáló',
		'Feed sources' => 'Feedforrások',
		'Configured feeds' => 'Beállított feedek',
		'Import articles from' => 'Cikkek importálása ettől',
		'Enabled' => 'Aktív',
		'Feed URL' => 'Feed URL',
		'Feed name' => 'Feed neve',
		'Author name' => 'Szerző neve',
		'Category' => 'Kategória',
		'Language' => 'Nyelv',
		'Add feed' => 'Feed hozzáadása',
		'Preview' => 'Előnézet',
		'Preview feed' => 'Feed előnézete',
		'Edit' => 'Szerkesztés',
		'Save feed' => 'Feed mentése',
		'Close' => 'Bezárás',
		'Configure how this feed is imported and displayed.' => 'Állítsd be, hogyan importálja és jelenítse meg a rendszer ezt a feedet.',
		'Import this feed' => 'Feed importálása',
		'Remove feed profile' => 'Feedprofil törlése',
		'Delete imported posts only' => 'Csak az importált posztok törlése',
		'No category' => 'Nincs kategória',
		'Do not assign' => 'Nincs hozzárendelés',
		'Create a WordPress category first.' => 'Először hozz létre egy WordPress-kategóriát.',
		'Install and activate Polylang to assign post languages.' => 'A bejegyzésnyelvekhez telepítsd és aktiváld a Polylangot.',
		'RSS CONTROL CENTER' => 'RSS VEZÉRLŐKÖZPONT',
		'Bring external news into your native WordPress post flow with predictable categories and source links.' => 'Külső hírek importálása natív WordPress-posztként, kiszámítható kategóriákkal és forráshivatkozásokkal.',
		'Active feeds' => 'Aktív feedek',
		'Published posts' => 'Publikált posztok',
		'Imported posts' => 'Importált posztok',
		'Keep only the 20 newest imported posts' => 'Csak a 20 legújabb importált poszt megtartása',
		'Settings' => 'Beállítások',
		'Last sync' => 'Utolsó szinkron',
		'Run log' => 'Futási napló',
		'Live progress from the current manual or scheduled run.' => 'Az aktuális kézi vagy ütemezett futás élő állapota.',
		'Sync now' => 'Szinkronizálás most',
		'Ready to refresh?' => 'Készen áll a frissítésre?',
		'Run all active feeds now. New items are imported, changed items are updated, unchanged items are skipped automatically.' => 'Az összes aktív feed azonnali futtatása. Az új elemek importálódnak, a módosultak frissülnek, a változatlanok automatikusan kimaradnak.',
		'Next scheduled sync: %1$s (%2$s)' => 'Következő ütemezett szinkron: %1$s (%2$s)',
		'Next scheduled sync is not available yet.' => 'A következő ütemezett szinkron még nem érhető el.',
		'Feed sources' => 'Feedforrások',
		'Control naming, authors and categories independently for every source.' => 'A név, szerző és kategória forrásonként külön szabályozható.',
		'The feed name, author and category are stored with each imported post. Empty feed name uses the RSS channel title; empty author uses dc:creator.' => 'A feed neve, szerzője és kategóriája minden importált poszton mentésre kerül. Üres feednévnél az RSS csatorna neve, üres szerzőnél a dc:creator mező használatos.',
		'Only articles published on or after this date will be imported for this feed. Leave empty to import all new articles.' => 'Csak az ezen a napon vagy később publikált cikkek importálódnak ennél a feednél. Hagyd üresen az összes új cikk importálásához.',
		'Clear date' => 'Dátum törlése',
		'Configured feeds' => 'Beállított feedek',
		'Import articles from' => 'Cikkek importálása ettől',
		'Active feeds' => 'Aktív feedek',
		'Scheduled sync started.' => 'Az ütemezett szinkron elindult.',
		'Manual sync queued.' => 'A kézi szinkron várólistára került.',
		'Fetching %s...' => '%s letöltése...',
		'Sync finished. Imported: %1$d, updated: %2$d, skipped: %3$d, pruned: %4$d, errors: %5$d.' => 'A szinkron elkészült. Új: %1$d, frissített: %2$d, kihagyott: %3$d, törölt: %4$d, hibás: %5$d.',
		'%1$s done: %2$d imported, %3$d updated, %4$d skipped, %5$d pruned, %6$d errors.' => '%1$s kész: új %2$d, frissített %3$d, kihagyott %4$d, törölt %5$d, hibás %6$d.',
		'Sync complete. Imported: %1$d, updated: %2$d, skipped: %3$d, pruned: %4$d, errors: %5$d.' => 'A szinkron elkészült. Új: %1$d, frissített: %2$d, kihagyott: %3$d, törölt: %4$d, hibás: %5$d.',
		'Starting sync...' => 'Szinkron indítása...',
		'Loading preview...' => 'Előnézet betöltése...',
		'Enter a feed URL first.' => 'Először adj meg egy feed URL-t.',
		'%d articles available' => '%d elérhető cikk',
		'Remove this feed profile?' => 'Törlöd ezt a feedprofilt?',
		'Also permanently delete posts imported from this feed?' => 'Véglegesen törlöd a feedből importált posztokat is?',
		'Permanently delete all imported posts from this feed?' => 'Véglegesen törlöd a feedből importált összes posztot?',
		'imported posts deleted.' => 'importált poszt törölve.',
		'Permission denied.' => 'Nincs jogosultságod ehhez a művelethez.',
		'The feed URL is missing.' => 'Hiányzik a feed URL-je.',
		'Please enter a valid feed URL.' => 'Adj meg érvényes feed URL-t.',
		'Unnamed feed' => 'Névtelen feed',
		'No URL configured' => 'Nincs URL beállítva',
		'Please enter a valid HTTP(S) feed URL.' => 'Adj meg érvényes HTTP(S) feed URL-t.',
		'The feed could not be fetched.' => 'A feed nem tölthető le.',
		'The feed XML could not be parsed.' => 'A feed XML nem dolgozható fel.',
	);
	return isset( $translations[ $text ] ) ? $translations[ $text ] : $translation;
}

add_filter( 'gettext_rss-feed-importer', 'rss_feed_importer_translate', 10, 3 );

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'rss-feed-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

define( 'RSS_FEED_IMPORTER_VERSION', '1.1.0' );
define( 'RSS_FEED_IMPORTER_FILE', __FILE__ );
define( 'RSS_FEED_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );

$rss_feed_importer_autoload = RSS_FEED_IMPORTER_DIR . 'vendor/autoload.php';
if ( file_exists( $rss_feed_importer_autoload ) ) {
	require_once $rss_feed_importer_autoload;
}

require_once RSS_FEED_IMPORTER_DIR . 'includes/class-rss-feed-importer-admin.php';
require_once RSS_FEED_IMPORTER_DIR . 'includes/class-rss-feed-importer-sync.php';
require_once RSS_FEED_IMPORTER_DIR . 'includes/class-rss-feed-importer-frontend.php';
require_once RSS_FEED_IMPORTER_DIR . 'includes/class-rss-feed-importer.php';

register_activation_hook( __FILE__, array( 'RSS_Feed_Importer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RSS_Feed_Importer', 'deactivate' ) );

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'options-general.php?page=rss-feed-importer' ) ),
			esc_html__( 'Settings', 'rss-feed-importer' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}
);

// Initialize the updater only when Composer supplied the PUC package.
if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
	$update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/SZKK-SZUCS/RSS-Feed-Importer',
		RSS_FEED_IMPORTER_FILE,
		'rss-feed-importer'
	);
	$update_checker->setBranch( 'main' );
	$update_checker->getVcsApi()->enableReleaseAssets( '/rss-feed-importer-.*\.zip$/i' );
}
RSS_Feed_Importer::init();