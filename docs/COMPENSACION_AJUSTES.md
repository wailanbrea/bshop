# Compensación de ajustes económicos confirmados

## Operación

**Ventas/Apartados → pedido → Devoluciones de mercancía → conciliación →
Compensar ajuste**. Revisar crédito a retirar, asignaciones a restituir, nuevo neto
y pendiente; indicar motivo y confirmar. Permiso independiente `adjustments.reverse`
para owner/manager; consulta con sales.view y acceso explícito a sucursal.

Se compensa completamente el ajuste sin editar su importe, sus líneas históricas,
asignaciones, liberaciones ni PDF. Una compensación por ajuste, con motivo/autor/
fecha/snapshot/hash. No cambia stock ni registra pagos o transferencias.

El neto aumenta por el crédito compensado. Si el ajuste liberó asignaciones,
éstas vuelven a ser efectivas y se retira ese saldo disponible; el resto reabre
deuda. Se actualiza el saldo/estado de la factura, incluida la factura semanal,
sin generar otra factura ni modificar su PDF original.

La recepción vuelve a `received`: nuevos cobros de su factura quedan retenidos
hasta revisarla. Puede aprobarse un **nuevo ajuste con otro ID/PDF**, o corregirse
la recepción física mediante su flujo, si hay stock disponible. El contador
provisional refund_amount de los artículos refleja el ajuste vigente; se reinicia
al compensar, pero cantidades/destino y líneas de cada ajuste permanecen históricos.

## Versiones e historial

- `return_adjustment_heads`: un puntero por recepción al ajuste vigente; FK compuesta
  impide enlazar el ajuste de otra recepción y active_adjustment_id es único.
- `return_adjustment_revision_items`: líneas inmutables por ajuste/artículo, incluidas
  las versiones iniciales copiadas al migrar.
- `return_adjustment_reversals`: compensación única y evidencia sellada.
- `return_adjustment_items` conserva la primera representación histórica por artículo
  para compatibilidad; no se sobrescribe al reaprobar.
- La migración copia datos y sustituye el antiguo índice que sólo admitía un ajuste
  por recepción. No borra ajustes, líneas, facturas ni movimientos existentes.
- La conciliación muestra cada versión y conserva la descarga de sus PDF originales,
  incluso cuando la recepción está pendiente de volver a conciliar. La actividad
  comercial distingue ajuste y compensación; los saldos suman sólo ajustes vigentes.

## Límites y redondeo

Sólo el **último ajuste vigente del pedido** puede compensarse. Este orden inverso
conserva la distribución acumulada de descuentos/impuestos y céntimos. Está probado
el reparto0,33/0,34/0,33 al compensar y reaprobar varias devoluciones.

El crédito liberado debe estar íntegramente disponible. Bloquea aplicaciones vigentes,
reembolsos efectivos o pendientes, pagos/documentos incompatibles y comisiones por
conciliar. Una aplicación revertida o un reembolso erróneo ya compensado no consumen
el saldo. No deshace operaciones posteriores implícitamente.

Admite ajustes de valor0 sin crear entradas monetarias ficticias. También permite
deshacer una cadena en orden: resolver ajuste/recepción del destino, revertir la
aplicación y después compensar el ajuste de origen. Mientras exista recepción
pendiente, mantiene la retención de cobro.

## API, transacción y recuperación

Prefijo `/api/v1/business/stores/{store}/sales/orders/{order}/returns/{receipt}/adjustments/{adjustment}/reversal`.
- GET `/`: revisión, saldos proyectados, procedencia y bloqueos.
- POST `/`: request_key/version/review_hash/reason5..255; sales.view + adjustments.reverse.
- GET `/requests/{uuid}`: recuperación del mismo actor/contexto/documento.
- Sesión/CSRF,15 POST/min/usuario, no-store.409 exige revisar estado/saldos,422 valida datos.

Locks ordenados de producto/variante→sucursal→pedidos/facturas→pagos/liberaciones;
se revalida el conjunto de orígenes y conservación antes/después. Cambios en saldos
o versión invalidan la revisión. Journal por actor/sucursal/pedido conserva documento,
UUID y motivo. Al volver consulta GET, sin reenviar automáticamente POST. Un replay
del ajuste original después de reaprobar no compensa la versión nueva.

Textos UI en `resources/js/locales/es/adjustmentReversal.ts`; errores nuevos en
`lang/es|en/adjustment_reversal.php`. Datos de operación provienen del servidor.

## Verificación

- 251 pruebas PHP/3.482 aserciones, SQLite :memory: protegido. Incluye migración con
  filas previas, re-conciliación/PDF, deuda, valor0, LIFO/redondeo, crédito/reembolso
  consumido, stock físico, scope/permisos/CSRF, factura semanal y cadena completa.
- 718/718 browser en seis perfiles emulados;30 nuevos de giro, respuesta perdida,
  conflicto, permiso, bloqueo, nueva revisión y PDF histórico. Build/Pint aprobados.
- SQLite local: respaldo íntegro `.runtime/bshop-backup-20261115-073914.sqlite`,80 tablas
  previas; simulación y aplicación sólo003100. Demo98/8/90 stock;480=257+223 USD.
- MySQL8.4.13 aislado:127.0.0.1:33078,bshop_testing_single,testing,datadir del proyecto
  verificados. Migraciones pendientes002000–003100 simuladas/aplicadas después de copia
  de63 tablas `.runtime/checked-mysql-backup-20261115-073149-ae7260.json`; filas previas
  y permisos anteriores conservados.
- `scripts/verify-adjustment-reversals-mysql.php`: PASS de compensación/revisión,
  corrección de reembolso, asignaciones/stock intactos e importe DECIMAL extremo
  199999999999999,98. Fixtures/perfil revertidos por transacción; sin HTTP/PDF/banco.

Concurrencia MySQL, rendimiento bajo carga, conciliación con comisiones y aceptación
en tablet física siguen pendientes. La prueba nativa de este hito no simula carreras.
