<?php
/**
 * Plugin Name: Cerrito Substack Importer
 * Plugin URI:  https://github.com/lougriffith/cerrito-substack-importer
 * Description: Imports posts from the Cerrito Substack RSS feed (https://cerrito.substack.com/feed) into WordPress. Runs on a configurable schedule and avoids duplicates.
 * Version:     1.1
 * Author:      Lou Griffith
 * Author URI:  https://lougriffith.com
 * License:     GPL2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────
// CONSTANTS
// ─────────────────────────────────────────────
define( 'CSI_FEED_URL',    'https://cerrito.substack.com/feed' );
define( 'CSI_OPTION_KEY',  'cerrito_substack_importer' );
define( 'CSI_CRON_HOOK',   'cerrito_substack_import_cron' );
define( 'CSI_MENU_SLUG',   'cerrito-substack-importer' );
define( 'CSI_GITHUB_USER', 'lougriffith' );
define( 'CSI_GITHUB_REPO', 'cerrito-substack-importer' );
define( 'CSI_PLUGIN_SLUG', plugin_basename( __FILE__ ) );
define( 'CSI_CURRENT_VER', '1.1' );

// ─────────────────────────────────────────────
// GITHUB AUTO-UPDATER
// ─────────────────────────────────────────────
/**
 * Hooks into WordPress' native update system to check GitHub Releases
 * for a newer version. When found, WordPress shows the standard
 * "update available" notice and handles download/install automatically.
 *
 * HOW TO RELEASE AN UPDATE:
 *  1. Bump the Version header above and CSI_CURRENT_VER constant.
 *  2. Commit and push to GitHub.
 *  3. Create a new Release on GitHub tagged vX.X (e.g. v1.2).
 *  4. Attach the plugin ZIP to the release
 *     (e.g. cerrito-substack-importer-1.2.zip).
 *  5. WordPress sites detect the update within 12 hours, or immediately
 *     via Dashboard > Updates > Check Again.
 */
class CSI_GitHub_Updater {

    private string $user;
    private string $repo;
    private string $slug;
    private string $current_version;
    private string $api_url;
    private ?object $release_data = null;

    public function __construct( string $user, string $repo, string $slug, string $current_version ) {
        $this->user            = $user;
        $this->repo            = $repo;
        $this->slug            = $slug;
        $this->current_version = $current_version;
        $this->api_url         = "https://api.github.com/repos/{$user}/{$repo}/releases/latest";

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 20, 3 );
        add_filter( 'upgrader_post_install',                 [ $this, 'post_install' ], 10, 3 );
    }

    /** Fetch latest release from GitHub, cached 12 hours. */
    private function get_release(): ?object {
        if ( $this->release_data ) return $this->release_data;

        $cache_key = 'csi_gh_release_' . md5( $this->api_url );
        $cached    = get_transient( $cache_key );
        if ( $cached ) {
            $this->release_data = $cached;
            return $cached;
        }

        $response = wp_remote_get( $this->api_url, [
            'timeout' => 15,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ),
            ],
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ) );
        if ( empty( $data->tag_name ) ) return null;

        set_transient( $cache_key, $data, 12 * HOUR_IN_SECONDS );
        $this->release_data = $data;
        return $data;
    }

    /** Strip leading "v" so "v1.2" becomes "1.2". */
    private function clean_version( string $tag ): string {
        return ltrim( $tag, 'v' );
    }

    /** Return best ZIP URL from a release (asset first, fallback to zipball). */
    private function get_zip_url( object $release ): string {
        if ( ! empty( $release->assets ) ) {
            foreach ( $release->assets as $asset ) {
                if ( str_ends_with( strtolower( $asset->name ), '.zip' ) ) {
                    return $asset->browser_download_url;
                }
            }
        }
        return $release->zipball_url ?? '';
    }

    /** Inject update data into WordPress' plugin update transient. */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;

        $release = $this->get_release();
        if ( ! $release ) return $transient;

        $remote_version = $this->clean_version( $release->tag_name );

        if ( version_compare( $remote_version, $this->current_version, '>' ) ) {
            $transient->response[ $this->slug ] = (object) [
                'slug'         => dirname( $this->slug ),
                'plugin'       => $this->slug,
                'new_version'  => $remote_version,
                'url'          => "https://github.com/{$this->user}/{$this->repo}",
                'package'      => $this->get_zip_url( $release ),
                'requires_php' => '7.4',
                'tested'       => get_bloginfo( 'version' ),
                'icons'        => [],
                'banners'      => [],
            ];
        }

        return $transient;
    }

    /** Populate the plugin info/changelog modal in WP admin. */
    public function plugin_info( $result, string $action, $args ) {
        if ( $action !== 'plugin_information' ) return $result;
        if ( ( $args->slug ?? '' ) !== dirname( $this->slug ) ) return $result;

        $release = $this->get_release();
        if ( ! $release ) return $result;

        return (object) [
            'name'          => 'Cerrito Substack Importer',
            'slug'          => dirname( $this->slug ),
            'version'       => $this->clean_version( $release->tag_name ),
            'author'        => '<a href="https://lougriffith.com">Lou Griffith</a>',
            'homepage'      => "https://github.com/{$this->user}/{$this->repo}",
            'download_link' => $this->get_zip_url( $release ),
            'requires_php'  => '7.4',
            'tested'        => get_bloginfo( 'version' ),
            'sections'      => [
                'description' => 'Imports posts from cerrito.substack.com into WordPress via RSS feed.',
                'changelog'   => nl2br( esc_html( $release->body ?? 'See GitHub releases for changelog.' ) ),
            ],
        ];
    }

    /**
     * After install, rename the extracted folder to the correct plugin
     * directory name (GitHub ZIPs unzip to "repo-tag/" which WordPress
     * won't recognise as the active plugin).
     */
    public function post_install( $response, array $hook_extra, array $result ) {
        global $wp_filesystem;

        $target = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname( $this->slug );
        $wp_filesystem->move( $result['destination'], $target );
        $result['destination'] = $target;

        if ( is_plugin_active( $this->slug ) ) {
            activate_plugin( $this->slug );
        }

        return $result;
    }
}

new CSI_GitHub_Updater( CSI_GITHUB_USER, CSI_GITHUB_REPO, CSI_PLUGIN_SLUG, CSI_CURRENT_VER );

// ─────────────────────────────────────────────
// ACTIVATION / DEACTIVATION
// ─────────────────────────────────────────────
register_activation_hook( __FILE__, 'csi_activate' );
register_deactivation_hook( __FILE__, 'csi_deactivate' );

function csi_activate(): void {
    $defaults = [
        'category'      => 0,
        'post_status'   => 'publish',
        'post_author'   => 1,
        'import_images' => 1,
        'frequency'     => 'hourly',
        'last_run'      => '',
        'last_message'  => '',
    ];
    if ( ! get_option( CSI_OPTION_KEY ) ) {
        add_option( CSI_OPTION_KEY, $defaults );
    }
    csi_schedule_cron();
}

function csi_deactivate(): void {
    $timestamp = wp_next_scheduled( CSI_CRON_HOOK );
    if ( $timestamp ) wp_unschedule_event( $timestamp, CSI_CRON_HOOK );
}

function csi_schedule_cron(): void {
    $opts = csi_get_opts();
    $freq = $opts['frequency'] ?? 'hourly';
    if ( ! wp_next_scheduled( CSI_CRON_HOOK ) ) {
        wp_schedule_event( time(), $freq, CSI_CRON_HOOK );
    }
}

// ─────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────
function csi_get_opts(): array {
    return wp_parse_args( get_option( CSI_OPTION_KEY, [] ), [
        'category'      => 0,
        'post_status'   => 'publish',
        'post_author'   => 1,
        'import_images' => 1,
        'frequency'     => 'hourly',
        'last_run'      => '',
        'last_message'  => '',
    ] );
}

function csi_save_opts( array $data ): void {
    update_option( CSI_OPTION_KEY, array_merge( csi_get_opts(), $data ) );
}

// ─────────────────────────────────────────────
// CORE IMPORT LOGIC
// ─────────────────────────────────────────────
add_action( CSI_CRON_HOOK, 'csi_run_import' );

function csi_run_import(): void {
    $opts = csi_get_opts();

    $response = wp_remote_get( CSI_FEED_URL, [ 'timeout' => 30 ] );
    if ( is_wp_error( $response ) ) {
        csi_save_opts( [
            'last_run'     => current_time( 'mysql' ),
            'last_message' => 'Feed fetch error: ' . $response->get_error_message(),
        ] );
        return;
    }

    $body = wp_remote_retrieve_body( $response );
    if ( empty( $body ) ) {
        csi_save_opts( [
            'last_run'     => current_time( 'mysql' ),
            'last_message' => 'Feed returned empty body.',
        ] );
        return;
    }

    libxml_use_internal_errors( true );
    $xml = simplexml_load_string( $body );
    libxml_clear_errors();

    if ( ! $xml ) {
        csi_save_opts( [
            'last_run'     => current_time( 'mysql' ),
            'last_message' => 'Could not parse RSS feed XML.',
        ] );
        return;
    }

    $items    = $xml->channel->item ?? [];
    $imported = 0;
    $skipped  = 0;

    foreach ( $items as $item ) {
        $title    = (string) $item->title;
        $link     = (string) $item->link;
        $pub_date = (string) $item->pubDate;
        $guid     = (string) $item->guid;

        // Prefer content:encoded, fall back to description
        $namespaces = $item->getNameSpaces( true );
        $content_ns = $namespaces['content'] ?? '';
        $content    = '';
        if ( $content_ns ) {
            $content_item = $item->children( $content_ns );
            $content      = (string) ( $content_item->encoded ?? '' );
        }
        if ( empty( $content ) ) {
            $content = (string) $item->description;
        }

        // Strip Substack subscribe/footer sections
        $content = preg_replace( '/<div[^>]*class="[^"]*subscribe[^"]*"[^>]*>.*?<\/div>/is', '', $content );

        // Skip duplicates
        $existing = get_posts( [
            'post_type'   => 'post',
            'meta_key'    => '_csi_substack_guid',
            'meta_value'  => $guid,
            'fields'      => 'ids',
            'numberposts' => 1,
        ] );

        if ( ! empty( $existing ) ) {
            $skipped++;
            continue;
        }

        $post_data = [
            'post_title'   => wp_strip_all_tags( $title ),
            'post_content' => wp_kses_post( $content ),
            'post_status'  => $opts['post_status'],
            'post_author'  => (int) $opts['post_author'],
            'post_date'    => get_date_from_gmt( date( 'Y-m-d H:i:s', strtotime( $pub_date ) ) ),
            'post_type'    => 'post',
        ];

        if ( ! empty( $opts['category'] ) ) {
            $post_data['post_category'] = [ (int) $opts['category'] ];
        }

        $post_id = wp_insert_post( $post_data );
        if ( is_wp_error( $post_id ) || ! $post_id ) continue;

        update_post_meta( $post_id, '_csi_substack_guid', $guid );
        update_post_meta( $post_id, '_csi_substack_url',  $link );

        if ( $opts['import_images'] ) {
            $image_url = '';

            if ( isset( $item->enclosure ) ) {
                $enc_attrs = $item->enclosure->attributes();
                if ( ! empty( $enc_attrs['url'] ) ) {
                    $image_url = (string) $enc_attrs['url'];
                }
            }

            if ( empty( $image_url ) ) {
                preg_match( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $img_match );
                if ( ! empty( $img_match[1] ) ) {
                    $image_url = $img_match[1];
                }
            }

            if ( $image_url ) {
                csi_set_featured_image( $post_id, $image_url, $title );
            }
        }

        $imported++;
    }

    csi_save_opts( [
        'last_run'     => current_time( 'mysql' ),
        'last_message' => "Imported: {$imported} | Skipped (already exist): {$skipped}",
    ] );
}

// ─────────────────────────────────────────────
// FEATURED IMAGE HELPER
// ─────────────────────────────────────────────
function csi_set_featured_image( int $post_id, string $image_url, string $title = '' ): void {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url( $image_url );
    if ( is_wp_error( $tmp ) ) return;

    $ext        = pathinfo( parse_url( $image_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) ?: 'jpg';
    $file_array = [
        'name'     => sanitize_file_name( $title ?: 'substack-image' ) . '.' . $ext,
        'tmp_name' => $tmp,
    ];

    $attach_id = media_handle_sideload( $file_array, $post_id );
    if ( ! is_wp_error( $attach_id ) ) {
        set_post_thumbnail( $post_id, $attach_id );
    } elseif ( file_exists( $tmp ) ) {
        @unlink( $tmp );
    }
}

// ─────────────────────────────────────────────
// ADMIN MENU
// ─────────────────────────────────────────────
add_action( 'admin_menu', 'csi_admin_menu' );

function csi_admin_menu(): void {
    add_options_page(
        'Substack Importer',
        'Substack Importer',
        'manage_options',
        CSI_MENU_SLUG,
        'csi_admin_page'
    );
}

// ─────────────────────────────────────────────
// ADMIN PAGE
// ─────────────────────────────────────────────
function csi_admin_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $opts    = csi_get_opts();
    $message = '';

    if ( isset( $_POST['csi_save'] ) && check_admin_referer( 'csi_settings' ) ) {
        $new_freq = sanitize_text_field( $_POST['frequency'] ?? 'hourly' );

        if ( $new_freq !== $opts['frequency'] ) {
            $ts = wp_next_scheduled( CSI_CRON_HOOK );
            if ( $ts ) wp_unschedule_event( $ts, CSI_CRON_HOOK );
            wp_schedule_event( time(), $new_freq, CSI_CRON_HOOK );
        }

        csi_save_opts( [
            'category'      => (int) ( $_POST['category'] ?? 0 ),
            'post_status'   => sanitize_text_field( $_POST['post_status'] ?? 'publish' ),
            'post_author'   => (int) ( $_POST['post_author'] ?? 1 ),
            'import_images' => isset( $_POST['import_images'] ) ? 1 : 0,
            'frequency'     => $new_freq,
        ] );
        $opts    = csi_get_opts();
        $message = '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    if ( isset( $_POST['csi_import_now'] ) && check_admin_referer( 'csi_settings' ) ) {
        csi_run_import();
        $opts    = csi_get_opts();
        $message = '<div class="notice notice-success"><p>Import ran. Result: ' . esc_html( $opts['last_message'] ) . '</p></div>';
    }

    $categories = get_categories( [ 'hide_empty' => false ] );
    $authors    = get_users( [ 'capability' => 'publish_posts' ] );
    $schedules  = wp_get_schedules();
    ?>
    <div class="wrap">
        <h1>Cerrito Substack Importer</h1>
        <p>
            Imports posts from <a href="https://cerrito.substack.com" target="_blank">cerrito.substack.com</a> into your WordPress site.
            &bull; <a href="https://github.com/lougriffith/cerrito-substack-importer" target="_blank">View on GitHub &rarr;</a>
        </p>

        <?php echo $message; ?>

        <?php if ( $opts['last_run'] ) : ?>
            <p><strong>Last run:</strong> <?php echo esc_html( $opts['last_run'] ); ?> &mdash; <?php echo esc_html( $opts['last_message'] ); ?></p>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field( 'csi_settings' ); ?>

            <table class="form-table">
                <tr>
                    <th>Import Frequency</th>
                    <td>
                        <select name="frequency">
                            <?php foreach ( $schedules as $key => $sched ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $opts['frequency'], $key ); ?>>
                                    <?php echo esc_html( $sched['display'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Post Status</th>
                    <td>
                        <select name="post_status">
                            <option value="publish" <?php selected( $opts['post_status'], 'publish' ); ?>>Published</option>
                            <option value="draft"   <?php selected( $opts['post_status'], 'draft' );   ?>>Draft</option>
                            <option value="pending" <?php selected( $opts['post_status'], 'pending' ); ?>>Pending Review</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Assign Category</th>
                    <td>
                        <select name="category">
                            <option value="0">— None —</option>
                            <?php foreach ( $categories as $cat ) : ?>
                                <option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( $opts['category'], $cat->term_id ); ?>>
                                    <?php echo esc_html( $cat->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Post Author</th>
                    <td>
                        <select name="post_author">
                            <?php foreach ( $authors as $user ) : ?>
                                <option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $opts['post_author'], $user->ID ); ?>>
                                    <?php echo esc_html( $user->display_name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Import Featured Images</th>
                    <td>
                        <label>
                            <input type="checkbox" name="import_images" value="1" <?php checked( $opts['import_images'], 1 ); ?>>
                            Sideload images from Substack as featured images
                        </label>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="csi_save" class="button button-primary">Save Settings</button>
                &nbsp;
                <button type="submit" name="csi_import_now" class="button button-secondary"
                    onclick="return confirm('Run import now?')">Import Now</button>
            </p>
        </form>

        <hr>
        <h2>How It Works</h2>
        <ol>
            <li>The plugin fetches <code>https://cerrito.substack.com/feed</code> on your chosen schedule.</li>
            <li>Each item's GUID is stored as post meta (<code>_csi_substack_guid</code>) to prevent duplicates.</li>
            <li>The original Substack URL is stored as <code>_csi_substack_url</code> post meta for reference.</li>
            <li>If <em>Import Featured Images</em> is enabled, the post's enclosure image (or first inline image) is sideloaded into your Media Library and set as the featured image.</li>
            <li>Substack subscribe/footer sections are automatically stripped from post content.</li>
        </ol>

        <h2>Releasing Updates from GitHub</h2>
        <ol>
            <li>Bump the <code>Version</code> header and <code>CSI_CURRENT_VER</code> constant in the PHP file.</li>
            <li>Commit and push to GitHub.</li>
            <li>Go to <a href="https://github.com/lougriffith/cerrito-substack-importer/releases/new" target="_blank">GitHub → Releases → Create a new release</a>.</li>
            <li>Tag it <code>vX.X</code> (e.g. <code>v1.2</code>) and attach the plugin ZIP.</li>
            <li>WordPress will detect the update within 12 hours, or immediately via <strong>Dashboard → Updates → Check Again</strong>.</li>
        </ol>
    </div>
    <?php
}

// ─────────────────────────────────────────────
// SHORTCODE: [cerrito_substack_posts]
// ─────────────────────────────────────────────
// Attributes: count (default 5), category (slug or ID), show_excerpt (1/0)
add_shortcode( 'cerrito_substack_posts', 'csi_shortcode' );

function csi_shortcode( array $atts ): string {
    $atts = shortcode_atts( [
        'count'        => 5,
        'category'     => '',
        'show_excerpt' => 1,
    ], $atts );

    $args = [
        'post_type'      => 'post',
        'posts_per_page' => (int) $atts['count'],
        'meta_key'       => '_csi_substack_guid',
        'meta_compare'   => 'EXISTS',
    ];

    if ( ! empty( $atts['category'] ) ) {
        if ( is_numeric( $atts['category'] ) ) {
            $args['cat'] = (int) $atts['category'];
        } else {
            $args['category_name'] = sanitize_text_field( $atts['category'] );
        }
    }

    $query = new WP_Query( $args );
    if ( ! $query->have_posts() ) {
        return '<p>No Substack posts found.</p>';
    }

    ob_start();
    echo '<div class="cerrito-substack-posts">';
    while ( $query->have_posts() ) {
        $query->the_post();
        $substack_url = get_post_meta( get_the_ID(), '_csi_substack_url', true );
        ?>
        <article class="csi-post">
            <?php if ( has_post_thumbnail() ) : ?>
                <a href="<?php the_permalink(); ?>"><?php the_post_thumbnail( 'medium' ); ?></a>
            <?php endif; ?>
            <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
            <p class="csi-meta"><?php echo get_the_date(); ?></p>
            <?php if ( $atts['show_excerpt'] ) : ?>
                <div class="csi-excerpt"><?php the_excerpt(); ?></div>
            <?php endif; ?>
            <?php if ( $substack_url ) : ?>
                <p><a href="<?php echo esc_url( $substack_url ); ?>" target="_blank" rel="noopener">View on Substack &rarr;</a></p>
            <?php endif; ?>
        </article>
        <?php
    }
    echo '</div>';
    wp_reset_postdata();

    return ob_get_clean();
}
