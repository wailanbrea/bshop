# Inventario y kardex

Existencias por variante y almacén, dentro de sucursal autorizada. Físico, reservado
y disponible se muestran por separado; disponible = físico − reservado.

Desde Inventario se registran entradas, salidas, conteos físicos y transferencias.
Se exige cantidad decimal exacta, motivo y versión del saldo mostrado. El conteo
registra la diferencia, nunca sobrescribe el saldo sin movimiento. Salida/transferencia
no pueden consumir unidades reservadas. Nuevas operaciones requieren artículo,
sucursal y almacén activos; servicios sin seguimiento no crean stock.

Cada acción tiene UUID/hash idempotente, auditoría y una transacción corta. Una
transferencia registra salida/entrada emparejadas y requiere acceso a ambas sucursales.
Se conserva el kardex: no hay rutas para editar/borrar movimientos. El modelo bloquea
su reescritura y las correcciones se hacen mediante nuevos movimientos.

`StockLedger` es el escritor compartido de saldos y movimientos para operaciones
manuales, reservas, liberaciones y entregas. No usar actualizaciones directas de saldo
desde nuevas pantallas. Conservar locks de producto/variante y el orden de ubicaciones.

Pruebas: DECIMAL(19,4) completo en MySQL 8.4.13, dos usuarios compitiendo por la última
unidad, permisos de sucursal, stock reservado protegido, transferencias y errores de
versión. La demo SQLite usa cantidades/importes pequeños; la precisión extrema de
entrega se verifica en MySQL, no se atribuye al almacenamiento numérico de SQLite.

`scripts/verify-demo.php` comprueba de sólo lectura kardex vs saldos, reservado vs
reservas activas, importes/pagos/crédito y checksums de PDFs, en el SQLite local
estrictamente identificado. La expiración de reservas dispone de fase funcional
(ver abajo).

## Valoración del inventario, capital invertido y alertas de reposición

- En **Inventario** (`/api/v1/business/stores/{store}/warehouses/{warehouse}/stock`) y en **Vista general** (`/api/v1/business/stores/{store}/sales/control`) se calcula y presenta en tiempo real la valoración del inventario con precisión `DECIMAL(19,4)` (BCMath):
  - **Capital invertido (al costo):** Suma de `existencia física × precio de compra (cost_price)` para las variantes con existencia positiva (visible para roles con permiso `catalog.costs`).
  - **Valor del inventario en venta:** Suma de `existencia física × precio de venta (sale_price)`.
  - **Ganancia proyectada:** Diferencia entre el valor comercial en venta y el capital invertido.
  - **Unidades físicas y disponibles:** Total de unidades en mano y disponibles para la venta.
  - **Alertas de reposición y filtro `Por reponer (≤ 2)`:** Cuenta variantes por agotarse (`0 < disponible <= 2`) y agotadas (`disponible <= 0`), permitiendo filtrarlas directamente desde el selector de disponibilidad (`filter=low`).
- En cada renglón de **Inventario** se muestran además el costo unitario (`unit_cost`), el precio de venta y el monto invertido por variante, junto con el botón **Editar precios** (para usuarios con `catalog.manage`) que permite actualizar directamente el precio de compra y el precio de venta de la variante y recalcular de inmediato la valoración del almacén.
- Incluye también el botón **Exportar inventario CSV** (`GET /api/v1/business/stores/{store}/warehouses/{warehouse}/stock/export`) que exporta todas las variantes filtradas con SKU, código de barras, producto, atributos, físico, reservado, disponible, precio de compra (si tiene `catalog.costs`), precio de venta, capital invertido, valor en venta y estado, neutralizando fórmulas CSV.

La revisión operativa ya está disponible en **Inventario → Revisar inventario**,
API y comando `bshop:inventory-reconcile`. Compara saldos, reservas pendientes y
cadena del kardex sin reparar ni escribir datos. Tiene programación horaria declarada;
falta instalar/verificar el programador del destino y validar esta nueva lectura
concurrente y extrema en MySQL. Ver `RECONCILIACION_INVENTARIO.md`.

El vencimiento F5.04 ya tiene plazo opcional para nuevas reservas, diagnóstico y
comando idempotente que libera mediante StockLedger, sin cambiar físico ni pagos.
Se excluyen apartados con pagos, factura o entregas. El programador del destino y
la prueba concurrente MySQL siguen pendientes. Ver `EXPIRACION_RESERVAS.md`.
