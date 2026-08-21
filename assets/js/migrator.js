/**
 * Kapsule Migrator, admin JS.
 *
 * THE BROWSER OWNS THE RETRY LOOP. It used to live in PHP, inside a single AJAX request, which meant
 * a failing piece slept and retried while the customer's own nginx counted down its
 * `fastcgi_read_timeout` (60 seconds by default). Five attempts with backoff guaranteed a 504, and a
 * 504 is indistinguishable from a dead migration: the old code simply reloaded the page.
 *
 * So each request is now ONE attempt, kept short on purpose, and the waiting happens out here where
 * we can also SHOW it. That is why "retrying" is a real state a customer can see rather than a
 * frozen progress bar: the honest thing to display during a retry is what is being retried and how
 * long until the next go.
 *
 * EVERY string here is translated through wp.i18n against the `kapsule-migrator` domain. The states
 * that move are built at runtime in JS, so leaving them untranslated would give a French or Arabic
 * customer a half-translated screen: static copy in their language, and every word describing what
 * is happening to their site in English.
 */
(function ($) {
    'use strict';

    // wp-i18n is a declared dependency, so it is present. The fallback exists only so a stripped or
    // mis-enqueued environment degrades to English rather than throwing and leaving a dead screen.
    var i18n    = (window.wp && window.wp.i18n) ? window.wp.i18n : null;
    var __      = i18n ? i18n.__ : function (s) { return s; };
    var _n      = i18n ? i18n._n : function (s, p, n) { return n === 1 ? s : p; };
    var sprintf = i18n ? i18n.sprintf : function (f) {
        var a = Array.prototype.slice.call(arguments, 1), i = 0;
        return String(f).replace(/%(\d+\$)?[sd]/g, function (m, pos) {
            return pos ? a[parseInt(pos, 10) - 1] : a[i++];
        });
    };
    var DOMAIN = 'kapsule-migrator';

    var cfg = window.kapsuleMigrator || {};
    var MAX_ATTEMPTS = parseInt(cfg.maxAttempts, 10) || 5;

    var cancelled     = false;
    var startedAt     = Date.now();
    var baselineBytes = null;   // bytes already moved when THIS page load began, so a resumed run
                                // does not report a wildly optimistic transfer rate.

    // ── Small helpers ────────────────────────────────────────────────────────

    function localeNum(n, dp) {
        try {
            // cfg.bcp47 is the SITE locale, not the browser's. Passing undefined here uses the browser,
            // which put "2.8 Go" beside PHP's "5,9 Go" in the same row for a French customer.
            return Number(n).toLocaleString(cfg.bcp47 || undefined, { minimumFractionDigits: dp, maximumFractionDigits: dp });
        } catch (e) {
            return Number(n).toFixed(dp);
        }
    }

    function fmtCount(n) {
        return localeNum(Number(n) || 0, 0);
    }

    /**
     * Byte sizes, localised.
     *
     * The UNIT is translatable because it is not universal: "GB" is "Go" in French and is written in
     * Arabic script for ar. The NUMBER goes through toLocaleString so the decimal separator follows
     * the reader (5,9 Go for a French customer, not 5.9).
     */
    function fmtBytes(n) {
        n = Number(n) || 0;
        if (n >= 1073741824) return sprintf(__('%s GB', 'kapsule-migrator'), localeNum(n / 1073741824, 1));
        if (n >= 1048576)    return sprintf(__('%s MB', 'kapsule-migrator'), localeNum(n / 1048576, 1));
        if (n >= 1024)       return sprintf(__('%s KB', 'kapsule-migrator'), localeNum(n / 1024, 1));
        return sprintf(__('%s B', 'kapsule-migrator'), localeNum(n, 0));
    }

    /** Plain-language duration. "about 6 minutes" reads better than "00:06:13" and is honest about precision. */
    function fmtEta(seconds) {
        if (!isFinite(seconds) || seconds <= 0) return '';
        if (seconds < 60) return __('less than a minute left', 'kapsule-migrator');

        var mins = Math.round(seconds / 60);
        if (mins < 60) {
            /* translators: %s: whole number of minutes remaining. */
            return sprintf(_n('about %s minute left', 'about %s minutes left', mins, 'kapsule-migrator'), fmtCount(mins));
        }
        var hrs = Math.floor(mins / 60), rem = mins % 60;
        if (!rem) {
            /* translators: %s: whole number of hours remaining. */
            return sprintf(_n('about %s hour left', 'about %s hours left', hrs, 'kapsule-migrator'), fmtCount(hrs));
        }
        /* translators: 1: whole hours remaining, 2: additional minutes remaining. */
        return sprintf(__('about %1$s h %2$s min left', 'kapsule-migrator'), fmtCount(hrs), fmtCount(rem));
    }

    function setChip(state, label) {
        if (state) $('#km-chip').attr('data-state', state);
        if (label) $('#km-chip-label').text(label);
    }

    function setHead(title, lede) {
        if (title) $('#km-head').text(title);
        if (lede)  $('#kapsule-status-text').text(lede);
    }

    function setMeter(pct, note) {
        pct = Math.min(100, Math.max(0, Math.round(pct)));
        $('#kapsule-progress-fill').css('width', pct + '%');
        $('#km-pct').text(fmtCount(pct));
        if (note !== undefined) $('#km-meter-note').text(note);
    }

    function setNote(tone, html) {
        $('#km-note').attr('data-tone', tone);
        $('#km-note-text').html(html);
    }

    function setFact4(key, value) {
        $('#km-f-4k').text(key);
        $('#km-f-4v').text(value);
    }

    var STEP_ORDER = ['kstep-scan', 'kstep-files', 'kstep-db', 'kstep-done'];

    function setStep(stepId) {
        var target = STEP_ORDER.indexOf(stepId);
        $('#kapsule-steps .km-step').each(function () {
            var i = STEP_ORDER.indexOf($(this).attr('id'));
            $(this).attr('data-on',   i === target ? '1' : '0');
            $(this).attr('data-done', i <  target ? '1' : '0');
        });
    }

    /** The rail's sheen is a claim that bytes are moving. Stop making it while they are not. */
    function setLive(isLive) {
        $('#km-rail').attr('data-live', isLive ? '1' : '0');
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /**
     * A numeric pair like "56 / 119", safe in a right-to-left layout.
     *
     * "56 / 119" is entirely NEUTRAL characters, so in an RTL paragraph bidi lays the run
     * right-to-left and it renders as "119 / 56": the customer is told 119 pieces of 56 are sent.
     * Measured in Arabic on the attempt counter too, which read attempt 5 of 3. The isolate pins the
     * pair to one direction without affecting the Arabic around it.
     */
    function pair(a, b) {
        return '\u2066' + a + ' / ' + b + '\u2069';
    }

    /** Pieces are 1-indexed for a human and 0-indexed internally. One place so they cannot disagree. */
    function pieceOf(index, total) {
        return sprintf(__('piece %1$s of %2$s', 'kapsule-migrator'), fmtCount(Math.min(index + 1, total)), fmtCount(total));
    }

    // ── Idle screen ──────────────────────────────────────────────────────────

    $('.kapsule-tab').on('click', function () {
        var tab = $(this).data('tab');
        $('.kapsule-tab').removeClass('km-tab--on');
        $(this).addClass('km-tab--on');
        $('.kapsule-tab-panel').hide();
        $('#kapsule-panel-' + tab).show();
    });

    function showFormError($box, message) {
        $box.find('span').text(message);
        $box.show();
    }

    /** The server now answers with an object; older builds answered with a bare string. Read both. */
    function reasonOf(resp) {
        var d = resp && resp.data;
        if (!d) return '';
        return (typeof d === 'string') ? d : (d.reason || '');
    }

    $('#kapsule-start-btn').on('click', function () {
        var token = $.trim($('#kapsule-token-input').val() || '');
        var $btn  = $(this);
        var $err  = $('#kapsule-error-msg');

        if (!token) {
            showFormError($err, __('Paste your migration token first. You will find it in your KapsuleHost panel under Sites, then Migrate.', 'kapsule-migrator'));
            $('#kapsule-token-input').focus();
            return;
        }

        $btn.prop('disabled', true).text(__('Checking your site...', 'kapsule-migrator'));
        $err.hide();

        $.post(cfg.ajaxUrl, {
            action: 'kapsule_start_migration',
            nonce:  cfg.nonce,
            token:  token
        }, function (resp) {
            if (resp.success) {
                window.location.reload();
            } else {
                showFormError($err, reasonOf(resp) || __('We could not connect to KapsuleHost with that token. Check it and try again.', 'kapsule-migrator'));
                $btn.prop('disabled', false).text(__('Start the move', 'kapsule-migrator'));
            }
        }).fail(function () {
            showFormError($err, __('We could not reach KapsuleHost just now. Check this server can get online, then try again.', 'kapsule-migrator'));
            $btn.prop('disabled', false).text(__('Start the move', 'kapsule-migrator'));
        });
    });

    $('#kapsule-standalone-btn').on('click', function () {
        var $btn = $(this);
        var $err = $('#kapsule-standalone-error-msg');

        $btn.prop('disabled', true).text(__('Packaging...', 'kapsule-migrator'));
        $err.hide();

        $.post(cfg.ajaxUrl, {
            action: 'kapsule_start_standalone',
            nonce:  cfg.nonce
        }, function (resp) {
            if (resp.success) {
                window.location.reload();
            } else {
                showFormError($err, reasonOf(resp) || __('We could not start packaging. Please try again.', 'kapsule-migrator'));
                $btn.prop('disabled', false).text(__('Package this site', 'kapsule-migrator'));
            }
        }).fail(function () {
            showFormError($err, __('We could not start packaging. Please try again.', 'kapsule-migrator'));
            $btn.prop('disabled', false).text(__('Package this site', 'kapsule-migrator'));
        });
    });

    $(document).on('click', '#kapsule-reset-btn', function () {
        cancelled = true;
        $(this).prop('disabled', true).text(__('Cleaning up...', 'kapsule-migrator'));

        $.post(cfg.ajaxUrl, {
            action: 'kapsule_reset',
            nonce:  cfg.nonce
        }).always(function () {
            window.location.reload();
        });
    });

    // ── The transfer ─────────────────────────────────────────────────────────

    function reportProgress(bytesDone, totalBytes, chunkIndex, chunkCount) {
        if (baselineBytes === null) baselineBytes = bytesDone;

        var pct = totalBytes > 0 ? (bytesDone / totalBytes) * 100 : 0;

        // Rate is measured from THIS page load only. Extrapolating across a resumed run would divide
        // hours of previous transfer by seconds of current uptime and promise a finish time that is
        // not real. An honest blank beats a confident wrong number.
        var elapsed = (Date.now() - startedAt) / 1000;
        var moved   = bytesDone - baselineBytes;
        var note    = pieceOf(chunkIndex, chunkCount);

        if (elapsed > 15 && moved > 0 && totalBytes > bytesDone) {
            var eta = fmtEta((totalBytes - bytesDone) / (moved / elapsed));
            /* translators: 1: which piece is moving, 2: estimated time remaining. Joined into one line. */
            if (eta) note = sprintf(__('%1$s, %2$s', 'kapsule-migrator'), note, eta);
        }

        setMeter(pct, note);
            $('#km-f-pieces').text(pair(fmtCount(chunkIndex), fmtCount(chunkCount)));
        $('#km-f-moved').text(fmtBytes(bytesDone));
    }

    function runChunkLoop(chunkCount, totalBytes, index, attempt) {
        if (cancelled) return;

        if (index >= chunkCount) {
            uploadDbAndComplete(totalBytes);
            return;
        }

        attempt = attempt || 1;

        $.post(cfg.ajaxUrl, {
            action: 'kapsule_upload_chunk',
            nonce:  cfg.nonce,
            index:  index
        }, function (resp) {
            if (cancelled) return;

            if (resp.success) {
                var bytesDone  = resp.data.bytesDone  || 0;
                var knownTotal = totalBytes || resp.data.totalBytes || 0;

                // A piece landed, so the connection is healthy again whatever happened before it.
                if (attempt > 1) restoreTransferringState();

                reportProgress(bytesDone, knownTotal, index + 1, chunkCount);
                runChunkLoop(chunkCount, knownTotal, index + 1, 1);
                return;
            }

            var data = resp.data || {};
            if (data.retryable === false) {
                // Permanent. The server has already recorded it; reload so the customer reads the
                // real reason on the error card rather than a guess from here.
                window.location.reload();
                return;
            }
            scheduleRetry(chunkCount, totalBytes, index, attempt, reasonOf(resp));

        }).fail(function (xhr) {
            if (cancelled) return;
            // A transport failure IS the retryable case: a timeout, a dropped connection, a 502 from
            // the far side reloading. Reloading the page here (as this used to) throws away a live
            // migration over one bad request.
            var why;
            if (xhr && xhr.status) {
                /* translators: %s: the HTTP status code the server returned, e.g. "502". */
                why = sprintf(__('the connection returned %s', 'kapsule-migrator'), xhr.status);
            } else {
                why = __('the connection dropped', 'kapsule-migrator');
            }
            scheduleRetry(chunkCount, totalBytes, index, attempt, why);
        });
    }

    /** The one string carrying markup. Assembled here so the emphasis cannot be lost in translation. */
    function keepTabOpenHtml() {
        return '<strong>' + escapeHtml(__('Keep this tab open.', 'kapsule-migrator')) + '</strong> ' +
               escapeHtml(__('The move runs from here, so closing the tab pauses it. Nothing is lost if you do: reopen this page and it carries on from the last piece that arrived.', 'kapsule-migrator'));
    }

    function restoreTransferringState() {
        setChip('transferring', __('Copying files', 'kapsule-migrator'));
        setHead(__('Moving your site', 'kapsule-migrator'),
                __('Your site is being copied across in pieces. It stays live and unchanged the whole time.', 'kapsule-migrator'));
        setLive(true);
        setFact4(__('Files', 'kapsule-migrator'), fmtCount(cfg.fileCount));
        setNote('info', keepTabOpenHtml());
    }

    function scheduleRetry(chunkCount, totalBytes, index, attempt, why) {
        if (attempt >= MAX_ATTEMPTS) {
            showPaused(chunkCount, totalBytes, index, why);
            return;
        }

        var wait = backoffSeconds(attempt);
        var next = attempt + 1;

        setChip('retrying', __('Retrying', 'kapsule-migrator'));
        setHead(__('The connection dropped', 'kapsule-migrator'),
                /* translators: %s: the number of the piece being retried. */
                sprintf(__('We are retrying piece %s. Everything already sent is kept, so this carries on from where it stopped rather than starting again.', 'kapsule-migrator'),
                        fmtCount(index + 1)));
        setLive(false);
        setFact4(__('Attempt', 'kapsule-migrator'), pair(fmtCount(next), fmtCount(MAX_ATTEMPTS)));
        setNote('warn', escapeHtml(__('Nothing has been lost. Your site is untouched and still serving visitors. If this keeps failing we will tell you exactly what to do next.', 'kapsule-migrator')));

        var remaining = wait;
        (function tick() {
            if (cancelled) return;
            var countdown;
            if (remaining > 0) {
                /* translators: 1: seconds until the next attempt, 2: the attempt number, 3: total attempts. */
                var pattern = _n('retrying in %1$s second, attempt %2$s of %3$s',
                                 'retrying in %1$s seconds, attempt %2$s of %3$s', remaining, 'kapsule-migrator');
                countdown = sprintf(pattern, fmtCount(remaining), fmtCount(next), fmtCount(MAX_ATTEMPTS));
            } else {
                /* translators: 1: the attempt number, 2: total attempts. */
                countdown = sprintf(__('retrying now, attempt %1$s of %2$s', 'kapsule-migrator'), fmtCount(next), fmtCount(MAX_ATTEMPTS));
            }
            $('#km-meter-note').text(countdown);
            if (remaining <= 0) {
                runChunkLoop(chunkCount, totalBytes, index, next);
                return;
            }
            remaining--;
            setTimeout(tick, 1000);
        })();
    }

    /** Same curve the server uses on the WP-Cron path, so behaviour does not depend on who is driving. */
    function backoffSeconds(attempt) {
        return Math.min(Math.pow(2, Math.max(0, attempt - 1)) * 2, 60);
    }

    /**
     * Out of attempts. NOT an error: the migration is intact and every accepted piece is still
     * accepted, so the honest word is paused. Declaring it dead would be a lie that costs the
     * customer everything transferred so far.
     */
    function showPaused(chunkCount, totalBytes, index, why) {
        setChip('error', __('Paused', 'kapsule-migrator'));
        setHead(__('We have paused the move', 'kapsule-migrator'),
                /* translators: 1: how many attempts were made, 2: why the connection failed. */
                sprintf(__('We could not reach KapsuleHost after %1$s tries (%2$s). Nothing is lost. Everything already copied is still there, and this site has not been changed.', 'kapsule-migrator'),
                        fmtCount(MAX_ATTEMPTS), why || __('the connection dropped', 'kapsule-migrator')));
        setLive(false);
        setFact4(__('Stopped at', 'kapsule-migrator'), pieceOf(index, chunkCount));
        /* translators: %s: describes which piece the transfer stopped on, e.g. "piece 57 of 119". */
        $('#km-meter-note').text(sprintf(__('paused at %s', 'kapsule-migrator'), pieceOf(index, chunkCount)));
        setNote('error', escapeHtml(__('Check this server can reach the internet, then pick up where you left off. Nothing needs to be re-sent.', 'kapsule-migrator')));

        if (!$('#km-resume-btn').length) {
            $('<button id="km-resume-btn" class="km-btn km-btn--primary"></button>')
                .text(__('Pick up where it stopped', 'kapsule-migrator'))
                .prependTo('.km-actions')
                .on('click', function () {
                    $(this).remove();
                    restoreTransferringState();
                    startedAt     = Date.now();
                    baselineBytes = null;
                    runChunkLoop(chunkCount, totalBytes, index, 1);
                });
        }
    }

    function uploadDbAndComplete(totalBytes) {
        if (cancelled) return;

        setStep('kstep-db');
        setChip('transferring', __('Copying database', 'kapsule-migrator'));
        setHead(__('Copying your database', 'kapsule-migrator'),
                __('The files are across. We are copying your database now, which is usually the quickest part.', 'kapsule-migrator'));
        setLive(true);
        setMeter(95, __('database', 'kapsule-migrator'));

        $.post(cfg.ajaxUrl, {
            action: 'kapsule_upload_db_and_complete',
            nonce:  cfg.nonce
        }, function () {
            if (cancelled) return;
            setStep('kstep-done');
            setMeter(100, __('finishing', 'kapsule-migrator'));
            window.location.reload();
        }).fail(function () {
            if (!cancelled) window.location.reload();
        });
    }

    // ── Boot ─────────────────────────────────────────────────────────────────

    function statusLabel(status) {
        switch (status) {
            case 'preflight':            return __('Checking the connection', 'kapsule-migrator');
            case 'scanning':             return __('Counting your files', 'kapsule-migrator');
            case 'uploading_files':      return __('Copying files', 'kapsule-migrator');
            case 'uploading_db':         return __('Copying database', 'kapsule-migrator');
            case 'standalone_packaging': return __('Packaging', 'kapsule-migrator');
            default:                     return '';
        }
    }

    function pollStatus(delay) {
        setTimeout(function () {
            $.post(cfg.ajaxUrl, {
                action: 'kapsule_get_status',
                nonce:  cfg.nonce
            }, function (resp) {
                if (!resp.success) return;
                var data = resp.data || {};

                var label = statusLabel(data.status);
                if (label) setChip(null, label);

                if (data.progress && data.progress.totalBytes > 0) {
                    setMeter(Math.min(95, (data.progress.bytesTransferred / data.progress.totalBytes) * 100));
                }

                if (data.status === 'complete' || data.status === 'error' || data.status === 'standalone_ready') {
                    window.location.reload();
                } else if (data.status === 'uploading_db') {
                    pollStatus(3000);
                } else if (data.status === 'standalone_packaging') {
                    pollStatus(5000);
                }
            });
        }, delay);
    }

    if (cfg.status === 'uploading_files' && cfg.chunkCount > 0) {
        setStep('kstep-files');
        restoreTransferringState();

        var begin = function (from) {
            reportProgress(
                cfg.totalBytes > 0 && cfg.chunkCount > 0 ? (from / cfg.chunkCount) * cfg.totalBytes : 0,
                cfg.totalBytes, from, cfg.chunkCount
            );
            runChunkLoop(cfg.chunkCount, cfg.totalBytes, from, 1);
        };

        // Ask the SERVER which pieces it holds. The local counter can be stale from an aborted run,
        // and the far side is the only party that knows what actually arrived.
        if (cfg.token && cfg.apiBase) {
            $.getJSON(cfg.apiBase + '/chunks?token=' + encodeURIComponent(cfg.token))
                .done(function (manifest) {
                    begin(typeof manifest.firstGap === 'number' ? manifest.firstGap : (cfg.nextChunk || 0));
                })
                .fail(function () {
                    begin(cfg.nextChunk || 0);
                });
        } else {
            begin(cfg.nextChunk || 0);
        }

    } else if (cfg.status === 'uploading_db') {
        setStep('kstep-db');
        setMeter(95, __('database', 'kapsule-migrator'));
        pollStatus(3000);

    } else if (cfg.status === 'standalone_packaging') {
        pollStatus(5000);
    }

})(jQuery);
