# Protocolo local — BShop

## Arquitectura vigente

El propietario confirmó que BShop se entrega como una instalación para **un solo
negocio y sus sucursales**. KeylaShop es el negocio de esta instalación.
No desarrollar tenants, membresías multiempresa, alta/selector de otras empresas,
suscripciones SaaS ni panel de administrador de plataforma.

Prioridad documental: `docs/ARQUITECTURA_VIGENTE.md`, README, API/DESARROLLO y
`qa/12_TODO_MAESTRO.md`. Las fuentes iniciales bajo `docs/referencia` y los
apartados antiguos de PROGRESO son antecedentes y no sustituyen esta decisión.

## Desarrollo

Conservar permisos por usuario/acción y acceso explícito a sucursales. Usar
StoreContext para operaciones de una sucursal; el perfil único no requiere
identificador de empresa en consultas o rutas.

## Prioridad tablet

Referencia visual y funcional de Ventas/Apartados: `docs/REFERENCIA_VISUAL.md` y
`docs/TODO_UI_VENTAS_APARTADOS.md`, paquete original en `E:/KEYLASHOP/Nueva carpeta`.
Verificar también escritorio/móvil. Ejemplos únicamente en demo local, con datos
coherentes creados y comprobados a través de los flujos reales de la aplicación.

Corrección vigente: menú **Apartados**, sin módulo duplicado «Canastas». Una vista
con grid arriba y detalle reactivo debajo. Ver `docs/TABLERO_APARTADOS.md` y
`docs/TODO_TABLERO_APARTADOS.md`. WhatsApp automático es configurable/opcional por
instrucción expresa; no fingir envío si está desactivado.

La tablet es el dispositivo principal del cliente. Diseñar y verificar cada
pantalla nueva en horizontal y vertical, sin esperar a una fase final de UI.
Controles principales táctiles de al menos 48 px (ningún objetivo menor de
44×44 px), campos de 16 px para evitar zoom de foco en Safari, texto legible,
sucursal activa siempre accesible y zoom de usuario permitido.

Usar `AppDialog` para formularios superpuestos: acciones fuera del cuerpo
desplazable, foco/restauración y fondo inerte. Conservar borradores al girar la
tablet y verificar viewport reducido por teclado. Ejecutar `npm run test:tablet`
tras cambios de navegación/formularios. La emulación WebKit/Chromium no sustituye
la aceptación en la tablet física del cliente.

No declarar un módulo terminado porque exista su tabla. Registrar comandos,
destino de pruebas, resultado real y pendientes en `docs/PROGRESO.md`.

## Datos y pruebas

Vista previa local: `database/bshop-business.sqlite`; pruebas rápidas: SQLite
`:memory:` con guardia en TestCase. MySQL aislado: 127.0.0.1:33078,
`bshop_testing_single`. Verificar destino antes de cualquier operación SQL.
No refrescar datos de cliente ni usar pruebas destructivas contra VPS/producción.
Conservar respaldos y verificar la copia antes de una conversión.

Las operaciones futuras de stock/pedidos/imágenes deben coordinar sus escrituras
con los locks de producto/variante y revalidar estado después de esperar: la
reversión de importaciones sólo archiva productos sin cambios ni uso. No escribir
saldos o referencias saltándose esos servicios y su orden de bloqueos.
