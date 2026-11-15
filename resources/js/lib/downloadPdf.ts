import axios from 'axios';

/** Fetch under the current session so download failures stay visible in the app. */
export async function downloadPrivatePdf(url: string, filename: string) {
    const target = new URL(url, window.location.origin);
    const invoice=/^\/api\/v1\/business\/stores\/\d+\/invoices\/\d+\/document$/.test(target.pathname);
    const financial=/^\/api\/v1\/business\/stores\/\d+\/sales\/orders\/\d+\/documents\/(adjustment|credit)\/\d+$/.test(target.pathname);
    if (target.origin !== window.location.origin || (!invoice&&!financial)) {
        throw new Error('Invalid private document URL.');
    }
    const response = await axios.get<Blob>(target.href, { responseType: 'blob', withCredentials: true, headers: { Accept: 'application/pdf' } });
    if (!/^application\/pdf(?:;|$)/i.test(response.data.type)) throw new Error('Invalid invoice document.');
    const objectUrl = URL.createObjectURL(response.data);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = filename.replace(/[\\/]/g, '_');
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
}
