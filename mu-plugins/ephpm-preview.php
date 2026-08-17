<?php
/**
 * Plugin Name: ePHPm Preview Adjustments
 * Description: Must-use tweaks for the ePHPm preview environment. Loaded on
 *              every request (including the installer) before regular plugins.
 * Author: ePHPm
 * License: MIT
 */

if (!defined('ABSPATH')) {
    return;
}

/*
 * Short-circuit wp_mail().
 *
 * The embedded PHP build has no mail() function (there is no local MTA), so
 * WordPress's PHPMailer fatals with "Call to undefined function
 * PHPMailer\PHPMailer\mail()" the moment anything tries to send email. During
 * `wp core install` / the web installer this happens at the very end, in
 * wp_new_blog_notification() — after the database is fully populated — which
 * turns a successful install into an HTTP 500 on the finalize step. Preview
 * sites are disposable and must not send email anyway (WP_HTTP_BLOCK_EXTERNAL
 * is already set), so pretend every message was sent. Returns true from the
 * pre_wp_mail short-circuit filter (WordPress 5.7+).
 */
add_filter('pre_wp_mail', '__return_true');
