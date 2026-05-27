/* Kapsule Migrator — Admin JS */
(function ($) {
    'use strict';

    var STATUS_LABELS = {
        preflight:       'Scanning your site...',
        scanning:        'Scanning files and database...',
        uploading_files: 'Uploading files to Kapsule...',
        uploading_db:    'Uploading database to Kapsule...',
        complete:        'Complete',
        error:           'Error',
    };

    // Start migration
    $('#kapsule-start-btn').on('click', function () {
        var token = $('#kapsule-token-input').val().trim();
        var $btn  = $(this);
        var $err  = $('#kapsule-error-msg');

        if (!token) {
            $err.text('Please paste your migration token.').show();
            return;
        }

        $btn.prop('disabled', true).text('Connecting...');
        $err.hide();

        $.post(kapsuleMigrator.ajaxUrl, {
            action:  'kapsule_start_migration',
            nonce:   kapsuleMigrator.nonce,
            token:   token,
        }, function (resp) {
            if (resp.success) {
                // Reload page to show progress UI
                window.location.reload();
            } else {
                $err.text(resp.data || 'Connection failed. Check your token and try again.').show();
                $btn.prop('disabled', false).text('Start Migration');
            }
        }).fail(function () {
            $err.text('Connection error. Please try again.').show();
            $btn.prop('disabled', false).text('Start Migration');
        });
    });

    // Reset / retry
    $('#kapsule-retry-btn').on('click', function () {
        $.post(kapsuleMigrator.ajaxUrl, {
            action: 'kapsule_get_status',
            nonce:  kapsuleMigrator.nonce,
        });
        // Clear options via separate reset action — for now just reload
        window.location.reload();
    });

    // Poll for progress if migration is running
    var RUNNING_STATES = ['preflight', 'scanning', 'uploading_files', 'uploading_db'];
    if (RUNNING_STATES.indexOf(kapsuleMigrator.status) !== -1) {
        pollStatus();
    }

    function pollStatus() {
        setTimeout(function () {
            $.post(kapsuleMigrator.ajaxUrl, {
                action: 'kapsule_get_status',
                nonce:  kapsuleMigrator.nonce,
            }, function (resp) {
                if (!resp.success) return;
                var data = resp.data;

                // Update status text
                var label = STATUS_LABELS[data.status] || data.status;
                $('#kapsule-status-text').text(label);

                // Update progress bar
                if (data.progress && data.progress.totalBytes > 0) {
                    var pct = Math.round((data.progress.bytesTransferred / data.progress.totalBytes) * 100);
                    $('#kapsule-progress-fill').css('width', pct + '%');
                }

                if (data.status === 'complete' || data.status === 'error') {
                    window.location.reload();
                } else if (RUNNING_STATES.indexOf(data.status) !== -1) {
                    pollStatus();
                }
            });
        }, 3000);
    }

})(jQuery);
