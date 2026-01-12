/**
 * BSV Payment Console - Live Status & Interactions
 */
(function($) {
    'use strict';

    const BSVPaymentConsole = {
        orderId: null,
        orderKey: null,
        pollingInterval: null,
        pollingDelay: 10000, // 10 seconds default
        lastCheckTime: 0,
        recheckCooldown: 3000, // 3 seconds between manual checks
        pollingStartTime: 0,
        maxPollingDuration: 7200000, // 2 hours in milliseconds
        pollCount: 0,

        init: function() {
            // Get order data from DOM
            const consoleEl = $('.bsv-payment-console');
            if (!consoleEl.length) return;

            this.orderId = consoleEl.data('order-id');
            this.orderKey = consoleEl.data('order-key');
            this.pollingDelay = (consoleEl.data('polling-interval') || 10) * 1000;

            if (!this.orderId || !this.orderKey) {
                console.warn('BSV Payment Console: Missing order ID or key');
                return;
            }

            // Bind event handlers
            this.bindEvents();

            // Start polling
            this.pollingStartTime = Date.now();
            this.startPolling();

            // Initial status check
            this.checkStatus();
        },

        bindEvents: function() {
            // Copy buttons
            $('.bsv-copy-btn').on('click', this.handleCopy.bind(this));

            // Force recheck button
            $('.bsv-recheck-btn').on('click', this.handleForceRecheck.bind(this));
            
            // BSV/sats toggle
            $('.bsv-amount').on('click', this.handleAmountToggle.bind(this));
        },
        
        handleAmountToggle: function(e) {
            const amountEl = $(e.currentTarget);
            const currentMode = amountEl.data('mode');
            const bsvAmount = amountEl.data('bsv');
            const satsAmount = amountEl.data('sats');
            const valueEl = amountEl.find('.bsv-amount-value');
            const unitEl = amountEl.find('.bsv-unit');
            const copyBtn = $('.bsv-copy-amount');
            
            if (currentMode === 'bsv') {
                // Switch to sats
                valueEl.text(parseInt(satsAmount).toLocaleString());
                unitEl.text('sats');
                amountEl.data('mode', 'sats');
                copyBtn.data('copy', satsAmount);
            } else {
                // Switch to BSV
                valueEl.text(bsvAmount);
                unitEl.text('BSV');
                amountEl.data('mode', 'bsv');
                copyBtn.data('copy', bsvAmount);
            }
        },

        handleCopy: function(e) {
            e.preventDefault();
            const btn = $(e.currentTarget);
            const textToCopy = btn.data('copy');

            if (!textToCopy) return;

            // Use modern clipboard API if available
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(textToCopy).then(() => {
                    this.showCopySuccess(btn);
                }).catch(() => {
                    this.fallbackCopy(textToCopy, btn);
                });
            } else {
                this.fallbackCopy(textToCopy, btn);
            }
        },

        fallbackCopy: function(text, btn) {
            // Fallback for older browsers
            const textarea = $('<textarea>');
            textarea.val(text);
            textarea.css({
                position: 'fixed',
                opacity: 0,
                top: 0,
                left: 0
            });
            $('body').append(textarea);
            textarea[0].select();
            
            try {
                document.execCommand('copy');
                this.showCopySuccess(btn);
            } catch (err) {
                console.error('Copy failed:', err);
            }
            
            textarea.remove();
        },

        showCopySuccess: function(btn) {
            const originalText = btn.text();
            btn.addClass('copied').text('Copied!');
            
            setTimeout(() => {
                btn.removeClass('copied').text(originalText);
            }, 2000);
        },

        handleForceRecheck: function(e) {
            e.preventDefault();
            
            const now = Date.now();
            const btn = $(e.currentTarget);
            
            if (now - this.lastCheckTime < this.recheckCooldown) {
                // Still in cooldown - show message
                this.showRecheckCooldown(btn);
                return;
            }

            btn.prop('disabled', true).html('<span class="bsv-spinner"></span> Checking...');

            this.checkStatus(true).done((response) => {
                if (response.success && response.data) {
                    const state = response.data.payment_state || 'waiting';
                    if (state === 'waiting') {
                        // No payment detected yet
                        this.showNoPaymentMessage(btn);
                    } else {
                        // Payment detected or confirmed
                        btn.prop('disabled', false).html('✓ Updated');
                        setTimeout(() => {
                            btn.text('I\'ve Paid');
                        }, 2000);
                    }
                } else {
                    btn.prop('disabled', false).text('I\'ve Paid');
                }
            }).fail(() => {
                btn.prop('disabled', false).text('I\'ve Paid');
            });
        },
        
        showRecheckCooldown: function(btn) {
            const originalText = btn.text();
            btn.text('Please wait...');
            setTimeout(() => {
                btn.text(originalText);
            }, 1500);
        },
        
        showNoPaymentMessage: function(btn) {
            btn.prop('disabled', false).html('⏱ Not detected yet');
            setTimeout(() => {
                btn.text('I\'ve Paid');
            }, 3000);
        },

        startPolling: function() {
            if (this.pollingInterval) {
                clearInterval(this.pollingInterval);
            }

            this.pollingInterval = setInterval(() => {
                // Check if polling has exceeded max duration (2 hours)
                const elapsed = Date.now() - this.pollingStartTime;
                if (elapsed > this.maxPollingDuration) {
                    console.log('BSV: Polling stopped after 2 hours');
                    this.stopPolling();
                    return;
                }
                
                this.pollCount++;
                this.checkStatus();
            }, this.pollingDelay);
        },

        stopPolling: function() {
            if (this.pollingInterval) {
                clearInterval(this.pollingInterval);
                this.pollingInterval = null;
            }
        },

        checkStatus: function(forceRecheck = false) {
            this.lastCheckTime = Date.now();

            return $.ajax({
                url: bsvPaymentData.statusEndpoint,
                method: 'POST',
                data: {
                    order_id: this.orderId,
                    order_key: this.orderKey,
                    force: forceRecheck ? '1' : '0',
                    nonce: bsvPaymentData.nonce
                },
                timeout: 15000
            }).done((response) => {
                if (response.success && response.data) {
                    this.updateUI(response.data);
                }
            }).fail((xhr, status, error) => {
                console.error('BSV status check failed:', error);
            });
        },

        updateUI: function(data) {
            // Update status box
            this.updateStatusBox(data);

            // Update confirmations
            this.updateConfirmations(data);

            // Update expiration countdown
            this.updateExpiration(data);

            // Update payment details
            this.updatePaymentDetails(data);

            // Update explorer link
            this.updateExplorerLink(data);

            // Stop polling if payment is confirmed or expired
            if (data.payment_state === 'confirmed' || data.payment_state === 'expired') {
                console.log('BSV: Polling stopped - payment ' + data.payment_state);
                this.stopPolling();
            }
            
            // Also stop if order is already completed
            if (data.order_status === 'completed' || data.order_status === 'processing') {
                console.log('BSV: Polling stopped - order ' + data.order_status);
                this.stopPolling();
            }

            // Reload page if order is completed
            if (data.order_status === 'completed' || data.order_status === 'processing') {
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            }
        },

        updateStatusBox: function(data) {
            const statusBox = $('.bsv-status-box');
            if (!statusBox.length) return;

            const state = data.payment_state || 'waiting';
            const statusMessages = {
                waiting: {
                    label: 'Waiting for Payment',
                    message: 'Send the exact amount to the address above. Payment will be detected within seconds.'
                },
                detected: {
                    label: 'Payment Detected!',
                    message: 'Your payment has been detected on the blockchain. Waiting for confirmation...'
                },
                confirmed: {
                    label: 'Payment Confirmed',
                    message: 'Your payment has been confirmed. Thank you!'
                },
                expired: {
                    label: 'Payment Window Expired',
                    message: 'The payment window has expired. Please contact support if you already sent payment.'
                },
                underpaid: {
                    label: 'Underpaid',
                    message: `Received ${data.received_sats || 0} sats but expected ${data.expected_sats || 0} sats. Please send the remaining amount.`
                },
                overpaid: {
                    label: 'Overpaid (Thank You!)',
                    message: `Received ${data.received_sats || 0} sats (${((data.received_sats - data.expected_sats) || 0)} sats extra). Payment accepted!`
                }
            };

            const statusInfo = statusMessages[state] || statusMessages.waiting;

            // Update classes
            statusBox.removeClass('status-waiting status-detected status-confirmed status-expired status-underpaid status-overpaid');
            statusBox.addClass('status-' + state);

            // Update content
            statusBox.find('.bsv-status-label').text(statusInfo.label);
            statusBox.find('.bsv-status-message').text(statusInfo.message);
        },

        updateConfirmations: function(data) {
            const confEl = $('.bsv-confirmations');
            if (!confEl.length) return;

            const current = parseInt(data.best_confirmations) || 0;
            const required = parseInt(data.required_confirmations) || 1;

            confEl.find('.bsv-conf-current').text(current);
            confEl.find('.bsv-conf-required').text(required);

            // Update progress dots
            const dots = confEl.find('.bsv-conf-dot');
            dots.each(function(index) {
                if (index < current) {
                    $(this).addClass('confirmed');
                } else {
                    $(this).removeClass('confirmed');
                }
            });
        },

        updateExpiration: function(data) {
            const expEl = $('.bsv-expiration-time');
            if (!expEl.length || !data.expires_at) return;

            const expiresAt = new Date(data.expires_at * 1000);
            const now = new Date();
            const diff = expiresAt - now;

            if (diff <= 0) {
                expEl.text('Expired').addClass('expired').removeClass('warning');
                return;
            }

            const minutes = Math.floor(diff / 60000);
            const seconds = Math.floor((diff % 60000) / 1000);

            let timeText = '';
            if (minutes > 0) {
                timeText = `${minutes}m ${seconds}s`;
            } else {
                timeText = `${seconds}s`;
            }

            expEl.text(timeText);

            // Add warning class if less than 5 minutes
            if (minutes < 5) {
                expEl.addClass('warning').removeClass('expired');
            } else {
                expEl.removeClass('warning expired');
            }
        },

        updatePaymentDetails: function(data) {
            // Update received amount if shown
            const receivedEl = $('.bsv-detail-received');
            if (receivedEl.length && data.received_sats) {
                receivedEl.text(data.received_sats + ' sats');
            }

            // Update expected amount
            const expectedEl = $('.bsv-detail-expected');
            if (expectedEl.length && data.expected_sats) {
                expectedEl.text(data.expected_sats + ' sats');
            }
        },

        updateExplorerLink: function(data) {
            const explorerEl = $('.bsv-explorer-link');
            if (!explorerEl.length) return;

            if (data.txids && data.txids.length > 0) {
                const txid = data.txids[0];
                const explorerUrl = data.explorer_url || 'https://whatsonchain.com/tx/';
                explorerEl.html(`<a href="${explorerUrl}${txid}" target="_blank" rel="noopener">View Transaction On BSV Blockchain ↗</a>`);
                explorerEl.show();
            } else if (data.address) {
                const explorerUrl = data.explorer_url || 'https://whatsonchain.com/address/';
                explorerEl.html(`<a href="${explorerUrl}${data.address}" target="_blank" rel="noopener">View Address On BSV Blockchain ↗</a>`);
                explorerEl.show();
            }
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        BSVPaymentConsole.init();
    });

})(jQuery);
