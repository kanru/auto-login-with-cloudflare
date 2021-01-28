<?php
/**
 * @link               https://github.com/kanru/wp-cf-access-jwt-assertion-login
 * @since              0.1.0
 * @package            Wp_Cf_Access_Jwt_Assertion_Login
 *
 * @wordpress-plugin
 * Plugin Name:        Cloudflare Access JWT Login
 * Plugin URI:         https://github.com/kanru/wp-cf-access-jwt-assertion-login
 * Description:        Allowa login to Wordpress when using Cloudflare Access.
 * Version:            0.1.0
 * Author:             Kan-Ru Chen
 * Author URI:         https://github.com/kanru
 * License:            GPL-2.0+
 * License URI:        http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:        wp-cf-access-jwt-assertion-login
 * Domain Path:        /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

function wpcfajal_init()
{
    require_once __DIR__ . 'vendor/php-jwt/src/BeforeValidException.php';
    require_once __DIR__ . 'vendor/php-jwt/src/ExpiredException.php';
    require_once __DIR__ . 'vendor/php-jwt/src/SignatureInvalidException.php';
    require_once __DIR__ . 'vendor/php-jwt/src/JWK.php';
    require_once __DIR__ . 'vendor/php-jwt/src/JWT.php';
    Wpcfajal\refresh_keys();
}

function wpcfajal_activation()
{
    wpcfajal_init();
}

function wpcfajal_deactivation()
{

}

add_action('after_setup_theme', 'wpcfajal_check_login');
function wpcfajal_check_login()
{
    Wpcfajal\check_login();
}

add_action('wp_logout', 'wpcfajal_redirect_to_cf_access_logout');
function wpcfajal_redirect_to_cf_access_logout()
{
    Wpcfajal\logout();
    exit();
}

register_activation_hook(__FILE__, 'wpcfajal_activation');
register_deactivation_hook(__FILE__, 'wpcfajal_deactivation');
