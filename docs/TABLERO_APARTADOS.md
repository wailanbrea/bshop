# Apartados — tablero único

El menú se llama **Apartados**. Canasta y apartado son sinónimos, no dos módulos.
La vista `/app/apartados` muestra grid arriba y detalle debajo. Seleccionar otra
tarjeta consulta datos sin recargar la página ni abrir una ruta de detalle.
`/app/apartados/nuevo` es un alias compatible de la misma vista.

## Operación y componentes

En pedidos facturados, **Devoluciones de mercancía** recibe sólo unidades con
entrega trazada, con reposición opcional en el almacén original. Se conserva el
documento y se marca el ajuste económico pendiente; el cobro queda bloqueado hasta
su conciliación. Ver `RECEPCION_DEVOLUCIONES.md`.

**Pagos y reversiones** consulta el historial del pedido. Propietario/administrador
con payments.reverse puede corregir un cobro registrado por error con motivo y
versión, conservando recibo, asignaciones y PDF históricos. Ver `REVERSION_PAGOS.md`.

### Vista de cuadrícula

Selector **2×2 / 4×4 / 6×6**, con hasta **4 / 16 / 36 tarjetas por página**.
La preferencia se conserva por usuario en este navegador (`localStorage`), con
4×4 como valor inicial. Cambiarla vuelve a la primera página del listado sin
descartar el apartado seleccionado ni sus artículos pendientes de guardar.

Las columnas se adaptan al ancho del contenedor: la preferencia 6×6 presenta
seis columnas en tablet horizontal de 1024 px, cuatro en vertical de 768 px y
dos en teléfono de 390 px. Conserva el tamaño de página y la preferencia al girar.

`BasketBoard`, `BasketCard`, `BasketGrid`, `BasketDetailPanel`, `BasketItemsList`
y `BasketSummary`. Búsqueda por cliente/teléfono/número, filtros Sin depósito,
Abierto, Factura enviada, Pagado, Enviado y Todos; botón + Cliente.

El detalle permite agregar variantes, editar cantidades, retirar líneas, modificar
envío/descuento y registrar depósitos. Guardar recalcula en servidor, actualiza
la tarjeta y ajusta sólo la diferencia de reserva. Precios existentes históricos;
artículos nuevos a precio actual. El nuevo total no puede ser menor que el depósito.
Los pagos nunca se sobrescriben. Artículos facturados o con entregas incompatibles
se conservan; cancelar no borra historial ni dinero y requiere resolver devoluciones.

«Cambiar #» modifica `basket_number`, único por sucursal; no cambia ID ni referencias
de stock/pago/factura. «Editar cliente» usa versión y actualiza contacto vivo; el
cierre toma los datos actualizados y congela el documento. PDFs emitidos no se reescriben.

Borradores independientes al alternar tarjetas. Conflictos409 preservan campos;
«Descartar borrador y actualizar» vuelve al servidor por acción explícita.
«Actualizar estados» refresca grid/detalle no editado, sin reload. Mutaciones
interrumpidas conservan UUID/cuerpo por usuario/sucursal en sessionStorage; al
recargar se consulta resultado. Nunca se reenvían operaciones automáticamente.

## Estados independientes

- Abierto: no cerrado/cancelado; puede tener depósito y reservas.
- Factura generada: factura única y closed_at; no implica mensaje enviado.
- Pagado: pagos cubren total o factura pagada; no implica despacho.
- Factura enviada: proveedor confirma sent/delivered/read, no mero HTTP200.
- Enviado: shipped_at explícito después de registrar entrega física.

Cerrar genera una sola factura. Si WhatsApp está habilitado/completo, el botón
indica «Cerrar pedido y enviar factura» y envía desde servidor tras confirmar
factura/PDF. Un fallo conserva la factura; reintentar mensaje no genera otra.
Hay descarga privada y reintento de fallos confirmados, sin WhatsApp Web ni wa.me.

## WhatsApp opcional

También puede compartirse manualmente un **enlace temporal de consulta**, desde
«Compartir enlace» en el detalle guardado. Duración elegible y revocación; muestra
el pedido sin contacto/notas del cliente ni otros documentos. Ver `ENLACES_CONSULTA.md`.

**Configuración → WhatsApp · Opcional**, permiso business.manage:

1. ID del número remitente y versión Graph soportada.
2. Token de acceso con permiso de mensajería y App Secret.
3. Token verificador de webhook, mínimo16 caracteres.
4. Plantilla de factura aprobada con encabezado documento PDF e idioma correcto.
5. Variables del cuerpo, en orden: customer_name, invoice_number, total, business_name;
   vacío si no hay variables. Deben coincidir exactamente con su plantilla aprobada.

Se usa plantilla para no asumir una ventana de atención24h abierta. Secretos cifrados
con APP_KEY; formulario devuelve presencia y vacío para conservar. Auditoría no
incluye claves. Variables `.env.example` permiten aprovisionar; configuración guardada
en la instalación tiene precedencia. Conservar APP_KEY en el respaldo de configuración.

Registrar `/webhooks/whatsapp` en URL pública **HTTPS** con el token verificador.
POST valida HMAC-SHA256 del cuerpo original con App Secret, correlaciona ID de
mensaje/callback/remitente/destinatario y rechaza regresiones de estado/tiempo.
Configuración completa no significa cuenta verificada: se comprueba con el número,
plantilla y callbacks reales al activar la integración.

El servidor verifica PDF privado, sube multipart a Meta y envía su media ID en la
plantilla. No publica enlace privado. `invoice_deliveries` conserva estados
pending/sending/accepted/sent/delivered/read/failed/unknown. Accepted no se muestra
como enviado. Timeout/5xx tras enviar se consideran desconocidos: **sin reintento
automático** para evitar duplicados; webhook puede reconciliarlos. Dos UUID distintos
tampoco duplican un envío activo. El lock supone un servidor con almacenamiento
compartido entre procesos PHP, como los locks locales de imágenes/PDF.

La demo mantiene la integración **desactivada**. No se enviaron mensajes reales a
contactos ficticios. Proveedor/webhooks se probaron con HTTP fake y firmas de prueba;
activar y comprobar la cuenta real es opcional y requiere su configuración.

## Verificación

Checklist `TODO_TABLERO_APARTADOS.md`, evidencia en último `PROGRESO.md`.
Verificación actual: 137 pruebas PHP/1.323 aserciones; regresión integral de
316 casos browser/seis perfiles y revalidación posterior de los 78 casos de
Apartados. MySQL probó reserva
2→3→1→3, depósito conservado, precio histórico, agregación/renumeración y cierre único.
Dos usuarios editando apartados por última unidad:200/409.

Demo real: cantidad editada/restaurada, artículo añadido/retirado con depósito50,
#2 cambiado a #12 conservando ID2, contacto actualizado y mismos totales. Sin reload
al alternar tarjetas; capturas1440/1024/768/390 sin desbordamiento horizontal.

Fuentes: [Meta Messages](https://developers.facebook.com/docs/whatsapp/cloud-api/reference/messages/),
[plantillas](https://developers.facebook.com/documentation/business-messaging/whatsapp/templates/overview),
[webhooks firmados](https://developers.facebook.com/docs/graph-api/webhooks/getting-started/).
