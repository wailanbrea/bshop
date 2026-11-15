# Catálogo — productos, atributos y variantes

## Alcance implementado

Catálogo único de esta instalación de BShop, compartido por las sucursales del
negocio. No representa existencias globales ni permite operar inventario de una
sucursal sin autorización. Stock, fotos y pedidos siguen en sus módulos pendientes.

- Productos físicos y servicios, categoría, marca, descripción, unidad y archivo/reactivación.
- Categorías jerárquicas sin ciclos; marcas; atributos y valores reutilizables.
- Variantes con SKU, barras, precio/costo y combinación canónica de valores.
- Consulta paginada, búsqueda por nombre/SKU/barras y filtros por tipo/categoría/marca/estado.
- UI tablet-first: tarjetas, detalle paginado, formularios AppDialog y guardado seguro.
- Importación CSV con revisión por fila, confirmación atómica/idempotente y
  reversión por archivo. Guía en `IMPORTACION_CSV.md`.

## Permisos

| Rol inicial | catalog.view | catalog.manage | catalog.costs |
|---|---|---|---|
| Propietario | Sí | Sí | Sí |
| Administrador | Sí | Sí | Sí |
| Vendedor | Sí | No | No |

La migración aditiva incorpora estos permisos a las instalaciones existentes.
Los costos no se serializan en el modelo por defecto; el resource sólo los
incluye con permiso explícito. Un editor personalizado sin catalog.costs no puede
enviar ni siquiera cost_price=null para borrar el costo de otro usuario.

## Integridad

- Dinero de entrada como **string decimal** no negativo, sin separadores de miles,
  exponentes ni números JSON float. Máximo 15 enteros y 4 decimales. BCMath
  normaliza a cuatro decimales antes de guardar en DECIMAL(19,4).
- La UI acepta punto/coma decimal sin convertir precios a Number/float; presenta
  cadenas con código de moneda y conserva fracciones no nulas hasta cuatro decimales.
- SKU y barras se recortan y normalizan a mayúsculas. Unicidad en BD, incluso si
  el producto/variante está archivado o inactivo. No se reciclan por archivar.
- Valores por variante: uno por atributo, activos y existentes; clave SHA-256 de
  pares attribute_id:value_id ordenados. Sin valores, combinación `default`.
- Variantes activas de un producto usan el mismo conjunto de atributos. Para
  convertir una variante general a tallas/colores, primero se desactiva la general.
- La combinación de una variante existente es inmutable: se crea otra variante
  para otra identidad física, manteniendo stock/historial del ID anterior.
- Cambios sobre producto y variante requieren su `version`; discrepancia →409,
  sin sobrescribir. Lock del producto serializa altas/ediciones de variantes.
- Cambiar tipo/seguimiento de stock se bloquea si hay movimientos, saldos no nulos
  o ventas. Desactivar variante con reservas activas se bloquea.
- No hay DELETE. Archivo de producto y estado de variante preservan historial.
- Cambiar precio actual no actualiza líneas históricas de pedidos/facturas.
- Referencias inactivas existentes se conservan; no se pueden asignar nuevas.

## Uso desde tablet

1. **Catálogo → Organizar catálogo**: crear categorías, marcas y atributos/valores.
2. **Nuevo producto**: datos generales y una o más variantes; precios sin miles.
3. Tocar una tarjeta: detalle y precios por variante, edición y nuevas variantes.
4. Para archivar, editar producto y cambiar Estado; filtro Archivados para reactivar.
5. Ante 409, el borrador se conserva: cerrar, pulsar **Actualizar** y volver a abrir
   antes de aplicar cambios. No se sobreescribe automáticamente el trabajo de otro.

Lista: 24 productos por página, máximo técnico API 60. Detalle: 24 variantes por
página. Creación: máximo técnico 100 variantes por solicitud y 12 valores por
variante; no se generan combinaciones cartesianas sin selección explícita.

La demo local incluye `Camiseta de demostración QA`, con Talla QA M/L y SKU
DEMO-CAM-M/DEMO-CAM-L. Son datos sintéticos sin inventario inicial ni ventas.
Las instalaciones de entrega no reciben esta base ni se siembran con estos datos.

## Evidencia

- `tests/Feature/CatalogTest.php`: permisos/costos, importes, SKU/barras,
  combinaciones, aislamiento entre IDs de producto, versiones, archivo,
  categorías y validación de referencias.
- `scripts/verify-catalog-mysql.php`: destino testing/127.0.0.1:33078/
  bshop_testing_single, valida bind/datadir/version antes de operar. Demuestra
  rango completo DECIMAL, snapshot histórico y unicidad nativa. QA en transacción
  revertida; no toca VPS. SQLite no replica la precisión DECIMAL completa de MySQL.
- Suite tablet: 84 casos actuales, incluidos catálogo/importación, giro, costos
  ocultos, conflictos, reintentos de red y navegación durante refresco lento.

## Pendiente

Fotos/R2, operaciones de inventario y medición con catálogos grandes. El
placeholder no es una fotografía ni un estado de stock. La importación ya fue
implementada y probada; ampliar aceptación con datos del piloto y tablet física.
