<?php
if (!defined('ABSPATH')) { exit; }

final class HS_Main {
    private static $instance = null;
    public $setup, $ajax, $shortcodes, $admin, $helpers, $fields, $tickets;

    public static function get_instance() {
        if (null === self::$instance) { self::$instance = new self(); }
        return self;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->fields = new HS_Fields();
        $this->helpers = new HS_Helpers();
        $this->setup = new HS_Setup();
        $this->ajax = new HS_Ajax($this->helpers, $this->fields);
        $this->shortcodes = new HS_Shortcodes($this->helpers, $this->fields);
        $this->admin = new HS_Admin($this->helpers, $this->fields);
        $this->tickets = new HS_Tickets($this->helpers);
        $this->add_actions_and_filters();
    }

    private function load_dependencies() {
        $files = [
            'data/iran-cities.php', 'class-hs-setup.php', 'class-hs-fields.php', 
            'class-hs-helpers.php', 'class-hs-shortcodes.php', 'class-hs-ajax.php', 
            'class-hs-admin.php', 'class-hs-tickets.php'
        ];
        foreach ($files as $file) { require_once HS_PLUGIN_PATH . 'includes/' . $file; }
    }

    private function add_actions_and_filters() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);
        add_action('init', [$this->helpers, 'update_last_seen']);
        add_action('user_register', [$this->helpers, 'generate_profile_uuid_on_register']);
        add_action('template_redirect', [$this, 'handle_redirects']);
        add_action('hs_auto_cancel_old_requests_hook', [$this, 'auto_cancel_old_requests']);
        add_action('wp_login', [$this, 'log_user_login'], 10, 2);
    }

    public function enqueue_scripts() {
        wp_enqueue_style('hs-styles', HS_PLUGIN_URL . 'assets/css/main.css', [], '5.2.0');
        wp_enqueue_script('hs-scripts', HS_PLUGIN_URL . 'assets/js/main.js', ['jquery'], '5.2.0', true);
        
        $province_city_data = hs_get_iran_provinces_cities();

        wp_localize_script('hs-scripts', 'hs_ajax_data', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('hs_ajax_nonce'),
            'messages' => [
                'field_required' => 'این فیلد الزامی است.',
                'final_submission_error'   => 'لطفاً خطاهای مشخص شده در فرم را برطرف کنید.',
                'final_submission_success' => 'اطلاعات شما با موفقیت ثبت شد و برای بررسی ارسال گردید.',
                'error_saving' => 'خطایی در ذخیره‌سازی اطلاعات رخ داد.',
            ],
            'provinces_cities' => $province_city_data,
        ]);
    }

    public function admin_enqueue_scripts($hook) {
        // Enqueue Select2 for admin user search in tickets
        if ('post.php' === $hook || 'post-new.php' === $hook) {
            global $post_type;
            if ('hs_ticket' === $post_type) {
                wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0-rc.0');
                wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0-rc.0', true);
            }
        }
    }

    public function handle_redirects() {
        if (is_admin() || !is_user_logged_in()) return;
        
        $user_id = get_current_user_id();
        
        $ban_until = get_user_meta($user_id, '_hs_ban_until', true);
        if ($ban_until && time() < $ban_until) {
            $remaining_time = human_time_diff(time(), $ban_until);
            $message_title = 'حساب کاربری شما مسدود است';
            $message_body = '<p style="font-size: 1.2em; text-align:center;">دسترسی شما به سایت به طور موقت مسدود شده است.</p>';
            $message_body .= '<p style="font-size: 1.1em; text-align:center; color: #c00;">زمان باقی‌مانده تا رفع مسدودیت: <strong>' . $remaining_time . '</strong></p>';
            $message_body .= '<p style="text-align:center;"><a href="' . wp_logout_url() . '">خروج از حساب</a></p>';
            wp_die($message_body, $message_title, ['response' => 403]);
        }

        global $post;
        if (!is_a($post, 'WP_Post')) return;
        $is_listing_page = has_shortcode($post->post_content, 'hamtam_user_listing');
        $active_sent_request = $this->helpers->get_user_active_sent_request($user_id);
        if ($active_sent_request && $is_listing_page) {
            $dashboard_url = $this->helpers->get_dashboard_page_url();
            if ($dashboard_url && !is_page(basename($dashboard_url))) {
                wp_redirect($dashboard_url);
                exit;
            }
        }
    }

    public function auto_cancel_old_requests() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'hs_requests';
        $time_limit = current_time('mysql', 1);
        $twenty_four_hours_ago = date('Y-m-d H:i:s', strtotime('-24 hours', strtotime($time_limit)));
        $wpdb->query($wpdb->prepare("UPDATE {$table_name} SET status = 'auto_cancelled' WHERE status = 'pending' AND request_date < %s", $twenty_four_hours_ago));
    }

    public function log_user_login($user_login, $user) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'hs_login_logs';
        
        $ip_address = !empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'UNKNOWN';
        $user_agent = !empty($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : 'UNKNOWN';

        $wpdb->insert(
            $table_name,
            [
                'user_id'         => $user->ID,
                'login_timestamp' => current_time('mysql'),
                'ip_address'      => $ip_address,
                'user_agent'      => $user_agent,
            ],
            [ '%d', '%s', '%s', '%s' ]
        );
    }
}
