(function($) {
    'use strict';

    var CybermapsSync = {
        total: 0,
        audited: 0,
        cursor: 0,
        batches: 0,
        is_running: false,

        init: function() {
            $(document).on('click', '#cybermaps-start-sync', this.start.bind(this));
        },

        start: function(e) {
            e.preventDefault();
            if (this.is_running) return;

            this.showStatus('', false);
            var selectedIntensity = String($('#media_discovery_intensity').val() || 'none');
            var savedIntensity = String(cybermaps_auditor.savedIntensity || 'none');
            if (selectedIntensity !== savedIntensity || savedIntensity === 'none') {
                this.showStatus(cybermaps_auditor.saveFirstNotice, true);
                return;
            }

            var $btn = $(e.currentTarget);
            $btn.prop('disabled', true).text(cybermaps_auditor.rescanningLabel);
            $('#cybermaps-sync-progress-wrapper').show();
            
            this.is_running = true;
            this.cursor = 0;
            this.batches = 0;
            this.getStats();
        },

        getStats: function() {
            var self = this;
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'cybermaps_get_sync_stats',
                    nonce: cybermaps_auditor.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.total = response.data.total;
                        self.audited = 0;
                        self.updateUI();
                        if (self.total > 0) {
                            self.processBatch();
                        } else {
                            self.finish();
                        }
                    } else {
                        self.finish(self.errorMessage(response.data));
                    }
                },
                error: function(xhr) {
                    self.finish(self.xhrError(xhr));
                }
            });
        },

        processBatch: function() {
            var self = this;
            if (!this.is_running) return;

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'cybermaps_process_sync_batch',
                    nonce: cybermaps_auditor.nonce,
                    cursor: this.cursor
                },
                success: function(response) {
                    if (response.success) {
                        var processed = parseInt(response.data.processed, 10) || 0;
                        var nextCursor = parseInt(response.data.next_cursor, 10) || 0;
                        self.batches += 1;

                        if (processed > 0 && nextCursor <= self.cursor) {
                            self.finish(cybermaps_auditor.cursorError);
                            return;
                        }

                        if (self.batches > Math.ceil(self.total / 50) + 2) {
                            self.finish(cybermaps_auditor.batchError);
                            return;
                        }

                        if (processed > 0) {
                            self.audited += processed;
                            self.cursor = nextCursor;
                            self.updateUI();
                            if (response.data.done) {
                                self.finish();
                            } else {
                                self.processBatch();
                            }
                        } else {
                            self.finish();
                        }
                    } else {
                        self.finish(self.errorMessage(response.data));
                    }
                },
                error: function(xhr) {
                    self.finish(self.xhrError(xhr));
                }
            });
        },

        updateUI: function() {
            var percent = this.total > 0 ? Math.min(100, Math.round((this.audited / this.total) * 100)) : 100;
            $('#cybermaps-sync-progress-bar')
                .css('width', percent + '%')
                .attr('aria-valuenow', percent);
            $('#cybermaps-sync-progress-text').text(this.audited + ' / ' + this.total + ' (' + percent + '%)');
        },

        finish: function(error) {
            this.is_running = false;
            var $btn = $('#cybermaps-start-sync');
            $btn.prop('disabled', false).text(cybermaps_auditor.rescanLabel);
            if (error) {
                this.showStatus(cybermaps_auditor.failedPrefix + error, true);
            } else {
                this.audited = this.total;
                this.updateUI();
                this.showStatus(cybermaps_auditor.completeNotice, false);
            }
        },

        showStatus: function(message, isError) {
            var $status = $('#cybermaps-sync-status');
            if (!$status.length) return;
            if (!message) {
                $status.hide().removeClass('notice-error notice-success').find('p').text('');
                return;
            }
            $status
                .removeClass('notice-error notice-success')
                .addClass(isError ? 'notice-error' : 'notice-success')
                .find('p').text(message);
            $status.show();
        },

        errorMessage: function(data) {
            if (data && typeof data === 'object' && data.message) return data.message;
            if (typeof data === 'string' && data) return data;
            return cybermaps_auditor.invalidResponse;
        },

        xhrError: function(xhr) {
            if (xhr && xhr.responseJSON) {
                return this.errorMessage(xhr.responseJSON.data);
            }
            return cybermaps_auditor.connectionError;
        }
    };

    $(document).ready(function() {
        CybermapsSync.init();
    });

})(jQuery);
