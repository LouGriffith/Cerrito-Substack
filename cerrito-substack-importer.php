<?php
/**
 * Plugin Name: Cerrito Substack Importer
 * Plugin URI:  https://cerritoentertainment.com
 * Description: Imports posts from the Cerrito Substack RSS feed (https://cerrito.substack.com/feed) into WordPress. Runs on a configurable schedule and avoids duplicates.
 * Version:     1.0
 * Author:      Cerrito Entertainment
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

// ─────────────────────────────────────────────
// ACTIVATION / DEACTIVATION
// ─────────────────────────────────────────────
register_activation_hook( __FILE__, 'csi_activate' );
register_deactivation_hook( __FILE__, 'csi_deactivate' );

function csi_activate() {
    $defaults = [
        'category'       => 0,
        'post_status'    => 'publish',
        'post_author'    => 1,
        'import_images'  => 1,
        'frequency'      => 'hourly',
        'last_run'       => '',
        'last_message'   => '',
    ];
    if ( ! get_option( CSI_OPTION_KEY ) ) {
        add_option( CSI_OPTION_KEY, $defaults );
    }
    csi_schedule_cron();
}

function csi_deactivate() {
    $timestamp = wp_next_scheduled( CSI_CRON_HOOK );
    if ( $timestamp ) wp_unschedule_event( $timestamp, CSI_CRON_HOOK );
}

function csi_schedule_cron() {
    $opts = csi_get_opts();
    $freq = $opts['frequency'] ?? 'hourly';
    if ( ! wp_next_scheduled( CSI_CRON_HOOK ) ) {
        wp_schedule_event( time(), $freq, CSI_CRON_HOOK );
    }
}

// ─────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────
function csi_get_opts() {
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

function csi_save_opts( $data ) {
    $opts = csi_get_opts();
    update_option( CSI_OPTION_KEY, array_merge( $opts, $data ) );
}

// ─────────────────────────────────────────────
// CORE IMPORT LOGIC
// ─────────────────────────────────────────────
add_action( CSI_CRON_HOOK, 'csi_run_import' );

function csi_run_import() {
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

    // Suppress XML warnings
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
        $title      = (string) $item->title;
        $link       = (string) $item->link;
        $pub_date   = (string) $item->pubDate;
        $guid       = (string) $item->guid;

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

        // Strip Substack footer/subscribe nags
        $content = preg_replace( '/<div[^>]*class="[^"]*subscribe[^"]*"[^>]*>.*?<\/div>/is', '', $content );

        // Check for duplicate by GUID stored as post meta
        $existing = get_posts( [
            'post_type'  => 'post',
            'meta_key'   => '_csi_substack_guid',
            'meta_value' => $guid,
            'fields'     => 'ids',
            'numberposts' => 1,
        ] );

        if ( ! empty( $existing ) ) {
            $skipped++;
            continue;
        }

        // Build post array
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
        if ( is_wp_error( $post_id ) || ! $post_id ) {
            continue;
        }

        // Store GUID to prevent re-import
        update_post_meta( $post_id, '_csi_substack_guid', $guid );
        // Store original Substack URL for reference
        update_post_meta( $post_id, '_csi_substack_url', $link );

        // Featured image from enclosure or og:image in content
        if ( $opts['import_images'] ) {
            $image_url = '';

            // Check <enclosure> tag
            if ( isset( $item->enclosure ) ) {
                $enc_attrs = $item->enclosure->attributes();
                if ( ! empty( $enc_attrs['url'] ) ) {
                    $image_url = (string) $enc_attrs['url'];
                }
            }

            // Fallback: first <img> in content
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
function csi_set_featured_image( $post_id, $image_url, $title = '' ) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url( $image_url );
    if ( is_wp_error( $tmp ) ) return;

    $ext      = pathinfo( parse_url( $image_url, PHP_URL_PATH ), PATHINFO_EXTENSION );
    $ext      = $ext ?: 'jpg';
    $filename = sanitize_file_name( $title ?: 'substack-image' ) . '.' . $ext;

    $file_array = [
        'name'     => $filename,
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

function csi_admin_menu() {
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
function csi_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $opts    = csi_get_opts();
    $message = '';

    // Handle form save
    if ( isset( $_POST['csi_save'] ) && check_admin_referer( 'csi_settings' ) ) {
        $new_freq = sanitize_text_field( $_POST['frequency'] ?? 'hourly' );

        // Reschedule if frequency changed
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

    // Handle manual import
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
        <p>Imports posts from <a href="https://cerrito.substack.com" target="_blank">cerrito.substack.com</a> into your WordPress site.</p>
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
                            <option value="draft"   <?php selected( $opts['post_status'], 'draft' ); ?>>Draft</option>
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
            <li>Substack's subscribe/footer sections are automatically stripped from the content.</li>
        </ol>
    </div>
    <?php
}

// ─────────────────────────────────────────────
// SHORTCODE: [cerrito_substack_posts]
// ─────────────────────────────────────────────
// Displays recently imported Substack posts.
// Attributes: count (default 5), category (slug or ID), show_excerpt (1/0)
add_shortcode( 'cerrito_substack_posts', 'csi_shortcode' );

function csi_shortcode( $atts ) {
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
