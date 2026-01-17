/**
 * BSV Wallet Integration - BRC-100 Payment Support
 * v6.0.0 - Customer-side BRC-100 wallet payment to xpub-derived addresses
 * 
 * Based on Nullify app patterns for BRC-100 wallet interaction
 */
(function($) {
    'use strict';

    const BSVWalletIntegration = {
        wallet: null,
        walletType: null,

        init: function() {
            console.log('[BSV Wallet] Initializing wallet integration...');
            
            // Bind wallet tab switching
            this.bindWalletTabs();
            
            // Bind BRC-100 payment button
            this.bindBRC100Button();
            
            // Check if wallet is available
            this.checkWalletAvailability();
        },

        bindWalletTabs: function() {
            const self = this;
            
            $('.bsv-wallet-tab').on('click', function() {
                const $tab = $(this);
                const walletType = $tab.data('wallet');
                
                // Update active tab
                $('.bsv-wallet-tab').removeClass('active').attr('aria-selected', 'false');
                $tab.addClass('active').attr('aria-selected', 'true');
                
                // Regenerate QR code for selected wallet
                self.switchWalletQR(walletType);
            });
        },

        switchWalletQR: function(walletType) {
            const $qrEl = $('#bsv-qr-code');
            const $qrCard = $('.bsv-qr-card');
            
            // Fade out
            $qrCard.addClass('transitioning');
            
            setTimeout(() => {
                // Clear existing QR
                $qrEl.empty();
                
                // Update wallet type
                $qrEl.attr('data-wallet', walletType);
                
                // Regenerate QR with wallet-specific URI
                this.generateWalletQR(walletType);
                
                // Fade in
                setTimeout(() => {
                    $qrCard.removeClass('transitioning');
                }, 50);
            }, 300);
        },

        generateWalletQR: function(walletType) {
            const $qrEl = $('#bsv-qr-code');
            const address = $qrEl.data('address') || bsvPaymentData.bsvAddress;
            const amount = $qrEl.data('amount') || bsvPaymentData.bsvAmount;

            if (!address || !amount) {
                console.warn('[BSV Wallet] Missing address or amount for QR code');
                return;
            }

            // Generate wallet-specific BIP21 URI
            let bip21Uri = `bitcoin:${address}?amount=${amount}`;
            
            // Add wallet-specific parameters if needed
            switch(walletType) {
                case 'handcash':
                    // HandCash may support additional parameters
                    break;
                case 'rock':
                    // RockWallet parameters
                    break;
                case 'yours':
                    // YoursWallet parameters
                    break;
                case 'generic':
                default:
                    // Standard BIP21
                    break;
            }

            try {
                $qrEl.qrcode({
                    text: bip21Uri,
                    width: 256,
                    height: 256,
                    render: 'canvas'
                });
                console.log('[BSV Wallet] QR code generated for', walletType);
            } catch (error) {
                console.error('[BSV Wallet] QR code generation failed', error);
                $qrEl.html('<div style="padding: 40px; text-align: center; color: #999;">QR Code Unavailable<br><small>Please use copy buttons below</small></div>');
            }
        },

        checkWalletAvailability: function() {
            // Check if window.bsv or window.metanet is available (BRC-100 wallets)
            if (typeof window !== 'undefined') {
                if (window.bsv || window.metanet) {
                    console.log('[BSV Wallet] BRC-100 wallet detected');
                    $('#bsv-brc100-pay-button').prop('disabled', false);
                } else {
                    console.log('[BSV Wallet] No BRC-100 wallet detected - button will prompt for wallet');
                }
            }
        },

        bindBRC100Button: function() {
            const self = this;
            
            $('#bsv-brc100-pay-button').on('click', async function() {
                const $button = $(this);
                
                // Prevent double-click
                if ($button.prop('disabled')) return;
                
                $button.prop('disabled', true);
                const originalText = $button.html();
                $button.html('<span style="opacity: 0.7;">Connecting wallet...</span>');
                
                try {
                    await self.payWithBRC100Wallet();
                } catch (error) {
                    console.error('[BSV Wallet] Payment failed', error);
                    alert('Payment failed: ' + (error.message || 'Unknown error'));
                } finally {
                    $button.prop('disabled', false);
                    $button.html(originalText);
                }
            });
        },

        async getWallet() {
            // Check for BRC-100 wallet (similar to Nullify's getWallet pattern)
            if (typeof window === 'undefined') {
                throw new Error('Window object not available');
            }

            // Check for Metanet Client (BRC-73 standard)
            if (window.metanet && typeof window.metanet.createAction === 'function') {
                console.log('[BSV Wallet] Using Metanet Client');
                return { type: 'metanet', client: window.metanet };
            }

            // Check for legacy window.bsv
            if (window.bsv && typeof window.bsv.createAction === 'function') {
                console.log('[BSV Wallet] Using window.bsv');
                return { type: 'bsv', client: window.bsv };
            }

            throw new Error('No BRC-100 wallet found. Please install a BSV wallet extension like Yours Wallet or Panda Wallet.');
        },

        async payWithBRC100Wallet() {
            console.log('[BSV Wallet] Initiating BRC-100 payment...');

            // Get payment details from page
            const address = bsvPaymentData.bsvAddress;
            const amountBSV = parseFloat(bsvPaymentData.bsvAmount);
            const amountSats = Math.round(amountBSV * 100000000);
            const orderId = bsvPaymentData.orderId;

            if (!address || !amountSats || amountSats <= 0) {
                throw new Error('Invalid payment details');
            }

            console.log('[BSV Wallet] Payment details:', {
                address,
                amountBSV,
                amountSats,
                orderId
            });

            // Get wallet client
            const { client, type } = await this.getWallet();
            this.wallet = client;
            this.walletType = type;

            // Build locking script for P2PKH payment (based on Nullify's sendSatsToAddress)
            const lockingScript = await this.buildLockingScript(address);

            // Create payment output
            const outputs = [{
                satoshis: amountSats,
                lockingScript: lockingScript,
                outputDescription: `WooCommerce Order #${orderId}`
            }];

            console.log('[BSV Wallet] Creating payment action...');

            // Create action using BRC-100 wallet
            const result = await client.createAction({
                description: `Payment for WooCommerce Order #${orderId}`,
                outputs: outputs,
                labels: ['wallet payment', 'woocommerce'],
                options: {
                    returnTXIDOnly: false
                }
            });

            console.log('[BSV Wallet] Payment action created:', result);

            // Extract txid from result
            const txid = this.extractTxid(result);
            
            if (txid) {
                console.log('[BSV Wallet] Payment broadcast successful, txid:', txid);
                
                // Show success message
                alert('Payment sent! Transaction ID: ' + txid + '\n\nThe page will refresh to check payment status.');
                
                // Trigger payment check
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            } else {
                throw new Error('Payment created but no transaction ID returned');
            }

            return result;
        },

        async buildLockingScript(address) {
            // We need to build a P2PKH locking script from the address
            // This requires the @bsv/sdk P2PKH class, but we're in a browser context
            // For now, we'll use a simple approach that works with most wallets
            
            // The wallet should accept the address directly in many cases
            // If not, we need to decode the address and build the script
            
            // Simple approach: return the address and let the wallet handle it
            // Most BRC-100 wallets can derive the locking script from an address
            
            // For proper implementation, we'd need to:
            // 1. Decode base58 address to get pubkey hash
            // 2. Build OP_DUP OP_HASH160 <pubkeyhash> OP_EQUALVERIFY OP_CHECKSIG
            
            // Temporary: return address as hex (wallet will convert)
            // This is a placeholder - proper implementation needs @bsv/sdk in browser
            
            throw new Error('Locking script generation not yet implemented. Please use QR code or copy address method.');
        },

        extractTxid(result) {
            // Extract txid from wallet response (based on Nullify's extractTxid pattern)
            if (!result) return null;

            // Check various possible locations for txid
            if (result.txid) return result.txid;
            if (result.tx && result.tx.id) return result.tx.id;
            if (result.transaction && result.transaction.id) return result.transaction.id;
            if (typeof result === 'string' && result.length === 64) return result;

            // Check sendWithResults array
            if (Array.isArray(result.sendWithResults) && result.sendWithResults.length > 0) {
                const firstSend = result.sendWithResults[0];
                if (firstSend.txid) return firstSend.txid;
            }

            console.warn('[BSV Wallet] Could not extract txid from result:', result);
            return null;
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        BSVWalletIntegration.init();
    });

})(jQuery);
