<?php
/**
 * Lever: strip EXIF/GPS metadata from uploaded images.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Removes EXIF metadata - including GPS coordinates - from images at
 * upload time, while preserving the image's natural orientation.
 *
 * Phones routinely embed GPS coordinates, camera make/model, capture
 * date and a thumbnail inside JPEG headers. WordPress doesn't strip
 * any of this by default, so a casually-uploaded photo can quietly
 * leak the contributor's home address on the public site.
 *
 * This lever hooks `wp_handle_upload`, reads the EXIF orientation
 * first so the photo doesn't end up sideways, rotates the pixels to
 * match, then writes the file back without any EXIF block.
 *
 * Prefers Imagick (which can autoOrient + stripImage in two calls);
 * falls back to GD for JPEGs (re-encodes the image, which discards
 * EXIF as a side effect, after a manual orientation rotate).
 */
class Levers_Lever_Strip_Exif_Uploads extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'strip-exif-uploads';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Strip EXIF/GPS from uploads', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Strips EXIF metadata (including GPS coordinates) from uploaded JPEGs while preserving the image's natural orientation.", 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'security';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'map-pin-off';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_filter( 'wp_handle_upload', array( $this, 'strip_image_metadata' ) );
	}

	/**
	 * Strip metadata after an upload lands.
	 *
	 * @param mixed $upload wp_handle_upload return: array with file/url/type.
	 * @return mixed
	 */
	public function strip_image_metadata( $upload ) {
		if ( ! is_array( $upload ) || empty( $upload['file'] ) || empty( $upload['type'] ) ) {
			return $upload;
		}

		$type = strtolower( (string) $upload['type'] );

		// JPEG / TIFF are the formats that actually carry EXIF in the wild.
		// PNG and WebP can but rarely do; skip them to avoid needless
		// re-encoding.
		if ( ! in_array( $type, array( 'image/jpeg', 'image/jpg', 'image/pjpeg', 'image/tiff' ), true ) ) {
			return $upload;
		}

		$path = (string) $upload['file'];

		if ( ! file_exists( $path ) || ! is_writable( $path ) ) {
			return $upload;
		}

		if ( $this->strip_with_imagick( $path ) ) {
			return $upload;
		}

		$this->strip_with_gd( $path );

		return $upload;
	}

	/* ---------------------------------------------------------------------
	 * Backends
	 * ------------------------------------------------------------------- */

	/**
	 * Use Imagick to auto-orient and strip metadata. Returns true on success.
	 *
	 * @param string $path Image path.
	 * @return bool
	 */
	private function strip_with_imagick( $path ) {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( 'Imagick' ) ) {
			return false;
		}

		try {
			$im = new Imagick( $path );

			if ( method_exists( $im, 'autoOrient' ) ) {
				$im->autoOrient();
			} else {
				// Older Imagick: do it manually.
				$this->imagick_manual_orient( $im );
			}

			$im->stripImage();
			$im->writeImage( $path );
			$im->clear();
			$im->destroy();

			return true;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Pre-autoOrient fallback for old Imagick builds.
	 *
	 * @param Imagick $im Image handle.
	 * @return void
	 */
	private function imagick_manual_orient( $im ) {
		$orientation = $im->getImageOrientation();

		switch ( $orientation ) {
			case Imagick::ORIENTATION_BOTTOMRIGHT:
				$im->rotateImage( '#000', 180 );
				break;
			case Imagick::ORIENTATION_RIGHTTOP:
				$im->rotateImage( '#000', 90 );
				break;
			case Imagick::ORIENTATION_LEFTBOTTOM:
				$im->rotateImage( '#000', -90 );
				break;
		}

		$im->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
	}

	/**
	 * GD fallback for JPEG: read EXIF orientation, rotate pixels, re-encode.
	 *
	 * Re-encoding is what actually drops the EXIF (GD has no way to write it).
	 *
	 * @param string $path JPEG path.
	 * @return void
	 */
	private function strip_with_gd( $path ) {
		if ( ! function_exists( 'imagecreatefromjpeg' ) || ! function_exists( 'imagejpeg' ) ) {
			return;
		}

		$img = @imagecreatefromjpeg( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- non-image data fails silently.

		if ( ! $img ) {
			return;
		}

		// Apply EXIF orientation before re-encoding, so portrait phone
		// shots don't end up sideways once the orientation tag is gone.
		if ( function_exists( 'exif_read_data' ) ) {
			$exif        = @exif_read_data( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- missing/invalid EXIF is fine.
			$orientation = is_array( $exif ) && isset( $exif['Orientation'] ) ? (int) $exif['Orientation'] : 1;

			switch ( $orientation ) {
				case 3:
					$img = imagerotate( $img, 180, 0 );
					break;
				case 6:
					$img = imagerotate( $img, -90, 0 );
					break;
				case 8:
					$img = imagerotate( $img, 90, 0 );
					break;
			}
		}

		// q=90 keeps quality high; the recompression cost is one-off per upload.
		@imagejpeg( $img, $path, 90 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		imagedestroy( $img );
	}
}
