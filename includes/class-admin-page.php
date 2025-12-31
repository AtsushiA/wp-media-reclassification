<?php
/**
 * 管理画面UIクラス
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Media_Reclassification_Admin_Page {

    /**
     * コンストラクタ
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_get_media_count', array($this, 'ajax_get_media_count'));
        add_action('wp_ajax_process_reclassification', array($this, 'ajax_process_reclassification'));
        add_action('wp_ajax_delete_log_file', array($this, 'ajax_delete_log_file'));
        add_action('wp_ajax_delete_old_logs', array($this, 'ajax_delete_old_logs'));
        add_action('wp_ajax_download_log_file', array($this, 'ajax_download_log_file'));
    }

    /**
     * 管理メニューに追加
     */
    public function add_admin_menu() {
        add_management_page(
            'メディア再分類',
            'メディア再分類',
            'manage_options',
            'wp-media-reclassification',
            array($this, 'render_admin_page')
        );
    }

    /**
     * スクリプトとスタイルを読み込み
     *
     * @param string $hook 現在のページのフック名
     */
    public function enqueue_scripts($hook) {
        if ($hook !== 'tools_page_wp-media-reclassification') {
            return;
        }

        wp_enqueue_style(
            'wp-media-reclassification-admin',
            WP_MEDIA_RECLASSIFICATION_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WP_MEDIA_RECLASSIFICATION_VERSION
        );

        wp_enqueue_script(
            'wp-media-reclassification-admin',
            WP_MEDIA_RECLASSIFICATION_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            WP_MEDIA_RECLASSIFICATION_VERSION,
            true
        );

        wp_localize_script('wp-media-reclassification-admin', 'wpMediaReclassification', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_media_reclassification_nonce'),
            'strings' => array(
                'confirmStart' => '件のファイルを移動します。実行してよろしいですか？',
                'processing' => '処理中...',
                'completed' => '件の処理が完了しました。（成功: ',
                'success' => '件、エラー: ',
                'error' => '件、スキップ: ',
                'skipped' => '件）'
            )
        ));
    }

    /**
     * 管理画面をレンダリング
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('このページにアクセスする権限がありません。');
        }

        ?>
        <div class="wrap">
            <h1>メディア再分類</h1>

            <div class="notice notice-warning">
                <p><strong>重要:</strong> 実行前にデータベースとファイルのバックアップを取ることを強く推奨します。</p>
            </div>

            <div class="card">
                <h2>処理対象の設定</h2>

                <form id="media-reclassification-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="dry_run">ドライラン（テスト実行）</label>
                            </th>
                            <td>
                                <input type="checkbox" id="dry_run" name="dry_run" value="1">
                                <p class="description">実際にファイルを移動せず、移動対象と移動先を表示します。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="batch_size">バッチサイズ</label>
                            </th>
                            <td>
                                <input type="number" id="batch_size" name="batch_size" value="50" min="1" max="500" class="small-text">
                                <p class="description">一度に処理する件数（デフォルト: 50件）</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="date_from">開始日</label>
                            </th>
                            <td>
                                <input type="date" id="date_from" name="date_from">
                                <p class="description">この日付以降にアップロードされたファイルのみ処理（省略可）</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="date_to">終了日</label>
                            </th>
                            <td>
                                <input type="date" id="date_to" name="date_to">
                                <p class="description">この日付以前にアップロードされたファイルのみ処理（省略可）</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="offset">オフセット</label>
                            </th>
                            <td>
                                <input type="number" id="offset" name="offset" value="0" min="0" class="small-text">
                                <p class="description">スキップする件数（処理を中断した場合に使用）</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="enable_logging">ログ出力</label>
                            </th>
                            <td>
                                <input type="checkbox" id="enable_logging" name="enable_logging" value="1">
                                <p class="description">処理ログをファイルに出力します（エラー調査に有用）</p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="button" id="check-count-btn" class="button">対象件数を確認</button>
                        <button type="button" id="start-process-btn" class="button button-primary" disabled>処理を開始</button>
                    </p>
                </form>

                <div id="count-result" style="display: none;">
                    <h3>対象メディア件数</h3>
                    <p class="count-display">対象: <strong id="target-count">0</strong> 件</p>
                </div>

                <div id="progress-container" style="display: none;">
                    <h3>処理状況</h3>
                    <div class="progress-bar-wrapper">
                        <div class="progress-bar">
                            <div class="progress-bar-fill" style="width: 0%;"></div>
                        </div>
                        <div class="progress-text">
                            <span id="progress-current">0</span> / <span id="progress-total">0</span>
                            (<span id="progress-percentage">0</span>%)
                        </div>
                    </div>
                    <div class="progress-stats">
                        <p>
                            成功: <strong id="success-count">0</strong> 件 |
                            エラー: <strong id="error-count">0</strong> 件 |
                            スキップ: <strong id="skipped-count">0</strong> 件
                        </p>
                    </div>
                </div>

                <div id="result-container" style="display: none;">
                    <h3>処理結果</h3>
                    <div id="result-message"></div>

                    <div id="error-list" style="display: none;">
                        <h4>エラーが発生したファイル</h4>
                        <ul id="error-items"></ul>
                    </div>

                    <div id="success-list" style="display: none;">
                        <h4>移動されたファイル（最初の10件）</h4>
                        <ul id="success-items"></ul>
                    </div>
                </div>
            </div>

            <?php $this->render_log_files_section(); ?>
        </div>
        <?php
    }

    /**
     * ログファイル一覧セクションをレンダリング
     */
    private function render_log_files_section() {
        $log_files = WP_Media_Reclassification_Logger::get_all_log_files();

        if (empty($log_files)) {
            return;
        }

        ?>
        <div class="card" style="margin-top: 20px;">
            <h2>ログファイル一覧</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ファイル名</th>
                        <th>サイズ</th>
                        <th>最終更新</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($log_files as $file): ?>
                        <tr>
                            <td><?php echo esc_html($file['name']); ?></td>
                            <td><?php echo esc_html(size_format($file['size'])); ?></td>
                            <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', $file['modified'])); ?></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=download_log_file&file=' . urlencode($file['name']) . '&nonce=' . wp_create_nonce('download_log_' . $file['name']))); ?>" class="button button-small">ダウンロード</a>
                                <button class="button button-small delete-log-btn" data-file="<?php echo esc_attr($file['name']); ?>">削除</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top: 15px;">
                <button type="button" id="delete-old-logs-btn" class="button">30日以上前のログを削除</button>
            </p>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // ログファイル削除
            $('.delete-log-btn').on('click', function() {
                var fileName = $(this).data('file');
                var $row = $(this).closest('tr');

                if (!confirm('ログファイル「' + fileName + '」を削除してよろしいですか？')) {
                    return;
                }

                $.ajax({
                    url: wpMediaReclassification.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'delete_log_file',
                        nonce: wpMediaReclassification.nonce,
                        file_name: fileName
                    },
                    success: function(response) {
                        if (response.success) {
                            $row.fadeOut(300, function() {
                                $(this).remove();
                            });
                        } else {
                            alert('削除に失敗しました: ' + response.data);
                        }
                    }
                });
            });

            // 古いログを一括削除
            $('#delete-old-logs-btn').on('click', function() {
                if (!confirm('30日以上前のログファイルをすべて削除してよろしいですか？')) {
                    return;
                }

                $.ajax({
                    url: wpMediaReclassification.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'delete_old_logs',
                        nonce: wpMediaReclassification.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.deleted_count + '件のログファイルを削除しました。');
                            location.reload();
                        } else {
                            alert('削除に失敗しました: ' + response.data);
                        }
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: 対象メディア件数を取得
     */
    public function ajax_get_media_count() {
        check_ajax_referer('wp_media_reclassification_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('権限がありません。');
        }

        $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
        $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';

        $args = array();
        if (!empty($date_from)) {
            $args['date_from'] = $date_from;
        }
        if (!empty($date_to)) {
            $args['date_to'] = $date_to;
        }

        $reclassifier = new WP_Media_Reclassifier();
        $count = $reclassifier->get_total_count($args);

        wp_send_json_success(array('count' => $count));
    }

    /**
     * AJAX: 再分類処理を実行
     */
    public function ajax_process_reclassification() {
        check_ajax_referer('wp_media_reclassification_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('権限がありません。');
        }

        // タイムアウトを延長
        set_time_limit(300);

        $dry_run = isset($_POST['dry_run']) && $_POST['dry_run'] === '1';
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
        $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';
        $enable_logging = isset($_POST['enable_logging']) && $_POST['enable_logging'] === '1';

        $options = array(
            'dry_run' => $dry_run,
            'batch_size' => $batch_size,
            'enable_logging' => $enable_logging
        );

        $args = array(
            'offset' => $offset
        );

        if (!empty($date_from)) {
            $args['date_from'] = $date_from;
        }
        if (!empty($date_to)) {
            $args['date_to'] = $date_to;
        }

        $reclassifier = new WP_Media_Reclassifier($options);
        $results = $reclassifier->process_batch($args);
        $log = $reclassifier->get_log();

        // ログファイル情報を取得
        $logger = $reclassifier->get_logger();
        $log_file_info = null;
        if ($logger->is_enabled()) {
            $log_file_info = array(
                'file_name' => $logger->get_log_file_name(),
                'file_path' => $logger->get_log_file_path()
            );
        }

        wp_send_json_success(array(
            'results' => $results,
            'log' => $log,
            'log_file' => $log_file_info
        ));
    }

    /**
     * AJAX: ログファイルを削除
     */
    public function ajax_delete_log_file() {
        check_ajax_referer('wp_media_reclassification_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('権限がありません。');
        }

        $file_name = isset($_POST['file_name']) ? sanitize_file_name($_POST['file_name']) : '';

        if (empty($file_name)) {
            wp_send_json_error('ファイル名が指定されていません。');
        }

        $result = WP_Media_Reclassification_Logger::delete_log_file($file_name);

        if ($result) {
            wp_send_json_success('ログファイルを削除しました。');
        } else {
            wp_send_json_error('ログファイルの削除に失敗しました。');
        }
    }

    /**
     * AJAX: 古いログファイルを削除
     */
    public function ajax_delete_old_logs() {
        check_ajax_referer('wp_media_reclassification_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('権限がありません。');
        }

        $deleted_count = WP_Media_Reclassification_Logger::delete_old_logs(30);

        wp_send_json_success(array('deleted_count' => $deleted_count));
    }

    /**
     * AJAX: ログファイルをダウンロード
     */
    public function ajax_download_log_file() {
        if (!current_user_can('manage_options')) {
            wp_die('権限がありません。');
        }

        $file_name = isset($_GET['file']) ? sanitize_file_name($_GET['file']) : '';
        $nonce = isset($_GET['nonce']) ? $_GET['nonce'] : '';

        if (empty($file_name)) {
            wp_die('ファイル名が指定されていません。');
        }

        if (!wp_verify_nonce($nonce, 'download_log_' . $file_name)) {
            wp_die('不正なリクエストです。');
        }

        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/wp-media-reclassification-logs';
        $file_path = $log_dir . '/' . $file_name;

        if (!file_exists($file_path)) {
            wp_die('ログファイルが見つかりません。');
        }

        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    }
}
