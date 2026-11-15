# Anulación compensatoria de recepciones físicas

**Ventas/Apartados → pedido → Devoluciones de mercancía → recepción pendiente →
Anular recepción por error**. Permiso `returns.reverse` para owner/manager;
consulta con sales.view y acceso a sucursal. Revisar líneas/almacén, indicar motivo
y confirmar. Diálogos secuenciales, controles táctiles y textos centralizados.

Sólo recepciones `received`, sin ajuste ni reembolso. Se conserva la recepción y
sus artículos originales; se agrega return_receipt_reversals con snapshot/hash,
actor/motivo/fecha y detalle de cantidades/movimientos en return_receipt_reversal_items.
La recepción queda void. No modifica dinero, factura, abonos ni PDF histórico.

Cada línea repuesta exige un ingreso original trazado y existencias **disponibles**
suficientes en su almacén histórico. StockLedger registra customer_return_reversal
negativo con reserva delta0; nunca se reescribe el ingreso. Cantidades por variante
se agregan antes de validar. Sin reposición original no hay movimiento inverso.
Si hay unidades consumidas/reservadas, falla todo el lote sin efectos parciales.

Se restaura el cupo para recibir las unidades correctamente mediante otra recepción.
Sólo desaparece la retención de cobro si no quedan otras recepciones pendientes de
la factura; probado también con factura semanal. Una recepción con ajuste económico
exige compensar antes ese flujo (`COMPENSACION_AJUSTES.md`); esta acción no borra ni
deshace un ajuste aprobado. Reembolsos erróneos corregidos dejan de bloquear el
historial económico efectivo.

Locks producto/variante→sucursal/almacén/saldos→pedido/factura/recepción. UUID,
versión y hash incluyen stock/versiones.409 conserva motivo y exige revisar;
respuesta incierta conserva UUID y recupera por GET al volver, sin POST automático.
Una reversión completa por recepción; mismo UUID devuelve replay.

API tras `/api/v1/business/stores/{store}/sales/orders/{order}/returns/{receipt}/reversal`:
- GET `/`: revisión y bloqueos con sales.view.
- POST `/`: sales.view + returns.reverse; request_key/version/review_hash/reason.
- GET `/requests/{uuid}`: recuperación del actor/contexto original.
Sesión/CSRF, no-store,15 operaciones/minuto. Historia visible en pedido y actividad.

Verificación:231 PHP/3.081 aserciones,658 browser en seis perfiles (30 nuevos),
build y Pint aprobados. Se verificó Pint con caché nueva para igualar el CI remoto.
Migración002900 sólo SQLite local, respaldo íntegro
`.runtime/bshop-backup-20261115-032624.sqlite` (77 tablas previas); demo conciliada
98 físico/8 reservado/90 disponible,480=257+223 USD, sin anulaciones comerciales.
Concurrencia MySQL y aceptación en hardware físico siguen pendientes.
