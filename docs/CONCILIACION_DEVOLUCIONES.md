# Conciliación económica de devoluciones y reembolsos manuales

## Operación

**Ventas/Apartados → pedido facturado → Devoluciones de mercancía → Ver conciliación
y reembolso** en la recepción correspondiente.

1. Revisar la propuesta calculada sobre los importes originales. El precio actual
   del catálogo no interviene. Indicar envío a acreditar (inicialmente0), actualizar
   propuesta e introducir motivo.
2. Con `returns.adjust`, **Confirmar ajuste económico**. Quedan registrados el
   crédito, total neto y la parte que reduce deuda o libera saldo a favor.
3. Conservar el saldo a favor, o seleccionar un pago con crédito liberado y preparar
   el registro de reembolso. Con `refunds.record`, indicar importe, método, motivo
   y referencia obligatoria para transferencia/tarjeta.
4. **Registrar reembolso realizado** deja constancia de dinero ya devuelto fuera
   del sistema. No ejecuta transferencias ni llamadas a un procesador de tarjetas.

Ambos permisos se agregan a owner/manager; seller conserva consulta sin aprobación
ni reembolso por defecto. La aprobación y el registro de salida son acciones distintas.

## Cálculo y límites

- Valor de mercancía = subtotal original − descuento original + impuesto original;
  el envío se trata por separado y sólo se acredita explícitamente, hasta el envío
  original aún no acreditado de ese pedido.
- El valor neto con impuestos se distribuye entre artículos mediante proporciones
  acumuladas de sus importes originales. Cada devolución parcial usa diferencia de
  redondeos acumulados por cantidad; tres unidades de1,00 se acreditan0,33/0,34/0,33.
  No se pierden ni crean céntimos al devolver todos los artículos.
- Primero reduce deuda. Sólo el exceso de abonos aplicados sobre el nuevo total neto
  libera asignaciones para crédito/reembolso; no se crea dinero porque un pedido
  impago devuelva mercancía. Las liberaciones se toman de pagos recientes primero.
- Un reembolso se limita simultáneamente al crédito liberado de esa recepción/pago
  pendiente de devolver y al crédito realmente disponible del pago original.
- Referencias externas únicas por sucursal/método; UUID repetido no repite efectos.
  Pagos con resultado pendiente o documentos/comisiones que requieren conciliación
  específica bloquean la operación correspondiente.
- Se admite crédito0 para mercancía gratuita, sin asientos ficticios ni bloqueo
  permanente. La aplicación explícita a otros pedidos facturados ya está integrada
  en `APLICACION_CREDITO.md`, siempre en el mismo cliente/sucursal/moneda.

Todos los cálculos usan strings/BCMath, cantidades DECIMAL y redondeo monetario
half-up a céntimos. El servidor no acepta precios ni importes de crédito calculados
por el navegador; la revisión incluye versión y hash sobre cantidades, dinero y pagos.

## Historial y saldos

Se conserva la factura original, sus líneas, pagos y asignaciones. No se vuelve a
mover stock. Se agregan documentos internos en return_adjustments/items, liberaciones
en payment_allocation_releases y comprobantes de registro en refund_confirmations.
El campo económico provisional refund_amount del artículo recibido pasa de0 al
crédito aprobado, sin cambiar cantidad/destino físicos; el ajuste sellado conserva
su desglose, motivo, autor y fecha. No hay edición/borrado de ajustes confirmados.

La recepción pasa de received a adjusted. Se levanta la retención de cobro sólo
si no quedan otras recepciones pendientes de esa factura. En una factura semanal
se recalcula su saldo completo; no se transfiere crédito entre miembros implícitamente.

Campos de pedido: grand_total/original_total históricos, return_credit_total,
net_total, gross_received, paid_total aplicado, credit_available, refunded_total
y pending_total neto. En pedidos aún sin factura los depósitos conservan su
presentación ligada al pedido, sin ofrecerlos como crédito libre para otra operación.

Ejemplo: original100, abonado100 y crédito20 → neto80, aplicado80, saldo a favor20.
Al registrar reembolso20 quedan aplicado80, saldo a favor0 y reembolsado20. La
factura y el recibo original siguen en100; el PDF histórico conserva sus bytes.

El crédito disponible por pago es la suma del ledger original más liberaciones.
La asignación efectiva es asignación histórica menos liberación. Un reembolso
consume disponibilidad con el evento negativo unused_refund del ledger existente,
vinculado a refunds(kind=return); no se introduce otro recibo positivo para restaurar
crédito ni se borra la asignación original. El saldo fiscal neto saldado no implica
que se haya enviado dinero: los reembolsos registrados son una dimensión separada.

Los consumos de credit_application_sources se restan del cupo de reembolso de la
liberación de origen. Una devolución posterior del pedido que recibió crédito
libera su propia asignación y mantiene la procedencia al pago original, sin contar
dos veces abonos dentro de una misma factura semanal ni reembolsos por pedido.

Ventas, Apartados, CxC/CSV, cliente, cuenta semanal, listado de facturas y consultas
públicas distinguen original/crédito/neto/disponibilidad/reembolsos. Los modelos de
lectura financiera usan snapshots transaccionales para no mezclar estados de consultas.

## API privada

Prefijo `/api/v1/business/stores/{store}/sales/orders/{order}/returns/{receipt}`:

| Método | Ruta | Datos/permiso |
|---|---|---|
| GET | `/financial?shipping_credit=0` | sales.view; propuesta o ajuste confirmado con fuentes/historial |
| POST | `/adjust` | returns.adjust + sales.view; request_key, version, review_hash, shipping_credit, reason |
| POST | `/refund` | refunds.record + sales.view; request_key, version, payment_id, amount, method, external_reference, reason |
| GET | `/financial/requests/{key}?operation=adjust` | recuperación propia adjust/refund |

Sesión/CSRF, StoreContext,15 operaciones/min/usuario; permisos revalidados en la
transacción. Locks ordenados de producto/variante/sucursal/pedido/factura/pagos y
ledger; registro atómico. Journal por actor/sucursal/pedido/recepción, recuperación
por GET al reabrir la conciliación, sin reenvío automático. Respuestas no-store.

## Evidencia y pendientes

- PHP:187 pruebas /2.180 aserciones, SQLite :memory: con guardia antes de migrar.
- Ocho escenarios financieros nuevos: crédito/reembolso/replay/referencia/límites,
  reducción de deuda y límite de cobro neto, redondeo acumulado, envío/precio histórico,
  factura semanal, CSRF, reparto entre artículos y permisos/conflictos.
- Browser:472/472 en seis perfiles;24 casos nuevos de revisión con giro, reembolso
  perdido/recuperado por GET, conflicto conservando motivo y consulta sin permisos.
- Migración002500 aplicada sólo a SQLite local tras copia íntegra verificada
  `.runtime/bshop-backup-20261114-181950.sqlite`. La demo conserva inventario/dinero;
  no se registraron ajustes o reembolsos comerciales por el agente.
- Aplicación entre operaciones incorporada posteriormente; ver `APLICACION_CREDITO.md`.
- Comprobantes propios incorporados en `COMPROBANTES_FINANCIEROS.md`; reversión de
  aplicaciones en `REVERSION_APLICACIONES_CREDITO.md`.
- Corrección de reembolsos sin entrega real de dinero en `CORRECCION_REEMBOLSOS.md`.
- Compensación de ajustes y re-conciliación versionada en `COMPENSACION_AJUSTES.md`.
- Pendientes: concurrencia/rendimiento de las compensaciones,
  conciliación avanzada con comisiones, concurrencia
  MySQL específica, aceptación física y cualquier integración bancaria real.
