<?php
/**
 * Uninstall: delete the media_folder terms and taxonomy data.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$terms = get_terms(
    array(
        'taxonomy'   => 'media_folder',
        'hide_empty' => false,
        'fields'     => 'ids',
    )
);
if ( ! is_wp_error( $terms ) ) {
    foreach ( $terms as $term_id ) {
        wp_delete_term( $term_id, 'media_folder' );
    }
}
