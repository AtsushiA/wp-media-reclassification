<?php
/**
 * Plugin Name: WP Media Reclassification
 * Plugin URI: https://github.com/yourusername/wp-media-reclassification
 * Description: メディアファイルを年/月のフォルダ構造に再分類します
 * Version: 1.0.0
 * Author: NExT-Season
 * Author URI: https://next-season.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-media-reclassification
 * Domain Path: /languages
 */

// セキュリティ: 直接アクセスを防止
if (!defined('ABSPATH')) {
    exit;
}

// プラグイン定数の定義
define('WP_MEDIA_RECLASSIFICATION_VERSION', '1.0.0');
define('WP_MEDIA_RECLASSIFICATION_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_MEDIA_RECLASSIFICATION_PLUGIN_URL', plugin_dir_url(__FILE__));

// 必要なクラスファイルを読み込み
require_once WP_MEDIA_RECLASSIFICATION_PLUGIN_DIR . 'includes/class-logger.php';
require_once WP_MEDIA_RECLASSIFICATION_PLUGIN_DIR . 'includes/class-media-reclassifier.php';
require_once WP_MEDIA_RECLASSIFICATION_PLUGIN_DIR . 'includes/class-admin-page.php';

// WP-CLIが利用可能な場合はコマンドを登録
if (defined('WP_CLI') && WP_CLI) {
    require_once WP_MEDIA_RECLASSIFICATION_PLUGIN_DIR . 'includes/class-wp-cli-command.php';
}

/**
 * プラグインの初期化
 */
function wp_media_reclassification_init() {
    // 言語ファイルの読み込み
    load_plugin_textdomain('wp-media-reclassification', false, dirname(plugin_basename(__FILE__)) . '/languages');

    // 管理画面のみで実行
    if (is_admin()) {
        new WP_Media_Reclassification_Admin_Page();
    }
}
add_action('plugins_loaded', 'wp_media_reclassification_init');

/**
 * プラグイン有効化時の処理
 */
function wp_media_reclassification_activate() {
    // 必要な権限チェック
    if (!current_user_can('activate_plugins')) {
        return;
    }

    // 将来的にオプションテーブルやカスタムテーブルを作成する場合はここに記述
}
register_activation_hook(__FILE__, 'wp_media_reclassification_activate');

/**
 * プラグイン無効化時の処理
 */
function wp_media_reclassification_deactivate() {
    // 必要な権限チェック
    if (!current_user_can('activate_plugins')) {
        return;
    }

    // 無効化時のクリーンアップ処理（必要に応じて）
}
register_deactivation_hook(__FILE__, 'wp_media_reclassification_deactivate');
