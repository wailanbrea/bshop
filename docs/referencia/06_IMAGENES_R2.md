# Arquitectura definitiva de fotografías — Cloudflare R2 (v3.0)

## Parámetros del producto (confirmados)
- Almacenamiento lógico inicial: **2 GiB = 2,147,483,648 bytes por tienda/tenant**.
- **5 imágenes máximo por producto** sumando imágenes generales y de variante.
- Destino: **Cloudflare R2 Standard** administrado por BSolutions.Dev; no prometer gratuidad ilimitada al cliente ni uso libre de operaciones.
- Público: solamente imágenes de productos optimizadas. Privado: documentos, datos personales, temporales y cualquier contenido no publicado.

## Tarifas y distinción crítica
R2 tiene un tramo gratuito por **cuenta**: 10 GB-mes Standard, 1 millón de Class A y 10 millones Class B al mes; exceso puede cobrarse por almacenamiento y operaciones. Confirmar precios actuales antes de contratar/mostrar un presupuesto. Información oficial: https://developers.cloudflare.com/r2/pricing/ (consultada 2026-11-12). La cuota individual de KeylaShop no equivale a 2 GB gratis de Cloudflare por tienda.

## Estructura sugerida de R2
**Bucket 1:** `keylashop-catalog-public` conectado a `media.bsolutions.dev` (configurar custom domain desde R2, no CNAME a r2.dev). Guardar exclusivamente derivados revisados de fotos de catálogo. Con caché por URL de versión.

**Bucket 2:** `keylashop-private` NO público (solo si se requiere para facturas/documentos privados y staging remoto futuro). Para MVP, el staging puede permanecer en carpeta TEMP **privada** del VPS; originales temporales se borran tras procesar y publicar.

Ejemplo de keys:
```
tenants/{tenant_ulid}/products/{product_ulid}/{media_ulid}/v1/thumb.webp
tenants/{tenant_ulid}/products/{product_ulid}/{media_ulid}/v1/card.webp
tenants/{tenant_ulid}/products/{product_ulid}/{media_ulid}/v1/detail.webp
```
No guardar ruta local absoluta ni URL completa en MySQL; guardar `(provider, bucket_name, object_key, checksum, size, status)` y construir URL con config. Nunca confiar en nombre original para la key.

## Flujo MVP recomendado: carga a Laravel y optimización ANTES de R2
Este camino tiene menos riesgos de abuso para cargas de 10 MiB y simplifica control de cuota, virus/MIME y plataforma Windows. La carga directa mediante URL presignada queda como mejora futura si los clientes generan mucho tráfico.

1. Interfaz iPad selecciona imagen; optimización básica en navegador **opcional**, pero backend nunca confía en ella.
2. API Laravel valida login/rol, tenant, producto, límite 5 fotos, `Content-Length` (cuando existe) y archivo máximo 10 MiB.
3. En transacción corta: bloquea fila `products` + `tenant_storage_quotas`, revalida número de imágenes y reserva provisional de bytes; genera sesión única de carga idempotente.
4. Laravel recibe bytes en staging privado bajo límite PHP y tamaño/tiempo de carga. No escribir en carpeta pública de Apache.
5. Identificar tipo real (`finfo`, decodificador), dimensiones y límite de píxeles (máximo sugerido 20 MP, validar memoria). Aceptar JPEG/PNG/WebP; rechazar SVG, ejecutables, GIF animado. HEIC: implementar conversión probada o ofrecer selector compatible y mensaje preciso.
6. Re-encodificar a WebP eliminando EXIF/GPS; rendiciones `thumb` <=320 px, `card` <=640 px, `detail` <=1600 px, calidad aprox. 78-84; no ampliar fotos pequeñas.
7. Calcular hash y bytes REALES de las tres rendiciones; comprobar cuota con saldo reservado en transacción separada.
8. Subir cada derivado a R2 con cabeceras `image/webp`, `Cache-Control: public, max-age=31536000, immutable` para keys versionadas; verificar respuesta/HEAD (o comprobación de existencia segura) y solo después poner `media_assets.ready`.
9. Insertar o confirmar metadatos `media_renditions` y vínculo `product_images`; convertir cuota provisional a `bytes_ready` reales. Borrar staging privado temporal.
10. Si falla una etapa: marcar `failed/rejected`, no mostrar imagen, liberar reserva temporal, limpiar objetos huérfanos mediante tarea de reconciliación (saga compensatoria).

**Importante:** operaciones de archivo y transacciones SQL no son atómicas juntas. No mantener transacción SQL abierta mientras comprimes/subes 3 ficheros. Coordinar con estados, outbox, idempotencia, reservas de cuota y reintentos seguros.

### Contabilidad de cuota
`bytes_ready = SUM(bytes de objetos reales activos y no duplicados del tenant)`; no sumar imágenes dos veces por referencias a otros productos. Contar las 3 rendiciones y cualquier original retenido. `bytes_pending` previene exceso por cargas simultáneas. Política para nuevas cargas: rechazar cuando `bytes_ready + bytes_pending + reserva_propuesta > max_bytes`. Para reservar el original temporal, usar límite por archivo; cuando se conocen las rendiciones, ajustar con garantía transaccional.

El máximo 5 por producto **no** se puede garantizar con `CHECK` SQL de una fila. Bloquear producto con `SELECT ... FOR UPDATE` y contar imágenes activas + sesiones pendientes. Prohibir que 2 uploads simultáneos creen la sexta.

### Riesgo técnico de cuotas en R2
En la opción directa presigned PUT, R2 no garantiza por sí solo que el navegador no envíe más bytes de los previstos en la política del servidor. Preferir proxy Laravel para MVP, o implementar comprobación post-upload, restricciones razonables, tareas de limpieza, tokens de corta duración y límites de cuenta; no afirmar que URL firmada equivale a validación completa.

## Integración Laravel (orientativa, verificar contra versión real)
Instalar adaptador S3 compatible Laravel Flysystem, configurar un disk `r2_catalog` con `driver: s3`, `region: auto`, `endpoint: https://<ACCOUNT_ID>.r2.cloudflarestorage.com`, bucket de catálogo, credentials de alcance mínimo. Usar el cliente para `put`, `exists`, `delete`; no pasar `R2_SECRET_ACCESS_KEY` al frontend.

Variables sugeridas en `config/.env.example.keylashop`. No registrar secretos, ni commit del `.env` real. Seguridad de ACL R2: bucket catálogo explícitamente público para derivados **publicados** y privado para staging/documentos.

## Caché y eliminación
- URL inmutable por versión (`.../v1/card.webp`); al reemplazar imagen crear `media_ulid`/`v2`, actualizar referencia en BD.
- No sobrescribir imagen ya cacheada en la misma key.
- Borrado en dos fases: desvincular/lógica `deleted`; después de plazo de seguridad borrar del bucket vía job idempotente. Revisar facturas históricas que pudieran necesitar imagen antes de borrar de forma irreversible.
- No habilitar listing de objetos públicamente; que una key pública sea accesible es esperado si el catálogo la referencia.

## PWA y experiencia de usuario
- Arrastrar, selector iPad, cámara, vista previa, progreso de carga, cambiar principal, ordenar fotos, eliminar y reintentar.
- Mensajes claros: "Máximo 5 fotos por producto", "La tienda alcanzó 2 GiB", "Formato HEIC no admitido" cuando aplica.
- Mostrar miniaturas lazy-loading, `srcset` cuando convenga; never download detail para 100 tarjetas.

## Seguridad y pruebas
- Test de imagen renombrada `.jpg` con bytes no imagen; no publicarla.
- Test de imagen enorme/compression bomb y metadatos EXIF/GPS; recodificar sin datos sensibles.
- Test de 2 cargas concurrentes cuando ya hay 4 fotos (una debe rechazarse).
- Test de cuota 2 GiB y dos subidas simultáneas; no desbordar.
- Test caída de R2 entre rendiciones; job de limpieza y cuota recuperada.
- Test producto borrado mientras se sube foto; no dejar objeto público suelto.
- Test tenant A consultando `media_asset_id` de B; no divulgar metadatos privados.
- Test acceso HTTP al bucket privado: 403/no listado; custom domain solo sirve derivado.

## Documentación oficial de referencia
- Precios: https://developers.cloudflare.com/r2/pricing/
- Dominio público y caché: https://developers.cloudflare.com/r2/buckets/public-buckets/
- Presigned URLs: https://developers.cloudflare.com/r2/api/s3/presigned-urls/
- CORS para alternativa directa: https://developers.cloudflare.com/r2/buckets/cors/
- Compatibilidad Laravel S3: https://laravel.com/docs/12.x/filesystem
