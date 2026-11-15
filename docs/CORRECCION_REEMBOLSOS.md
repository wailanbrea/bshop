# Corrección de registros de reembolso erróneos

Regla confirmada por el propietario: sólo corregir registros en los que **nunca
se entregó dinero al cliente**. No es devolución bancaria ni recuperación de efectivo.

## Operación

Ventas/Apartados → pedido → Devoluciones de mercancía → conciliación de recepción
ajustada → Reembolsos registrados → **Corregir registro**.

Revisar el importe, indicar motivo y marcar expresamente **Confirmo que este
reembolso se registró por error y no se entregó dinero al cliente**. La casilla es
obligatoria también en el servidor. `refunds.reverse` es independiente de
refunds.record y se agrega a owner/manager. Seller conserva consulta.

La corrección completa tiene una referencia única por reembolso, actor/motivo/fecha,
confirmación, snapshot y hash en refund_reversals. Conserva el reembolso y su ledger
original, recibos, asignaciones, factura/PDF, ajuste y stock. Restituye únicamente el
saldo a favor que el registro erróneo había consumido; no crea ingreso, venta o pago.
El historial conserva ambas acciones y el dashboard excluye el registro corregido
de reembolsos efectivos. Una corrección por registro; no admite corrección parcial.

El saldo restaurado puede aplicarse a otro pedido o usarse al registrar una devolución
de dinero realmente realizada. Las referencias externas originales se conservan y
siguen siendo únicas; otro registro debe usar su referencia real distinta.

## Contabilidad e integridad

Los importes históricos y efectivos se distinguen. Si el ledger original incluye
un débito por el reembolso erróneo, la compensación lo contrapesa **en el modelo de
saldo**, sin escribir un recibo positivo ficticio. Refunded efectivo=histórico−corregido;
disponible agrega las compensaciones. Se preserva efectivo recibido=
asignado efectivo+disponible+reembolso efectivo, con BCMath/strings.

OrderSettlement, CreditPools, conciliaciones, aplicaciones, clientes, CxC y dashboard
usan la misma vigencia. Los controles sobre ledger/asignaciones históricas usan el
reembolso histórico para no perder conservación al aplicar/restaurar después.

Bloquea registro no confirmado, otro reembolso pendiente del mismo pago, corrección
ya realizada, ledger original inconsistente, comisión/documento/estado incompatible.
Admite únicamente registros manuales de devolución con contexto/procedencia trazados.
Locks ordenados, versión/hash revisados y conservación antes/después. No ejecuta
llamadas a banco ni interpreta la casilla como prueba de una transferencia externa.

## API y recuperación

Prefijo `/api/v1/business/stores/{store}/sales/orders/{order}/returns/{receipt}/refunds/{refund}/reversal`.

- GET `/`: revisión con sales.view/acceso a sucursal.
- POST `/`: además refunds.reverse; request_key,version,review_hash,reason,
  `confirmed_no_money=true` obligatorio.
- GET `/requests/{uuid}`: resultado sólo del actor y contexto originales.
- Sesión/CSRF, no-store,15 POST/min/usuario.409 exige revisión,422 datos inválidos.

Journal por actor/sucursal/pedido conserva recepción/reembolso, UUID, motivo y casilla.
Al volver al pedido consulta por GET; no envía automáticamente otro POST. La respuesta
incierta permite comprobar o reintentar iguales datos.409 conserva texto/casilla y
exige actualizar revisión. Diálogos secuenciales; datos conservados al girar.

## Evidencia

239 PHP/3.228 aserciones y688/688 browser en seis perfiles. Ocho casos backend nuevos:
historia/dinero/PDF intactos, confirmación obligatoria, reembolso posterior real,
aplicación del crédito restaurado, ajuste posterior, scope/revisión/no confirmado,
permisos/recuperación y CSRF.30 browser nuevos: casilla/giro, recuperación, conflicto,
permiso y bloqueo. Build y Pint con caché nueva aprobados.

Migración003000 sólo en SQLite local, previa copia íntegra
`.runtime/bshop-backup-20261115-063846.sqlite` (79 tablas antes). Demo conciliada:
98 físico/8 reservado/90 disponible;480=257+223 USD,2 facturas/5 pagos; no se crearon
ni corrigieron reembolsos comerciales de demo. MySQL/concurrencia específica y
aceptación en tablet física siguen pendientes.
