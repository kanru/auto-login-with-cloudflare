<?php
/**
 * @link               https://github.com/kanru/wp-cf-access-jwt-assertion-login
 * @since              0.9.0
 * @package            Wp_Cf_Access_Jwt_Assertion_Login
 *
 * @wordpress-plugin
 * Plugin Name:        Cloudflare Access Auto Login
 * Plugin URI:         https://github.com/kanru/wp-cf-access-jwt-assertion-login
 * Description:        Allow login to Wordpress when using Cloudflare Access.
 * Version:            0.9.0
 * Author:             Kan-Ru Chen
 * Author URI:         https://github.com/kanru
 * License:            GPL-2.0+
 * License URI:        http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:        wp-cf-access-jwt-assertion-login
 * Domain Path:        /languages
 */

namespace Wpcfajal;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

require_once 'vendor/php-jwt/src/BeforeValidException.php';
require_once 'vendor/php-jwt/src/ExpiredException.php';
require_once 'vendor/php-jwt/src/SignatureInvalidException.php';
require_once 'vendor/php-jwt/src/JWK.php';
require_once 'vendor/php-jwt/src/JWT.php';

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

// define('WP_CF_ACCESS_AUTH_DOMAIN', '');
// define('WP_CF_ACCESS_JWT_AUD', '');
// define('WP_CF_ACCESS_REDIRECT_LOGIN', true);

define('WP_CF_ACCESS_JWT_ALG', ['RS256']);
define('WP_CF_ACCESS_RETRY', 1);
define('WP_CF_ACCESS_CACHE_KEY', 'wpcfajal_jwks');

function get_auth_domain()
{
    if (\defined('WP_CF_ACCESS_AUTH_DOMAIN')) {
        return WP_CF_ACCESS_AUTH_DOMAIN;
    }
    return get_option('wpcfajal_auth_domain');
}

function get_jwt_aud()
{
    if (\defined('WP_CF_ACCESS_JWT_AUD')) {
        return WP_CF_ACCESS_JWT_AUD;
    }
    return get_option('wpcfajal_aud');
}

function get_redirect_login()
{
    if (\defined('WP_CF_ACCESS_REDIRECT_LOGIN')) {
        return WP_CF_ACCESS_REDIRECT_LOGIN;
    }
    return get_option('wpcfajal_redirect_login_page');
}

function refresh_keys()
{
    $jwks = null;
    try {
        $response = wp_remote_get('https://' . get_auth_domain() . '/cdn-cgi/access/certs');
        $jwks = json_decode(wp_remote_retrieve_body($response), true);
    } catch (Exception $e) {
        $jwks = null;
    } finally {
        wp_cache_set(WP_CF_ACCESS_CACHE_KEY, $jwks);
        return $jwks;
    }
}

function verify_aud($aud)
{
    if (is_array($aud)) {
        return in_array(get_jwt_aud(), $aud, true);
    } elseif (is_string($aud)) {
        return get_jwt_aud() == $aud;
    }
    return false;
}

/**
 * Called for every page after setup theme
 */
function login()
{
    if (!get_auth_domain() || !get_jwt_aud()) {
        return;
    }

    $jwks = wp_cache_get(WP_CF_ACCESS_CACHE_KEY);
    if (!$jwks) {
        $jwks = refresh_keys();
    }

    $recognized = false;
    $user = null;
    $user_id = 0;
    $retry_count = 0;
    $cf_auth_jwt = $_COOKIE["CF_Authorization"];

    if (isset($cf_auth_jwt) && $cf_auth_jwt != "") {
        while (!$recognized && $retry_count < WP_CF_ACCESS_RETRY) {
            try {
                JWT::$leeway = 60;
                $jwt_decoded = JWT::decode($cf_auth_jwt, JWK::parseKeySet($jwks), WP_CF_ACCESS_JWT_ALG);
                if (isset($jwt_decoded->aud) && verify_aud($jwt_decoded->aud)) {
                    if (isset($jwt_decoded->email)) {
                        $current_user = wp_get_current_user();
                        if ($current_user->exists() && $current_user->user_email == $jwt_decoded->email) {
                            $user = $current_user;
                            $user_id = $user->id;
                        } else {
                            $user = get_user_by('email', $jwt_decoded->email);
                            $user_id = $user->id;
                        }
                        $recognized = true;
                    }
                }
            } catch (\UnexpectedValueException $e) {
                $jwks = refresh_keys();
            }
            $retry_count++;
        }
    }

    if ($recognized) {
        if ($user_id > 0) {
            $current_user = wp_get_current_user();
            if ($user_id != $current_user->id) {
                wp_set_auth_cookie($user_id);
                wp_set_current_user($user_id);
                add_action('init', function () use ($user) {
                    do_action('wp_login', $user->name, $user);
                    wp_safe_redirect('/wp-admin');
                    exit;
                });
            }
        } elseif ($user_id == 0 && is_user_logged_in() && !wp_doing_ajax()) {
            wp_logout();
            wp_set_current_user(0);
        }
    }
}

function login_redirect()
{
    if (!get_auth_domain() || !get_jwt_aud()) {
        return;
    }

    if (get_redirect_login() && wp_safe_redirect(admin_url())) {
        exit;
    }
}

function logout_redirect()
{
    if (wp_safe_redirect('/cdn-cgi/access/logout')) {
        exit;
    }
}

\add_action('plugins_loaded', __NAMESPACE__ . '\\login');
\add_action('login_form_login', __NAMESPACE__ . '\\login_redirect');
\add_action('wp_logout', __NAMESPACE__ . '\\logout_redirect');

function settings_init()
{
    register_setting('wpcfajal', 'wpcfajal_auth_domain');
    register_setting('wpcfajal', 'wpcfajal_aud');
    register_setting('wpcfajal', 'wpcfajal_redirect_login_page');

    add_settings_section(
        'wpcfajal_section_general',
        __('Application settings', 'wp-cf-access-jwt-assertion-login'),
        __NAMESPACE__ . '\\section_general_callback',
        'wpcfajal'
    );

    add_settings_field(
        'wpcfajal_field_auth_domain',
        __('Auth domain', 'wp-cf-access-jwt-assertion-login'),
        __NAMESPACE__ . '\\field_auth_domain_cb',
        'wpcfajal',
        'wpcfajal_section_general',
        array(
            'label_for' => 'wpcfajal_field_auth_domain',
            'class' => 'wpcfajal_row',
        )
    );

    add_settings_field(
        'wpcfajal_field_aud',
        __('Application audience (AUD) tag', 'wp-cf-access-jwt-assertion-login'),
        __NAMESPACE__ . '\\field_aud_cb',
        'wpcfajal',
        'wpcfajal_section_general',
        array(
            'label_for' => 'wpcfajal_field_aud',
            'class' => 'wpcfajal_row',
        )
    );

    add_settings_field(
        'wpcfajal_field_redirect_login_page',
        __('Redirect login page', 'wp-cf-access-jwt-assertion-login'),
        __NAMESPACE__ . '\\field_redirect_login_page_cb',
        'wpcfajal',
        'wpcfajal_section_general',
        array(
            'label_for' => 'wpcfajal_field_redirect_login_page',
            'class' => 'wpcfajal_row',
        )
    );
}

add_action('admin_init', __NAMESPACE__ . '\\settings_init');

function section_general_callback($args)
{
}

function field_auth_domain_cb($args)
{
    if (\defined('WP_CF_ACCESS_AUTH_DOMAIN')) {
        $auth_domain = WP_CF_ACCESS_AUTH_DOMAIN;
        $disabled = true;
    } else {
        $auth_domain = get_option('wpcfajal_auth_domain');
        $disabled = false;
    }
    ?>
    <input name="wpcfajal_auth_domain" type="text" id="<?php echo $args['label_for'] ?>" value="<?php echo esc_html_e($auth_domain) ?>" class="regular-text" <?php echo $disabled ? "disabled" : "" ?>>
    <?php
}

function field_aud_cb($args)
{
    if (\defined('WP_CF_ACCESS_JWT_AUD')) {
        $aud = WP_CF_ACCESS_JWT_AUD;
        $disabled = true;
    } else {
        $aud = get_option('wpcfajal_aud');
        $disabled = false;
    }
    ?>
    <input name="wpcfajal_aud" type="text" id="<?php echo $args['label_for'] ?>" value="<?php echo esc_html_e($aud) ?>" class="regular-text" <?php echo $disabled ? "disabled" : "" ?>>
    <?php
}

function field_redirect_login_page_cb($args)
{
    if (\defined('WP_CF_ACCESS_REDIRECT_LOGIN')) {
        $redirect_login_page = WP_CF_ACCESS_REDIRECT_LOGIN;
        $disabled = true;
    } else {
        $redirect_login_page = get_option('wpcfajal_redirect_login_page');
        $disabled = false;
    }
    ?>
    <label for="<?php echo $args['label_for'] ?>">
    <input name="wpcfajal_redirect_login_page" type="checkbox" id="<?php echo $args['label_for'] ?>" <?php echo $redirect_login_page ? "checked" : "" ?> <?php echo $disabled ? "disabled" : "" ?>>
    <?php echo __('redirect to Cloudflare Access', 'wp-cf-access-jwt-assertion-login') ?>
    </label>
    <?php
}

function settings_page()
{
    add_options_page(
        __('Cloudflare Access Auto Login', 'wp-cf-access-jwt-assertion-login'),
        __('Cloudflare Access', 'wp-cf-access-jwt-assertion-login'),
        'manage_options',
        'cloudflare_access_login.php',
        __NAMESPACE__ . '\\settings_page_html'
    );
}

add_action('admin_menu', __NAMESPACE__ . '\\settings_page');

function settings_page_html()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    <form action="options.php" method="post">
    <?php

    settings_fields('wpcfajal');
    do_settings_sections('wpcfajal');
    submit_button(__('Save Settings', 'wp-cf-access-jwt-assertion-login'));

    ?>
    </form>
    </div>
    <?php
}

function wpcfajal_load_plugin_textdomain()
{
    load_plugin_textdomain('wp-cf-access-jwt-assertion-login', false, basename(dirname(__FILE__)) . '/languages/');
}
add_action('plugins_loaded', __NAMESPACE__ . '\\wpcfajal_load_plugin_textdomain');