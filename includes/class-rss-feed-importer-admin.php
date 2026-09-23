<?php
/**
 * Admin settings.
 *
 * @package RSS_Feed_Importer
 */

defined( 'ABSPATH' ) || exit;

final class RSS_Feed_Importer_Admin {
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_manual_sync' ) );
		add_action( 'admin_init', array( $this, 'handle_deleted_feeds' ) );
		add_action( 'admin_head', array( $this, 'render_admin_styles' ) );
		add_action( 'wp_ajax_rss_feed_importer_sync_start', array( $this, 'ajax_sync_start' ) );
		add_action( 'wp_ajax_rss_feed_importer_sync_step', array( $this, 'ajax_sync_step' ) );
		add_action( 'wp_ajax_rss_feed_importer_logs', array( $this, 'ajax_logs' ) );
		add_action( 'wp_ajax_rss_feed_importer_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_rss_feed_importer_delete_posts', array( $this, 'ajax_delete_posts' ) );
		add_action( 'wp_ajax_rss_feed_importer_save_feed', array( $this, 'ajax_save_feed' ) );
	}

	public function register_menu() {
		add_options_page(
			__( 'RSS Feed Importer', 'rss-feed-importer' ),
			__( 'RSS Feed Importer', 'rss-feed-importer' ),
			'manage_options',
			'rss-feed-importer',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'rss_feed_importer_settings',
			RSS_Feed_Importer::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_feeds' ),
				'default'           => array(),
			)
		);

		register_setting(
			'rss_feed_importer_settings',
			RSS_Feed_Importer::IMPORT_FROM_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_date' ),
				'default'           => '',
			)
		);

		add_settings_section(
			'rss_feed_importer_main',
			__( 'Feed sources', 'rss-feed-importer' ),
			'__return_false',
			'rss-feed-importer'
		);

		add_settings_field(
			'rss_feed_importer_feeds',
			__( 'Configured feeds', 'rss-feed-importer' ),
			array( $this, 'render_feeds_field' ),
			'rss-feed-importer',
			'rss_feed_importer_main'
		);

		add_settings_field(
			'rss_feed_importer_import_from',
			__( 'Import articles from', 'rss-feed-importer' ),
			array( $this, 'render_date_field' ),
			'rss-feed-importer',
			'rss_feed_importer_main'
		);
	}

	public function sanitize_feeds( $value ) {
		if ( ! is_array( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n/', (string) $value );
		}

		$feeds = array();

		foreach ( $value as $feed ) {
			if ( is_string( $feed ) ) {
				$feed = array( 'url' => $feed );
			}

			$url = isset( $feed['url'] ) ? esc_url_raw( trim( $feed['url'] ) ) : '';
			if ( ! $url || ! wp_http_validate_url( $url ) || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
				continue;
			}

			$category = isset( $feed['category'] ) ? absint( $feed['category'] ) : 0;
			$feeds[] = array(
				'url'      => $url,
				'name'     => isset( $feed['name'] ) ? sanitize_text_field( $feed['name'] ) : '',
				'author'   => isset( $feed['author'] ) ? sanitize_text_field( $feed['author'] ) : '',
				'category' => $category,
				'language' => isset( $feed['language'] ) ? sanitize_key( $feed['language'] ) : '',
				'enabled' => isset( $feed['enabled'] ) && '0' !== (string) $feed['enabled'] ? 1 : 0,
			);
		}

		return $feeds;
	}

	public function sanitize_date( $value ) {
		$value = sanitize_text_field( $value );
		$date  = DateTime::createFromFormat( 'Y-m-d', $value );

		return $date && $date->format( 'Y-m-d' ) === $value ? $value : '';
	}

	public function render_feeds_field() {
		$feeds = get_option( RSS_Feed_Importer::OPTION_NAME, array() );
		$feeds = $this->normalize_feeds( $feeds );
		$categories = get_categories( array( 'hide_empty' => false ) );
		$languages  = $this->get_polylang_languages();
		?>
		<div id="rss-feed-importer-deleted-feeds"></div>
		<div class="rss-feed-importer-feed-list" id="rss-feed-importer-feed-list">
			<?php foreach ( $feeds as $index => $feed ) : ?>
				<?php $this->render_feed_card( $index, $feed ); ?>
			<?php endforeach; ?>
		</div>
		<template class="rss-feed-importer-feed-card-template"><?php $this->render_feed_card( '__INDEX__', array( 'url' => '', 'name' => '', 'author' => '', 'category' => 0, 'language' => '', 'enabled' => 1 ) ); ?></template>
		<p><button type="button" class="button" id="rss-feed-importer-add-feed"><?php esc_html_e( 'Add feed', 'rss-feed-importer' ); ?></button></p>
		<p class="description"><?php esc_html_e( 'The feed name, author and category are stored with each imported post. Empty feed name uses the RSS channel title; empty author uses dc:creator.', 'rss-feed-importer' ); ?><?php if ( empty( $categories ) ) : ?> <?php esc_html_e( 'Create at least one WordPress category before assigning categories to feeds.', 'rss-feed-importer' ); ?><?php endif; ?></p>
		<div class="rss-feed-importer-modal" id="rss-feed-importer-modal" hidden>
			<div class="rss-feed-importer-modal-backdrop" data-rss-modal-close></div>
			<div class="rss-feed-importer-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="rss-feed-importer-modal-title">
				<button type="button" class="rss-feed-importer-modal-close" data-rss-modal-close aria-label="<?php echo esc_attr__( 'Close', 'rss-feed-importer' ); ?>">&times;</button>
				<h2 id="rss-feed-importer-modal-title"><?php esc_html_e( 'Edit feed', 'rss-feed-importer' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Configure how this feed is imported and displayed.', 'rss-feed-importer' ); ?></p>
				<div class="rss-feed-importer-modal-grid">
					<label><?php esc_html_e( 'Feed URL', 'rss-feed-importer' ); ?><input type="url" id="rss-modal-url" required></label>
					<label><?php esc_html_e( 'Feed name', 'rss-feed-importer' ); ?><input type="text" id="rss-modal-name"></label>
					<label><?php esc_html_e( 'Author name', 'rss-feed-importer' ); ?><input type="text" id="rss-modal-author"></label>
					<label><?php esc_html_e( 'Category', 'rss-feed-importer' ); ?><?php echo $this->get_category_select_html( 'rss_modal_category', 0, $categories ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<label><?php esc_html_e( 'Language', 'rss-feed-importer' ); ?><?php echo $this->get_language_select_html( 'rss_modal_language', '', $languages ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<label class="rss-modal-enabled"><input type="checkbox" id="rss-modal-enabled"> <?php esc_html_e( 'Import this feed', 'rss-feed-importer' ); ?></label>
				</div>
				<div class="rss-feed-importer-modal-actions"><button type="button" class="button" id="rss-modal-preview"><span class="dashicons dashicons-visibility"></span><?php esc_html_e( 'Preview feed', 'rss-feed-importer' ); ?></button><button type="button" class="button button-primary" id="rss-modal-save"><?php esc_html_e( 'Save feed', 'rss-feed-importer' ); ?></button></div>
				<div class="rss-feed-importer-preview" id="rss-modal-preview-result"></div>
			</div>
		</div>
		<script>
		(function () {
			const list = document.querySelector('#rss-feed-importer-feed-list'); const modal = document.querySelector('#rss-feed-importer-modal'); const add = document.querySelector('#rss-feed-importer-add-feed'); let activeCard = null; let nextIndex = list ? list.querySelectorAll('.rss-feed-importer-feed-card').length : 0;
			if (!list || !modal) return;
			const field = id => document.querySelector('#' + id); const hidden = (card, key) => card.querySelector('[data-feed-field="' + key + '"]');
			function openEditor(card) { activeCard = card; field('rss-modal-url').value = hidden(card, 'url').value; field('rss-modal-name').value = hidden(card, 'name').value; field('rss-modal-author').value = hidden(card, 'author').value; field('rss_modal_category').value = hidden(card, 'category').value; field('rss_modal_language').value = hidden(card, 'language').value; field('rss-modal-enabled').checked = hidden(card, 'enabled').value === '1'; field('rss-modal-preview-result').innerHTML = ''; modal.hidden = false; document.body.classList.add('rss-feed-importer-modal-open'); field('rss-modal-url').focus(); }
			function closeEditor() { modal.hidden = true; document.body.classList.remove('rss-feed-importer-modal-open'); activeCard = null; }
			function setHidden(card, key, value) { hidden(card, key).value = value; }
			function updateCard(card) { const title = card.querySelector('[data-feed-title]'); const url = card.querySelector('[data-feed-url]'); const meta = card.querySelector('[data-feed-meta]'); const category = card.querySelector('[data-feed-category-label]'); if (title) title.textContent = hidden(card, 'name').value || hidden(card, 'url').value || '<?php echo esc_js( __( 'Unnamed feed', 'rss-feed-importer' ) ); ?>'; if (url) url.textContent = hidden(card, 'url').value || '<?php echo esc_js( __( 'No URL configured', 'rss-feed-importer' ) ); ?>'; if (meta) meta.textContent = [hidden(card, 'author').value, category ? category.textContent : '', hidden(card, 'language').value].filter(Boolean).join(' · '); card.classList.toggle('is-disabled', hidden(card, 'enabled').value !== '1'); }
			function escapeHtml(value) { const div = document.createElement('div'); div.textContent = value || ''; return div.innerHTML; }
			function preview() { const url = field('rss-modal-url').value.trim(); const box = field('rss-modal-preview-result'); if (!url) { box.textContent = '<?php echo esc_js( __( 'Enter a feed URL first.', 'rss-feed-importer' ) ); ?>'; return; } box.textContent = '<?php echo esc_js( __( 'Loading preview...', 'rss-feed-importer' ) ); ?>'; fetch(ajaxurl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'rss_feed_importer_preview', nonce: '<?php echo esc_js( wp_create_nonce( 'rss_feed_importer_admin' ) ); ?>', url: url }) }).then(response => response.json()).then(response => { if (!response.success) throw new Error(response.data); box.innerHTML = '<strong>' + response.data.count + ' <?php echo esc_js( __( 'articles available', 'rss-feed-importer' ) ); ?></strong>' + response.data.items.map(item => '<article><b>' + escapeHtml(item.title) + '</b><small>' + escapeHtml(item.date) + '</small><p>' + escapeHtml(item.excerpt) + '</p></article>').join(''); }).catch(error => { box.textContent = error.message; }); }
			function saveFeed() { if (!field('rss-modal-url').checkValidity()) { field('rss-modal-url').reportValidity(); return; } const feed = { url: field('rss-modal-url').value.trim(), name: field('rss-modal-name').value.trim(), author: field('rss-modal-author').value.trim(), category: field('rss_modal_category').value, language: field('rss_modal_language').value, enabled: field('rss-modal-enabled').checked ? '1' : '0' }; const saveButton = field('rss-modal-save'); saveButton.disabled = true; const payload = new URLSearchParams({ action: 'rss_feed_importer_save_feed', nonce: '<?php echo esc_js( wp_create_nonce( 'rss_feed_importer_admin' ) ); ?>', index: activeCard.dataset.index, 'feed[url]': feed.url, 'feed[name]': feed.name, 'feed[author]': feed.author, 'feed[category]': feed.category, 'feed[language]': feed.language, 'feed[enabled]': feed.enabled }); fetch(ajaxurl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: payload }).then(response => response.json()).then(response => { if (!response.success) throw new Error(response.data); Object.keys(feed).forEach(key => setHidden(activeCard, key, feed[key])); const category = activeCard.querySelector('[data-feed-category-label]'); if (category) category.textContent = field('rss_modal_category').selectedOptions[0]?.text || '<?php echo esc_js( __( 'No category', 'rss-feed-importer' ) ); ?>'; updateCard(activeCard); closeEditor(); }).catch(error => window.alert(error.message)).finally(() => { saveButton.disabled = false; }); }
			add.addEventListener('click', function () { const template = document.querySelector('.rss-feed-importer-feed-card-template'); if (template) { const index = nextIndex++; const fragment = template.content.cloneNode(true); const card = fragment.firstElementChild; card.innerHTML = card.innerHTML.split('__INDEX__').join(index); card.dataset.index = index; list.appendChild(card); openEditor(card); } });
			list.addEventListener('click', function (event) { const edit = event.target.closest('[data-rss-edit]'); if (edit) openEditor(edit.closest('.rss-feed-importer-feed-card')); const remove = event.target.closest('[data-rss-remove]'); if (remove) { const card = remove.closest('.rss-feed-importer-feed-card'); const url = hidden(card, 'url').value.trim(); if (url && !window.confirm('<?php echo esc_js( __( 'Remove this feed profile?', 'rss-feed-importer' ) ); ?>')) return; if (url && window.confirm('<?php echo esc_js( __( 'Also permanently delete posts imported from this feed?', 'rss-feed-importer' ) ); ?>')) { fetch(ajaxurl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'rss_feed_importer_delete_posts', nonce: '<?php echo esc_js( wp_create_nonce( 'rss_feed_importer_admin' ) ); ?>', url: url }) }).catch(() => {}); } card.remove(); } });
			list.addEventListener('click', function (event) { const deleteButton = event.target.closest('[data-rss-delete-posts]'); if (!deleteButton) return; const card = deleteButton.closest('.rss-feed-importer-feed-card'); const url = hidden(card, 'url').value.trim(); if (!url || !window.confirm('<?php echo esc_js( __( 'Permanently delete all imported posts from this feed?', 'rss-feed-importer' ) ); ?>')) return; deleteButton.disabled = true; fetch(ajaxurl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'rss_feed_importer_delete_posts', nonce: '<?php echo esc_js( wp_create_nonce( 'rss_feed_importer_admin' ) ); ?>', url: url }) }).then(response => response.json()).then(response => { if (!response.success) throw new Error(response.data); window.alert(response.data.deleted + ' <?php echo esc_js( __( 'imported posts deleted.', 'rss-feed-importer' ) ); ?>'); }).catch(error => window.alert(error.message)).finally(() => { deleteButton.disabled = false; }); });
			modal.addEventListener('click', event => { if (event.target.hasAttribute('data-rss-modal-close')) closeEditor(); }); field('rss-modal-save').addEventListener('click', saveFeed); field('rss-modal-preview').addEventListener('click', preview); document.addEventListener('keydown', event => { if (event.key === 'Escape' && !modal.hidden) closeEditor(); });
			list.querySelectorAll('.rss-feed-importer-feed-card').forEach(updateCard);
		}());
		</script>
		<?php
	}

	private function render_feed_card( $index, $feed ) {
		$name = isset( $feed['name'] ) ? $feed['name'] : '';
		$author = isset( $feed['author'] ) ? $feed['author'] : '';
		$category = isset( $feed['category'] ) ? absint( $feed['category'] ) : 0;
		$language = isset( $feed['language'] ) ? $feed['language'] : '';
		$enabled = ! isset( $feed['enabled'] ) || $feed['enabled'];
		$category_term = $category ? get_term( $category, 'category' ) : false;
		$category_label = $category_term && ! is_wp_error( $category_term ) ? $category_term->name : __( 'No category', 'rss-feed-importer' );
		?>
		<div class="rss-feed-importer-feed-card <?php echo $enabled ? '' : 'is-disabled'; ?>" data-index="<?php echo esc_attr( $index ); ?>">
			<input type="hidden" data-feed-field="url" name="<?php echo esc_attr( RSS_Feed_Importer::OPTION_NAME ); ?>[<?php echo esc_attr( $index ); ?>][url]" value="<?php echo esc_attr( $feed['url'] ); ?>">
			<input type="hidden" data-feed-field="name" name="<?php echo esc_attr( RSS_Feed_Importer::OPTION_NAME ); ?>[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $name ); ?>">
			<input type="hidden" data-feed-field="author" name="<?php echo esc_attr( RSS_Feed_Importer::OPTION_NAME ); ?>[<?php echo esc_attr( $index ); ?>][author]" value="<?php echo esc_attr( $author ); ?>">
			<input type="hidden" data-feed-field="category" name="<?php echo esc_attr( RSS_Feed_Importer::OPTION_NAME ); ?>[<?php echo esc_attr( $index ); ?>][category]" value="<?php echo esc_attr( $category ); ?>">
			<input type="hidden" data-feed-field="language" name="<?php echo esc_attr( RSS_Feed_Importer::OPTION_NAME ); ?>[<?php echo esc_attr( $index ); ?>][language]" value="<?php echo esc_attr( $language ); ?>">
			<input type="hidden" data-feed-field="enabled" name="<?php echo esc_attr( RSS_Feed_Importer::OPTION_NAME ); ?>[<?php echo esc_attr( $index ); ?>][enabled]" value="<?php echo $enabled ? '1' : '0'; ?>">
			<div class="rss-feed-importer-feed-status"><span class="rss-feed-importer-status-dot"></span></div>
			<div class="rss-feed-importer-feed-main"><strong data-feed-title><?php echo esc_html( $name ? $name : $feed['url'] ); ?></strong><span data-feed-url><?php echo esc_html( $feed['url'] ); ?></span><small data-feed-meta><span data-feed-category-label><?php echo esc_html( $category_label ); ?></span><?php if ( $author ) : ?> · <?php echo esc_html( $author ); ?><?php endif; ?><?php if ( $language ) : ?> · <?php echo esc_html( strtoupper( $language ) ); ?><?php endif; ?></small></div>
			<div class="rss-feed-importer-feed-actions"><button type="button" class="button" data-rss-edit><span class="dashicons dashicons-edit"></span><?php esc_html_e( 'Edit', 'rss-feed-importer' ); ?></button><button type="button" class="rss-feed-importer-icon-button" data-rss-delete-posts title="<?php echo esc_attr__( 'Delete imported posts only', 'rss-feed-importer' ); ?>" aria-label="<?php echo esc_attr__( 'Delete imported posts only', 'rss-feed-importer' ); ?>"><span class="dashicons dashicons-archive"></span></button><button type="button" class="rss-feed-importer-icon-button" data-rss-remove title="<?php echo esc_attr__( 'Remove feed profile', 'rss-feed-importer' ); ?>" aria-label="<?php echo esc_attr__( 'Remove feed profile', 'rss-feed-importer' ); ?>"><span class="dashicons dashicons-trash"></span></button></div>
		</div>
		<?php
	}

	private function normalize_feeds( $feeds ) {
		$normalized = array();
		foreach ( (array) $feeds as $feed ) {
			$normalized[] = is_string( $feed ) ? array( 'url' => $feed, 'name' => '', 'author' => '', 'category' => 0, 'language' => '', 'enabled' => 1 ) : $feed;
		}
		return $normalized;
	}

	private function get_category_select_html( $index, $selected = 0, $categories = null ) {
		if ( null === $categories ) {
			$categories = get_categories( array( 'hide_empty' => false ) );
		}
		$name = RSS_Feed_Importer::OPTION_NAME . '[' . $index . '][category]';
		$html = '<select id="' . esc_attr( $index ) . '" class="rss-feed-importer-category" name="' . esc_attr( $name ) . '"' . ( empty( $categories ) ? ' disabled' : '' ) . '>';
		$html .= '<option value="0">' . esc_html__( 'No category', 'rss-feed-importer' ) . '</option>';
		foreach ( $categories as $category ) {
			$html .= sprintf( '<option value="%1$d"%2$s>%3$s</option>', (int) $category->term_id, selected( (int) $selected, (int) $category->term_id, false ), esc_html( $category->name ) );
		}
		$html .= '</select>';
		if ( empty( $categories ) ) {
			$html .= '<small class="rss-feed-importer-disabled-help">' . esc_html__( 'Create a WordPress category first.', 'rss-feed-importer' ) . '</small>';
		}
		return $html;
	}

	private function get_polylang_languages() {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return array();
		}
		$slugs = (array) pll_languages_list( array( 'fields' => 'slug' ) );
		$names = (array) pll_languages_list( array( 'fields' => 'name' ) );
		$languages = array();
		foreach ( $slugs as $index => $slug ) {
			$languages[] = (object) array( 'slug' => $slug, 'name' => isset( $names[ $index ] ) ? $names[ $index ] : $slug );
		}
		return $languages;
	}

	private function get_language_select_html( $index, $selected, $languages ) {
		$name = RSS_Feed_Importer::OPTION_NAME . '[' . $index . '][language]';
		$html = '<select id="' . esc_attr( $index ) . '" class="rss-feed-importer-language" name="' . esc_attr( $name ) . '"' . ( empty( $languages ) ? ' disabled' : '' ) . '>';
		$html .= '<option value="">' . esc_html__( 'Do not assign', 'rss-feed-importer' ) . '</option>';
		foreach ( $languages as $language ) {
			$html .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $language->slug ), selected( $selected, $language->slug, false ), esc_html( $language->name ) );
		}
		$html .= '</select>';
		if ( empty( $languages ) ) {
			$html .= '<small class="rss-feed-importer-disabled-help">' . esc_html__( 'Install and activate Polylang to assign post languages.', 'rss-feed-importer' ) . '</small>';
		}
		return $html;
	}

	public function render_date_field() {
		$value = get_option( RSS_Feed_Importer::IMPORT_FROM_OPTION, '' );
		?>
		<input type="date" name="<?php echo esc_attr( RSS_Feed_Importer::IMPORT_FROM_OPTION ); ?>" value="<?php echo esc_attr( $value ); ?>">
		<p class="description"><?php esc_html_e( 'Only articles published on or after this date will be imported. Leave empty to import all new articles.', 'rss-feed-importer' ); ?></p>
		<?php
	}

	public function handle_manual_sync() {
		if ( ! isset( $_POST['rss_feed_importer_sync_now'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'rss_feed_importer_sync_now' );
		$result = RSS_Feed_Importer::sync_now();
		$url = add_query_arg( 'rss_feed_importer_sync', rawurlencode( wp_json_encode( $result ) ), admin_url( 'options-general.php?page=rss-feed-importer' ) );
		wp_safe_redirect( $url );
		exit;
	}

	public function handle_deleted_feeds() {
		if ( empty( $_POST['rss_feed_importer_delete_urls'] ) || 'rss_feed_importer_settings' !== ( $_POST['option_page'] ?? '' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'rss_feed_importer_settings-options' );
		foreach ( (array) wp_unslash( $_POST['rss_feed_importer_delete_urls'] ) as $url ) {
			$this->delete_feed_posts( esc_url_raw( $url ) );
		}
	}

	public function ajax_sync_start() {
		$this->verify_ajax_request();
		wp_send_json_success( RSS_Feed_Importer::start_async_sync() );
	}

	public function ajax_sync_step() {
		$this->verify_ajax_request();
		wp_send_json_success( RSS_Feed_Importer::process_async_step() );
	}

	public function ajax_logs() {
		$this->verify_ajax_request();
		wp_send_json_success( RSS_Feed_Importer::get_logs() );
	}

	public function ajax_preview() {
		$this->verify_ajax_request();
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( ! $url || ! wp_http_validate_url( $url ) || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
			wp_send_json_error( __( 'Please enter a valid HTTP(S) feed URL.', 'rss-feed-importer' ), 400 );
		}
		$result = RSS_Feed_Importer::preview_feed( $url );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message(), 400 );
		}
		wp_send_json_success( $result );
	}

	public function ajax_delete_posts() {
		$this->verify_ajax_request();
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( ! $url ) {
			wp_send_json_error( __( 'The feed URL is missing.', 'rss-feed-importer' ), 400 );
		}
		wp_send_json_success( array( 'deleted' => $this->delete_feed_posts( $url ) ) );
	}

	public function ajax_save_feed() {
		$this->verify_ajax_request();
		$index = isset( $_POST['index'] ) ? sanitize_key( wp_unslash( $_POST['index'] ) ) : '';
		$feed  = isset( $_POST['feed'] ) && is_array( $_POST['feed'] ) ? wp_unslash( $_POST['feed'] ) : array();
		$feeds = $this->normalize_feeds( get_option( RSS_Feed_Importer::OPTION_NAME, array() ) );
		$clean = $this->sanitize_feeds( array( $feed ) );
		if ( '' === $index || empty( $clean ) ) {
			wp_send_json_error( __( 'Please enter a valid feed URL.', 'rss-feed-importer' ), 400 );
		}
		$feeds[ $index ] = $clean[0];
		update_option( RSS_Feed_Importer::OPTION_NAME, array_values( $feeds ) );
		wp_send_json_success( array( 'feed' => $clean[0] ) );
	}

	private function delete_feed_posts( $url ) {
		$meta_query = array(
			'relation' => 'OR',
			array(
				'key'     => '_rss_feed_url',
				'value'   => $url,
				'compare' => '=',
			),
		);
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $host ) {
			$meta_query[] = array(
				'key'     => '_external_source_url',
				'value'   => '%://' . $host . '/%',
				'compare' => 'LIKE',
			);
		}
		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'   => true,
				'meta_query'     => $meta_query,
			)
		);
		$count = 0;
		foreach ( (array) $query->posts as $post_id ) {
			if ( wp_delete_post( $post_id, true ) ) {
				$count++;
			}
		}
		return $count;
	}

	private function verify_ajax_request() {
		check_ajax_referer( 'rss_feed_importer_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'rss-feed-importer' ), 403 );
		}
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$feeds       = $this->normalize_feeds( get_option( RSS_Feed_Importer::OPTION_NAME, array() ) );
		$active_feeds = count( array_filter( $feeds, function ( $feed ) { return ! isset( $feed['enabled'] ) || $feed['enabled']; } ) );
		$published   = wp_count_posts( 'post' );
		$last_sync   = get_option( RSS_Feed_Importer::LAST_SYNC_OPTION, array() );
		$next_sync   = wp_next_scheduled( RSS_Feed_Importer::CRON_HOOK );
		?>
		<div class="wrap rss-feed-importer-wrap">
			<div class="rss-feed-importer-hero">
				<div>
					<span class="rss-feed-importer-eyebrow"><?php esc_html_e( 'RSS CONTROL CENTER', 'rss-feed-importer' ); ?></span>
					<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
					<p><?php esc_html_e( 'Bring external news into your native WordPress post flow with predictable categories and source links.', 'rss-feed-importer' ); ?></p>
				</div>
				<div class="rss-feed-importer-mark" aria-hidden="true">RSS</div>
			</div>
			<div class="rss-feed-importer-stats">
				<div class="rss-feed-importer-stat"><span class="dashicons dashicons-rss"></span><strong><?php echo esc_html( count( $feeds ) ); ?></strong><small><?php esc_html_e( 'Configured feeds', 'rss-feed-importer' ); ?></small></div>
				<div class="rss-feed-importer-stat"><span class="dashicons dashicons-yes-alt"></span><strong><?php echo esc_html( $active_feeds ); ?></strong><small><?php esc_html_e( 'Active feeds', 'rss-feed-importer' ); ?></small></div>
				<div class="rss-feed-importer-stat"><span class="dashicons dashicons-admin-post"></span><strong><?php echo esc_html( (int) $published->publish ); ?></strong><small><?php esc_html_e( 'Published posts', 'rss-feed-importer' ); ?></small></div>
				<div class="rss-feed-importer-stat"><span class="dashicons dashicons-update"></span><strong><?php echo ! empty( $last_sync['time'] ) ? esc_html( $last_sync['time'] ) : '&mdash;'; ?></strong><small><?php esc_html_e( 'Last sync', 'rss-feed-importer' ); ?></small></div>
			</div>
			<?php $this->render_sync_notice(); ?>
			<div class="rss-feed-importer-panel rss-feed-importer-action-panel">
				<div><h2><?php esc_html_e( 'Ready to refresh?', 'rss-feed-importer' ); ?></h2><p><?php esc_html_e( 'Run all active feeds now. New items are imported, changed items are updated, unchanged items are skipped automatically.', 'rss-feed-importer' ); ?></p><p class="rss-feed-importer-next-sync"><?php echo $next_sync ? esc_html( sprintf( __( 'Next scheduled sync: %1$s (%2$s)', 'rss-feed-importer' ), wp_date( 'Y-m-d H:i:s', $next_sync ), wp_timezone_string() ) ) : esc_html__( 'Next scheduled sync is not available yet.', 'rss-feed-importer' ); ?></p></div>
				<form method="post">
				<?php wp_nonce_field( 'rss_feed_importer_sync_now' ); ?>
				<button type="submit" id="rss-feed-importer-sync-now" name="rss_feed_importer_sync_now" class="button button-primary button-hero" data-nonce="<?php echo esc_attr( wp_create_nonce( 'rss_feed_importer_admin' ) ); ?>"><span class="dashicons dashicons-update"></span><?php esc_html_e( 'Sync now', 'rss-feed-importer' ); ?></button>
				</form>
			</div>
			<div class="rss-feed-importer-panel rss-feed-importer-log-panel">
				<div class="rss-feed-importer-panel-heading"><div><h2><?php esc_html_e( 'Run log', 'rss-feed-importer' ); ?></h2><p><?php esc_html_e( 'Live progress from the current manual or scheduled run.', 'rss-feed-importer' ); ?></p></div></div>
				<pre id="rss-feed-importer-log" aria-live="polite"><?php echo esc_html( $this->format_logs_for_display( (array) get_option( RSS_Feed_Importer::LOG_OPTION, array() ) ) ); ?></pre>
			</div>
			<div class="rss-feed-importer-panel">
				<div class="rss-feed-importer-panel-heading"><div><h2><?php esc_html_e( 'Feed sources', 'rss-feed-importer' ); ?></h2><p><?php esc_html_e( 'Control naming, authors and categories independently for every source.', 'rss-feed-importer' ); ?></p></div></div>
				<form action="options.php" method="post">
				<?php
				settings_fields( 'rss_feed_importer_settings' );
				do_settings_sections( 'rss-feed-importer' );
				submit_button();
				?>
				</form>
			</div>
		</div>
		<script>
		(function () {
			const button = document.querySelector('#rss-feed-importer-sync-now');
			const logBox = document.querySelector('#rss-feed-importer-log');
			if (!button || !logBox) return;
			const request = (action, extra = {}) => fetch(ajaxurl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(Object.assign({ action: action, nonce: button.dataset.nonce }, extra)) }).then(response => response.json());
			const refreshLog = () => request('rss_feed_importer_logs').then(response => { if (response.success) logBox.textContent = response.data.map(item => item.time + '  ' + item.message.replace(/^(.+) done: (\d+) imported, (\d+) updated, (\d+) skipped, (\d+) errors\.$/, '$1 kész: új $2, frissített $3, kihagyott $4, hibás $5.')).join('\n'); });
			button.addEventListener('click', function (event) {
				event.preventDefault(); button.disabled = true; button.classList.add('is-busy'); logBox.textContent = '<?php echo esc_js( __( 'Starting sync...', 'rss-feed-importer' ) ); ?>';
				request('rss_feed_importer_sync_start').then(() => step()).catch(error => { logBox.textContent = error.message; button.disabled = false; });
				function step() { request('rss_feed_importer_sync_step').then(response => { refreshLog(); if (response.success && response.data.complete) { button.disabled = false; button.classList.remove('is-busy'); return; } window.setTimeout(step, 650); }).catch(error => { logBox.textContent += '\n' + error.message; button.disabled = false; }); }
			});
		}());
		</script>
		<?php
	}

	public function render_admin_styles() {
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_rss-feed-importer' !== $screen->id ) {
			return;
		}
		?>
		<style>
			.rss-feed-importer-wrap { max-width: 1320px; margin-right: 24px; color: #1f2937; }
			.rss-feed-importer-hero { display: flex; justify-content: space-between; align-items: center; margin: 24px 0 18px; padding: 30px 34px; border-radius: 14px; color: #fff; background: linear-gradient(120deg, #103c55 0%, #087e8b 100%); box-shadow: 0 10px 28px rgba(16, 60, 85, .18); }
			.rss-feed-importer-eyebrow { display: block; margin-bottom: 8px; color: #a7f3d0; font-size: 11px; font-weight: 700; letter-spacing: 1.8px; }
			.rss-feed-importer-hero h1 { margin: 0 0 6px; color: #fff; font-size: 30px; font-weight: 700; }
			.rss-feed-importer-hero p { margin: 0; color: #d8f3f0; font-size: 14px; }
			.rss-feed-importer-mark { display: grid; place-items: center; width: 76px; height: 76px; border: 1px solid rgba(255,255,255,.45); border-radius: 50%; color: #fff; font-size: 17px; font-weight: 800; letter-spacing: 1px; }
			.rss-feed-importer-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 18px; }
			.rss-feed-importer-stat { display: grid; grid-template-columns: 28px 1fr; grid-template-rows: auto auto; column-gap: 10px; padding: 17px 18px; border: 1px solid #dce7e8; border-radius: 10px; background: #fff; box-shadow: 0 3px 10px rgba(31, 41, 55, .04); }
			.rss-feed-importer-stat .dashicons { grid-row: span 2; color: #087e8b; font-size: 25px; }
			.rss-feed-importer-stat strong { font-size: 18px; line-height: 1.25; }
			.rss-feed-importer-stat small { color: #64748b; font-size: 11px; }
			.rss-feed-importer-panel { margin: 18px 0; padding: 24px 26px; border: 1px solid #dce7e8; border-radius: 12px; background: #fff; box-shadow: 0 3px 12px rgba(31, 41, 55, .04); }
			.rss-feed-importer-action-panel { display: flex; justify-content: space-between; align-items: center; border-color: #b7e4df; background: #f1fbfa; }
			.rss-feed-importer-action-panel h2, .rss-feed-importer-panel-heading h2 { margin: 0 0 5px; font-size: 18px; }
			.rss-feed-importer-action-panel p, .rss-feed-importer-panel-heading p { margin: 0; color: #64748b; }
			.rss-feed-importer-next-sync { margin-top: 8px !important; color: #087e8b !important; font-size: 12px; font-weight: 600; }
			.rss-feed-importer-action-panel .dashicons { margin: 3px 5px 0 0; vertical-align: top; }
			.rss-feed-importer-action-panel .is-busy .dashicons { animation: rss-feed-importer-spin .9s linear infinite; }
			@keyframes rss-feed-importer-spin { to { transform: rotate(360deg); } }
			.rss-feed-importer-log-panel { padding-bottom: 18px; }
			#rss-feed-importer-log { min-height: 72px; max-height: 230px; overflow: auto; margin: 16px 0 0; padding: 14px 16px; border-radius: 8px; color: #c7f9f1; background: #102b36; font: 12px/1.65 Consolas, monospace; white-space: pre-wrap; }
			.rss-feed-importer-panel form > h2 { display: none; }
			.rss-feed-importer-panel .form-table { display: block; margin: 8px 0 0; }
			.rss-feed-importer-panel .form-table > tbody { display: block; }
			.rss-feed-importer-panel .form-table > tbody > tr { display: grid; grid-template-columns: 150px minmax(0, 1fr); border-bottom: 1px solid #edf2f2; }
			.rss-feed-importer-panel .form-table > tbody > tr:last-child { border-bottom: 0; }
			.rss-feed-importer-panel .form-table > tbody > tr > th { width: auto; padding: 18px 18px 18px 0; }
			.rss-feed-importer-panel .form-table > tbody > tr > td { padding: 18px 0; }
			.rss-feed-importer-table-wrap { overflow-x: auto; margin: 0; padding-bottom: 2px; }
			.rss-feed-importer-feed-list { display: grid; gap: 9px; }
			.rss-feed-importer-feed-card { display: grid; grid-template-columns: 20px minmax(0, 1fr) auto; gap: 12px; align-items: center; padding: 14px 16px; border: 1px solid #dce7e8; border-radius: 9px; background: #fff; transition: border-color .15s, background .15s; }
			.rss-feed-importer-feed-card:hover { border-color: #8acbc5; background: #fbfefe; }
			.rss-feed-importer-feed-card.is-disabled { opacity: .58; }
			.rss-feed-importer-status-dot { display: block; width: 9px; height: 9px; border-radius: 50%; background: #0f9d8d; box-shadow: 0 0 0 4px #e4f7f3; }
			.rss-feed-importer-feed-card.is-disabled .rss-feed-importer-status-dot { background: #94a3b8; box-shadow: 0 0 0 4px #f1f5f9; }
			.rss-feed-importer-feed-main { min-width: 0; }
			.rss-feed-importer-feed-main strong, .rss-feed-importer-feed-main span, .rss-feed-importer-feed-main small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
			.rss-feed-importer-feed-main strong { color: #173c4a; font-size: 14px; }
			.rss-feed-importer-feed-main span { margin-top: 3px; color: #64748b; font-size: 12px; }
			.rss-feed-importer-feed-main small { margin-top: 6px; color: #087e8b; font-size: 11px; }
			.rss-feed-importer-feed-actions { display: flex; align-items: center; gap: 8px; }
			.rss-feed-importer-feed-actions .dashicons { margin: 3px 3px 0 0; font-size: 15px; vertical-align: top; }
			.rss-feed-importer-feed-card-template { display: none; }
			.rss-feed-importer-modal[hidden] { display: none; }
			.rss-feed-importer-modal { position: fixed; z-index: 100000; inset: 0; display: grid; place-items: center; padding: 24px; }
			.rss-feed-importer-modal-backdrop { position: absolute; inset: 0; background: rgba(15, 35, 45, .58); }
			.rss-feed-importer-modal-dialog { position: relative; width: min(680px, 100%); max-height: calc(100vh - 48px); overflow: auto; padding: 28px; border-radius: 12px; background: #fff; box-shadow: 0 24px 70px rgba(15, 35, 45, .28); }
			.rss-feed-importer-modal-dialog h2 { margin: 0 0 5px; color: #173c4a; font-size: 22px; }
			.rss-feed-importer-modal-close { position: absolute; top: 14px; right: 16px; border: 0; color: #64748b; background: transparent; cursor: pointer; font-size: 27px; line-height: 1; }
			.rss-feed-importer-modal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 22px; }
			.rss-feed-importer-modal-grid label { color: #49616b; font-size: 12px; font-weight: 600; }
			.rss-feed-importer-modal-grid input[type="url"], .rss-feed-importer-modal-grid input[type="text"], .rss-feed-importer-modal-grid select { display: block; width: 100%; min-height: 38px; margin-top: 6px; border-color: #cbdde0; border-radius: 5px; }
			.rss-feed-importer-modal-grid .rss-feed-importer-disabled-help { font-weight: 400; }
			.rss-modal-enabled { display: flex !important; align-items: center; gap: 8px; align-self: end; min-height: 38px; }
			.rss-modal-enabled input { margin: 0; }
			.rss-feed-importer-modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px; padding-top: 18px; border-top: 1px solid #edf2f2; }
			.rss-feed-importer-modal-actions .dashicons { margin: 3px 4px 0 0; font-size: 15px; vertical-align: top; }
			body.rss-feed-importer-modal-open { overflow: hidden; }
			#rss-feed-importer-feeds { width: 100%; min-width: 1120px; table-layout: fixed; border: 1px solid #dce7e8; border-radius: 8px; overflow: hidden; }
			#rss-feed-importer-feeds th:nth-child(1), #rss-feed-importer-feeds td:nth-child(1) { width: 60px; text-align: center; }
			#rss-feed-importer-feeds th:nth-child(2), #rss-feed-importer-feeds td:nth-child(2) { width: 300px; }
			#rss-feed-importer-feeds th:nth-child(3), #rss-feed-importer-feeds td:nth-child(3) { width: 150px; }
			#rss-feed-importer-feeds th:nth-child(4), #rss-feed-importer-feeds td:nth-child(4) { width: 150px; }
			#rss-feed-importer-feeds th:nth-child(5), #rss-feed-importer-feeds td:nth-child(5) { width: 160px; }
			#rss-feed-importer-feeds th:nth-child(6), #rss-feed-importer-feeds td:nth-child(6) { width: 150px; }
			#rss-feed-importer-feeds th:nth-child(7), #rss-feed-importer-feeds td:nth-child(7) { width: 48px; }
			#rss-feed-importer-feeds th { padding: 12px 10px; color: #49616b; background: #f5faf9; font-size: 11px; text-transform: uppercase; letter-spacing: .4px; }
			#rss-feed-importer-feeds td { padding: 12px 10px; vertical-align: middle; }
			#rss-feed-importer-feeds input[type="url"], #rss-feed-importer-feeds input[type="text"] { width: 100%; min-height: 36px; border-color: #cbdde0; border-radius: 5px; }
			#rss-feed-importer-feeds select { width: 100%; min-height: 36px; max-width: 170px; border-color: #cbdde0; border-radius: 5px; }
			.rss-feed-importer-preview-button { margin-top: 6px; font-size: 11px !important; }
			.rss-feed-importer-preview-button .dashicons { margin: 3px 3px 0 0; font-size: 15px; }
			.rss-feed-importer-preview { min-width: 260px; max-width: 440px; margin-top: 8px; color: #64748b; font-size: 11px; }
			.rss-feed-importer-preview article { margin-top: 6px; padding: 6px 8px; border-left: 3px solid #b7e4df; background: #f5faf9; }
			.rss-feed-importer-preview article b, .rss-feed-importer-preview article small { display: block; }
			.rss-feed-importer-preview article small { margin-top: 2px; color: #087e8b; }
			.rss-feed-importer-preview article p { margin: 3px 0 0; }
			.rss-feed-importer-icon-button { border: 0; color: #b42318; background: transparent; cursor: pointer; }
			.rss-feed-importer-icon-button:hover { color: #7a1710; }
			.rss-feed-importer-icon-button .dashicons { font-size: 19px; }
			.rss-feed-importer-disabled-help { display: block; max-width: 170px; margin-top: 5px; color: #9a6700; font-size: 10px; line-height: 1.3; }
			.rss-feed-importer-switch { position: relative; display: inline-block; width: 38px; height: 22px; }
			.rss-feed-importer-switch input { width: 1px; height: 1px; opacity: 0; }
			.rss-feed-importer-switch span { position: absolute; inset: 0; border-radius: 22px; background: #cbd5e1; cursor: pointer; transition: .2s; }
			.rss-feed-importer-switch span:before { content: ''; position: absolute; width: 16px; height: 16px; left: 3px; top: 3px; border-radius: 50%; background: #fff; transition: .2s; box-shadow: 0 1px 2px rgba(0,0,0,.2); }
			.rss-feed-importer-switch input:checked + span { background: #0f9d8d; }
			.rss-feed-importer-switch input:checked + span:before { transform: translateX(16px); }
			@media (max-width: 900px) { .rss-feed-importer-stats { grid-template-columns: repeat(2, 1fr); } .rss-feed-importer-action-panel { align-items: flex-start; gap: 18px; flex-direction: column; } .rss-feed-importer-panel .form-table > tbody > tr { grid-template-columns: 1fr; } .rss-feed-importer-panel .form-table > tbody > tr > th { padding-bottom: 4px; } .rss-feed-importer-panel .form-table > tbody > tr > td { padding-top: 4px; } }
			@media (max-width: 600px) { .rss-feed-importer-wrap { margin-right: 10px; } .rss-feed-importer-hero { padding: 24px; } .rss-feed-importer-mark { display: none; } .rss-feed-importer-stats { grid-template-columns: 1fr; } .rss-feed-importer-feed-card { grid-template-columns: 14px minmax(0, 1fr); } .rss-feed-importer-feed-actions { grid-column: 2; } .rss-feed-importer-modal-grid { grid-template-columns: 1fr; } .rss-feed-importer-modal-dialog { padding: 22px; } }
		</style>
		<?php
	}

	private function render_sync_notice() {
		if ( empty( $_GET['rss_feed_importer_sync'] ) ) {
			return;
		}
		$result = json_decode( rawurldecode( wp_unslash( $_GET['rss_feed_importer_sync'] ) ), true );
		if ( ! is_array( $result ) ) {
			return;
		}
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( __( 'Sync complete. Imported: %1$d, updated: %2$d, skipped: %3$d, errors: %4$d.', 'rss-feed-importer' ), (int) $result['imported'], (int) ( $result['updated'] ?? 0 ), (int) $result['skipped'], (int) $result['errors'] ) ) );
	}

	private function format_logs_for_display( $logs ) {
		$lines = array();
		foreach ( $logs as $log ) {
			$message = isset( $log['message'] ) ? $log['message'] : '';
			$message = preg_replace( '/^(.+) done: (\d+) imported, (\d+) updated, (\d+) skipped, (\d+) errors\.$/', '$1 kész: új $2, frissített $3, kihagyott $4, hibás $5.', $message );
			$lines[] = ( $log['time'] ?? '' ) . '  ' . $message;
		}
		return implode( "\n", $lines );
	}
}