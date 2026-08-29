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

    /*
     * THE WORDS WITHOUT THE NUMBER.
     *
     * Every caller below used to pass a percentage of its own alongside its message: 95 when the
     * database started, 97 when the upload finished, and a local bytes/total calculation while polling.
     * Those are what Jesse watched jump from 95 to 97 and then back to 48 when KapsuleHost's real
     * figure arrived. The messages were right and the numbers were a second opinion.
     *
     * The percentage now comes from KapsuleHost and from nowhere else. These callers say what is
     * happening and touch the meter's number never.
     */
    function setMeterNote(text) {
        $('#km-meter-note').text(text);
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

    /*
     * THE STEP LIST IS KAPSULEHOST'S, NOT THIS SCREEN'S.
     *
     * This screen had four hand-written rows while the KPanel screen had twelve, so one moment was
     * called "Checking what arrived" here and "Importing database" there. Jesse's specification,
     * 2026-08-29: pick one set of step names, serve it, render the same names on both screens.
     *
     * The rows are now REBUILT from `display.steps` on every poll that carries one. The four static
     * rows in the PHP remain as the first paint, before any reply has arrived, so the card is never
     * empty; the moment KapsuleHost speaks they are replaced by its list.
     */
    /*
     * THE PLUGIN KEEPS ITS OWN FOUR STEPS, DRIVEN BY KAPSULEHOST'S PHASE.
     *
     * An earlier version replaced these rows with KPanel's full list. Jesse's clarification,
     * 2026-08-29: KPanel having more steps is fine, and what this screen must make unmistakable is
     * that the FILES ARE COMPLETE and the rest is happening on KapsuleHost.
     *
     * So the four rows stay, and which one is lit comes from the served phase rather than from this
     * screen guessing. Everything after the upload maps to the last row, "KapsuleHost puts it
     * together", which is the honest single name for the nine things KPanel itemises.
     *
     * THE PERCENTAGE IS UNAFFECTED BY ANY OF THIS. It is KapsuleHost's number on both screens at all
     * times, whatever either list of steps says.
     */
    var PHASE_TO_STEP = {
        queued:         'kstep-scan',
        preflight:      'kstep-scan',
        connecting:     'kstep-scan',
        scanning:       'kstep-scan',
        uploading:      'kstep-files',
        receiving:      'kstep-files',
        // Everything below is ours, not theirs. One row, so this screen never implies the customer
        // still has something to do.
        provisioning:   'kstep-done',
        unpacking:      'kstep-done',
        placing_files:  'kstep-done',
        pulling_files:  'kstep-done',
        importing_db:   'kstep-done',
        search_replace: 'kstep-done',
        verifying:      'kstep-done',
        done:           'kstep-done'
    };

    function renderServedSteps(display) {
        if (!display || !display.stepKey) return;
        var row = PHASE_TO_STEP[display.stepKey];
        if (!row) return;
        setStep(row);
        // Once the sending is finished, say so on the row itself rather than leaving a customer to
        // wonder whether their browser still has work to do.
        if (row === 'kstep-done') {
            $('#kstep-files').attr('data-done', '1').attr('data-on', '0');
            $('#kstep-db').attr('data-done', '1').attr('data-on', '0');
        }
    }

    /*
     * ONE PERCENT, ONE LABEL, ONE ESTIMATE, ALL OF THEM THEIRS.
     *
     * Everything this screen used to work out for itself is gone: the percentage, the step wording and
     * the time remaining. A screen that calculates is a screen that can disagree, and it disagrees
     * invisibly to whoever wrote either half. `display` is null on a reply that knows nothing, and a
     * null must leave what is on screen exactly as it is rather than blanking it.
     */
    function renderDisplay(display) {
        if (!display) return;
        if (typeof display.percent === 'number') { lastServerPct = display.percent; }
        renderServedSteps(display);
        var note = display.pieces
            ? pieceOf(display.pieces.done, display.pieces.total)
            : (display.stepLabel || '');
        if (typeof display.etaSeconds === 'number' && display.etaSeconds >= 30) {
            note = note ? (note + ', ' + fmtEta(display.etaSeconds)) : fmtEta(display.etaSeconds);
        }
        if (lastServerPct !== null) setMeter(lastServerPct, note || undefined);
        else if (note) $('#km-meter-note').text(note);
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

    /**
     * "piece N of M", where N is a COUNT OF PIECES DONE, never a zero-based index.
     *
     * This took an index and added one, which was right while the only caller passed `chunkIndex`
     * (0-based, what this browser has finished sending). It stopped being right the moment the count
     * was unified onto the server's `chunksReceived`, which is already a COUNT: two pieces landed
     * rendered here as "piece 3 of 10" while the panel, reading the very same field, said 2 of 10.
     *
     * IT ALSO DISAGREED WITH THIS SCREEN'S OWN FACT ROW, which printed the count raw and so said
     * "2 / 10" beside a meter note saying "piece 3 of 10". One value, three renderings, two wrong.
     *
     * The argument is a COUNT now. Every caller converts its own value once, and the +1 lives at the
     * call sites that genuinely hold an index.
     */
    /*
     * WHAT THE SERVER LAST TOLD US, held at module scope on purpose: these outlive one poll, which is
     * the entire point. A reply that carries no numbers must not be able to change what is on screen.
     */
    var lastServerPct = null;
    var lastServerBytes = null;

    function pieceOf(done, total) {
        return sprintf(__('piece %1$s of %2$s', 'kapsule-migrator'), fmtCount(Math.min(done, total)), fmtCount(total));
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

    /**
     * `server` is what KapsuleHost answered on the last progress call, or undefined when we have
     * not heard from them yet (the very first paint, or an older server).
     */
    function reportProgress(bytesDone, totalBytes, chunkIndex, chunkCount, server) {
        if (baselineBytes === null) baselineBytes = bytesDone;

        /*
         * ONE PERCENTAGE, AND IT IS KAPSULEHOST'S.
         *
         * This screen used to compute `bytesDone / totalBytes * 100`, which is a perfectly correct
         * answer to "how far through the upload am I" and a different number from the one the
         * KapsuleHost panel shows, which covers the whole move including unpacking, importing and
         * rewriting after the upload ends. Two honest numbers for one migration is still a
         * contradiction to whoever is reading both, so this renders theirs.
         *
         * The local calculation stays as the fallback and is used ONLY when they have not told us
         * one. A blank meter on a working transfer would be worse than a number that is about the
         * upload alone, and an older server that does not send `progress` must still show movement.
         */
        /*
         * ONCE THEY HAVE TOLD US A NUMBER, NEVER RENDER A DIFFERENT QUANTITY AGAIN.
         *
         * This fell back to the local upload percentage whenever `server.progress` was missing from a
         * reply, and the server has a path that answers a progress poll with no fields at all. So
         * consecutive polls alternated between two DIFFERENT MEASUREMENTS of two different things, and
         * the meter read 95, then 97, then back to 48 in front of Jesse. Both numbers were honest.
         * Neither was wrong. Showing them in the same place is what made the bar run backwards.
         *
         * A missing value is not a reason to substitute a different quantity. It is a reason to keep
         * showing the last one we were given:
         *
         *   never had a server number     the local upload figure, so a working transfer is not blank
         *                                 and an older server still shows movement
         *   have one, this reply lacks    the LAST server number, unchanged, because a stale true
         *                                 number beats a fresh number about something else
         *   this reply has one            use it
         *
         * The bar can therefore stall for a poll. It cannot go backwards, and it cannot quietly change
         * what it is measuring, which is the part a customer cannot see happening.
         */
        var localPct = totalBytes > 0 ? (bytesDone / totalBytes) * 100 : 0;
        if (server && typeof server.progress === 'number') { lastServerPct = server.progress; }
        var pct = (lastServerPct !== null) ? lastServerPct : localPct;

        /*
         * MOVED IS WHAT THEY HOLD, NOT WHAT WE BELIEVE WE SENT.
         *
         * Reported side by side: this screen said 473.6 MB while the panel said 495 MB. Not a units
         * mismatch, both format in 1024s. Two genuinely different counts, and theirs is the one that
         * matters because it is what has actually arrived. The local figure stays as the fallback for
         * an older server, because a blank where a number was is worse than a number about the upload.
         */
        // Same rule as the percentage, for the same reason: what THEY hold, remembered, never swapped
        // back to what this browser believes it sent just because one reply came back thin.
        if (server && typeof server.bytesReceived === 'number') { lastServerBytes = server.bytesReceived; }
        var shownBytes = (lastServerBytes !== null) ? lastServerBytes : bytesDone;

        // Rate is measured from THIS page load only. Extrapolating across a resumed run would divide
        // hours of previous transfer by seconds of current uptime and promise a finish time that is
        // not real. An honest blank beats a confident wrong number.
        var elapsed = (Date.now() - startedAt) / 1000;
        var moved   = bytesDone - baselineBytes;

        /*
         * ONE PIECE COUNT, AND IT IS WHAT LANDED RATHER THAN WHAT WAS SENT.
         *
         * `chunkIndex` is what this browser has finished sending. `server.chunksReceived` is what
         * KapsuleHost has on disk, counted from the directory. They differ by whatever is in flight,
         * which is at least one piece for the whole of every upload, and that difference is what put
         * two different counts on the two screens. Theirs is both the truthful one and the one the
         * panel shows, so it wins where we have it.
         */
        // `chunksReceived` is already a COUNT of what landed. `chunkIndex` is 0-based, so it becomes a
        // count by adding one. Normalising HERE is what stops the two screens differing by exactly one.
        var shownDone = (server && typeof server.chunksReceived === 'number') ? server.chunksReceived : (chunkIndex + 1);
        var shownTotal = (server && typeof server.chunkCount === 'number' && server.chunkCount > 0) ? server.chunkCount : chunkCount;
        var note    = pieceOf(shownDone, shownTotal);

        /*
         * AND ONE TIME ESTIMATE, THEIRS. Two surfaces each deriving one from their own rate over their
         * own window cannot agree except by accident, which is why this screen said "about 1 minute
         * left" while the panel said "about 1 minutes left". KapsuleHost computes it once now.
         */
        var srvEta = (server && typeof server.etaSeconds === 'number') ? server.etaSeconds : null;
        if (srvEta !== null) {
            var eta = fmtEta(srvEta);
            /* translators: 1: which piece is moving, 2: estimated time remaining. Joined into one line. */
            if (eta) note = sprintf(__('%1$s, %2$s', 'kapsule-migrator'), note, eta);
        } else if (elapsed > 15 && moved > 0 && totalBytes > bytesDone) {
            var eta = fmtEta((totalBytes - bytesDone) / (moved / elapsed));
            /* translators: 1: which piece is moving, 2: estimated time remaining. Joined into one line. */
            if (eta) note = sprintf(__('%1$s, %2$s', 'kapsule-migrator'), note, eta);
        }

        /*
         * THEIRS WINS. If this reply carried a display block, it decides the percentage, the wording
         * and the estimate, and the lines above become the fallback for a server too old to send one.
         */
        if (server && server.display) { renderDisplay(server.display); }
        else { setMeter(pct, note); }
        // The same count as the note above and as the panel, rather than this browser's send count.
        $('#km-f-pieces').text(pair(fmtCount(shownDone), fmtCount(shownTotal)));
        $('#km-f-moved').text(fmtBytes(shownBytes));
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

                // STOPPED FROM THE KAPSULEHOST PANEL. Learned on the very next piece, because the
                // progress call now answers with it. Before this the plugin could not find out at
                // all: it kept sending pieces at a job that had ended, and kept showing a live
                // transfer to a customer who had stopped it from the other screen. Reload rather
                // than draw the stopped state out here, so there is one implementation of that card
                // (PHP's) and the two cannot disagree.
                if (resp.data.stopped) {
                    cancelled = true;
                    window.location.reload();
                    return;
                }

                // A piece landed, so the connection is healthy again whatever happened before it.
                if (attempt > 1) restoreTransferringState();

                reportProgress(bytesDone, knownTotal, index + 1, chunkCount, resp.data);
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
        setFact4(__('Stopped at', 'kapsule-migrator'), pieceOf(index + 1, chunkCount));
        /* translators: %s: describes which piece the transfer stopped on, e.g. "piece 57 of 119". */
        $('#km-meter-note').text(sprintf(__('paused at %s', 'kapsule-migrator'), pieceOf(index + 1, chunkCount)));
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

    /**
     * Send the database, then HAND OVER. This function does not decide anything is finished.
     *
     * WHAT IT USED TO DO, and it is the browser half of the defect this file's PHP counterpart
     * describes: on a successful POST it called `setStep('kstep-done')` and `setMeter(100, ...)`. The
     * fourth step in this plugin's own list is labelled "KapsuleHost puts it together", so ticking it
     * the instant the upload returned was claiming the far side had done work it had not started. The
     * customer read 100%, and the reload then drew a completion screen off a local flag.
     *
     * The honest end of the browser's job is: the last byte left this server. The meter stops at 97
     * because 100 is a claim about the whole migration and the browser cannot see the whole migration.
     */
    function uploadDbAndComplete(totalBytes) {
        if (cancelled) return;

        setStep('kstep-db');
        setChip('transferring', __('Copying database', 'kapsule-migrator'));
        setHead(__('Copying your database', 'kapsule-migrator'),
                __('The files are across. We are copying your database now, which is usually the quickest part.', 'kapsule-migrator'));
        setLive(true);
        setMeterNote(__('database', 'kapsule-migrator'));

        $.post(cfg.ajaxUrl, {
            action: 'kapsule_upload_db_and_complete',
            nonce:  cfg.nonce
        }, function () {
            if (cancelled) return;
            // The upload is over. The fourth step belongs to KapsuleHost and is NOT ticked here: the
            // page reloads into the awaiting-import screen, which reports what the JOB says.
            setChip('transferring', __('Handing over to KapsuleHost', 'kapsule-migrator'));
            setLive(false);
            // The moment the customer needs named plainly: their side is finished and ours is running.
            setMeterNote(__('All files sent. KapsuleHost is finishing the move.', 'kapsule-migrator'));
            window.location.reload();
        }).fail(function () {
            if (!cancelled) window.location.reload();
        });
    }

    // ── Waiting on the job ───────────────────────────────────────────────────
    //
    // Once the upload is done this screen shows the JOB's state and nothing else. Every value below is
    // read from KapsuleHost through the plugin's own PHP (which is where the state is cached, because
    // PHP is what renders the card on the next load). There is no local fallback: if the poll fails,
    // the screen keeps saying what it last knew came from the server, and the reload path renders the
    // explicit "we cannot check" card rather than anything reassuring.

    /** Statuses after which polling is pointless because the job has stopped moving. */
    var JOB_TERMINAL = ['COMPLETED', 'COMPLETED_WITH_ERRORS', 'FAILED', 'CANCELLED', 'OPS_ESCALATED'];

    function pollJob(delay) {
        setTimeout(function () {
            $.post(cfg.ajaxUrl, {
                action: 'kapsule_job_status',
                nonce:  cfg.nonce
            }, function (resp) {
                if (cancelled) return;

                if (!resp.success) {
                    // Unreachable. Say so on the meter note rather than freezing a number that reads
                    // like progress, and keep trying.
                    $('#km-job-note').text(__('we cannot reach KapsuleHost just now, still trying', 'kapsule-migrator'));
                    pollJob(15000);
                    return;
                }

                var job = resp.data || {};

                if (JOB_TERMINAL.indexOf(job.status) !== -1) {
                    // The outcome, whatever it is, is rendered by PHP. Reload rather than build a
                    // second implementation of the outcome cards out here that could disagree with it.
                    window.location.reload();
                    return;
                }

                var pct = Math.max(0, Math.min(99, parseInt(job.progress, 10) || 0));
                $('#km-job-pct').text(fmtCount(pct));
                $('#km-job-fill').css('width', pct + '%');
                if (job.phaseMessage) $('#km-job-note').text(job.phaseMessage);

                pollJob(8000);
            }).fail(function () {
                if (cancelled) return;
                $('#km-job-note').text(__('we cannot reach KapsuleHost just now, still trying', 'kapsule-migrator'));
                pollJob(15000);
            });
        }, delay);
    }

    $(document).on('click', '#kapsule-recheck-btn', function () {
        $(this).prop('disabled', true).text(__('Checking...', 'kapsule-migrator'));
        $.post(cfg.ajaxUrl, {
            action: 'kapsule_job_status',
            nonce:  cfg.nonce
        }).always(function () {
            window.location.reload();
        });
    });

    // ── Boot ─────────────────────────────────────────────────────────────────

    function statusLabel(status) {
        switch (status) {
            case 'preflight':            return __('Checking the connection', 'kapsule-migrator');
            case 'scanning':             return __('Counting your files', 'kapsule-migrator');
            case 'uploading_files':      return __('Copying files', 'kapsule-migrator');
            case 'uploading_db':         return __('Copying database', 'kapsule-migrator');
            case 'awaiting_import':      return __('KapsuleHost is working on it', 'kapsule-migrator');
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
                    // The percentage is KapsuleHost's. A local bytes/total figure here is a second
                    // opinion about a different quantity, and it is what made the bar run backwards.
                }

                if (data.status === 'awaiting_import' || data.status === 'error' || data.status === 'standalone_ready') {
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
        setMeterNote(__('database', 'kapsule-migrator'));
        pollStatus(3000);

    } else if (cfg.status === 'awaiting_import') {
        // The upload is finished and the JOB is the only thing that knows anything now.
        pollJob(4000);

    } else if (cfg.status === 'standalone_packaging') {
        pollStatus(5000);
    }

})(jQuery);
