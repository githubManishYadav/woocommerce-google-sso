<?php
/*
Plugin Name: WooCommerce Google SSO
Description: Allows users to log in or register with Google in WooCommerce.
Version: 1.0
Author: Manish Yadav
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Include Composer autoload
require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

// Enqueue CSS for the front end
function wgs_enqueue_styles() {
    wp_enqueue_style('wgs-style', plugin_dir_url(__FILE__) . 'css/style.css');
}
add_action('wp_enqueue_scripts', 'wgs_enqueue_styles');

// Enqueue CSS for the admin area
function wgs_enqueue_admin_styles() {
    wp_enqueue_style('wgs-admin-style', plugin_dir_url(__FILE__) . 'css/style.css');
}
add_action('admin_enqueue_scripts', 'wgs_enqueue_admin_styles');

// Add settings link to the plugin actions
function wgs_add_settings_link($links) {
    $settings_link = '<a href="options-general.php?page=wgs_settings">Settings</a>';
    $help_link = '<a href="admin.php?page=wgs_help">Help</a>';
    array_unshift($links, $settings_link);
    array_unshift($links, $help_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wgs_add_settings_link');

// Add settings and help menu items
function wgs_add_admin_menu() {
    add_options_page(
        'WooCommerce Google SSO Settings',
        'WooCommerce Google SSO',
        'manage_options',
        'wgs_settings',
        'wgs_settings_page'
    );
    add_submenu_page(
        null,
        'WooCommerce Google SSO Help',
        'WooCommerce Google SSO Help',
        'manage_options',
        'wgs_help',
        'wgs_help_page'
    );
}
add_action('admin_menu', 'wgs_add_admin_menu');

// Register settings
function wgs_register_settings() {
    register_setting('wgs_settings_group', 'wgs_google_client_id');
    register_setting('wgs_settings_group', 'wgs_google_client_secret');
}
add_action('admin_init', 'wgs_register_settings');

// Settings page content
function wgs_settings_page() {
    ?>
    <div class="wrap">
        <h1>WooCommerce Google SSO Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('wgs_settings_group'); ?>
            <?php do_settings_sections('wgs_settings_group'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Google Client ID</th>
                    <td><input type="text" name="wgs_google_client_id" value="<?php echo esc_attr(get_option('wgs_google_client_id')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Google Client Secret</th>
                    <td><input type="text" name="wgs_google_client_secret" value="<?php echo esc_attr(get_option('wgs_google_client_secret')); ?>" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <h2>Need Help?</h2>
        <p><a href="admin.php?page=wgs_help">Click here for help and setup instructions</a>.</p>
    </div>
    <?php
}

// Help page content
function wgs_help_page() {
    ?>
    <div class="wrap">
        <h1>WooCommerce Google SSO Help</h1>
        <h2>Setting Up Google SSO</h2>
        <ol>
            <li>Go to the <a href="https://console.developers.google.com/" target="_blank">Google Developers Console</a> and create a new project.</li>
            <li>Navigate to the "OAuth consent screen" and configure your OAuth consent screen.</li>
            <li>Under "Credentials", create a new OAuth 2.0 Client ID.</li>
            <li>Set the redirect URI to <code><?php echo home_url('/google-callback'); ?></code>.</li>
            <li>Copy the Client ID and Client Secret into the plugin settings page.</li>
        </ol>
        <h2>Usage</h2>
        <p>Users can log in or register using their Google account by clicking the "Login with Google" button on the login or registration page.</p>
    </div>
    <?php
}

// Initialize Google Client
function wgs_initialize_google_client() {
    $client_id = get_option('wgs_google_client_id');
    $client_secret = get_option('wgs_google_client_secret');
    $client = new Google_Client();
    $client->setClientId($client_id);
    $client->setClientSecret($client_secret);
    $client->setRedirectUri(home_url('/google-callback'));
    $client->addScope("email");
    $client->addScope("profile");

    return $client;
}

// Handle Google login
function wgs_google_login() {
    $client = wgs_initialize_google_client();
    $auth_url = $client->createAuthUrl();
    header('Location: ' . filter_var($auth_url, FILTER_SANITIZE_URL));
    exit();
}

// Handle Google callback
function wgs_google_callback() {
    $client = wgs_initialize_google_client();

    if (isset($_GET['code'])) {
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
        $client->setAccessToken($token);

        // Get profile data
        $google_oauth = new Google_Service_Oauth2($client);
        $google_account_info = $google_oauth->userinfo->get();
        $email = $google_account_info->email;
        $name = $google_account_info->name;

        // Process the user data
        wgs_process_user($email, $name);
    }
}

// Process user data
function wgs_process_user($email, $name) {
    if (email_exists($email)) {
        // User exists, log them in
        $user = get_user_by('email', $email);
        wc_set_customer_auth_cookie($user->ID);
    } else {
        // User does not exist, create new user
        $password = wp_generate_password();
        $user_id = wp_create_user($email, $password, $email);
        wp_update_user(array('ID' => $user_id, 'display_name' => $name));

        wc_set_customer_auth_cookie($user_id);
    }

    // Redirect to the "my account" page
    wp_redirect(site_url('/my-account'));
    exit();
}

// Add Google login button
function wgs_add_google_login_button() {
    echo '<a href="' . esc_url(home_url('/google-login')) . '"><img alt="google-sso-signup" class="google-sso-signup" src="../wp-content/plugins/woocommerce-google-sso/assets/google-register-black.png" /></a>';
}
add_action('woocommerce_login_form', 'wgs_add_google_login_button');


// Handle Google login and callback endpoints
function wgs_handle_google_auth() {
    if (strpos($_SERVER['REQUEST_URI'], '/google-login') !== false) {
        wgs_google_login();
    } elseif (strpos($_SERVER['REQUEST_URI'], '/google-callback') !== false) {
        wgs_google_callback();
    }
}
add_action('init', 'wgs_handle_google_auth');
