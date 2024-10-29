<?php

/**
 * Plugin Name: AltTextGenerator AI
 * Description: This plugin automatically identifies the images that don't have alt texts in your image library and will auto generate them using our AI Computer Vision model and bulk update them for you with a single click. 
 * Version: 1.8.1
 * Author: WebToffee
 * Author URI: https://www.webtoffee.com
 */

if (!defined('ABSPATH')) exit;

// Enqueue the JavaScript and CSS files for admin page
function atgai_enqueue_scripts($hook_suffix)
{
    // Only enqueue scripts and styles on your specific admin page
    if ('toplevel_page_atgai-admin' !== $hook_suffix) {
        return;
    }

    // Enqueue main.js 
    wp_enqueue_script(
        'atgai-plugin-main',
        plugin_dir_url(__FILE__) . 'build/index.js?v=1.8.1',
        array('jquery', 'wp-element'),
        '1.8.1',
        true
    );

    // Enqueue index.css
    wp_enqueue_style(
        'atgai-plugin-css',
        plugin_dir_url(__FILE__) . 'build/index.css',
        array(),
        '1.8.1',
        'all'
    );

    // Create nonces
    $fetch_images_nonce = wp_create_nonce('fetch_images_nonce');
    $set_api_key_nonce = wp_create_nonce('set_api_key_nonce');
    $update_image_alt_text_nonce = wp_create_nonce('update_image_alt_text_nonce');

    // Pass the image URL and nonces to JavaScript
    $image_url = plugins_url('assets/alttextgenerator-logo.png', __FILE__);
    wp_localize_script(
        'atgai-plugin-main',
        'atgaiWpApiSettings',
        array(
            'imageUrl' => $image_url,
            'fetchImagesNonce' => $fetch_images_nonce,
            'setApiKeyNonce' => $set_api_key_nonce,
            'updateImageAltTextNonce' => $update_image_alt_text_nonce
        )
    );
}
add_action('admin_enqueue_scripts', 'atgai_enqueue_scripts');

// Add a menu page in the WordPress admin
function atgai_admin_menu()
{
    add_menu_page(
        'Alt Text Generator AI',
        'Alt Text Generator AI',
        'manage_options',
        'atgai-admin',
        'atgai_admin_page',
        'dashicons-admin-generic',
        20
    );
}
add_action('admin_menu', 'atgai_admin_menu');
// Callback function for the admin page
function atgai_admin_page()
{
    echo '<div class="reset-css"><div id="root"></div></div>';
}

// AJAX action to fetch images
add_action('wp_ajax_atgai_fetch_images', 'atgai_fetch_images');

// Function to fetch images
function atgai_fetch_images()
{
    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fetch_images_nonce')) {
        wp_send_json_error(array('message' => 'Nonce verification failed'));
        return;
    }

    // Sanitize the input, default to 'all' if no filter set
    $filter = isset($_POST['filter']) ? sanitize_text_field($_POST['filter']) : 'all';

    // Validate the filter against allowed values
    $allowed_filters = array('all', 'some_filter_value', 'another_filter_value'); // List your allowed filters here
    if (!in_array($filter, $allowed_filters)) {
        wp_send_json_error(array('message' => 'Invalid filter value'));
        return;
    }

    $images = atgai_fetch_all_images($filter);
    $imageData = array();

    foreach ($images as $image) {
        $thumbnail_url = wp_get_attachment_image_url($image['id'], 'thumbnail');
        $original_url = wp_get_attachment_image_url($image['id'], 'full');

        $imageData[] = array(
            'imageId' => $image['id'],
            'thumbnailUrl' => $thumbnail_url,
            'imageUrl' => $original_url,
            'altTag' => $image['alt'],
        );
    }

    wp_send_json_success($imageData);
    wp_die();
}

// Function to fetch all images
function atgai_fetch_all_images($filter)
{
    $args = array(
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'post_status' => 'inherit',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => '_wp_attachment_metadata',
                'compare' => 'EXISTS',
            ),
        ),
    );

    if ($filter === 'notag') {
        $args['meta_query'][] = array(
            'key' => '_wp_attachment_image_alt',
            'compare' => 'NOT EXISTS',
        );
    }

    $query = new WP_Query($args);
    $images = array();

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $attachment_id = get_the_ID();
            $attachment_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
            $attachment_file = get_attached_file($attachment_id);
            $attachment_mime = get_post_mime_type($attachment_id);

            // Check if the file exists before adding it to the array.
            if (file_exists($attachment_file) && $attachment_mime != "image/svg+xml") {
                $image = array(
                    'id' => $attachment_id,
                    'alt' => $attachment_alt,
                );

                $images[] = $image;
            }
        }
        wp_reset_postdata();
    }

    return $images;
}


// Add AJAX action to get images without alt tag
add_action('wp_ajax_atgai_get_images_count', 'atgai_get_images_count_ajax');


function atgai_get_images_count_ajax()
{
    $count_without_alt = atgai_get_images_without_alt();
    $total_count = atgai_get_total_images();
    $response = array(
        'withoutAlt' => $count_without_alt,
        'total' => $total_count,
    );
    wp_send_json_success($response);
    wp_die();
}

// Function to get the number of images without alt tag
function atgai_get_images_without_alt()
{
    $args = array(
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'post_status' => 'inherit',
        'posts_per_page' => -1,
        'fields' => 'ids', // Only get post IDs
    );

    $images = get_posts($args);
    $images_without_alt = 0;

    foreach ($images as $image_id) {
        $alt_text = get_post_meta($image_id, '_wp_attachment_image_alt', true);
        if (empty($alt_text)) {
            $images_without_alt++;
        }
    }

    return $images_without_alt;
}

// Function to get the total number of images
function atgai_get_total_images()
{
    $args = array(
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'post_status' => 'inherit',
        'posts_per_page' => -1,
        'fields' => 'ids', // Only get post IDs
    );

    $images = get_posts($args);

    return count($images);
}

// Add AJAX actions to set and get the API key
add_action('wp_ajax_atgai_set_api_key', 'atgai_set_api_key');
add_action('wp_ajax_atgai_get_api_key', 'atgai_get_api_key');
add_action('wp_ajax_atgai_delete_api_key', 'atgai_delete_api_key');


// Function to set the API key
function atgai_set_api_key()
{
    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'set_api_key_nonce')) {
        wp_send_json_error(array('message' => 'Nonce verification failed'));
        return;
    }

    $api_key = sanitize_text_field($_POST['api_key']);
    update_option('atgai_api_key', $api_key);
    wp_send_json_success(array('message' => 'API Key saved successfully'));
    wp_die();
}

// Function to get the API key
function atgai_get_api_key()
{
    $api_key = get_option('atgai_api_key', '');
    wp_send_json_success(array('api_key' => $api_key));
    wp_die();
}

// Function to delete the API key

function atgai_delete_api_key()
{
    delete_option('atgai_api_key');
    wp_send_json_success(array('message' => 'API Key deleted successfully'));
    wp_die();
}



// Function to update the tag description in the Wordpress Database 

add_action('wp_ajax_atgai_update_image_alt_text', 'atgai_update_image_alt_text');

function atgai_update_image_alt_text()
{
    // get the image ID and new alt text from the AJAX request
    $image_id = intval($_POST['image_id']);

    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'update_image_alt_text_nonce')) {
        wp_send_json_error(array('message' => 'Nonce verification failed'));
        return;
    }

    $new_alt_text = sanitize_text_field($_POST['new_alt_text']);

    // update the alt text
    update_post_meta($image_id, '_wp_attachment_image_alt', $new_alt_text);

    // return a success message
    wp_send_json_success(array('message' => 'Alt text updated successfully'));
    wp_die();
}

// Add AJAX action to get site domain
add_action('wp_ajax_atgai_get_site_domain', 'atgai_get_site_domain');

// Function to get the site domain
function atgai_get_site_domain()
{
    $site_url = get_site_url();
    $parsed_url = parse_url($site_url);
    $domain = $parsed_url['host'];
    wp_send_json_success(array('domain' => $domain));
    wp_die();
}
