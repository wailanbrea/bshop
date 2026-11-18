# Cuentas semanales y factura agregada

## Operación

Menú **Cuentas semanales** (`/app/cuentas-semanales`):

1. Crear una cuenta para un cliente activo y elegir el día inicial del período.
   Son siete días naturales incluidos, en la zona horaria del negocio capturada
   al crear la cuenta. El intervalo UTC respeta medianoche local y cambios DST.
2. Agregar apartados disponibles del mismo cliente, sucursal, moneda y período.
   Máximo50 pedidos por cuenta; no pueden estar en otra cuenta ni facturados.
3. Revisar total, abonado y pendiente. Quitar de cuenta sólo retira la agrupación,
   conservando el pedido y sus reservas/pagos. Las modificaciones usan versión.
4. **Revisar cierre semanal → Cerrar cuenta y facturar**: autorización explícita
   con un hash de la revisión. Si otro usuario editó un pedido, registró un abono
   o facturó individualmente, se rechaza409 y se debe actualizar/revisar de nuevo.
5. Se genera una sola factura agregada con sus artículos, impuestos, descuentos,
   envío, abonos asignados y saldo al cierre. PDF privado descargable después del
   commit. Un fallo de PDF permite reintentar ese documento sin volver a facturar.

En Configuración → Corte semanal, el propietario puede elegir el día final del
período (lunes a domingo). Al crear una cuenta se sugiere el inicio del período
actual de siete días en la zona horaria del negocio; la fecha sigue siendo editable.
Si no se puede consultar la configuración se solicita elegirla manualmente. El
cambio no modifica períodos ya guardados. El cierre permanece manual; su ejecución
automática de facturación no se realiza. Al llegar el día final local de una cuenta
abierta, la pantalla indica cuántas cuentas requieren revisión y ofrece el filtro
**Por revisar**. El aviso se consulta al abrir o actualizar la lista; no es una
notificación externa ni un cierre en segundo plano. Revisar el aviso no modifica
cuentas ni genera facturas.

## Agrupación, exclusiones y conservación

- Se agrupan apartados reserved o fulfilled sin factura y dentro del intervalo
  `[inicio local, día posterior al final local)`. No se convierten monedas.
- Las semanas activas de un mismo cliente/sucursal no pueden solaparse.
- Si un miembro se factura individualmente mientras la cuenta está abierta, queda
  visible como excluido; sus artículos y pagos no se vuelven a facturar al cerrar.
  También se excluyen pedidos cancelados. Una cuenta sin miembros facturables no cierra.
- Anular una cuenta vacía conserva su fila y auditoría. Crear de nuevo el mismo
  período exacto reactiva esa cuenta vacía anulada, manteniendo ID, versiones e
  historial de anulación/reapertura; nunca reabre cuentas cerradas con operaciones.
- Cerrar no entrega mercancía ni mueve stock. Los pedidos facturados conservan
  reservas y permiten los flujos existentes de entrega y cobro; se bloquean nuevas
  ediciones/cancelaciones incompatibles y la emisión de otra factura individual.
- La cuenta cerrada congela contacto, zona/período y abonado al cierre. El PDF
  conserva bytes/hash originales aunque cambien el contacto o los pagos posteriores.

## Facturas, abonos y CxC

El saldo liberado puede aplicarse explícitamente a otro miembro facturado mediante
`APLICACION_CREDITO.md`. Las asignaciones se atribuyen al pedido destino, incluso
cuando el pago original y el destino pertenecen a esta misma factura. No se
duplica el abono del origen y se conserva el saldo neto agregado correcto.

Las devoluciones conciliadas de `CONCILIACION_DEVOLUCIONES.md` conservan el total
fiscal original y agregan crédito/total neto, saldo aplicado, disponibilidad y
reembolsos registrados. No se transfiere saldo entre pedidos de la cuenta de forma
implícita. El PDF y el abonado al cierre siguen congelados, mientras el saldo vivo
de la cuenta y CxC usan importes netos y asignaciones efectivas.

`invoice_orders` es la fuente común para localizar la factura de un pedido,
individual o semanal. Se mantiene su PK de pedido y sus FK de contexto; la factura
semanal tiene `weekly_account_id`, sin `order_id` directo, y es única por cuenta.

El cierre asigna los pagos confirmados previos una sola vez, sin registrar otro
cobro. Los cobros posteriores se siguen registrando sobre el pedido correspondiente
y actualizan la factura agregada con la suma exacta de sus asignaciones. No se
marca pagada sólo porque un pedido de la cuenta esté pagado. Se conserva el ledger
de crédito existente y la restricción de sobreabonos por pedido.

Apartados, CxC, resumen del cliente, listado/PDF de facturas, vencimiento de reservas
y servicio WhatsApp opcional reconocen la fuente agregada. CxC conserva las filas
por pedido sin duplicar importes; el contador de documentos cuenta cada factura
agregada una sola vez. WhatsApp sigue desactivado salvo configuración explícita.

## Transacciones y recuperación

- UUID por acción: create/attach/detach/close/cancel, con identidad usuario/sucursal
  y cuenta, versiones, auditoría y validación de payload repetido.
- Un mutex persistente por cliente/sucursal serializa creación de ciclos. Es una
  fila sólo de bloqueo; no lleva FK para no adquirir locks de sucursal/cliente
  antes del escaneo de períodos. Las identidades se validan en la operación.
- Cierre: cuenta → productos/variantes ordenados → sucursal → pedidos/artículos →
  fuentes fiscales/pagos → secuencia. No modifica inventario. Si cambian variantes
  mientras espera, devuelve409. Lee filas actuales con locks bajo REPEATABLE READ,
  no una foto antigua al copiar artículos o consultar pagos.
- Totales se suman con BCMath/strings y se validan contra DECIMAL(19,4) antes de
  emitir/consumir la secuencia. No se recalculan precios históricos del catálogo.
- Un cierre repetido con el mismo UUID devuelve la factura original; otro UUID
  contra una cuenta cerrada se rechaza. La UI guarda la operación pendiente por
  pestaña/usuario/sucursal; tras recargar consulta resultado, sin reenvío automático.

## API privada

Prefijo `/api/v1/business/stores/{store}/weekly-accounts`:

| Método | Ruta | Acción |
|---|---|---|
| GET | `/` | status=open/closed/due/all, page; listado paginado y `meta.due_count` |
| GET | `/{account}` | detalle, miembros, exclusiones, totales y review_hash |
| GET | `/{account}/candidates` | apartados elegibles paginados |
| POST | `/` | request_key, customer_id, period_start YYYY-MM-DD |
| POST | `/{account}/orders/attach` | request_key, version y order_id |
| POST | `/{account}/orders/detach` | request_key, version y order_id |
| POST | `/{account}/close` | request_key, version y review_hash |
| POST | `/{account}/cancel` | request_key, version; sólo cuenta abierta vacía |
| GET | `/requests/{key}?operation=close` | recuperación propia de cualquiera de las cinco acciones |

Sesión, CSRF, StoreContext y sales.view + customers.view en todas las rutas;
sales.create para escribir e invoices.view adicional para cerrar. El permiso y el
acceso se revalidan en transacción. PDF usa el endpoint privado de facturas existente.
La configuración usa `GET /api/v1/business/weekly/settings` (sesión activa) y
`PATCH` a la misma ruta con `close_weekday` y `previous_weekday` (ambos ISO 1–7,
permiso `business.manage`). Una modificación concurrente devuelve 409.

## Evidencia y pendientes

- Concurrencia MySQL8.4 verificada posteriormente:32 carreras multiproceso en dos
  rondas, ambos órdenes mixtos, replay, pagos por miembro y recierre tras revisión.
  Fuentes/asignaciones, ledger, secuencia, stock y snapshots comprobados. Sin defecto
  productivo nuevo detectado. Ver `CONCURRENCIA_SEMANAL.md` para ejecutar y consultar
  artefactos/límites. Suite PHP actual258 pruebas/3.490 aserciones.

Evidencia del hito funcional original:

- Suite PHP completa:161 pruebas /1.746 aserciones, SQLite :memory: protegido.
- Regresión de navegador:388/388 en seis perfiles; cuatro escenarios semanales
  por perfil: agrupación/cierre con giro, respuesta perdida/recarga, conflicto de
  revisión y creación/anulación de cuenta vacía.
- Pruebas financieras: factura única, pagos antes/después, exclusión de factura
  individual, CxC sin duplicación, PDF/contacto históricos, versiones/moneda/período,
  permisos/sucursal/recuperación, medianoche/DST, reapertura y límite DECIMAL.
- Migración002200: mutex y cinco columnas aditivas. Aplicada sólo a SQLite local
  tras `.runtime/bshop-backup-20261114-053136.sqlite`, copia íntegra verificada.
- Pendientes: política global de corte/automatización, carga sostenida, aceptación
  física y configuración de producción.
