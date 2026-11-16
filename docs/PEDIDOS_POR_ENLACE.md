# Pedidos mediante enlace temporal

## Flujo de tienda y cliente

1. En **Clientes**, seleccionar la ficha y **Enlace para nuevo pedido**.
2. Elegir almacén activo, hasta50 variantes autorizadas, envío acordado y duración
   de1/24/72/168 horas. El cliente debe estar activo para generar la autorización.
3. Generar y copiar manualmente el enlace `/s/{token}`. Hay hasta5 autorizaciones
   activas por cliente/sucursal; el historial prioriza las activas para revocarlas.
4. El destinatario abre el enlace sin cuenta, elige variantes/cantidades, revisa
   subtotal/impuestos/envío/precios unitarios vigentes y confirma el pedido.
5. Se crea un apartado reservado en la sucursal/almacén del enlace, visible en el
   tablero existente. Pago, factura y entrega continúan en los flujos de tienda.

El enlace vincula un cliente existente; la página pública no devuelve sus datos
de contacto, notas, otras deudas ni otros pedidos. Sólo se muestran variantes de la
lista autorizada. No hay alta anónima de clientes ni edición de pedidos ya confirmados
en esta entrega. El carrito puede editarse antes de confirmar.

## Integridad y recuperación

- Token opaco HMAC-SHA256 separado por dominio; sólo se guarda su SHA256. El journal
  de generación y la auditoría no contienen token ni URL pública.
- `share_link_catalogs` agrega autorización de variantes, almacén y envío con FK
  compuestas a las tablas existentes, sin alterar los datos de `share_links`.
- GET de página/datos/catálogo no consume la autorización ni reserva inventario.
- El servidor fija cliente, sucursal, almacén, modo reservation, descuento0,
  envío acordado y pago inicial0. No acepta precios, cobros, notas ni identificadores
  de contexto proporcionados por el navegador como autoridad.
- Cotización/cierre reutilizan SalesService/StockLedger: DECIMAL/BCMath, precios
  actuales, snapshots, versiones de producto y bloqueos de stock ordenados.
- Confirmación con UUID y hash de cotización; se bloquea la autorización y sólo se
  marca used al commit conjunto con el pedido/reserva. `orders.share_link_id`
  conserva su restricción única. Fallo de stock/precio/validación revierte todo y
  deja el enlace activo; se revalida expiry después de esperar los locks de stock.
- Repetir el mismo UUID/payload recupera el resultado; otros datos/UUID tras consumo
  devuelven409. GET del enlace usado recupera un resumen limitado del pedido propio.
- El navegador guarda carrito/confirmación pendiente en sessionStorage bajo SHA256
  del token, sin almacenar el token en el journal. Girar conserva el carrito; al
  recargar tras respuesta perdida se consulta GET, sin confirmar automáticamente.
- Revocar un enlace usado impide consultar su resumen pero no cancela el pedido,
  libera stock ni revierte pagos. La política de vencimiento de reservas es distinta
  de la vigencia de la autorización pública.

## API

Rutas privadas después de `/api/v1/business/stores/{store}`:

| Método | Ruta | Permiso |
|---|---|---|
| GET | `/order-request-links?customer_id={id}` | sales.view + customers.view; metadatos sin secretos |
| POST | `/order-request-links` | sales.create; UUID, customer_id, warehouse_id, variant_ids[], hours, shipping |
| POST | `/order-request-links/{link}/revoke` | sales.create; revocación repetible |

Rutas públicas después de `/api/v1/order-requests/{token}`:

| Método | Ruta | Efecto |
|---|---|---|
| GET | `/` | tienda/vigencia y estado; resumen propio si used |
| GET | `/catalog?q=&page=1` | hasta12 variantes autorizadas/página, precio/disponibilidad e imagen |
| POST | `/quote` | lines[{variant_id,quantity}]; calcula total/hash sin reservar |
| POST | `/confirm` | lines, quote_hash y request_key UUID; crea una reserva única |

Sesión/CSRF/permisos/sucursal para rutas privadas. Las escrituras públicas conservan
CSRF web de mismo origen y rate limits (60/min/IP global,10 confirmaciones/min/IP).
La entrada `/s/{token}` carga una página pública independiente, sin consultar la
sesión del equipo ni mostrar la navegación interna. La API rechaza enlaces vencidos,
revocados, de emisores sin autorización, sucursales/almacenes inactivos y clientes
archivados antes del consumo. Cabeceras no-store/no-referrer/noindex y CSP.

La página HTML es un shell; la API determina la disponibilidad de la autorización.
En producción: HTTPS, access logs/telemetría con tokens redactados y CSP de imágenes
ajustada al dominio autorizado si se activa R2/CDN. La URL local no es una publicación
en Internet. El envío del enlace es manual; no dispara mensajes de WhatsApp.

## Evidencia y pendientes

- PHP:150 pruebas /1.569 aserciones, SQLite :memory: con guardia antes de migrar.
- Navegadores:364/364, seis perfiles;18/18 públicos repetidos tras pulido final.
- Pruebas: whitelist/privacidad, binding, manipulación de importe/pago/contexto,
  replay, nuevoUUID tras consumo, cambio de precio, sobrestock, expiry/revocación,
  emisor suspendido, alcance de sucursal y conservación de reservas al revocar.
- Migración002100 aplicada en SQLite de demo con respaldo íntegro verificado.
- En MySQL 8.4 aislado se reprodujo y corrigió una carrera al generar el quinto
  enlace para un cliente: dos trabajadores esperaron el bloqueo de sucursal y solo
  uno pudo completar la creación; el otro recibió 409. Evidencia privada en
  `.runtime/reservation-qa-D28846BBAA97`. No cubre todas las carreras del módulo.
- Pendientes: concurrencia adicional del módulo, edición de pedidos ya guardados,
  validación HTTP pública en HTTPS y aceptación en tablet física.
