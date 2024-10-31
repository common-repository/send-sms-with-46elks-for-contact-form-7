<?php
/**
 * WP SMS 46elks
 *
 * @package     wp-sms-46elks
 * @author      46elks
 * @license     GPL2
 * @link        https://46elks.com/
 */

/*
Plugin Name:    Send SMS with 46elks for Contact Form 7
Plugin URI:     https://46elks.com/
Description:    Wordpress module for sending SMS using 46elks and Contact Form 7
Version:        1.0.1
Author:         46elks
Author URI:     https://46elks.com/
License:        GPL2
License URI:    http://www.gnu.org/licenses/gpl-2.0.txt
Text Domain:    wp-sms-46elks
Domain Path:    /languages


Send SMS with 46elks for Contact Form 7 is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.

Send SMS with 46elks for Contact Form 7 is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Send SMS with 46elks for Contact Form 7.
*/

if (!class_exists('WPSMS46elks')) {
    class WPSMS46elks
    {
        private $plugin_slug = 'wp-sms-46elks';
        private $cellphone_slug = 'cellphone';
        private $debug = false;
        private $frommaxlength = '11';
        private $totalSMScost = '0';
        private $credMultiply = 10000;
        private $API_uri = 'https://api.46elks.com/a1';

        protected $AccountBalance;
        protected $FromOptions = array();
        protected $receivers = array();
        protected $sms = array();
        protected $result = array('success' => '0', 'failed' => '0');
        protected $status = array();


        public function __construct()
        {
            // init post function for wp-sms-46elks
            add_action('init', array($this, 'wpsms46elks_init'));

            add_action("wpcf7_mail_sent", array($this, 'wpsms46elks_cf7_send_sms'));

            // add pages to WP-admin
            add_action('admin_menu', array($this, 'wpsms46elks_admin_menu'));
            add_action('admin_init', array($this, 'wpsms46elks_admin_init'));

            // add dashboad widget for WP-admin
            add_action('wp_dashboard_setup', array($this, 'wpsms46elks_wp_dashboard_setup'));

            // add new field on users called cellphone
            add_filter('user_contactmethods', array($this, 'wpsms46elks_user_contactmethods'));

            // adding jquery if it's not enqueued yet
            add_action('admin_enqueue_scripts', array($this, 'wpsms46elks_load_jquery'));

            // adding gsm charset counter javascript
            add_action('admin_enqueue_scripts', array($this, 'wpsms46elks_jquery_smscharcount'));
        }

        function wpsms46elks_init()
        {
            // Add receivers based on Wordpress setup for wp-sms-46elks
            $this->addReceiversFromWP();

            // count all receivers for counters
            $this->totalReceivers = count($this->getReceivers());

            // check if message is longer than zerogfjtur
            if (isset($_POST['wp-sms-46elks-send-to']) && strlen($_POST['wp-sms-46elks-send-to']) > 0) {
                $this->addSendTo(sanitize_text_field($_POST['wp-sms-46elks-send-to']));
            } elseif (isset($_POST['wp-sms-46elks-send-to']))
                $this->status['failed'] = __('You forgot to enter the Send to phone number.', 'send-sms-with-46elks-for-contact-form-7') . '!';


            // check if message is longer than zero
            if (isset($_POST['wp-sms-46elks-message']) && strlen($_POST['wp-sms-46elks-message']) > 0) {
                // add message content and from to SMS
                $this->addMessage(sanitize_text_field($_POST['wp-sms-46elks-message']));
                $this->addFrom(sanitize_text_field($_POST['wp-sms-46elks-from']), sanitize_text_field($_POST['-cf7-sender-id-test']));

                // sending of the SMS
                $sms = array(
                    'from' => $this->sms['from'],
                    'to' => $this->sms['send_to'],
                    'message' => $this->sms['message']
                );

                $this->result = $this->sendSMS($sms);
                $this->json = json_decode(wp_remote_retrieve_body($this->result));
                // giving success message
                if ($this->json->status != "failed") {
                    $this->status['success'] = __('Your message was successful when sending to', 'send-sms-with-46elks-for-contact-form-7') . ' ' . $this->json->to . ' ' . __('cellphone', 'send-sms-with-46elks-for-contact-form-7') . '!';
                    if ($this->totalSMScost > 0)
                        $this->status['success'] .= __('The SMS cost was ', 'send-sms-with-46elks-for-contact-form-7') . $this->convertBalanceValue($this->totalSMScost) . ' sek';
                } else {
                    $this->status['failed'] = __('Your message failed when sending to', 'send-sms-with-46elks-for-contact-form-7') . ' ' . $this->json->to . ' ' . __('cellphone', 'send-sms-with-46elks-for-contact-form-7') . '!';
                }
            } elseif (isset($_POST['wp-sms-46elks-message']))
                $this->status['failed'] = __('You forgot to enter a message', 'send-sms-with-46elks-for-contact-form-7') . '!';

            // getting the current account balance for status window
            $this->getAccountRequest();
        }

        function get_string_between($string, $start, $end){
            $string = ' ' . $string;
            $ini = strpos($string, $start);
            if ($ini == 0) return '';
            $ini += strlen($start);
            $len = strpos($string, $end, $ini) - $ini;
            return substr($string, $ini, $len);
        }

        function wpsms46elks_cf7_send_sms(&$WPCF7_ContactForm)
        {

            $wpcf7 = $WPCF7_ContactForm->get_current();
            $form_name = get_option($this->plugin_slug . '-cf7-form-name');
            $start = '(';
            $end = ')';
            $form_name = ' ' . $form_name;
            $ini = strpos($form_name, $start);
            if ($ini == 0) return '';
            $ini += strlen($start);
            $len = strpos($form_name, $end, $ini) - $ini;
            $form_id = substr($form_name, $ini, $len);

            $settingsfrom = get_option($this->plugin_slug . '-cf7-from');
            $sms_content = get_option($this->plugin_slug . '-cf7-sms-content');

            $matches = array();

            $submission = WPCF7_Submission::get_instance();

            if ($sms_content != "" && $submission && $wpcf7->id == $form_id) {
                $posted_data = $submission->get_posted_data();
                if (preg_match_all('/\[[^\]]*\]/', $sms_content, $matches)) {
                    foreach ($matches[0] as $matchgroup) {
                        $parameter = substr($matchgroup, 1, -1);
                        $sms_content = str_replace($matchgroup, $posted_data[$parameter], $sms_content);
                    }
                }

                if (strlen($sms_content) >= 160) {
                    $sms_content = substr($sms_content, 0, 160);
                }
            }

            if ($settingsfrom == 'Sender ID') {
                $settingsfrom = get_option($this->plugin_slug . '-cf7-sender-id');
            }

            $send_to_raw = get_option($this->plugin_slug . '-cf7-send-to');
            $send_to_array = explode(',', $send_to_raw);

            foreach ($send_to_array as $send_to) {
                if ($wpcf7->id == $form_id) {
                    $sms = array(
                        'from' => $settingsfrom,
                        'to' => $send_to,
                        'message' => $sms_content
                    );

                    if ($this->checkAccountBalance() != "Blocked") {
                        $this->sendSMS($sms);
                    }
                }
            }
            return $WPCF7_ContactForm;
        }

        function wpsms46elks_load_jquery()
        {
            wp_enqueue_script('jquery');
        }

        function wpsms46elks_jquery_smscharcount()
        {
            wp_register_script('jquery_smscharcount', plugin_dir_url(__FILE__) . 'admin/js/jquery.smscharcount.js', array('jquery'));
            wp_enqueue_script('jquery_smscharcount');
        }

        function wpsms46elks_wp_dashboard_setup()
        {
            wp_add_dashboard_widget($this->plugin_slug . '-dashboard', __('Send SMS with 46elks for Contact Form 7', $this->plugin_slug), array($this, 'wpsms46elks_dashboard_content'), null);
        }

        function wpsms46elks_dashboard_content()
        {
            if ($this->getAccountLowCredits()) {
                ?>
                <p>
                    <b><?php _e('There are only a few credits left', 'send-sms-with-46elks-for-contact-form-7'); ?>!</b><br/>
                </p>
                <hr/>
                <?php
            }
            if ($this->getAccountNoCredits()) {
                ?>
                <p>
                    <b><?php _e('There are no credits left', 'send-sms-with-46elks-for-contact-form-7'); ?>!</b><br/>
                </p>
                <hr/>
                <?php
            }
            // get the account status from function
            $this->wpsms46elks_account_status();

            if ($this->getAccountType() === 'main') {
                $this->wpsms46elks_subaccount_balance();
            }
            ?>
            <hr/>
            <p>
                <a href="<?php echo esc_html(admin_url('admin.php?page=' . 'send-sms-with-46elks-for-contact-form-7')); ?>"><?php _e('Go to plugin page', 'send-sms-with-46elks-for-contact-form-7'); ?></a>
            </p>
            <?php
        }

        function handleResponse($response)
        {
            if (is_wp_error($response)) {
                $return = array(
                    'status' => 'error',
                    'servermsg' => $response->get_error_message()
                );
            } else {
                if (wp_remote_retrieve_response_code($response) == '200') {
                    if (isset($this->json))
                        $this->json->status++;
                }

                $return = array(
                    'status' => 'success',
                    'servermsg' => array(
                        'code' => wp_remote_retrieve_response_code($response),
                        'message' => wp_remote_retrieve_response_message($response),
                        'body' => wp_remote_retrieve_body($response)
                    )
                );
            }

            return $return;
        }

        function getAccountRequest()
        {
            // creating WP_remote_post and performing sending
            $sms = $this->generateSMSbasics();
            $this->response = wp_remote_get(
                $this->API_uri . '/me',
                $sms
            );

            $data = $this->handleResponse($this->response);
            $data['body'] = json_decode($data['servermsg']['body']);

            if ($data['servermsg']['code'] != 200) {
                // set account to invalid
                $this->setAccountValidStatus(false);
                $this->setAccountStatusMessage($data['servermsg']['code'] . ' ' . $data['servermsg']['message']);

                return false;
            } else {
                // set account to valid
                $this->setAccountValidStatus(true);

                // set various parameters for account stuff..
                $this->generateAccountInformation($data['body']);

                return true;
            }
        }

        function generateAccountInformation($data = '')
        {
            if (isset($data->displayname))
                $this->setAccountType('main');
            elseif (isset($data->name))
                $this->setAccountType('sub');

            if ($this->getAccountType() === 'main') {
                $this->setAccountBalance('name', $data->displayname);

                // account with limitation
                $this->setAccountLimited(true);

                if (is_numeric($data->balance))
                    $this->setAccountBalance('leftcred', $data->balance);
                else
                    $this->setAccountBalance('leftcred', '0');
            } elseif ($this->getAccountType() === 'sub') {
                $this->setAccountBalance('name', $data->name);

                if (is_numeric($data->balanceused))
                    $this->setAccountBalance('balanceused', $data->balanceused);
                else
                    $this->setAccountBalance('balanceused', '0');

                if (is_numeric($data->usagelimit)) {
                    // account with limitation
                    $this->setAccountLimited(true);

                    $this->setAccountBalance('usagelimit', $data->usagelimit);
                    $this->setAccountBalance('leftcred', ($this->getAccountBalance('usagelimit') - $this->getAccountBalance('balanceused')));
                } else {
                    // account does not have any limitation set
                    $this->setAccountLimited(false);
                }
            }

            $this->setAccountBalance('currency', $data->currency);

            $this->checkAccountBalance();

            return true;
        }

        function checkAccountBalance()
        {
            // run is its a limited account

            if ($this->getAccountLimited()) {
                if (($this->totalReceivers * $this->credMultiply) >= $this->getAccountBalance('leftcred'))
                    $this->setAccountNoCredits(true);
                else {
                    $this->setAccountNoCredits(false);
                    $balanceAlert = floatval(get_option($this->plugin_slug . '-balancealert'));
                    if (!empty($balanceAlert) && ($balanceAlert * $this->credMultiply * 1.1) >= $this->getAccountBalance('leftcred')) {
                        $this->setAccountLowCredits(true);
                        $this->triggerAlertEmail();
                    } else {
                        $this->setAccountLowCredits(false);

                        if (get_option($this->plugin_slug . '-balancealert-sent') == 'true')
                            update_option($this->plugin_slug . '-balancealert-sent', 'false');
                    }
                    if (!empty($balanceAlert) && ($balanceAlert * $this->credMultiply) >= $this->getAccountBalance('leftcred')) {
                        return "Blocked";
                    }
                }
            }
        }

        function setAccountValidStatus($status = false)
        {
            $this->AccountValidStatus = $status;
            return true;
        }

        function getAccountValidStatus()
        {
            return ($this->AccountValidStatus);
        }

        function setAccountType($type = 'main')
        {
            $this->AccountType = $type;
            return true;
        }

        function getAccountType()
        {
            return ($this->AccountType);
        }

        function setAccountLimited($limited = false)
        {
            $this->AccountLimited = $limited;
            return true;
        }

        function getAccountLimited()
        {
            return $this->AccountLimited;
        }

        function setAccountStatusMessage($message = '')
        {
            $this->AccountStatusMessage = $message;
            return true;
        }

        function getAccountStatusMessage()
        {
            return $this->AccountStatusMessage;
        }

        function setAccountLowCredits($status = false)
        {
            $this->AccountLowCredits = $status;
            return true;
        }

        function getAccountLowCredits()
        {
            if (isset($this->AccountLowCredits)) {
                return $this->AccountLowCredits;
            }
            return null;
        }

        function setAccountNoCredits($status = false)
        {
            $this->AccountNoCredits = $status;
            return true;
        }

        function getAccountNoCredits()
        {
            if (isset($this->AccountNoCredits)) {
                return $this->AccountNoCredits;
            }
            return null;
        }

        function setAccountBalance($key = '', $value = '')
        {
            if (strlen($key) > 0 && strlen($value) > 0) {
                $this->AccountBalance[$key] = $value;
            }
            return true;
        }

        function getAccountBalance($which = '')
        {
            if (isset ($this->AccountBalance[$which]))
                return $this->AccountBalance[$which];
            else
                return '';
        }

        function setFromOption($data = array())
        {
            array_push($this->FromOptions, $data);
            return true;
        }

        function getFromOption()
        {
            return $this->FromOptions;
        }

        function getFromForGUI()
        {
            $settingsfrom = get_option($this->plugin_slug . '-from');
            if (!empty($settingsfrom))
                $this->setFromOption($settingsfrom);

            // getting list of numbers allocated to 46elks account
            $this->getAccountFromNumbers();

            return true;
        }

        function triggerAlertEmail()
        {
            if (get_option($this->plugin_slug . '-balancealert-sent') != 'true') {
                $balance_alert_email = get_option($this->plugin_slug . '-balancealert-email');
                $balance_alert_phone = get_option($this->plugin_slug . '-balancealert-phone-number');
                $to = get_option('admin_email');
                if (!empty($balance_alert_email)) {
                    $to = $balance_alert_email;
                }
                $subject = '[' . get_option('siteurl') . '] Send SMS with 46elks for Contact Form 7 - low on credits';
                $body = 'Hello admin for ' . get_option('siteurl') . '!<br /><br />Your account has a balance of: ' . $this->convertBalanceValue($this->getAccountBalance('leftcred')) . ' ' . $this->getAccountBalance('currency') . '<br />Add more credits or you might run out of option to send SMS to users.<br /><b< />/ Send SMS with 46elks for Contact Form 7';
                $headers = array('Content-Type: text/html; charset=UTF-8');
                wp_mail($to, $subject, $body, $headers);
                if (!empty($balance_alert_phone)) {
                    $settingsfrom = get_option($this->plugin_slug . '-cf7-from');
                    if ($settingsfrom == 'Sender ID') {
                        $settingsfrom = get_option($this->plugin_slug . '-cf7-sender-id');
                    }
                    $sms = array(
                        'from' => $settingsfrom,
                        'to' => $balance_alert_phone,
                        'message' => 'Your account has a balance of: ' . $this->convertBalanceValue($this->getAccountBalance('leftcred')) . ' ' . $this->getAccountBalance('currency') . 'Add more credits or you might run out of option to send SMS to users.'
                    );
                    if (!empty($settingsfrom)) {
                        $this->sendSMS($sms);
                    }
                }


                update_option($this->plugin_slug . '-balancealert-sent', 'true');
                return true;
            } else
                return false;
        }

        function getAccountFromNumbers()
        {
            // creating WP_remote_post and performing sending
            $sms = $this->generateSMSbasics();
            $this->response = wp_remote_get(
                $this->API_uri . '/Numbers',
                $sms
            );

            $data = $this->handleResponse($this->response);
            $data['body'] = json_decode($data['servermsg']['body']);

            if (!empty ($data['body']->data)) {
                foreach ($data['body']->data as $key => $value) {
                    if ($value->active === 'yes')
                        $this->setFromOption($value->number);
                }
            }
        }


        function wpsms46elks_account_status()
        {
            if ($this->getAccountValidStatus()) {
                ?>
                <p>
                    <?php _e('46elks account name', 'send-sms-with-46elks-for-contact-form-7'); ?>:<br/>
                    <b><?php echo esc_html($this->getAccountBalance('name')); ?></b>
                </p>
                <p>
                    <?php _e('46elks credits left', 'send-sms-with-46elks-for-contact-form-7'); ?>:<br/>
                    <b>
                        <?php
                        if ($this->getAccountLimited())
                            echo esc_html($this->convertBalanceValue($this->getAccountBalance('leftcred'))) . ' ' . esc_html($this->getAccountBalance('currency'));
                        else
                            _e('unavailable', 'send-sms-with-46elks-for-contact-form-7');
                        ?></b>
                </p>
                <?php
            } else {
                ?>
                <p>
                    <b><?php _e('46elks credentials wrong or missing', 'send-sms-with-46elks-for-contact-form-7'); ?>.</b>
                </p>
                <?php
            }
        }

        function wpsms46elks_subaccount_balance()
        {
            // creating WP_remote_post and performing sending
            $sms = $this->generateSMSbasics();
            $this->response = wp_remote_get(
                $this->API_uri . '/subaccounts',
                $sms
            );

            $data = $this->handleResponse($this->response);
            $data['body'] = json_decode($data['servermsg']['body']);

            if (isset($data['body']->data) && $data['servermsg']['code'] == 200) {
                ?>
                <hr/>
                <br/>
                <h4><?php _e('All 46elks subaccounts', 'send-sms-with-46elks-for-contact-form-7'); ?></h4>
                <?php
                foreach ($data['body']->data as $key => $value) {
                    ?>
                    <p>
                        <b><?php echo esc_html($value->name); ?></b><br/>
                        <?php
                        _e('Credits used', 'send-sms-with-46elks-for-contact-form-7'); ?>
                        : <?php echo esc_html(($this->convertBalanceValue($value->balanceused)));
                        echo ' sek<br />';

                        if (isset($value->usagelimit)) {
                            _e('Account limit', 'send-sms-with-46elks-for-contact-form-7'); ?>: <?php echo esc_html($this->convertBalanceValue($value->usagelimit));
                            echo ' sek<br />';
                            _e('Credits left', 'send-sms-with-46elks-for-contact-form-7'); ?>: <?php echo esc_html(($this->convertBalanceValue(($value->usagelimit - $value->balanceused))));
                            echo ' sek<br />';
                            _e('Percentage usage', 'send-sms-with-46elks-for-contact-form-7'); ?>: <?php echo esc_html(($value->balanceused / $value->usagelimit * 100));
                            echo '%';
                        } else {
                            _e('Account limit', 'send-sms-with-46elks-for-contact-form-7'); ?>:
                            <i><?php _e('unlimited', 'send-sms-with-46elks-for-contact-form-7'); ?></i><?php
                        }
                        ?>
                    </p>
                    <?php
                }
            }
        }

        // function to make value more readable
        function convertBalanceValue($value = 0)
        {
            return ($value / $this->credMultiply);
        }

        // function that displays the whole WP-admin GUI
        function wpsms46elks_gui()
        {
            $this->getFromForGUI();
            ?>
            <div class="wrap">

                <h2><?php _e('Send SMS with 46elks for Contact Form 7', 'send-sms-with-46elks-for-contact-form-7'); ?></h2>

                <?php

                if ($this->getAccountLowCredits()) {
                    ?>
                    <div class="notice notice-warning">
                        <p>
                            <b><?php _e('Few credits left', 'send-sms-with-46elks-for-contact-form-7'); ?>!</b><br/>
                            <?php _e('There are only a few credits left', 'send-sms-with-46elks-for-contact-form-7'); ?>
                            : <?php echo esc_html($this->convertBalanceValue($this->getAccountBalance('leftcred')));
                            echo " " . esc_html($this->getAccountBalance('currency')) ?><br/>
                        </p>
                    </div>
                    <?php
                }
                if ($this->getAccountNoCredits()) {
                    ?>
                    <div class="notice notice-error">
                        <p>
                            <b><?php _e('No credits left', 'send-sms-with-46elks-for-contact-form-7'); ?>!</b><br/>
                            <?php _e('There are not enought credits left to perform sending of SMS to all receivers', 'send-sms-with-46elks-for-contact-form-7'); ?>
                        </p>
                    </div>
                    <?php
                }

                // print success message
                if (!empty($this->status['success'])) {
                    ?>
                    <div class="notice notice-success">
                    <p><?php echo esc_html($this->status['success']); ?></p>
                    </div><?php
                }
                // print error message
                if (!empty($this->status['failed'])) {
                    ?>
                    <div class="notice notice-error">
                    <p><?php echo esc_html(($this->status['failed'])); ?></p>
                    </div><?php
                }
                ?>

                <div id="poststuff">
                    <div id="post-body" class="metabox-holder columns-2">
                        <div id="postbox-container-1" class="postbox-container">

                            <div class="meta-box-sortables">
                                <div class="postbox ">
                                    <h3 class="hndle" style="cursor: inherit;">
                                        <span><?php _e('Account status', 'send-sms-with-46elks-for-contact-form-7'); ?></span></h3>
                                    <div class="inside">
                                        <div id="misc-publishing-actions">
                                            <?php
                                            $this->wpsms46elks_account_status();
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="meta-box-sortables">
                                <div class="postbox ">
                                    <h3 class="hndle" style="cursor: inherit;">
                                        <span><?php _e('Current receivers', 'send-sms-with-46elks-for-contact-form-7'); ?></span></h3>
                                    <div class="inside">
                                        <div id="misc-publishing-actions">
                                            <p>
                                                <?php
                                                _e('Current amount of receivers', 'send-sms-with-46elks-for-contact-form-7');
                                                echo ': ' . esc_html($this->totalReceivers);
                                                ?>
                                            </p>
                                            <p>
                                                <?php
                                                if ($this->totalReceivers > 0) {
                                                    foreach ($this->getReceivers() as $key => $value) {
                                                        foreach ($value as $cellphone => $name) {
                                                            echo esc_html($name); ?> <i><?php echo esc_html($cellphone); ?></i><br/><?php
                                                        }
                                                    }
                                                }
                                                ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php
                            if (is_super_admin()) {
                                ?>
                                <div class="meta-box-sortables">
                                    <div class="postbox ">
                                        <h3 class="hndle" style="cursor: inherit;">
                                            <span><?php _e('Cost history', 'send-sms-with-46elks-for-contact-form-7'); ?></span></h3>
                                        <div class="inside">
                                            <div id="misc-publishing-actions">
                                                <p>
                                                    <?php
                                                    $this->getCostHistory();
                                                    ?>
                                                </p>
                                            </div>
                                        </div>
                                        <h3 class="hndle" style="cursor: inherit;">
                                            <span><a href='https://46elks.com/tutorials/cf7-wp-plugin'><?php _e('FAQ', 'send-sms-with-46elks-for-contact-form-7'); ?></a></span></h3>
                                    </div>
                                </div>
                                <?php
                            }
                            ?>

                        </div>
                        <div id="postbox-container-2" class="postbox-container">
                            <?php
                            if (is_super_admin() && $this->getAccountValidStatus()) {
                                ?>
                                <div class="meta-box-sortables">
                                    <div class="postbox ">
                                        <h3 class="hndle" style="cursor: inherit;">
                                            <span><?php _e('Settings', 'send-sms-with-46elks-for-contact-form-7'); ?></span></h3>
                                        <?php
                                        if (!is_plugin_active('contact-form-7/wp-contact-form-7.php')) { ?>
                                            <h3 class="hndle" style="cursor: inherit;">
                                                <span><?php _e('Please install/activate the ', 'send-sms-with-46elks-for-contact-form-7'); ?> <a
                                                            href="https://wordpress.org/plugins/contact-form-7"><?php _e('Contact Form 7', 'send-sms-with-46elks-for-contact-form-7'); ?></a> <?php _e(' plugin before using the "Send SMS with 46elks for Contact Form 7" plugin', 'send-sms-with-46elks-for-contact-form-7'); ?></span>
                                            </h3>
                                        <?php } else {
                                            ?>
                                            <div class="inside">
                                                <form method="POST" action="options.php">
                                                    <?php
                                                    settings_fields($this->plugin_slug . '-settings');
                                                    do_settings_sections($this->plugin_slug . '-settings');
                                                    ?>
                                                    <table class="form-table">
                                                        <tbody>
                                                        <?php
                                                        if ($this->getAccountLimited()) {
                                                            $args = array('post_type' => 'wpcf7_contact_form', 'posts_per_page' => -1);
                                                            $cf7Forms = get_posts($args);
                                                            $from = wp_list_pluck($cf7Forms, 'post_title', 'ID');
                                                            ?>
                                                            <tr>
                                                                <th>
                                                                    <label for="wp-sms-46elks-cf7-form-name"><?php _e('Choose from CF7 contact forms', 'send-sms-with-46elks-for-contact-form-7'); ?></label>
                                                                </th>
                                                                <td>
                                                                    <select id="wp-sms-46elks-cf7-form-name"
                                                                            name="wp-sms-46elks-cf7-form-name">
                                                                        <?php

                                                                        foreach ($from as $key => $value) {
                                                                            $concat_value = $value . ' ID: (' . $key . ')';
                                                                            if ($concat_value != get_option($this->plugin_slug . '-cf7-form-name')) {
                                                                                ?>
                                                                                <option
                                                                                value="<?php echo esc_html($concat_value); ?>" ><?php echo esc_html($concat_value); ?></option><?php
                                                                            }
                                                                        }
                                                                        ?>
                                                                        <option selected="selected"
                                                                                value="<?php echo esc_html(get_option($this->plugin_slug . '-cf7-form-name')); ?>">
                                                                            <?php echo esc_html(get_option($this->plugin_slug . '-cf7-form-name')); ?>
                                                                        </option>
                                                                    </select>
                                                            </tr>
                                                            <tr>
                                                                <div id="tags" >


                                                                    <?php

                                                                    foreach ($from as $key => $value) {
                                                                        $concat_value = $value . ' ID: (' . $key . ')';
                                                                        $ContactForm = WPCF7_ContactForm::get_instance( $key );
                                                                        $form_fields = $ContactForm->scan_form_tags();
                                                                        $tag_list = "Tag list of the Contact form you had chosen: ";
                                                                        foreach ($form_fields as $single_tag) {
                                                                            $tag_list = $tag_list . $single_tag->raw_name . " ";
                                                                        }

                                                                        ?>

                                                                        <p1 id ="<?php echo esc_html($key) ?>" <?php echo ($concat_value != get_option($this->plugin_slug . '-cf7-form-name')) ? 'style="display: none"' : ''; ?>><?php echo esc_html($tag_list); ?></p1>
                                                                        <?php
                                                                    }
                                                                    ?>
                                                                </div>
                                                            </tr>
                                                            <tr>
                                                                <th>
                                                                    <label for="wp-sms-46elks-cf7-from"><?php _e('Send SMS from:', 'send-sms-with-46elks-for-contact-form-7'); ?></label>
                                                                </th>
                                                                <td>
                                                                    <select id="wp-sms-46elks-cf7-from"
                                                                            name="wp-sms-46elks-cf7-from">
                                                                        <?php
                                                                        $from = $this->getFromOption();
                                                                        foreach ($from as $key => $value) {
                                                                            if ($value != get_option($this->plugin_slug . '-cf7-from')) {
                                                                                ?>
                                                                                <option
                                                                                value="<?php echo esc_html($value); ?>" ><?php echo esc_html($value); ?></option><?php
                                                                            }
                                                                        }

                                                                        if (get_option($this->plugin_slug . '-cf7-from') != null) {
                                                                            ?>

                                                                            <option selected="selected"
                                                                                    value="<?php echo esc_html(get_option($this->plugin_slug . '-cf7-from')); ?>">
                                                                                <?php echo esc_html(get_option($this->plugin_slug . '-cf7-from')); ?>
                                                                            </option>
                                                                        <?php } ?>
                                                                        <?php if (get_option($this->plugin_slug . '-cf7-from') != "Sender ID") { ?>
                                                                            <option value="Sender ID">Sender ID</option>
                                                                        <?php } ?>
                                                                        <?php if (get_option($this->plugin_slug . '-cf7-from') == null) { ?>
                                                                            <option <?php echo 'selected="selected"' ?>
                                                                                    value="">--Choose--
                                                                            </option>
                                                                        <?php } ?>
                                                                    </select>
                                                            </tr>
                                                            <tr id="sender-id-tr" <?php if (get_option($this->plugin_slug . '-cf7-from') != "Sender ID") echo 'style="visibility:collapse"'; ?>>
                                                                <th>
                                                                    <label for="wp-sms-46elks-cf7-sender-id"
                                                                           id="input-sender-id"><?php _e('Sender ID:', 'send-sms-with-46elks-for-contact-form-7'); ?></label>
                                                                </th>
                                                                <td>
                                                                    <input type="text"
                                                                           name="wp-sms-46elks-cf7-sender-id"
                                                                           id="wp-sms-46elks-cf7-sender-id"
                                                                           value="<?php echo esc_html(get_option($this->plugin_slug . '-cf7-sender-id')); ?>"
                                                                           class="regular-text"
                                                                           maxlength="11">
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <th>
                                                                    <label for="wp-sms-46elks-cf7-send-to"><?php _e('Send SMS to:', 'send-sms-with-46elks-for-contact-form-7'); ?></label>
                                                                </th>
                                                                <td><input type="text"
                                                                           name="wp-sms-46elks-cf7-send-to"
                                                                           id="wp-sms-46elks-cf7-send-to"
                                                                           placeholder="+46 123 456,+46 789 101,+46 987 654"
                                                                           value="<?php echo esc_html(get_option($this->plugin_slug . '-cf7-send-to')); ?>"
                                                                           class="regular-text"></td>
                                                            </tr>
                                                            <tr>
                                                                <th>
                                                                    <label for="wp-sms-46elks-cf7-sms-content"><?php _e('SMS message content', 'send-sms-with-46elks-for-contact-form-7'); ?></label>
                                                                </th>
                                                                <td><textarea id="wp-sms-46elks-cf7-sms-content"
                                                                              name="wp-sms-46elks-cf7-sms-content"
                                                                              placeholder="Your name [your-name]. Your email [your-email]. Subject [your-subject].
                                                                          Message: [your-message]."
                                                                              rows="5"
                                                                              cols="30"><?php echo esc_html(get_option($this->plugin_slug . '-cf7-sms-content')); ?></textarea>
                                                                </td>
                                                            </tr>
                                                            <?php
                                                        }
                                                        ?>
                                                        <hr/>
                                                        <tr>
                                                            <th>
                                                                <h3 class="hndle"
                                                                    style="cursor: inherit; padding: 8px 0px;">
                                                                    <span><?php _e('Balance alert settings', 'send-sms-with-46elks-for-contact-form-7'); ?></span>
                                                                </h3>
                                                            </th>
                                                        </tr>
                                                        <tr>
                                                            <th>
                                                                <label for="wp-sms-46elks-balancealert-email"><?php _e('Send balance alert e-mail to', 'send-sms-with-46elks-for-contact-form-7'); ?></label>
                                                            </th>
                                                            <td>
                                                                <input type="text"
                                                                       name="wp-sms-46elks-balancealert-email"
                                                                       id="wp-sms-46elks-balancealert-email"
                                                                       value="<?php echo esc_html(get_option($this->plugin_slug . '-balancealert-email')); ?>"
                                                                       class="regular-text"/>
                                                                <p class="description"><?php _e('Enter an email to get notification on low credits.', 'send-sms-with-46elks-for-contact-form-7'); ?></p>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <th>
                                                                <label for="wp-sms-46elks-balancealert-phone-number"><?php _e('Send balance alert SMS to', 'send-sms-with-46elks-for-contact-form-7'); ?></label>
                                                            </th>
                                                            <td>
                                                                <input type="text"
                                                                       name="wp-sms-46elks-balancealert-phone-number"
                                                                       id="wp-sms-46elks-balancealert-phone-number"
                                                                       value="<?php echo esc_html(get_option($this->plugin_slug . '-balancealert-phone-number')); ?>"
                                                                       class="regular-text"/>
                                                                <p class="description"><?php _e('Enter a phone number to get notification on low credits.', 'send-sms-with-46elks-for-contact-form-7'); ?></p>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <th>
                                                                <label for="wp-sms-46elks-balancealert"><?php _e('Balance alert value', 'send-sms-with-46elks-for-contact-form-7'); ?></label>
                                                            </th>
                                                            <td>
                                                                <input type="text" name="wp-sms-46elks-balancealert"
                                                                       id="wp-sms-46elks-balancealert"
                                                                       value="<?php echo esc_html(get_option($this->plugin_slug . '-balancealert')); ?>"
                                                                       class="regular-text"/>
                                                                <p class="description"><?php _e('If you cross the balance alert value + 10%, then you get sms + mail about it. If you have a balance alert value or less then it will not send SMS anymore.', 'send-sms-with-46elks-for-contact-form-7'); ?></p>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <th>&nbsp;</th>
                                                            <td>
                                                                <?php submit_button(__('Save', 'send-sms-with-46elks-for-contact-form-7'), 'primary', 'wp-sms-46elks' . '-submit-settings', true, array('disabled' => 'disabled')); ?>
                                                            </td>
                                                        </tr>
                                                        </tbody>
                                                    </table>
                                                </form>
                                            </div>
                                        <?php } ?>
                                    </div>
                                </div>
                                <?php
                            }
                            ?>
                        </div>
                        <div id="postbox-container-2" class="postbox-container">
                            <?php
                            if (is_super_admin()) {
                                ?>
                                <div class="meta-box-sortables">
                                    <div class="postbox ">
                                        <h3 class="hndle" style="cursor: inherit;">
                                            <span><?php _e('Credential settings', 'send-sms-with-46elks-for-contact-form-7'); ?></span></h3>
                                        <?php
                                        if (!$this->getAccountLimited()) { ?>
                                            <h3 class="hndle" style="cursor: inherit;">
                                                        <span><?php _e('For this plugin to work properly you have to have ', 'send-sms-with-46elks-for-contact-form-7'); ?> <a
                                                                    href="https://46elks.com/register/wpplugin"><?php _e('46elks account', 'send-sms-with-46elks-for-contact-form-7'); ?></a> <?php _e(' with balance on it and you have to install and activate the ', 'send-sms-with-46elks-for-contact-form-7'); ?> <a
                                                                    href="https://wordpress.org/plugins/contact-form-7"><?php _e('Contact Form 7', 'send-sms-with-46elks-for-contact-form-7'); ?></a> <?php _e('  plugin!', 'send-sms-with-46elks-for-contact-form-7'); ?></span>
                                            </h3>
                                            <h3 class="hndle" style="cursor: inherit;">
                                                <span><a href='https://46elks.com/tutorials/cf7-wp-plugin'><?php _e('FAQ', 'send-sms-with-46elks-for-contact-form-7'); ?></a></span></h3>
                                        <?php }
                                        ?>
                                        <div class="inside">
                                            <form method="POST" action="options.php">
                                                <?php
                                                settings_fields($this->plugin_slug . '-credentials');
                                                do_settings_sections($this->plugin_slug . '-credentials');
                                                ?>
                                                <table class="form-table">
                                                    <tbody>
                                                    <tr>
                                                        <th>
                                                            <label for="wp-sms-46elks-api-username"><?php _e('Your API username', 'send-sms-with-46elks-for-contact-form-7'); ?></label>
                                                        </th>
                                                        <td><input type="text" name="wp-sms-46elks-api-username"
                                                                   id="wp-sms-46elks-api-username"
                                                                   value="<?php echo esc_html(get_option($this->plugin_slug . '-api-username')); ?>"
                                                                   class="regular-text"></td>
                                                    </tr>
                                                    <tr>
                                                        <th>
                                                            <label for="wp-sms-46elks-api-password"><?php _e('Your API password', 'send-sms-with-46elks-for-contact-form-7'); ?></label>
                                                        </th>
                                                        <td><input type="password" name="wp-sms-46elks-api-password"
                                                                   id="wp-sms-46elks-api-password"
                                                                   value="<?php echo esc_html(get_option($this->plugin_slug . '-api-password')); ?>"
                                                                   class="regular-text"></td>
                                                    </tr>
                                                    <tr>
                                                        <th>&nbsp;</th>
                                                        <td>
                                                            <?php submit_button(); ?>
                                                        </td>
                                                    </tr>
                                                    </tbody>
                                                </table>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php
                            }
                            ?>
                        </div>

                        <?php // CURRENTHERE
                        if ($this->getAccountValidStatus() && !$this->getAccountNoCredits()) {
                            ?>
                            <div id="post-body-content" style="position: relative;">
                                <div id="wp-sms-46elks-new-container" class="postbox-container" style="width: 100%;">
                                    <div class="postbox " id="wp-sms-46elks-new">
                                        <h3 class="hndle" style="cursor: inherit;">
                                            <span><?php _e('Send Test SMS', 'send-sms-with-46elks-for-contact-form-7'); ?></span></h3>
                                        <div class="inside">
                                            <style type="text/css">
                                                @media screen and (min-width: 783px) {
                                                    .form-table td textarea, .form-table td select {
                                                        width: 25em;
                                                    }
                                                }
                                            </style>

                                            <form method="POST">
                                                <table class="form-table">
                                                    <tbody>
                                                    <tr>
                                                        <th>
                                                            <label for="wp-sms-46elks-from"><?php _e('From', 'send-sms-with-46elks-for-contact-form-7'); ?></label>
                                                        </th>
                                                        <td>
                                                            <select id="wp-sms-46elks-from" name="wp-sms-46elks-from">
                                                                <?php
                                                                $from = $this->getFromOption();
                                                                if (!empty ($from)) {
                                                                    foreach ($from as $key => $value) {
                                                                        ?>
                                                                        <option
                                                                        value="<?php echo esc_html($value); ?>" ><?php echo esc_html($value); ?></option><?php
                                                                    }?>
                                                                    <option value="Sender ID">Sender ID</option>
                                                              <?php } else {
                                                                    ?>
                                                                    <option
                                                                    value="<?php echo esc_html(trim(substr($this->getAccountBalance('name'), 0, 11))); ?>" ><?php echo esc_html(trim(substr($this->getAccountBalance('name'), 0, 11))); ?></option><?php
                                                                }
                                                                ?>
                                                            </select>
                                                            <p class="description">
                                                                <?php _e("Send your SMS from phone number you bought from 46elks or if don't have one define a Sender ID (Alphanumeric of up to 11 char that [A-Z],[a-z],[0-9]).", 'send-sms-with-46elks-for-contact-form-7'); ?>
                                                            </p>
                                                    </tr>
                                                    <tr id="sender-id-tr-test" <?php if (get_option($this->plugin_slug . '-cf7-from') != "Sender ID") echo 'style="visibility:collapse"'; ?>>
                                                        <th>
                                                            <label for="wp-sms-46elks-cf7-sender-id-test"
                                                                   id="input-sender-id"><?php _e('Sender ID:', 'send-sms-with-46elks-for-contact-form-7'); ?></label>
                                                        </th>
                                                        <td>
                                                            <input type="text"
                                                                   name="wp-sms-46elks-cf7-sender-id-test"
                                                                   id="wp-sms-46elks-cf7-sender-id-test"
                                                                   value="<?php echo esc_html(get_option($this->plugin_slug . '-cf7-sender-id-test')); ?>"
                                                                   class="regular-text"
                                                                   maxlength="11">
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <th>
                                                            <label for="wp-sms-46elks-message"><?php _e('Message content', 'send-sms-with-46elks-for-contact-form-7'); ?></label>
                                                        </th>
                                                        <td><textarea id="wp-sms-46elks-message"
                                                                      name="wp-sms-46elks-message"
                                                                      maxlength="160"
                                                                      placeholder="<?php _e('Write your SMS text here..', 'send-sms-with-46elks-for-contact-form-7'); ?>"
                                                                      rows="5" cols="30"></textarea>
                                                            <p class="wp-sms-46elks-message-description">
                                                                <span id="wp-sms-46elks-message-used-chars">Max 160 characters</span>
                                                            </p>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <th>
                                                            <label for="wp-sms-46elks-send-to"><?php _e('Send to: ', 'send-sms-with-46elks-for-contact-form-7'); ?></label>
                                                        </th>
                                                        <td>
                                                            <input type="text" id="wp-sms-46elks-send-to"
                                                                   name="wp-sms-46elks-send-to"
                                                                   placeholder="<?php _e('Send SMS to', 'send-sms-with-46elks-for-contact-form-7'); ?>"
                                                                   class="regular-text"
                                                                   maxlength="<?php $this->frommaxlength; ?>"/>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <th>&nbsp;</th>
                                                        <td>
                                                            <?php submit_button(__('Send SMS', 'send-sms-with-46elks-for-contact-form-7'), 'primary', 'wp-sms-46elks' . '-submit', true, array('disabled' => 'disabled')); ?>
                                                        </td>
                                                    </tr>
                                                    </tbody>
                                                </table>
                                            </form>
                                        </div><!-- div inside -->
                                    </div><!-- div wp-sms-46elks-new -->
                                </div><!-- div wp-sms-46elks-new-container -->
                            </div>
                            <?php
                        }
                        ?>

                        <?php
                        // Debug stuff to print various output
                        if (is_super_admin() && $this->debug) {
                            ?>
                            <hr/>
                            <h4><?php _e('Debug $this', 'send-sms-with-46elks-for-contact-form-7'); ?></h4>
                            <pre>
                                <?php print_r($this); ?>
                            </pre>
                            <?php
                        }
                        ?>
                    </div>
                </div>
                <script type="text/javascript">
                    jQuery(document).ready(function () {
                        smslengthdefault = 160;
                        smslengthspecial = 70;

                        jQuery('#wp-sms-46elks-send-to').smsCharCount({
                            onUpdate: function (data) {
                                jQuery('#wp-sms-46elks-submit').attr("disabled", false);

                                if (data.charRemaining === 0 && data.messageCount === 0) {
                                    jQuery('#wp-sms-46elks-submit').attr("disabled", true);
                                }
                            }
                        });

                        jQuery('#wp-sms-46elks-cf7-sms-content').smsCharCount({
                            onUpdate: function (data) {
                                jQuery('#wp-sms-46elks-submit-settings').attr("disabled", false);

                                if (data.charRemaining === 0 && data.messageCount === 0) {
                                    jQuery('#wp-sms-46elks-submit-settings').attr("disabled", true);
                                }
                            }
                        });
                    });

                    jQuery('#wp-sms-46elks-cf7-form-name').change(function(){
                        var str = jQuery( this ).val();
                        var mySubString = str.substring(
                            str.indexOf("(") + 1,
                            str.lastIndexOf(")")
                        );
                        jQuery('#tags').children('*').css('display', 'none');
                        jQuery('#' + mySubString).css('display', 'inline-block');
                    });


                    var ddl = document.getElementById("wp-sms-46elks-cf7-from");
                    ddl.onchange = newFrom;

                    function newFrom() {
                        var ddl = document.getElementById("wp-sms-46elks-cf7-from");
                        var selectedValue = ddl.options[ddl.selectedIndex].value;


                        if (selectedValue == "Sender ID") {
                            document.getElementById("sender-id-tr").style.visibility = "visible";
                        } else {
                            document.getElementById("sender-id-tr").style.visibility = "collapse";
                        }
                    }

                    var ddl_test = document.getElementById("wp-sms-46elks-from");
                    ddl_test.onchange = newFrom_testSMS;

                    function newFrom_testSMS() {
                        var ddl_test = document.getElementById("wp-sms-46elks-from");
                        var selectedValue_test = ddl_test.options[ddl_test.selectedIndex].value;


                        if (selectedValue_test == "Sender ID") {
                            document.getElementById("sender-id-tr-test").style.visibility = "visible";
                        } else {
                            document.getElementById("sender-id-tr-test").style.visibility = "collapse";
                        }
                    }

                </script>
            </div><!-- div wrap -->
            <?php
        }

        function getReceivers(): array
        {
            return $this->receivers;
        }

        function addReceiver($data): bool
        {
            // Add receiver to receiver list
            $this->receivers[] = $data;
            return true;
        }

        function getCostHistoryData()
        {
            // creating WP_remote_post and performing sending
            $sms = $this->generateSMSbasics();
            $this->response = wp_remote_get(
                $this->API_uri . '/SMS',
                $sms
            );

            $data = $this->handleResponse($this->response);
            $data['body'] = json_decode($data['servermsg']['body']);

            if ($data['servermsg']['code'] === 200) {
                $list = $data['body']->data;

                // Loop until all SMS are receaved or max 10 000 messages.
                $max = 100;
                while (true) {
                    if (isset($data['body']->next)) {
                        if ($max < 0) {
                            break;
                        }

                        $max = $max - 1;

                        // creating WP_remote_post and performing sending
                        $sms = $this->generateSMSbasics();
                        $this->response = wp_remote_get(
                            $this->API_uri . '/SMS?start=' . $data['body']->next,
                            $sms
                        );

                        $data = $this->handleResponse($this->response);
                        $data['body'] = json_decode($data['servermsg']['body']);

                        if ($data['servermsg']['code'] === 200) {
                            $list = array_merge($list, $data['body']->data);
                        } else {
                            break;
                        }
                    } else
                        break;
                }
                return $list;
            } else
                return false;
        }

        function getCostHistory()
        {
            $list = $this->getCostHistoryData();
            if ($list != false) {
                $costOfMonth = array();
                $numberOfMonth = array();

                // Read all items.
                foreach ($list as $sms) {
                    if (isset($sms->cost)) {
                        $month = substr($sms->created, 0, 7);
                        $numStart = substr($sms->to, 0, 4);

                        if (isset($costOfMonth[$month]) === false)
                            $costOfMonth[$month] = 0;
                        $costOfMonth[$month] = $costOfMonth[$month] + $sms->cost;

                        if (isset($numberOfMonth[$month]) === false)
                            $numberOfMonth[$month] = array();
                        if (isset($numberOfMonth[$month][$numStart]) === false)
                            $numberOfMonth[$month][$numStart] = 0;
                        $numberOfMonth[$month][$numStart] = $numberOfMonth[$month][$numStart] + 1;
                    }
                }

                if (is_super_admin()) {
                    ?>
                    <table style="width: 100%;">
                        <thead>
                        <tr>
                            <th><?php _e('Month', 'send-sms-with-46elks-for-contact-form-7'); ?></th>
                            <th><?php _e('Cost', 'send-sms-with-46elks-for-contact-form-7'); ?></th>
                            <th><?php _e('Destination', 'send-sms-with-46elks-for-contact-form-7'); ?></th>
                            <th><?php _e('SMS', 'send-sms-with-46elks-for-contact-form-7'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php
                        // Print the data to the user:
                        foreach ($costOfMonth as $month => $cost) {
                            $i = 0;
                            $numbers = '';
                            $amounts = '';

                            foreach ($numberOfMonth[$month] as $number => $amount) {
                                ++$i;
                                if ($i != 1)
                                    $linebreak = '<br />';
                                else
                                    $linebreak = '';

                                $numbers .= $number . $linebreak;
                                $amounts .= $amount . $linebreak;
                            }

                            ?>
                            <tr>
                                <td><?php echo esc_html($month); ?></td>
                                <td><?php echo esc_html($this->convertBalanceValue($cost)); ?></td>
                                <td><?php echo esc_html($numbers); ?></td>
                                <td><?php echo esc_html($amounts); ?></td>
                            </tr>
                            <?php
                        }
                        ?>
                        </tbody>
                    </table>
                    <?php
                }
            } else {
                _e('No SMS sent yet so there is no history available.', 'send-sms-with-46elks-for-contact-form-7');
            }
        }

        function generateArgsUserQuery(): array
        {
            $return = array(
                'meta_query' => array(
                    array(
                        'key' => $this->cellphone_slug,
                        'compare' => '!=',
                        'value' => ''
                    ),
                ),
                'orderby' => 'first_name, last_name',
                'fields' => array('ID', 'display_name'),
                'order' => 'ASC'
            );

            return $return;
        }

        function addReceiversFromWP(): bool
        {
            $users = get_users($this->generateArgsUserQuery());
            if (!empty ($users)) {
                foreach ($users as $user) {
                    $cellphone = get_user_meta($user->ID, $this->cellphone_slug, true);
                    $cellphone = $this->convertToInternational($cellphone);
                    $this->addReceiver(array($cellphone => $user->display_name));
                }
            }
            return true;
        }

        function convertToInternational($number)
        {
            $number = str_replace('-', '', str_replace(' ', '', $number));
            $number = preg_replace('/^00/', '+', $number);
            // FIXME add option to set default country code ( $this->plugin_slug.'-default-countrycode' )
            $number = preg_replace('/^0/', '+46', $number);
            return $number;
        }

        function addMessage($message): bool
        {
            $this->sms['message'] = $message;
            return true;
        }

        function addSendTo($send_to): bool
        {
            $this->sms['send_to'] = $send_to;
            return true;
        }

        function addFrom($from, $senderID): bool
        {
            if ($from == 'Sender ID') {
                $this->sms['from'] = $senderID;
            } else {
                $this->sms['from'] = $from;
            }

            return true;
        }

        function generateSMSbasics(): array
        {
            $data = array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode(get_option($this->plugin_slug . '-api-username') . ':' . get_option($this->plugin_slug . '-api-password')),
                    'Content-type' => 'application/x-www-form-urlencoded'
                )
            );
            return $data;
        }

        function sendSMS($sms)
        {
            $headers = array(
                'Authorization' => 'Basic ' . base64_encode(get_option($this->plugin_slug . '-api-username') . ':' . get_option($this->plugin_slug . '-api-password'))
            );

            $url = "https://api.46elks.com/a1/sms";

            $response = wp_remote_post($url, array(
                'body' => $sms,
                'headers' => $headers
            ));

            return $response;
        }

        function wpsms46elks_user_contactmethods($profile_fields)
        {
            // Add new fields
            $profile_fields[$this->cellphone_slug] = 'Cellphone';
            return $profile_fields;
        }


        function wpsms46elks_admin_menu()
        {
            add_menu_page(__('Send SMS with 46elks for Contact Form 7', 'send-sms-with-46elks-for-contact-form-7'), __('SMS via 46elks', 'send-sms-with-46elks-for-contact-form-7'), 'publish_pages', 'send-sms-with-46elks-for-contact-form-7', array($this, 'wpsms46elks_gui'), 'dashicons-testimonial', '3.98765');
        }

        function wpsms46elks_admin_init()
        {
            register_setting($this->plugin_slug . '-settings', $this->plugin_slug . '-from');
            register_setting($this->plugin_slug . '-settings', $this->plugin_slug . '-default-countrycode');
            register_setting($this->plugin_slug . '-settings', $this->plugin_slug . '-balancealert');
            register_setting($this->plugin_slug . '-settings', $this->plugin_slug . '-balancealert-email');
            register_setting($this->plugin_slug . '-settings', $this->plugin_slug . '-balancealert-phone-number');
            register_setting($this->plugin_slug . '-settings-balance-alert', $this->plugin_slug . '-balancealert-sent');
            register_setting($this->plugin_slug . '-credentials', $this->plugin_slug . '-api-username');
            register_setting($this->plugin_slug . '-credentials', $this->plugin_slug . '-api-password');
            register_setting($this->plugin_slug . '-settings', $this->plugin_slug . '-cf7-form-name');
            register_setting($this->plugin_slug . '-settings', $this->plugin_slug . '-cf7-send-to');
            register_setting($this->plugin_slug . '-settings', $this->plugin_slug . '-cf7-sms-content');
            register_setting($this->plugin_slug . '-settings', $this->plugin_slug . '-cf7-sender-id');
            register_setting($this->plugin_slug . '-settings', $this->plugin_slug . '-cf7-from');
        }
    }
}


if (class_exists('WPSMS46elks')) {
    // instantiate the plugin WPSMS46elks class
    new WPSMS46elks();
}
