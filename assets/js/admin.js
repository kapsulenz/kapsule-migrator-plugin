/* Kapsule Migrator — Admin JS */
(function ($) {
    'use strict';

    var STATUS_LABELS = {
        preflight:             'Scanning your site...',
        scanning:              'Scanning files and database...',
        uploading_files:       'Uploading files to Kapsule...',
        uploading_db:          'Uploading database to Kapsule...',
        standalone_packaging:  'Packaging your site...',
    };

    var RUNNING_STATES    = ['preflight', 'scanning', 'uploading_files', 'uploading_db'];
    var STANDALONE_STATES = ['standalone_packaging'];

    // Tab switching
    $('.kapsule-tab').on('click', function () {
        var tab = $(this).data('tab');
        $('.kapsule-tab').removeClass('kapsule-tab--active');
        $(this).addClass('kapsule-tab--active');
        $('.kapsule-tab-panel').hide();
        $('#kapsule-panel-' + tab).show();
    });

    // Start connected migration
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
            action: 'kapsule_start_migration',
            nonce:  kapsuleMigrator.nonce,
            token:  token,
        }, function (resp) {
            if (resp.success) {
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

    // Start standalone export
    $('#kapsule-standalone-btn').on('click', function () {
        var $btn = $(this);
        var $err = $('#kapsule-standalone-error-msg');

        $btn.prop('disabled', true).text('Starting export...');
        $err.hide();

        $.post(kapsuleMigrator.ajaxUrl, {
            action: 'kapsule_start_standalone',
            nonce:  kapsuleMigrator.nonce,
        }, function (resp) {
            if (resp.success) {
                window.location.reload();
            } else {
                $err.text(resp.data || 'Could not start export. Please try again.').show();
                $btn.prop('disabled', false).text('Export Site');
            }
        }).fail(function () {
            $err.text('Connection error. Please try again.').show();
            $btn.prop('disabled', false).text('Export Site');
        });
    });

    // Reset / retry
    $('#kapsule-reset-btn').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Cleaning up...');

        $.post(kapsuleMigrator.ajaxUrl, {
            action: 'kapsule_reset',
            nonce:  kapsuleMigrator.nonce,
        }, function () {
            window.location.reload();
        }).fail(function () {
            window.location.reload();
        });
    });

    // Poll for progress
    if (RUNNING_STATES.indexOf(kapsuleMigrator.status) !== -1) {
        pollStatus(3000);
    } else if (STANDALONE_STATES.indexOf(kapsuleMigrator.status) !== -1) {
        pollStatus(5000);
    }

    function pollStatus(delay) {
        setTimeout(function () {
            $.post(kapsuleMigrator.ajaxUrl, {
                action: 'kapsule_get_status',
                nonce:  kapsuleMigrator.nonce,
            }, function (resp) {
                if (!resp.success) return;
                var data = resp.data;

                var label = STATUS_LABELS[data.status] || data.status;
                $('#kapsule-status-text').text(label);

                if (data.progress && data.progress.totalBytes > 0) {
                    var pct = Math.round((data.progress.bytesTransferred / data.progress.totalBytes) * 100);
                    $('#kapsule-progress-fill').css('width', Math.min(pct, 95) + '%');
                }

                if (data.status === 'complete' || data.status === 'error' || data.status === 'standalone_ready') {
                    window.location.reload();
                } else if (RUNNING_STATES.indexOf(data.status) !== -1) {
                    pollStatus(3000);
                } else if (STANDALONE_STATES.indexOf(data.status) !== -1) {
                    pollStatus(5000);
                }
            });
        }, delay);
    }

})(jQuery);
