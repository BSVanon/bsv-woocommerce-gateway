/**
 * Bitcoin SV Payment Method for WooCommerce Blocks
 *
 * @package Bitcoin-SV-Payments-for-WooCommerce
 * @since 5.1.0
 */

const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { decodeEntities } = window.wp.htmlEntities;
const { __ } = window.wp.i18n;

const settings = getSetting('bitcoin_sv_data', {});

const defaultLabel = decodeEntities(settings.title) || 'Bitcoin SV Payment';
const defaultDescription = decodeEntities(settings.description) || 'Please proceed to the next screen to see necessary payment details.';

const ensureStylesInjected = () => {
    if (document.getElementById('bsv-select-style')) {
        return;
    }

    const style = document.createElement('style');
    style.id = 'bsv-select-style';
    style.innerHTML = `
        .bsv-select-card {
            background: #fff;
            border-radius: 20px;
            padding: 24px 28px;
            box-shadow: 0 14px 35px rgba(15, 23, 42, 0.12);
            border: 1px solid rgba(148, 163, 184, 0.3);
            color: #0f172a;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .bsv-select-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .bsv-select-eyebrow {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            font-weight: 600;
            color: #94a3b8;
            margin-bottom: 4px;
        }

        .bsv-select-title-group h3 {
            margin: 0;
            font-size: 26px;
            font-weight: 700;
        }

        .bsv-select-badge {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .bsv-select-badge img {
            height: 64px;
            width: auto;
        }

        .bsv-select-description {
            margin: 0;
            color: #475569;
            line-height: 1.6;
        }

        .bsv-select-features {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .bsv-select-feature {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            background: rgba(15, 118, 110, 0.04);
            font-weight: 600;
            color: #0f172a;
        }

        .bsv-select-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #0d9488;
            display: inline-block;
        }

        .bsv-select-next {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 20px;
            border-radius: 16px;
            background: rgba(252, 200, 9, 0.16);
            border: 1px solid rgba(252, 200, 9, 0.35);
        }

        .bsv-select-next-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: #ca8a04;
            display: block;
            margin-bottom: 4px;
        }

        .bsv-select-next strong {
            font-size: 18px;
            font-weight: 700;
            color: #854d0e;
        }

        .bsv-select-arrow {
            font-size: 28px;
            font-weight: 500;
            color: #b45309;
        }

        @media (max-width: 600px) {
            .bsv-select-card {
                padding: 20px;
            }

            .bsv-select-title-group h3 {
                font-size: 22px;
            }
        }
    `;

    document.head.appendChild(style);
};

const { createElement } = window.wp.element;

const Feature = ({ label }) => {
    return createElement('div', { className: 'bsv-select-feature' },
        createElement('span', { className: 'bsv-select-dot', 'aria-hidden': 'true' }),
        createElement('span', null, label)
    );
};

const Content = () => {
    if (typeof document !== 'undefined') {
        ensureStylesInjected();
    }

    return createElement('div', { className: 'bsv-select-card', 'aria-live': 'polite' },
        createElement('div', { className: 'bsv-select-header' },
            createElement('div', { className: 'bsv-select-title-group' },
                createElement('div', { className: 'bsv-select-eyebrow' }, __('Digital Payment', 'sendbsv-bsv-payments-for-woocommerce')),
                createElement('h3', null, defaultLabel)
            ),
            createElement('div', { className: 'bsv-select-badge' },
                settings.icon ? createElement('img', { src: settings.icon, alt: 'BSV' }) : null
            )
        ),
        createElement('p', { className: 'bsv-select-description' }, defaultDescription),
        createElement('div', { className: 'bsv-select-features' },
            createElement(Feature, { label: __('BRC-100 Payment Button & Legacy Payments Supported', 'sendbsv-bsv-payments-for-woocommerce') }),
            createElement(Feature, { label: __('Variety of QR Codes Styles for any wallet', 'sendbsv-bsv-payments-for-woocommerce') }),
            createElement(Feature, { label: __('Live Payment Confirmation Tracker & Blockchain Explorer Link', 'sendbsv-bsv-payments-for-woocommerce') })
        ),
        createElement('div', { className: 'bsv-select-next' },
            createElement('div', null,
                createElement('span', { className: 'bsv-select-next-label' }, __('Up next', 'sendbsv-bsv-payments-for-woocommerce')),
                createElement('strong', null, __('Scan QR & submit payment', 'sendbsv-bsv-payments-for-woocommerce'))
            ),
            createElement('span', { className: 'bsv-select-arrow', 'aria-hidden': 'true' }, '→')
        )
    );
};

const BitcoinPaymentMethod = {
    name: 'bitcoin_sv',
    label: defaultLabel,
    content: createElement(Content, {}),
    edit: createElement(Content, {}),
    canMakePayment: () => true,
    ariaLabel: defaultLabel,
    supports: {
        features: settings.supports || ['products'],
    },
};

registerPaymentMethod(BitcoinPaymentMethod);
