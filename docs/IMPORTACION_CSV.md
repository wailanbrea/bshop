# Importación CSV del catálogo

## Uso desde tablet

1. **Catálogo → Importar CSV → Descargar plantilla**.
2. Sustituye los ejemplos y guarda como **CSV UTF-8**.
3. Selecciona el archivo y pulsa **Revisar archivo**. Todavía no se crean productos.
4. Corrige todos los errores. Puedes descargar el reporte y subir el archivo
   corregido; Revalidar sirve para referencias reactivadas en el catálogo.
5. Pulsa **Importar N productos**. Se importa todo el lote o no se añade nada.
6. Ante conexión interrumpida, consulta Actualizar estado o reintenta el mismo
   lote: devuelve los mismos IDs. Mis importaciones recupera revisiones/resultados.

La barra inferior identifica archivo y cantidades, y sigue accesible al girar.
Elegir otro archivo limpia sólo la revisión en pantalla; el anterior queda en
historial. La vista previa vence en 24 horas.

## Formato y límites

Coma o punto y coma, UTF-8 con/sin BOM, CRLF/LF, campos entre comillas y saltos
de línea entrecomillados. Escapar comillas con otras comillas (`""`). No se
admiten XLS/XLSX/ODS, UTF-16, bytes nulos o CSV mal formado. Cabeceras sin
distinción de mayúsculas/minúsculas, sin duplicados ni columnas desconocidas.

Máximos técnicos: **2 MiB**, **500 filas de datos**, 100 variantes por producto,
12 valores por variante. Celdas de más de 64 KiB o más de 64 columnas se rechazan
antes de generar vista previa. La plantilla tiene 15 columnas. Filas completamente
vacías se ignoran. Los errores indican la línea física donde empieza el registro.

| Columna | Significado |
|---|---|
| name | Obligatoria, nombre hasta 200 caracteres |
| sku | Obligatoria, único en catálogo/archivo, hasta 90 caracteres |
| sale_price | Obligatoria, decimal no negativo, hasta 15 enteros y 4 decimales |
| product_key | Agrupa variantes; vacío = producto por fila |
| cost_price | Sólo con catalog.costs; vacío = costo desconocido |
| barcode | Único en catálogo/archivo, hasta 100 caracteres |
| product_type | physical/service; vacío = physical |
| unit_code | Hasta 20 caracteres; vacío = unit |
| track_stock | 1/0, true/false, sí/no; vacío = sí para físico, no para servicio |
| category_id | ID existente y activo, o vacío |
| brand_id | ID existente y activo, o vacío |
| attribute_value_ids | IDs activos separados con `|`, uno por atributo, o vacío |
| description | Hasta 10000 caracteres, opcional |
| status | active/archived; vacío = active |
| variant_active | 1/0, true/false, sí/no; vacío = activa |

Importes sin miles ni exponentes. Coma decimal aceptada dentro de una celda
válida: entrecomillar si el separador del archivo también es coma. No se usa float.
SKU/barras se normalizan a mayúsculas; conservar como texto en Excel para no
perder ceros iniciales. El texto literal `0` no equivale a vacío.

El mismo product_key exige repetir todos los datos generales. Sólo varían SKU,
barras, precio/costo, valores y estado de variante. Dos variantes generales del
mismo grupo son duplicadas. Ejemplo (reemplazar IDs por referencias del negocio):

```csv
product_key,name,sku,sale_price,attribute_value_ids
camiseta,Camiseta básica,CAM-M,1250.00,11|20
camiseta,Camiseta básica,CAM-L,1250.00,12|20
```

Consultar IDs en Formato y referencias del importador. No se crean referencias
automáticamente ni se importan existencias, almacenes, clientes, impuestos o pagos.
Sólo se añaden productos nuevos; no es upsert ni actualización masiva.

## Consistencia y errores

- Preview guarda únicamente lote privado y auditoría, no productos/referencias.
- Reutiliza validación de catálogo y combinaciones canónicas. Reporta errores por
  fila: duplicados, precios, grupos, referencias y permisos de costo.
- Confirmar revalida el catálogo actual; si un SKU fue ocupado o una referencia
  cambió, rechaza el lote. Referencias activas se bloquean al persistir.
- Un fallo durante cualquier creación revierte productos, variantes y auditorías
  de la transacción; lote no consumido y sin productos parciales.
- Confirmación usa sólo contenido almacenado. POST no acepta filas/precios sustitutos.
- Repetir confirmación devuelve IDs previos. Dos lotes con mismos SKU dejan uno
  importado y otro con errores; procesos separados/barrera verifican esa carrera.

## Reversión controlada

Archiva únicamente productos creados por el lote, sin eliminarlos ni liberar SKU.
Rechaza toda la operación si alguno fue editado, tiene otras variantes, fue
eliminado lógicamente o usado en stock/movimientos/reservas/ventas/facturas/fotos.
Compara huellas y versiones bajo locks antes de archivar cualquier producto.
Si ya no aplica la reversión automática, gestionar individualmente en Catálogo.

Repetir reversión no cambia nada más. Confirmar un lote revertido se rechaza.
Para reutilizar artículos revertidos, filtrar Archivados y restaurar/editar sus
registros, preservando identidad e historial.

## Privacidad y operación

catalog.manage requerido. Historial, vista previa, reporte y acciones sólo para
su creador. Lotes con costos también requieren catalog.costs al recuperarlos o
confirmarlos. Payload/result cifrados con APP_KEY en LONGTEXT, ocultos por defecto;
conservar APP_KEY en respaldos. Original no se guarda en carpeta pública/R2.
Auditoría registra resumen, no CSV completo. Errores CSV neutralizan fórmulas.
Respuestas privadas no-store; cinco previews por minuto/usuario.

Expirar una revisión impide confirmar, no borra su historial cifrado. No se agregó
limpieza destructiva automática. Auditoría admite IDs opacos sin perder los
numéricos anteriores; rollback a BIGINT se bloquea si truncaría identificadores.

## Evidencia

CatalogImportTest, tests/Browser/import.spec.ts, verify-import-mysql.php y
verify-import-concurrency.php. Integración y procesos independientes sólo en
bshop_testing_single, 127.0.0.1:33078, con guardia de versión/bind/datadir; nunca VPS.
Las 84 pruebas browser interceptan API, complementadas con demo real y MySQL.
Aceptación en la tablet física del cliente sigue pendiente.
