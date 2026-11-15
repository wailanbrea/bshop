# Reversión compensatoria de aplicaciones de crédito

## Operación

**Ventas/Apartados → pedido destino facturado → Saldo a favor del cliente →
aplicación recibida → Revertir aplicación**.

1. Revisar importe, pago original y deuda del destino después de corregir.
2. Escribir motivo de5..255 caracteres y confirmar **Reversión de crédito**.
3. El historial identifica aplicación revertida, autor, motivo y fecha. La actividad
   del pedido muestra tanto la aplicación como su corrección posterior.

Permiso independiente `credits.reverse`, inicialmente owner/manager. Consulta con
`sales.view`; seller conserva lectura sin reversión. El permiso se revalida dentro
de la transacción, además de la protección de rutas y acceso explícito a sucursal.

## Efecto y conservación

- Reabre exactamente la deuda cubierta por la aplicación y restaura su importe a
  las mismas liberaciones que consumió. Una cadena A→B→C devuelve saldo a B cuando
  la liberación pertenecía a B, aunque el efectivo original hubiera llegado a A.
- Una aplicación admite una sola reversión completa. No hay edición parcial ni
  borrado del documento, asignación, ledger, procedencia o comprobante original.
- `credit_application_reversals` conserva referencia única, importe, actor/motivo/
  fecha y snapshot/hash. No crea un recibo positivo ni una venta para devolver crédito.
- No modifica stock, recepción física, factura original ni bytes de su PDF. Ajusta
  saldo/estado de la factura destino; en factura semanal cuenta la corrección una vez.
- Saldo restaurado puede aplicarse nuevamente o reembolsarse mediante su flujo
  original, con los mismos límites. Historial/PDF de la primera aplicación permanece.

Contabilidad de lectura por pago: `A`=asignaciones históricas, `L`=ledger original,
`R`=liberaciones y `V`=aplicaciones compensadas. Disponible=`L+R+V`;
asignado efectivo=`A−R−V`. Aplicaciones activas, consumos por liberación y crédito
entrante/saliente excluyen compensadas. Se conserva efectivo original=
asignado efectivo+disponible+reembolso, sin interpretar la reversión como dinero nuevo.

## Límites y recuperación

Bloquea si ya fue revertida, su asignación fue liberada por una devolución posterior,
la factura tiene recepción pendiente, existe reembolso del pago pendiente de resultado,
comisiones por conciliar, pago no confirmado o estado/documento incompatible.
No deshace automáticamente una cadena ya consumida ni habilita por sí sola la
reversión de un pago histórico con aplicaciones: esas conciliaciones son posteriores.

UUID/versión/hash de revisión; bloqueo de productos/variantes→sucursal→pedidos
ordenados→facturas ordenadas→pago/asignaciones/liberaciones. Revalida el conjunto de
orígenes tras la espera. Compensación única y controles de conservación antes/después.

Journal por pestaña/actor/sucursal/pedido con aplicación y motivo. Si se pierde la
respuesta, al volver al pedido consulta el UUID por GET. No repite el POST de forma
automática. En409 conserva motivo y pide actualizar revisión; en resultado incierto
conserva UUID/datos y ofrece comprobar/reintentar la misma operación.

## API

Tras `/api/v1/business/stores/{store}/sales/orders/{order}/credits/{application}/reversal`:

| Método | Ruta | Acceso/resultado |
|---|---|---|
|GET|`/`|sales.view; revisión, saldo, procedencia y allowed/bloqueos |
|POST|`/`|sales.view + credits.reverse; request_key,version,review_hash,reason |
|GET|`/requests/{uuid}`|sales.view; recuperación del mismo actor/contexto |

Sesión/CSRF, StoreContext, límite independiente15 reversiones/minuto/usuario;
no-store.401/403/404 para sesión/permiso/contexto,409 para revisión/estado y422 para
datos inválidos. POST devuelve pedido actualizado, application/reversal IDs y replay.

## Verificación y habilitación local

- 223 pruebas PHP/2.944 aserciones; testing/sqlite/:memory: forzado y verificado por
  TestCase antes de migrar. Nueve escenarios nuevos: inmutabilidad/replay/PDF,
  reembolso posterior, factura semanal, crédito consumido/retención, revisión/scope,
  permiso/recuperación privada, CSRF, cadena con dueño real y reembolso pendiente.
- 628/628 browser, seis perfiles;30 casos nuevos de giro, recuperación, revisión,
  permiso y bloqueo. Build/Pint aprobados. Emulación con API interceptada.
- Copia local íntegra `.runtime/bshop-backup-20261115-025251.sqlite`,76 tablas antes
  de migrar; aplicada sólo002800 sobre database/bshop-business.sqlite, sin refrescar.
- Demo conciliada:98 físico/8 reservado/90 disponible;480=257+223 USD;2 facturas/5 pagos.
  No se crearon ni revirtieron aplicaciones comerciales de demostración.
- Verificador local valida hashes/contexto/importes de compensaciones y conservación
  global. MySQL/concurrencia específica y aceptación física siguen pendientes.
