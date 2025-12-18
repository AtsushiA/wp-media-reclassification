(function($) {
    'use strict';

    let totalCount = 0;
    let processedCount = 0;
    let successCount = 0;
    let errorCount = 0;
    let skippedCount = 0;
    let isProcessing = false;
    let allErrors = [];
    let allSuccess = [];

    $(document).ready(function() {
        // 対象件数を確認
        $('#check-count-btn').on('click', function() {
            checkMediaCount();
        });

        // 処理を開始
        $('#start-process-btn').on('click', function() {
            if (confirm(totalCount + wpMediaReclassification.strings.confirmStart)) {
                startProcessing();
            }
        });
    });

    /**
     * 対象メディア件数を確認
     */
    function checkMediaCount() {
        const dateFrom = $('#date_from').val();
        const dateTo = $('#date_to').val();

        $('#check-count-btn').prop('disabled', true).text('確認中...');

        $.ajax({
            url: wpMediaReclassification.ajaxUrl,
            type: 'POST',
            data: {
                action: 'get_media_count',
                nonce: wpMediaReclassification.nonce,
                date_from: dateFrom,
                date_to: dateTo
            },
            success: function(response) {
                if (response.success) {
                    totalCount = response.data.count;
                    $('#target-count').text(totalCount);
                    $('#count-result').slideDown();

                    if (totalCount > 0) {
                        $('#start-process-btn').prop('disabled', false);
                    } else {
                        $('#start-process-btn').prop('disabled', true);
                        alert('対象となるメディアファイルが見つかりませんでした。');
                    }
                } else {
                    alert('エラーが発生しました: ' + response.data);
                }
            },
            error: function() {
                alert('通信エラーが発生しました。');
            },
            complete: function() {
                $('#check-count-btn').prop('disabled', false).text('対象件数を確認');
            }
        });
    }

    /**
     * 処理を開始
     */
    function startProcessing() {
        if (isProcessing) {
            return;
        }

        isProcessing = true;
        processedCount = 0;
        successCount = 0;
        errorCount = 0;
        skippedCount = 0;
        allErrors = [];
        allSuccess = [];

        // UIを更新
        $('#start-process-btn').prop('disabled', true);
        $('#check-count-btn').prop('disabled', true);
        $('#media-reclassification-form input, #media-reclassification-form select').prop('disabled', true);
        $('#progress-container').slideDown();
        $('#result-container').hide();

        updateProgress();

        processBatch(0);
    }

    /**
     * バッチ処理を実行
     */
    function processBatch(offset) {
        const dryRun = $('#dry_run').is(':checked') ? '1' : '0';
        const batchSize = parseInt($('#batch_size').val()) || 50;
        const dateFrom = $('#date_from').val();
        const dateTo = $('#date_to').val();

        $.ajax({
            url: wpMediaReclassification.ajaxUrl,
            type: 'POST',
            data: {
                action: 'process_reclassification',
                nonce: wpMediaReclassification.nonce,
                dry_run: dryRun,
                batch_size: batchSize,
                offset: offset,
                date_from: dateFrom,
                date_to: dateTo
            },
            success: function(response) {
                if (response.success) {
                    const results = response.data.results;
                    const log = response.data.log;

                    processedCount += results.total;
                    successCount += results.success;
                    errorCount += results.error;
                    skippedCount += results.skipped;

                    // ログを保存
                    if (log.error && log.error.length > 0) {
                        allErrors = allErrors.concat(log.error);
                    }
                    if (log.success && log.success.length > 0) {
                        allSuccess = allSuccess.concat(log.success);
                    }

                    updateProgress();

                    // 次のバッチを処理
                    if (processedCount < totalCount) {
                        processBatch(offset + batchSize);
                    } else {
                        completeProcessing();
                    }
                } else {
                    alert('エラーが発生しました: ' + response.data);
                    completeProcessing();
                }
            },
            error: function() {
                alert('通信エラーが発生しました。処理を中断します。');
                completeProcessing();
            }
        });
    }

    /**
     * 進捗を更新
     */
    function updateProgress() {
        const percentage = totalCount > 0 ? Math.round((processedCount / totalCount) * 100) : 0;

        $('#progress-current').text(processedCount);
        $('#progress-total').text(totalCount);
        $('#progress-percentage').text(percentage);
        $('.progress-bar-fill').css('width', percentage + '%');

        $('#success-count').text(successCount);
        $('#error-count').text(errorCount);
        $('#skipped-count').text(skippedCount);
    }

    /**
     * 処理完了
     */
    function completeProcessing() {
        isProcessing = false;

        // UIを更新
        $('#start-process-btn').prop('disabled', false);
        $('#check-count-btn').prop('disabled', false);
        $('#media-reclassification-form input, #media-reclassification-form select').prop('disabled', false);

        // 結果を表示
        const resultMessage = totalCount + wpMediaReclassification.strings.completed +
            successCount + wpMediaReclassification.strings.success +
            errorCount + wpMediaReclassification.strings.error +
            skippedCount + wpMediaReclassification.strings.skipped;

        let resultClass = 'notice-success';
        if (errorCount > 0) {
            resultClass = 'notice-warning';
        }

        $('#result-message').html('<div class="notice ' + resultClass + '"><p>' + resultMessage + '</p></div>');
        $('#result-container').slideDown();

        // エラーリストを表示
        if (allErrors.length > 0) {
            let errorHtml = '';
            allErrors.forEach(function(item) {
                errorHtml += '<li>[ID: ' + item.id + '] ' + item.message + '</li>';
            });
            $('#error-items').html(errorHtml);
            $('#error-list').show();
        }

        // 成功リストを表示（最初の10件）
        if (allSuccess.length > 0) {
            let successHtml = '';
            const displayCount = Math.min(allSuccess.length, 10);
            for (let i = 0; i < displayCount; i++) {
                const item = allSuccess[i];
                successHtml += '<li>[ID: ' + item.id + '] ' + item.old_path + ' → ' + item.new_path + '</li>';
            }
            $('#success-items').html(successHtml);
            $('#success-list').show();
        }

        // オフセット値を更新（処理を中断した場合の再開用）
        $('#offset').val(processedCount);
    }

})(jQuery);
