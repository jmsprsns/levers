<?php
/**
 * Lever: allow SVG uploads, sanitized on the way in.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Allows administrators to upload SVG images through the media library,
 * while running every uploaded file through a strict allowlist-based
 * sanitizer before it's stored.
 *
 * WordPress blocks SVG by default for good reason: an SVG is XML, and
 * XML can carry <script>, on*-handlers, external <use href="...">
 * references, foreignObject HTML, and entity-expansion (XXE) payloads.
 * Any of those, served back from /uploads, is XSS.
 *
 * The sanitizer used here is enshrined/svg-sanitize, the same library
 * Safe SVG ships, bundled under includes/vendor/svg-sanitize/. It works
 * by parsing the file with DOMDocument (external entities disabled),
 * stripping every tag and attribute that isn't on an explicit allowlist,
 * and rejecting documents that fail to parse.
 *
 * Uploads are gated on the manage_options capability - editors and
 * contributors cannot upload SVGs even with this lever on. That matches
 * Safe SVG's default and keeps the trust model tight: an SVG is, in
 * effect, executable, so only admins get to add one.
 *
 * Existing SVG attachments uploaded before this lever was enabled are
 * not re-sanitized; only new uploads pass through the filter.
 */
class Levers_Lever_Allow_Sanitized_Svg extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'allow-sanitized-svg';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Allow SVG uploads (sanitized)', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Lets admins upload SVGs to the media library, sanitizing each file to strip scripts, event handlers and external references.', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'admin-tools';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'file-image';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_filter( 'upload_mimes', array( $this, 'allow_svg_mime' ) );
		add_filter( 'wp_check_filetype_and_ext', array( $this, 'fix_svg_filetype_check' ), 10, 4 );
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'sanitize_uploaded_svg' ) );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'skip_svg_subsize_generation' ), 10, 2 );
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'expose_svg_to_media_modal' ), 10, 3 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'fallback_svg_dimensions' ), 10, 4 );

		add_action( 'admin_head', array( $this, 'media_library_svg_css' ) );
	}

	/* ---------------------------------------------------------------------
	 * Upload gate
	 * ------------------------------------------------------------------- */

	/**
	 * Add image/svg+xml to the allowed upload MIME map, but only for users
	 * who can manage_options. Editors and below see no change.
	 *
	 * @param array $mimes Existing extension => mime map.
	 * @return array
	 */
	public function allow_svg_mime( $mimes ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $mimes;
		}

		$mimes['svg']  = 'image/svg+xml';
		$mimes['svgz'] = 'image/svg+xml';

		return $mimes;
	}

	/**
	 * Work around WP 4.7+'s strict real-MIME check.
	 *
	 * Since SVG is XML, finfo classifies the file as text/xml, text/plain,
	 * or image/svg depending on libmagic. WordPress treats any disagreement
	 * with the extension as a rejection. Force the verdict back to
	 * image/svg+xml when the extension is .svg and the current user is
	 * allowed to upload SVGs.
	 *
	 * @param array        $data     Existing verdict array.
	 * @param string       $file     Absolute path to the file being checked.
	 * @param string       $filename Original filename.
	 * @param string[]|null $mimes   Allowed mimes for the current user.
	 * @return array
	 */
	public function fix_svg_filetype_check( $data, $file, $filename, $mimes ) {
		unset( $mimes ); // Not used; signature kept for the filter contract.

		if ( ! empty( $data['ext'] ) && ! empty( $data['type'] ) ) {
			return $data;
		}

		$ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( 'svg' !== $ext && 'svgz' !== $ext ) {
			return $data;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return $data;
		}

		$data['ext']             = $ext;
		$data['type']            = 'image/svg+xml';
		$data['proper_filename'] = $filename;

		return $data;
	}

	/* ---------------------------------------------------------------------
	 * Sanitization
	 * ------------------------------------------------------------------- */

	/**
	 * Sanitize SVG payloads before WordPress moves the file into uploads.
	 *
	 * Runs on wp_handle_upload_prefilter, which is the last hook before the
	 * temp file is moved. Reading and rewriting tmp_name here means the
	 * sanitized version is what ends up on disk; the original never gets
	 * stored.
	 *
	 * Reject (don't silently pass through) if the file looks like SVG by
	 * extension/type but the sanitizer refuses it - that's the safe choice
	 * when something looks structurally wrong.
	 *
	 * @param array $file $_FILES-shaped entry: name, type, tmp_name, error, size.
	 * @return array
	 */
	public function sanitize_uploaded_svg( $file ) {
		if ( ! is_array( $file ) || empty( $file['name'] ) || empty( $file['tmp_name'] ) ) {
			return $file;
		}

		if ( ! empty( $file['error'] ) ) {
			return $file;
		}

		if ( ! $this->looks_like_svg( $file ) ) {
			return $file;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			$file['error'] = __( 'You do not have permission to upload SVG files.', 'levers' );
			return $file;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local upload temp file.
		$contents = @file_get_contents( $file['tmp_name'] );

		if ( false === $contents || '' === $contents ) {
			$file['error'] = __( 'SVG file appears to be empty or unreadable.', 'levers' );
			return $file;
		}

		// Detect and inflate gzipped SVGZ before sanitizing.
		$is_gzipped = $this->is_gzipped( $contents );

		if ( $is_gzipped ) {
			$inflated = function_exists( 'gzdecode' ) ? @gzdecode( $contents ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt input fails silently.

			if ( false === $inflated || '' === $inflated ) {
				$file['error'] = __( 'SVG file could not be decoded.', 'levers' );
				return $file;
			}

			$contents = $inflated;
		}

		$cleaned = $this->run_sanitizer( $contents );

		if ( false === $cleaned ) {
			$file['error'] = __( 'SVG could not be sanitized and was rejected.', 'levers' );
			return $file;
		}

		// Re-compress if the upload arrived as .svgz.
		if ( $is_gzipped && function_exists( 'gzencode' ) ) {
			$compressed = @gzencode( $cleaned ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false !== $compressed ) {
				$cleaned = $compressed;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- writing back to the local upload temp file.
		$written = @file_put_contents( $file['tmp_name'], $cleaned );

		if ( false === $written ) {
			$file['error'] = __( 'Could not write the sanitized SVG.', 'levers' );
			return $file;
		}

		$file['size'] = $written;

		return $file;
	}

	/**
	 * Whether the upload looks SVG-shaped, by either MIME or extension.
	 *
	 * @param array $file $_FILES entry.
	 * @return bool
	 */
	private function looks_like_svg( $file ) {
		$type = isset( $file['type'] ) ? strtolower( (string) $file['type'] ) : '';

		if ( 'image/svg+xml' === $type || 'image/svg' === $type ) {
			return true;
		}

		$ext = strtolower( (string) pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		return 'svg' === $ext || 'svgz' === $ext;
	}

	/**
	 * Detect a gzip magic-number prefix - SVGZ files start with 1F 8B.
	 *
	 * @param string $contents File bytes.
	 * @return bool
	 */
	private function is_gzipped( $contents ) {
		return strlen( $contents ) >= 2 && "\x1f\x8b" === substr( $contents, 0, 2 );
	}

	/**
	 * Load the bundled sanitizer library and run it over $contents.
	 *
	 * Returns the cleaned XML string, or false if the sanitizer refused
	 * to parse the input.
	 *
	 * @param string $contents Raw SVG markup.
	 * @return string|false
	 */
	private function run_sanitizer( $contents ) {
		$this->require_sanitizer_library();

		try {
			$sanitizer = new \enshrined\svgSanitize\Sanitizer();
			$sanitizer->minify( false );
			$sanitizer->removeRemoteReferences( true );

			$cleaned = $sanitizer->sanitize( $contents );
		} catch ( Exception $e ) {
			return false;
		}

		if ( ! is_string( $cleaned ) || '' === $cleaned ) {
			return false;
		}

		return $cleaned;
	}

	/**
	 * require_once every file the sanitizer library needs.
	 *
	 * No composer here, so we hand-require in dependency order. The
	 * library is self-contained: only ext-dom and ext-libxml beyond this.
	 *
	 * @return void
	 */
	private function require_sanitizer_library() {
		if ( class_exists( '\\enshrined\\svgSanitize\\Sanitizer' ) ) {
			return;
		}

		$base = LEVERS_DIR . 'includes/vendor/svg-sanitize/src/';

		require_once $base . 'data/AttributeInterface.php';
		require_once $base . 'data/TagInterface.php';
		require_once $base . 'data/AllowedAttributes.php';
		require_once $base . 'data/AllowedTags.php';
		require_once $base . 'data/XPath.php';
		require_once $base . 'ElementReference/Resolver.php';
		require_once $base . 'ElementReference/Subject.php';
		require_once $base . 'ElementReference/Usage.php';
		require_once $base . 'Exceptions/NestingException.php';
		require_once $base . 'Helper.php';
		require_once $base . 'Sanitizer.php';
	}

	/* ---------------------------------------------------------------------
	 * Media library UX
	 * ------------------------------------------------------------------- */

	/**
	 * SVGs don't have raster sub-sizes; tell WordPress not to try.
	 *
	 * Without this, image_make_intermediate_size() emits a flurry of
	 * warnings when it tries to imagecopyresampled() something it
	 * thinks is an image but isn't.
	 *
	 * @param array $metadata      Generated attachment meta.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function skip_svg_subsize_generation( $metadata, $attachment_id ) {
		$mime = get_post_mime_type( $attachment_id );

		if ( 'image/svg+xml' !== $mime ) {
			return $metadata;
		}

		$path = get_attached_file( $attachment_id );

		if ( $path && file_exists( $path ) ) {
			$dimensions = $this->read_svg_dimensions( $path );

			$metadata = is_array( $metadata ) ? $metadata : array();
			$upload   = wp_get_upload_dir();

			$metadata['file']   = ltrim( str_replace( trailingslashit( (string) $upload['basedir'] ), '', $path ), '/' );
			$metadata['width']  = $dimensions['width'];
			$metadata['height'] = $dimensions['height'];
			$metadata['sizes']  = array();
		}

		return $metadata;
	}

	/**
	 * Show the actual SVG as its own thumbnail in the media modal.
	 *
	 * Without this, SVGs appear as a generic file icon in the picker,
	 * which is jarring once you've allowed them.
	 *
	 * @param array       $response   Attachment data already prepared for JS.
	 * @param WP_Post     $attachment Attachment post.
	 * @param array|false $meta       Attachment meta.
	 * @return array
	 */
	public function expose_svg_to_media_modal( $response, $attachment, $meta ) {
		unset( $meta );

		if ( ! is_array( $response ) || empty( $response['mime'] ) || 'image/svg+xml' !== $response['mime'] ) {
			return $response;
		}

		$url = wp_get_attachment_url( $attachment->ID );

		if ( ! $url ) {
			return $response;
		}

		$response['icon'] = $url;

		$path       = get_attached_file( $attachment->ID );
		$dimensions = $path && file_exists( $path )
			? $this->read_svg_dimensions( $path )
			: array(
				'width'  => 0,
				'height' => 0,
			);

		$response['width']  = $dimensions['width'];
		$response['height'] = $dimensions['height'];

		$response['sizes'] = array(
			'full' => array(
				'url'         => $url,
				'width'       => $dimensions['width'],
				'height'      => $dimensions['height'],
				'orientation' => $dimensions['height'] > $dimensions['width'] ? 'portrait' : 'landscape',
			),
		);

		return $response;
	}

	/**
	 * Give wp_get_attachment_image() something to work with for SVGs.
	 *
	 * Without dimensions, WordPress returns false from
	 * wp_get_attachment_image_src() and the front-end img tag never
	 * renders.
	 *
	 * @param array|false  $image         Existing image data (url, w, h, is_intermediate).
	 * @param int          $attachment_id Attachment id.
	 * @param string|int[] $size          Requested size.
	 * @param bool         $icon          Whether an icon was requested.
	 * @return array|false
	 */
	public function fallback_svg_dimensions( $image, $attachment_id, $size, $icon ) {
		unset( $size, $icon );

		if ( ! empty( $image ) ) {
			return $image;
		}

		if ( 'image/svg+xml' !== get_post_mime_type( $attachment_id ) ) {
			return $image;
		}

		$url = wp_get_attachment_url( $attachment_id );

		if ( ! $url ) {
			return $image;
		}

		$path       = get_attached_file( $attachment_id );
		$dimensions = $path && file_exists( $path )
			? $this->read_svg_dimensions( $path )
			: array(
				'width'  => 0,
				'height' => 0,
			);

		return array( $url, $dimensions['width'], $dimensions['height'], false );
	}

	/**
	 * Read an SVG's width/height by parsing the root <svg> element.
	 *
	 * Looks at width/height first, falls back to viewBox. Returns zeros
	 * when nothing usable is present rather than guessing.
	 *
	 * @param string $path Absolute path to the SVG file.
	 * @return array{width:int,height:int}
	 */
	private function read_svg_dimensions( $path ) {
		$zero = array(
			'width'  => 0,
			'height' => 0,
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local attachment file.
		$contents = @file_get_contents( $path );

		if ( false === $contents || '' === $contents ) {
			return $zero;
		}

		if ( $this->is_gzipped( $contents ) && function_exists( 'gzdecode' ) ) {
			$inflated = @gzdecode( $contents ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_string( $inflated ) && '' !== $inflated ) {
				$contents = $inflated;
			}
		}

		if ( ! preg_match( '/<svg\b[^>]*>/i', $contents, $match ) ) {
			return $zero;
		}

		$tag = $match[0];

		$width  = $this->extract_svg_length( $tag, 'width' );
		$height = $this->extract_svg_length( $tag, 'height' );

		if ( $width > 0 && $height > 0 ) {
			return array(
				'width'  => $width,
				'height' => $height,
			);
		}

		if ( preg_match( '/viewBox\s*=\s*(["\'])\s*([\-\d.]+)\s+([\-\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\1/i', $tag, $vb ) ) {
			$vb_width  = (int) round( (float) $vb[4] );
			$vb_height = (int) round( (float) $vb[5] );

			if ( $vb_width > 0 && $vb_height > 0 ) {
				return array(
					'width'  => $width > 0 ? $width : $vb_width,
					'height' => $height > 0 ? $height : $vb_height,
				);
			}
		}

		return $zero;
	}

	/**
	 * Pull a numeric length (px) out of a width/height attribute.
	 *
	 * Strips trailing unit suffixes. Percentages are treated as unknown.
	 *
	 * @param string $tag       The opening <svg ...> tag, raw.
	 * @param string $attribute "width" or "height".
	 * @return int
	 */
	private function extract_svg_length( $tag, $attribute ) {
		if ( ! preg_match( '/\b' . preg_quote( $attribute, '/' ) . '\s*=\s*(["\'])([^"\']+)\1/i', $tag, $m ) ) {
			return 0;
		}

		$raw = trim( $m[2] );

		if ( '' === $raw || false !== strpos( $raw, '%' ) ) {
			return 0;
		}

		$num = (float) preg_replace( '/[^0-9.\-]/', '', $raw );

		return $num > 0 ? (int) round( $num ) : 0;
	}

	/**
	 * Tiny CSS so SVGs render at full thumbnail size in media list/grid
	 * views instead of collapsing to their intrinsic (often tiny) size.
	 *
	 * @return void
	 */
	public function media_library_svg_css() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && ! in_array( $screen->id, array( 'upload', 'attachment' ), true ) ) {
			// Always render in the media modal too - admin_head fires
			// on every admin screen, and the modal can open from anywhere.
		}

		echo '<style id="levers-svg-thumbnails">'
			. '.media-icon img[src$=".svg"],'
			. '.attachment-preview .thumbnail img[src$=".svg"],'
			. '.wp-core-ui .attachment .thumbnail img[src$=".svg"]{'
			. 'width:100%;height:100%;object-fit:contain;'
			. '}</style>';
	}
}
