=== Auto Login with Cloudflare ===
Contributors: kanru
Tags: cloudflare,jwt,login
Donate link: https://www.buymeacoffee.com/kanru
Requires at least: 5.0
Tested up to: 6.1.1
Requires PHP: 5.3
Stable tag: 1.1.4
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt

Super simple way to allow a single sign on to your Wordpress site when using Cloudflare Access.

== Description ==
Enable Cloudflare Access self-hosted application to protect your `/wp-admin` folder. Add your auth domain and aud settings from Cloudflare Access. Authenticated user will be automatically logined to Wordpress if their email address matches.

Follow [Cloudflare document](https://developers.cloudflare.com/cloudflare-one/applications/configure-apps/self-hosted-apps/) to setup Access.

You can also define configs in `wp-config.php`

    define('WP_CF_ACCESS_AUTH_DOMAIN', 'yourdomain.cloudflareaccess.com');
    define('WP_CF_ACCESS_JWT_AUD', 'examplef2nat0rkar2866wn829a0x2ztdg');
    define('WP_CF_ACCESS_REDIRECT_LOGIN', true);

This plugin is not affiliated with nor developed by Cloudflare. All trademarks, service marks and company names are the property of their respective owners.

== Frequently Asked Questions ==

= How do I redirect the WP login page at `/wp-login.php` to Cloudflare Access? =

Enable the "Redirect login page" option and all future logins will be redirected to `/wp-admin` and trigger Access authentication.

= Why do I get infinite redirect loop after enabling the redirect login page option? =

The option assumes that `/wp-admin` folder is protected by Cloudflare Access. If the folder is not protected, then auto-login will fail and redirect back to the login page, causing the redirect loop.

== Screenshots ==
1. Settings 

== Changelog ==

= 1.1.4 =
* Fix redirect issue for non-default wp-admin urls

= 1.1.3 =
* Tested with WordPress 6.1.1

= 1.1.2 =
* Tested with WordPress 5.9
* Fixed errors when activated in multi-site enabled installation

= 1.1.1 =
* Show an error message when user does not exist to prevent redirect loop

= 1.0.0 =
* Tested with WordPress 5.8
* Update php-jwt to 5.4
* Stable. No major change planned

= 0.9.3 =
* Tested with WordPress 5.7

= 0.9.2 =
* update minimum requirements
* update php-jwt to 5.2.1

= 0.9.0 =
* First beta release.

== Upgrade Notice ==
