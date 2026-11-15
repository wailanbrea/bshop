# API v1 — negocio único

Todas las rutas pertenecen al negocio de esta instalación. No se reciben
identificadores de empresa ni slugs. Sesión Sanctum same-origin y CSRF para
mutaciones; inicializar con `GET /sanctum/csrf-cookie`.

| Método | Ruta después de `/api/v1` | Acceso |
|---|---|---|
| POST | `/login` | Credenciales válidas; rate limit |
| POST | `/logout` | Sesión; también permite cerrar sesión a usuarios suspendidos |
| POST | `/forgot-password`, `/reset-password` | Broker de recuperación; SMTP configurable |
| GET | `/session` | Usuario activo, roles, permisos y perfil del único negocio |
| GET | `/business/workspace` | stores.view; sólo sucursales autorizadas |
| PATCH | `/business` | business.manage; nombre, legal_name y timezone |
| POST | `/business/stores` | stores.manage |
| PATCH | `/business/stores/{store}` | stores.manage + acceso explícito a sucursal |
| GET | `/business/stores/{store}/warehouses` | warehouses.view + acceso a sucursal |
| POST/PATCH | `/business/stores/{store}/warehouses[/{id}]` | warehouses.manage + misma sucursal |
| GET | `/business/team` | members.manage |
| POST | `/business/invitations` | members.manage; enlace manual mostrado una vez |
| POST | `/business/invitations/{id}/revoke` | members.manage |
| PATCH | `/business/users/{id}` | members.manage; rol, sucursales y estado del usuario |
| GET | `/invitations/{token}` | Token vigente; GET nunca consume |
| POST | `/invitations/{token}/accept` | CSRF, correo invitado, nombre y contraseña para el nuevo usuario |

No existe `POST /business` ni rutas de creación/selección de empresas. El perfil
se instala por consola y la base impide insertar otro perfil con distinto ID.

Usuario/permiso/sucursal no autorizados: 401/403/404. Validación: 422;
CSRF: 419; rate limit: 429. Campos monetarios del modelo usan DECIMAL(19,4).

Invitar:
```json
{"email":"vendedor@example.test","role_id":3,"store_ids":[1]}
```

Gestionar acceso:
```json
{"role_id":3,"store_ids":[1],"status":"suspended"}
```

Suspender desactiva el usuario de esta instalación. No se puede retirar al último
propietario activo. Los cambios quedan auditados sin contraseñas/tokens. Cuentas
existentes se administran desde Equipo, no se recrean mediante invitación.

## Catálogo

| Método | Ruta después de `/api/v1/business` | Permiso |
|---|---|---|
| GET | `/catalog/options` | catalog.view |
| POST | `/catalog/{categories|brands|attributes|values}` | catalog.manage |
| PATCH | `/catalog/{kind}/{record}` | catalog.manage |
| GET | `/products` | catalog.view |
| GET | `/products/{id}?page=1` | catalog.view; archivados sólo gestores |
| POST | `/products` | catalog.manage |
| PATCH | `/products/{id}` | catalog.manage + versión actual |
| POST | `/products/{id}/variants` | catalog.manage + versión del producto |
| PATCH | `/products/{id}/variants/{variant}` | catalog.manage + versión de variante |

GET products: q (máx.120), category_id, brand_id, product_type, status
(active/archived/all), page y per_page (1..60, default24). Vendedor sólo status
active. Respuesta paginada `data`, `links`, `meta`. Búsqueda SQL parametrizada y
caracteres `%`/`_` tratados literalmente.

POST producto:
```json
{
  "name":"Camiseta",
  "product_type":"physical",
  "unit_code":"unit",
  "track_stock":true,
  "status":"active",
  "category_id":null,
  "brand_id":null,
  "variants":[{"sku":"CAM-001","barcode":null,"sale_price":"1250.00","cost_price":"700.00","attribute_value_ids":[]}]
}
```

Los importes deben ser strings decimales exactos. cost_price sólo se admite y
devuelve con catalog.costs. Los resources presentan importes con cuatro decimales.

GET detalle: `data.product` y `data.variants` (data/current_page/last_page/total).
Cada recurso tiene `version`. PATCH producto envía sus campos generales más
version, sin variants; editar variante envía sku/barcode/sale_price/cost_price
opcional/is_active/version, sin attribute_value_ids. POST variante sí envía esos
valores y version del producto. Respuesta incluye meta.product_version.

Opciones: categoría {name,parent_id?,is_active}, marca/atributo {name,is_active},
valor nuevo {attribute_id,value,is_active}. El attribute_id de un valor existente
es inmutable. Estado inactivo sustituye borrado. Conflictos de versión:409;
duplicados/referencias/formato:422. Detalles y reglas en `CATALOGO.md`.
En PATCH de variante, omitir barcode/cost_price conserva su valor; enviarlos
como null los limpia únicamente si el actor tiene el permiso correspondiente.

## Importación CSV

Requiere catalog.manage, sólo lotes del usuario creador y catalog.costs si contienen
costos. Después de `/api/v1/business/catalog/imports`:

| Método | Ruta | Función |
|---|---|---|
| GET | `/template` | Plantilla CSV UTF-8 |
| GET | `/` | Historial propio paginado, 10 por página |
| POST | `/preview` | multipart/form-data con file, hasta 2 MiB |
| GET | `/{id}?page=1` | Estado, resumen y 25 filas por página |
| GET | `/{id}/errors` | Reporte CSV seguro para hoja de cálculo |
| POST | `/{id}/revalidate` | Revalidar contenido almacenado |
| POST | `/{id}/confirm` | Lote completo atómico o errores sin productos parciales |
| POST | `/{id}/revert` | Archivar lote sin cambios/uso |

ID ULID. Preview:201 incluso con errores de datos; CSV estructuralmente inválido
o límites:422 sin lote. GET nunca consume. POST de acciones con cuerpo vacío y
CSRF: no recibe precios/filas sustitutos. Confirmar de nuevo devuelve IDs previos.

DTO data: id, filename, status, row_count, product_count, error_count, expires_at,
is_expired, can_confirm, can_revert, fechas, products (ID/nombre), rows y pagination.
Cada fila lleva línea física, grupo, nombre, SKU/precio y errors [{field,message}].
Costos sólo con permiso. Status:preview/committed/reverted; vencimiento aparte.

Lote ajeno:404; permiso:403; vencido:410; datos/catálogo cambiado:422; reversión
no aplicable o estado incompatible:409. Confirmación 422 incluye DTO actualizado
en data. Formato completo en `IMPORTACION_CSV.md`.

## Fotografías

Rutas después de `/api/v1/business/products/{product}`:

| Método | Ruta | Permiso / función |
|---|---|---|
| GET | `/images` | catalog.view; galería, versión; cuota/cupos pendientes sólo gestores |
| POST | `/images` | catalog.manage; multipart `file`, UUID `request_key`, `variant_id?`, `alt_text?`; 20/min |
| GET | `/uploads/{key}` | catalog.manage y autor de la carga; estado/error/retryable |
| PATCH | `/images` | catalog.manage; guardar galería completa con versión actual |
| DELETE | `/images/{image}` | catalog.manage; cuerpo `{version}`; retiro con retención temporal |

POST devuelve 201 o 200 si ya estaba verificado. Se reutiliza el UUID con el mismo
archivo/variante al reintentar. Mientras esté procesándose o requiera compensación:
409; formato/cupo/cuota:422; proveedor no disponible:503. No se publican rutas de
originales ni credenciales. GET no consume ni recupera cargas automáticamente.

PATCH de ejemplo:
```json
{"version":3,"primary_id":12,"images":[{"id":12,"variant_id":null,"alt_text":"Frente"},{"id":11,"variant_id":5,"alt_text":"Azul"}]}
```

El orden del array define el orden visual; IDs exactos de la galería, sin omitir,
duplicar ni añadir otros. `primary_id` debe pertenecer al producto. Versión obsoleta:
409, sin sobrescribir. Cambiar fotos incrementa la versión del producto y audita.
Los DTO de catálogo incluyen `images` con IDs, variante, texto, principal, orden y
URLs/dimensiones por rendición. Ver `IMAGENES.md` para límites, retención y operación.

## Inventario

Rutas después de `/api/v1/business/stores/{store}`, siempre con acceso positivo
a la sucursal. `inventory.view` para consulta; `inventory.manage` para mutaciones.

| Método | Ruta | Función |
|---|---|---|
| GET | `/warehouses/{warehouse}/stock` | Saldos paginados, q, filter=all/available/empty |
| GET | `/warehouses/{warehouse}/kardex` | Movimientos paginados; variant_id opcional |
| GET | `/inventory/destinations` | Almacenes activos de sucursales autorizadas |
| POST | `/inventory/movements` | Entrada/salida/conteo/transferencia idempotente |
| GET | `/inventory/requests/{key}` | Resultado propio de una operación completada |

Ejemplo de entrada:
```json
{"request_key":"<uuid>","warehouse_id":1,"variant_id":6,"operation":"receipt","quantity":"5","version":0,"note":"Recepción de compra"}
```
`operation`: receipt/issue/count/transfer. Transfer añade destination_warehouse_id
distinto y autorizado. Count recibe la cantidad física final, incluido 0. Cantidades
son strings DECIMAL(19,4); no se admiten floats, exponentes o negativos. Conflicto
de versión/disponible:409; formato/límite:422. 201 nuevo, 200 replay. El DTO conserva
snapshots on_hand_after/reserved_after/balance_version y los IDs originales.

## Clientes, ventas, apartados y documentos

Aplicación de crédito: GET/POST `/business/stores/{store}/sales/orders/{order}/credits`
y GET `/credits/requests/{key}`. Requiere credits.apply para escribir, UUID/versión/
review_hash y pago original elegible. Destino facturado del mismo cliente/sucursal/
moneda. Ver `APLICACION_CREDITO.md` para procedencia, saldos y reembolsos posteriores.

Conciliación económica: `/business/stores/{store}/sales/orders/{order}/returns/{receipt}`,
GET `/financial`, POST `/adjust`, POST `/refund` y GET `/financial/requests/{key}`.
Permisos returns.adjust/refunds.record; UUID, versión y revisión en servidor.
Los reembolsos son registro manual de salidas realizadas, no ejecución bancaria.
Ver `CONCILIACION_DEVOLUCIONES.md` para límites, crédito y saldo neto.

Recepción física de devoluciones: GET/POST
`/business/stores/{store}/sales/orders/{order}/returns` y recuperación propia GET
`/returns/requests/{key}`. POST requiere returns.receive, UUID/versión/review_hash,
motivo y líneas con cantidad/reposición. Conserva importes originales e informa
`economic_status=pending_adjustment`; `return_pending` bloquea nuevos cobros de
la factura hasta conciliar. Ver `RECEPCION_DEVOLUCIONES.md`.

Historial/reversión: `/business/stores/{store}/sales/orders/{order}/payments`,
GET de historial, POST `/{payment}/reverse` (UUID/version/reason; payments.reverse)
y GET `/requests/{key}` para recuperación propia. Ver `REVERSION_PAGOS.md`.
Los saldos efectivos usan pagos confirmados; las asignaciones originales de pagos
revertidos conservan valor histórico y no se suman como cobro vigente.

Extensión de cuentas semanales: `/business/stores/{store}/weekly-accounts`, con
listado/detalle/candidatos, creación, agregar/quitar pedidos, cierre y anulación
vacía. Operaciones con UUID/versión y cierre con review_hash. Ver
`CUENTAS_SEMANALES.md`. Las facturas se localizan por invoice_orders, sean directas
o agregadas; los abonos posteriores conservan el endpoint del pedido.

Extensión de pedidos públicos: ver `PEDIDOS_POR_ENLACE.md` para las rutas privadas
`/business/stores/{store}/order-request-links` (listar/generar/revocar) y públicas
`/order-requests/{token}` (datos, catálogo, cotización y confirmación única). La
confirmación conserva CSRF y usa los mismos servicios de stock/precios del negocio.

Mismo prefijo de sucursal. No se usa tenant ni selector de otras empresas.

| Método | Ruta | Permiso / función |
|---|---|---|
| GET | `/customers` | customers.view; q nombre/teléfono, page |
| POST | `/customers` | customers.manage; UUID y full_name, phone/email/address/notes opcionales |
| GET | `/customers/requests/{key}` | customers.view; recuperación propia de alta confirmada |
| GET | `/customers/{customer}/summary` | customers.view + sales.view; ficha/saldo/compras/canastas de la sucursal |
| GET | `/sales/catalog` | sales.view; warehouse_id requerido, q/category_id/sort=newest o name/page |
| POST | `/sales/quote` | sales.create; calcular, sin reservar ni persistir pedido |
| POST | `/sales/orders` | sales.create; confirmar sale/reservation |
| GET | `/sales/orders` | sales.view; status=open/all, customer_id, page |
| GET | `/sales/orders/{order}` | sales.view; estado actual con líneas/reservas/pagos/factura |
| POST | `/sales/orders/{order}/{action}` | Permiso por transición, indicado abajo |
| GET | `/sales/requests/{key}?action=create` | sales.view; resultado propio actual; action también invoice/payment/deliver/cancel/remove |
| GET | `/invoices` | invoices.view; listado paginado de sucursal, q número |
| POST | `/invoices/{invoice}/document` | invoices.view; generar/reintentar mismo PDF privado |
| GET | `/invoices/{invoice}/document` | invoices.view; descarga con hash y caché privada/no-store |

Cotizar:
```json
{"warehouse_id":1,"customer_id":1,"lines":[{"variant_id":6,"quantity":"1"}],"discount":"0","shipping":"5","notes":null,"estimated_delivery":null}
```

Devuelve currency, subtotal/discount_total/tax_total/shipping_total/grand_total,
líneas a precio actual y quote_hash. El backend toma precios y moneda de la instalación.
Para confirmar, añadir request_key UUID, quote_hash recibido, mode=**sale** o
**reservation**, initial_payment (string, 0 si no hay pago) y payment_method
(cash/bank_transfer/card, registro del pago ya recibido; no pasarela bancaria).
Cliente obligatorio al confirmar. Hasta 50 variantes distintas, cantidades positivas.

Nuevo pedido:201; mismo UUID/cuerpo:200. data contiene order/replay y, cuando hay
factura, document.status. Un fallo de PDF no deshace la emisión ni repite el cobro.
Ver detalle actual para recuperar versiones posteriores al resultado original.

Transiciones reciben UUID y **version** actual del pedido:
- invoice: sales.create; deliver_now boolean opcional. Emisión única.
- deliver: sales.create; todo lo pendiente o item_id + quantity para entrega parcial.
- payment: payments.record; amount string positivo y payment_method, sin sobreabono.
- remove: sales.create; item_id, no facturado/sin abonos ni entregas incompatibles.
- cancel: sales.cancel; liberación sin borrar historial, sólo cuando no haya dinero
  o entregas que requieran resolución explícita.

Errores: formato/datos422, estado/stock/precio/versión409, permiso403, recurso ajeno404.
Los GET de recuperación no mutan ni reenvían. Comprueban autor/sucursal originales;
no exponen la metadata interna de identidad ni hashes del cuerpo.

Detalle del comportamiento y límites en `VENTAS_APARTADOS.md`; ejemplos verificados
en `DEMO_KEYLASHOP.md`. El envío opcional posterior se documenta a continuación.

## Tablero Apartados y WhatsApp opcional — ampliación vigente

La implementación opcional posterior sustituye la limitación de WhatsApp del hito
anterior. Está desactivada en la demo. Ver `TABLERO_APARTADOS.md` para estados y
activación. Todas las rutas de negocio siguen protegidas por sesión/CSRF/permisos.

Después de `/api/v1/business/stores/{store}`:

| Método | Ruta | Permiso / acción |
|---|---|---|
| GET | `/baskets` | sales.view; q, filter=open/all/without_deposit/invoice_sent/paid/shipped, page, per_page=4/16/36 opcional (24 por defecto) |
| GET | `/baskets/{order}/view-links` | sales.view; hasta20, activos primero, sólo metadatos |
| POST | `/baskets/{order}/view-links` | sales.create; request_key UUID, hours=1/24/72/168, replay sin nuevo enlace |
| POST | `/baskets/{order}/view-links/{link}/revoke` | sales.create; revocación idempotente |
| POST | `/baskets` | sales.create; UUID/customer_id/warehouse_id, apartado abierto vacío |
| POST | `/baskets/{order}/preview` | sales.create; cotizar edición sin reservar |
| PATCH | `/baskets/{order}` | sales.create; guardar líneas y reservas diferenciales |
| POST | `/baskets/{order}/number` | sales.create; UUID/version/number, alias visible único |
| POST | `/baskets/{order}/dispatch` | sales.create; UUID/version, marcar enviado tras entrega |
| GET | `/baskets/requests/{key}?operation=save` | sales.view; recuperación propia create/save/number/dispatch |
| PATCH | `/customers/{customer}` | customers.manage; UUID/version/datos de contacto |
| POST | `/invoices/{invoice}/whatsapp` | invoices.view + sales.create; UUID, envío desde servidor |

Edición completa:
```json
{"request_key":"<uuid>","version":3,"lines":[{"id":5,"variant_id":6,"quantity":"2"},{"variant_id":21,"quantity":"1"}],"shipping":"5","discount":"0","quote_hash":"<hash-preview>"}
```
Preview no requiere UUID/quote_hash; devuelve éstos importes recalculados y hash.
Las líneas omitidas se retiran con historial; cambiar variante consiste en retirar
la original/agregar otra, no reutilizar un ID de línea ajeno. Se conservan precios
existentes y pagos; total inferior al depósito422, stock/versión/estado409. La
facturación y entrega se hacen por transiciones existentes, sin reescribir facturas.

Después de `/api/v1/business/integrations/whatsapp`: GET/PATCH con business.manage
para configuración; GET `/status` con sales.view, sin secretos. Secretos enviados
vacíos se conservan; se almacenan cifrados y no vuelven en respuestas. Guardar no envía.

POST WhatsApp devuelve delivery.id/status/error_code/timestamps; accepted **no**
equivale a sent. Un envío activo devuelve su registro existente aunque cambie UUID.
Unknown/sending no se repiten. Fallo confirmado permite nuevo intento explícito
contra la misma factura. No se incluyen tokens/respuestas privadas del proveedor.

Webhook público `/webhooks/whatsapp`: GET handshake y POST con
`X-Hub-Signature-256` HMAC-SHA256 del cuerpo original. Única excepción CSRF específica;
sin firma válida403. Sólo actualiza estados de entrega correlacionados; nunca pagos,
stock ni despacho físico. `customer_live`, `whatsapp_delivery`, `order_kind`,
`basket_number`, `closed_at` y `shipped_at` amplían el detalle. La tarjeta devuelve
items_count y quantity_count, importes/depósito y flags independientes.

## Reconciliación de inventario - F5.07

`GET /api/v1/business/stores/{store}/warehouses/{warehouse}/reconciliation`
requiere sesión, sucursal autorizada e `inventory.view`; límite 20/minuto.
Acepta `only_issues=0|1` (por defecto 1) y `page` (desde 1). Devuelve `data`
con 25 variantes por página, `meta` (current_page/last_page/total), `generated_at`
y `summary` global del almacén (checked/consistent/with_differences/differences/movements).
Las cantidades son cadenas a cuatro decimales. Cada variante incluye saldos
actuales, saldos del kardex, reserva activa pendiente y diferencias con código/mensaje.
Consulta sin escrituras ni reparaciones. Detalle en `RECONCILIACION_INVENTARIO.md`.

## Plazo de reservas - F5.04

GET/PATCH `/api/v1/business/reservations/settings`, sesión activa y
`business.manage`. GET devuelve `data.hours` (null desactivado). PATCH exige
`hours` y `previous_hours`, ambos null o entero 1..8760. Devuelve 409 si el valor
anterior ya cambió. No modifica las fechas existentes ni aplica vencimientos.

El pedido incluye `reservation_expires_at` (ISO UTC o null) y
`reservation_expired` (booleano). Plazo del inventario, separado de antigüedad de deuda.
Ver `EXPIRACION_RESERVAS.md` para condiciones, comando y programación pendiente.

## Gestión interna de clientes

Tras `/api/v1/business/stores/{store}`: GET `/customers/{customer}` consulta ficha
activa o archivada con customers.view; PATCH `/customers/{customer}/status` exige
customers.manage y `{request_key,version,status:active|archived}`. Cambios auditados,
versionados e idempotentes; no borran deuda/documentos ni cancelan pedidos.
GET `/customers` admite status=active (predeterminado), archived o all.
Recuperación propia: GET `/customers/requests/{uuid}?action=status`, mismo usuario
 y sucursal. Ver `CLIENTES.md` para alcance, selección y reglas de cobro.

## Comprobantes financieros privados

GET/POST `/api/v1/business/stores/{store}/sales/orders/{order}/documents/{kind}/{record}`,
kind=adjustment/credit, con sales.view + invoices.view y acceso a sucursal.
POST prepara/reutiliza el mismo PDF después del commit; GET descarga sólo ready.
Sesión/CSRF, límite propio20/minuto, no-store y hash/tamaño/clave fija verificados.
Detalle y recuperación de histórico en `COMPROBANTES_FINANCIEROS.md`.

## Dashboard comercial e historial

GET `/api/v1/business/stores/{store}/sales/control`, `/sales/control/export` y
`/sales/orders/{order}/activity`, con sales.view y StoreContext. Consulta sin
escrituras, no-store, filtros from/to/q/kind/state/page y monedas separadas.
Resumen de pedidos por creación/saldo actual, cobros por fecha de pago/reembolso
y actividad original. Contrato completo y semántica de fechas en `CONTROL_VENTAS.md`.

## Reversión de aplicaciones de crédito

GET/POST `/api/v1/business/stores/{store}/sales/orders/{order}/credits/{application}/reversal`:
revisión con sales.view; POST requiere además credits.reverse y request_key/version/
review_hash/reason. GET `/reversal/requests/{uuid}` recupera sólo al actor original.
15 POST/minuto, sesión/CSRF, StoreContext/no-store. Compensación única, procedencia
restaurada y deuda reabierta sin modificar recibos/PDF/stock; bloqueo de crédito
posteriormente liberado y conciliaciones inciertas. Ver REVERSION_APLICACIONES_CREDITO.

## Compensación de recepciones físicas pendientes

GET/POST `/api/v1/business/stores/{store}/sales/orders/{order}/returns/{receipt}/reversal`;
POST exige returns.reverse + sales.view y request_key/version/review_hash/reason.
GET `/reversal/requests/{uuid}` recupera actor/contexto original. Stock inverso
trazado, cupo restaurado, sin cambios de dinero/PDF; bloquea ajuste/reembolso o
existencias no disponibles.15 POST/min, sesión/CSRF/no-store. Ver REVERSION_RECEPCIONES.

## Corrección de reembolsos erróneos

GET/POST `/api/v1/business/stores/{store}/sales/orders/{order}/returns/{receipt}/refunds/{refund}/reversal`.
POST exige sales.view+refunds.reverse, request_key/version/review_hash/reason y
confirmed_no_money=true. GET `/reversal/requests/{uuid}` recupera al actor/contexto.
Sólo registro manual confirmado sin entrega real de dinero: conserva ledger y
reembolso históricos, restituye crédito sin ingreso ficticio.15 POST/min, sesión/
CSRF/no-store. Ver `CORRECCION_REEMBOLSOS.md` para vigencia, bloqueos y recuperación.
