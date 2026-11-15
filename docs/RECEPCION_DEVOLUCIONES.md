# Recepción física de mercancía devuelta

## Operación y alcance

En **Ventas** o **Apartados**, abrir un pedido facturado → **Devoluciones de
mercancía**. Se muestran cantidades facturadas, entregadas, ya recibidas y aún
disponibles para devolución, con referencia al almacén original.

Con `returns.receive`, indicar cantidades, motivo y destino por artículo:
- **Reponer en existencias**: ingreso positivo al almacén original con kardex.
- **No ingresar a existencias**: recepción registrada sin aumentar stock; las
  unidades consumen el cupo de devolución para evitar recibirlas otra vez.

Confirmar **Registrar recepción física**. El historial conserva autor, motivo,
fecha, cantidades y destino. No permite editar/borrar recepciones ni eliminar
movimientos; un flujo compensatorio quedará para su fase.

**La recepción física nace pendiente de ajuste económico.** No calcula crédito,
cambia el total fiscal ni entrega dinero. Se registra received con importes
provisionales0. La conciliación ya está implementada en `CONCILIACION_DEVOLUCIONES.md`:
una aprobación separada registra crédito/neto y el reembolso manual correspondiente.
El sistema conserva factura, PDF, cobros y asignaciones originales.

## Protección de cobros

Una recepción `received` bloquea nuevos cobros de su factura y la vía alternativa
de facturación por pedido. Para una factura semanal, comprende todos sus pedidos,
sin afectar otras facturas del cliente. La reversión de pagos ya rechazaba facturas
con devoluciones; la interfaz oculta esa acción mientras está pendiente el ajuste.

Ventas/Apartados, CxC/CSV, ficha de cliente, cuenta semanal y consultas públicas
muestran que los importes originales están por ajustar. La API de facturas también
informa `return_pending`. No se presenta un reembolso inexistente como completado.
El desbloqueo ocurre mediante el ajuste revisado de `CONCILIACION_DEVOLUCIONES.md`,
si no quedan otras recepciones pendientes de esa factura. No se omite esa conciliación
cambiando un estado desde la interfaz.

## Entrega demostrable y límites

- Requiere factura issued/partially_paid/paid y almacén histórico de la sucursal.
- Venta directa: salida `sale` del kardex con identificador canónico del artículo.
- Apartado: movimientos `fulfill` por reserva y coincidencia con fulfilled_quantity.
- El estado fulfilled, por sí solo, no demuestra salida física de inventario.
- Sólo artículos con seguimiento de stock; servicios/no-stock no generan mercancía.
- Recibido acumulado ≤ entregado ≤ facturado; se cuentan todas las devoluciones
  históricas para evitar duplicación. Entregas parciales admiten devoluciones
  parciales exactas, sin alterar reservas ni volver a habilitar su entrega.
- Sólo almacén original activo; no se elige otro mediante datos del cliente.
  Productos archivados mantienen su trazabilidad; cambiar el seguimiento de stock
  ya está bloqueado cuando hay historia.

## Integridad y recuperación

UUID, versión y hash de revisión por usuario/sucursal/pedido. Normalización y límites
en servidor, con locks producto→variante→sucursal→almacén→saldos→pedido→factura/
artículos/entregas/devoluciones. Lecturas actuales bajo REPEATABLE READ y rollback
íntegro del lote ante conflicto o capacidad DECIMAL.

Se reutilizan sales_returns/sales_return_items y se agrega sales_return_receipts
para vincular recepción, factura, pedido, almacén y actor mediante FK compuestas.
StockLedger registra `customer_return` con return_item_id, reserva delta0, saldo/
versión posteriores y evento único. No hay escrituras de saldo fuera del ledger.

Permiso para owner/manager, consulta para seller sin recepción por defecto; sesión,
CSRF y StoreContext,15 recepciones/minuto/usuario. Journal por pestaña/actor/sucursal/
pedido: al volver al pedido tras recargar se consulta el resultado propio sin POST
automático. Motivo, cantidades y destino se conservan ante conflicto/respuesta perdida.

## API privada

Prefijo `/api/v1/business/stores/{store}/sales/orders/{order}/returns`:

| Método | Ruta | Comportamiento |
|---|---|---|
| GET | `/` | sales.view; versión/review_hash, líneas y últimas20 recepciones |
| POST | `/` | sales.view + returns.receive; recibe lote,201 o200 replay |
| GET | `/requests/{uuid}` | sales.view; recuperación del mismo actor/sucursal/pedido |

POST: request_key, version, review_hash, reason5..255 y
`lines[{invoice_item_id,quantity,restock}]` (máximo50, IDs distintos, cantidades
positivas DECIMAL y restock booleano). No admite importe de devolución del navegador.
Respuesta: economic_status=pending_adjustment, sales_return_id y pedido actualizado.
409 pide revisar estados/cantidades,422 rechaza el lote y404 protege contextos ajenos.
Respuestas privadas no-store.

## Evidencia y pendientes

- PHP:179 pruebas /2.031 aserciones, SQLite :memory: con guardia antes de migrar.
- Diez escenarios API: reposición/replay/historia fiscal, no reposición/cupo, lote
  ajeno, entrega parcial, factura semanal/bloqueo, alcance/permisos, rollback por
  capacidad DECIMAL, servicios, estado sin prueba de entrega y CSRF419.
- Browser:448/448 en seis perfiles;30 casos nuevos de cantidades/destino al girar,
  respuesta perdida, revisión obsoleta, consulta sin recepción y venta directa.
- Migración002400 aplicada sólo en SQLite local con respaldo íntegro verificado
  `.runtime/bshop-backup-20261114-171037.sqlite`. No se recibieron devoluciones en
  la demo; inventario/dinero/PDF originales conciliados.
- Conciliación económica y registro manual de reembolsos incorporados posteriormente;
  ver la guía específica y la regresión actual187/2.180 PHP y472 browser.
- Aplicación de crédito entre pedidos incorporada en `APLICACION_CREDITO.md`.
- Pendientes: correcciones compensatorias de recepción, concurrencia MySQL
  específica y aceptación en tablet física.
