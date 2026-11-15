import type { SalesCustomer } from '../sales';

// In-page updates only; server permissions and customer status remain authoritative.
export function notifyCustomerChanged(customer:SalesCustomer): void {
    window.dispatchEvent(new CustomEvent('bshop:customer-changed', {detail:customer}));
}
