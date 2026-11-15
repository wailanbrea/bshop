# Fotografías del catálogo — negocio único

## Uso

Abrir Catálogo → producto → **Administrar fotos**. El vendedor dispone de **Ver fotos**.
Se puede elegir archivo o cámara, consultar el estado de una carga, reintentar,
elegir principal, ordenar con botones táctiles, describir y asociar a una variante.
La lista de variantes usa la página actual del detalle; las asociaciones existentes
de otra página se identifican por ID. Abrir la página de esa variante antes de
asignarle una foto nueva.

- Hasta **5 fotos por producto**, contando variantes y cargas en proceso.
- Hasta **10 MiB por original**, **20 MP** y 20 000 px por dimensión; se aplica
  también un presupuesto de memoria antes de decodificar.
- JPEG, PNG y WebP estáticos, verificados por contenido. HEIC/HEIF, SVG, GIF y
  animaciones PNG/WebP se rechazan con mensaje. Exportar HEIC a JPEG primero.
- GD aplica orientación EXIF y genera WebP de lado máximo **320/640/1600 px**,
  sin ampliar originales pequeños, conservando transparencia y sin copiar EXIF.
- La foto principal aparece en la tarjeta del catálogo. La galería conserva los
  borradores al girar y usa AppDialog con acciones fuera del scroll y foco restaurado.

## Flujo, integridad y espacio

El original y el trabajo intermedio permanecen en `storage/app/media/staging`,
fuera de `public`. Se eliminan después del procesamiento y su ausencia se verifica.
El reconciliador puede completar esa limpieza tras una interrupción.

1. Reserva atómica de cupo/bytes bajo locks de producto y cuota.
2. Decodificación, derivados, subida y verificación **fuera de transacciones SQL**.
3. Publicación atómica de la galería, auditoría y versión de producto.

La cuota es **2 GiB (2147483648 bytes)** del único negocio y suma bytes listos,
pendientes y retenidos. Antes de publicar se ajusta la reserva a los bytes reales
de los tres derivados. Si falla el proveedor, los objetos parciales se compensan;
si su ausencia no puede verificarse, el espacio sigue reservado.

El UUID de petición queda ligado al autor/producto/variante y SHA-256 del original.
Repetir una carga finalizada devuelve el resultado existente. Un fallo recuperable
permite reusar el mismo UUID; una foto retirada requiere una nueva carga.

Cada UUID usa `flock` en almacenamiento privado durante el procesamiento y la
compensación: el sistema operativo libera el lock al morir el proceso. Esta entrega
asume **un servidor con almacenamiento local compartido entre sus procesos PHP**;
una instalación con varios servidores requeriría coordinar ese lock entre nodos.
Los locks SQL usan lecturas actuales para cupos, incluso después de esperar bajo
MySQL REPEATABLE READ. La reversión CSV continúa bloqueada ante referencias media.

Retirar una foto hace soft delete y mueve sus bytes de listos a retenidos. Se
conservan **24 horas** antes de ser elegible para purga; sólo la eliminación
verificada libera cuota. Se conserva el historial SQL. Una URL pública ya cacheada
por navegador/CDN puede persistir después del retiro; retirar no equivale a revocar
copias cacheadas.

## Almacenamiento local y R2

`MEDIA_PROVIDER=local` es la configuración actual de la demo. Los derivados se
guardan en `storage/app/media/catalog`. La ruta pública local entrega únicamente
assets listos, enlazados a un producto activo y con hash/rendición correctos.
No crear enlaces públicos al staging ni a la carpeta completa de media.

Para una futura activación R2 se prepararon estas variables en `.env.example`:

```dotenv
MEDIA_PROVIDER=r2
R2_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
R2_ACCESS_KEY_ID=<clave-del-bucket>
R2_SECRET_ACCESS_KEY=<secreto>
R2_CATALOG_BUCKET=<bucket-catalogo>
R2_PUBLIC_URL=https://<dominio-publico-catalogo>
```

El adaptador utiliza S3 PutObject sin ACL, Content-MD5, Content-Type WebP,
metadato SHA-256 y verificación HeadObject. Claves inmutables:
`products/{producto}/{uuid}/v1/{thumb|card|detail}.webp`.
Endpoint y dominio R2 requieren HTTPS. Cambiar proveedor no migra objetos anteriores:
cada rendición conserva su proveedor y namespace.

**Estado verificado:** SDK instalado y adaptador configurable; bucket, credenciales,
dominio, permisos, política de caché y llamadas R2 reales **pendientes**. No se
crearon recursos ni se publicaron objetos remotos.

## Operación

Desde la raíz, usando `.runtime/php83/php.exe` en este equipo:

```powershell
& ".\.runtime\php83\php.exe" artisan bshop:media-status
& ".\.runtime\php83\php.exe" artisan bshop:media-reconcile
```

`media-status` no consulta DB ni proveedor y nunca muestra secretos. El segundo
comando imprime entorno/conexión/host/base y sólo inspecciona por defecto.

En un destino ya verificado, `bshop:media-reconcile --apply` recupera cargas vencidas
(lease de 15 min más 3 min de gracia), reintenta compensaciones, purga hasta 20
assets retirados elegibles y revisa hasta 20 trabajos privados terminados. Una carga
con lock de proceso activo no se compensa. Ejecutar periódicamente para liberar
espacio retenido; **la tarea persistente del sistema operativo aún no está instalada**.

PHP requiere GD con WebP, Fileinfo y EXIF. Reiniciar el proceso HTTP tras habilitar
EXIF; comprobar la carga JPEG desde HTTP, además del diagnóstico CLI.

## Verificación ejecutada

- PHP: 74 pruebas / 544 aserciones en SQLite `:memory:` (15 pruebas de media).
- Tablet: 104 casos WebKit/Chromium; API interceptada para no escribir en la demo.
- MySQL 8.4.13 aislado: migración conservadora, upload/replay, principal único,
  cuota/retiro y dos procesos disputando el quinto cupo → **201/422**.
- HTTP real local: JPEG 80×40 → **201**, WebP → **200**, retiro → **200**;
  192 bytes retenidos temporalmente, sin original público ni ampliación.
- Pint y TypeScript/build aprobados. R2 real y tablet física siguen pendientes.
