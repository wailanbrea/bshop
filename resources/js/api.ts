import axios from 'axios';

export const api = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});

export async function csrf() {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

export function errorMessage(error: unknown): string {
    if (!axios.isAxiosError(error)) return 'Ocurrió un error inesperado. Inténtalo de nuevo.';
    if (!error.response) return 'No hay conexión. Comprueba tu red antes de reintentar.';
    if (error.response.status === 419) return 'La sesión ha caducado. Recarga la página e inicia sesión.';
    if (error.response.status === 403) return 'Tu usuario no tiene permiso para esta acción.';
    if (error.response.status === 404) return 'Este recurso ya no está disponible o no tienes acceso.';
    if (error.response.status === 409) return 'Los datos cambiaron. Actualiza antes de volver a intentarlo.';
    if (error.response.status === 429) return 'Has realizado varios intentos. Espera un minuto.';
    const errors = error.response.data?.errors as Record<string, string[]> | undefined;
    return errors ? Object.values(errors).flat().join(' ') : error.response.data?.message || 'No se pudo completar la operación.';
}
