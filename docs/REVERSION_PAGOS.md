# Reversión contable de pagos registrados por error

## Operación

En **Ventas** o **Apartados**, abrir un pedido guardado y **Pagos y reversiones**.
El historial conserva importe, método, fecha y estado de cada cobro. Con el permiso
`payments.reverse`, seleccionar **Revertir**, indicar el motivo y confirmar
**Revertir pago por error**. La reversión afecta el pago completo, con importe
obtenido del servidor; el navegador no elige una cantidad a descontar.

Esta operación corrige el registro de un cobro erróneo. No procesa una transferencia
ni un reembolso de tarjeta. Las devoluciones y sus reembolsos manuales se gestionan
en `CONCILIACION_DEVOLUCIONES.md`, con crédito y asignaciones compensadas explícitamente.

El permiso se agrega a propietario/administrador; vendedor conserva consulta y
registro de cobros, sin reversión por defecto. Se requiere acceso explícito a la
sucursal, sales.view y motivo de5 a500 caracteres, además de la versión vigente
del pedido. Un conflicto conserva el motivo y permite actualizar el historial.

## Historial, crédito y facturas

- El pago conserva importe, moneda, método, fecha y notas; sólo pasa a `reversed`.
  `payment_reversals` registra importe completo, motivo, autor/nombre histórico,
  fecha y las partes asignada/no asignada. Hay una sola reversión por pago.
- Las asignaciones y movimientos originales permanecen como historia. Para saldo
  efectivo de factura sólo cuentan asignaciones de pagos `confirmed`.
- El ledger de crédito representa disponibilidad no asignada. Se compensa únicamente
  el remanente disponible del pago; una parte asignada no genera crédito nuevo
  porque también se está anulando la entrada de dinero registrada por error.

| Estado previo del pago | Movimiento nuevo de crédito | Estado efectivo posterior |
|---|---|---|
| Recibo5, asignado0, crédito5 | reversal−5 | ingreso efectivo0, crédito0 |
| Recibo5, asignado5, crédito0 | ninguno de importe0 | asignación efectiva0, crédito0 |
| Recibo5, asignado3, crédito2 | reversal−2 | asignación efectiva0, crédito0 |

Las asignaciones históricas siguen visibles en base de datos. Una suma de importes
históricos no sustituye al cálculo efectivo filtrado por estado del pago. El abono
revertido tampoco se asigna de nuevo al facturar posteriormente el pedido.

Se actualizan abonado/pendiente del pedido, CxC, ficha de cliente, tarjetas y estado
de la factura individual o agregada semanal. Un pago de otro pedido de la cuenta
continúa aportando a la misma factura. No se modifica el total fiscal, sus artículos,
la entrega ni el inventario. El PDF histórico y el abonado al cierre semanal quedan
congelados como estaban al emitirse; el saldo vivo se consulta en el sistema.

## Transacción y límites de esta entrega

- UUID/payload por usuario/sucursal/pedido/pago, versión de pedido, auditoría y
  bloqueo ordenado de productos/variantes/sucursal/pedido/factura/pago/ledger.
- Se revalida el permiso dentro de la transacción; nuevos artículos mientras espera
  o versión cambiada producen409. Repetir el mismo UUID no repite los efectos.
- Verifica contexto, crédito no negativo y `asignado + disponible = pago`, usando
  strings/BCMath. Se preservan filas originales y las restricciones del ledger.
- Bloquea pagos con reembolsos pendientes/confirmados, devoluciones asociadas,
  comisiones, factura anulada/reembolsada o asignaciones ajenas al pedido. Esos casos
  requieren su flujo de conciliación; no se resuelven implícitamente aquí.
- La reversión no mueve stock. Si quedan sólo pagos revertidos/fallidos, una reserva
  sin factura/entrega puede vencer después por la política configurada; pagos
  pendientes o confirmados mantienen su protección frente a ese vencimiento.

Journal por pestaña/usuario/sucursal/pedido: al volver al pedido tras recargar se
consulta la operación propia y se actualiza el resultado sin POST automático.
Ante respuesta incierta se mantiene UUID/versión/motivo para reintento explícito.
Los borradores de artículos se preservan si se recupera una reversión anterior.

## API privada

Después de `/api/v1/business/stores/{store}/sales/orders/{order}/payments`:

| Método | Ruta | Permiso/acción |
|---|---|---|
| GET | `/` | sales.view; historial de pagos y reversión asociada |
| POST | `/{payment}/reverse` | sales.view + payments.reverse; request_key, version, reason |
| GET | `/requests/{key}` | sales.view; recuperación del mismo actor/sucursal/pedido |

Sesión/CSRF y StoreContext; respuestas privadas no-store. Límite15 reversiones por
minuto/usuario. 409 para versión/estado/conciliación,422 para validación y404 para
contexto inexistente/ajeno. El estado inválido no altera pagos, asignaciones ni crédito.

## Evidencia y entrega

- PHP completo:169 pruebas /1.860 aserciones, SQLite :memory: protegido.
- Incluye pago sin factura y facturación posterior, factura pagada/PDF estable,
  cuenta semanal, crédito parcial, permisos/alcance, versión/motivo, recuperación,
  reembolso pendiente, vencimiento y prueba CSRF419 sin el bypass de pruebas.
- Navegadores:418/418 en seis perfiles;30 casos nuevos de reversión con giro,
  respuesta perdida/recarga, conflicto, consulta sin permiso de reversión y venta
  directa guardada. El resto de flujos financieros pasó la regresión completa.
- Migración002300 aplicada en SQLite local tras respaldo íntegro verificado
  `.runtime/bshop-backup-20261114-062820.sqlite`; tabla nueva y permiso administrativo.
  Ningún pago de la demo fue revertido por el agente. Totales e inventario conciliados.
- Pendientes: MySQL aislado/concurrencia específica, tablet física y los módulos
  de devoluciones/reembolsos/crédito entre operaciones/comisiones.
