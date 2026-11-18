# Base PWA de KeylaShop

La aplicación incorpora un manifiesto y un service worker para su uso en contexto
seguro (HTTPS o localhost). El manifiesto abre `/app/` y utiliza modo standalone;
el alcance `/` incluye las rutas internas actuales de la aplicación.

El service worker usa exclusivamente la red para navegar. Si una navegación a
`/` o `/app/…` falla por falta de red, muestra una página informativa sin datos
del negocio. No usa Cache Storage, no guarda sesiones, respuestas de API ni
documentos y no intercepta POST ni otras mutaciones. No hay cola ni reenvío de
operaciones sin conexión. Las rutas públicas de enlaces y las API no reciben
respuesta del service worker. La página pública de pedido no registra el worker.

## Verificación y pendientes

- `node --test tests/Unit/pwa-worker.test.mjs tests/Unit/pwa-browser.test.mjs`:
  cinco casos aprobados, incluido Chromium real con red desconectada, cero cachés
  y ninguna escritura reenviada. El navegador usa un servidor sintético local.
- `npm run build` y la regresión de interfaz se documentan en `PROGRESO.md`.
- La instalación desde el navegador, los iconos raster para plataformas que los
  requieran, la apariencia en pantalla de inicio y la aceptación en iPad/Android
  físicos siguen pendientes. El SVG temporal no sustituye el logo oficial.
- La instalación pública y el comportamiento real detrás de HTTPS/CDN no se han
  verificado con estos cambios. CI queda preparado, pendiente de ejecución remota.
