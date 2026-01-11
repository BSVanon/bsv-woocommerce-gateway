/**
 * Bitcoin SV Payment Method for WooCommerce Blocks
 *
 * @package Bitcoin-SV-Payments-for-WooCommerce
 * @since 5.1.0
 */

const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { decodeEntities } = window.wp.htmlEntities;
const { createElement } = window.wp.element;

const settings = getSetting('bitcoin_data', {});

const defaultLabel = decodeEntities(settings.title) || 'Bitcoin SV Payment';
const defaultDescription = decodeEntities(settings.description) || 'Please proceed to the next screen to see necessary payment details.';

/**
 * Content component for Bitcoin SV payment method
 */
const Content = () => {
    return createElement('div', { className: 'wc-block-bitcoin-payment-description' },
        defaultDescription
    );
};

/**
 * Label component for Bitcoin SV payment method
 */
const Label = (props) => {
    const { PaymentMethodLabel } = props.components;
    const icon = settings.icon ? createElement('img', {
        src: settings.icon,
        alt: defaultLabel,
        style: { height: '24px', width: 'auto', marginLeft: '8px' }
    }) : null;

    return createElement(PaymentMethodLabel, { text: defaultLabel }, icon);
};

/**
 * Bitcoin SV payment method configuration
 */
const BitcoinPaymentMethod = {
    name: 'bitcoin',
    label: createElement(Label, null),
    content: createElement(Content, null),
    edit: createElement(Content, null),
    canMakePayment: () => true,
    ariaLabel: defaultLabel,
    supports: {
        features: settings.supports || ['products'],
    },
};

registerPaymentMethod(BitcoinPaymentMethod);
