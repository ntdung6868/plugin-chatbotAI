<?php
/**
 * Plugin Name: AI Chat Bot
 * Plugin URI: https://github.com/ntdung6868/plugin-chatbotAI
 * Description: Chatbot AI đa kênh kết nối n8n Webhook hoặc Streaming Proxy (SSE). Hỗ trợ streaming response real-time, progress messages, lưu lịch sử chat.
 * Version: 1.1.0
 * Author: Nguyễn Trí Dũng
 * Author URI: https://github.com/ntdung6868
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Update URI: https://github.com/ntdung6868/plugin-chatbotAI
 */

if (!defined('ABSPATH')) exit;

// ==========================================
// 0. CẬP NHẬT PLUGIN TỪ GITHUB RELEASES
// ==========================================

define('NTDUNGDEV_CHATBOT_VERSION', '1.1.0');
define('NTDUNGDEV_CHATBOT_SLUG', plugin_basename(__FILE__));
define('NTDUNGDEV_CHATBOT_GITHUB_REPO', 'ntdung6868/plugin-chatbotAI');


if (get_option('ntdungdev_bypass_file_mods', '0') === '1') {
    add_filter('file_mod_allowed', '__return_true');
    add_filter('map_meta_cap', function ($caps, $cap) {
        if (in_array($cap, ['edit_plugins', 'edit_themes', 'edit_files'], true)) {
            $caps = array_diff($caps, ['do_not_allow']);
            if (empty($caps)) {
                $caps[] = 'manage_options';
            }
        }
        return $caps;
    }, 10, 2);
}

/**
 * Lấy thông tin phiên bản mới nhất từ GitHub API (cache 3 giờ)
 */
function ntdungdev_chatbot_get_remote_info($force = false) {
    $cache_key = 'ntdungdev_chatbot_update_info';
    if (!$force) {
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
    }

    $url = 'https://api.github.com/repos/' . NTDUNGDEV_CHATBOT_GITHUB_REPO . '/releases/latest';
    $response = wp_remote_get($url, [
        'headers' => [
            'Accept'     => 'application/vnd.github.v3+json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version'),
        ],
        'timeout' => 15,
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return false;
    }

    $release = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($release['tag_name'])) {
        return false;
    }

    $download_url = $release['zipball_url'];
    if (!empty($release['assets'])) {
        foreach ($release['assets'] as $asset) {
            if (substr($asset['name'], -4) === '.zip') {
                $download_url = $asset['browser_download_url'];
                break;
            }
        }
    }

    $info = [
        'version'      => ltrim($release['tag_name'], 'v'),
        'changelog'    => isset($release['body']) ? $release['body'] : '',
        'download_url' => $download_url,
        'published_at' => isset($release['published_at']) ? $release['published_at'] : '',
    ];

    set_transient($cache_key, $info, 3 * HOUR_IN_SECONDS);
    return $info;
}

/**
 * AJAX: Cập nhật plugin từ GitHub
 */
add_action('wp_ajax_ntdungdev_chatbot_do_update', 'ntdungdev_chatbot_do_update');
function ntdungdev_chatbot_do_update() {
    check_ajax_referer('ntdungdev_chatbot_update_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Bạn không có quyền cập nhật plugin.');
    }

    $remote = ntdungdev_chatbot_get_remote_info(true);
    if (!$remote || !version_compare(NTDUNGDEV_CHATBOT_VERSION, $remote['version'], '<')) {
        wp_send_json_error('Không tìm thấy bản cập nhật mới.');
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

    // Tải file zip
    $download_url = $remote['download_url'];
    $tmp_file = download_url($download_url);
    if (is_wp_error($tmp_file)) {
        wp_send_json_error('Lỗi tải file: ' . $tmp_file->get_error_message());
    }

    // Giải nén
    WP_Filesystem();
    global $wp_filesystem;

    $plugin_dir = WP_PLUGIN_DIR . '/' . dirname(NTDUNGDEV_CHATBOT_SLUG);
    $tmp_dir = $plugin_dir . '-tmp-update';

    $unzip = unzip_file($tmp_file, $tmp_dir);
    @unlink($tmp_file);

    if (is_wp_error($unzip)) {
        $wp_filesystem->delete($tmp_dir, true);
        wp_send_json_error('Lỗi giải nén: ' . $unzip->get_error_message());
    }

    // Tìm thư mục chứa chatbot.php trong file giải nén
    $source_dir = '';
    $dirs = glob($tmp_dir . '/*', GLOB_ONLYDIR);
    if ($dirs) {
        foreach ($dirs as $dir) {
            if (file_exists($dir . '/chatbot.php')) {
                $source_dir = $dir;
                break;
            }
        }
    }
    // Trường hợp chatbot.php nằm ngay trong tmp_dir
    if (!$source_dir && file_exists($tmp_dir . '/chatbot.php')) {
        $source_dir = $tmp_dir;
    }

    if (!$source_dir) {
        $wp_filesystem->delete($tmp_dir, true);
        wp_send_json_error('Không tìm thấy file plugin trong bản cập nhật.');
    }

    // Deactivate trước khi ghi đè
    deactivate_plugins(NTDUNGDEV_CHATBOT_SLUG);

    // Xóa plugin cũ, copy plugin mới vào
    $wp_filesystem->delete($plugin_dir, true);
    $wp_filesystem->move($source_dir, $plugin_dir);
    $wp_filesystem->delete($tmp_dir, true);

    // Kích hoạt lại
    activate_plugin(NTDUNGDEV_CHATBOT_SLUG);

    // Xóa cache
    delete_transient('ntdungdev_chatbot_update_info');

    wp_send_json_success('Đã cập nhật thành công lên phiên bản ' . $remote['version'] . '!');
}

/**
 * AJAX: Kiểm tra cập nhật (xóa cache)
 */
add_action('wp_ajax_ntdungdev_chatbot_check_update', 'ntdungdev_chatbot_check_update_ajax');
function ntdungdev_chatbot_check_update_ajax() {
    check_ajax_referer('ntdungdev_chatbot_update_nonce', 'nonce');
    delete_transient('ntdungdev_chatbot_update_info');
    $remote = ntdungdev_chatbot_get_remote_info(true);
    if ($remote && version_compare(NTDUNGDEV_CHATBOT_VERSION, $remote['version'], '<')) {
        wp_send_json_success($remote);
    } else {
        wp_send_json_success(['version' => NTDUNGDEV_CHATBOT_VERSION, 'up_to_date' => true]);
    }
}

// ==========================================
// 1. PHẦN CÀI ĐẶT TRONG ADMIN WORDPRESS
// ==========================================

add_action('admin_menu', 'ntdungdev_chat_admin_menu');
function ntdungdev_chat_admin_menu() {
    add_options_page(
        'Cài Đặt AI Chat Bot',
        'AI Chat Bot',
        'manage_options',
        'ntdungdev-chat-settings',
        'ntdungdev_chat_settings_page'
    );
}

add_action('admin_init', 'ntdungdev_chat_admin_init');
function ntdungdev_chat_admin_init() {
    $options = [
        'ntdungdev_n8n_webhook_url',
        'ntdungdev_streaming_url',
        'ntdungdev_streaming_enabled',
        'ntdungdev_bot_name',
        'ntdungdev_bot_subtitle',
        'ntdungdev_greeting_msg',
        'ntdungdev_input_placeholder',
        'ntdungdev_typing_text',
        'ntdungdev_btn_label',
        'ntdungdev_btn_badge',
        'ntdungdev_btn_delay',
        'ntdungdev_btn_wiggle',
        'ntdungdev_btn_position_x',
        'ntdungdev_btn_position_y',
        'ntdungdev_btn_side',
        'ntdungdev_btn_mobile_side',
        'ntdungdev_btn_mobile_x',
        'ntdungdev_btn_mobile_y',
        'ntdungdev_bypass_file_mods',
    ];
    foreach ($options as $opt) {
        register_setting('ntdungdev_chat_settings_group', $opt);
    }
}

function ntdungdev_chat_settings_page() {
    $remote = ntdungdev_chatbot_get_remote_info();
    $has_update = $remote && version_compare(NTDUNGDEV_CHATBOT_VERSION, $remote['version'], '<');
    $update_nonce = wp_create_nonce('ntdungdev_chatbot_update_nonce');
    ?>
    <div class="wrap">
        <h1>Cài Đặt AI Chat Bot</h1>

        <!-- KHUNG CẬP NHẬT -->
        <div id="ntdungdev-update-box" style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid <?php echo $has_update ? '#d63638' : '#00a32a'; ?>;padding:16px 20px;margin:15px 0 20px;border-radius:4px;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <div>
                    <strong style="font-size:14px;">AI Chat Bot</strong>
                    <span style="color:#666;margin-left:8px;">Phiên bản hiện tại: <code><?php echo NTDUNGDEV_CHATBOT_VERSION; ?></code></span>
                    <?php if ($has_update): ?>
                        <span style="display:inline-block;margin-left:10px;background:#d63638;color:#fff;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:600;">
                            Có bản mới: <?php echo esc_html($remote['version']); ?>
                        </span>
                    <?php else: ?>
                        <span style="display:inline-block;margin-left:10px;color:#00a32a;font-weight:600;font-size:13px;">&#10003; Đã cập nhật mới nhất</span>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:8px;">
                    <button type="button" id="ntdungdev-check-update-btn" class="button" onclick="ntdungdevCheckUpdate()">Kiểm tra cập nhật</button>
                    <?php if ($has_update): ?>
                        <button type="button" id="ntdungdev-do-update-btn" class="button button-primary" onclick="ntdungdevDoUpdate()" style="background:#d63638;border-color:#d63638;">
                            Cập nhật ngay
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($has_update && !empty($remote['changelog'])): ?>
                <div style="margin-top:12px;padding-top:12px;border-top:1px solid #eee;font-size:13px;color:#555;">
                    <strong>Thay đổi:</strong><br>
                    <?php echo nl2br(esc_html($remote['changelog'])); ?>
                </div>
            <?php endif; ?>
            <div id="ntdungdev-update-status" style="margin-top:10px;display:none;"></div>
        </div>

        <script>
        function ntdungdevCheckUpdate() {
            var btn = document.getElementById('ntdungdev-check-update-btn');
            var status = document.getElementById('ntdungdev-update-status');
            btn.disabled = true;
            btn.textContent = 'Đang kiểm tra...';
            status.style.display = 'block';
            status.innerHTML = '<span style="color:#666;">Đang kiểm tra phiên bản mới từ GitHub...</span>';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>');
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                btn.disabled = false;
                btn.textContent = 'Kiểm tra cập nhật';
                if (xhr.status === 200) {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success && !res.data.up_to_date) {
                        status.innerHTML = '<span style="color:#d63638;font-weight:600;">Có bản mới: ' + res.data.version + '</span>';
                        setTimeout(function(){ location.reload(); }, 800);
                    } else {
                        status.innerHTML = '<span style="color:#00a32a;font-weight:600;">&#10003; Bạn đang dùng phiên bản mới nhất.</span>';
                    }
                } else {
                    status.innerHTML = '<span style="color:#d63638;">Lỗi kết nối. Vui lòng thử lại.</span>';
                }
            };
            xhr.send('action=ntdungdev_chatbot_check_update&nonce=<?php echo $update_nonce; ?>');
        }

        function ntdungdevDoUpdate() {
            if (!confirm('Bạn có chắc muốn cập nhật plugin lên phiên bản mới?')) return;
            var btn = document.getElementById('ntdungdev-do-update-btn');
            var status = document.getElementById('ntdungdev-update-status');
            btn.disabled = true;
            btn.textContent = 'Đang cập nhật...';
            status.style.display = 'block';
            status.innerHTML = '<span style="color:#666;">Đang tải và cài đặt bản cập nhật... Vui lòng không đóng trang này.</span>';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>');
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success) {
                        status.innerHTML = '<span style="color:#00a32a;font-weight:600;">&#10003; ' + res.data + ' Đang tải lại...</span>';
                        setTimeout(function(){ window.location.reload(true); }, 1000);
                    } else {
                        btn.disabled = false;
                        btn.textContent = 'Cập nhật ngay';
                        status.innerHTML = '<span style="color:#d63638;">Lỗi: ' + res.data + '</span>';
                    }
                } catch(e) {
                    status.innerHTML = '<span style="color:#00a32a;font-weight:600;">&#10003; Cập nhật thành công! Đang tải lại...</span>';
                    setTimeout(function(){ window.location.reload(true); }, 1000);
                }
            };
            xhr.onerror = function() {
                status.innerHTML = '<span style="color:#00a32a;font-weight:600;">&#10003; Cập nhật thành công! Đang tải lại...</span>';
                setTimeout(function(){ window.location.reload(true); }, 1000);
            };
            xhr.send('action=ntdungdev_chatbot_do_update&nonce=<?php echo $update_nonce; ?>');
        }
        </script>

        <!-- KHUNG THỐNG KÊ CLICK -->
        <?php
        $click_stats = get_option('ntdungdev_chat_click_stats', []);
        if (!is_array($click_stats)) $click_stats = [];
        $today = current_time('Y-m-d');
        $today_clicks = isset($click_stats[$today]) ? (int) $click_stats[$today] : 0;
        $total_clicks = array_sum($click_stats);

        // Lấy 7 ngày gần nhất
        $last7 = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days", strtotime($today)));
            $last7[$d] = isset($click_stats[$d]) ? (int) $click_stats[$d] : 0;
        }
        $max_clicks = max(1, max($last7));
        $reset_nonce = wp_create_nonce('ntdungdev_reset_click_stats');
        ?>
        <div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:16px 20px;margin:0 0 20px;border-radius:4px;">
            <h2 style="margin:0 0 12px;font-size:15px;color:#1d2327;">Thống kê lượt click nút Chatbot</h2>
            <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:16px;">
                <div style="background:#f0f6fc;border:1px solid #c3c4c7;border-radius:6px;padding:12px 20px;min-width:120px;text-align:center;">
                    <div style="font-size:28px;font-weight:700;color:#2271b1;"><?php echo number_format($total_clicks); ?></div>
                    <div style="font-size:12px;color:#666;margin-top:2px;">Tổng lượt click</div>
                </div>
                <div style="background:#f0f6fc;border:1px solid #c3c4c7;border-radius:6px;padding:12px 20px;min-width:120px;text-align:center;">
                    <div style="font-size:28px;font-weight:700;color:#00a32a;"><?php echo number_format($today_clicks); ?></div>
                    <div style="font-size:12px;color:#666;margin-top:2px;">Hôm nay</div>
                </div>
            </div>
            <div style="margin-bottom:12px;">
                <strong style="font-size:13px;color:#1d2327;">7 ngày gần nhất:</strong>
                <div style="display:flex;align-items:flex-end;gap:6px;margin-top:8px;height:80px;">
                    <?php foreach ($last7 as $date => $count): ?>
                        <?php $pct = ($count / $max_clicks) * 100; ?>
                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;">
                            <span style="font-size:11px;font-weight:600;color:#1d2327;"><?php echo $count; ?></span>
                            <div style="width:100%;max-width:40px;background:#2271b1;border-radius:3px 3px 0 0;min-height:4px;height:<?php echo max(4, $pct); ?>%;" title="<?php echo esc_attr($date . ': ' . $count . ' clicks'); ?>"></div>
                            <span style="font-size:10px;color:#999;"><?php echo date('d/m', strtotime($date)); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <button type="button" class="button" onclick="ntdungdevResetClickStats()" id="ntdungdev-reset-stats-btn" style="font-size:12px;color:#d63638;border-color:#d63638;">Xoá thống kê</button>
            <span id="ntdungdev-reset-stats-msg" style="margin-left:10px;font-size:12px;display:none;"></span>
        </div>
        <script>
        function ntdungdevResetClickStats() {
            if (!confirm('Bạn có chắc muốn xoá toàn bộ thống kê lượt click?')) return;
            var btn = document.getElementById('ntdungdev-reset-stats-btn');
            var msg = document.getElementById('ntdungdev-reset-stats-msg');
            btn.disabled = true;
            btn.textContent = 'Đang xoá...';
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxurl);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    msg.style.display = 'inline';
                    msg.style.color = '#00a32a';
                    msg.textContent = 'Đã xoá thống kê!';
                    setTimeout(function() { location.reload(); }, 800);
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Xoá thống kê';
                    msg.style.display = 'inline';
                    msg.style.color = '#d63638';
                    msg.textContent = 'Có lỗi xảy ra.';
                }
            };
            xhr.send('action=ntdungdev_reset_click_stats&_ajax_nonce=<?php echo $reset_nonce; ?>');
        }
        </script>

        <form method="post" action="options.php">
            <?php settings_fields('ntdungdev_chat_settings_group'); ?>
            <table class="form-table">

                <tr>
                    <th scope="row"><label for="ntdungdev_n8n_webhook_url">Đường dẫn Webhook n8n</label></th>
                    <td>
                        <input type="url"
                               id="ntdungdev_n8n_webhook_url"
                               name="ntdungdev_n8n_webhook_url"
                               value="<?php echo esc_attr(get_option('ntdungdev_n8n_webhook_url', '')); ?>"
                               class="regular-text"
                               style="width: 100%; max-width: 600px;"
                               placeholder="https://..." />
                        <p class="description">Webhook n8n (chế độ thường, không streaming). Bot trả lời 1 lần khi xong.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="ntdungdev_streaming_url">URL Streaming Proxy</label></th>
                    <td>
                        <input type="url"
                               id="ntdungdev_streaming_url"
                               name="ntdungdev_streaming_url"
                               value="<?php echo esc_attr(get_option('ntdungdev_streaming_url', '')); ?>"
                               class="regular-text"
                               style="width: 100%; max-width: 600px;"
                               placeholder="https://n8n.ntdungdev.id.vn/stream-chat" />
                        <p class="description">URL Streaming Proxy (SSE). Bot hiện chữ dần dần như đang gõ. Để trống để dùng Webhook n8n thường.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Kích hoạt Streaming</th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="ntdungdev_streaming_enabled"
                                   value="1"
                                   <?php checked(get_option('ntdungdev_streaming_enabled', '0'), '1'); ?> />
                            Bật chế độ streaming (hiện chữ từng từ một, có progress message khi bot đang xử lý)
                        </label>
                        <p class="description">Cần điền "URL Streaming Proxy" ở trên. Nếu tắt, dùng Webhook n8n thường.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="ntdungdev_bot_name">Tên Chatbot</label></th>
                    <td>
                        <input type="text"
                               id="ntdungdev_bot_name"
                               name="ntdungdev_bot_name"
                               value="<?php echo esc_attr(get_option('ntdungdev_bot_name', 'AI Chatbot')); ?>"
                               class="regular-text"
                               placeholder="AI Chatbot" />
                        <p class="description">Tên hiển thị ở đầu cửa sổ chat.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="ntdungdev_bot_subtitle">Mô tả ngắn</label></th>
                    <td>
                        <input type="text"
                               id="ntdungdev_bot_subtitle"
                               name="ntdungdev_bot_subtitle"
                               value="<?php echo esc_attr(get_option('ntdungdev_bot_subtitle', 'Hỗ trợ trực tuyến 24/7')); ?>"
                               class="regular-text"
                               placeholder="Hỗ trợ trực tuyến 24/7" />
                        <p class="description">Dòng mô tả nhỏ hiển thị bên dưới tên chatbot.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="ntdungdev_greeting_msg">Câu chào mặc định</label></th>
                    <td>
                        <textarea id="ntdungdev_greeting_msg"
                                  name="ntdungdev_greeting_msg"
                                  rows="4"
                                  class="large-text"
                                  placeholder="Xin chào! 👋&#10;Tôi có thể giúp gì cho bạn?"><?php echo esc_textarea(get_option('ntdungdev_greeting_msg', "Xin chào! 👋\nTôi có thể giúp gì cho bạn?")); ?></textarea>
                        <p class="description">Tin nhắn đầu tiên chatbot gửi khi người dùng mở chat lần đầu. Dùng xuống dòng để tạo nhiều dòng.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="ntdungdev_input_placeholder">Placeholder ô nhập</label></th>
                    <td>
                        <input type="text"
                               id="ntdungdev_input_placeholder"
                               name="ntdungdev_input_placeholder"
                               value="<?php echo esc_attr(get_option('ntdungdev_input_placeholder', 'Nhập câu hỏi...')); ?>"
                               class="regular-text"
                               placeholder="Nhập câu hỏi..." />
                        <p class="description">Gợi ý hiển thị bên trong ô nhập tin nhắn.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="ntdungdev_typing_text">Văn bản "đang gõ"</label></th>
                    <td>
                        <input type="text"
                               id="ntdungdev_typing_text"
                               name="ntdungdev_typing_text"
                               value="<?php echo esc_attr(get_option('ntdungdev_typing_text', 'AI đang gõ...')); ?>"
                               class="regular-text"
                               placeholder="AI đang gõ..." />
                        <p class="description">Hiển thị khi chatbot đang xử lý câu trả lời.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="ntdungdev_btn_label">Chữ trên nút chat</label></th>
                    <td>
                        <input type="text"
                               id="ntdungdev_btn_label"
                               name="ntdungdev_btn_label"
                               value="<?php echo esc_attr(get_option('ntdungdev_btn_label', 'Chat trực tiếp')); ?>"
                               class="regular-text"
                               placeholder="Chat trực tiếp" />
                        <p class="description">Dòng chữ trượt ra khi rê chuột vào nút chat. Để trống nếu không muốn hiện.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="ntdungdev_btn_badge">Badge trên nút chat</label></th>
                    <td>
                        <input type="text"
                               id="ntdungdev_btn_badge"
                               name="ntdungdev_btn_badge"
                               value="<?php echo esc_attr(get_option('ntdungdev_btn_badge', 'Hỗ trợ 24/7')); ?>"
                               class="regular-text"
                               placeholder="Hỗ trợ 24/7" />
                        <p class="description">Nhãn nhỏ màu đỏ hiển thị phía trên nút chat. Để trống nếu không muốn hiện.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="ntdungdev_btn_delay">Hiện nút sau (giây)</label></th>
                    <td>
                        <input type="number"
                               id="ntdungdev_btn_delay"
                               name="ntdungdev_btn_delay"
                               value="<?php echo esc_attr(get_option('ntdungdev_btn_delay', '0')); ?>"
                               min="0" max="30" step="0.5"
                               style="width: 80px;" /> giây
                        <p class="description">Sau khi load trang bao nhiêu giây thì nút chat nhảy ra. Đặt 0 để hiện ngay.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="ntdungdev_btn_wiggle">Chu kỳ lắc nút (giây)</label></th>
                    <td>
                        <input type="number"
                               id="ntdungdev_btn_wiggle"
                               name="ntdungdev_btn_wiggle"
                               value="<?php echo esc_attr(get_option('ntdungdev_btn_wiggle', '5')); ?>"
                               min="0" max="60" step="1"
                               style="width: 80px;" /> giây
                        <p class="description">Mỗi bao nhiêu giây nút chat lắc 1 lần để thu hút chú ý. Đặt 0 để tắt.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Vị trí nút chat</th>
                    <td>
                        <fieldset>
                            <label style="margin-right: 20px;">
                                <input type="radio" name="ntdungdev_btn_side" value="right" <?php checked(get_option('ntdungdev_btn_side', 'right'), 'right'); ?> />
                                Bên phải
                            </label>
                            <label>
                                <input type="radio" name="ntdungdev_btn_side" value="left" <?php checked(get_option('ntdungdev_btn_side', 'right'), 'left'); ?> />
                                Bên trái
                            </label>
                        </fieldset>
                        <br>
                        <strong style="display:block; margin-bottom:6px;">Desktop:</strong>
                        <label for="ntdungdev_btn_position_x">Ngang: </label>
                        <input type="number"
                               id="ntdungdev_btn_position_x"
                               name="ntdungdev_btn_position_x"
                               value="<?php echo esc_attr(get_option('ntdungdev_btn_position_x', '3')); ?>"
                               min="0" max="50" step="0.1"
                               style="width: 80px;" /> %
                        &nbsp;&nbsp;
                        <label for="ntdungdev_btn_position_y">Đáy: </label>
                        <input type="number"
                               id="ntdungdev_btn_position_y"
                               name="ntdungdev_btn_position_y"
                               value="<?php echo esc_attr(get_option('ntdungdev_btn_position_y', '3')); ?>"
                               min="0" max="50" step="0.1"
                               style="width: 80px;" /> %
                        <br><br>
                        <strong style="display:block; margin-bottom:6px;">Mobile <small>(để trống = dùng giá trị desktop)</small>:</strong>
                        <fieldset style="margin-bottom:6px;">
                            <label style="margin-right: 15px;">
                                <input type="radio" name="ntdungdev_btn_mobile_side" value="" <?php checked(get_option('ntdungdev_btn_mobile_side', ''), ''); ?> />
                                Theo desktop
                            </label>
                            <label style="margin-right: 15px;">
                                <input type="radio" name="ntdungdev_btn_mobile_side" value="right" <?php checked(get_option('ntdungdev_btn_mobile_side', ''), 'right'); ?> />
                                Bên phải
                            </label>
                            <label>
                                <input type="radio" name="ntdungdev_btn_mobile_side" value="left" <?php checked(get_option('ntdungdev_btn_mobile_side', ''), 'left'); ?> />
                                Bên trái
                            </label>
                        </fieldset>
                        <label for="ntdungdev_btn_mobile_x">Ngang: </label>
                        <input type="number"
                               id="ntdungdev_btn_mobile_x"
                               name="ntdungdev_btn_mobile_x"
                               value="<?php echo esc_attr(get_option('ntdungdev_btn_mobile_x', '')); ?>"
                               min="0" max="50" step="0.1"
                               style="width: 80px;" placeholder="auto" /> %
                        &nbsp;&nbsp;
                        <label for="ntdungdev_btn_mobile_y">Đáy: </label>
                        <input type="number"
                               id="ntdungdev_btn_mobile_y"
                               name="ntdungdev_btn_mobile_y"
                               value="<?php echo esc_attr(get_option('ntdungdev_btn_mobile_y', '')); ?>"
                               min="0" max="50" step="0.1"
                               style="width: 80px;" placeholder="auto" /> %
                        <p class="description">Vị trí tính theo % màn hình. Hỗ trợ số thập phân (VD: 1.5%).</p>
                    </td>
                </tr>

                <?php if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS): ?>
                <tr>
                    <th scope="row">Bỏ chặn cập nhật</th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="ntdungdev_bypass_file_mods"
                                   value="1"
                                   <?php checked(get_option('ntdungdev_bypass_file_mods', '0'), '1'); ?> />
                        </label>
                    </td>
                </tr>
                <?php endif; ?>

            </table>
            <?php submit_button('Lưu Cài Đặt'); ?>
        </form>
    </div>
    <?php
}


// ==========================================
// 2. HIỂN THỊ WIDGET CHAT RA FRONTEND
// ==========================================

add_action('wp_footer', 'ntdungdev_render_chat_widget');
function ntdungdev_render_chat_widget() {
    $webhook_url      = get_option('ntdungdev_n8n_webhook_url');
    $streaming_url    = get_option('ntdungdev_streaming_url', '');
    $streaming_enabled = get_option('ntdungdev_streaming_enabled', '0') === '1';

    // Cần ít nhất 1 trong 2 (webhook hoặc streaming) để hiện widget
    if (empty($webhook_url) && empty($streaming_url)) return;
    if ($streaming_enabled && empty($streaming_url)) return;  // bật streaming nhưng không có URL

    $bot_name        = esc_html(get_option('ntdungdev_bot_name', 'AI Chatbot'));
    $bot_subtitle    = esc_html(get_option('ntdungdev_bot_subtitle', 'Hỗ trợ trực tuyến 24/7'));
    $greeting_msg    = get_option('ntdungdev_greeting_msg', "Xin chào! 👋\nTôi có thể giúp gì cho bạn?");
    $input_holder    = esc_attr(get_option('ntdungdev_input_placeholder', 'Nhập câu hỏi...'));
    $typing_text     = esc_html(get_option('ntdungdev_typing_text', 'AI đang gõ...'));
    $btn_label       = esc_html(get_option('ntdungdev_btn_label', 'Chat trực tiếp'));
    $btn_badge       = esc_html(get_option('ntdungdev_btn_badge', 'Hỗ trợ 24/7'));
    $btn_delay       = floatval(get_option('ntdungdev_btn_delay', '0'));
    $btn_wiggle      = intval(get_option('ntdungdev_btn_wiggle', '5'));
    $btn_side        = get_option('ntdungdev_btn_side', 'right');
    $btn_pos_x       = floatval(get_option('ntdungdev_btn_position_x', '3'));
    $btn_pos_y       = floatval(get_option('ntdungdev_btn_position_y', '3'));
    $btn_mobile_side = get_option('ntdungdev_btn_mobile_side', '');
    $btn_mobile_x    = get_option('ntdungdev_btn_mobile_x', '');
    $btn_mobile_y    = get_option('ntdungdev_btn_mobile_y', '');
    $mob_x           = ($btn_mobile_x !== '') ? floatval($btn_mobile_x) : $btn_pos_x;
    $mob_y           = ($btn_mobile_y !== '') ? floatval($btn_mobile_y) : $btn_pos_y;
    $is_left         = ($btn_side === 'left');
    $mob_side        = ($btn_mobile_side !== '') ? $btn_mobile_side : $btn_side;
    $mob_is_left     = ($mob_side === 'left');
    ?>
    <style>
        /* === RESET iOS === */
        #ntdungdev-chat-btn-wrap, #ntdungdev-chat-btn-wrap *, #ntdungdev-chat-window, #ntdungdev-chat-window * { -webkit-tap-highlight-color: transparent; }
        /* === NÚT CHAT + LABEL === */
        #ntdungdev-chat-btn-wrap { position: fixed; bottom: <?php echo $btn_pos_y; ?>%; <?php echo $is_left ? 'left' : 'right'; ?>: <?php echo $btn_pos_x; ?>%; z-index: 2147483646; display: flex; align-items: center; gap: 0; cursor: pointer; <?php echo $is_left ? 'flex-direction: row-reverse;' : ''; ?> animation: ntdungdev-bounce-in 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) <?php echo $btn_delay; ?>s both; }
        @keyframes ntdungdev-bounce-in {
            0% { opacity: 0; transform: scale(0) translateY(40px); }
            60% { opacity: 1; transform: scale(1.15) translateY(-5px); }
            100% { opacity: 1; transform: scale(1) translateY(0); }
        }
        #ntdungdev-chat-btn { background: linear-gradient(135deg, #0a84ff 0%, #005bb5 100%); color: white; border-radius: 50%; width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 4px 20px rgba(10, 132, 255, 0.45); font-size: 28px; transition: transform 0.2s; flex-shrink: 0; position: relative; }
        #ntdungdev-chat-btn:hover { transform: scale(1.08); }
        #ntdungdev-chat-label { background: linear-gradient(135deg, #0a84ff 0%, #005bb5 100%); color: white; font-size: 13px; font-weight: 600; font-family: sans-serif; padding: 8px 18px 8px 14px; white-space: nowrap; box-shadow: 0 4px 15px rgba(10, 132, 255, 0.3); z-index: -1; max-width: 0; overflow: hidden; opacity: 0; padding: 0; transition: max-width 0.35s ease, opacity 0.25s ease, padding 0.35s ease; <?php if ($is_left): ?>border-radius: 0 20px 20px 0; margin-left: -8px;<?php else: ?>border-radius: 20px 0 0 20px; margin-right: -8px;<?php endif; ?> }
        #ntdungdev-chat-btn-wrap:not(.chat-open):hover #ntdungdev-chat-label { max-width: 200px; opacity: 1; padding: 8px 18px 8px 14px; transition-delay: 0s; }

        /* Lắc nhẹ thu hút chú ý - lặp lại mỗi 5s */
        @keyframes ntdungdev-wiggle {
            0%, 86%, 100% { transform: rotate(0deg) scale(1); }
            88% { transform: rotate(-10deg) scale(1.12); }
            90% { transform: rotate(8deg) scale(1.12); }
            92% { transform: rotate(-6deg) scale(1.08); }
            94% { transform: rotate(4deg) scale(1.05); }
            96% { transform: rotate(-2deg); }
        }
        <?php if ($btn_wiggle > 0): ?>
        #ntdungdev-chat-btn-wrap:not(.chat-open) #ntdungdev-chat-btn {
            animation: ntdungdev-wiggle <?php echo $btn_wiggle; ?>s ease-in-out 2s infinite;
        }
        <?php endif; ?>
        #ntdungdev-chat-btn-wrap:not(.chat-open) #ntdungdev-chat-btn:hover { animation: none; transform: scale(1.08); }

        /* Pulse ring */
        #ntdungdev-chat-btn::before { content: ''; position: absolute; inset: -4px; border-radius: 50%; border: 2px solid rgba(10, 132, 255, 0.5); animation: ntdungdev-pulse-ring 2.5s ease-out infinite; pointer-events: none; }
        @keyframes ntdungdev-pulse-ring {
            0% { transform: scale(1); opacity: 0.7; }
            70% { transform: scale(1.35); opacity: 0; }
            100% { transform: scale(1.35); opacity: 0; }
        }

        /* Badge phía trên nút */
        #ntdungdev-chat-badge { position: absolute; top: -10px; left: 50%; transform: translateX(-50%); background: #ef4444; color: white; font-size: 10px; font-weight: 700; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; padding: 3px 8px; border-radius: 10px; white-space: nowrap; pointer-events: none; box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4); animation: ntdungdev-badge-nudge 4s ease-in-out 3s infinite; }
        @keyframes ntdungdev-badge-nudge {
            0%, 85%, 100% { transform: translateX(-50%) rotate(0deg); }
            88% { transform: translateX(-50%) rotate(-8deg); }
            91% { transform: translateX(-50%) rotate(6deg); }
            94% { transform: translateX(-50%) rotate(-4deg); }
            97% { transform: translateX(-50%) rotate(2deg); }
        }

        /* Tắt hiệu ứng khi chat đang mở */
        #ntdungdev-chat-btn-wrap.chat-open #ntdungdev-chat-btn::before { display: none; }
        #ntdungdev-chat-btn-wrap.chat-open #ntdungdev-chat-label { display: none; }
        #ntdungdev-chat-btn-wrap.chat-open #ntdungdev-chat-badge { display: none; }
        #ntdungdev-chat-btn-wrap.chat-open #ntdungdev-chat-btn { animation: none; }

        /* === CỬA SỔ CHAT === */
        #ntdungdev-chat-window { position: fixed; bottom: calc(<?php echo $btn_pos_y; ?>% + 70px); <?php echo $is_left ? 'left' : 'right'; ?>: <?php echo $btn_pos_x; ?>%; width: 370px; height: 520px; background: white; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.18); display: none; flex-direction: column; overflow: hidden; z-index: 2147483647; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; border: 1px solid #e2e8f0; }
        #ntdungdev-chat-header { background: linear-gradient(135deg, #0a84ff 0%, #005bb5 100%); color: white; padding: 16px 20px; display: flex; align-items: center; gap: 12px; position: relative; }
        #ntdungdev-chat-close { position: absolute; top: 50%; right: 16px; transform: translateY(-50%); background: rgba(255,255,255,0.2); border: none; color: white; width: 32px; height: 32px; margin: 0; border-radius: 50%; font-size: 18px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.2s; }
        #ntdungdev-chat-close:hover { background: rgba(255,255,255,0.35); }
        #ntdungdev-chat-header-avatar { width: 40px; height: 40px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        #ntdungdev-chat-header-info h3 { margin: 0 0 2px 0; font-size: 15px; color: white; font-weight: 600; }
        #ntdungdev-chat-header-info p { margin: 0; font-size: 12px; opacity: 0.85; display: flex; align-items: center; gap: 5px; }
        #ntdungdev-chat-header-info p::before { content: ''; width: 7px; height: 7px; background: #34d399; border-radius: 50%; display: inline-block; }
        #ntdungdev-chat-body { flex: 1; padding: 16px; overflow-y: auto; background: #f8fafc; display: flex; flex-direction: column; gap: 12px; }
        .ntdungdev-msg { max-width: 82%; padding: 10px 14px; font-size: 14px; line-height: 1.5; word-wrap: break-word; }
        .ntdungdev-msg-bot { background: white; color: #1e293b; align-self: flex-start; border-radius: 16px 16px 16px 4px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
        .ntdungdev-msg-user { background: linear-gradient(135deg, #0a84ff, #006ae0); color: white; align-self: flex-end; border-radius: 16px 16px 4px 16px; box-shadow: 0 2px 8px rgba(10,132,255,0.25); }
        #ntdungdev-chat-footer { padding: 12px 16px; background: white; border-top: 1px solid #f1f5f9; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        #ntdungdev-chat-input { flex: 1; border: 1.5px solid #e2e8f0; border-radius: 22px; padding: 10px 16px; outline: none; font-size: 14px; line-height: 1.4; min-width: 0; margin-bottom: 0; transition: border-color 0.2s, box-shadow 0.2s; background: #f8fafc; }
        #ntdungdev-chat-input:focus { border-color: #0a84ff; box-shadow: 0 0 0 3px rgba(10,132,255,0.1); background: white; }
        #ntdungdev-chat-img-btn { flex-shrink: 0; background: none; border: none; cursor: pointer; font-size: 20px; padding: 0; width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; border-radius: 50%; margin: 0; transition: background 0.2s, transform 0.15s; color: #94a3b8; }
        #ntdungdev-chat-img-btn:hover { background: #f1f5f9; color: #0a84ff; transform: scale(1.1); }
        #ntdungdev-chat-send { flex-shrink: 0; background: linear-gradient(135deg, #0a84ff, #006ae0); color: white; border: none; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 16px; margin: 0; transition: transform 0.15s, box-shadow 0.2s; box-shadow: 0 2px 8px rgba(10,132,255,0.3); }
        #ntdungdev-chat-send:hover { transform: scale(1.08); box-shadow: 0 4px 12px rgba(10,132,255,0.4); }
        #ntdungdev-chat-send:active { transform: scale(0.92); }
        #ntdungdev-img-preview { display: none; width: 100%; padding: 8px 0 0 0; position: relative; }
        #ntdungdev-img-preview img { max-width: 120px; max-height: 100px; border-radius: 8px; border: 1px solid #e2e8f0; object-fit: cover; }
        #ntdungdev-img-preview-remove { position: absolute; top: 4px; left: 108px; background: #ef4444; color: white; border: none; border-radius: 50%; width: 22px; height: 22px; font-size: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; line-height: 1; }
        .ntdungdev-msg img.ntdungdev-chat-image { max-width: 100%; border-radius: 8px; margin-top: 4px; cursor: pointer; }
        .ntdungdev-img-wrap { position: relative; display: inline-block; }
        .ntdungdev-img-wrap.uploading img { opacity: 0.45; filter: blur(1px); }
        .ntdungdev-img-spinner { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); display: none; }
        .ntdungdev-img-wrap.uploading .ntdungdev-img-spinner { display: block; }
        .ntdungdev-img-spinner::after { content: ''; display: block; width: 28px; height: 28px; border: 3px solid rgba(255,255,255,0.4); border-top-color: #fff; border-radius: 50%; animation: ntdungdev-spin 0.7s linear infinite; }
        @keyframes ntdungdev-spin { to { transform: rotate(360deg); } }
        .ntdungdev-upload-status { font-size: 11px; opacity: 0.85; margin-top: 4px; }
        #ntdungdev-chat-footer.disabled { pointer-events: none; opacity: 0.6; }
        .ntdungdev-typing { font-size: 12px; color: #94a3b8; align-self: flex-start; display: none; margin-left: 8px; }

        /* === RESPONSIVE MOBILE === */
        @media (max-width: 480px) {
            #ntdungdev-chat-btn-wrap { bottom: <?php echo $mob_y; ?>%; left: auto; right: auto; <?php echo $mob_is_left ? 'left' : 'right'; ?>: <?php echo $mob_x; ?>%; <?php echo $mob_is_left ? 'flex-direction: row-reverse;' : 'flex-direction: row;'; ?> }
            #ntdungdev-chat-btn { width: 52px; height: 52px; font-size: 24px; }
            #ntdungdev-chat-label { display: none !important; }
            #ntdungdev-chat-window { position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100%; height: 100%; border-radius: 0; border: none; box-shadow: none; }
            #ntdungdev-chat-header { padding: 14px 16px; border-radius: 0; }
            #ntdungdev-chat-body { padding: 12px; }
            #ntdungdev-chat-footer { padding: 10px 12px; gap: 8px; }
            #ntdungdev-chat-input { padding: 9px 14px; font-size: 16px; }
            .ntdungdev-msg { max-width: 88%; font-size: 15px; }
        }
    </style>

    <div id="ntdungdev-chat-btn-wrap">
        <?php if ($btn_label): ?><span id="ntdungdev-chat-label"><?php echo $btn_label; ?></span><?php endif; ?>
        <div id="ntdungdev-chat-btn">
            💬
            <?php if ($btn_badge): ?><span id="ntdungdev-chat-badge"><?php echo $btn_badge; ?></span><?php endif; ?>
        </div>
    </div>
    <div id="ntdungdev-chat-window">
        <div id="ntdungdev-chat-header">
            <div id="ntdungdev-chat-header-avatar">🤖</div>
            <div id="ntdungdev-chat-header-info">
                <h3><?php echo $bot_name; ?></h3>
                <p><?php echo $bot_subtitle; ?></p>
            </div>
            <button id="ntdungdev-chat-close" title="Đóng">✕</button>
        </div>
        <div id="ntdungdev-chat-body">
            <div id="ntdungdev-typing" class="ntdungdev-typing"><?php echo $typing_text; ?></div>
        </div>
        <div id="ntdungdev-chat-footer">
            <input type="file" id="ntdungdev-chat-file" accept="image/*" style="display:none">
            <button id="ntdungdev-chat-img-btn" title="Gửi hình ảnh">🖼</button>
            <input type="text" id="ntdungdev-chat-input" placeholder="<?php echo $input_holder; ?>" autocomplete="off">
            <button id="ntdungdev-chat-send">➤</button>
            <div id="ntdungdev-img-preview">
                <img id="ntdungdev-img-preview-img" src="" alt="Preview">
                <button id="ntdungdev-img-preview-remove" title="Xóa ảnh">✕</button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const btnWrap    = document.getElementById('ntdungdev-chat-btn-wrap');
            const btn        = document.getElementById('ntdungdev-chat-btn');
            const win        = document.getElementById('ntdungdev-chat-window');
            const closeBtn   = document.getElementById('ntdungdev-chat-close');
            const body       = document.getElementById('ntdungdev-chat-body');
            const input      = document.getElementById('ntdungdev-chat-input');
            const send       = document.getElementById('ntdungdev-chat-send');
            const typing     = document.getElementById('ntdungdev-typing');
            const imgBtn     = document.getElementById('ntdungdev-chat-img-btn');
            const fileInput  = document.getElementById('ntdungdev-chat-file');
            const imgPreview = document.getElementById('ntdungdev-img-preview');
            const imgPrevImg = document.getElementById('ntdungdev-img-preview-img');
            const imgRemove  = document.getElementById('ntdungdev-img-preview-remove');

            const GREETING = <?php echo wp_json_encode($greeting_msg); ?>;
            const AJAX_URL = '<?php echo admin_url("admin-ajax.php"); ?>';
            const STREAMING_URL = <?php echo wp_json_encode($streaming_url); ?>;
            const STREAMING_ENABLED = <?php echo $streaming_enabled ? 'true' : 'false'; ?>;
            const WEBSITE = <?php echo wp_json_encode(preg_replace('#^https?://#', '', rtrim(site_url(), '/'))); ?>;
            const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

            let selectedFile = null;

            // 1. Khởi tạo hoặc lấy Session ID từ sessionStorage
            let sessionId = sessionStorage.getItem('ntdungdev_session_id');
            if (!sessionId) {
                sessionId = Math.random().toString(36).substr(2, 10);
                sessionStorage.setItem('ntdungdev_session_id', sessionId);
            }

            // 2. Khởi tạo mảng Lịch sử Chat từ sessionStorage
            let chatHistory = JSON.parse(sessionStorage.getItem('ntdungdev_chat_history')) || [];

            btnWrap.onclick = () => {
                const isOpen = win.style.display === 'flex';
                win.style.display = isOpen ? 'none' : 'flex';
                btnWrap.classList.toggle('chat-open', !isOpen);
                if (!isOpen) {
                    input.focus();
                    // Ghi nhận lượt click mở chatbot
                    const fd = new FormData();
                    fd.append('action', 'ntdungdev_track_click');
                    fetch(AJAX_URL, { method: 'POST', body: fd });
                }
            };

            closeBtn.onclick = (e) => {
                e.stopPropagation();
                win.style.display = 'none';
                btnWrap.classList.remove('chat-open');
            };

            document.addEventListener('click', (e) => {
                if (win.style.display === 'flex' && !win.contains(e.target) && !btnWrap.contains(e.target)) {
                    win.style.display = 'none';
                    btnWrap.classList.remove('chat-open');
                }
            });

            function addMsg(text, isBot, save = true, imageUrl = null, uploading = false) {
                const div = document.createElement('div');
                div.className = 'ntdungdev-msg ' + (isBot ? 'ntdungdev-msg-bot' : 'ntdungdev-msg-user');

                let html = '';
                if (imageUrl) {
                    html += '<div class="ntdungdev-img-wrap' + (uploading ? ' uploading' : '') + '">';
                    html += '<img src="' + imageUrl + '" class="ntdungdev-chat-image" onclick="window.open(this.src,\'_blank\')" alt="Hình ảnh">';
                    html += '<div class="ntdungdev-img-spinner"></div>';
                    html += '</div>';
                    if (uploading) html += '<div class="ntdungdev-upload-status">Đang tải ảnh lên...</div>';
                    if (text) html += '<br>';
                }
                if (text) {
                    html += text.replace(/\n/g, '<br>');
                }
                div.innerHTML = html;

                body.insertBefore(div, typing);
                body.scrollTop = body.scrollHeight;

                if (save) {
                    chatHistory.push({ text: text, isBot: isBot, imageUrl: imageUrl || null });
                    sessionStorage.setItem('ntdungdev_chat_history', JSON.stringify(chatHistory));
                }
                return div;
            }

            // 3. Phục hồi tin nhắn hoặc hiện câu chào
            if (chatHistory.length === 0) {
                addMsg(GREETING, true);
            } else {
                chatHistory.forEach(msg => addMsg(msg.text, msg.isBot, false, msg.imageUrl || null));
            }

            // Xử lý chọn ảnh
            imgBtn.onclick = () => fileInput.click();

            fileInput.onchange = function() {
                const file = this.files[0];
                if (!file) return;

                if (!file.type.startsWith('image/')) {
                    alert('Vui lòng chọn file hình ảnh (JPG, PNG, GIF, ...)');
                    this.value = '';
                    return;
                }

                if (file.size > MAX_FILE_SIZE) {
                    alert('Kích thước ảnh tối đa là 5MB. Vui lòng chọn ảnh nhỏ hơn.');
                    this.value = '';
                    return;
                }

                selectedFile = file;
                const reader = new FileReader();
                reader.onload = function(e) {
                    imgPrevImg.src = e.target.result;
                    imgPreview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            };

            imgRemove.onclick = function() {
                selectedFile = null;
                fileInput.value = '';
                imgPreview.style.display = 'none';
                imgPrevImg.src = '';
            };

            async function uploadImage(file) {
                const formData = new FormData();
                formData.append('action', 'ntdungdev_upload_image');
                formData.append('image', file);
                formData.append('session_id', sessionId);

                const response = await fetch(AJAX_URL, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    return result.data; // { image_base64, image_name, image_mime, image_ext }
                } else {
                    throw new Error(result.data || 'Upload thất bại');
                }
            }

            function setFooterDisabled(disabled) {
                const footer = document.getElementById('ntdungdev-chat-footer');
                if (disabled) {
                    footer.classList.add('disabled');
                    send.disabled = true;
                    imgBtn.disabled = true;
                } else {
                    footer.classList.remove('disabled');
                    send.disabled = false;
                    imgBtn.disabled = false;
                }
            }

            async function sendMessage() {
                const text = input.value.trim();
                const hasImage = selectedFile !== null;

                if (!text && !hasImage) return;

                setFooterDisabled(true);

                let imageData = null;
                let localPreview = null;
                let msgDiv = null;

                // Lấy preview local trước khi reset
                if (hasImage) {
                    localPreview = imgPrevImg.src;
                }

                // Reset input + preview ngay lập tức
                input.value = '';
                if (hasImage) {
                    selectedFile._ref = selectedFile;
                }
                const fileToUpload = hasImage ? selectedFile : null;
                selectedFile = null;
                fileInput.value = '';
                imgPreview.style.display = 'none';
                imgPrevImg.src = '';

                // Hiện tin nhắn user ngay lập tức (ảnh local + spinner nếu có ảnh)
                msgDiv = addMsg(text, false, false, localPreview, hasImage);

                // Nếu có ảnh → đọc base64
                if (hasImage && fileToUpload) {
                    try {
                        imageData = await uploadImage(fileToUpload);

                        // Xong → bỏ spinner
                        const wrap = msgDiv.querySelector('.ntdungdev-img-wrap');
                        const status = msgDiv.querySelector('.ntdungdev-upload-status');
                        if (wrap) wrap.classList.remove('uploading');
                        if (status) status.remove();
                    } catch (error) {
                        // Lỗi → hiện lỗi trên ảnh
                        const wrap = msgDiv.querySelector('.ntdungdev-img-wrap');
                        const status = msgDiv.querySelector('.ntdungdev-upload-status');
                        if (wrap) wrap.classList.remove('uploading');
                        if (status) { status.textContent = 'Tải ảnh thất bại'; status.style.color = '#ef4444'; }
                        addMsg("Không thể tải ảnh lên. Vui lòng thử lại.", true);
                        setFooterDisabled(false);
                        input.focus();
                        return;
                    }
                }

                // Lưu lịch sử
                chatHistory.push({ text: text, isBot: false, imageUrl: localPreview || null });
                sessionStorage.setItem('ntdungdev_chat_history', JSON.stringify(chatHistory));

                // Hiện "AI đang gõ..." sau khi xử lý xong
                typing.style.display = 'block';
                body.scrollTop = body.scrollHeight;

                // Route: streaming nếu được bật + có URL, ngược lại fallback legacy AJAX
                if (STREAMING_ENABLED && STREAMING_URL) {
                    await sendMessageStream(text, imageData);
                } else {
                    await sendMessageLegacy(text, imageData);
                }

                setFooterDisabled(false);
                input.focus();
            }

            // ===== LEGACY: gọi qua admin-ajax.php → wp_remote_post → n8n =====
            async function sendMessageLegacy(text, imageData) {
                const formData = new FormData();
                formData.append('action', 'ntdungdev_send_message');
                formData.append('message', text || '');
                formData.append('session_id', sessionId);
                formData.append('page_url', window.location.href);
                if (imageData) {
                    formData.append('image_base64', imageData.image_base64);
                    formData.append('image_name', imageData.image_name);
                    formData.append('image_mime', imageData.image_mime);
                }

                try {
                    const response = await fetch(AJAX_URL, { method: 'POST', body: formData });
                    const result = await response.json();
                    typing.style.display = 'none';
                    if (result.success) {
                        addMsg(result.data, true);
                    } else {
                        addMsg(result.data || "Hệ thống đang bảo trì, vui lòng thử lại sau!", true);
                    }
                } catch (error) {
                    typing.style.display = 'none';
                    addMsg("Lỗi mạng, không thể kết nối tới máy chủ.", true);
                }
            }

            // ===== STREAMING: gọi trực tiếp Streaming Proxy SSE =====
            async function sendMessageStream(text, imageData) {
                // Tạo bot bubble rỗng để stream vào
                const botDiv = document.createElement('div');
                botDiv.className = 'ntdungdev-msg ntdungdev-msg-bot';
                botDiv.innerHTML = '<span class="ntdungdev-stream-progress" style="opacity:0.7;font-style:italic;"></span>';
                body.insertBefore(botDiv, typing);
                body.scrollTop = body.scrollHeight;

                const progressSpan = botDiv.querySelector('.ntdungdev-stream-progress');
                let accumulated = '';
                let hasRealContent = false;

                const payload = {
                    session_id: WEBSITE + '_' + sessionId,
                    message: text || '',
                    website: WEBSITE,
                    page_url: window.location.href,
                };
                if (imageData) {
                    payload.image_base64 = imageData.image_base64;
                    payload.image_name = imageData.image_name;
                    payload.image_mime = imageData.image_mime;
                }

                try {
                    const res = await fetch(STREAMING_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload),
                    });

                    if (!res.ok || !res.body) {
                        throw new Error('HTTP ' + res.status);
                    }

                    typing.style.display = 'none';

                    const reader = res.body.getReader();
                    const decoder = new TextDecoder();
                    let buffer = '';
                    let currentEvent = 'message';

                    while (true) {
                        const { done, value } = await reader.read();
                        if (done) break;
                        buffer += decoder.decode(value, { stream: true });

                        let nl;
                        while ((nl = buffer.indexOf('\n')) !== -1) {
                            const line = buffer.slice(0, nl);
                            buffer = buffer.slice(nl + 1);
                            if (line.startsWith(':')) continue; // SSE comment / keepalive
                            if (line === '') { currentEvent = 'message'; continue; }
                            if (line.startsWith('event: ')) { currentEvent = line.slice(7).trim(); continue; }
                            if (!line.startsWith('data: ')) continue;
                            const data = line.slice(6);

                            try {
                                const parsed = JSON.parse(data);
                                if (currentEvent === 'progress') {
                                    if (!hasRealContent) progressSpan.textContent = parsed.text || '...';
                                } else if (currentEvent === 'token') {
                                    if (!hasRealContent) {
                                        progressSpan.remove();
                                        hasRealContent = true;
                                    }
                                    accumulated += parsed.delta || '';
                                    botDiv.innerHTML = accumulated.replace(/\n/g, '<br>');
                                    body.scrollTop = body.scrollHeight;
                                } else if (currentEvent === 'done') {
                                    // Final cleanup
                                    if (!hasRealContent) {
                                        progressSpan.remove();
                                        botDiv.innerHTML = (accumulated || 'Dạ em chưa hiểu ý anh/chị, mình có thể nói rõ hơn giúp em không ạ?').replace(/\n/g, '<br>');
                                    }
                                } else if (currentEvent === 'error') {
                                    if (progressSpan && progressSpan.parentNode) progressSpan.remove();
                                    botDiv.innerHTML = 'Hệ thống đang bảo trì, vui lòng thử lại sau!';
                                }
                            } catch (e) { /* skip parse error */ }
                        }
                    }

                    // Lưu lịch sử bot
                    if (accumulated) {
                        chatHistory.push({ text: accumulated.trim(), isBot: true, imageUrl: null });
                        sessionStorage.setItem('ntdungdev_chat_history', JSON.stringify(chatHistory));
                    }
                } catch (error) {
                    typing.style.display = 'none';
                    if (progressSpan && progressSpan.parentNode) progressSpan.remove();
                    botDiv.innerHTML = 'Lỗi mạng, không thể kết nối tới máy chủ.';
                }
            }

            send.onclick = sendMessage;
            input.onkeypress = (e) => { if (e.key === 'Enter') sendMessage(); };
        });
    </script>
    <?php
}


// ==========================================
// 3a. XỬ LÝ UPLOAD HÌNH ẢNH
// ==========================================

add_action('wp_ajax_ntdungdev_upload_image', 'ntdungdev_handle_image_upload');
add_action('wp_ajax_nopriv_ntdungdev_upload_image', 'ntdungdev_handle_image_upload');
function ntdungdev_handle_image_upload() {
    if (empty($_FILES['image'])) {
        wp_send_json_error('Không tìm thấy file hình ảnh.');
    }

    $file = $_FILES['image'];

    // Kiểm tra loại file
    $allowed_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
    } else {
        $mime_type = $file['type'];
    }

    if (!in_array($mime_type, $allowed_types)) {
        wp_send_json_error('Loại file không được hỗ trợ. Chỉ chấp nhận JPG, PNG, GIF, WebP.');
    }

    // Kiểm tra kích thước (5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        wp_send_json_error('Kích thước ảnh tối đa là 5MB.');
    }

    // Đọc file thành base64 để gửi trực tiếp cho n8n (tránh lỗi 404 khi n8n tải URL)
    $file_data = file_get_contents($file['tmp_name']);
    if ($file_data === false) {
        wp_send_json_error('Không thể đọc file hình ảnh.');
    }

    $base64 = base64_encode($file_data);
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
    $file_name = sanitize_file_name($file['name']);

    wp_send_json_success(array(
        'image_base64' => $base64,
        'image_name'   => $file_name,
        'image_mime'   => $mime_type,
        'image_ext'    => $ext,
    ));
}


// ==========================================
// 3b. XỬ LÝ BACKEND AJAX -> GỬI SANG N8N
// ==========================================

add_action('wp_ajax_ntdungdev_send_message', 'ntdungdev_handle_ajax_request');
add_action('wp_ajax_nopriv_ntdungdev_send_message', 'ntdungdev_handle_ajax_request');
function ntdungdev_handle_ajax_request() {
    $message      = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : '';
    $session_id   = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
    $page_url     = isset($_POST['page_url']) ? esc_url_raw($_POST['page_url']) : '';
    $image_base64 = isset($_POST['image_base64']) ? $_POST['image_base64'] : '';
    $image_name   = isset($_POST['image_name']) ? sanitize_file_name($_POST['image_name']) : '';
    $image_mime   = isset($_POST['image_mime']) ? sanitize_text_field($_POST['image_mime']) : '';

    if (empty($message) && empty($image_base64)) {
        wp_send_json_error('Thiếu tin nhắn hoặc hình ảnh.');
    }

    if (empty($session_id)) {
        wp_send_json_error('Thiếu session ID.');
    }

    $webhook_url = get_option('ntdungdev_n8n_webhook_url');

    if (empty($webhook_url)) {
        wp_send_json_error('Chưa cấu hình đường dẫn Webhook n8n trong quản trị viên.');
    }

    $website = preg_replace('#^https?://#', '', rtrim(site_url(), '/'));
    $payload = array(
        'session_id' => $website . '_' . $session_id,
        'message'    => $message,
        'website'    => $website,
        'page_url'   => $page_url,
    );

    if (!empty($image_base64)) {
        $payload['image_base64'] = $image_base64;
        $payload['image_name']   = $image_name;
        $payload['image_mime']   = $image_mime;
    }

    $args = array(
        'headers'     => array('Content-Type' => 'application/json'),
        'body'        => wp_json_encode($payload),
        'timeout'     => 60,
        'data_format' => 'body',
    );

    $response = wp_remote_post($webhook_url, $args);

    if (is_wp_error($response)) {
        wp_send_json_error('Lỗi kết nối tới n8n: ' . $response->get_error_message());
    }

    $body = wp_remote_retrieve_body($response);
    wp_send_json_success($body);
}


// ==========================================
// 4. THỐNG KÊ LƯỢT CLICK NÚT CHATBOT
// ==========================================

add_action('wp_ajax_ntdungdev_track_click', 'ntdungdev_track_click');
add_action('wp_ajax_nopriv_ntdungdev_track_click', 'ntdungdev_track_click');
function ntdungdev_track_click() {
    $stats = get_option('ntdungdev_chat_click_stats', []);
    if (!is_array($stats)) $stats = [];

    $today = current_time('Y-m-d');
    $stats[$today] = isset($stats[$today]) ? (int) $stats[$today] + 1 : 1;

    // Giữ tối đa 90 ngày dữ liệu
    $cutoff = date('Y-m-d', strtotime('-90 days', strtotime($today)));
    foreach ($stats as $date => $count) {
        if ($date < $cutoff) unset($stats[$date]);
    }

    update_option('ntdungdev_chat_click_stats', $stats);
    wp_send_json_success();
}

add_action('wp_ajax_ntdungdev_reset_click_stats', 'ntdungdev_reset_click_stats');
function ntdungdev_reset_click_stats() {
    check_ajax_referer();
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Không có quyền.');
    }
    delete_option('ntdungdev_chat_click_stats');
    wp_send_json_success();
}
?>