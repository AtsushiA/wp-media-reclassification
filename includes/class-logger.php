<?php
/**
 * ログ出力クラス
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Media_Reclassification_Logger {

    /**
     * ログファイルのパス
     */
    private $log_file_path;

    /**
     * ログを有効にするか
     */
    private $enabled;

    /**
     * エラーのみログに記録するか
     */
    private $error_only;

    /**
     * ログレベル
     */
    const LEVEL_ERROR = 'ERROR';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_INFO = 'INFO';
    const LEVEL_SUCCESS = 'SUCCESS';

    /**
     * コンストラクタ
     *
     * @param bool $enabled ログを有効にするか
     * @param string $log_file_name ログファイル名（オプション）
     * @param bool $error_only エラーのみ記録するか（オプション）
     */
    public function __construct($enabled = false, $log_file_name = null, $error_only = false) {
        $this->enabled = $enabled;
        $this->error_only = $error_only;

        if ($this->enabled) {
            $upload_dir = wp_upload_dir();
            $log_dir = $upload_dir['basedir'] . '/wp-media-reclassification-logs';

            // ログディレクトリが存在しない場合は作成
            if (!file_exists($log_dir)) {
                wp_mkdir_p($log_dir);

                // .htaccessを作成してアクセスを制限
                $htaccess_file = $log_dir . '/.htaccess';
                $htaccess_content = "Order deny,allow\nDeny from all";
                file_put_contents($htaccess_file, $htaccess_content);
            }

            // ログファイル名を生成
            if ($log_file_name) {
                $this->log_file_path = $log_dir . '/' . $log_file_name;
            } else {
                $timestamp = current_time('Y-m-d_H-i-s');
                $this->log_file_path = $log_dir . '/reclassification-' . $timestamp . '.log';
            }
        }
    }

    /**
     * ログが有効かどうか
     *
     * @return bool
     */
    public function is_enabled() {
        return $this->enabled;
    }

    /**
     * ログファイルのパスを取得
     *
     * @return string|null
     */
    public function get_log_file_path() {
        return $this->log_file_path;
    }

    /**
     * ログファイル名を取得
     *
     * @return string|null
     */
    public function get_log_file_name() {
        if ($this->log_file_path) {
            return basename($this->log_file_path);
        }
        return null;
    }

    /**
     * ログを書き込み
     *
     * @param string $level ログレベル
     * @param string $message メッセージ
     * @param array $context 追加情報
     */
    public function log($level, $message, $context = array()) {
        if (!$this->enabled || !$this->log_file_path) {
            return;
        }

        // エラーのみモードの場合、エラーと警告以外はスキップ
        if ($this->error_only && $level !== self::LEVEL_ERROR && $level !== self::LEVEL_WARNING) {
            return;
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] [{$level}] {$message}";

        // コンテキスト情報があれば追加
        if (!empty($context)) {
            $log_entry .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $log_entry .= PHP_EOL;

        // ファイルに書き込み
        file_put_contents($this->log_file_path, $log_entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * エラーログ
     *
     * @param string $message メッセージ
     * @param array $context 追加情報
     */
    public function error($message, $context = array()) {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * 警告ログ
     *
     * @param string $message メッセージ
     * @param array $context 追加情報
     */
    public function warning($message, $context = array()) {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * 情報ログ
     *
     * @param string $message メッセージ
     * @param array $context 追加情報
     */
    public function info($message, $context = array()) {
        $this->log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * 成功ログ
     *
     * @param string $message メッセージ
     * @param array $context 追加情報
     */
    public function success($message, $context = array()) {
        $this->log(self::LEVEL_SUCCESS, $message, $context);
    }

    /**
     * ログディレクトリ内の全ログファイルを取得
     *
     * @return array ログファイルのリスト
     */
    public static function get_all_log_files() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/wp-media-reclassification-logs';

        if (!file_exists($log_dir)) {
            return array();
        }

        $files = glob($log_dir . '/*.log');
        $log_files = array();

        foreach ($files as $file) {
            $log_files[] = array(
                'name' => basename($file),
                'path' => $file,
                'size' => filesize($file),
                'modified' => filemtime($file)
            );
        }

        // 更新日時の降順でソート
        usort($log_files, function($a, $b) {
            return $b['modified'] - $a['modified'];
        });

        return $log_files;
    }

    /**
     * ログファイルを削除
     *
     * @param string $filename ファイル名
     * @return bool 成功したかどうか
     */
    public static function delete_log_file($filename) {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/wp-media-reclassification-logs';
        $file_path = $log_dir . '/' . basename($filename);

        if (file_exists($file_path)) {
            return unlink($file_path);
        }

        return false;
    }

    /**
     * 古いログファイルを削除
     *
     * @param int $days 保持日数
     * @return int 削除したファイル数
     */
    public static function delete_old_logs($days = 30) {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/wp-media-reclassification-logs';

        if (!file_exists($log_dir)) {
            return 0;
        }

        $files = glob($log_dir . '/*.log');
        $deleted_count = 0;
        $threshold = time() - ($days * 24 * 60 * 60);

        foreach ($files as $file) {
            if (filemtime($file) < $threshold) {
                if (unlink($file)) {
                    $deleted_count++;
                }
            }
        }

        return $deleted_count;
    }

    /**
     * ログファイルの内容を読み込み
     *
     * @param string $filename ファイル名
     * @param int $lines 読み込む行数（末尾から）
     * @return string|false ログ内容
     */
    public static function read_log_file($filename, $lines = 100) {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/wp-media-reclassification-logs';
        $file_path = $log_dir . '/' . basename($filename);

        if (!file_exists($file_path)) {
            return false;
        }

        // ファイル全体を読み込み
        $content = file_get_contents($file_path);

        // 指定行数のみ取得（末尾から）
        if ($lines > 0) {
            $all_lines = explode("\n", $content);
            $total_lines = count($all_lines);
            $start = max(0, $total_lines - $lines);
            $content = implode("\n", array_slice($all_lines, $start));
        }

        return $content;
    }
}
