<?php
if (!defined('ABSPATH')) { exit; }

class HS_Setup {
    public function __construct() {
         add_action('init', [$this, 'check_and_create_dependencies']);
    }

    public static function activate() {
        self::create_roles();
        self::create_requests_table();
        self::create_login_log_table(); // New
        // The secure uploads dir is now created on-demand by the AJAX class.
        if (!wp_next_scheduled('hs_auto_cancel_old_requests_hook')) {
            wp_schedule_event(time(), 'hourly', 'hs_auto_cancel_old_requests_hook');
        }
        update_option('hs_db_version', '1.1'); // Updated version
    }

    public static function deactivate() {
        self::remove_roles();
        wp_clear_scheduled_hook('hs_auto_cancel_old_requests_hook');
    }

    public function check_and_create_dependencies() {
        self::create_roles(); // Ensures roles exist on every load
        if (get_option('hs_db_version') !== '1.1') {
            self::create_requests_table();
            self::create_login_log_table(); // New
            update_option('hs_db_version', '1.1');
        }
        // The secure uploads dir is now created on-demand, so no check is needed here.
    }

    private static function create_roles() {
        if (get_role('hs_approved')) {
             // Check if our new role exists, if so, assume all are created.
            if(get_role('hs_banned')) return;
        }
        $subscriber_caps = get_role('subscriber')->capabilities;
        $roles = [
            'hs_pending'   => 'در انتظار بررسی',
            'hs_approved'  => 'تأیید شده',
            'hs_rejected'  => 'رد شده',
            'hs_blocked'   => 'مسدود شده',
            'hs_inactive'  => 'غیرفعال',
            'hs_banned'    => 'مسدود شده (BAN)', // New Role
        ];
        foreach ($roles as $slug => $name) {
            if (!get_role($slug)) {
                add_role($slug, $name, $subscriber_caps);
            }
        }
    }

    private static function remove_roles() {
        $roles = ['hs_pending', 'hs_approved', 'hs_rejected', 'hs_blocked', 'hs_inactive', 'hs_banned'];
        foreach ($roles as $role) { remove_role($role); }
    }
    
    private static function create_requests_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'hs_requests';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT, sender_id bigint(20) UNSIGNED NOT NULL, receiver_id bigint(20) UNSIGNED NOT NULL, status varchar(50) DEFAULT 'pending' NOT NULL,
            request_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL, response_date datetime, cancellation_reason text, cancelled_by bigint(20) UNSIGNED,
            admin_decision varchar(50) DEFAULT 'new' NOT NULL, admin_id bigint(20) UNSIGNED, admin_decision_date datetime,
            PRIMARY KEY (id), KEY sender_id (sender_id), KEY receiver_id (receiver_id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    private static function create_login_log_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'hs_login_logs';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name (
            log_id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            login_timestamp datetime NOT NULL,
            ip_address varchar(100) NOT NULL,
            user_agent text NOT NULL,
            PRIMARY KEY (log_id),
            KEY user_id (user_id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
