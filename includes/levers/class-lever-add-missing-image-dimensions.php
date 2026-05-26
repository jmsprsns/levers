<?php
/**
 * Lever: add missing width/height to images and picture sources.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Backfills width/height attributes on <img> tags - and on <source>
 * tags inside <picture> - whenever they're missing.
 *
 * Browsers use the width/height attributes to reserve the right amount
 * of space for an image before it loads, which is the single biggest
 * lever on Cumulative Layout Shift. WordPress 5.5+ already adds them
 * to images that carry a wp-image-{id} class, but anything outside
 * that path - hand-written HTML, page-builder output, theme template
 * markup, third-party blocks - misses out. And nothing in core touches
 * <source> elements inside <picture>, even though those elements
 * have supported width/height since 2021 and need them to prevent
 * art-direction-driven layout shift.
 *
 * Resolution order for each tag:
 *   1. If the URL is a known attachment - use the attachment's saved
 *      width/height. Free.
 *   2. Else if the URL points to a file on disk under WP_CONTENT_DIR -
 *      read dimensions with getimagesize() and cache for a week.
 *   3. Else - leave the tag alone. External images aren't worth a
 *      synchronous fetch.
 *
 * Cheap on cache hits; the disk read only happens once per unknown
 * image per week.
 */
class Levers_Lever_Add_Missing_Image_Dimensions extends Levers_Lever {

	/** Cache lifetime for resolved dimensions of non-attachment files. */
	const CACHE_TTL = WEEK_IN_SECONDS;

	/** Shorter cache lifetime for unresolvable URLs, to avoid hammering. */
	const NEGATIVE_CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'add-missing-image-dimensions';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Add missing image dimensions', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Adds width/height to img and picture source tags that are missing them. Prevents layout shift and helps Core Web Vitals.', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'performance';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'proportions';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		if ( is_admin() ) {
			return;
		}

		// Run at 11 so we land just after core's wp_filter_content_tags,
		// which already covers WP-rendered images carrying wp-image-{id}.
		add_filter( 'the_content', array( $this, 'add_dimensions_to_html' ), 11 );
		add_filter( 'the_excerpt', array( $this, 'add_dimensions_to_html' ), 11 );
		add_filter( 'widget_text_content', array( $this, 'add_dimensions_to_html' ), 11 );
		add_filter( 'post_thumbnail_html', array( $this, 'add_dimensions_to_html' ), 11 );
	}

	/* ---------------------------------------------------------------------
	 * HTML walker
	 * ------------------------------------------------------------------- */

	/**
	 * Filter callback: find every <img>/<source> in the HTML and add
	 * dimensions to the ones that don't have any.
	 *
	 * @param string $html Filterable HTML.
	 * @return string
	 */
	public function add_dimensions_to_html( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		if ( false === stripos( $html, '<img' ) && false === stripos( $html, '<source' ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'#<(img|source)\b[^>]*>#i',
			array( $this, 'process_tag' ),
			$html
		);
	}

	/**
	 * Decide what to do with a single matched tag.
	 *
	 * @param array $match preg_replace_callback match: [full, tagname].
	 * @return string
	 */
	private function process_tag( $match ) {
		$tag      = $match[0];
		$tag_name = strtolower( $match[1] );

		// Skip tags that already have either dimension - the author has
		// expressed intent, so we don't second-guess them.
		if ( $this->has_attribute( $tag, 'width' ) || $this->has_attribute( $tag, 'height' ) ) {
			return $tag;
		}

		$url = 'img' === $tag_name
			? $this->extract_attribute( $tag, 'src' )
			: $this->first_url_in_srcset( $this->extract_attribute( $tag, 'srcset' ) );

		if ( '' === $url ) {
			return $tag;
		}

		// data: URIs are inline; we can't measure them cheaply, and they're
		// usually tiny placeholders the author would set dims on themselves
		// if it mattered.
		if ( 0 === stripos( $url, 'data:' ) ) {
			return $tag;
		}

		$dimensions = $this->dimensions_for_url( $url );

		if ( ! $dimensions ) {
			return $tag;
		}

		return $this->inject_dimensions( $tag, $dimensions['width'], $dimensions['height'] );
	}

	/**
	 * Return the first comma-separated URL out of a srcset string.
	 *
	 * @param string $srcset Raw srcset attribute value.
	 * @return string URL or empty string.
	 */
	private function first_url_in_srcset( $srcset ) {
		if ( '' === $srcset ) {
			return '';
		}

		$first = strtok( $srcset, ',' );

		if ( false === $first ) {
			return '';
		}

		// Each candidate is "url descriptor" - take the url part.
		$parts = preg_split( '/\s+/', trim( $first ), 2 );

		return is_array( $parts ) && ! empty( $parts[0] ) ? $parts[0] : '';
	}

	/* ---------------------------------------------------------------------
	 * Attribute helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Whether the tag carries the named attribute (with any value).
	 *
	 * @param string $tag  Whole tag string.
	 * @param string $name Attribute name.
	 * @return bool
	 */
	private function has_attribute( $tag, $name ) {
		return (bool) preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=/i', $tag );
	}

	/**
	 * Extract a quoted or unquoted attribute value, or empty string.
	 *
	 * @param string $tag  Whole tag string.
	 * @param string $name Attribute name.
	 * @return string
	 */
	private function extract_attribute( $tag, $name ) {
		$quoted_pattern = '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/i';

		if ( preg_match( $quoted_pattern, $tag, $m ) ) {
			return $m[2];
		}

		$bare_pattern = '/\s' . preg_quote( $name, '/' ) . '\s*=\s*([^\s>]+)/i';

		if ( preg_match( $bare_pattern, $tag, $m ) ) {
			return $m[1];
		}

		return '';
	}

	/**
	 * Insert width and height before the closing > of a tag, preserving
	 * everything else exactly.
	 *
	 * @param string $tag    Original tag.
	 * @param int    $width  Width in px.
	 * @param int    $height Height in px.
	 * @return string
	 */
	private function inject_dimensions( $tag, $width, $height ) {
		$attrs = sprintf( ' width="%d" height="%d"', $width, $height );

		// Self-closing form (<img ... />) keeps the slash; bare form
		// (<img ...>) gets the attrs just before the >.
		if ( '/>' === substr( $tag, -2 ) ) {
			return rtrim( substr( $tag, 0, -2 ) ) . $attrs . ' />';
		}

		return rtrim( substr( $tag, 0, -1 ) ) . $attrs . '>';
	}

	/* ---------------------------------------------------------------------
	 * URL -> dimensions
	 * ------------------------------------------------------------------- */

	/**
	 * Find width/height for an image URL. Returns false when unknown.
	 *
	 * @param string $url Image URL (absolute or root-relative).
	 * @return array{width:int,height:int}|false
	 */
	private function dimensions_for_url( $url ) {
		$url = $this->normalize_url( $url );

		if ( '' === $url ) {
			return false;
		}

		$cache_key = 'levers_imgdims_' . md5( $url );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			// Negative cache stores 'none' so we can distinguish from a miss.
			if ( 'none' === $cached ) {
				return false;
			}

			if ( is_array( $cached ) && isset( $cached['width'], $cached['height'] ) ) {
				return $cached;
			}
		}

		$dimensions = $this->resolve_attachment_dimensions( $url );

		if ( ! $dimensions ) {
			$dimensions = $this->resolve_disk_dimensions( $url );
		}

		if ( $dimensions ) {
			set_transient( $cache_key, $dimensions, self::CACHE_TTL );
			return $dimensions;
		}

		set_transient( $cache_key, 'none', self::NEGATIVE_CACHE_TTL );
		return false;
	}

	/**
	 * Make a URL absolute. Drops query strings and fragments so cache
	 * keys aren't fragmented by cache-busters.
	 *
	 * @param string $url Source URL.
	 * @return string
	 */
	private function normalize_url( $url ) {
		$url = trim( html_entity_decode( $url ) );

		if ( '' === $url ) {
			return '';
		}

		// Strip ?query and #fragment - cache by the bare resource.
		$hash = strpos( $url, '#' );
		if ( false !== $hash ) {
			$url = substr( $url, 0, $hash );
		}

		$query = strpos( $url, '?' );
		if ( false !== $query ) {
			$url = substr( $url, 0, $query );
		}

		// Protocol-relative -> absolute.
		if ( 0 === strpos( $url, '//' ) ) {
			$scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
			$url    = ( $scheme ? $scheme : 'https' ) . ':' . $url;
		}

		// Root-relative -> absolute.
		if ( '' !== $url && '/' === $url[0] ) {
			$url = home_url( $url );
		}

		return $url;
	}

	/**
	 * Try resolving the URL via the attachments table.
	 *
	 * @param string $url Absolute image URL.
	 * @return array{width:int,height:int}|false
	 */
	private function resolve_attachment_dimensions( $url ) {
		if ( ! function_exists( 'attachment_url_to_postid' ) ) {
			return false;
		}

		$post_id = attachment_url_to_postid( $url );

		if ( ! $post_id ) {
			return false;
		}

		$src = wp_get_attachment_image_src( $post_id, 'full' );

		if ( ! is_array( $src ) || empty( $src[1] ) || empty( $src[2] ) ) {
			return false;
		}

		return array(
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
		);
	}

	/**
	 * Try resolving the URL by mapping it to a filesystem path under
	 * WP_CONTENT_DIR and reading the file with getimagesize().
	 *
	 * Constrained to WP_CONTENT_DIR so we never try to read outside the
	 * site's content tree, even if some plugin rewrites image URLs to
	 * point at strange places.
	 *
	 * @param string $url Absolute image URL.
	 * @return array{width:int,height:int}|false
	 */
	private function resolve_disk_dimensions( $url ) {
		$content_url = content_url();

		if ( 0 !== strpos( $url, $content_url ) ) {
			return false;
		}

		$relative = ltrim( substr( $url, strlen( $content_url ) ), '/' );

		if ( '' === $relative ) {
			return false;
		}

		// Refuse traversal segments outright - we trust no .. anywhere
		// in the URL-derived path.
		if ( false !== strpos( $relative, '..' ) ) {
			return false;
		}

		$path = trailingslashit( WP_CONTENT_DIR ) . $relative;

		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- non-image files yield false.

		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
			return false;
		}

		return array(
			'width'  => (int) $info[0],
			'height' => (int) $info[1],
		);
	}
}
