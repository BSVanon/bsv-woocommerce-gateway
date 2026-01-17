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
        reloadScheduled: false,

        init: function() {
            // Get order data from DOM
            const consoleEl = $('.bsv-payment-console');
            if (!consoleEl.length) return;

            this.orderId = consoleEl.data('order-id') || bsvPaymentData.orderId || null;
            this.orderKey = consoleEl.data('order-key') || bsvPaymentData.orderKey || null;
            this.pollingDelay = (consoleEl.data('polling-interval') || 10) * 1000;

            if (!this.orderId || !this.orderKey) {
                console.warn('BSV Payment Console: Missing order ID or key');
                return;
            }

            // Generate QR code
            this.generateQRCode();

            // Bind event handlers
            this.bindEvents();
            
            // Bind protocol tab switching
            this.bindProtocolTabs();

            // Initial status check to determine if we should poll
            this.checkStatus().done((response) => {
                if (response.success && response.data) {
                    const shouldStop = this.shouldStopPolling(response.data);
                    if (!shouldStop.stop) {
                        // Only start polling if order is not in final state
                        this.pollingStartTime = Date.now();
                        this.startPolling();
                    } else {
                        console.log('BSV: Order in final state, polling not started - ' + shouldStop.reason);
                    }
                }
            });
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
            
            // Update button state
            this.updateButtonState(data);

            // Stop polling on final states
            const shouldStopPolling = this.shouldStopPolling(data);
            if (shouldStopPolling.stop) {
                console.log('BSV: Polling stopped - ' + shouldStopPolling.reason);
                this.stopPolling();
            }

            // Reload page if order is completed (only once)
            // Use sessionStorage to persist flag across reloads
            const reloadKey = 'bsv_reload_scheduled_' + data.order_id;
            const alreadyReloaded = sessionStorage.getItem(reloadKey);
            
            if ((data.order_status === 'completed' || data.order_status === 'processing') && !alreadyReloaded && !this.reloadScheduled) {
                this.reloadScheduled = true;
                sessionStorage.setItem(reloadKey, '1');
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            }
        },
        
        shouldStopPolling: function(data) {
            // CRITICAL: Check order_status FIRST - this is the source of truth
            // WooCommerce order status indicates actual order state
            if (data.order_status === 'completed' || data.order_status === 'processing') {
                console.log('BSV: Stopping - order status is ' + data.order_status);
                return { stop: true, reason: 'order ' + data.order_status };
            }
            
            // Parse all values for additional checks
            const current = parseInt(data.best_confirmations) || 0;
            const required = parseInt(data.required_confirmations) || 1;
            const receivedSats = parseInt(data.received_sats) || 0;
            const expectedSats = parseInt(data.expected_sats) || 0;
            const confirmedSats = parseInt(data.confirmed_sats) || 0;
            
            // Log current state for debugging
            console.log('BSV: Poll check - state=' + data.payment_state + ' confirmed=' + confirmedSats + '/' + expectedSats + ' confs=' + current + '/' + required + ' order_status=' + data.order_status);
            
            // Stop if payment is confirmed AND order should be completed
            // This catches cases where payment is confirmed but order status hasn't updated yet
            if (data.payment_state === 'confirmed' && 
                confirmedSats >= expectedSats && 
                current >= required && 
                expectedSats > 0) {
                console.log('BSV: Stopping - payment fully confirmed');
                return { stop: true, reason: 'payment confirmed (' + confirmedSats + ' sats, ' + current + '/' + required + ' confirmations)' };
            }
            
            // Stop if expired with no funds received
            if (data.payment_state === 'expired' && receivedSats == 0) {
                console.log('BSV: Stopping - expired with no payment');
                return { stop: true, reason: 'payment window expired with no payment' };
            }
            
            // Continue polling for waiting, pending, underpaid states
            return { stop: false };
        },
        
        updateButtonState: function(data) {
            const btn = $('.bsv-recheck-btn');
            if (!btn.length) return;
            
            const state = data.payment_state || 'waiting';
            
            if (state === 'detected' || state === 'confirmed' || state === 'pending') {
                btn.text('Payment Received!');
                btn.removeClass('bsv-btn-primary').addClass('bsv-btn-success');
                btn.prop('disabled', true);
                btn.css('cursor', 'not-allowed');
            } else if (state === 'underpaid') {
                btn.text("I've Paid More");
            } else if (state === 'overpaid') {
                btn.text('Payment Received!');
                btn.removeClass('bsv-btn-primary').addClass('bsv-btn-success');
            } else {
                btn.text("I've Paid");
                btn.removeClass('bsv-btn-success').addClass('bsv-btn-primary');
                btn.prop('disabled', false);
                btn.css('cursor', 'pointer');
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
                pending: {
                    label: 'Payment Received',
                    message: `Payment received! Awaiting ${data.required_confirmations || 1} blockchain confirmation(s). This usually takes a few minutes—no action needed.`
                },
                confirmed: {
                    label: 'Payment Confirmed',
                    message: 'Your payment has been confirmed. Thank you!'
                },
                expired: {
                    label: data.received_sats > 0 ? 'Late Payment Received' : 'Payment Window Expired',
                    message: data.received_sats > 0 
                        ? 'Payment received after the window expired. Awaiting confirmations—your order will be processed.'
                        : 'The payment window has expired. Please create a new order to continue.'
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

        createStepper: function(requiredConfs) {
            const statusBox = $('.bsv-status-box');
            if (!statusBox.length) return;
            
            const maxDots = Math.max(requiredConfs, 3);
            let dotsHtml = '';
            for (let i = 0; i < maxDots; i++) {
                dotsHtml += '<div class="bsv-conf-dot" data-index="' + i + '"></div>';
            }
            
            const stepperHtml = '<div class="bsv-confirmations" style="margin-top: 20px;">' +
                '<div style="font-size: 14px; opacity: 0.7; margin-bottom: 0.5rem;">' +
                'Confirmations: <strong class="bsv-conf-current">0</strong> / <span class="bsv-conf-required">' + requiredConfs + '</span>' +
                '</div>' +
                '<div class="bsv-conf-progress">' + dotsHtml + '</div>' +
                '</div>';
            
            statusBox.after(stepperHtml);
            console.log('BSV: Stepper created with ' + maxDots + ' dots');
        },
        
        updateConfirmations: function(data) {
            const confEl = $('.bsv-confirmations');
            
            const current = parseInt(data.best_confirmations) || 0;
            const required = parseInt(data.required_confirmations) || 1;
            const state = data.payment_state || 'waiting';
            const received = parseInt(data.received_sats) || 0;
            const expected = parseInt(data.expected_sats) || 0;

            // Show/hide stepper based on state - ALWAYS show for non-waiting states
            if (state === 'waiting') {
                if (confEl.length) confEl.hide();
                return;
            }
            
            // If stepper doesn't exist but should show, create it
            if (!confEl.length && state !== 'waiting') {
                console.log('BSV: Creating stepper dynamically for state: ' + state);
                this.createStepper(required);
            }
            
            // Now get the element again (might have just been created)
            const stepperEl = $('.bsv-confirmations');
            if (!stepperEl.length) return;
            
            stepperEl.show();
            stepperEl.attr('data-state', state);

            // Update text
            const labelEl = stepperEl.find('div').first();
            if (state === 'expired' && received === 0) {
                labelEl.html('Payment Window Expired');
            } else if (state === 'underpaid') {
                labelEl.html('Partial Payment Received');
            } else {
                stepperEl.find('.bsv-conf-current').text(current);
                stepperEl.find('.bsv-conf-required').text(required);
            }

            // Update progress dots
            const dots = stepperEl.find('.bsv-conf-dot');
            dots.each(function(index) {
                const dot = $(this);
                dot.removeClass('confirmed pending partial failed');
                
                if (state === 'expired' && received === 0) {
                    dot.addClass('failed');
                } else if (state === 'underpaid') {
                    if (index === 0) {
                        dot.addClass('partial');
                    }
                } else if (state === 'detected' || state === 'pending') {
                    if (index < current) {
                        dot.addClass('confirmed');
                    } else if (index === 0 && received >= expected) {
                        dot.addClass('pending');
                    }
                } else if (state === 'confirmed') {
                    if (index < current) {
                        dot.addClass('confirmed');
                    }
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
            if (receivedEl.length && typeof data.received_sats !== 'undefined') {
                receivedEl.text((data.received_sats || 0).toLocaleString() + ' sats');
            }

            const confirmedEl = $('.bsv-detail-confirmed');
            if (confirmedEl.length && typeof data.confirmed_sats !== 'undefined') {
                confirmedEl.text((data.confirmed_sats || 0).toLocaleString() + ' sats');
            }

            // Update expected amount
            const expectedEl = $('.bsv-detail-expected');
            if (expectedEl.length && typeof data.expected_sats !== 'undefined') {
                expectedEl.text((data.expected_sats || 0).toLocaleString() + ' sats');
            }
        },

        updateExplorerLink: function(data) {
            const explorerEl = $('.bsv-explorer-link');
            if (!explorerEl.length) return;

            if (data.txids && data.txids.length > 0) {
                const txid = data.txids[0];
                const explorerBase = data.explorer_url || 'https://whatsonchain.com';
                const explorerUrl = explorerBase + '/tx/' + txid;
                explorerEl.html(`<a href="${explorerUrl}" target="_blank" rel="noopener">View Transaction On BSV Blockchain ↗</a>`);
                explorerEl.show();
            } else if (data.address) {
                const explorerBase = data.explorer_url || 'https://whatsonchain.com';
                const explorerUrl = explorerBase + '/address/' + data.address;
                explorerEl.html(`<a href="${explorerUrl}" target="_blank" rel="noopener">View Address On BSV Blockchain ↗</a>`);
                explorerEl.show();
            }
        },

        generateQRCode: function(protocol) {
            const qrEl = $('#bsv-qr-code');
            if (!qrEl.length) return;

            const address = qrEl.data('address') || bsvPaymentData.bsvAddress;
            const amount = qrEl.data('amount') || bsvPaymentData.bsvAmount;
            const orderId = qrEl.data('order-id') || bsvPaymentData.orderId;
            const orderKey = qrEl.data('order-key') || bsvPaymentData.orderKey;
            const currentProtocol = protocol || qrEl.data('protocol') || 'bip21';

            if (!address || !amount) {
                console.warn('BSV: Missing address or amount for QR code');
                qrEl.html('<div style="padding: 40px; text-align: center; color: #999;">QR Code Unavailable</div>');
                return;
            }

            let qrPayload;
            
            if (currentProtocol === 'bip270') {
                // BIP270: QR encodes invoice URL
                // Wallet fetches PaymentTerms from this URL
                qrPayload = this.getInvoiceUrl(orderId, orderKey);
                console.log('BSV: Generating BIP270 invoice QR:', qrPayload);
            } else {
                // BIP21: Standard bitcoin: URI with address and amount
                // Amount MUST be in BSV decimal, not sats
                qrPayload = `bitcoin:${address}?amount=${amount}`;
                console.log('BSV: Generating BIP21 QR:', qrPayload);
            }

            try {
                // Clear existing QR
                qrEl.empty();
                
                // Generate QR code using jQuery QRCode
                qrEl.qrcode({
                    text: qrPayload,
                    width: 256,
                    height: 256,
                    render: 'canvas'
                });
                
                // Update protocol data attribute
                qrEl.data('protocol', currentProtocol);
            } catch (error) {
                console.error('BSV: QR code generation failed', error);
                qrEl.html('<div style="padding: 40px; text-align: center; color: #999;">QR Code Unavailable<br><small>Please use copy buttons below</small></div>');
            }
        },

        getInvoiceUrl: function(orderId, orderKey) {
            // Generate signed invoice URL for BIP270
            // Server will verify signature to prevent tampering
            const baseUrl = window.location.origin;
            const params = new URLSearchParams({
                order_id: orderId,
                key: orderKey,
                sig: 'placeholder' // Server generates real signature
            });
            
            // Note: Real signature is generated server-side
            // This is just for QR generation - actual URL comes from localized data
            return bsvPaymentData.invoiceUrl || `${baseUrl}/wc-api/bsv_invoice?${params.toString()}`;
        },

        bindProtocolTabs: function() {
            const self = this;
            
            $('.bsv-protocol-tab, .bsv-wallet-tab').on('click', function() {
                const $tab = $(this);
                const protocol = $tab.data('protocol');
                
                // Update active state
                $('.bsv-protocol-tab, .bsv-wallet-tab').removeClass('active').attr('aria-selected', 'false');
                $tab.addClass('active').attr('aria-selected', 'true');
                
                // Update QR code
                self.generateQRCode(protocol);
                
                // Update protocol description/hint
                $('.bsv-protocol-description, .bsv-qr-hint').hide();
                $(`.bsv-protocol-description[data-protocol="${protocol}"], .bsv-qr-hint[data-protocol="${protocol}"]`).show();
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        BSVPaymentConsole.init();
    });

})(jQuery);
