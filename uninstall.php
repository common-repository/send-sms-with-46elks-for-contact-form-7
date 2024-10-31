<?php
/**
 * WP SMS 46elks
 *
 * @package     wp-sms-46elks
 * @author      Tobias Ehlert
 * @license     GPL2
 * @link        http://ehlert.se/wordpress/wp-sms-46elks/
 */

// If uninstall is not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit();
}

delete_option('wp-sms-46elks-from');
delete_option('wp-sms-46elks-default-countrycode');
delete_option('wp-sms-46elks-balancealert');
delete_option('wp-sms-46elks-balancealerte-mail');
delete_option('wp-sms-46elks-balancealert-phone-number');
delete_option('wp-sms-46elks-balancealert-sent');
delete_option('wp-sms-46elks-api-username');
delete_option('wp-sms-46elks-api-password');
delete_option('wp-sms-46elks-cf7-form-name');
delete_option('wp-sms-46elks-cf7-send-sms');
delete_option('wp-sms-46elks-cf7-send-to');
delete_option('wp-sms-46elks-cf7-sms-content');
delete_option('wp-sms-46elks-cf7-sender-id');
delete_option('wp-sms-46elks-cf7-from');