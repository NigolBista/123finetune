<?php
/*
Plugin Name: Fine-Tune Dashboard
Description: A custom dashboard for users to fine-tune and manage their content.
Version: 1.1
Author: Your Name
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Load Composer's autoloader
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    error_log('Composer autoload file not found. Please run "composer install".');
}

// Use necessary libraries
use Goutte\Client;
use GuzzleHttp\Client as GuzzleClient;
use Smalot\PdfParser\Parser as PdfParser;
use League\HTMLToMarkdown\HtmlConverter;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use \Firebase\JWT\JWT;

// Enqueue the React app's JS and CSS from the build directory
function fine_tune_dashboard_enqueue_scripts() {
    $plugin_dir = plugin_dir_path(__FILE__);
    $manifest_path = $plugin_dir . 'frontend/build/asset-manifest.json';

    if (file_exists($manifest_path)) {
        $manifest = json_decode(file_get_contents($manifest_path), true);

        if (isset($manifest['files']['main.css'])) {
            wp_enqueue_style(
                'fine-tune-dashboard-css',
                plugins_url('frontend/build/' . $manifest['files']['main.css'], __FILE__)
            );
        }

        if (isset($manifest['files']['main.js'])) {
            wp_enqueue_script(
                'fine-tune-dashboard-js',
                plugins_url('frontend/build/' . $manifest['files']['main.js'], __FILE__),
                array(), // Add any dependencies here
                null,
                true // Load in footer
            );
        }

        // Pass data to the React app
        wp_localize_script('fine-tune-dashboard-js', 'fineTuneDashboard', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('fine_tune_dashboard_nonce')
        ));
    } else {
        // Error handling if manifest file is not found
        error_log('Fine-Tune Dashboard: asset-manifest.json not found.');
    }
}
add_action('wp_enqueue_scripts', 'fine_tune_dashboard_enqueue_scripts');

// Create a shortcode to display the React app
function fine_tune_dashboard_render() {
    if (is_user_logged_in()) {
        echo '<div id="root"></div>'; // The React app will mount to this div
    } else {
        echo 'You need to log in to access this page.';
    }
}
add_shortcode('fine_tune_dashboard', 'fine_tune_dashboard_render');

// Create a page for the dashboard on plugin activation
function fine_tune_dashboard_create_page() {
    $dashboard_page = array(
        'post_title'    => 'Fine-Tune Dashboard',
        'post_content'  => '[fine_tune_dashboard]', // Use the shortcode
        'post_status'   => 'publish',
        'post_type'     => 'page',
        'post_author'   => get_current_user_id(),
    );
    wp_insert_post($dashboard_page);
}
register_activation_hook(__FILE__, 'fine_tune_dashboard_create_page');

// Handle AJAX requests from the React app (file uploads, scraping, etc.)
function fine_tune_dashboard_handle_ajax() {
    check_ajax_referer('fine_tune_dashboard_nonce', 'security');

    $action = sanitize_text_field($_POST['action_type']);

    switch ($action) {
        case 'upload_file':
            // Handle file uploads and text extraction
            if (!empty($_FILES)) {
                foreach ($_FILES as $file) {
                    $upload = wp_handle_upload($file, array('test_form' => false));
                    if ($upload && !isset($upload['error'])) {
                        // Process the uploaded file
                        $extracted_text = fine_tune_extract_text_from_file($upload['file']);
                        if ($extracted_text) {
                            // Save extracted text as a custom post
                            wp_insert_post([
                                'post_title'   => basename($upload['file']),
                                'post_type'    => 'crawled_urls',
                                'post_status'  => 'publish',
                                'post_content' => $extracted_text,
                            ]);
                        }
                    } else {
                        error_log('File upload error: ' . $upload['error']);
                        wp_send_json_error(['message' => 'File upload failed']);
                    }
                }
            }
            wp_send_json_success(['message' => 'Files uploaded and processed successfully']);
            break;

        case 'scrape_url':
            // Handle URL scraping
            $urls = json_decode(stripslashes($_POST['urls']));
            $temp_dir = wp_upload_dir()['basedir'] . '/temp_data/';

            if (!file_exists($temp_dir)) {
                mkdir($temp_dir, 0755, true);
            }

            foreach ($urls as $url) {
                $url = esc_url_raw($url);
                try {
                    $response = wp_remote_get($url);
                    if (is_wp_error($response)) {
                        error_log('Failed to fetch URL: ' . $url . ' - Error: ' . $response->get_error_message());
                        wp_send_json_error(['message' => 'Failed to fetch URL: ' . $url]);
                    } else {
                        $body = wp_remote_retrieve_body($response);
                        $parsed_url = parse_url($url);
                        $host = $parsed_url['host'] ?? 'unknown';
                        $filename = $host . '-' . md5($url) . '.html';
                        file_put_contents($temp_dir . $filename, $body);
                    }
                } catch (Exception $e) {
                    error_log('Exception while fetching URL: ' . $url . ' - ' . $e->getMessage());
                    wp_send_json_error(['message' => 'Exception occurred while fetching URL: ' . $url]);
                }
            }
            wp_send_json_success(['message' => 'URLs processed successfully']);
            break;

        case 'save_data':
            // Save URLs, text, and files
            wp_send_json_success(['message' => 'Data saved successfully']);
            break;

        default:
            wp_send_json_error(['message' => 'Invalid action']);
    }

    wp_die();
}
add_action('wp_ajax_fine_tune_dashboard_action', 'fine_tune_dashboard_handle_ajax');

// Function to extract text from different file formats
function fine_tune_extract_text_from_file($file_path) {
    $file_type = wp_check_filetype($file_path);

    switch ($file_type['ext']) {
        case 'pdf':
            $parser = new PdfParser();
            $pdf = $parser->parseFile($file_path);
            return $pdf->getText();

        case 'txt':
            return file_get_contents($file_path);

        case 'html':
            $html = file_get_contents($file_path);
            $converter = new HtmlConverter();
            return $converter->convert($html);

        case 'docx':
            $phpWord = IOFactory::load($file_path);
            $text = '';
            foreach ($phpWord->getSections() as $section) {
                $elements = $section->getElements();
                foreach ($elements as $element) {
                    $text .= $element->getText();
                }
            }
            return $text;

        case 'xlsx':
        case 'xls':
            $spreadsheet = SpreadsheetIOFactory::load($file_path);
            $text = '';
            foreach ($spreadsheet->getActiveSheet()->getRowIterator() as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                foreach ($cellIterator as $cell) {
                    $text .= $cell->getValue() . ' ';
                }
                $text .= "\n";
            }
            return $text;

        case 'md':
            return file_get_contents($file_path);

        default:
            return '';
    }
}

// CORS Headers
add_action('rest_api_init', function() {
    add_filter('rest_pre_serve_request', function($value) {
        header('Access-Control-Allow-Origin: http://123finetunecom.local');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
        header('Access-Control-Allow-Credentials: true');
        return $value;
    });
});

// Register REST API endpoint for login
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/login', array(
        'methods' => 'POST',
        'callback' => 'handle_custom_login',
        'permission_callback' => '__return_true',
    ));
});

  // Get all saved Q/A
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/get_all_saved_qa', array(
        'methods' => 'GET',
        'callback' => 'handle_get_all_saved_qa',
        'permission_callback' => function () {
            return current_user_can('read'); // Adjust permissions as needed
        }
    ));
});
// Register REST API endpoint for crawling URLs
add_action('rest_api_init', function () {
    register_rest_route('my-plugin/v1', '/crawl-urls', array(
        'methods' => 'POST',
        'callback' => 'handle_crawl_urls',
        'permission_callback' => '__return_true', // Adjust as needed
    ));

    register_rest_route('my-plugin/v1', '/upload-file', array(
        'methods' => 'POST',
        'callback' => 'fine_tune_dashboard_handle_file_upload',
        'permission_callback' => '__return_true', // Adjust as needed
    ));
});

// Register custom post type for crawled URLs
add_action('init', function () {
    register_post_type('crawled_urls', [
        'public' => true,
        'label'  => 'Crawled URLs',
        'supports' => ['title', 'editor'],
    ]);
});

// Handle login request
function handle_custom_login(WP_REST_Request $request) {
    $creds = array();
    $creds['user_login'] = sanitize_text_field($request->get_param('username'));
    $creds['user_password'] = $request->get_param('password');
    $creds['remember'] = true;

    $user = wp_signon($creds, false);

    if (is_wp_error($user)) {
        return new WP_REST_Response(array('message' => 'Invalid username or password'), 401);
    }

    // Secret key for signing the JWT token (replace with your actual secret key)
    $secret_key = 'your-top-secret-key';

    // Token data - structure it to include user_id directly
    $token = array(
        "iss" => get_bloginfo('url'), // Issuer
        "iat" => time(), // Issued at time
        "exp" => time() + (60 * 60), // Expiry time (1 hour)
        "data" => array(
            "user" => array(
                "id" => $user->ID,
            )
        )
    );

    // Encode the JWT token with the correct number of arguments
    $jwt = JWT::encode($token, $secret_key, 'HS256');

    return new WP_REST_Response(array(
        'user_id' => $user->ID,
        'token' => $jwt
    ), 200);
}

add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/get_user_posts', array(
        'methods' => 'GET',
        'callback' => 'handle_get_user_posts',
        'permission_callback' => function () {
            return current_user_can('read');
        }
    ));
});

add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/get_post', array(
        'methods' => 'GET',
        'callback' => 'handle_get_post',
        'permission_callback' => function () {
            return current_user_can('read');
        }
    ));
});

add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/save_post', array(
        'methods' => 'POST',
        'callback' => 'handle_save_post_request',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        }
    ));
});

function handle_save_post_request(WP_REST_Request $request) {
    $post_id = $request->get_param('post_id');
    $title = sanitize_text_field($request->get_param('title'));
    $content = wp_kses_post($request->get_param('content'));

    if (!$post_id || !$title || !$content) {
        return new WP_REST_Response(array('message' => 'Post ID, title, and content are required'), 400);
    }

    $post_data = array(
        'ID' => $post_id,
        'post_title' => $title,
        'post_content' => $content,
    );

    $updated_post_id = wp_update_post($post_data);

    if (is_wp_error($updated_post_id)) {
        return new WP_REST_Response(array('message' => 'Failed to update post'), 500);
    }

    return new WP_REST_Response(array('message' => 'Post updated successfully'), 200);
}

function handle_get_post(WP_REST_Request $request) {
    $post_id = $request->get_param('post_id');

    // Check if the post ID is provided
    if (!$post_id) {
        return new WP_REST_Response(array('message' => 'Post ID is required'), 400);
    }

    // Fetch the post
    $post = get_post($post_id);

    if (!$post || $post->post_status !== 'publish') {
        return new WP_REST_Response(array('message' => 'Post not found or not published'), 404);
    }

    // Prepare the data
    $post_data = array(
        'id' => $post->ID,
        'title' => $post->post_title,
        'content' => $post->post_content,
        'file_name' => get_post_meta($post->ID, '_wp_attached_file', true), // Assuming you store the filename in meta
    );

    return new WP_REST_Response($post_data, 200);
}


function handle_get_user_posts(WP_REST_Request $request) {
    $user_id = $request->get_param('user_id');

    // Check if the user ID is provided
    if (!$user_id) {
        return new WP_Error('no_user', 'Invalid user ID', array('status' => 404));
    }

    // Fetch the posts for the user
    $args = array(
        'post_type' => 'crawled_urls', // Your custom post type
        'author' => $user_id,
        'post_status' => 'publish',
    );
    $user_posts = get_posts($args);

    if (empty($user_posts)) {
        return new WP_REST_Response(array(), 200); // Return an empty array if no posts are found
    }

    // Prepare the data
    $posts_data = array();
    foreach ($user_posts as $post) {
        $posts_data[] = array(
            'id' => $post->ID,
            'title' => get_the_title($post->ID),
            'content' => $post->post_content,
            'file_name' => get_post_meta($post->ID, '_wp_attached_file', true), // Assuming you store the filename in meta
        );
    }

    return new WP_REST_Response($posts_data, 200);
}

add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/generate_qa', array(
        'methods' => 'POST', // Ensure this matches the method you're using
        'callback' => 'handle_generate_qa_request',
        'permission_callback' => function () {
            return current_user_can('edit_posts'); // Adjust permissions as needed
        }
    ));
});

function handle_generate_qa_request(WP_REST_Request $request) {
    $post_id = $request->get_param('post_id');
    
    if (!$post_id) {
        return new WP_REST_Response(array('message' => 'Post ID is required'), 400);
    }

    $post = get_post($post_id);
    
    if (!$post || $post->post_status !== 'publish') {
        return new WP_REST_Response(array('message' => 'Post not found or not published'), 404);
    }
    
    $post_content = escapeshellarg($post->post_content);

    // Escape the path to the Python script to handle spaces
    $python_script = escapeshellarg(plugin_dir_path(__FILE__) . 'qa-generator/scripts/generate_qa.py');
    
    // Path to the virtual environment's Python interpreter
    $venv_python = escapeshellarg(plugin_dir_path(__FILE__) . 'qa-generator/scripts/fine_tune_env/bin/python3');

    // Set the OpenAI API key in the environment variable
    $api_key = '';
    putenv("OPENAI_API_KEY=$api_key");

    // Run the Python script with the content as an argument
    $command = $venv_python . " " . $python_script . " --content " . $post_content . " 2>&1"; // Redirect errors to output
    $output = shell_exec($command);
    $response = json_decode($output, true);

    if ($response) {
        // Save the generated Q/A to the post meta
        update_post_meta($post_id, 'generated_qa', json_encode($response));

        return new WP_REST_Response($response, 200);
    } else {
        error_log('Python script output: ' . $output); // Log the output for debugging
        return new WP_REST_Response(array('message' => 'Failed to generate Q/A', 'details' => $output), 500);
    }
}

// Handle file uploads
function fine_tune_dashboard_handle_file_upload(WP_REST_Request $request) {
    // Make sure the file handling functions are available
    if (!function_exists('wp_handle_upload')) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
    }

    $files = $request->get_file_params();

    if (empty($files)) {
        return new WP_REST_Response('No files uploaded', 400);
    }

    foreach ($files as $file) {
        $upload = wp_handle_upload($file, array('test_form' => false));
        if ($upload && !isset($upload['error'])) {
            $extracted_text = fine_tune_extract_text_from_file($upload['file']);
            if ($extracted_text) {
                wp_insert_post([
                    'post_title'   => basename($upload['file']),
                    'post_type'    => 'crawled_urls',
                    'post_status'  => 'publish',
                    'post_content' => $extracted_text,
                ]);
            }
        } else {
            return new WP_REST_Response('File upload failed: ' . $upload['error'], 500);
        }
    }

    return new WP_REST_Response('Files uploaded and processed successfully', 200);
}


// Register REST API endpoint for getting generated Q/A
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/get_generated_qa', array(
        'methods' => 'GET',
        'callback' => 'handle_get_generated_qa',
        'permission_callback' => function () {
            return current_user_can('read'); // Adjust permissions as needed
        }
    ));
});

function handle_get_generated_qa(WP_REST_Request $request) {
    $post_id = $request->get_param('post_id');

    // Check if the post ID is provided
    if (!$post_id) {
        return new WP_REST_Response(array('message' => 'Post ID is required'), 400);
    }

    // Fetch the post
    $post = get_post($post_id);

    if (!$post || $post->post_status !== 'publish') {
        return new WP_REST_Response(array('message' => 'Post not found or not published'), 404);
    }

    // Retrieve the generated Q/A from the post meta
    $generated_qa = get_post_meta($post_id, 'generated_qa', true);

    if (!$generated_qa) {
        return new WP_REST_Response(array('message' => 'No Q/A found for this post'), 404);
    }

    return new WP_REST_Response(json_decode($generated_qa), 200);
}

// Allow uploading of .md files
function fine_tune_dashboard_custom_mime_types($mimes) {
    // Add .md file type
    $mimes['md'] = 'text/markdown';
    return $mimes;
}
add_filter('upload_mimes', 'fine_tune_dashboard_custom_mime_types');


add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/save_qa', array(
        'methods' => 'POST',
        'callback' => 'handle_save_qa_request',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        }
    ));
});


// Handle the REST API for getting all saved Q/A
function handle_get_all_saved_qa(WP_REST_Request $request) {
    $user_id = $request->get_param('user_id');

    // Check if the user ID is provided
    if (!$user_id) {
        return new WP_REST_Response(array('message' => 'User ID is required'), 400);
    }

    // Fetch all posts for the user with saved Q/A
    $args = array(
        'post_type' => 'crawled_urls',
        'author' => $user_id,
        'post_status' => 'publish',
        'meta_query' => array(
            array(
                'key' => 'generated_qa',
                'compare' => 'EXISTS',
            ),
        ),
    );
    $user_posts = get_posts($args);

    if (empty($user_posts)) {
        return new WP_REST_Response(array('message' => 'No saved Q/A found for this user'), 404);
    }

    $qa_data = array();
    foreach ($user_posts as $post) {
        $qa_data[] = array(
            'post_id' => $post->ID,
            'title' => get_the_title($post->ID),
            'generated_qa' => json_decode(get_post_meta($post->ID, 'generated_qa', true)),
        );
    }

    return new WP_REST_Response($qa_data, 200);
}

function handle_save_qa_request(WP_REST_Request $request) {
    $post_id = $request->get_param('post_id');
    $qa_data = $request->get_param('qa_data');

    if (!$post_id || !$qa_data) {
        return new WP_REST_Response(array('message' => 'Post ID and Q/A data are required'), 400);
    }

    update_post_meta($post_id, 'generated_qa', json_encode($qa_data));

    return new WP_REST_Response(array('message' => 'Q/A saved successfully'), 200);
}


// Bypass MIME type check for testing purposes (only use this for debugging)
function allow_unfiltered_uploads($file) {
    $file['ext'] = 'md'; // Force extension to .md
    $file['type'] = 'text/markdown'; // Force MIME type to text/markdown
    return $file;
}
add_filter('wp_check_filetype_and_ext', 'allow_unfiltered_uploads', 10, 1);

// Allow OPTIONS requests
add_action('init', function() {
    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        header('Access-Control-Allow-Origin: http://123finetunecom.local');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, Authorization');
        exit(0);
    }
});


add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/delete_post/(?P<id>\d+)', array(
        'methods' => 'DELETE',
        'callback' => 'handle_delete_post_request',
        'permission_callback' => function () {
            return current_user_can('delete_posts'); // Adjust permissions as needed
        }
    ));
});

function handle_delete_post_request(WP_REST_Request $request) {
    $post_id = $request->get_param('id');

    if (!$post_id) {
        return new WP_REST_Response(array('message' => 'Post ID is required'), 400);
    }

    $post = get_post($post_id);

    if (!$post || $post->post_status !== 'publish') {
        return new WP_REST_Response(array('message' => 'Post not found or not published'), 404);
    }

    // Delete the post
    $deleted = wp_delete_post($post_id, true);

    if ($deleted) {
        return new WP_REST_Response(array('message' => 'Post deleted successfully'), 200);
    } else {
        return new WP_REST_Response(array('message' => 'Failed to delete post'), 500);
    }
}


// Handle URL crawling
function handle_crawl_urls(WP_REST_Request $request) {
    $urls = $request->get_param('urls');
    $guzzleClient = new GuzzleClient();
    $goutteClient = new Client();

    foreach ($urls as $urlObj) {
        try {
            $url = sanitize_text_field($urlObj['value']);

            // Use Guzzle to fetch the page
            $response = $guzzleClient->request('GET', $url);
            $html = $response->getBody()->getContents();

            // Log the fetched HTML content for debugging
            error_log('Fetched HTML for ' . $url . ': ' . substr($html, 0, 1000));

            // Use Goutte to crawl and extract main content
            $crawler = $goutteClient->request('GET', $url);
            $main_content = '';

            // Extract content from various tags and handle lazy-loaded content
            $crawler->filter('article, div.content, div.main-content, section, p, span, img[data-src]')->each(function ($node) use (&$main_content) {
                if ($node->nodeName() == 'img') {
                    $main_content .= '<img src="' . $node->attr('data-src') . '">';
                } else {
                    $main_content .= $node->html();
                }
            });

            // Clean up unwanted elements
            $crawler->filter('script, style, .ad, .advertisement')->each(function ($node) {
                $node->getNode(0)->parentNode->removeChild($node->getNode(0));
            });

            // Log the cleaned and extracted content for debugging
            error_log('Extracted content for ' . $url . ': ' . substr($main_content, 0, 1000));

            // Save the URL and the main content as a custom post
            wp_insert_post([
                'post_title'   => $url,
                'post_type'    => 'crawled_urls',
                'post_status'  => 'publish',
                'post_content' => $main_content,
            ]);

        } catch (Exception $e) {
            error_log('Failed to process URL: ' . $url . ' - Error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Failed to process URL: ' . $url]);
        }
    }

    return new WP_REST_Response('URLs processed and crawled successfully', 200);
}

