<?php
namespace Wpcfajal;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

define('WP_CF_ACCESS_JWT_ASSERTION_CERTS', 'https://qwtyde.cloudflareaccess.com/cdn-cgi/access/certs');
define('WP_CF_ACCESS_JWT_AUD', '');
define('WP_CF_ACCESS_ALG', ['RS256']);

$wpcfajal_keys = null;

function refresh_keys()
{
    global $wpcfajal_keys;
    JWT::$leeway = 60;
    try {
        $response = wp_remote_get(WP_CF_ACCESS_JWT_ASSERTION_CERTS);
        $jwks = json_decode(wp_remote_retrieve_body($response), true);
        $wpcfajal_keys = JWK::parseKeySet($jwks);
    } catch (Exception $e) {
        $wpcfajal_keys = null;
    }
}

function check_login()
{
    $allowed = false;
    $user = null;
    $user_id = 0;
    $retry_times = 1;
    $retry_count = 0;
    $cf_auth_jwt = $_SERVER["Cf-Access-Jwt-Assertion"];
    while (!$allowed || $retry_count < $retry_times) {
        try {
            $jwt_decoded = JWT::decode($cf_auth_jwt, $wpcfajal_keys, WP_CF_ACCESS_ALG);
            if (isset($jwt_decoded->aud) && $jwt_decoded->aud == WP_CF_ACCESS_AUD) {
                if (isset($jwt_decoded->email)) {
                    $user = get_user_by('email', $jwt_decoded->email);
                    $user_id = $user->id;
                    $allowed = true;
                }
            }
        } catch (Exception $e) {
            refresh_keys();
        }
    }

    if ($allowed) {
        if ($user_id > 0 && !is_user_logged_in()) {
            $user = get_user_by('id', $user_id);
            wp_set_current_user($user->ID, $user->user_login);
            if (is_user_logged_in()) {
                return true;
            }
        } elseif ($user_id == 0 && is_user_logged_in()) {
            wp_logout();
            wp_set_current_user(0);
        }
    }

    if ($user_id == 0) {
        $nousermessage = 'User not found in site database. Please contact your site administrator for access.';
        echo '<html><body><center>';
        echo '<h1>Cloudflare Access mismatch:</h1>';
        echo $nousermessage;
        echo "<br /><a href='/cdn-cgi/access/logout'>Logout and try again...</a>";
        echo '</center></body></html>';
        die;
    }
}

function logout()
{
    wp_logout();
    wp_set_current_user(0);
    wp_safe_redirect('/cdn-cgi/access/logout');
}
