<?php
/**
 * WP-CLIコマンドクラス
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_CLI')) {
    return;
}

/**
 * メディアファイルを年/月のフォルダ構造に再分類
 */
class WP_Media_Reclassification_CLI_Command extends WP_CLI_Command {

    /**
     * メディアファイルを年/月フォルダに再分類します
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : ドライランモード。実際にファイルを移動せず、移動対象と移動先を表示します。
     *
     * [--batch-size=<number>]
     * : 一度に処理する件数（デフォルト: 50）
     * ---
     * default: 50
     * ---
     *
     * [--date-from=<date>]
     * : 開始日（YYYY-MM-DD形式）。この日付以降にアップロードされたファイルのみ処理
     *
     * [--date-to=<date>]
     * : 終了日（YYYY-MM-DD形式）。この日付以前にアップロードされたファイルのみ処理
     *
     * [--all]
     * : すべてのメディアファイルを処理（バッチ処理を繰り返し実行）
     *
     * [--log]
     * : 処理ログをファイルに出力します
     *
     * [--log-file=<filename>]
     * : ログファイル名を指定（デフォルト: reclassification-YYYY-MM-DD_HH-MM-SS.log）
     *
     * [--error-only]
     * : エラーと警告のみをログに記録します（ログファイルサイズを削減）
     *
     * ## EXAMPLES
     *
     *     # ドライラン（テスト実行）
     *     $ wp media reclassify --dry-run
     *
     *     # 50件のメディアを処理
     *     $ wp media reclassify --batch-size=50
     *
     *     # 特定期間のメディアを処理
     *     $ wp media reclassify --date-from=2023-01-01 --date-to=2023-12-31
     *
     *     # すべてのメディアを処理（ログ出力あり）
     *     $ wp media reclassify --all --log
     *
     *     # ログファイル名を指定
     *     $ wp media reclassify --log --log-file=my-reclassification.log
     *
     *     # エラーのみログに記録
     *     $ wp media reclassify --all --log --error-only
     *
     * @when after_wp_load
     */
    public function __invoke($args, $assoc_args) {
        $dry_run = isset($assoc_args['dry-run']);
        $batch_size = isset($assoc_args['batch-size']) ? intval($assoc_args['batch-size']) : 50;
        $date_from = isset($assoc_args['date-from']) ? $assoc_args['date-from'] : '';
        $date_to = isset($assoc_args['date-to']) ? $assoc_args['date-to'] : '';
        $process_all = isset($assoc_args['all']);
        $enable_logging = isset($assoc_args['log']);
        $log_file_name = isset($assoc_args['log-file']) ? $assoc_args['log-file'] : null;
        $error_only = isset($assoc_args['error-only']);

        // 日付フォーマットのバリデーション
        if (!empty($date_from) && !$this->validate_date($date_from)) {
            WP_CLI::error('date-from の形式が正しくありません。YYYY-MM-DD形式で指定してください。');
        }

        if (!empty($date_to) && !$this->validate_date($date_to)) {
            WP_CLI::error('date-to の形式が正しくありません。YYYY-MM-DD形式で指定してください。');
        }

        $options = array(
            'dry_run' => $dry_run,
            'batch_size' => $batch_size,
            'enable_logging' => $enable_logging,
            'log_file_name' => $log_file_name,
            'error_only' => $error_only
        );

        $query_args = array();
        if (!empty($date_from)) {
            $query_args['date_from'] = $date_from;
        }
        if (!empty($date_to)) {
            $query_args['date_to'] = $date_to;
        }

        $reclassifier = new WP_Media_Reclassifier($options);

        // 対象件数を取得
        $total_count = $reclassifier->get_total_count($query_args);

        if ($total_count === 0) {
            WP_CLI::success('対象となるメディアファイルが見つかりませんでした。');
            return;
        }

        WP_CLI::log("対象メディア件数: {$total_count} 件");

        if ($dry_run) {
            WP_CLI::warning('ドライランモード: 実際にファイルは移動されません。');
        }

        if ($enable_logging) {
            $logger = $reclassifier->get_logger();
            $log_file_path = $logger->get_log_file_path();
            WP_CLI::log("ログ出力: 有効");
            WP_CLI::log("ログファイル: {$log_file_path}");
        }

        if ($process_all) {
            $this->process_all_batches($reclassifier, $query_args, $total_count, $batch_size);
        } else {
            $this->process_single_batch($reclassifier, $query_args, $batch_size);
        }

        // 処理完了後にログファイル情報を表示
        if ($enable_logging) {
            $logger = $reclassifier->get_logger();
            WP_CLI::success("ログファイルが保存されました: " . $logger->get_log_file_path());
        }
    }

    /**
     * 単一バッチを処理
     *
     * @param WP_Media_Reclassifier $reclassifier
     * @param array $query_args
     * @param int $batch_size
     */
    private function process_single_batch($reclassifier, $query_args, $batch_size) {
        WP_CLI::log("処理を開始します（最大 {$batch_size} 件）...");

        $results = $reclassifier->process_batch($query_args);
        $log = $reclassifier->get_log();

        $this->display_results($results, $log);
    }

    /**
     * すべてのバッチを処理
     *
     * @param WP_Media_Reclassifier $reclassifier
     * @param array $query_args
     * @param int $total_count
     * @param int $batch_size
     */
    private function process_all_batches($reclassifier, $query_args, $total_count, $batch_size) {
        $total_batches = ceil($total_count / $batch_size);

        WP_CLI::log("全 {$total_count} 件を {$total_batches} バッチで処理します...");

        $overall_success = 0;
        $overall_error = 0;
        $overall_skipped = 0;
        $offset = 0;

        $progress = \WP_CLI\Utils\make_progress_bar('処理中', $total_count);

        while ($offset < $total_count) {
            $query_args['offset'] = $offset;

            $results = $reclassifier->process_batch($query_args);

            $overall_success += $results['success'];
            $overall_error += $results['error'];
            $overall_skipped += $results['skipped'];

            $progress->tick($results['total']);

            $offset += $batch_size;

            // メモリ管理
            wp_cache_flush();
        }

        $progress->finish();

        WP_CLI::success("すべての処理が完了しました。");
        WP_CLI::log("成功: {$overall_success} 件");
        WP_CLI::log("エラー: {$overall_error} 件");
        WP_CLI::log("スキップ: {$overall_skipped} 件");

        // エラーがあった場合は警告を表示
        if ($overall_error > 0) {
            WP_CLI::warning("{$overall_error} 件のファイルでエラーが発生しました。");
        }
    }

    /**
     * 処理結果を表示
     *
     * @param array $results
     * @param array $log
     */
    private function display_results($results, $log) {
        WP_CLI::log("\n処理結果:");
        WP_CLI::log("処理件数: {$results['total']} 件");
        WP_CLI::log("成功: {$results['success']} 件");
        WP_CLI::log("エラー: {$results['error']} 件");
        WP_CLI::log("スキップ: {$results['skipped']} 件");

        // 成功したファイルの詳細（最初の5件）
        if (!empty($log['success'])) {
            WP_CLI::log("\n成功したファイル（最初の5件）:");
            $count = 0;
            foreach ($log['success'] as $item) {
                if ($count >= 5) break;
                WP_CLI::log("  [{$item['id']}] {$item['old_path']} → {$item['new_path']}");
                $count++;
            }
        }

        // エラーが発生したファイル
        if (!empty($log['error'])) {
            WP_CLI::warning("\nエラーが発生したファイル:");
            foreach ($log['error'] as $item) {
                WP_CLI::warning("  [{$item['id']}] {$item['message']}");
            }
        }

        // スキップされたファイル（最初の5件）
        if (!empty($log['skipped'])) {
            WP_CLI::log("\nスキップされたファイル（最初の5件）:");
            $count = 0;
            foreach ($log['skipped'] as $item) {
                if ($count >= 5) break;
                WP_CLI::log("  [{$item['id']}] {$item['message']}");
                $count++;
            }
        }

        if ($results['success'] > 0) {
            WP_CLI::success('処理が完了しました。');
        } elseif ($results['error'] > 0) {
            WP_CLI::error('エラーが発生しました。');
        } else {
            WP_CLI::success('処理対象のファイルはすべてスキップされました。');
        }
    }

    /**
     * 日付フォーマットを検証
     *
     * @param string $date
     * @return bool
     */
    private function validate_date($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}

WP_CLI::add_command('media reclassify', 'WP_Media_Reclassification_CLI_Command');
