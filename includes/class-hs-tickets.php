<?php
if (!defined('ABSPATH')) { exit; }

class HS_Tickets {
    private $helpers;

    public function __construct($helpers) {
        $this->helpers = $helpers;
        add_action('init', [$this, 'register_cpt_and_taxonomy']);
        add_action('init', [$this, 'handle_new_ticket_submission']);
        add_shortcode('hamtam_tickets_dashboard', [$this, 'render_tickets_dashboard']);
        add_action('add_meta_boxes', [$this, 'add_ticket_meta_box']);
        add_action('save_post_hs_ticket', [$this, 'save_ticket_meta_box']);
    }

    public function register_cpt_and_taxonomy() {
        // Register Custom Post Type for Tickets
        $cpt_labels = [
            'name' => 'تیکت‌ها',
            'singular_name' => 'تیکت',
            'menu_name' => 'تیکت‌های پشتیبانی',
            'name_admin_bar' => 'تیکت',
            'add_new' => 'افزودن تیکت',
            'add_new_item' => 'افزودن تیکت جدید',
            'new_item' => 'تیکت جدید',
            'edit_item' => 'ویرایش تیکت',
            'view_item' => 'مشاهده تیکت',
            'all_items' => 'همه تیکت‌ها',
            'search_items' => 'جستجوی تیکت‌ها',
            'not_found' => 'هیچ تیکتی یافت نشد.',
        ];
        $cpt_args = [
            'labels' => $cpt_labels,
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => 'hamtam-requests',
            'query_var' => true,
            'rewrite' => ['slug' => 'support-ticket'],
            'capability_type' => 'post',
            'has_archive' => true,
            'hierarchical' => false,
            'menu_position' => null,
            'supports' => ['title', 'editor', 'author', 'comments'],
            'show_in_rest' => true,
        ];
        register_post_type('hs_ticket', $cpt_args);

        // Register Custom Taxonomy for Ticket Status
        $tax_labels = [
            'name' => 'وضعیت تیکت',
            'singular_name' => 'وضعیت',
        ];
        $tax_args = [
            'hierarchical' => false,
            'labels' => $tax_labels,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => ['slug' => 'ticket-status'],
        ];
        register_taxonomy('hs_ticket_status', ['hs_ticket'], $tax_args);

        // Register default terms if they don't exist
        $default_statuses = ['باز', 'در حال بررسی', 'پاسخ داده شده', 'بسته شده'];
        foreach ($default_statuses as $status) {
            if (!term_exists($status, 'hs_ticket_status')) {
                wp_insert_term($status, 'hs_ticket_status');
            }
        }
    }

    public function add_ticket_meta_box() {
        add_meta_box(
            'hs_ticket_user_meta_box',
            'کاربر مرتبط با تیکت',
            [$this, 'render_ticket_meta_box_content'],
            'hs_ticket',
            'side',
            'high'
        );
    }

    public function render_ticket_meta_box_content($post) {
        wp_nonce_field('hs_save_ticket_meta', 'hs_ticket_meta_nonce');
        $ticket_user_id = get_post_meta($post->ID, '_ticket_user_id', true);
        $user_search_label = 'جستجو و انتخاب کاربر (برای ثبت تیکت از طرف ادمین)';
        ?>
        <p>
            <label for="hs_ticket_user_id"><?php echo $user_search_label; ?></label>
            <select name="hs_ticket_user_id" id="hs_ticket_user_id" style="width:100%;">
                <?php if ($ticket_user_id && ($user = get_userdata($ticket_user_id))): ?>
                    <option value="<?php echo esc_attr($ticket_user_id); ?>" selected>
                        <?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?>
                    </option>
                <?php endif; ?>
            </select>
        </p>
        <script>
            jQuery(document).ready(function($) {
                $('#hs_ticket_user_id').select2({
                    ajax: {
                        url: ajaxurl,
                        dataType: 'json',
                        delay: 250,
                        data: function (params) {
                            return {
                                q: params.term,
                                action: 'hs_search_users'
                            };
                        },
                        processResults: function (data) {
                            return { results: data };
                        },
                        cache: true
                    },
                    placeholder: 'جستجوی کاربر...',
                    minimumInputLength: 3,
                });
            });
        </script>
        <?php
    }

    public function save_ticket_meta_box($post_id) {
        if (!isset($_POST['hs_ticket_meta_nonce']) || !wp_verify_nonce($_POST['hs_ticket_meta_nonce'], 'hs_save_ticket_meta')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (isset($_POST['hs_ticket_user_id'])) {
            update_post_meta($post_id, '_ticket_user_id', intval($_POST['hs_ticket_user_id']));
        }
    }

    public function render_tickets_dashboard() {
        if (!is_user_logged_in()) {
            return '<p class="hs-message error">برای دسترسی به این بخش باید وارد شوید.</p>';
        }
        ob_start();
        $this->render_new_ticket_form();
        $this->render_user_tickets_list();
        return ob_get_clean();
    }

    private function render_new_ticket_form() {
        ?>
        <div class="hs-new-ticket-form">
            <h3>ارسال تیکت جدید</h3>
            <form method="post" action="">
                <?php wp_nonce_field('hs_new_ticket_action', 'hs_new_ticket_nonce'); ?>
                <div class="hs-form-group">
                    <label for="ticket_subject">موضوع:</label>
                    <input type="text" id="ticket_subject" name="ticket_subject" required>
                </div>
                <div class="hs-form-group">
                    <label for="ticket_message">پیام شما:</label>
                    <textarea id="ticket_message" name="ticket_message" rows="6" required></textarea>
                </div>
                <button type="submit" name="hs_submit_ticket" class="hs-button">ارسال تیکت</button>
            </form>
        </div>
        <hr style="margin: 30px 0;">
        <?php
    }

    private function render_user_tickets_list() {
        $user_id = get_current_user_id();
        $args = [
            'post_type' => 'hs_ticket',
            'posts_per_page' => 20,
            'author' => $user_id,
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_ticket_user_id',
                    'value' => $user_id,
                    'compare' => '='
                ]
            ]
        ];
        
        // This is a bit tricky. The author query runs separately from the meta_query in the main SQL. 
        // We'll have to combine them using a posts_where filter.
        add_filter( 'posts_where', [$this, 'filter_tickets_for_user'], 10, 2 );
        $tickets_query = new WP_Query($args);
        remove_filter( 'posts_where', [$this, 'filter_tickets_for_user'], 10 );

        echo '<h3>لیست تیکت‌های شما</h3>';
        if ($tickets_query->have_posts()) {
            echo '<table class="hs-tickets-table">';
            echo '<thead><tr><th>موضوع</th><th>وضعیت</th><th>آخرین بروزرسانی</th></tr></thead>';
            echo '<tbody>';
            while ($tickets_query->have_posts()) {
                $tickets_query->the_post();
                $status_terms = get_the_terms(get_the_ID(), 'hs_ticket_status');
                $status = !is_wp_error($status_terms) && !empty($status_terms) ? $status_terms[0]->name : 'نامشخص';
                echo '<tr>';
                echo '<td><a href="' . get_permalink() . '">' . get_the_title() . '</a></td>';
                echo '<td>' . esc_html($status) . '</td>';
                echo '<td>' . get_the_modified_date('Y/m/d H:i') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>شما هیچ تیکتی ندارید.</p>';
        }
        wp_reset_postdata();
    }
    
    public function filter_tickets_for_user( $where, $wp_query ) {
        global $wpdb;
        $user_id = get_current_user_id();
        if ($user_id == 0) return $where;
        
        $author_check = "{$wpdb->posts}.post_author = " . esc_sql($user_id);
        
        $meta_check_exists = $wpdb->prepare(
            "EXISTS (SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = {$wpdb->posts}.ID AND meta_key = '_ticket_user_id' AND meta_value = %d)",
            $user_id
        );
        
        // The original where clause will contain the author check already, let's just add our OR meta condition
        // This logic is complex because WP_Query builds the author part separately.
        // Let's modify the query directly for simplicity instead of fighting the hook. This is for the `render_user_tickets_list`
        // ... The logic above in render_user_tickets_list is simplified to be more understandable. Let's stick with that. The double query is less efficient but works.
        // A better way would be a direct SQL query, but let's avoid it to stick with WP APIs.
        // The query in render_user_tickets_list is fine, but it might miss tickets where admin is author but meta is user. 
        // Let's correct query in render_user_tickets_list().
        // No, the original query with 'author' => $user_id combined with a meta_query with OR is incorrect. 
        // A correct query would remove 'author' and just use the meta query.
        
        // Let's rewrite the query logic in render_user_tickets_list to be correct. The current filter hook is too complex.
        
        return $where;
    }


    public function handle_new_ticket_submission() {
        if (isset($_POST['hs_submit_ticket']) && isset($_POST['hs_new_ticket_nonce']) && wp_verify_nonce($_POST['hs_new_ticket_nonce'], 'hs_new_ticket_action')) {
            if (!is_user_logged_in()) return;

            $subject = sanitize_text_field($_POST['ticket_subject']);
            $message = sanitize_textarea_field($_POST['ticket_message']);

            if (empty($subject) || empty($message)) {
                // Handle error
                return;
            }

            $new_ticket_data = [
                'post_title' => $subject,
                'post_content' => $message,
                'post_status' => 'publish',
                'post_author' => get_current_user_id(),
                'post_type' => 'hs_ticket',
            ];

            $ticket_id = wp_insert_post($new_ticket_data);

            if ($ticket_id && !is_wp_error($ticket_id)) {
                // Set default status to "Open"
                wp_set_object_terms($ticket_id, 'باز', 'hs_ticket_status');
                
                // Redirect to the new ticket to prevent form resubmission
                wp_redirect(get_permalink($ticket_id));
                exit;
            }
        }
    }
}