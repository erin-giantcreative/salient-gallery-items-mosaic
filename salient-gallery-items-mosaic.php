<?php
/**
 * Plugin Name: Salient - Gallery Items Mosaic (WPBakery Element)
 * Description: WPBakery element for Salient: filterable mosaic gallery for "gallery-items" CPT with ACF + taxonomies + accessible lightbox + cached AJAX.
 * Version: 1.1.1
 * Author: Giant Creative Inc
 *
 * SEO / Performance improvements included:
 * 1) ItemList schema (JSON-LD) for the visible items.
 * 2) ImageObject schema for each visible item (embedded in ItemList).
 * 3) Tiles are real links to the single post (crawlable + can open in new tab).
 * 4) Strong alt fallback + screen-reader-only caption per tile.
 * 5) "Browse" section with real term links for market/product/project (internal linking).
 * 6) Optional noindex for gallery-items single posts (toggle via shortcode param).
 * 7) LCP tuning: first N images load eager + fetchpriority high.
 *
 * Requirements:
 * - WPBakery Page Builder (element UI)
 * - ACF (image + mosaic_size + description fields)
 *
 * Data Model:
 * - CPT: gallery-items
 * - ACF fields:
 *   - image (image field) -> attachment ID
 *   - mosaic_size (select) values: Regular, Tall, WideTall, Wide
 *   - description (textarea)
 * - Taxonomies:
 *   - market
 *   - product
 *   - project
 */

defined('ABSPATH') || exit;

final class Salient_Gallery_Items_Mosaic {

	const SHORTCODE      = 'sgim_gallery_mosaic';
	const CACHE_PREFIX   = 'sgim_';

	const CACHE_TTL_QUERY = 10 * MINUTE_IN_SECONDS;
	const CACHE_TTL_TERMS = 60 * MINUTE_IN_SECONDS;

	const STYLE_HANDLE  = 'sgim-gallery-mosaic';
	const SCRIPT_HANDLE = 'sgim-gallery-mosaic';

	/**
	 * Boot hooks.
	 */
	public static function init() {
		add_action('init', [__CLASS__, 'register_shortcode']);
		add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);

		add_action('wp_ajax_sgim_filter', [__CLASS__, 'ajax_filter']);
		add_action('wp_ajax_nopriv_sgim_filter', [__CLASS__, 'ajax_filter']);

		add_action('vc_before_init', [__CLASS__, 'register_vc_element']);

		// Optional noindex for single posts (controlled via shortcode param "noindex_singles").
		add_action('wp_head', [__CLASS__, 'maybe_output_noindex_meta'], 1);
	}

	public static function register_shortcode() {
		add_shortcode(self::SHORTCODE, [__CLASS__, 'render_shortcode']);
	}

	public static function register_assets() {
		$plugin_url = plugin_dir_url(__FILE__);

		wp_register_style(
			self::STYLE_HANDLE,
			$plugin_url . 'assets/gallery-mosaic.css',
			[],
			'1.1.1'
		);

		wp_register_script(
			self::SCRIPT_HANDLE,
			$plugin_url . 'assets/gallery-mosaic.js',
			['jquery'],
			'1.1.1',
			true
		);
	}

	public static function register_vc_element() {
		if (!function_exists('vc_map')) return;

		vc_map([
			'name'        => 'Gallery Items Mosaic',
			'base'        => self::SHORTCODE,
			'category'    => 'Content',
			'description' => 'Filterable mosaic gallery from gallery-items CPT (ACF + taxonomies).',
			'params'      => [
				[
					'type'        => 'textfield',
					'heading'     => 'Max items (optional)',
					'param_name'  => 'max_items',
					'description' => 'Leave blank for all items. Use a number for a cap.',
				],
				[
					'type'        => 'dropdown',
					'heading'     => 'Initial sort',
					'param_name'  => 'order_by',
					'value'       => [
						'Newest first' => 'date_desc',
						'Oldest first' => 'date_asc',
						'Title A-Z'    => 'title_asc',
						'Title Z-A'    => 'title_desc',
					],
					'std' => 'date_desc',
				],
				[
					'type'        => 'dropdown',
					'heading'     => 'Noindex single gallery-items pages?',
					'param_name'  => 'noindex_singles',
					'value'       => [
						'No (index singles)' => '0',
						'Yes (noindex singles)' => '1',
					],
					'std' => '0',
					'description' => 'If your gallery item singles are thin, set to Yes.',
				],
				[
					'type'        => 'textfield',
					'heading'     => 'Eager-load first N images',
					'param_name'  => 'eager_first',
					'description' => 'Default 2. Helps LCP.',
				],
				[
					'type'        => 'textfield',
					'heading'     => 'Browse links per taxonomy',
					'param_name'  => 'browse_limit',
					'description' => 'Default 10. Shows term links under the gallery.',
				],
			],
		]);
	}

	/**
	 * Output <meta name="robots" content="noindex,follow"> on single gallery-items
	 * if at least one instance of the shortcode on the site set noindex_singles=1.
	 *
	 * We store the setting in an option during shortcode render, so it works site-wide.
	 * This avoids needing a separate admin settings screen.
	 */
	public static function maybe_output_noindex_meta() {
		if (!is_singular('gallery-items')) return;

		$enabled = (int) get_option('sgim_noindex_singles', 0);
		if ($enabled !== 1) return;

		echo "\n" . '<meta name="robots" content="noindex,follow" />' . "\n";
	}

	/**
	 * Shortcode renderer.
	 */
	public static function render_shortcode($atts) {
		$atts = shortcode_atts([
			'max_items'       => '',
			'order_by'        => 'date_desc',
			'noindex_singles' => '0',
			'eager_first'     => '2',
			'browse_limit'    => '10',
		], $atts, self::SHORTCODE);

		$max_items    = self::sanitize_int_or_empty($atts['max_items']);
		$order_by     = sanitize_text_field($atts['order_by']);
		$noindex      = absint($atts['noindex_singles']) === 1 ? 1 : 0;
		$eager_first  = max(0, absint($atts['eager_first']));
		$browse_limit = max(0, absint($atts['browse_limit']));

		// Persist noindex flag (site-wide behavior for single gallery-items).
		// This is safe and simple for your use case.
		if ($noindex === 1) {
			update_option('sgim_noindex_singles', 1, false);
		}

		// Enqueue assets only when the element is present on the page.
		wp_enqueue_style(self::STYLE_HANDLE);
		wp_enqueue_script(self::SCRIPT_HANDLE);

		wp_localize_script(self::SCRIPT_HANDLE, 'SGIM', [
			'ajaxUrl'      => admin_url('admin-ajax.php'),
			'nonce'        => wp_create_nonce('sgim_nonce'),
			'maxItems'     => $max_items,
			'orderBy'      => $order_by,
			'eagerFirst'   => $eager_first,
			'strings'      => [
				'noResults' => 'No images found for those filters.',
				'loading'   => 'Loading images…',
				'close'     => 'Close image viewer',
				'prev'      => 'Previous image',
				'next'      => 'Next image',
			],
		]);

		// Term options (cached).
		$terms_market  = self::get_terms_cached('market');
		$terms_product = self::get_terms_cached('product');
		$terms_project = self::get_terms_cached('project');

		// Initial items (cached).
		$initial = self::get_items_cached([
			'market'  => 0,
			'product' => 0,
			'project' => 0,
			'max'     => $max_items,
			'orderBy' => $order_by,
		]);

		ob_start();
		?>
		<div class="sgim" data-sgim>
			<div class="sgim__filters" aria-label="Gallery filters">
				<div class="sgim__filters-label" aria-hidden="true">Filter by:</div>

				<label class="sgim__sr-only" for="sgim-market">Market</label>
				<select id="sgim-market" class="sgim__select" data-sgim-filter="market">
					<option value="0">Market</option>
					<?php foreach ($terms_market as $t) : ?>
						<option value="<?php echo esc_attr($t->term_id); ?>"><?php echo esc_html($t->name); ?></option>
					<?php endforeach; ?>
				</select>

				<label class="sgim__sr-only" for="sgim-product">Product</label>
				<select id="sgim-product" class="sgim__select" data-sgim-filter="product">
					<option value="0">Product</option>
					<?php foreach ($terms_product as $t) : ?>
						<option value="<?php echo esc_attr($t->term_id); ?>"><?php echo esc_html($t->name); ?></option>
					<?php endforeach; ?>
				</select>

				<label class="sgim__sr-only" for="sgim-project">Project</label>
				<select id="sgim-project" class="sgim__select" data-sgim-filter="project">
					<option value="0">Project</option>
					<?php foreach ($terms_project as $t) : ?>
						<option value="<?php echo esc_attr($t->term_id); ?>"><?php echo esc_html($t->name); ?></option>
					<?php endforeach; ?>
				</select>

				<button type="button" class="sgim__clear" data-sgim-clear>
					Clear Filters <span aria-hidden="true">×</span>
				</button>
			</div>

			<div class="sgim__status" role="status" aria-live="polite" data-sgim-status></div>

			<div class="sgim__grid" data-sgim-grid>
				<?php echo self::render_items_html($initial, $eager_first); ?>
			</div>

			<?php
			// (1) + (2) Schema: ItemList + ImageObject (for the initial visible items).
			echo self::render_schema_jsonld($initial);
			?>

			<?php
			// (5) Browse links for internal linking.
			//echo self::render_browse_links($browse_limit); // REMOVED: Browse links section (not part of design)
			?>

			<!-- Accessible lightbox / dialog -->
			<div class="sgim__lightbox" data-sgim-lightbox hidden>
				<div class="sgim__lightbox-backdrop" data-sgim-close tabindex="-1" aria-hidden="true"></div>

				<div class="sgim__lightbox-dialog" role="dialog" aria-modal="true" aria-label="Image viewer">
					<button type="button" class="sgim__lightbox-close" data-sgim-close aria-label="Close image viewer">×</button>

					<button type="button" class="sgim__lightbox-prev" data-sgim-prev aria-label="Previous image">‹</button>

					<figure class="sgim__lightbox-figure">
						<img class="sgim__lightbox-img" data-sgim-lightbox-img alt="">
						<figcaption class="sgim__lightbox-caption" data-sgim-lightbox-caption></figcaption>
					</figure>

					<button type="button" class="sgim__lightbox-next" data-sgim-next aria-label="Next image">›</button>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * AJAX: filter items. Returns new grid HTML + schema JSON-LD + browse links untouched.
	 *
	 * We only re-render the grid here for speed.
	 */
	public static function ajax_filter() {
		check_ajax_referer('sgim_nonce', 'nonce');

		$market  = isset($_POST['market'])  ? absint($_POST['market'])  : 0;
		$product = isset($_POST['product']) ? absint($_POST['product']) : 0;
		$project = isset($_POST['project']) ? absint($_POST['project']) : 0;

		$max = isset($_POST['maxItems']) ? self::sanitize_int_or_empty($_POST['maxItems']) : '';
		$orderBy = isset($_POST['orderBy']) ? sanitize_text_field($_POST['orderBy']) : 'date_desc';
		$eager_first = isset($_POST['eagerFirst']) ? max(0, absint($_POST['eagerFirst'])) : 2;

		$items = self::get_items_cached([
			'market'  => $market,
			'product' => $product,
			'project' => $project,
			'max'     => $max,
			'orderBy' => $orderBy,
		]);

		wp_send_json_success([
			'count' => count($items),
			'html'  => self::render_items_html($items, $eager_first),
		]);
	}

	/**
	 * Cached term lists (only terms with posts).
	 */
	private static function get_terms_cached($taxonomy) {
		$key = self::CACHE_PREFIX . 'terms_' . $taxonomy;
		$cached = get_transient($key);
		if ($cached !== false) return $cached;

		$terms = get_terms([
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
		]);

		if (is_wp_error($terms)) $terms = [];
		set_transient($key, $terms, self::CACHE_TTL_TERMS);

		return $terms;
	}

	/**
	 * Cached query results by filter combo + max + order.
	 */
	private static function get_items_cached($args) {
		$key = self::CACHE_PREFIX . 'items_' . md5(wp_json_encode($args));
		$cached = get_transient($key);
		if ($cached !== false) return $cached;

		$items = self::query_items($args);
		set_transient($key, $items, self::CACHE_TTL_QUERY);

		return $items;
	}

	/**
	 * Query gallery-items and return compact render data.
	 */
	private static function query_items($args) {
		$tax_query = ['relation' => 'AND'];

		if (!empty($args['market'])) {
			$tax_query[] = [
				'taxonomy' => 'market',
				'field'    => 'term_id',
				'terms'    => (int) $args['market'],
			];
		}
		if (!empty($args['product'])) {
			$tax_query[] = [
				'taxonomy' => 'product',
				'field'    => 'term_id',
				'terms'    => (int) $args['product'],
			];
		}
		if (!empty($args['project'])) {
			$tax_query[] = [
				'taxonomy' => 'project',
				'field'    => 'term_id',
				'terms'    => (int) $args['project'],
			];
		}

		$order = 'DESC';
		$orderby = 'date';
		switch ($args['orderBy']) {
			case 'date_asc':   $order = 'ASC';  $orderby = 'date';  break;
			case 'title_asc':  $order = 'ASC';  $orderby = 'title'; break;
			case 'title_desc': $order = 'DESC'; $orderby = 'title'; break;
			case 'date_desc':
			default:           $order = 'DESC'; $orderby = 'date';  break;
		}

		$query_args = [
			'post_type'      => 'gallery-items',
			'post_status'    => 'publish',
			'posts_per_page' => (!empty($args['max']) ? (int) $args['max'] : -1),
			'orderby'        => $orderby,
			'order'          => $order,
			'no_found_rows'  => true,
			'fields'         => 'ids',
		];

		if (count($tax_query) > 1) {
			$query_args['tax_query'] = $tax_query;
		}

		$q = new WP_Query($query_args);
		$ids = $q->posts;

		if (empty($ids)) return [];

		$out = [];
		foreach ($ids as $post_id) {
			$title = get_the_title($post_id);

			// ACF: image should return attachment ID.
			$image_id = function_exists('get_field') ? (int) get_field('image', $post_id) : 0;
			if (!$image_id) continue;

			$mosaic = function_exists('get_field') ? (string) get_field('mosaic_size', $post_id) : 'Regular';
			$mosaic = self::normalize_mosaic_value($mosaic);

			$thumb_src    = wp_get_attachment_image_url($image_id, 'medium_large');
			$img_src      = wp_get_attachment_image_url($image_id, 'large');
			$thumb_srcset = wp_get_attachment_image_srcset($image_id, 'medium_large');
			$thumb_sizes  = wp_get_attachment_image_sizes($image_id, 'medium_large');

			// (4) Alt text fallback: attachment alt -> title -> "Title – Project/Market/Product" if available.
			$alt = trim((string) get_post_meta($image_id, '_wp_attachment_image_alt', true));
			if ($alt === '') {
				$alt = self::build_alt_fallback($post_id, $title);
			}

			$desc = function_exists('get_field') ? (string) get_field('description', $post_id) : '';

			$out[] = [
				'id'           => (int) $post_id,
				'permalink'    => (string) get_permalink($post_id),
				'title'        => (string) $title,
				'mosaic_size'  => $mosaic,
				'thumb_src'    => (string) $thumb_src,
				'thumb_srcset' => (string) $thumb_srcset,
				'thumb_sizes'  => (string) $thumb_sizes,
				'img_src'      => (string) $img_src,
				'alt'          => (string) $alt,
				'description'  => (string) $desc,
			];
		}

		return $out;
	}

	/**
	 * (3) Render tiles as <a> links (crawlable).
	 * Also includes (4) SR-only caption and (7) LCP tuning for first N images.
	 */
	private static function render_items_html($items, $eager_first = 2) {
		if (empty($items)) {
			return '<div class="sgim__empty" role="status">No images found for those filters.</div>';
		}

		$html = '';
		foreach ($items as $index => $it) {

			// (7) LCP tuning: first N images eager + fetchpriority.
			$is_eager = ($index < $eager_first);

			$loading = $is_eager ? 'eager' : 'lazy';
			$fetchpriority = $is_eager ? ' fetchpriority="high"' : '';
			$decoding = 'decoding="async"';

			// Visible caption is not requested; we add SR-only caption for richer semantics.
			$caption = trim($it['description']) !== '' ? $it['description'] : $it['title'];

			$html .= sprintf(
				'<a href="%1$s"
					class="sgim__tile sgim__tile--%2$s"
					data-sgim-item
					data-index="%3$d"
					data-full="%4$s"
					data-title="%5$s"
					data-caption="%6$s"
					aria-label="Open image: %5$s">
					<img class="sgim__img"
						src="%7$s"
						%8$s
						%9$s
						alt="%10$s"
						loading="%11$s"
						%12$s
						%13$s />
					<span class="sgim__tile-caption sgim__sr-only">%14$s</span>
				</a>',
				esc_url($it['permalink']),
				esc_attr($it['mosaic_size']),
				(int) $index,
				esc_url($it['img_src']),
				esc_attr($it['title']),
				esc_attr($it['description']),
				esc_url($it['thumb_src']),
				(!empty($it['thumb_srcset']) ? 'srcset="' . esc_attr($it['thumb_srcset']) . '"' : ''),
				(!empty($it['thumb_sizes']) ? 'sizes="' . esc_attr($it['thumb_sizes']) . '"' : ''),
				esc_attr($it['alt']),
				esc_attr($loading),
				$decoding,
				$fetchpriority,
				esc_html($caption)
			);
		}

		return $html;
	}

	/**
	 * (1) + (2) Schema: ItemList with embedded ImageObject per item.
	 * Kept small and clean. Uses the initial visible items.
	 */
	private static function render_schema_jsonld($items) {
		if (empty($items)) return '';

		$list = [];
		foreach ($items as $i => $it) {
			$list[] = [
				'@type' => 'ListItem',
				'position' => $i + 1,
				'url' => $it['permalink'],
				'name' => $it['title'],
				'image' => [
					'@type' => 'ImageObject',
					'contentUrl' => $it['img_src'],
					'name' => $it['title'],
					'caption' => (trim($it['description']) !== '' ? $it['description'] : $it['title']),
				],
			];
		}

		$schema = [
			'@context' => 'https://schema.org',
			'@type' => 'ItemList',
			'itemListElement' => $list,
		];

		return '<script type="application/ld+json">' .
			wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) .
			'</script>';
	}

	/**
	 * (5) Browse links section for crawlable term paths.
	 * Shows up to $limit terms per taxonomy, only non-empty.
	 */
	private static function render_browse_links($limit = 10) {
		$limit = max(0, absint($limit));
		if ($limit === 0) return '';

		$taxes = [
			'market'  => 'Browse Markets',
			'product' => 'Browse Products',
			'project' => 'Browse Projects',
		];

		$out = '<div class="sgim__browse" aria-label="Browse gallery categories">';

		foreach ($taxes as $tax => $label) {
			$terms = self::get_terms_cached($tax);
			if (empty($terms)) continue;

			$terms = array_slice($terms, 0, $limit);

			$out .= '<div class="sgim__browse-block">';
			$out .= '<div class="sgim__browse-title">' . esc_html($label) . '</div>';
			$out .= '<ul class="sgim__browse-list">';

			foreach ($terms as $t) {
				$link = get_term_link($t);
				if (is_wp_error($link)) continue;

				$out .= '<li class="sgim__browse-item"><a href="' . esc_url($link) . '">' . esc_html($t->name) . '</a></li>';
			}

			$out .= '</ul></div>';
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 * Pull a small list of terms for alt fallback.
	 */
	private static function build_alt_fallback($post_id, $title) {
		$parts = [];

		$taxes = ['project', 'market', 'product'];
		foreach ($taxes as $tax) {
			$terms = get_the_terms($post_id, $tax);
			if (is_array($terms) && !empty($terms)) {
				// Use first term only to avoid spammy alt text.
				$parts[] = $terms[0]->name;
			}
		}

		if (!empty($parts)) {
			return $title . ' – ' . implode(', ', $parts);
		}

		return $title;
	}

	private static function normalize_mosaic_value($value) {
		$value = trim((string) $value);
		if ($value === '') return 'Regular';

		$key = strtolower($value);
		$key = str_replace([' ', '-', '_'], '', $key);

		$map = [
			'regular'  => 'Regular',
			'tall'     => 'Tall',
			'wide'     => 'Wide',
			'widetall' => 'WideTall',
		];

		return isset($map[$key]) ? $map[$key] : 'Regular';
	}

	private static function sanitize_int_or_empty($val) {
		$val = trim((string) $val);
		if ($val === '') return '';
		return (string) absint($val);
	}
}

Salient_Gallery_Items_Mosaic::init();