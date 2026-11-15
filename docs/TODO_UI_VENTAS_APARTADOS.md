# Ventas y apartados — ejecución de la referencia KeylaShop

Fuente indicada por el propietario: `E:/KEYLASHOP/Nueva carpeta/`.
Leídos `00_LEER_PRIMERO.md`, `01_PROMPT_MAESTRO_INTERFAZ_VENTAS_APARTADOS.md`
y ambas imágenes. No se copia marco físico ni barra de estado del iPad.

## F0 — Auditoría del 2026-11-13

- [x] Revisar README, arquitectura, protocolo, tablero vigente y esquema/código real.
- [x] Revisar referencias Venta (catálogo/resumen) y Nuevo apartado (cliente/catálogo/resumen).
- [x] Inventariar APIs y componentes reutilizables.
- [x] Registrar brechas: ver abajo.

Estado **al iniciar F0**: sesión Sanctum/CSRF, permisos, sucursal autorizada por StoreContext,
catálogo, variantes, importación, fotos/cuota y AppDialog. Rutas vigentes bajo
`/api/v1/business`, sucursales bajo `/stores/{store}`. El shell usa App.vue; no hay
router ni store externos. Inventario tiene esquema previo y servicios/UI actualmente
en implementación; aún no se consideran verificados. Clientes, pedidos, reservas,
facturas y pagos tienen tablas, pero no servicios/endpoints/pantallas comerciales.
El esquema exige cliente en pedido y conserva importes DECIMAL y snapshots.

No se localizaron documentos separados v3.0/v3.1; se dispone del prompt maestro
original y modelo relacional V3 bajo `docs/referencia`. Prevalece negocio único.
No hay logo oficial independiente en el paquete: marca tipográfica temporal.
El propietario confirmó posteriormente **USD / US$** como moneda del sistema.
Los nombres/cifras del mockup siguen siendo ejemplos visuales, no datos reales.
No hay WhatsApp oficial configurado ni autorización para inventar envíos.

## F1 — Sistema visual

- [x] Centralizar tema marino/violeta y estilos reutilizables.
- [x] Adaptar sidebar/topbar existentes, iconografía lineal y marca del negocio.
- [x] Verificar capturas y regresión en iPad horizontal/vertical, web y móvil.
- [x] Verificar vacíos, errores, legibilidad/contraste, teclado y objetivos táctiles.
- [ ] Aceptación final en la tablet física y sus navegadores nativos.

## Dependencia — Inventario

- [x] Completar y verificar saldos, entradas/salidas/conteos, transferencias y kardex.
- [x] Probar precisión, permisos y concurrencia; movimientos sin saldos negativos.
- [x] Integrar reserva/liberación/entrega con pedidos, sin reservar un borrador.

## F2 — Venta

- [x] Búsqueda real, categorías, grid/lista, fotos y selector de variantes.
- [x] Disponibilidad por variante y almacén de sucursal activa.
- [x] Pedido editable con cliente obligatorio conforme al esquema real.
- [x] Subtotal/descuento/envío/total calculados en servidor con decimales exactos.
- [x] Emisión idempotente con snapshots; compartir separado de la emisión.

## F3 — Apartados y canastas

- [x] Alta/búsqueda/consulta de clientes, alta rápida y selección inmediata.
- [x] Ficha del cliente con compras/saldo/notas autorizados.
- [x] Borrador cliente/productos/resumen sin efectos de inventario.
- [x] Guardar reserva atómica y reabrir canasta persistida.
- [x] Liberar/cancelar canastas compatibles y entregar parcialmente con auditoría/idempotencia.
- [x] Abonos auditables sin superar saldo; no imponer una canasta única por cliente.
- [ ] Edición/archivo de clientes y devoluciones/reembolsos que resuelvan canastas con dinero/entregas previas.

## F4 — Cierre y compartir

- [x] Factura/PDF histórico, sin duplicación por doble toque/reintento.
- [x] Compartir manualmente con estado veraz cuando no hay integración oficial.
- [x] Separar fallos de PDF/compartir de la emisión; reutilizar la misma factura.
- [x] Implementación WhatsApp oficial configurable/opcional, confirmada posteriormente; ver TABLERO_APARTADOS.md.
- [ ] Activación opcional de número/plantilla real y webhook HTTPS.

## F5 — Demostración y QA solicitados

- [x] Preparar datos locales de ejemplo identificados, coherentes y auditables.
- [x] Verificar USD/US$ en catálogo, apartados, abonos, facturas y demostración.
- [x] Dar de alta productos/fotos/variantes/clientes mediante flujos reales.
- [x] Justificar existencias con entradas y crear operaciones de cliente razonables.
- [x] Validar ejemplo 25+45+22+5=97 y apartado 25+45+22+49+5−50=96.
- [x] Dos usuarios compiten por última unidad: sólo uno reserva.
- [x] Cliente nuevo, variantes, producto sin foto, sin stock, fallos API/red y doble toque/replay.
- [x] WebKit/iPad horizontal y vertical, Android tablet, web y móvil; capturas reales.
- [x] Documentar pruebas ejecutadas, errores corregidos y pendientes reales.

Resultado: 99 pruebas PHP /784 aserciones, regresión integral de 184 casos browser;
54 casos de ventas revalidados tras el último ajuste móvil. MySQL real aislado y
recorridos HTTP locales aprobados. Guías `VENTAS_APARTADOS.md`, `INVENTARIO.md` y
`DEMO_KEYLASHOP.md`; evidencia detallada en el último registro de `PROGRESO.md`.

Hito posterior vigente: tablero único Apartados, 108 pruebas PHP/926 aserciones y
232 casos browser. Reemplaza las tres zonas históricas para apartados; ver
`TODO_TABLERO_APARTADOS.md` y `TABLERO_APARTADOS.md`.

La demostración se limita a la instalación local, con respaldo previo. No se siembran
datos en VPS/producción. No cambiar impuestos, moneda, comisiones o credenciales para
imitar una imagen. Los checklists generales existentes se conservan.
