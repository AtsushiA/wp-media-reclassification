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
        </div>
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

        $options = array(
            'dry_run' => $dry_run,
            'batch_size' => $batch_size
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

        wp_send_json_success(array(
            'results' => $results,
            'log' => $log
        ));
    }
}
