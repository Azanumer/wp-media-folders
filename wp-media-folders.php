<?php
/**
 * Plugin Name: WP Media Folders
 * Plugin URI: https://github.com/Azanumer/wp-media-folders
 * Description: Organize the Media Library into folders. A hierarchical taxonomy for attachments, with a list filter, a folder column and bulk move.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: wp-media-folders
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hierarchical "Media Folder" taxonomy for attachments.
 */
function wmf_register_taxonomy() {
    register_taxonomy(
        'media_folder',
        'attachment',
        array(
            'labels'            => array(
                'name'          => __( 'Media Folders', 'wp-media-folders' ),
                'singular_name' => __( 'Media Folder', 'wp-media-folders' ),
                'add_new_item'  => __( 'Add New Folder', 'wp-media-folders' ),
                'edit_item'     => __( 'Edit Folder', 'wp-media-folders' ),
                'search_items'  => __( 'Search Folders', 'wp-media-folders' ),
            ),
            'hierarchical'      => true,
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => false, // We render our own column below.
            'show_in_rest'      => true,
            'rewrite'           => false,
            'capabilities'      => array(
                'manage_terms' => 'upload_files',
                'edit_terms'   => 'upload_files',
                'delete_terms' => 'upload_files',
                'assign_terms' => 'upload_files',
            ),
        )
    );
}
add_action( 'init', 'wmf_register_taxonomy' );

/**
 * Folder filter dropdown on the Media Library list screen.
 */
function wmf_filter_dropdown() {
    $screen = get_current_screen();
    if ( ! $screen || 'upload' !== $screen->id ) {
        return;
    }

    $selected = isset( $_GET['media_folder'] ) ? sanitize_text_field( wp_unslash( $_GET['media_folder'] ) ) : '';
    $terms    = get_terms(
        array(
            'taxonomy'   => 'media_folder',
            'hide_empty' => false,
        )
    );

    echo '<select name="media_folder" id="media_folder_filter">';
    echo '<option value="">' . esc_html__( 'All folders', 'wp-media-folders' ) . '</option>';
    echo '<option value="0"' . selected( '0', $selected, false ) . '>' . esc_html__( 'Unassigned', 'wp-media-folders' ) . '</option>';
    if ( ! is_wp_error( $terms ) ) {
        foreach ( $terms as $term ) {
            echo '<option value="' . esc_attr( $term->slug ) . '"' . selected( $term->slug, $selected, false ) . '>'
                . esc_html( str_repeat( '— ', (int) wmf_term_depth( $term ) ) . $term->name ) . '</option>';
        }
    }
    echo '</select>';
}
add_action( 'restrict_manage_posts', 'wmf_filter_dropdown' );

/**
 * Depth of a term in the folder tree (for the indented dropdown).
 */
function wmf_term_depth( $term ) {
    $depth = 0;
    $parent = (int) $term->parent;
    while ( $parent > 0 && $depth < 20 ) {
        $parent_term = get_term( $parent, 'media_folder' );
        if ( ! $parent_term || is_wp_error( $parent_term ) ) {
            break;
        }
        $parent = (int) $parent_term->parent;
        $depth++;
    }
    return $depth;
}

/**
 * Apply the folder filter to the Media Library query.
 */
function wmf_apply_filter( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }
    $screen = get_current_screen();
    if ( ! $screen || 'upload' !== $screen->id ) {
        return;
    }
    if ( ! isset( $_GET['media_folder'] ) || '' === $_GET['media_folder'] ) {
        return;
    }

    $folder = sanitize_text_field( wp_unslash( $_GET['media_folder'] ) );
    if ( '0' === $folder ) {
        // Unassigned: attachments with no folder term.
        $query->set(
            'tax_query',
            array(
                array(
                    'taxonomy' => 'media_folder',
                    'operator' => 'NOT EXISTS',
                ),
            )
        );
    } else {
        $query->set( 'media_folder', $folder );
    }
}
add_action( 'pre_get_posts', 'wmf_apply_filter' );

/**
 * Also filter the media modal (grid) queries.
 */
function wmf_ajax_query_args( $query ) {
    if ( isset( $_REQUEST['media_folder'] ) && '' !== $_REQUEST['media_folder'] ) {
        $folder = sanitize_text_field( wp_unslash( $_REQUEST['media_folder'] ) );
        if ( '0' === $folder ) {
            $query['tax_query'] = array(
                array(
                    'taxonomy' => 'media_folder',
                    'operator' => 'NOT EXISTS',
                ),
            );
        } else {
            $query['media_folder'] = $folder;
        }
    }
    return $query;
}
add_filter( 'ajax_query_attachments_args', 'wmf_ajax_query_args' );

/**
 * Folder column in the Media Library list.
 */
function wmf_add_column( $columns ) {
    $columns['media_folder'] = __( 'Folder', 'wp-media-folders' );
    return $columns;
}
add_filter( 'manage_upload_columns', 'wmf_add_column' );

function wmf_render_column( $column, $post_id ) {
    if ( 'media_folder' !== $column ) {
        return;
    }
    $terms = get_the_terms( $post_id, 'media_folder' );
    if ( ! $terms || is_wp_error( $terms ) ) {
        echo '<span aria-hidden="true">—</span>';
        return;
    }
    $links = array();
    foreach ( $terms as $term ) {
        $url     = add_query_arg(
            array(
                'media_folder' => $term->slug,
            ),
            admin_url( 'upload.php' )
        );
        $links[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $term->name ) . '</a>';
    }
    echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- links are escaped above.
}
add_action( 'manage_media_custom_column', 'wmf_render_column', 10, 2 );

/**
 * Bulk action: move selected items into a folder.
 */
function wmf_bulk_actions( $actions ) {
    $terms = get_terms(
        array(
            'taxonomy'   => 'media_folder',
            'hide_empty' => false,
        )
    );
    if ( ! is_wp_error( $terms ) ) {
        foreach ( $terms as $term ) {
            $actions[ 'wmf_move_' . $term->term_id ] = sprintf(
                /* translators: %s: folder name. */
                __( 'Move to folder: %s', 'wp-media-folders' ),
                $term->name
            );
        }
    }
    $actions['wmf_remove'] = __( 'Remove from folder', 'wp-media-folders' );
    return $actions;
}
add_filter( 'bulk_actions-upload', 'wmf_bulk_actions' );

function wmf_handle_bulk_action( $redirect, $action, $post_ids ) {
    if ( 0 === strpos( $action, 'wmf_move_' ) ) {
        $term_id = absint( substr( $action, strlen( 'wmf_move_' ) ) );
        $term    = get_term( $term_id, 'media_folder' );
        if ( $term && ! is_wp_error( $term ) ) {
            $moved = 0;
            foreach ( $post_ids as $post_id ) {
                if ( current_user_can( 'edit_post', $post_id ) ) {
                    wp_set_object_terms( $post_id, array( $term_id ), 'media_folder', false );
                    $moved++;
                }
            }
            $redirect = add_query_arg( 'wmf_moved', $moved, $redirect );
        }
    } elseif ( 'wmf_remove' === $action ) {
        $removed = 0;
        foreach ( $post_ids as $post_id ) {
            if ( current_user_can( 'edit_post', $post_id ) ) {
                wp_set_object_terms( $post_id, array(), 'media_folder', false );
                $removed++;
            }
        }
        $redirect = add_query_arg( 'wmf_removed', $removed, $redirect );
    }
    return $redirect;
}
add_filter( 'handle_bulk_actions-upload', 'wmf_handle_bulk_action', 10, 3 );

/**
 * Admin notice after a bulk move.
 */
function wmf_bulk_notice() {
    if ( isset( $_GET['wmf_moved'] ) ) {
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html(
                sprintf(
                    /* translators: %d: number of items moved. */
                    _n( '%d item moved to its folder.', '%d items moved to their folder.', absint( $_GET['wmf_moved'] ), 'wp-media-folders' ),
                    absint( $_GET['wmf_moved'] )
                )
            )
        );
    } elseif ( isset( $_GET['wmf_removed'] ) ) {
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html(
                sprintf(
                    /* translators: %d: number of items removed. */
                    _n( '%d item removed from its folder.', '%d items removed from their folders.', absint( $_GET['wmf_removed'] ), 'wp-media-folders' ),
                    absint( $_GET['wmf_removed'] )
                )
            )
        );
    }
}
add_action( 'admin_notices', 'wmf_bulk_notice' );

/**
 * Folder field on the attachment edit screen.
 */
function wmf_attachment_fields( $form_fields, $post ) {
    $terms = get_terms(
        array(
            'taxonomy'   => 'media_folder',
            'hide_empty' => false,
        )
    );
    $current = wp_get_object_terms( $post->ID, 'media_folder', array( 'fields' => 'ids' ) );
    $current = ( ! is_wp_error( $current ) && $current ) ? (int) $current[0] : 0;

    $html = '<select name="attachments[' . (int) $post->ID . '][media_folder]">';
    $html .= '<option value="0"' . selected( 0, $current, false ) . '>' . esc_html__( '(No folder)', 'wp-media-folders' ) . '</option>';
    if ( ! is_wp_error( $terms ) ) {
        foreach ( $terms as $term ) {
            $html .= '<option value="' . esc_attr( $term->term_id ) . '"' . selected( $term->term_id, $current, false ) . '>'
                . esc_html( str_repeat( '— ', (int) wmf_term_depth( $term ) ) . $term->name ) . '</option>';
        }
    }
    $html .= '</select>';

    $form_fields['media_folder'] = array(
        'label' => __( 'Media Folder', 'wp-media-folders' ),
        'input' => 'html',
        'html'  => $html,
        'value' => $current,
    );
    return $form_fields;
}
add_filter( 'attachment_fields_to_edit', 'wmf_attachment_fields', 10, 2 );

function wmf_attachment_save( $post, $attachment ) {
    if ( isset( $attachment['media_folder'] ) && current_user_can( 'edit_post', $post['ID'] ) ) {
        $term_id = absint( $attachment['media_folder'] );
        if ( 0 === $term_id ) {
            wp_set_object_terms( $post['ID'], array(), 'media_folder', false );
        } else {
            wp_set_object_terms( $post['ID'], array( $term_id ), 'media_folder', false );
        }
    }
    return $post;
}
add_filter( 'attachment_fields_to_save', 'wmf_attachment_save', 10, 2 );
