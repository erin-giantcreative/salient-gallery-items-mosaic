<?php
/**
 * Plugin Name: Salient - Gallery Items Mosaic (WPBakery Element)
 * Description: WPBakery element for Salient: filterable mosaic gallery for "gallery-items" CPT with ACF + taxonomies + accessible lightbox + cached AJAX + infinite scroll.
 * Version: 1.2.0
 * Author: Giant Creative Inc
 *
 * Includes:
 * - Cached queries (transients) for terms + filtered results
 * - Accessible lightbox (keyboard + focus trap)
 * - Tiles are real links to single posts
 * - ItemList/ImageObject schema on initial load
 * - Infinite scroll pagination (append pages on scroll)
 *
 * Data Model:
 * - CPT: gallery-items
 * - ACF/meta fields:
 *   - image (image field) -> attachment ID (ACF stores ID in meta; we also support URL/array just in case)
 *   - mosaic_size (select) values: Regular, Tall, WideTall, Wide
 *   - description (textarea)
 * - Taxonomies: market, product, project
 */

defined('ABSPATH') || exit;

final class Salient_Gallery_Items_Mosaic {

	const SHORTCODE      = 'sgim_gallery_mosaic';
	const CACHE_PREFIX   = 'sgim_';

	// Increase if your gallery doesn't change often.
	const CACHE_TTL_QUERY = 6 * HOUR_IN_SECONDS;
	const CACHE_TTL_TERMS = 12 * HOUR_IN_SECONDS;

	const STYLE_HANDLE  = 'sgim-gallery-mosaic';
	const SCRIPT_HANDLE = 'sgim-gallery-mosaic';

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
			'1.2.0'
		);

		wp_register_script(
			self::SCRIPT_HANDLE,
			$plugin_url . 'assets/gallery-mosaic.js',
			['jquery'],
			'1.2.0',
			true
		);
	}

	public static function register_vc_element() {
		if (!function_exists('vc_map')) return;

		vc_map([
			'name'        => 'Gallery Items Mosaic',
			'base'        => self::SHORTCODE,
			'category'    => 'Content',
			'description' => 'Filterable mosaic gallery from gallery-items CPT (ACF/meta + taxonomies).',
			'params'      => [
				[
					'type'        => 'textfield',
					'heading'     => 'Items per page',
					'param_name'  => 'per_page',
					'description' => 'Default 24. Used for infinite scroll.',
				],
				[
					'type'        => 'dropdown',
					'heading'     => 'Infinite scroll?',
					'param_name'  => 'infinite',
					'value'       => [
						'Yes' => '1',
						'No'  => '0',
					],
					'std' => '1',
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
			],
		]);
	}

	public static function maybe_output_noindex_meta() {
		if (!is_singular('gallery-items')) return;

		$enabled = (int) get_option('sgim_noindex_singles', 0);
		if ($enabled !== 1) return;

		echo "\n" . '<meta name="robots" content="noindex,follow" />' . "\n";
	}

	public static function render_shortcode($atts) {
		$atts = shortcode_atts([
			'per_page'       => '24',
			'infinite'       => '1',
			'order_by'       => 'date_desc',
			'noindex_singles'=> '0',
			'eager_first'    => '2',
		], $atts, self::SHORTCODE);

		$per_page    = max(1, absint($atts['per_page']));
		$infinite    = absint($atts['infinite']) === 1 ? 1 : 0;
		$order_by    = sanitize_text_field($atts['order_by']);
		$noindex     = absint($atts['noindex_singles']) === 1 ? 1 : 0;
		$eager_first = max(0, absint($atts['eager_first']));

		if ($noindex === 1) {
			update_option('sgim_noindex_singles', 1, false);
		}

		// Assets only when shortcode exists on the page.
		wp_enqueue_style(self::STYLE_HANDLE);
		wp_enqueue_script(self::SCRIPT_HANDLE);

		wp_localize_script(self::SCRIPT_HANDLE, 'SGIM', [
			'ajaxUrl'    => admin_url('admin-ajax.php'),
			'nonce'      => wp_create_nonce('sgim_nonce'),
			'orderBy'    => $order_by,
			'eagerFirst' => $eager_first,
			'perPage'    => $per_page,
			'infinite'   => $infinite,
			'strings'    => [
				'noResults' => 'No images found for those filters.',
				'loading'   => 'Loading images…',
				'close'     => 'Close image viewer',
				'prev'      => 'Previous image',
				'next'      => 'Next image',
			],
		]);

		$terms_market  = self::get_terms_cached('market');
		$terms_product = self::get_terms_cached('product');
		$terms_project = self::get_terms_cached('project');

		$initial_result = self::get_items_cached([
			'market'   => 0,
			'product'  => 0,
			'project'  => 0,
			'orderBy'  => $order_by,
			'page'     => 1,
			'per_page' => $per_page,
		]);
		$initial_items = $initial_result['items'];

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

				<button type="button" class="sgim__clear" data-sgim-clear hidden>
					Clear Filters <span aria-hidden="true">×</span>
				</button>
			</div>

			<div class="sgim__grid" data-sgim-grid>
				<?php echo self::render_items_html($initial_items, $eager_first); ?>
			</div>

			<div class="sgim__status" role="status" aria-live="polite" data-sgim-status>
        <span class="sgim__loader" data-sgim-loader hidden>
          <span class="sgim__spinner" aria-hidden="true"></span>
          <span class="sgim__loader-text">Loading images…</span>
        </span>
      </div>

			<div class="sgim__sentinel" data-sgim-sentinel aria-hidden="true"></div>

			<?php
			// Schema for initial visible items only.
			echo self::render_schema_jsonld($initial_items);
			?>

			<!-- Accessible lightbox -->
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

	public static function ajax_filter() {
		check_ajax_referer('sgim_nonce', 'nonce');

		$market   = isset($_POST['market'])  ? absint($_POST['market'])  : 0;
		$product  = isset($_POST['product']) ? absint($_POST['product']) : 0;
		$project  = isset($_POST['project']) ? absint($_POST['project']) : 0;

		$orderBy     = isset($_POST['orderBy']) ? sanitize_text_field($_POST['orderBy']) : 'date_desc';
		$eager_first = isset($_POST['eagerFirst']) ? max(0, absint($_POST['eagerFirst'])) : 2;

		$page     = isset($_POST['page']) ? max(1, absint($_POST['page'])) : 1;
		$per_page = isset($_POST['perPage']) ? max(1, absint($_POST['perPage'])) : 24;

		$result = self::get_items_cached([
			'market'   => $market,
			'product'  => $product,
			'project'  => $project,
			'orderBy'  => $orderBy,
			'page'     => $page,
			'per_page' => $per_page,
		]);

		$items    = $result['items'];
		$has_more = (bool) $result['has_more'];

		wp_send_json_success([
			'count'   => count($items),
			'html'    => self::render_items_html($items, $eager_first),
			'hasMore' => $has_more,
			'page'    => $page,
		]);
	}

  /**
   * Cached term lists — ONLY terms that have at least one published gallery-items post.
   *
   * WordPress term counts can include other post types. This function avoids that by
   * querying term relationships joined to posts filtered by post_type/post_status.
   *
   * @param string $taxonomy
   * @return WP_Term[]
   */
  private static function get_terms_cached($taxonomy) {
    $key = self::CACHE_PREFIX . 'terms_' . $taxonomy . '_gallery_items';
    $cached = get_transient($key);
    if ($cached !== false) return $cached;

    $terms = self::get_terms_for_post_type($taxonomy, 'gallery-items');

    set_transient($key, $terms, self::CACHE_TTL_TERMS);
    return $terms;
  }

  /**
   * Return terms in a taxonomy that are actually used by a given post type.
   * Results are ordered by term name.
   *
   * @param string $taxonomy
   * @param string $post_type
   * @return WP_Term[]
   */
  private static function get_terms_for_post_type($taxonomy, $post_type) {
    global $wpdb;

    $taxonomy = sanitize_key($taxonomy);
    $post_type = sanitize_key($post_type);

    // Get term_ids that are attached to published posts of the target post type.
    $term_ids = $wpdb->get_col(
      $wpdb->prepare(
        "
        SELECT DISTINCT tt.term_id
        FROM {$wpdb->term_taxonomy} tt
        INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
        INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
        WHERE tt.taxonomy = %s
          AND p.post_type = %s
          AND p.post_status = 'publish'
        ",
        $taxonomy,
        $post_type
      )
    );

    if (empty($term_ids)) return [];

    // Now fetch full term objects, ordered nicely for the dropdown.
    $terms = get_terms([
      'taxonomy'   => $taxonomy,
      'hide_empty' => false,      // we already filtered
      'include'    => $term_ids,
      'orderby'    => 'name',
      'order'      => 'ASC',
    ]);

    if (is_wp_error($terms)) return [];
    return $terms;
  }

	private static function get_items_cached($args) {
		$key = self::CACHE_PREFIX . 'items_' . md5(wp_json_encode($args));
		$cached = get_transient($key);
		if ($cached !== false) return $cached;

		$result = self::query_items($args);
		set_transient($key, $result, self::CACHE_TTL_QUERY);

		return $result;
	}

	/**
	 * Resolve a meta/ACF image value into an attachment ID.
	 * Supports: ID, array, URL.
	 */
	private static function resolve_attachment_id($value) {
		if (is_numeric($value)) return absint($value);

		if (is_array($value)) {
			if (!empty($value['ID'])) return absint($value['ID']);
			if (!empty($value['id'])) return absint($value['id']);
			return 0;
		}

		if (is_string($value) && $value !== '') {
			return absint(attachment_url_to_postid($value));
		}

		return 0;
	}

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

		$page     = !empty($args['page']) ? (int) $args['page'] : 1;
		$per_page = !empty($args['per_page']) ? (int) $args['per_page'] : 24;

		$query_args = [
			'post_type'      => 'gallery-items',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => $orderby,
			'order'          => $order,
			'fields'         => 'ids',
		];

		if (count($tax_query) > 1) {
			$query_args['tax_query'] = $tax_query;
		}

		$q = new WP_Query($query_args);
		$ids = $q->posts;

		$max_pages = (int) $q->max_num_pages;
		$has_more  = ($page < $max_pages);

		if (empty($ids)) {
			return [
				'items'    => [],
				'has_more' => false,
			];
		}

		$out = [];
		foreach ($ids as $post_id) {
			$title = get_the_title($post_id);
			$permalink = get_permalink($post_id);

			// Fast reads from meta (ACF stores values in post meta).
			$img_meta = get_post_meta($post_id, 'image', true);
			$image_id = self::resolve_attachment_id($img_meta);
			if (!$image_id) continue;

			$mosaic = (string) get_post_meta($post_id, 'mosaic_size', true);
			$mosaic = self::normalize_mosaic_value($mosaic);

			$desc = (string) get_post_meta($post_id, 'description', true);

			$thumb_src    = wp_get_attachment_image_url($image_id, 'medium_large');
			$img_src      = wp_get_attachment_image_url($image_id, 'large');

			if (!$thumb_src) $thumb_src = wp_get_attachment_image_url($image_id, 'full');
			if (!$img_src)   $img_src   = wp_get_attachment_image_url($image_id, 'full');

			$thumb_srcset = wp_get_attachment_image_srcset($image_id, 'medium_large');
			$thumb_sizes  = wp_get_attachment_image_sizes($image_id, 'medium_large');

			$alt = trim((string) get_post_meta($image_id, '_wp_attachment_image_alt', true));
			if ($alt === '') {
				$alt = self::build_alt_fallback($post_id, $title);
			}

			$out[] = [
				'id'           => (int) $post_id,
				'permalink'    => (string) $permalink,
				'title'        => (string) $title,
				'mosaic_size'  => (string) $mosaic,
				'thumb_src'    => (string) $thumb_src,
				'thumb_srcset' => (string) $thumb_srcset,
				'thumb_sizes'  => (string) $thumb_sizes,
				'img_src'      => (string) $img_src,
				'alt'          => (string) $alt,
				'description'  => (string) $desc,
			];
		}

		return [
			'items'    => $out,
			'has_more' => (bool) $has_more,
		];
	}

	private static function render_items_html($items, $eager_first = 2) {
		if (empty($items)) {
			return '<div class="sgim__empty" role="status">No images found for those filters.</div>';
		}

		$html = '';
		foreach ($items as $index => $it) {
			$is_eager = ($index < $eager_first);

			$loading       = $is_eager ? 'eager' : 'lazy';
			$fetchpriority = $is_eager ? ' fetchpriority="high"' : '';
			$decoding      = 'decoding="async"';

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

	private static function render_schema_jsonld($items) {
		if (empty($items)) return '';

		$list = [];
		foreach ($items as $i => $it) {
			$list[] = [
				'@type'     => 'ListItem',
				'position'  => $i + 1,
				'url'       => $it['permalink'],
				'name'      => $it['title'],
				'image'     => [
					'@type'      => 'ImageObject',
					'contentUrl' => $it['img_src'],
					'name'       => $it['title'],
					'caption'    => (trim($it['description']) !== '' ? $it['description'] : $it['title']),
				],
			];
		}

		$schema = [
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'itemListElement' => $list,
		];

		return '<script type="application/ld+json">' .
			wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) .
			'</script>';
	}

	private static function build_alt_fallback($post_id, $title) {
		$parts = [];
		$taxes = ['project', 'market', 'product'];

		foreach ($taxes as $tax) {
			$terms = get_the_terms($post_id, $tax);
			if (is_array($terms) && !empty($terms)) {
				$parts[] = $terms[0]->name;
			}
		}

		if (!empty($parts)) return $title . ' – ' . implode(', ', $parts);
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
}

Salient_Gallery_Items_Mosaic::init();