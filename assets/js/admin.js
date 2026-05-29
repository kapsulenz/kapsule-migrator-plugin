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

    var STANDALONE_STATES = ['standalone_packaging'];

    var migrationCancelled = false;

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

        $btn.prop('disabled', true).text('Scanning site...');
        $err.hide();

        $.post(kapsuleMigrator.ajaxUrl, {
            action: 'kapsule_start_migration',
            nonce:  kapsuleMigrator.nonce,
            token:  token,
        }, function (resp) {
            if (resp.success) {
                // Page reload: server has set status=uploading_files + saved manifest.
                // On reload, JS will auto-start the chunk loop.
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

    // Reset / cancel
    $('#kapsule-reset-btn').on('click', function () {
        migrationCancelled = true;
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

    // ---------------------------------------------------------------
    // AJAX-driven chunk loop (replaces WP cron for connected migration)
    // ---------------------------------------------------------------

    function setStep(stepId) {
        $('#kapsule-steps .kapsule-step').each(function () {
            var id = $(this).attr('id');
            var icon = $(this).find('.kapsule-step-icon');

            if (id === stepId) {
                $(this).removeClass('kapsule-step--done').addClass('kapsule-step--active');
                icon.html('<div class="kapsule-step-spinner"></div>');
            } else if (STEP_ORDER.indexOf(id) < STEP_ORDER.indexOf(stepId)) {
                $(this).removeClass('kapsule-step--active').addClass('kapsule-step--done');
                icon.html('<svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 6l3 3 5-5" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>');
            } else {
                $(this).removeClass('kapsule-step--active kapsule-step--done');
                icon.html('<div class="kapsule-step-dot-inner"></div>');
            }
        });
    }

    var STEP_ORDER = ['kstep-scan', 'kstep-files', 'kstep-db', 'kstep-done'];

    function setFilesProgress(bytesDone, totalBytes) {
        if (totalBytes > 0) {
            var pct = Math.round((bytesDone / totalBytes) * 90);
            var clamped = Math.min(Math.max(pct, 1), 90);
            $('#kapsule-progress-fill').css('width', clamped + '%');
            $('#kapsule-status-text').text('Uploading files to Kapsule... ' + clamped + '%');
        }
    }

    function runChunkLoop(chunkCount, totalBytes, startFrom) {
        if (migrationCancelled) return;

        if (startFrom >= chunkCount) {
            uploadDbAndComplete();
            return;
        }

        $.post(kapsuleMigrator.ajaxUrl, {
            action: 'kapsule_upload_chunk',
            nonce:  kapsuleMigrator.nonce,
            index:  startFrom,
        }, function (resp) {
            if (migrationCancelled) return;

            if (resp.success) {
                var bytesDone  = resp.data.bytesDone  || 0;
                var knownTotal = totalBytes || resp.data.totalBytes || 0;
                setFilesProgress(bytesDone, knownTotal);
                runChunkLoop(chunkCount, knownTotal, startFrom + 1);
            } else {
                // Server already set status='error'; reload to show error card
                window.location.reload();
            }
        }).fail(function () {
            if (!migrationCancelled) {
                // Network error: reload so user sees spinner + cancel button
                window.location.reload();
            }
        });
    }

    function uploadDbAndComplete() {
        if (migrationCancelled) return;

        setStep('kstep-db');
        $('#kapsule-status-text').text('Uploading database to Kapsule...');
        $('#kapsule-progress-fill').css('width', '90%');

        $.post(kapsuleMigrator.ajaxUrl, {
            action: 'kapsule_upload_db_and_complete',
            nonce:  kapsuleMigrator.nonce,
        }, function (resp) {
            if (migrationCancelled) return;

            if (resp.success) {
                $('#kapsule-progress-fill').css('width', '100%');
                window.location.reload();
            } else {
                window.location.reload();
            }
        }).fail(function () {
            if (!migrationCancelled) {
                window.location.reload();
            }
        });
    }

    // Auto-start chunk loop on page load if we're in uploading_files state with a manifest.
    // Always ask the SERVER which chunks it has before starting — the local nextChunk WP option
    // can be stale from a previous aborted run. Server is the authoritative source of truth.
    if (kapsuleMigrator.status === 'uploading_files' && kapsuleMigrator.chunkCount > 0) {
        setStep('kstep-files');

        function startChunkLoop(startFrom) {
            if (startFrom > 0 && kapsuleMigrator.chunkCount > 0) {
                var resumePct = Math.round((startFrom / kapsuleMigrator.chunkCount) * 90);
                $('#kapsule-progress-fill').css('width', Math.min(resumePct, 90) + '%');
                $('#kapsule-status-text').text('Uploading files to Kapsule... ' + Math.min(resumePct, 90) + '%');
            }
            runChunkLoop(kapsuleMigrator.chunkCount, kapsuleMigrator.totalBytes, startFrom);
        }

        if (kapsuleMigrator.token && kapsuleMigrator.apiBase) {
            $.getJSON(kapsuleMigrator.apiBase + '/chunks?token=' + encodeURIComponent(kapsuleMigrator.token))
                .done(function (manifest) {
                    var serverFirst = (typeof manifest.firstGap === 'number') ? manifest.firstGap : kapsuleMigrator.nextChunk;
                    startChunkLoop(serverFirst);
                })
                .fail(function () {
                    // Server manifest unavailable — fall back to local WP option
                    startChunkLoop(kapsuleMigrator.nextChunk);
                });
        } else {
            startChunkLoop(kapsuleMigrator.nextChunk);
        }
    } else if (kapsuleMigrator.status === 'uploading_db') {
        setStep('kstep-db');
        $('#kapsule-progress-fill').css('width', '90%');
        // Page was reloaded while DB upload was in-flight; poll until server finishes
        pollStatus(3000);
    } else if (STANDALONE_STATES.indexOf(kapsuleMigrator.status) !== -1) {
        pollStatus(5000);
    }

    // Legacy poll — still used for standalone packaging
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
                } else if (data.status === 'uploading_db') {
                    pollStatus(3000);
                } else if (STANDALONE_STATES.indexOf(data.status) !== -1) {
                    pollStatus(5000);
                }
            });
        }, delay);
    }

})(jQuery);
