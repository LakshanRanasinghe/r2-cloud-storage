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

        init: function () {
            this.bindEvents();
            this.loadStats();
        },

        bindEvents: function () {
            $('#r2cs-test-connection').on('click', this.testConnection.bind(this));
            $('#r2cs-start-sync').on('click', this.startSync.bind(this));
            $('#r2cs-stop-sync').on('click', this.stopSync.bind(this));
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
                    if (res.success && res.data.media) {
                        $('#r2cs-stat-total').text(res.data.media.total);
                        $('#r2cs-stat-offloaded').text(res.data.media.offloaded);
                        $('#r2cs-stat-pending').text(res.data.media.pending);
                        $('#r2cs-stat-addons').text(res.data.addons || 0);
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

        startSync: function (e) {
            e.preventDefault();

            if (this.syncing) return;
            this.syncing = true;
            this.paused = false;

            var $startBtn = $('#r2cs-start-sync');
            var $stopBtn = $('#r2cs-stop-sync');

            $startBtn.hide();
            $stopBtn.show();
            $('.r2cs-progress-container').show();
            $('#r2cs-sync-log').show();

            this.addLog(r2csAdmin.i18n.syncStarting);
            this.runBatch();
        },

        stopSync: function (e) {
            e.preventDefault();
            this.paused = true;
            this.syncing = false;

            $('#r2cs-start-sync').show().text(r2csAdmin.i18n.syncResumed);
            $('#r2cs-stop-sync').hide();
            $('#r2cs-sync-status').text(r2csAdmin.i18n.paused);
            this.addLog(r2csAdmin.i18n.syncPaused, 'warning');
        },

        runBatch: function () {
            var self = this;

            if (this.paused || !this.syncing) return;

            $.ajax({
                url: r2csAdmin.restUrl + 'sync/batch',
                method: 'POST',
                data: JSON.stringify({ batch_size: 10 }),
                contentType: 'application/json',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', r2csAdmin.restNonce);
                },
                success: function (res) {
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
                    }
                }
            });
        },

        finishSync: function () {
            this.syncing = false;
            this.paused = false;
            $('#r2cs-start-sync').show().html(
                '<span class="dashicons dashicons-cloud-upload"></span> ' + r2csAdmin.i18n.startSync
            );
            $('#r2cs-stop-sync').hide();
            $('#r2cs-sync-status').text(r2csAdmin.i18n.completed);
            this.loadStats();
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
