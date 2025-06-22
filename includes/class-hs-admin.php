<?php
if (!defined('ABSPATH')) { exit; }

class HS_Admin {
    private $helpers, $fields;

    public function __construct($helpers, $fields) {
        $this->helpers = $helpers;
        $this->fields = $fields;
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('show_user_profile', [$this, 'add_profile_actions_box']);
        add_action('edit_user_profile', [$this, 'add_profile_actions_box']);
    }

    public function enqueue_scripts($hook) {
        if (strpos($hook, 'hamtam-') === false && $hook !== 'user-edit.php' && $hook !== 'profile.php') { return; }
        
        wp_enqueue_style('hs-admin-styles', HS_PLUGIN_URL . 'assets/css/admin.css', [], '7.2.0');
        wp_enqueue_script('hs-admin-js', HS_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], '7.2.0', true);
        wp_localize_script('hs-admin-js', 'hs_admin_data', ['nonce' => wp_create_nonce('hs_admin_nonce')]);
    }

    public function add_admin_menu() {
        $pending_count = count(get_users(['role' => 'hs_pending']));
        $bubble = $pending_count > 0 ? ' <span class="awaiting-mod">' . $pending_count . '</span>' : '';
        
        add_menu_page('همتام', 'همتام', 'manage_options', 'hamtam-requests', [$this, 'render_requests_page'], 'dashicons-groups', 25);
        add_submenu_page('hamtam-requests', 'مدیریت درخواست‌ها', 'مدیریت درخواست‌ها', 'manage_options', 'hamtam-requests', [$this, 'render_requests_page']);
        add_submenu_page('hamtam-requests', 'بررسی پروفایل‌ها', 'بررسی پروفایل‌ها' . $bubble, 'manage_options', 'hamtam-profiles', [$this, 'render_profiles_page']);
        add_submenu_page('hamtam-requests', 'سابقه ورود کاربران', 'سابقه ورود کاربران', 'manage_options', 'hamtam-login-history', [$this, 'render_login_history_page']);
    }
    
    public function render_requests_page() { global $wpdb; $table_name = $wpdb->prefix . 'hs_requests'; if (isset($_GET['action'], $_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field($_GET['_wpnonce']), 'hs_admin_action')) { $request_id = isset($_GET['request_id']) ? intval($_GET['request_id']) : 0; $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0; $action = sanitize_text_field($_GET['action']); if ($request_id) { if ($action === 'admin_confirm' || $action === 'admin_reject') { $wpdb->update($table_name, ['admin_decision' => $action], ['id' => $request_id]); echo '<div class="notice notice-success is-dismissible"><p>تصمیم شما با موفقیت ثبت شد.</p></div>'; } elseif ($action === 'admin_cancel') { $wpdb->update($table_name, ['status' => 'cancelled_by_admin', 'admin_decision' => 'cancelled_by_admin'], ['id' => $request_id]); echo '<div class="notice notice-success is-dismissible"><p>درخواست با موفقیت توسط شما لغو شد.</p></div>'; } } if ($user_id && $action === 'lift_lock') { $this->helpers->lift_cancellation_lock($user_id); echo '<div class="notice notice-success is-dismissible"><p>محدودیت ۲۴ ساعته کاربر با موفقیت لغو شد.</p></div>'; } } $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'mutual'; ?> <div class="wrap hs-admin-wrap"> <h1>مدیریت درخواست‌های آشنایی</h1> <h2 class="nav-tab-wrapper"> <a href="?page=hamtam-requests&tab=mutual" class="nav-tab <?php echo $active_tab == 'mutual' ? 'nav-tab-active' : ''; ?>">دوطرفه (منتظر تایید)</a> <a href="?page=hamtam-requests&tab=all" class="nav-tab <?php echo $active_tab == 'all' ? 'nav-tab-active' : ''; ?>">همه درخواست‌ها</a> </h2> <?php $this->render_requests_table($active_tab); ?> </div> <?php }
    private function render_requests_table($tab) { global $wpdb; $table_name = $wpdb->prefix . 'hs_requests'; $query = "SELECT r.*, sender.display_name as sender_name, receiver.display_name as receiver_name FROM {$table_name} r JOIN {$wpdb->users} sender ON r.sender_id = sender.ID JOIN {$wpdb->users} receiver ON r.receiver_id = receiver.ID"; if ($tab === 'mutual') { $query .= " WHERE r.status = 'accepted'"; } $requests = $wpdb->get_results($query . " ORDER BY r.request_date DESC"); ?> <table class="wp-list-table widefat fixed striped"> <thead><tr><th>ارسال کننده</th><th>دریافت کننده</th><th>تاریخ درخواست</th><th>وضعیت کاربر</th><th>تصمیم ادمین</th><th>عملیات</th></tr></thead> <tbody> <?php if ($requests): foreach ($requests as $request): $sender_lock = get_user_meta($request->sender_id, '_hs_cancellation_lock_until', true); ?> <tr> <td><?php echo esc_html($request->sender_name); ?> (<a href="<?php echo get_edit_user_link($request->sender_id); ?>" target="_blank">ویرایش</a>) <?php if ($sender_lock && time() < $sender_lock): $lift_lock_url = wp_nonce_url(admin_url('admin.php?page=hamtam-requests&action=lift_lock&user_id=' . $request->sender_id), 'hs_admin_action'); ?> <br><a href="<?php echo esc_url($lift_lock_url); ?>" style="color:red;" title="این کاربر محدود شده است.">(لغو محدودیت)</a> <?php endif; ?> </td> <td><?php echo esc_html($request->receiver_name); ?> (<a href="<?php echo get_edit_user_link($request->receiver_id); ?>" target="_blank">ویرایش</a>)</td> <td><?php echo esc_html(wp_date('Y/m/d H:i', strtotime($request->request_date))); ?></td> <td><span class="hs-status-badge status-<?php echo esc_attr($request->status); ?>"><?php echo esc_html($this->helpers->get_status_label($request->status)); ?></span></td> <td><span class="hs-status-badge status-<?php echo esc_attr($request->admin_decision); ?>"><?php echo esc_html($this->helpers->get_admin_decision_label($request->admin_decision)); ?></span></td> <td> <div class="hs-admin-actions"> <?php if ($request->admin_decision === 'new'): ?> <?php if ($request->status === 'accepted'): $confirm_url = wp_nonce_url(admin_url('admin.php?page=hamtam-requests&action=admin_confirm&request_id=' . $request->id), 'hs_admin_action'); $reject_url = wp_nonce_url(admin_url('admin.php?page=hamtam-requests&action=admin_reject&request_id=' . $request->id), 'hs_admin_action'); ?> <a href="<?php echo esc_url($confirm_url); ?>" class="button button-primary">تایید نهایی</a> <a href="<?php echo esc_url($reject_url); ?>" class="button button-secondary">رد نهایی</a> <?php endif; ?> <?php if (in_array($request->status, ['pending', 'accepted'])): $cancel_url = wp_nonce_url(admin_url('admin.php?page=hamtam-requests&action=admin_cancel&request_id=' . $request->id), 'hs_admin_action'); ?> <a href="<?php echo esc_url($cancel_url); ?>" class="button">لغو درخواست</a> <?php endif; ?> <?php else: echo '---'; endif; ?> </div> </td> </tr> <?php if (!empty($request->cancellation_reason)): ?> <tr class="cancellation-reason-row"><td colspan="6"><strong>دلیل لغو:</strong> <?php echo esc_html($request->cancellation_reason); ?> (توسط: <?php echo esc_html(get_userdata($request->cancelled_by)->display_name); ?>)</td></tr> <?php endif; ?> <?php endforeach; else: ?> <tr><td colspan="6">هیچ درخواستی در این بخش یافت نشد.</td></tr> <?php endif; ?> </tbody> </table> <?php }
    public function render_profiles_page() { ?> <div class="wrap hs-admin-wrap"> <h1>کاربران در انتظار بررسی</h1> <p>در این صفحه، لیست کاربرانی که فرم ثبت‌نام را تکمیل کرده و منتظر تأیید شما هستند، نمایش داده می‌شود.</p> <table class="wp-list-table widefat fixed striped"> <thead><tr><th>نام کاربری</th><th>نام و نام خانوادگی</th><th>ایمیل</th><th>تاریخ ثبت‌نام</th><th>عملیات</th></tr></thead> <tbody> <?php $pending_users = get_users(['role__in' => ['hs_pending', 'hs_rejected']]); if (!empty($pending_users)) { foreach ($pending_users as $user) { $edit_link = get_edit_user_link($user->ID); $full_name = get_user_meta($user->ID, 'hs_first_name', true) . ' ' . get_user_meta($user->ID, 'hs_last_name', true); $status = in_array('hs_rejected', (array)$user->roles) ? ' <span style="color:red;">(رد شده)</span>' : ''; ?> <tr> <td><strong><a href="<?php echo esc_url($edit_link); ?>"><?php echo esc_html($user->user_login); ?></a><?php echo $status; ?></strong></td> <td><?php echo esc_html($full_name); ?></td> <td><?php echo esc_html($user->user_email); ?></td> <td><?php echo date_i18n('Y/m/d', strtotime($user->user_registered)); ?></td> <td><a href="<?php echo esc_url($edit_link); ?>" class="button">مشاهده و بررسی پروفایل</a></td> </tr> <?php } } else { echo '<tr><td colspan="5">هیچ کاربر جدیدی در انتظار بررسی نیست.</td></tr>'; } ?> </tbody> </table> </div> <?php }

    public function render_login_history_page() {
        global $wpdb;
        $logs_table = $wpdb->prefix . 'hs_login_logs';
        $users_table = $wpdb->users;
        
        $search_term = isset($_GET['s']) ? sanitize_text_field(trim($_GET['s'])) : '';
        $where_clause = '';
        $query_args = [];
        ?>
        <div class="wrap hs-admin-wrap">
            <h1>سابقه ورود کاربران</h1>
            <p>برای مشاهده سابقه ورود یک کاربر خاص، نام کاربری، نام نمایشی یا ایمیل او را جستجو کنید. در غیر این صورت، ۱۰۰ ورود اخیر نمایش داده می‌شود.</p>

            <form method="get" style="margin-bottom: 20px;">
                <input type="hidden" name="page" value="hamtam-login-history">
                <label for="hs_user_search" class="screen-reader-text">جستجوی کاربر:</label>
                <input type="search" id="hs_user_search" name="s" value="<?php echo esc_attr($search_term); ?>" placeholder="نام کاربری، ایمیل و...">
                <input type="submit" class="button" value="جستجوی کاربر">
                 <?php if ($search_term): ?>
                    <a href="?page=hamtam-login-history" class="button button-secondary">نمایش همه</a>
                <?php endif; ?>
            </form>

            <?php
            if ($search_term) {
                $user_query = new WP_User_Query([
                    'search' => '*' . esc_attr($search_term) . '*',
                    'search_columns' => ['user_login', 'user_email', 'display_name'],
                    'fields' => 'ID',
                ]);
                $user_ids = $user_query->get_results();

                if (!empty($user_ids)) {
                    $ids_placeholder = implode(',', array_fill(0, count($user_ids), '%d'));
                    $where_clause = $wpdb->prepare("WHERE l.user_id IN ($ids_placeholder)", $user_ids);
                    if (count($user_ids) === 1) {
                         $user_data = get_userdata($user_ids[0]);
                         echo '<h2>سابقه ورود برای کاربر: ' . esc_html($user_data->display_name) . ' (' . esc_html($user_data->user_login) . ')</h2>';
                    } else {
                        echo '<h2>نتایج جستجو برای: "' . esc_html($search_term) . '"</h2>';
                    }
                } else {
                    echo '<h2>کاربری برای عبارت "' . esc_html($search_term) . '" یافت نشد.</h2>';
                    $where_clause = "WHERE 1=0"; // Force no results
                }
            }

            $limit_clause = empty($search_term) ? 'LIMIT 100' : '';
            $query = "SELECT l.*, u.user_login, u.display_name FROM {$logs_table} l LEFT JOIN {$users_table} u ON l.user_id = u.ID {$where_clause} ORDER BY l.login_timestamp DESC {$limit_clause}";
            $logs = $wpdb->get_results($query);
            ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>نام کاربری</th>
                        <th>IP</th>
                        <th>تاریخ و ساعت ورود</th>
                        <th style="width: 40%;">اطلاعات مرورگر و سیستم عامل (User Agent)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs): foreach ($logs as $log): ?>
                        <tr>
                            <td>
                                <?php if ($log->display_name): ?>
                                    <a href="<?php echo get_edit_user_link($log->user_id); ?>" target="_blank">
                                        <?php echo esc_html($log->display_name); ?> (<?php echo esc_html($log->user_login); ?>)
                                    </a>
                                <?php else: ?>
                                    کاربر حذف شده (ID: <?php echo esc_html($log->user_id); ?>)
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($log->ip_address); ?></td>
                            <td><?php echo esc_html(wp_date('Y/m/d H:i:s', strtotime($log->login_timestamp))); ?></td>
                            <td style="direction: ltr; text-align: left; font-size: 11px;"><?php echo esc_html($log->user_agent); ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="4">هیچ سابقه ورودی برای این جستجو یافت نشد.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function add_profile_actions_box($user) {
        if (!current_user_can('manage_options')) return;
        $user_roles = (array) $user->roles;
        $relevant_roles = ['hs_pending', 'hs_approved', 'hs_rejected', 'hs_blocked', 'hs_inactive', 'hs_banned'];
        if (empty(array_intersect($relevant_roles, $user_roles))) { return; }
        ?>
        <div id="hs-admin-review-box" class="postbox">
            <h2 class="hndle"><span>عملیات پروفایل همتام</span></h2>
            <div class="inside">
                 <div id="hs-profile-docs">
                    <h3>مدارک بارگذاری شده</h3>
                    <?php 
                    $doc_fields = array_filter($this->fields->get_fields()['documents']['fields'], fn($a) => $a['type'] === 'file');
                    $has_docs = false;
                    foreach ($doc_fields as $key => $attrs) {
                        $file_data = get_user_meta($user->ID, 'hs_' . $key, true);
                        if(!empty($file_data) && is_array($file_data)) {
                            $has_docs = true;
                            $nonce = wp_create_nonce('hs_serve_secure_file_nonce_action');
                            $serve_url = plugins_url('serve-document.php', HS_PLUGIN_FILE);
                            $file_url = add_query_arg([
                                'user_id' => $user->ID,
                                'doc_key' => 'hs_' . $key,
                                '_wpnonce' => $nonce
                            ], $serve_url);
                            
                            echo '<p><strong>' . esc_html($attrs['label']) . ':</strong> <a href="' . esc_url($file_url) . '" target="_blank">مشاهده فایل (' . esc_html($file_data['original_name']) . ')</a></p>';
                        }
                    }
                    if(!$has_docs) echo '<p>هیچ مدرکی توسط این کاربر بارگذاری نشده است.</p>';
                    ?>
                </div>
                <hr>
                <div class="hs-admin-actions">
                    <div id="hs-action-message" style="display:none;" class="notice"></div>
                    <?php if(!in_array('hs_banned', $user_roles)): ?>
                        <button type="button" class="button button-primary" id="hs-approve-btn" data-user-id="<?php echo $user->ID; ?>">تأیید پروفایل</button>
                        <button type="button" class="button button-secondary" id="hs-reject-btn-prompt">رد پروفایل</button>
                    <?php endif; ?>
                    <span class="spinner" style="float: none; vertical-align: middle;"></span>
                    <div id="rejection-reason-wrapper" style="display:none; margin-top:15px;">
                        <p><label for="rejection_reason"><strong>دلیل رد پروفایل (این پیام به کاربر نمایش داده می‌شود):</strong></label></p>
                        <textarea id="rejection_reason" rows="3" style="width: 100%;"></textarea>
                        <button type="button" class="button button-danger" id="hs-reject-btn-confirm" data-user-id="<?php echo $user->ID; ?>" style="margin-top: 10px;">ثبت دلیل و رد کردن نهایی</button>
                        <button type="button" class="button button-secondary" id="hs-reject-cancel-btn" style="margin-top: 10px;">انصراف</button>
                    </div>
                </div>
                <hr>
                <div id="hs-admin-notes-wrapper" style="margin-top:15px;">
                    <h4>یادداشت‌های خصوصی ادمین</h4>
                    <p><label for="hs_admin_private_notes">این یادداشت‌ها فقط برای مدیران دیگر قابل مشاهده است.</label></p>
                    <textarea id="hs_admin_private_notes" rows="4" style="width:100%;"><?php echo esc_textarea(get_user_meta($user->ID, '_hs_admin_private_notes', true)); ?></textarea>
                    <button type="button" class="button" id="hs-save-note-btn" data-user-id="<?php echo $user->ID; ?>">ذخیره یادداشت</button>
                    <span class="spinner" style="float: none; vertical-align: middle;"></span>
                </div>
                <hr>
                <div id="hs-ban-user-wrapper" style="margin-top:15px;">
                    <h4>مسدود کردن کاربر (Ban)</h4>
                     <div id="hs-ban-message" style="display:none;" class="notice"></div>
                    <p><label for="hs_ban_until_date"><strong>مسدود کردن تا تاریخ:</strong></label></p>
                    <input type="datetime-local" id="hs_ban_until_date" />
                    <button type="button" class="button button-danger" id="hs-confirm-ban-btn" data-user-id="<?php echo $user->ID; ?>">اعمال مسدودیت</button>
                    <?php $ban_until_timestamp = get_user_meta($user->ID, '_hs_ban_until', true); if ($ban_until_timestamp && $ban_until_timestamp > time()): ?>
                        <p style="color:red; margin-top:10px;"><strong>کاربر در حال حاضر تا <?php echo human_time_diff($ban_until_timestamp); ?> دیگر مسدود است.</strong> (<?php echo wp_date('Y/m/d H:i', $ban_until_timestamp); ?>)</p>
                        <button type="button" class="button" id="hs-unban-btn" data-user-id="<?php echo $user->ID; ?>">رفع مسدودیت</button>
                    <?php endif; ?>
                    <span class="spinner" style="float: none; vertical-align: middle;"></span>
                </div>

            </div>
        </div>
        <?php
    }
}
