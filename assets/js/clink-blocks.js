const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const el = window.wp.element.createElement;

const PAYMENT_METHOD_NAME = 'clink';
const settings = getSetting( `${PAYMENT_METHOD_NAME}_data`, {} );
const label     = settings.title || 'Lightning (CLINK)';

const Label = () =>
  el(
    'span',
    { style: { display: 'flex', alignItems: 'center', gap: '8px' } },
    settings.icon ? el( 'img', { src: settings.icon, style: { height: '20px', width: '20px' }, alt: '' } ) : null,
    label
  );

const PaymentContent = () =>
  el( 'div', { style: { margin: '4px 0' }, dangerouslySetInnerHTML: { __html: settings.description || '' } } );

registerPaymentMethod( {
  name: PAYMENT_METHOD_NAME,
  label: el( Label ),
  content: el( PaymentContent ),
  edit: el( PaymentContent ),
  canMakePayment: () => true,
  ariaLabel: label,
  supports: {
    features: settings.supports || [ 'products' ],
  },
} );
