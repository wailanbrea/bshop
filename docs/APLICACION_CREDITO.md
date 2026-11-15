# Aplicar saldo a favor entre pedidos

## Operación

En **Ventas** o **Apartados**, abrir un pedido facturado → **Saldo a favor del
cliente**. Se muestra su deuda neta, los pagos originales con crédito elegible y
el historial de aplicaciones recibidas. Con `credits.apply`, seleccionar origen,
indicar importe/motivo y confirmar **Aplicar crédito al pedido**.

El crédito elegible procede de liberaciones aprobadas de devoluciones conciliadas.
El destino debe pertenecer al mismo cliente, sucursal y moneda, tener factura
vigente y deuda neta, sin recepción pendiente de conciliar. Los depósitos reservados
a un pedido aún sin factura permanecen ligados a ese pedido y no se ofrecen como
dinero libre. No hay conversión ni traslado entre sucursales.

Se admite aplicación parcial, con importe limitado por crédito realmente disponible
y deuda neta del destino. El valor sugerido es el menor de esos dos límites. El
importe acepta coma/punto decimal; los cálculos y límites del servidor son DECIMAL/
BCMath. Permiso por defecto para owner/manager; consulta para seller sin aplicación.

## Registro y conservación

- No crea un pago, ingreso bancario o movimiento de inventario. Conserva pago
  original, su asociación al pedido de origen, facturas y PDFs históricos.
- Crea una asignación al documento destino y un débito de disponibilidad en el
  ledger del mismo pago. credit_applications documenta destino, origen de efectivo,
  importe, motivo, autor/fecha; credit_application_sources identifica cada liberación
  de devolución consumida. Se consumen liberaciones antiguas primero.
- Los saldos distinguen recibido originalmente, crédito recibido de otros pedidos,
  crédito aplicado a otros, abono efectivo, disponibilidad, reembolsos y pendiente.
- Una aplicación no vuelve a contarse en el pedido original cuando comparte la
  misma factura semanal con el destino; cada asignación tiene atribución de pedido.
- Customer/CxC/cuenta semanal usan saldos actuales sin sumar el mismo dinero dos veces.

## Reembolso y reutilización posterior

El importe ya aplicado deja de estar disponible para reembolsar en su devolución
de origen. Si el pedido destino devuelve mercancía y se aprueba crédito, se libera
su propia asignación con trazabilidad al mismo pago original. Ese nuevo saldo puede
aplicarse o reembolsarse desde la devolución del destino, sin inventar otro recibo.

Ejemplo: A recibió30 y generó crédito20; aplicar5 a B deja15 disponibles en A.
Reembolsar esos15 consume el saldo restante de A. Si B devuelve sus artículos y
libera los5 aplicados, B dispone de5 para su propia devolución. No se pueden volver
a reembolsar desde A. Efectivo original30 = aplicado final10 + reembolsado20.

CreditPools distribuye reembolsos confirmados y consumos por liberación antes de
ofrecer importes. Se comprueban disponibilidad global del pago y procedencia; un
reembolso pendiente de resultado excluye ese fondo de nuevas aplicaciones.

## Transacción, permisos y recuperación

UUID/versión/hash de revisión. Se revalida sales.view + credits.apply y acceso a
sucursal dentro de la transacción. Producto/variante→sucursal→pedidos ordenados→
facturas ordenadas→pago/asignaciones/liberaciones/ledger. Un cambio del conjunto
de orígenes mientras espera obliga a actualizar la revisión. No permite estados
cancelados, documentos ajustados incompatibles, devolución pendiente del destino
o comisiones que requieren conciliación avanzada.

Cada aplicación debita crédito una sola vez; nuevo UUID no permite exceder el saldo.
Se incrementan versiones del destino y de los pedidos cuyas liberaciones se usan.
Journal por pestaña/actor/sucursal/pedido: al volver al pedido tras recargar consulta
la operación propia por GET. En respuesta incierta conserva UUID/importe/motivo;
en409 conserva los campos y permite actualizar la revisión explícitamente.

## API privada

Prefijo `/api/v1/business/stores/{store}/sales/orders/{order}/credits`:

| Método | Ruta | Datos/permiso |
|---|---|---|
| GET | `/` | sales.view; deuda, fuentes elegibles, review_hash e historial20 |
| POST | `/` | sales.view + credits.apply; request_key, version, review_hash, payment_id, amount, reason |
| GET | `/requests/{key}` | sales.view; recuperación del mismo actor/sucursal/destino |

Sesión/CSRF/StoreContext y15 aplicaciones/minuto/usuario; respuestas no-store.
El POST devuelve pedido actualizado, credit_application_id y replay. Motivo5..255,
importe positivo;422 por exceso/validación,409 por revisión/estado/conciliación y
404 por contextos ajenos. Permisos granulares independientes del registro de efectivo.

## Evidencia y pendientes

- PHP:195 pruebas /2.379 aserciones, SQLite :memory: con guardia antes de migrar.
- Ocho escenarios nuevos: aplicación/replay/invariantes, límites de reembolso y
  devolución posterior del destino, atribución y cadena dentro de la misma factura
  semanal, otro cliente/moneda/sin factura, recepción pendiente, dos sucursales aun
  con acceso a ambas, revisión/permisos y CSRF419.
- Navegadores:502/502 en seis perfiles;30 casos nuevos de giro/motivo, respuesta
  perdida sin duplicación, revisión obsoleta, consulta sin permiso y venta directa.
- Migración002600 aplicada sólo en SQLite local tras copia íntegra verificada
  `.runtime/bshop-backup-20261114-220056.sqlite`. No se aplicaron créditos comerciales
  de demo; inventario/dinero/PDF originales siguen conciliados.
- Comprobantes descargables disponibles: ver [guía](COMPROBANTES_FINANCIEROS.md).
- Reversión compensatoria completa disponible: [operación y límites](REVERSION_APLICACIONES_CREDITO.md).
- Pendientes: otras anulaciones compensatorias,
  concurrencia MySQL de este flujo, integración avanzada con comisiones y tablet física.
