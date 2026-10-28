/**
 * R2 Cloud Storage — Admin JavaScript
 *
 * @package R2CloudStorage
 */

/* global jQuery, r2csAdmin */
(function ($) {
    'use strict';

    var R2CS = {
        syncing: false,
        paused: false,
        syncType: null,

        init: function () {
            this.bindEvents();
            this.loadStats();
            if ($('#r2cs-unsynced-table').length) {
                this.loadUnsyncedFiles(0);
            }
        },

        bindEvents: function () {
            $(document).on('click', '#r2cs-test-connection', this.testConnection.bind(this));
            $(document).on('click', '#r2cs-start-sync', this.startSync.bind(this));
            $(document).on('click', '#r2cs-start-folder-sync', this.startFolderSync.bind(this));
            $(document).on('click', '#r2cs-sync-all-unsynced-btn', this.syncAllUnsynced.bind(this));
            $(document).on('click', '#r2cs-refresh-unsynced-btn', this.refreshUnsynced.bind(this));
            $(document).on('click', '.r2cs-btn-sync-single', this.syncSingleFile.bind(this));
            $(document).on('input', '#r2cs-unsynced-search', this.debounceSearch.bind(this));
            $(document).on('click', '#r2cs-prev-page', this.prevUnsyncedPage.bind(this));
            $(document).on('click', '#r2cs-next-page', this.nextUnsyncedPage.bind(this));
            $(document).on('click', '#r2cs-reset-failed-btn', this.resetFailed.bind(this));
            $(document).on('click', '#r2cs-stop-sync', this.stopSync.bind(this));
        },

        /* ── Stats ─────────────────────────────────────── */

        loadStats: function () {
            $.ajax({
                url: r2csAdmin.restUrl + 'stats',
                method: 'GET',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', r2csAdmin.restNonce);
                },
                success: function (res) {
                    if (res.success && res.data) {
                        if (res.data.media) {
                            $('#r2cs-stat-total').text(res.data.media.total);
                            $('#r2cs-stat-offloaded').text(res.data.media.offloaded);
                            $('#r2cs-stat-pending').text(res.data.media.pending);
                            $('#r2cs-stat-failed').text(res.data.media.failed || 0);
                            $('#r2cs-stat-addons').text(res.data.addons || 0);
                        }
                        if (res.data.unsynced !== undefined) {
                            $('#r2cs-stat-unsynced').text(res.data.unsynced);
                            $('#r2cs-unsynced-badge').text(res.data.unsynced + ' files');
                        }
                    }
                }
            });
        },

        /* ── Test Connection ───────────────────────────── */

        testConnection: function (e) {
            e.preventDefault();
            var $btn = $('#r2cs-test-connection');
            var $result = $('#r2cs-test-result');

            $btn.prop('disabled', true);
            $result.removeClass('success error').addClass('loading').text(r2csAdmin.i18n.testing).show();

            $.ajax({
                url: r2csAdmin.restUrl + 'test-connection',
                method: 'POST',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', r2csAdmin.restNonce);
                },
                success: function (res) {
                    $result.removeClass('loading').addClass('success').text(res.message);
                },
                error: function (xhr) {
                    var msg = r2csAdmin.i18n.error;
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    }
                    $result.removeClass('loading').addClass('error').text(msg);
                },
                complete: function () {
                    $btn.prop('disabled', false);
                }
            });
        },

        /* ── Sync ──────────────────────────────────────── */

        resetFailed: function (e) {
            if (e) e.preventDefault();
            var self = this;
            var $btn = $('#r2cs-reset-failed-btn');
            $btn.prop('disabled', true);

            $.ajax({
                url: r2csAdmin.restUrl + 'sync/reset-failed',
                method: 'POST',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', r2csAdmin.restNonce);
                },
                success: function (res) {
                    $('.r2cs-sync-log').show();
                    self.addLog(res.message || r2csAdmin.i18n.failedReset, 'success');
                    self.loadStats();
                },
                error: function (xhr) {
                    self.addLog(r2csAdmin.i18n.error, 'error');
                },
                complete: function () {
                    $btn.prop('disabled', false);
                }
            });
        },

        startSync: function (e) {
            if (e) e.preventDefault();

            if (this.syncing) return;
            this.syncing = true;
            this.paused = false;
            this.syncType = 'media';
            this.retryCount = 0;

            var retryFailed = $('#r2cs-retry-failed-checkbox').is(':checked');
            var failedCount = parseInt($('#r2cs-stat-failed').text(), 10) || 0;
            if (!retryFailed && failedCount > 0) {
                retryFailed = true;
                $('#r2cs-retry-failed-checkbox').prop('checked', true);
            }

            var $startBtn = $('#r2cs-start-sync');
            var $folderBtn = $('#r2cs-start-folder-sync');
            var $stopBtn = $('#r2cs-stop-sync');

            $startBtn.hide();
            $folderBtn.hide();
            $stopBtn.show();
            $('.r2cs-progress-container').show();
            $('#r2cs-sync-log').show();
            $('#r2cs-sync-status').text(r2csAdmin.i18n.syncStarting || 'Starting sync...');

            this.addLog(r2csAdmin.i18n.syncStarting);

            var self = this;
            $.ajax({
                url: r2csAdmin.restUrl + 'sync/start',
                method: 'POST',
                data: JSON.stringify({ reset_failed: retryFailed }),
                contentType: 'application/json',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', r2csAdmin.restNonce);
                },
                success: function () {
                    self.runBatch();
                },
                error: function (xhr) {
                    var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : r2csAdmin.i18n.error;
                    self.addLog(msg, 'error');
                    self.finishSync();
                }
            });
        },

        startFolderSync: function (e) {
            if (e) e.preventDefault();

            if (this.syncing) return;
            this.syncing = true;
            this.paused = false;
            this.syncType = 'folder';
            this.retryCount = 0;

            var $startBtn = $('#r2cs-start-sync');
            var $folderBtn = $('#r2cs-start-folder-sync');
            var $stopBtn = $('#r2cs-stop-sync');

            $startBtn.hide();
            $folderBtn.hide();
            $stopBtn.show();
            $('.r2cs-progress-container').show();
            $('#r2cs-sync-log').show();
            $('#r2cs-sync-status').text('Checking uploads directory...');

            this.addLog(r2csAdmin.i18n.folderSyncStarting || 'Checking uploads folder and starting sync...');

            var self = this;
            var skipExisting = $('#r2cs-skip-existing-checkbox').is(':checked');

            $.ajax({
                url: r2csAdmin.restUrl + 'sync/folder/start',
                method: 'POST',
                data: JSON.stringify({ skip_existing: skipExisting }),
                contentType: 'application/json',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', r2csAdmin.restNonce);
                },
                success: function (res) {
                    if (res.message) {
                        self.addLog(res.message, res.resumed ? 'warning' : 'info');
                    }
                    if (res.progress) {
                        self.updateFolderProgress(res.progress);
                    }
                    self.runFolderBatch();
                },
                error: function (xhr) {
                    var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : r2csAdmin.i18n.error;
                    self.addLog(msg, 'error');
                    self.finishSync();
                }
            });
        },

        stopSync: function (e) {
            e.preventDefault();
            this.paused = true;
            this.syncing = false;

            $('#r2cs-start-sync').show().text(r2csAdmin.i18n.syncResumed);
            $('#r2cs-start-folder-sync').show();
            $('#r2cs-stop-sync').hide();
            $('#r2cs-sync-status').text(r2csAdmin.i18n.paused);
            this.addLog(r2csAdmin.i18n.syncPaused, 'warning');
        },

        runBatch: function () {
            var self = this;

            if (this.paused || !this.syncing || this.syncType !== 'media') return;

            var retryFailed = $('#r2cs-retry-failed-checkbox').is(':checked');

            $.ajax({
                url: r2csAdmin.restUrl + 'sync/batch',
                method: 'POST',
                data: JSON.stringify({ batch_size: 10, retry_failed: retryFailed }),
                contentType: 'application/json',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', r2csAdmin.restNonce);
                },
                success: function (res) {
                    self.retryCount = 0;

                    if (!res.success) {
                        self.addLog(r2csAdmin.i18n.error + ': ' + (res.message || r2csAdmin.i18n.errorUnknown), 'error');
                        self.finishSync();
                        return;
                    }

                    var data = res.data;
                    self.addLog(
                        r2csAdmin.i18n.batchSuccess.replace('%1$d', data.success).replace('%2$d', data.processed),
                        data.errors.length > 0 ? 'error' : 'success'
                    );

                    // Log individual errors
                    if (data.errors && data.errors.length > 0) {
                        data.errors.forEach(function (err) {
                            self.addLog('  ' + r2csAdmin.i18n.errorId.replace('%1$d', err.id).replace('%2$s', err.message), 'error');
                        });
                    }

                    // Update progress
                    self.updateProgress(data.remaining);

                    // Continue if there are more
                    if (data.remaining > 0 && self.syncing && !self.paused) {
                        setTimeout(function () {
                            self.runBatch();
                        }, 500);
                    } else if (data.remaining === 0) {
                        self.addLog(r2csAdmin.i18n.syncComplete, 'success');
                        self.finishSync();
                    }
                },
                error: function (xhr) {
                    self.retryCount = (self.retryCount || 0) + 1;
                    if (self.retryCount <= 3 && self.syncing && !self.paused) {
                        self.addLog('Network glitch detected. Retrying batch in 3 seconds... (Attempt ' + self.retryCount + ' of 3)', 'warning');
                        setTimeout(function () {
                            self.runBatch();
                        }, 3000);
                        return;
                    }
                    self.retryCount = 0;
                    var msg = r2csAdmin.i18n.networkError;
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    }
                    self.addLog(r2csAdmin.i18n.error + ': ' + msg, 'error');
                    self.finishSync();
                }
            });
        },

        runFolderBatch: function () {
            var self = this;

            if (this.paused || !this.syncing || this.syncType !== 'folder') return;

            var skipExisting = $('#r2cs-skip-existing-checkbox').is(':checked');
            var removeLocal  = $('#r2cs-remove-local-checkbox').is(':checked');

            $.ajax({
                url: r2csAdmin.restUrl + 'sync/folder/batch',
                method: 'POST',
                data: JSON.stringify({ batch_size: 15, skip_existing: skipExisting, remove_local: removeLocal }),
                contentType: 'application/json',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', r2csAdmin.restNonce);
                },
                success: function (res) {
                    self.retryCount = 0;

                    if (!res.success) {
                        self.addLog(r2csAdmin.i18n.error + ': ' + (res.message || r2csAdmin.i18n.errorUnknown), 'error');
                        self.finishSync();
                        return;
                    }

                    var data = res.data;
                    var details = [];
                    if (data.skipped) {
                        details.push(data.skipped + ' skipped');
                    }
                    if (data.deleted) {
                        details.push(data.deleted + ' local files removed');
                    }
                    var detailsStr = details.length > 0 ? ' (' + details.join(', ') + ')' : '';
                    self.addLog(
                        r2csAdmin.i18n.batchSuccess.replace('%1$d', data.success).replace('%2$d', data.processed) + detailsStr,
                        data.errors.length > 0 ? 'error' : 'success'
                    );

                    if (data.errors && data.errors.length > 0) {
                        data.errors.forEach(function (err) {
                            self.addLog('  ' + (err.file ? err.file + ': ' : '') + err.message, 'error');
                        });
                    }

                    self.updateFolderProgress(data);

                    if (data.remaining > 0 && self.syncing && !self.paused) {
                        setTimeout(function () {
                            self.runFolderBatch();
                        }, 300);
                    } else if (data.remaining === 0) {
                        self.addLog(r2csAdmin.i18n.syncComplete, 'success');
                        self.finishSync();
                    }
                },
                error: function (xhr) {
                    self.retryCount = (self.retryCount || 0) + 1;
                    if (self.retryCount <= 3 && self.syncing && !self.paused) {
                        self.addLog('Network glitch detected. Retrying batch in 3 seconds... (Attempt ' + self.retryCount + ' of 3)', 'warning');
                        setTimeout(function () {
                            self.runFolderBatch();
                        }, 3000);
                        return;
                    }
                    self.retryCount = 0;
                    var msg = r2csAdmin.i18n.networkError;
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    }
                    self.addLog(r2csAdmin.i18n.error + ': ' + msg, 'error');
                    self.finishSync();
                }
            });
        },

        updateProgress: function (remaining) {
            // Get updated progress
            $.ajax({
                url: r2csAdmin.restUrl + 'sync/progress',
                method: 'GET',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', r2csAdmin.restNonce);
                },
                success: function (res) {
                    if (res.success && res.progress) {
                        var p = res.progress;
                        $('#r2cs-progress-bar').css('width', p.percentage + '%');
                        $('#r2cs-progress-text').text(p.percentage + '%');
                        $('#r2cs-sync-status').text(r2csAdmin.i18n.syncing);
                        $('#r2cs-sync-count').text(p.offloaded + ' / ' + p.total);

                        // Update dashboard stats too
                        $('#r2cs-stat-offloaded').text(p.offloaded);
                        $('#r2cs-stat-pending').text(p.pending);
                        if (typeof p.failed !== 'undefined') {
                            $('#r2cs-stat-failed').text(p.failed);
                        }
                    }
                }
            });
        },

        updateFolderProgress: function (batchData) {
            var total = batchData.total || 0;
            var remaining = batchData.remaining || 0;
            var synced = Math.max(0, total - remaining);
            var percentage = total > 0 ? Math.round((synced / total) * 100) : 100;

            $('#r2cs-progress-bar').css('width', percentage + '%');
            $('#r2cs-progress-text').text(percentage + '%');
            $('#r2cs-sync-status').text(r2csAdmin.i18n.syncing);
            $('#r2cs-sync-count').text(synced + ' / ' + total);
        },

        finishSync: function () {
            this.syncing = false;
            this.paused = false;
            this.syncType = null;
            this.retryCount = 0;
            $('#r2cs-start-sync').show().html(
                '<span class="dashicons dashicons-cloud-upload"></span> ' + (r2csAdmin.i18n.startSync || 'Start Media Library Sync')
            );
            $('#r2cs-start-folder-sync').show();
            $('#r2cs-stop-sync').hide();
            $('#r2cs-sync-status').text(r2csAdmin.i18n.completed);
            this.loadStats();
            if ($('#r2cs-unsynced-table').length) {
                this.loadUnsyncedFiles(0);
            }
        },

        addLog: function (message, type) {
            type = type || 'info';
            var time = new Date().toLocaleTimeString();
            var $entry = $('<div></div>').addClass('r2cs-log-entry ' + type).text('[' + time + '] ' + message);
            $('#r2cs-log-entries').append($entry);

            // Auto-scroll to bottom
            var log = document.getElementById('r2cs-log-entries');
            if (log) {
                log.scrollTop = log.scrollHeight;
            }
        },

        /* ── Not Synced Files ──────────────────────────── */

        unsyncedOffset: 0,
        unsyncedLimit: 50,
        unsyncedTotal: 0,
        unsyncedSearchTimer: null,

        loadUnsyncedFiles: function (offset, search) {
            var self = this;
            if (typeof offset !== 'undefined') {
                this.unsyncedOffset = parseInt(offset, 10) || 0;
            }
            if (typeof search === 'undefined') {
                search = $('#r2cs-unsynced-search').val() || '';
            }

            var $tbody = $('#r2cs-unsynced-table-body');
            var $tableWrap = $('#r2cs-unsynced-table').closest('.r2cs-table-responsive');
            var $emptyState = $('#r2cs-unsynced-empty');
            var $pagination = $('#r2cs-unsynced-pagination');

            $tbody.html('<tr><td colspan="6" style="text-align: center; padding: 24px; color: #646970;"><span class="spinner is-active" style="float: none; margin: 0 6px 0 0;"></span> Scanning not synced files...</td></tr>');

            $.ajax({
                url: r2csAdmin.restUrl + 'unsynced/list?limit=' + this.unsyncedLimit + '&offset=' + this.unsyncedOffset + '&search=' + encodeURIComponent(search),
                method: 'GET',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', r2csAdmin.restNonce);
                },
                success: function (res) {
                    if (!res.success || !res.data) {
                        $tbody.html('<tr><td colspan="6" style="text-align:center;color:#dc3232;padding:16px;">Failed to scan files.</td></tr>');
                        return;
                    }

                    var data = res.data;
                    self.unsyncedTotal = data.total || 0;
                    $('#r2cs-stat-unsynced').text(self.unsyncedTotal);
                    $('#r2cs-unsynced-badge').text(self.unsyncedTotal + ' files');

                    if (self.unsyncedTotal === 0) {
                        $tableWrap.hide();
                        $pagination.hide();
                        $emptyState.show();
                        $('#r2cs-unsynced-showing-text').text('');
                        return;
                    }

                    $emptyState.hide();
                    $tableWrap.show();
                    $pagination.show();

                    var rowsHtml = '';
                    var items = data.items || [];

                    if (items.length === 0) {
                        rowsHtml = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#646970;">No matching files found.</td></tr>';
                    } else {
                        items.forEach(function (item) {
                            var previewHtml = '';
                            if (item.is_image) {
                                previewHtml = '<img src="' + item.url + '" class="r2cs-thumb-preview" alt="" onerror="this.onerror=null;this.parentElement.innerHTML=\'<span class=\\\'dashicons dashicons-format-image\\\' style=\\\'font-size:26px;color:#a7aaad;\\\'></span>\';" />';
                            } else if (item.extension === 'pdf') {
                                previewHtml = '<span class="dashicons dashicons-pdf" style="font-size: 28px; color: #d63638; width: 28px; height: 28px;"></span>';
                            } else {
                                previewHtml = '<span class="dashicons dashicons-media-default" style="font-size: 28px; color: #646970; width: 28px; height: 28px;"></span>';
                            }

                            rowsHtml += '<tr data-path="' + item.path + '">' +
                                '<td style="text-align: center; vertical-align: middle;">' + previewHtml + '</td>' +
                                '<td style="vertical-align: middle;">' +
                                    '<strong style="font-size: 13px; color: #1d2327;">' + item.filename + '</strong>' +
                                    '<div style="font-size: 11px; color: #646970; margin-top: 2px;">' +
                                        '<code class="r2cs-file-path">' + item.path + '</code>' +
                                    '</div>' +
                                '</td>' +
                                '<td style="vertical-align: middle; color: #50575e; font-size: 12px;">' + (item.size_formatted || '—') + '</td>' +
                                '<td style="vertical-align: middle; color: #50575e; font-size: 12px;">' + (item.date || '—') + '</td>' +
                                '<td style="vertical-align: middle;">' +
                                    '<span class="r2cs-badge r2cs-badge-unsynced" id="status-' + self.escapeId(item.path) + '">' +
                                        '<span class="dashicons dashicons-clock" style="font-size:12px;width:12px;height:12px;margin-right:3px;"></span>Not Synced' +
                                    '</span>' +
                                '</td>' +
                                '<td style="vertical-align: middle; text-align: right;">' +
                                    '<button type="button" class="button button-small r2cs-btn-sync-single" data-path="' + item.path + '" data-id="' + (item.id || 0) + '">' +
                                        '<span class="dashicons dashicons-cloud-upload"></span> Sync to R2' +
                                    '</button>' +
                                '</td>' +
                            '</tr>';
                        });
                    }

                    $tbody.html(rowsHtml);

                    // Update Pagination
                    var startNum = self.unsyncedOffset + 1;
                    var endNum = Math.min(self.unsyncedOffset + self.unsyncedLimit, self.unsyncedTotal);
                    $('#r2cs-page-info').text('Showing ' + startNum + '–' + endNum + ' of ' + self.unsyncedTotal + ' not synced files');
                    $('#r2cs-unsynced-showing-text').text(self.unsyncedTotal + ' not synced');

                    $('#r2cs-prev-page').prop('disabled', self.unsyncedOffset <= 0);
                    $('#r2cs-next-page').prop('disabled', endNum >= self.unsyncedTotal);
                },
                error: function () {
                    $tbody.html('<tr><td colspan="6" style="text-align:center;color:#dc3232;padding:16px;">Error scanning not synced files. Check network or server logs.</td></tr>');
                }
            });
        },

        escapeId: function (str) {
            return (str || '').replace(/[^a-zA-Z0-9_-]/g, '_');
        },

        debounceSearch: function (e) {
            var self = this;
            clearTimeout(this.unsyncedSearchTimer);
            this.unsyncedSearchTimer = setTimeout(function () {
                self.loadUnsyncedFiles(0, $(e.target).val());
            }, 300);
        },

        prevUnsyncedPage: function () {
            if (this.unsyncedOffset > 0) {
                var newOffset = Math.max(0, this.unsyncedOffset - this.unsyncedLimit);
                this.loadUnsyncedFiles(newOffset);
            }
        },

        nextUnsyncedPage: function () {
            if (this.unsyncedOffset + this.unsyncedLimit < this.unsyncedTotal) {
                var newOffset = this.unsyncedOffset + this.unsyncedLimit;
                this.loadUnsyncedFiles(newOffset);
            }
        },

        refreshUnsynced: function (e) {
            if (e) e.preventDefault();
            var $btn = $('#r2cs-refresh-unsynced-btn');
            $btn.prop('disabled', true);
            var self = this;
            this.loadStats();
            this.loadUnsyncedFiles(0);
            setTimeout(function () {
                $btn.prop('disabled', false);
            }, 800);
        },

        syncSingleFile: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var path = $btn.data('path');
            var id   = $btn.data('id') || 0;
            var self = this;

            $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 4px 0 0;"></span> Syncing...');

            $.ajax({
                url: r2csAdmin.restUrl + 'unsynced/sync-single',
                method: 'POST',
                data: JSON.stringify({ path: path, id: id }),
                contentType: 'application/json',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', r2csAdmin.restNonce);
                },
                success: function (res) {
                    if (res.success) {
                        var statusId = '#status-' + self.escapeId(path);
                        $(statusId).removeClass('r2cs-badge-unsynced').addClass('r2cs-badge-active').html('<span class="dashicons dashicons-yes" style="font-size:12px;width:12px;height:12px;margin-right:3px;"></span>Synced');
                        $btn.replaceWith('<span style="color:#16a34a;font-weight:600;font-size:12px;"><span class="dashicons dashicons-yes-alt" style="vertical-align:middle;margin-top:-2px;"></span> Synced</span>');

                        if (res.stats && typeof res.stats.unsynced !== 'undefined') {
                            $('#r2cs-stat-unsynced').text(res.stats.unsynced);
                            $('#r2cs-unsynced-badge').text(res.stats.unsynced + ' files');
                        } else {
                            var cur = parseInt($('#r2cs-stat-unsynced').text(), 10) || 0;
                            if (cur > 0) {
                                $('#r2cs-stat-unsynced').text(cur - 1);
                                $('#r2cs-unsynced-badge').text((cur - 1) + ' files');
                            }
                        }

                        // Also increment offloaded count in stats
                        var offloaded = parseInt($('#r2cs-stat-offloaded').text(), 10) || 0;
                        $('#r2cs-stat-offloaded').text(offloaded + 1);

                        $('.r2cs-sync-log').show();
                        self.addLog(res.data.message || ('Successfully synced ' + path + ' to R2'), 'success');
                    } else {
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-cloud-upload"></span> Retry Sync');
                        alert(res.message || 'Failed to sync file to R2.');
                    }
                },
                error: function (xhr) {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-cloud-upload"></span> Retry Sync');
                    var msg = r2csAdmin.i18n.error;
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    }
                    alert(msg);
                }
            });
        },

        syncAllUnsynced: function (e) {
            if (e) e.preventDefault();
            // Scroll down to bulk migration tool and initiate folder sync.
            $('html, body').animate({
                scrollTop: $('.r2cs-sync-container').offset().top - 40
            }, 400);

            // Trigger folder sync directly
            $('#r2cs-start-folder-sync').trigger('click');
        },

        /* ── License Activation ────────────────────────── */

        activateLicense: function (addonKey) {
            var $card = $('[data-addon="' + addonKey + '"]');
            var licenseKey = $card.find('.r2cs-license-input').val().trim();

            if (!licenseKey) {
                $card.find('.r2cs-license-message').addClass('error').text(r2csAdmin.i18n.enterLicense).show();
                return;
            }

            var $btn = $card.find('.r2cs-activate-btn');
            var $msg = $card.find('.r2cs-license-message');

            $btn.prop('disabled', true).text(r2csAdmin.i18n.activating);
            $msg.removeClass('success error').hide();

            $.ajax({
                url: r2csAdmin.restUrl + 'license/activate',
                method: 'POST',
                data: JSON.stringify({
                    addon_key: addonKey,
                    license_key: licenseKey
                }),
                contentType: 'application/json',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', r2csAdmin.restNonce);
                },
                success: function (res) {
                    $msg.addClass('success').text(res.message).show();
                    $card.find('.r2cs-license-status').text(r2csAdmin.i18n.licenseActive).addClass('active');
                    $btn.text(r2csAdmin.i18n.deactivate).removeClass('r2cs-activate-btn').addClass('r2cs-deactivate-btn');
                    $btn.off('click').on('click', function () {
                        R2CS.deactivateLicense(addonKey);
                    });
                },
                error: function (xhr) {
                    var msg = 'Erro ao ativar.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    }
                    $msg.addClass('error').text(msg).show();
                },
                complete: function () {
                    $btn.prop('disabled', false);
                }
            });
        },

        deactivateLicense: function (addonKey) {
            if (!confirm('Deseja desativar esta licença deste site?')) return;

            var $card = $('[data-addon="' + addonKey + '"]');
            var $btn = $card.find('.r2cs-deactivate-btn');
            var $msg = $card.find('.r2cs-license-message');

            $btn.prop('disabled', true).text('Desativando...');
            $msg.removeClass('success error').hide();

            $.ajax({
                url: r2csAdmin.restUrl + 'license/deactivate',
                method: 'POST',
                data: JSON.stringify({ addon_key: addonKey }),
                contentType: 'application/json',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', r2csAdmin.restNonce);
                },
                success: function (res) {
                    $msg.addClass('success').text(res.message).show();
                    $card.find('.r2cs-license-status').text(r2csAdmin.i18n.licenseInactive).removeClass('active');
                    $btn.text(r2csAdmin.i18n.activate).removeClass('r2cs-deactivate-btn').addClass('r2cs-activate-btn');
                    $btn.off('click').on('click', function () {
                        R2CS.activateLicense(addonKey);
                    });
                },
                error: function (xhr) {
                    var msg = r2csAdmin.i18n.error;
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    }
                    $msg.addClass('error').text(msg).show();
                },
                complete: function () {
                    $btn.prop('disabled', false);
                }
            });
        }
    };

    $(document).ready(function () {
        R2CS.init();

        // Bind license activation events on the add-ons page.
        $(document).on('click', '.r2cs-activate-btn', function () {
            var addonKey = $(this).closest('[data-addon]').data('addon');
            R2CS.activateLicense(addonKey);
        });

        $(document).on('click', '.r2cs-deactivate-btn', function () {
            var addonKey = $(this).closest('[data-addon]').data('addon');
            R2CS.deactivateLicense(addonKey);
        });
    });

})(jQuery);
