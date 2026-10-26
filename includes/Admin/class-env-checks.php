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

                        $before_rules = strtok( $group, "\n" );

                        foreach ( preg_split( '/\R/', (string) $group ) as $line ) {
                                $line = trim( (string) $line );

                                if ( 0 === stripos( $line, 'User-agent:' ) ) {
                                        $before_rules = $line;
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
                <h3 style="margin-top:20px;"><?php esc_html_e( 'AI crawler monitor', 'agentic-commerce-ai-product-feed' ); ?></h3>

                <table class="widefat striped" style="max-width:820px;">
                        <tbody>
                                <?php foreach ( $results as $result ) : ?>
                                        <?php
                                        $color = ( 'pass' === $result['status'] ) ? '#46b450' : ( ( 'warn' === $result['status'] ) ? '#dba617' : '#dc3232' );
                                        $icon  = ( 'pass' === $result['status'] ) ? '✓' : ( ( 'warn' === $result['status'] ) ? '!' : '✕' );
                                        ?>
                                        <tr>
                                                <td style="width:26px;color:<?php echo esc_attr( $color ); ?>;font-weight:700;"><?php echo esc_html( $icon ); ?></td>
                                                <td>
                                                        <strong><?php echo esc_html( $result['label'] ); ?></strong><br />
                                                        <span style="color:#50575e;"><?php echo esc_html( $result['detail'] ); ?></span>
                                                </td>
                                        </tr>

                                        <?php if ( ! empty( $result['rows'] ) ) : ?>
                                                <?php foreach ( $result['rows'] as $bot ) : ?>
                                                        <tr>
                                                                <td style="color:<?php echo $bot['blocked'] ? '#dc3232' : '#46b450'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static color constants. ?>;"><?php echo $bot['blocked'] ? '✕' : '✓'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static icons. ?></td>
                                                                <td>
                                                                        <code><?php echo esc_html( $bot['agent'] ); ?></code> — <?php echo esc_html( $bot['label'] ); ?>
                                                                        <?php if ( $bot['blocked'] ) : ?>
                                                                                <strong style="color:#dc3232;"><?php esc_html_e( 'BLOCKED', 'agentic-commerce-ai-product-feed' ); ?></strong>
                                                                        <?php endif; ?>
                                                                </td>
                                                        </tr>
                                                <?php endforeach; ?>
                                        <?php endif; ?>
                                <?php endforeach; ?>
                        </tbody>
                </table>

                <p style="margin-top:10px;">
                        <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acpfw_env_recheck' ), 'acpfw_env_recheck' ) ); ?>">
                                <?php esc_html_e( 'Re-run checks', 'agentic-commerce-ai-product-feed' ); ?>
                        </a>
                </p>
                <?php
        }
}
