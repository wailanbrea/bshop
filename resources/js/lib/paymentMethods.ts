export interface PaymentMethodDef {
    code: string;
    label: string;
    glyph: string;
    bg: string;
    fg: string;
}

export const PAYMENT_METHODS: PaymentMethodDef[] = [
    { code: 'cash', label: 'Efectivo', glyph: '💵', bg: '#dcfce7', fg: '#166534' },
    { code: 'bank_transfer', label: 'Transferencia', glyph: '🏦', bg: '#e0e7ff', fg: '#3730a3' },
    { code: 'card', label: 'Tarjeta', glyph: '💳', bg: '#f3e8ff', fg: '#6b21a8' },
    { code: 'zelle', label: 'Zelle', glyph: 'Z', bg: '#6d1ed4', fg: '#ffffff' },
    { code: 'cash_app', label: 'Cash App', glyph: '$', bg: '#00d632', fg: '#ffffff' },
    { code: 'paypal', label: 'PayPal', glyph: 'P', bg: '#003087', fg: '#ffffff' },
    { code: 'venmo', label: 'Venmo', glyph: 'V', bg: '#008cff', fg: '#ffffff' },
    { code: 'credit', label: 'Crédito', glyph: '★', bg: '#fef3c7', fg: '#b45309' },
    { code: 'afterpay', label: 'Afterpay', glyph: 'A', bg: '#b2fce4', fg: '#0f172a' },
    { code: 'klarna', label: 'Klarna', glyph: 'K', bg: '#ffb3c7', fg: '#18181b' },
    { code: 'apple_pay', label: 'Apple Pay', glyph: '', bg: '#111827', fg: '#ffffff' },
];

export const PAYMENT_METHOD_LABELS: Record<string, string> = Object.fromEntries(
    PAYMENT_METHODS.map((m) => [m.code, m.label]),
);

export function paymentMethodLabel(code: string): string {
    return PAYMENT_METHOD_LABELS[code] || code;
}

export function paymentMethodInfo(code: string): PaymentMethodDef {
    return PAYMENT_METHODS.find((m) => m.code === code) || { code, label: code, glyph: '•', bg: '#f1f5f9', fg: '#334155' };
}
