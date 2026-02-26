<?php
/**
 * プラグインアンインストール時のクリーンアップ処理
 */

// アンインストールフック経由でない直接アクセスを防止
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// ログディレクトリを削除
$upload_dir = wp_upload_dir();
$log_dir = $upload_dir['basedir'] . '/wp-media-reclassification-logs';

if (is_dir($log_dir)) {
    $files = glob($log_dir . '/*');
    if ($files) {
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
    rmdir($log_dir);
}
