<?php
/**
 * メディア再分類のコア処理クラス
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Media_Reclassifier {

    /**
     * バッチサイズのデフォルト値
     */
    const DEFAULT_BATCH_SIZE = 50;

    /**
     * 処理ログ
     */
    private $log = array(
        'success' => array(),
        'error' => array(),
        'skipped' => array()
    );

    /**
     * ドライランモード
     */
    private $dry_run = false;

    /**
     * バッチサイズ
     */
    private $batch_size = self::DEFAULT_BATCH_SIZE;

    /**
     * ロガー
     */
    private $logger;

    /**
     * コンストラクタ
     */
    public function __construct($options = array()) {
        $this->dry_run = isset($options['dry_run']) ? $options['dry_run'] : false;
        $this->batch_size = isset($options['batch_size']) ? intval($options['batch_size']) : self::DEFAULT_BATCH_SIZE;

        // ロガーの初期化
        $enable_logging = isset($options['enable_logging']) ? $options['enable_logging'] : false;
        $log_file_name = isset($options['log_file_name']) ? $options['log_file_name'] : null;
        $this->logger = new WP_Media_Reclassification_Logger($enable_logging, $log_file_name);
    }

    /**
     * 対象メディアを取得
     *
     * @param array $args フィルター条件
     * @return array 添付ファイルの配列
     */
    public function get_target_attachments($args = array()) {
        $defaults = array(
            'post_type' => 'attachment',
            'post_status' => 'any',
            'posts_per_page' => $this->batch_size,
            'offset' => 0,
            'orderby' => 'ID',
            'order' => 'ASC'
        );

        // 日付範囲フィルター
        if (!empty($args['date_from']) || !empty($args['date_to'])) {
            $defaults['date_query'] = array();

            if (!empty($args['date_from'])) {
                $defaults['date_query']['after'] = $args['date_from'];
            }

            if (!empty($args['date_to'])) {
                $defaults['date_query']['before'] = $args['date_to'];
                $defaults['date_query']['inclusive'] = true;
            }
        }

        $query_args = wp_parse_args($args, $defaults);
        $attachments = get_posts($query_args);

        return $attachments;
    }

    /**
     * 対象メディアの総数を取得
     *
     * @param array $args フィルター条件
     * @return int 総数
     */
    public function get_total_count($args = array()) {
        $count_args = array(
            'post_type' => 'attachment',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );

        if (!empty($args['date_from']) || !empty($args['date_to'])) {
            $count_args['date_query'] = array();

            if (!empty($args['date_from'])) {
                $count_args['date_query']['after'] = $args['date_from'];
            }

            if (!empty($args['date_to'])) {
                $count_args['date_query']['before'] = $args['date_to'];
                $count_args['date_query']['inclusive'] = true;
            }
        }

        $query = new WP_Query($count_args);
        return $query->found_posts;
    }

    /**
     * メディアを再分類（年/月フォルダに移動）
     *
     * @param int $attachment_id 添付ファイルID
     * @return array 処理結果
     */
    public function reclassify_media($attachment_id) {
        $result = array(
            'success' => false,
            'message' => '',
            'old_path' => '',
            'new_path' => ''
        );

        // 添付ファイル情報取得
        $attachment = get_post($attachment_id);
        if (!$attachment) {
            $result['message'] = 'Attachment not found';
            $this->logger->error('Attachment not found', array('attachment_id' => $attachment_id));
            return $result;
        }

        // 現在のファイルパスを取得
        $old_file_path = get_attached_file($attachment_id);
        if (!file_exists($old_file_path)) {
            $result['message'] = 'File does not exist: ' . $old_file_path;
            $this->logger->error('File does not exist', array(
                'attachment_id' => $attachment_id,
                'file_path' => $old_file_path
            ));
            return $result;
        }

        // アップロードディレクトリ情報を取得
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];

        // 年/月フォルダパスを生成（post_dateを使用）
        $post_date = $attachment->post_date;
        $year = date('Y', strtotime($post_date));
        $month = date('m', strtotime($post_date));
        $new_dir = $base_dir . '/' . $year . '/' . $month;

        // 既に正しいフォルダにある場合はスキップ
        if (strpos($old_file_path, '/' . $year . '/' . $month . '/') !== false) {
            $result['message'] = 'Already in correct folder structure';
            $result['skipped'] = true;
            $this->logger->info('File already in correct folder structure', array(
                'attachment_id' => $attachment_id,
                'file_path' => $old_file_path
            ));
            return $result;
        }

        // 新しいファイルパスを生成
        $filename = basename($old_file_path);
        $new_file_path = $new_dir . '/' . $filename;

        // ファイル名の重複チェック
        $new_file_path = $this->get_unique_filename($new_file_path);
        $filename = basename($new_file_path);

        $result['old_path'] = $old_file_path;
        $result['new_path'] = $new_file_path;

        // ドライランモードの場合はここで終了
        if ($this->dry_run) {
            $result['success'] = true;
            $result['message'] = 'Dry run: would move to ' . $new_file_path;
            return $result;
        }

        // ディレクトリが存在しない場合は作成
        if (!file_exists($new_dir)) {
            if (!wp_mkdir_p($new_dir)) {
                $result['message'] = 'Failed to create directory: ' . $new_dir;
                $this->logger->error('Failed to create directory', array(
                    'attachment_id' => $attachment_id,
                    'directory' => $new_dir
                ));
                return $result;
            }
        }

        // ファイルを移動
        if (!@rename($old_file_path, $new_file_path)) {
            $result['message'] = 'Failed to move file';
            $this->logger->error('Failed to move file', array(
                'attachment_id' => $attachment_id,
                'old_path' => $old_file_path,
                'new_path' => $new_file_path
            ));
            return $result;
        }

        // サムネイルも移動
        $this->move_thumbnails($attachment_id, $old_file_path, $new_file_path, $new_dir);

        // データベースを更新
        $update_result = $this->update_attachment_metadata($attachment_id, $new_file_path, $year, $month);

        if (!$update_result) {
            $result['message'] = 'File moved but failed to update database';
            $this->logger->error('Failed to update database', array(
                'attachment_id' => $attachment_id,
                'old_path' => $old_file_path,
                'new_path' => $new_file_path
            ));
            return $result;
        }

        // 記事内のパスを更新
        $this->update_post_content($old_file_path, $new_file_path);

        $result['success'] = true;
        $result['message'] = 'Successfully reclassified';

        // 成功ログを記録
        $this->logger->success('Successfully reclassified media', array(
            'attachment_id' => $attachment_id,
            'old_path' => $old_file_path,
            'new_path' => $new_file_path
        ));

        return $result;
    }

    /**
     * ファイル名の重複を回避
     *
     * @param string $filepath ファイルパス
     * @return string 一意なファイルパス
     */
    private function get_unique_filename($filepath) {
        if (!file_exists($filepath)) {
            return $filepath;
        }

        $path_info = pathinfo($filepath);
        $dir = $path_info['dirname'];
        $filename = $path_info['filename'];
        $ext = isset($path_info['extension']) ? '.' . $path_info['extension'] : '';

        $counter = 1;
        while (file_exists($filepath)) {
            $filepath = $dir . '/' . $filename . '-' . $counter . $ext;
            $counter++;
        }

        return $filepath;
    }

    /**
     * サムネイル画像を移動
     *
     * @param int $attachment_id 添付ファイルID
     * @param string $old_file_path 旧ファイルパス
     * @param string $new_file_path 新ファイルパス
     * @param string $new_dir 新ディレクトリ
     */
    private function move_thumbnails($attachment_id, $old_file_path, $new_file_path, $new_dir) {
        $metadata = wp_get_attachment_metadata($attachment_id);

        if (empty($metadata['sizes'])) {
            return;
        }

        $old_dir = dirname($old_file_path);
        $old_filename = pathinfo($old_file_path, PATHINFO_FILENAME);
        $old_ext = pathinfo($old_file_path, PATHINFO_EXTENSION);

        foreach ($metadata['sizes'] as $size => $size_data) {
            $old_thumb_path = $old_dir . '/' . $size_data['file'];
            $new_thumb_path = $new_dir . '/' . $size_data['file'];

            if (file_exists($old_thumb_path)) {
                // 重複チェック
                $new_thumb_path = $this->get_unique_filename($new_thumb_path);
                @rename($old_thumb_path, $new_thumb_path);
            }
        }
    }

    /**
     * 添付ファイルのメタデータを更新
     *
     * @param int $attachment_id 添付ファイルID
     * @param string $new_file_path 新しいファイルパス
     * @param string $year 年
     * @param string $month 月
     * @return bool 成功したかどうか
     */
    private function update_attachment_metadata($attachment_id, $new_file_path, $year, $month) {
        // _wp_attached_file を更新
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        $relative_path = str_replace($base_dir . '/', '', $new_file_path);

        update_attached_file($attachment_id, $relative_path);

        // _wp_attachment_metadata を更新
        $metadata = wp_get_attachment_metadata($attachment_id);

        if ($metadata) {
            // ファイル名を更新
            $metadata['file'] = $year . '/' . $month . '/' . basename($new_file_path);

            // サムネイルのパスは相対パスなので変更不要

            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        return true;
    }

    /**
     * 投稿・固定ページ・カスタムフィールド内のパスを更新
     *
     * @param string $old_path 旧パス
     * @param string $new_path 新パス
     */
    private function update_post_content($old_path, $new_path) {
        global $wpdb;

        $upload_dir = wp_upload_dir();
        $old_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $old_path);
        $new_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $new_path);

        // 投稿本文と固定ページ本文を更新
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
                $old_url,
                $new_url,
                '%' . $wpdb->esc_like($old_url) . '%'
            )
        );

        // カスタムフィールドを更新
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_value LIKE %s",
                $old_url,
                $new_url,
                '%' . $wpdb->esc_like($old_url) . '%'
            )
        );
    }

    /**
     * バッチ処理を実行
     *
     * @param array $args フィルター条件
     * @return array 処理結果
     */
    public function process_batch($args = array()) {
        $attachments = $this->get_target_attachments($args);

        $results = array(
            'total' => count($attachments),
            'success' => 0,
            'error' => 0,
            'skipped' => 0,
            'details' => array()
        );

        foreach ($attachments as $attachment) {
            $result = $this->reclassify_media($attachment->ID);

            if (isset($result['skipped']) && $result['skipped']) {
                $results['skipped']++;
                $this->log['skipped'][] = array(
                    'id' => $attachment->ID,
                    'message' => $result['message']
                );
            } elseif ($result['success']) {
                $results['success']++;
                $this->log['success'][] = array(
                    'id' => $attachment->ID,
                    'old_path' => $result['old_path'],
                    'new_path' => $result['new_path']
                );
            } else {
                $results['error']++;
                $this->log['error'][] = array(
                    'id' => $attachment->ID,
                    'message' => $result['message']
                );
            }

            $results['details'][] = $result;
        }

        return $results;
    }

    /**
     * ログを取得
     *
     * @return array ログ
     */
    public function get_log() {
        return $this->log;
    }

    /**
     * ログをクリア
     */
    public function clear_log() {
        $this->log = array(
            'success' => array(),
            'error' => array(),
            'skipped' => array()
        );
    }

    /**
     * ロガーを取得
     *
     * @return WP_Media_Reclassification_Logger
     */
    public function get_logger() {
        return $this->logger;
    }
}
