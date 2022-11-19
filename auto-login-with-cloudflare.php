<?php

/**
 * @link               https://github.com/kanru/auto-login-with-cloudflare
 * @since              0.9.0
 * @package            Wpcfajal
 *
 * @wordpress-plugin
 * Plugin Name:        Auto Login with Cloudflare
 * Plugin URI:         https://github.com/kanru/auto-login-with-cloudflare
 * Description:        Allow login to Wordpress when using Cloudflare Access.
 * Version:            1.1.4
 * Author:             Kan-Ru Chen
 * Author URI:         https://github.com/kanru
 * License:            GPL-2.0+
 * License URI:        http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:        auto-login-with-cloudflare
 * Domain Path:        /languages
 */

namespace Wpcfajal;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

require_once __DIR__ . '/vendor/php-jwt/src/BeforeValidException.php';
require_once __DIR__ . '/vendor/php-jwt/src/ExpiredException.php';
require_once __DIR__ . '/vendor/php-jwt/src/SignatureInvalidException.php';
require_once __DIR__ . '/vendor/php-jwt/src/JWK.php';
require_once __DIR__ . '/vendor/php-jwt/src/JWT.php';
require_once __DIR__ . '/settings.php';

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
    if (defined('WP_CF_ACCESS_AUTH_DOMAIN')) {
        return constant('WP_CF_ACCESS_AUTH_DOMAIN');
    }
    return get_option('wpcfajal_auth_domain');
}

function get_jwt_aud()
{
    if (defined('WP_CF_ACCESS_JWT_AUD')) {
        return constant('WP_CF_ACCESS_JWT_AUD');
    }
    return get_option('wpcfajal_aud');
}

function get_redirect_login()
{
    if (defined('WP_CF_ACCESS_REDIRECT_LOGIN')) {
        return constant('WP_CF_ACCESS_REDIRECT_LOGIN');
    }
    return get_option('wpcfajal_redirect_login_page');
}

function refresh_keys()
{
    $jwks = null;
    try {
        $response = wp_remote_get(esc_url_raw('https://' . get_auth_domain() . '/cdn-cgi/access/certs'));
        $jwks = json_decode(wp_remote_retrieve_body($response), true);
    } catch (\Exception $e) {
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

    if (isset($_COOKIE["CF_Authorization"]) && $_COOKIE["CF_Authorization"] != "") {
        $cf_auth_jwt = $_COOKIE["CF_Authorization"];
        while (!$recognized && $retry_count < WP_CF_ACCESS_RETRY) {
            try {
                JWT::$leeway = 60;
                $jwt_decoded = JWT::decode($cf_auth_jwt, JWK::parseKeySet($jwks), WP_CF_ACCESS_JWT_ALG);
                if (isset($jwt_decoded->aud) && verify_aud($jwt_decoded->aud)) {
                    if (isset($jwt_decoded->email)) {
                        $current_user = wp_get_current_user();
                        if ($current_user->exists() && $current_user->user_email == $jwt_decoded->email) {
                            $user = $current_user;
                            $user_id = $user->ID;
                        } else {
                            $user = get_user_by('email', $jwt_decoded->email);
                            $user_id = $user->ID;
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
            if ($user_id != $current_user->ID) {
                wp_set_auth_cookie($user_id);
                wp_set_current_user($user_id);
                add_action('init', function () use ($user) {
                    do_action('wp_login', $user->name, $user);
                    wp_safe_redirect(admin_url());
                    exit;
                });
            }
        } elseif ($user_id == 0 && is_user_logged_in() && !wp_doing_ajax()) {
            wp_logout();
            wp_set_current_user(0);
        } elseif (get_redirect_login()) {
            // User does not exist. If login page redirection is enabled then
            // show a error message and exit to prevent redirect loop.
            $args = array(
                'response' => 500,
                'link_url' => '/cdn-cgi/access/logout',
                'link_text' => __('Logout the current user.', 'auto-login-with-cloudflare'),
                'exit' => true,
            );
            $error = __('<strong>Error</strong>: The user does not exist in this site. Please contact the site admin.', 'auto-login-with-cloudflare');
            wp_die($error, __('User not found', 'auto-login-with-cloudflare'), $args);
        }
    }
}

// Redirect /wp-login.php to /wp-admin/
function login_redirect()
{
    // Don't redirect if the plugin is not configured, so that the admin can
    // login and configure the plugin.
    if (!get_auth_domain() || !get_jwt_aud()) {
        return;
    }

    // If the redirect option is enabled then redirect to /wp-admin/ to trigger
    // JWT auth.
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

add_action('plugins_loaded', __NAMESPACE__ . '\\login');
add_action('login_form_login', __NAMESPACE__ . '\\login_redirect');
add_action('wp_logout', __NAMESPACE__ . '\\logout_redirect');

function wpcfajal_load_plugin_textdomain()
{
    load_plugin_textdomain('auto-login-with-cloudflare', false, basename(dirname(__FILE__)) . '/languages/');
}
add_action('plugins_loaded', __NAMESPACE__ . '\\wpcfajal_load_plugin_textdomain');

function plugin_action_links($actions)
{
    $actions[] = '<a href="' . esc_url(get_admin_url(null, 'options-general.php?page=wpcfajal')) . '">' . __('Settings', 'auto-login-with-cloudflare') . '</a>';
    $actions[] = '<a href="https://www.buymeacoffee.com/kanru" target="_blank">' . __('Buy me a coffee', 'auto-login-with-cloudflare') . '</a>';
    return $actions;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), __NAMESPACE__ . '\\plugin_action_links');
