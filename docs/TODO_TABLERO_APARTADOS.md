# Tablero único de Apartados

Instrucción vigente del propietario: el menú se llama **Apartados**. «Canasta» es
un sinónimo de dominio, no otro módulo. Una sola vista contiene grid superior y
detalle inferior reactivo, sin navegar a una pantalla de detalle.

- [x] Auditar pedidos/reservas/pagos/PDF, permisos y configuración existente.
- [x] Confirmar WhatsApp configurable y opcional (respuesta expresa del propietario).
- [x] Migración conservadora para número visible, tipo de pedido, cierre y despacho.
- [x] Edición de líneas/cantidades/envío/descuento con reservas diferenciales, precios
      históricos, versión y rechazo de totales inferiores al depósito existente.
- [x] Alta de apartado, edición de contacto, cambio de número y cancelación compatible.
- [x] BasketCard, BasketGrid, BasketDetailPanel, BasketItemsList y BasketSummary.
- [x] Grid y detalle en una vista; búsqueda y filtros sin depósito/abierto/factura
      enviada/pagado/enviado, con estados independientes y datos reales.
- [x] Configuración opcional WhatsApp con secretos cifrados y diagnóstico sin secretos.
- [x] Subir PDF privado y enviar plantilla por Meta Cloud API desde servidor (HTTP fake probado).
- [x] Persistir aceptación/entrega/fallo; webhook firmado; no repetir envío de resultado
      desconocido ni generar una segunda factura al reintentar.
- [x] Pruebas de API, concurrencia, envíos con HTTP simulado y responsive seis perfiles.
- [x] Recorrido en demo local y documentación de resultados/pendientes reales.
- [x] Selector persistente 2×2/4×4/6×6, paginación 4/16/36 y columnas adaptables.
- [x] Cambiar densidad conserva selección/borrador; persistencia y giro probados en seis perfiles.
- [ ] Activación opcional con cuenta/número/plantilla real y webhook HTTPS público.
- [ ] Aceptación final en tablet física.

WhatsApp real no está configurado. Se habilitará con el número/token/plantilla de
la clienta; no se enviarán mensajes a teléfonos ficticios de la demo. Las pruebas
de proveedor usan HTTP fake y bloquean conexiones no simuladas. La factura generada,
la aceptación/envío WhatsApp, el pago y el despacho de mercancía no son el mismo estado.
