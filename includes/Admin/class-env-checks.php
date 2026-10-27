<?php
/**
 * AI Bot Allowlist & Crawler Monitor.
 *
 * Environment health checks for the dashboard:
 *
 *  1. "Discourage search engines" WordPress option (blocks honest crawlers).
 *  2. robots.txt scan: warns when an AI crawler (GPTBot, OAI-SearchBot,
 *     PerplexityBot, ClaudeBot, ...) is fully disallowed — the #1 reason a
 *     store never appears in AI answers despite a perfect feed.
 *  3. Live reachability of /llms.txt and the JSONL feed URL.
 *
 * Results are cached in a transient for 30 minutes; the dashboard offers a
 * "Re-run checks" button.
 *
 * @package ACPFW
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs and renders the AI-crawler environment checks.
 */
final class ACPFW_Env_Checks {

        const CACHE_KEY = 'acpfw_env_check';

        /**
         * AI crawlers worth watching, with friendly labels.
         *
         * @return array
         */
        private static function monitored_bots() {
                return array(
                        'GPTBot'             => __( 'OpenAI training crawler', 'agentic-commerce-ai-product-feed' ),
                        'OAI-SearchBot'      => __( 'ChatGPT Search', 'agentic-commerce-ai-product-feed' ),
                        'ChatGPT-User'       => __( 'ChatGPT user browsing', 'agentic-commerce-ai-product-feed' ),
                        'PerplexityBot'      => __( 'Perplexity Shopping', 'agentic-commerce-ai-product-feed' ),
                        'ClaudeBot'          => __( 'Claude', 'agentic-commerce-ai-product-feed' ),
                        'Google-Extended'    => __( 'Gemini / Google AI', 'agentic-commerce-ai-product-feed' ),
                        'CCBot'              => __( 'Common Crawl (feeds many AI models)', 'agentic-commerce-ai-product-feed' ),
                        'Amazonbot'          => __( 'Amazon AI (Rufus)', 'agentic-commerce-ai-product-feed' ),
                );
        }

        /**
         * Constructor.
         */
        public function __construct() {
                add_action( 'admin_post_acpfw_env_recheck', array( $this, 'handle_recheck' ) );
        }

        /**
         * "Re-run checks" button handler.
         *
         * @return void
         */
        public function handle_recheck() {
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_die( esc_html__( 'You are not allowed to manage settings.', 'agentic-commerce-ai-product-feed' ) );
                }

                check_admin_referer( 'acpfw_env_recheck' );

                delete_transient( self::CACHE_KEY );
                self::get_results( true );

                wp_safe_redirect(
                        add_query_arg(
                                array(
                                        'page'         => 'acpfw-feed',
                                        'acpfw_notice' => 'env_rechecked',
                                ),
                                admin_url( 'admin.php' )
                        )
                );
                exit;
        }

        /**
         * Run (or fetch cached) check results.
         *
         * @param bool $force Skip the cache.
         * @return array
         */
        public static function get_results( $force = false ) {
                if ( ! $force ) {
                        $cached = get_transient( self::CACHE_KEY );

                        if ( is_array( $cached ) ) {
                                return $cached;
                        }
                }

                $results = array();

                // 0. Public site URL? A dev site on localhost/private IPs can
                // generate perfect feeds that no AI crawler can ever fetch.
                $results['site_url'] = self::check_site_url();

                // 1. Search engines discouraged?
                $blog_public = (string) get_option( 'blog_public', '1' );

                $results['blog_public'] = array(
                        'status' => ( '1' === $blog_public ) ? 'pass' : 'fail',
                        'label'  => __( 'Search engines allowed', 'agentic-commerce-ai-product-feed' ),
                        'detail' => ( '1' === $blog_public )
                                ? __( 'WordPress is not discouraging crawlers.', 'agentic-commerce-ai-product-feed' )
                                : __( 'Settings > Reading has "Discourage search engines" enabled — most AI crawlers will respect this and stay away.', 'agentic-commerce-ai-product-feed' ),
                );

                // 2. robots.txt AI bot scan.
                $results['robots'] = self::check_robots();

                // 3. llms.txt reachable?
                $results['llms'] = self::check_url(
                        ACPFW_LLMS_Txt_Generator::get_llms_url(),
                        __( 'llms.txt reachable', 'agentic-commerce-ai-product-feed' )
                );

                // 4. JSONL feed reachable?
                $feed_check = self::check_url(
                        ACPFW_OpenAI_Feed_Generator::get_feed_url( 'jsonl' ),
                        __( 'Product feed reachable', 'agentic-commerce-ai-product-feed' )
                );

                if ( 'fail' === $feed_check['status'] ) {
                        $feed_check['detail'] = __( 'Feed file not found yet — click "Generate / refresh feeds now".', 'agentic-commerce-ai-product-feed' );
                }

                $results['feed'] = $feed_check;

                set_transient( self::CACHE_KEY, $results, 30 * MINUTE_IN_SECONDS );

                return $results;
        }

        /**
         * Site URL sanity: publicly resolvable host + HTTPS scheme.
         *
         * Feeds generated on http://localhost are fine during development,
         * but the merchant must be warned before submitting them anywhere.
         *
         * @return array
         */
        private static function check_site_url() {
                $host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
                $scheme = (string) wp_parse_url( home_url(), PHP_URL_SCHEME );

                $private = in_array( $host, array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' ), true )
                        || '.localhost' === substr( $host, -10 )
                        || '.local' === substr( $host, -6 )
                        || (bool) preg_match( '/^192\.168\./', $host )
                        || (bool) preg_match( '/^10\./', $host )
                        || (bool) preg_match( '/^172\.(1[6-9]|2[0-9]|3[01])\./', $host );

                if ( $private ) {
                        return array(
                                'status' => 'fail',
                                'label'  => __( 'Site URL is publicly reachable', 'agentic-commerce-ai-product-feed' ),
                                'detail' => sprintf(
                                        /* translators: %s: current site host, e.g. localhost */
                                        __( 'Your WordPress address points at "%s" — a private/dev host. Every feed row and llms.txt link carries this hostname, so AI crawlers and OpenAI cannot fetch anything. Move the site to a public domain (Settings > General) before submitting the feed.', 'agentic-commerce-ai-product-feed' ),
                                        $host
                                ),
                        );
                }

                if ( 'https' !== strtolower( $scheme ) ) {
                        return array(
                                'status' => 'warn',
                                'label'  => __( 'Site URL uses HTTPS', 'agentic-commerce-ai-product-feed' ),
                                'detail' => __( 'Your site URL starts with http://. Most AI shopping channels expect https:// product and image URLs — install an SSL certificate and update the WordPress address before going live.', 'agentic-commerce-ai-product-feed' ),
                        );
                }

                return array(
                        'status' => 'pass',
                        'label'  => __( 'Site URL is publicly reachable', 'agentic-commerce-ai-product-feed' ),
                        'detail' => __( 'Your WordPress address is a public HTTPS hostname — feeds and discovery URLs are fetchable from the internet.', 'agentic-commerce-ai-product-feed' ),
                );
        }

        /**
         * Scan the effective robots.txt output for AI bot blocks.
         *
         * @return array
         */
        private static function check_robots() {
                $public = '1' === (string) get_option( 'blog_public', '1' );

                $base   = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\n";
                $robots = $public ? (string) apply_filters( 'robots_txt', $base, true ) : '';
                $rows   = array();
                $status = 'pass';

                foreach ( self::monitored_bots() as $agent => $label ) {
                        $blocked = self::bot_is_blocked( $robots, $agent );
                        $rows[]  = array(
                                'agent'   => $agent,
                                'label'   => $label,
                                'blocked' => $blocked,
                        );

                        if ( $blocked ) {
                                $status = 'fail';
                        }
                }

                return array(
                        'status' => $status,
                        'label'  => __( 'AI crawlers in robots.txt', 'agentic-commerce-ai-product-feed' ),
                        'detail' => ( 'fail' === $status )
                                ? __( 'One or more AI crawlers are fully blocked in robots.txt (by a plugin, your host, or Cloudflare). Unblock them or AI assistants cannot read your products.', 'agentic-commerce-ai-product-feed' )
                                : __( 'No AI crawler is blocked by WordPress robots.txt filters. (Hosting firewalls and CDNs are outside WordPress and not covered here.)', 'agentic-commerce-ai-product-feed' ),
                        'rows'   => $rows,
                );
        }

        /**
         * Heuristic: does a robots.txt body fully disallow this user agent?
         *
         * @param string $robots Effective robots.txt text.
         * @param string $agent  User agent token.
         * @return bool
         */
        private static function bot_is_blocked( $robots, $agent ) {
                if ( '' === $robots || false === stripos( $robots, $agent ) ) {
                        return false;
                }

                // Split into "User-agent:" groups and inspect the matching one.
                $groups = preg_split( '/(?=User-agent:)/i', $robots );

                foreach ( (array) $groups as $group ) {
                        if ( false === stripos( $group, 'User-agent: ' . $agent ) && false === stripos( $group, 'User-agent:' . $agent ) ) {
                                continue;
                        }

                        foreach ( preg_split( '/\R/', (string) $group ) as $line ) {
                                $line = trim( (string) $line );

                                if ( 0 === stripos( $line, 'User-agent:' ) ) {
                                        continue;
                                }

                                if ( 0 === stripos( $line, 'Disallow:' ) ) {
                                        $path = trim( substr( $line, strlen( 'Disallow:' ) ) );

                                        if ( '' === $path || '/' === $path ) {
                                                return true;
                                        }
                                }
                        }
                }

                return false;
        }

        /**
         * Reachability check for a public URL on this very site.
         *
         * @param string $url  URL to probe.
         * @param string $label Friendly label.
         * @return array
         */
        private static function check_url( $url, $label ) {
                $response = wp_remote_head( $url, array( 'timeout' => 10 ) );

                if ( is_wp_error( $response ) ) {
                        // Some hosts 405 HEAD; retry with GET before giving up.
                        $response = wp_remote_get( $url, array( 'timeout' => 10 ) );
                }

                if ( is_wp_error( $response ) ) {
                        return array(
                                'status' => 'warn',
                                'label'  => $label,
                                'detail' => __( 'Could not reach the URL from inside WordPress (loopback requests may be blocked by your host — common on local dev). Visit the URL manually to confirm.', 'agentic-commerce-ai-product-feed' ),
                        );
                }

                $code = (int) wp_remote_retrieve_response_code( $response );

                return array(
                        'status' => ( $code >= 200 && $code < 300 ) ? 'pass' : 'fail',
                        'label'  => $label,
                        'detail' => ( $code >= 200 && $code < 300 )
                                ? sprintf( /* translators: %d: HTTP status code */ __( 'HTTP %d — serving correctly.', 'agentic-commerce-ai-product-feed' ), $code )
                                : sprintf( /* translators: %d: HTTP status code */ __( 'HTTP %d — see the fix hints on this page. If permalinks were just enabled, re-save Settings > Permalinks.', 'agentic-commerce-ai-product-feed' ), $code ),
                );
        }

        /**
         * Render the checks panel (inside the dashboard discovery section).
         *
         * @return void
         */
        public static function render() {
                $results = self::get_results();
                ?>
                <h3 style="margin:22px 0 6px;font-size:14px;font-weight:600;"><?php esc_html_e( 'AI crawler monitor', 'agentic-commerce-ai-product-feed' ); ?></h3>

                <ul class="acpfw-monitor">
                        <?php foreach ( $results as $result ) : ?>
                                <?php
                                $mod = ( 'pass' === $result['status'] ) ? 'pass' : ( ( 'warn' === $result['status'] ) ? 'warn' : 'fail' );
                                $icon = ( 'pass' === $result['status'] ) ? '✓' : ( ( 'warn' === $result['status'] ) ? '!' : '✕' );
                                ?>
                                <li>
                                        <span class="acpfw-monitor__icon acpfw-monitor__icon--<?php echo esc_attr( $mod ); ?>"><?php echo esc_html( $icon ); ?></span>
                                        <span>
                                                <span class="acpfw-monitor__label"><?php echo esc_html( $result['label'] ); ?></span>
                                                <span class="acpfw-monitor__detail" style="display:block;"><?php echo esc_html( $result['detail'] ); ?></span>
                                        </span>
                                </li>

                                <?php if ( ! empty( $result['rows'] ) ) : ?>
                                        <?php foreach ( $result['rows'] as $bot ) : ?>
                                                <?php $bot_mod = $bot['blocked'] ? 'fail' : 'pass'; ?>
                                                <li style="padding:5px 0 5px 12px;">
                                                        <span class="acpfw-monitor__icon acpfw-monitor__icon--<?php echo esc_attr( $bot_mod ); ?>" style="width:18px;height:18px;font-size:10px;"><?php echo $bot['blocked'] ? '✕' : '✓'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static glyphs. ?></span>
                                                        <span style="font-size:12.5px;color:#3c434a;">
                                                                <code><?php echo esc_html( $bot['agent'] ); ?></code> — <?php echo esc_html( $bot['label'] ); ?>
                                                                <?php if ( $bot['blocked'] ) : ?>
                                                                        <strong style="color:var(--acpfw-red,#cc3333);"><?php esc_html_e( 'BLOCKED', 'agentic-commerce-ai-product-feed' ); ?></strong>
                                                                <?php endif; ?>
                                                        </span>
                                                </li>
                                        <?php endforeach; ?>
                                <?php endif; ?>
                        <?php endforeach; ?>
                </ul>

                <p style="margin:14px 0 0;">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="acpfw-inline-form">
                                <input type="hidden" name="action" value="acpfw_env_recheck" />
                                <?php wp_nonce_field( 'acpfw_env_recheck' ); ?>
                                <button type="submit" class="button acpfw-btn">
                                        <span class="dashicons dashicons-update-alt" style="margin-top:3px;"></span>
                                        <?php esc_html_e( 'Re-run checks', 'agentic-commerce-ai-product-feed' ); ?>
                                </button>
                        </form>
                </p>
                <?php
        }
}
