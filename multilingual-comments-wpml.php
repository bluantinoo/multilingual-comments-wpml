<?php
/**
 * Plugin Name: Multilingual Comments WPML
 * Plugin URI: https://so-wp.com/plugin/multilingual-comments-wpml
 * Description: Show and count all comments from all languages for a post and its translations (WPML plugin required)
 * Author: Piet Bos
 * Version: 1.5.1
 * Author URI: https://senlinonline.com
 * Text Domain: multilingual-comments-wpml
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

define( 'MC_WPML_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Function to check for required plugin WPML
 *
 * @since 0.1.0
 */
function mc_wpml_check_parent_plugin() {

	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
	}

	// Check if WPML is active, if not deactivate and show warning
	if ( ! is_plugin_active( 'sitepress-multilingual-cms/sitepress.php' ) ) {
		deactivate_plugins( MC_WPML_BASENAME );
		add_action( 'admin_notices', 'mc_wpml_disabled_notice' );
		if ( isset( $_GET['activate'] ) ) {
			unset( $_GET['activate'] );
		}
	}
}

add_action( 'admin_init', 'mc_wpml_check_parent_plugin' );

/**
 * Warning message when plugin has been deactivated
 *
 * @since v0.1.0
 */
function mc_wpml_disabled_notice() {
	echo '<div class="notice notice-error"><p>' . __( 'Multilingual Comments WPML requires the <a href="https://wpml.org/" target="_blank">WPML</a> plugin. Please install and activate WPML first.', 'multilingual-comments-wpml' ) . '</p></div>';
}

/**
 * Function to count comments from all languages together
 * Uses the icl_object_id function from WPML to get "duplicate" posts in other languages
 *
 * @param int $count Comment count
 * @param int $post_id Post ID
 *
 * @return int Adjusted comment count
 *
 * @since 0.1.0
 */
function mc_wpml_get_comments_number( $count, $post_id ) {
	global $wpdb;

	// Only run this function if WPML is active and we're on the frontend
	if ( ! function_exists( 'icl_object_id' ) || is_admin() ) {
		return $count;
	}

	// Get post type
	$post_type = get_post_type( $post_id );

	// Make sure we get the right post ID in any language
	$post_id = apply_filters( 'wpml_object_id', $post_id, $post_type, false );

	// Get all translations
	$translations = apply_filters( 'wpml_get_element_translations', [], $post_id, $post_type );

	// If we don't have translations, return original count
	if ( empty( $translations ) ) {
		return $count;
	}

	// Get all the post IDs in different languages
	$post_ids = [];
	foreach ( $translations as $lang => $translation ) {
		if ( isset( $translation->element_id ) && $translation->element_id ) {
			$post_ids[] = $translation->element_id;
		}
	}

	// If we don't have any post IDs, return original count
	if ( empty( $post_ids ) ) {
		return $count;
	}

	// Prepare SQL query to count comments on all translated posts
	$sql = $wpdb->prepare(
		"SELECT COUNT(comment_ID) FROM $wpdb->comments WHERE comment_post_ID IN (" . implode( ',', array_fill( 0, count( $post_ids ), '%d' ) ) . ") AND comment_approved = '1'",
		$post_ids
	);

	// Get the comment count from the database
	$comments_count = $wpdb->get_var( $sql );

	// Return the count, or 0 if no comments found
	return $comments_count ? $comments_count : 0;
}

add_filter( 'get_comments_number', 'mc_wpml_get_comments_number', 10, 2 );

/**
 * Function to show comments from all languages together
 *
 * @param object $comments Comments
 * @param int $post_id Post ID
 *
 * @return array Adjusted comments
 *
 * @since 0.1.0
 */
function mc_wpml_get_comments( $comments, $post_id ) {
	global $wpdb;

	// Only run this function on frontend and for main queries
	if ( is_admin() || ! function_exists( 'icl_object_id' ) ) {
		return $comments;
	}

	// Get post type
	$post_type = get_post_type( $post_id );

	// Make sure we get the right post ID in any language
	$post_id = apply_filters( 'wpml_object_id', $post_id, $post_type, false );

	// Get all translations
	$translations = apply_filters( 'wpml_get_element_translations', [], $post_id, $post_type );

	// If we don't have translations, return original comments
	if ( empty( $translations ) ) {
		return $comments;
	}

	// Get all the post IDs in different languages
	$post_ids = [];
	foreach ( $translations as $lang => $translation ) {
		if ( isset( $translation->element_id ) && $translation->element_id ) {
			$post_ids[] = $translation->element_id;
		}
	}

	// If we don't have any post IDs, return original comments
	if ( empty( $post_ids ) ) {
		return $comments;
	}

	// If we're in a comment feed, return the original comments
	if ( is_comment_feed() ) {
		return $comments;
	}

	// Get the default comment status arguments
	$args = [
		'status'  => 'approve',
		'orderby' => 'comment_date_gmt',
		'order'   => 'ASC',
	];

	// Prepare the SQL query to get all comments from all translated posts
	$post_ids_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
	$sql = $wpdb->prepare(
		"SELECT * FROM $wpdb->comments WHERE comment_post_ID IN ($post_ids_placeholders) AND comment_approved = '1' ORDER BY comment_date_gmt ASC",
		$post_ids
	);

	// Execute the query and get all comments
	$comments = $wpdb->get_results( $sql );

	return $comments;
}

add_filter( 'comments_array', 'mc_wpml_get_comments', 10, 2 );

/**
 * Function to add taxonomy term IDs from all languages to the query
 * This helps with counting comments on taxonomy archives
 *
 * @param array $term_taxonomy_ids Array of term taxonomy IDs
 * @return array Modified array of term taxonomy IDs
 * @since 1.5.0
 */
function mc_wpml_term_taxonomy_id($term_taxonomy_ids) {
    global $sitepress;
    
    // Verify WPML is active and fully initialized
    if (!function_exists('icl_object_id') || !is_object($sitepress)) {
        return $term_taxonomy_ids;
    }
    
    // Skip if we're in admin or if it's not an array
    if (is_admin() || !is_array($term_taxonomy_ids)) {
        return $term_taxonomy_ids;
    }
    
    // Debug information in development environments
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Original term_taxonomy_ids: ' . print_r($term_taxonomy_ids, true));
    }
    
    // Get all active languages including hidden ones
    $languages = apply_filters('wpml_active_languages', null, ['skip_missing' => 0, 'include_hidden' => true]);
    
    if (empty($languages)) {
        return $term_taxonomy_ids;
    }
    
    $all_term_taxonomy_ids = $term_taxonomy_ids;
    
    foreach ($term_taxonomy_ids as $term_taxonomy_id) {
        // Get the term object
        $term = get_term_by('term_taxonomy_id', $term_taxonomy_id);
        
        if (!$term) {
            continue;
        }
        
        // For each language, get the translated term
        foreach ($languages as $language_code => $language_info) {
            $translated_term_id = apply_filters('wpml_object_id', $term->term_id, $term->taxonomy, false, $language_code);
            
            if ($translated_term_id && $translated_term_id != $term->term_id) {
                $translated_term = get_term_by('id', $translated_term_id, $term->taxonomy);
                
                if ($translated_term && isset($translated_term->term_taxonomy_id)) {
                    $all_term_taxonomy_ids[] = $translated_term->term_taxonomy_id;
                }
            }
        }
    }
    
    // Remove duplicates
    $all_term_taxonomy_ids = array_unique($all_term_taxonomy_ids);
    
    // Debug information in development environments
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Modified term_taxonomy_ids: ' . print_r($all_term_taxonomy_ids, true));
    }
    
    return $all_term_taxonomy_ids;
}

// Hook to modify term taxonomy IDs for comment counts, only after WPML is fully loaded
add_action('wpml_after_init', function() {
    add_filter('term_taxonomy_id', 'mc_wpml_term_taxonomy_id');
});

/**
 * Load plugin textdomain
 *
 * @since 0.1.0
 */
function mc_wpml_load_textdomain() {
	load_plugin_textdomain( 'multilingual-comments-wpml', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}

add_action( 'plugins_loaded', 'mc_wpml_load_textdomain', 15 );
