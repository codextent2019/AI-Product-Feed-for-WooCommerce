<?php
/**
 * Image resolver: guarantees feed rows ship a JPEG/PNG image URL.
 *
 * OpenAI's Commerce Feed spec rejects WebP/AVIF image URLs. Many stores now
 * serve WebP (via optimization plugins or core conversion), so this resolver
 * walks a small decision chain to find (or generate) a compliant copy:
 *
 *  1. Original is JPEG/PNG -> pick the best-sized compliant candidate
 *     (largest generated size within a 500-2600px quality band, else the
 *     scaled attached file, else the untouched original).
 *  2. Original is WebP/AVIF -> best compliant JPEG/PNG among generated sizes.
 *  3. A JPEG/PNG file sits next to the converted file on disk (kept as backup
 *     by most optimization plugins) -> ship its URL.
 *  4. Generate a JPEG copy on the fly with WP_Image_Editor (cached in meta).
 *  5. Last resort: ship the original URL so the row still makes it out.
 *
 * @package ACPFW
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves feed-compliant image URLs for attachments.
 */
final class ACPFW_Image_Resolver {

        /**
         * Public entry point: feed-compliant URL for an attachment ID.
         *
         * @param int $attachment_id Attachment (image) ID.
         * @return string Absolute URL, or '' when nothing resolvable exists.
         */
        public static function get_feed_url( $attachment_id ) {
                $attachment_id = absint( $attachment_id );

                if ( ! $attachment_id ) {
                        return '';
                }

                $url = self::resolve( $attachment_id );

                /**
                 * Filter the resolved feed image URL.
                 *
                 * @param string $url           Resolved URL ('' when unresolvable).
                 * @param int    $attachment_id Attachment ID.
                 */
                return (string) apply_filters( 'acpfw_feed_image_url', $url, $attachment_id );
        }

        /** Longest edge below which an image is considered too small for AI carousels. */
        const MIN_EDGE = 500;

        /** Longest edge above which an image is considered wastefully large (unscaled originals). */
        const MAX_EDGE = 2600;

        /**
         * Internal resolution chain.
         *
         * @param int $attachment_id Attachment ID.
         * @return string
         */
        private static function resolve( $attachment_id ) {
                $file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

                if ( '' === $file ) {
                        return (string) wp_get_attachment_url( $attachment_id );
                }

                $ext = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );

                // Step 1: original is already compliant — pick a sensibly sized
                // compliant candidate (never a 150px thumbnail, never a 6000px
                // unscaled original when a scaled version exists).
                if ( in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
                        return self::best_compliant_url( $attachment_id, $file );
                }

                // Step 2: original is WebP/AVIF — best compliant generated size.
                $size_url = self::best_size_url( $attachment_id, $file );

                if ( '' !== $size_url ) {
                        return $size_url;
                }

                // Step 3: compliant sibling next to the converted file on disk.
                $upload = wp_get_upload_dir();
                $subdir = ltrim( trailingslashit( dirname( $file ) ), '/' );
                $name   = pathinfo( $file, PATHINFO_FILENAME );

                foreach ( array( 'jpg', 'jpeg', 'png' ) as $candidate_ext ) {
                        if ( file_exists( trailingslashit( $upload['basedir'] ) . $subdir . $name . '.' . $candidate_ext ) ) {
                                return trailingslashit( $upload['baseurl'] ) . $subdir . $name . '.' . $candidate_ext;
                        }
                }

                // Step 4: generate a JPEG copy on the fly (result cached in meta).
                $generated = self::generate_jpeg_copy( $attachment_id );
                if ( '' !== $generated ) {
                        return $generated;
                }

                // Step 5: last resort — ship what exists.
                return (string) wp_get_attachment_url( $attachment_id );
        }

        /**
         * Best compliant URL for an attachment whose original is already
         * JPEG/PNG: prefers a generated size within the quality band, then
         * the (possibly -scaled) attached file, then the untouched original.
         *
         * @param int    $attachment_id Attachment ID.
         * @param string $file          Relative attached-file path.
         * @return string
         */
        private static function best_compliant_url( $attachment_id, $file ) {
                $size_url = self::best_size_url( $attachment_id, $file );

                if ( '' !== $size_url ) {
                        return $size_url;
                }

                // The attached file URL is the scaled version when WordPress
                // downscaled a huge original ("big image threshold").
                $attached_url = (string) wp_get_attachment_url( $attachment_id );

                if ( '' !== $attached_url ) {
                        return $attached_url;
                }

                if ( function_exists( 'wp_get_original_image_url' ) ) {
                        $original = wp_get_original_image_url( $attachment_id );
                        if ( $original ) {
                                return (string) $original;
                        }
                }

                return (string) wp_get_attachment_url( $attachment_id );
        }

        /**
         * Pick the best JPEG/PNG URL among the attachment's generated sizes.
         *
         * Candidates are scored by area: inside the quality band
         * (MIN_EDGE..MAX_EDGE on the longest edge) the LARGEST wins; when
         * nothing fits the band, the smallest oversized candidate beats a
         * giant original. Tiny thumbnails only win when nothing else exists.
         *
         * @param int    $attachment_id Attachment ID.
         * @param string $file          Relative attached-file path.
         * @return string '' when no compliant size exists.
         */
        private static function best_size_url( $attachment_id, $file ) {
                $meta = wp_get_attachment_metadata( $attachment_id );

                if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
                        return '';
                }

                $in_band  = array();
                $too_big  = array();

                foreach ( $meta['sizes'] as $size ) {
                        if ( empty( $size['file'] ) ) {
                                continue;
                        }

                        $size_ext = strtolower( (string) pathinfo( $size['file'], PATHINFO_EXTENSION ) );

                        if ( ! in_array( $size_ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
                                continue;
                        }

                        $width  = isset( $size['width'] ) ? (int) $size['width'] : 0;
                        $height = isset( $size['height'] ) ? (int) $size['height'] : 0;
                        $area   = max( 1, $width * $height );
                        $edge   = max( $width, $height );

                        $candidate = array(
                                'url'  => self::uploads_url_for( ltrim( dirname( $file ) . '/' . $size['file'], './' ) ),
                                'area' => $area,
                        );

                        if ( $edge >= self::MIN_EDGE && $edge <= self::MAX_EDGE ) {
                                $in_band[] = $candidate;
                        } elseif ( $edge > self::MAX_EDGE ) {
                                $too_big[] = $candidate;
                        }
                        // Smaller than MIN_EDGE: kept only as an implicit last
                        // resort (OpenAI quality floor) — not selected here.
                }

                if ( ! empty( $in_band ) ) {
                        usort( $in_band, array( __CLASS__, 'sort_by_area_desc' ) );

                        return $in_band[0]['url'];
                }

                if ( ! empty( $too_big ) ) {
                        usort( $too_big, array( __CLASS__, 'sort_by_area_asc' ) );

                        return $too_big[0]['url'];
                }

                return '';
        }

        /**
         * usort callback: area descending.
         *
         * @param array $a Candidate.
         * @param array $b Candidate.
         * @return int
         */
        private static function sort_by_area_desc( $a, $b ) {
                return $b['area'] <=> $a['area'];
        }

        /**
         * usort callback: area ascending.
         *
         * @param array $a Candidate.
         * @param array $b Candidate.
         * @return int
         */
        private static function sort_by_area_asc( $a, $b ) {
                return $a['area'] <=> $b['area'];
        }

        /**
         * Generate a JPEG copy of a WebP/AVIF image using WP_Image_Editor.
         * The generated file URL is cached in attachment meta.
         *
         * @param int $attachment_id Attachment ID.
         * @return string Generated file URL, or '' when generation is impossible.
         */
        private static function generate_jpeg_copy( $attachment_id ) {
                $cached = (string) get_post_meta( $attachment_id, '_acpfw_jpeg_copy_url', true );

                if ( '' !== $cached ) {
                        $upload = wp_get_upload_dir();
                        $path   = str_replace( trailingslashit( $upload['baseurl'] ), trailingslashit( $upload['basedir'] ), $cached );

                        if ( file_exists( $path ) ) {
                                return $cached;
                        }

                        delete_post_meta( $attachment_id, '_acpfw_jpeg_copy_url' );
                }

                $upload   = wp_get_upload_dir();
                $rel_file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

                if ( '' === $rel_file ) {
                        return '';
                }

                $abs_path = trailingslashit( $upload['basedir'] ) . ltrim( $rel_file, '/' );

                if ( ! file_exists( $abs_path ) ) {
                        return '';
                }

                if ( ! function_exists( 'wp_get_image_editor' ) ) {
                        require_once ABSPATH . 'wp-includes/media.php';
                }

                $editor = wp_get_image_editor( $abs_path );

                if ( is_wp_error( $editor ) ) {
                        return '';
                }

                if ( method_exists( $editor, 'supports_mime_type' ) && ! $editor->supports_mime_type( 'image/jpeg' ) ) {
                        return '';
                }

                $editor->set_quality( 90 );
                $result = $editor->save( null, 'image/jpeg' );

                if ( is_wp_error( $result ) || empty( $result['path'] ) ) {
                        return '';
                }

                $url = str_replace( trailingslashit( $upload['basedir'] ), trailingslashit( $upload['baseurl'] ), $result['path'] );

                // Guard: substitution must have produced a URL, not a filesystem path.
                if ( '' === $url || 0 === strpos( $url, trailingslashit( $upload['basedir'] ) ) ) {
                        return '';
                }

                update_post_meta( $attachment_id, '_acpfw_jpeg_copy_url', esc_url_raw( $url ) );

                return $url;
        }

        /**
         * Build an absolute uploads URL for a relative uploads path.
         *
         * @param string $relative Relative path under wp-content/uploads.
         * @return string
         */
        private static function uploads_url_for( $relative ) {
                $upload = wp_get_upload_dir();

                return trailingslashit( $upload['baseurl'] ) . ltrim( $relative, '/' );
        }
}
