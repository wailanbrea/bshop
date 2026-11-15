# QA tablet — BShop, negocio único

Actualización: 2026-11-13. La tablet es el dispositivo principal del cliente.
Alcance: pantallas existentes de acceso, panel, sucursales, almacenes, equipo,
invitaciones, configuración, catálogo, CSV, fotos, inventario, clientes, venta y apartados.
No acredita módulos pendientes ni aceptación en hardware físico.

## Problemas encontrados

- Selector de sucursal de 11 px y campos de 13 px: lectura pequeña y riesgo de
  zoom automático de foco en Safari.
- Diálogos personalizados con desplazamiento de fondo y acciones al final del
  formulario, que podían quedar fuera del área visible al abrir teclado.
- Menú oculto en tablet vertical, elementos fuera de pantalla aún enfocables y
  ausencia de cierre/foco consistentes.
- Confirmación de guardado perdida al recrear la página después de actualizar datos.
- Retorno de foco al cerrar formularios fallaba en WebKit táctil.
- Pattern HTML del código de sucursal incompatible con el modo Unicode `v` de
  Chromium actual: corregido y cubierto con códigos válidos/inválidos.

## Correcciones verificadas

- Campos/selectores de 16 px; acciones principales de 48 px; ningún objetivo
  interactivo visible de las pruebas por debajo de 44×44 px.
- Texto de operación más legible, contraste mejorado y zoom de usuario permitido.
- Rail de navegación con iconos y etiquetas en 768–1199 px; menú completo
  desplegable, cierre explícito, Escape, aislamiento del fondo y control de foco.
- Cabecera persistente con sucursal activa accesible al desplazar listas largas.
- `AppDialog` nativo compartido: fondo inerte, cuerpo desplazable, cabecera y
  acciones fuera del scroll, cierre/restauración de foco y prevención de doble envío.
- `VisualViewport` actualiza alto/offset de los diálogos, respetando pinch zoom.
- Teclado se abre al tocar un campo; el título recibe foco inicial al abrir formulario.
- Borradores se conservan al cambiar orientación; errores no borran los campos.
- Teléfono/correo con teclados apropiados; código sin autocorrección.
- Formulario largo de 60 sucursales: se alcanza la última opción sin perder acciones.
- Mensaje de éxito permanece después de guardar.

## Matriz automatizada

Última regresión de compensación física: **658/658**, seis perfiles,5,8 minutos.
Treinta casos nuevos de giro/motivo, respuesta perdida/recuperación por GET, stock
obsoleto, permiso y bloqueo económico/stock. Ejecución focalizada30/30,23,8s.

Última regresión de reversión de aplicaciones: **628/628**, seis perfiles,5,8 minutos.
Treinta casos nuevos: motivo al girar, respuesta perdida/GET al volver al pedido,
revisión obsoleta conservando motivo, permiso independiente y bloqueo de crédito
consumido. Diálogos secuenciales e historial con aplicación original y compensación.
Ejecución focalizada:30/30,23,8s. APIs interceptadas, sin movimientos comerciales.

Última regresión comercial: **598/598**, seis perfiles,5,1 minutos. Sesenta casos
del dashboard/historial: un h1 y una pantalla por ruta, gráficos SVG área/barras,
datos exactos accesibles, cambio de moneda, filtros/giro, historial/CSV, permisos,
errores recuperables y tabla con nombres/referencias extensos/importes máximos.
Se corrigió desbordamiento WebKit causado por encabezado accesible absoluto fuera
del contenedor desplazable. Prueba focalizada de tabla:6/6; vista manual conservada
al girar, datos completos y acciones de al menos44×44. Capturas de los seis perfiles.

Última regresión de comprobantes privados: **538/538**, seis perfiles,5 minutos.
Treinta y seis casos nuevos: descarga repetida, preparación503, descarga fallida y
reintento del mismo documento sin repetir dinero, controles táctiles y permiso PDF.
Ejecución focalizada de estos escenarios:36/36. APIs interceptadas sobre build real.

Última regresión con aplicación de saldo: **502/502**, seis perfiles,4,5 minutos.
Treinta casos nuevos: selección/importe/motivo al girar, respuesta perdida y
recuperación por GET, revisión obsoleta, consulta sin permiso de aplicación y
venta directa sin dinero nuevo. Ejecución focalizada Ventas/Apartados270/270.

Regresión actual de conciliación económica: **472/472** en seis perfiles,4 minutos.
Veinticuatro casos nuevos: aprobación con giro, registro manual de reembolso,
recuperación del reembolso perdido al reabrir la conciliación por GET, conflicto
de revisión conservando motivo y consulta sin permisos financieros. Las ventanas
de recepción y conciliación se abren secuencialmente con acciones fuera del scroll.

Última regresión con recepción física de devoluciones: **448/448**, seis perfiles,
5,4 minutos. Treinta casos nuevos: cantidad/destino al girar, recepción perdida sin
duplicar ingreso, revisión obsoleta, consulta sin permiso de recepción y venta
directa. El subtotal/cobro original se conserva y la conciliación pendiente se
identifica;216 casos de Ventas/Apartados pasaron además la ejecución focalizada.

Última regresión financiera con historial/reversiones: **418/418**, seis perfiles,
6,4 minutos. Motivo conservado al girar, recuperación por GET al volver al pedido,
conflicto con versión nueva, consulta sin permiso de reversión y saldo de venta
directa actualizados. Treinta casos nuevos;186 de ventas/apartados pasaron también
la ejecución focalizada antes de la regresión completa.

Última regresión con cuentas semanales: **388/388** en seis perfiles,5 minutos.
Veinticuatro casos nuevos: agrupación y cierre explícito con giro/teclado, recarga
tras respuesta perdida sin repetir factura, revisión obsoleta y creación/anulación
de cuenta vacía. El selector de sucursal se bloquea durante operaciones pendientes.

Regresión actual con catálogo/carrito por enlace: **364/364** en seis perfiles,
4,5 minutos. Incluye autorización privada de variantes al girar, carrito público,
revisión de precios de servidor, confirmación y recarga tras respuesta perdida
sin reenvío. Pulido final de precio unitario revalidado en18/18 casos públicos.

Última regresión con enlaces temporales de consulta: **340/340** en seis perfiles,
3 minutos. Generación/revocación y reintento con igual UUID probados al girar;
compartir se bloquea con artículos pendientes de guardar. La página pública Blade
se revisó con datos sintéticos de QA a 1440/1024/768/390/320 px, sin overflow.

`BSHOP_BROWSER_OFFLINE=1 npm run test:tablet`: **316 pruebas aprobadas** en la
revalidación integral, incluidos clientes, vencimientos, inventario y Apartados.
Tras mejorar consultas de clientes: **102/102** casos de clientes/ventas y
**78/78** de Apartados aprobados. Incluyen dos escenarios nuevos × seis perfiles:
reintentar la página fallida sin perder ficha y bloquear resultados anteriores
mientras se busca, conservando el texto al girar.
Selector 2×2/4×4/6×6: persistencia, paginación y borradores cubiertos en Apartados.
El hito previo tuvo184 y una revalidación54/54 de ventas. El hito
inicial de adaptación tablet tuvo 32 casos; se conserva su evidencia en PROGRESO.

| Perfil | Motor | Viewport inicial | Interacción |
|---|---|---|---|
| iPad vertical | WebKit 27.2 | 768×1024 | Touch / perfil iPad Mini |
| iPad horizontal | WebKit 27.2 | 1024×768 | Touch / perfil iPad Mini |
| Tablet Android vertical | Chromium | 800×1280 | Touch / viewport móvil |
| Tablet ancha horizontal | Chromium | 1280×800 | Touch / viewport móvil |
| Escritorio | Chromium | 1440×1000 | Ratón |
| Teléfono | WebKit / iPhone 13 | 390×844 | Touch |

Escenarios: páginas y hit targets, giro con borrador, menú/foco, formulario largo,
validación/doble toque, sucursal persistente al desplazar, acceso/invitación y
fallback móvil. Se redujo el viewport a **1024×380** y **390×340** para simular
el espacio que queda con teclado, además de rotar entre vertical/horizontal.

Ventas/Apartados: total97 y pendiente96, alta rápida, variante requerida/sin stock,
guardado y reapertura, pérdida de respuesta/retry, recuperación tras recarga, formularios
únicos al conservar vistas y borradores separados por sucursal. Se verifica también
que en móvil los totales queden después de las líneas y las acciones después de los
totales, sin superposición. En tablet horizontal el resumen conserva acciones visibles
y cuerpo desplazable; en vertical/móvil el cliente/pedido se accede por pestañas.

Capturas de la demo real actual en `E:/KEYLASHOP/.playwright-mcp`:
`keylashop-venta-{1440,1024,768}-final.png`,
`keylashop-apartado-{1440,1024,768}-final.png`,
`keylashop-apartado-mobile-productos-final.png` y
`keylashop-apartado-mobile-resumen-final.png`. Las capturas de escritorio/tablet
usan el navegador MCP con API real; las pruebas WebKit son la cobertura automatizada.

Referencia vigente: **Apartados = un grid superior y detalle debajo**, sin navegación
al seleccionar tarjeta. Capturas posteriores `keylashop-tablero-apartados-{1440,1024,768,390}.png`
reemplazan las vistas de apartado anteriores. Se midió grid.bottom <= detail.top y
ningún scroll horizontal, alternando selección con cero navegaciones/reloads.
Ocho escenarios específicos por seis perfiles cubren edición y depósito, búsqueda
de variante, alias, borradores/giro/conflicto, recuperación de guardado, envío interno
opcional y configuración sin mensajes. Aceptación en tablet física sigue pendiente.

La suite intercepta **todas** las rutas `/api/v1/**` con datos controlados; no
ejecuta migraciones ni modifica una base del cliente. Bloquea escrituras no
previstas. El servidor sólo sirve el frontend y establece CSRF. Esto permite
regresión de UI reproducible sin credenciales reales.

## Comprobación adicional con la demo real

http://127.0.0.1:8017, entorno local, archivo
`database/bshop-business.sqlite` (sin conexión remota).

- Login y navegación de KeylaShop correctos; no hay selector/alta de empresas.
- Abrir edición de Sucursal de prueba, cambiar de orientación sin modificar
  valores y guardar el mismo contenido: **PATCH 200**, confirmación visible.
- Con viewport alto 380 px: diálogo y acciones entre y=16 y y=364; Cancelar de
  48 px de alto visible. Fondo bloqueado y campos de 16 px.
- Validación del código con guion/underscore funciona; consola final sin errores.
- Sin desbordamiento horizontal en tablet vertical/horizontal.

Capturas en `E:\KEYLASHOP\.playwright-mcp`:
- `bshop-tablet-portrait.png`
- `bshop-tablet-landscape-final.png`
- `bshop-tablet-login-portrait-final.png`

## Ejecutar la regresión

```powershell
npm ci
npm run browsers:install
npm run build
npm run test:tablet
```

Los motores se almacenan en `.runtime/playwright-browsers`, excluidos de Git.
El runner puede iniciar un servidor local protegido con DB SQLite :memory: o
reutilizar la demo existente. El reporte HTML queda en `playwright-report`;
fallos generan captura/trace bajo `test-results/tablet` (artefactos ignorados).
El workflow de CI incorpora los mismos casos; su ejecución remota está pendiente.

## Aceptación física pendiente

WebKit automatizado no es Safari ejecutándose en el iPad real. Falta verificar en
la tablet del cliente: teclado nativo/selector del sistema, barras de Safari,
rotación física, safe areas, pinch zoom y cámara/selector de fotos cuando exista
el módulo. Repetir con las pantallas comerciales nuevas y los datos del piloto.

## Regresión del catálogo

`tests/Browser/catalog.spec.ts` agrega búsqueda por SKU, alta con strings decimales,
varias variantes con valores compartidos, giro y viewport reducido, 409 sin pérdida
de borrador, organización de atributos/valores, permisos de vendedor y volver a
la lista mientras termina un refresco lento. API interceptada en esta suite.

Demo real: categorías y tallas creadas desde UI; producto con M/L responde 201,
edición a precio 1300.0001 responde 200 y se muestra sin perder decimales.
Búsqueda DEMO-CAM-L devuelve un producto activo, archivo/reactivación conservan
variantes. Capturas `bshop-catalog-tablet.png` y `bshop-catalog-detail-tablet.png`
en `E:\KEYLASHOP\.playwright-mcp`.

## Regresión CSV y demo real

`tests/Browser/import.spec.ts` prueba revisión sin escrituras de productos, giro,
errores que bloquean el lote, descargas de plantilla/reporte, respuesta perdida
y reintento del mismo ID, historial/páginas/revalidación y reversión segura.
La barra de acciones usa nombre/resumen del archivo y queda fija sobre el borde
inferior; se corrigió que podía desaparecer al girar desde vertical.

Las pruebas browser usan API interceptada. Se complementaron en la demo SQLite:
preview de dos filas →201 y cero errores; confirmación →200; dos reintentos →200
con mismos IDs y tres productos totales (uno anterior más dos importados).
Reversión por UI →200; tres registros conservados, sólo el original activo.
No se sembraron stock/ventas/pagos. Archivo de prueba y captura:
`E:\KEYLASHOP\.playwright-mcp\bshop-import-smoke.csv` y `bshop-csv-import-tablet.png`.
