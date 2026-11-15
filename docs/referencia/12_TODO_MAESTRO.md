# TODO MAESTRO — KEYLASHOP v3.0

**Este archivo es el tablero único de control de Codex.** Marcar `[x]` solo con evidencia en `docs/PROGRESO.md`. La documentación está preparada, la implementación del sistema **NO**. Trabajar en secuencia F0→F14. No alterar retroactivamente el historial de ítems.

## F0 — Descubrimiento y validación de la base
- [ ] F0.01 Detectar si ya existe repositorio KeylaShop y listar carpetas/proyectos previos.
- [ ] F0.02 Documentar versión real PHP, Laravel, MySQL, Node, Composer, Apache y Windows Server.
- [ ] F0.03 Leer todos los documentos y registrar contradicciones con código existente.
- [ ] F0.04 Validar SQL v3 en MySQL 8 **de prueba**; registrar errores y corregir.
- [ ] F0.05 Diseñar diagrama ER y comprobar las FK compuestas e índices con INFORMATION_SCHEMA.
- [ ] F0.06 Registrar decisiones abiertas prioritarias, sin inventar reglas fiscales o de comisión.
- [ ] F0.07 Crear `docs/PROGRESO.md` con plantilla de evidencia y plan de fases.
- [ ] F0.08 Definir repositorio, branch develop, CI inicial y convención de commits.

### Criterio de salida F0
- [ ] SQL revisado y DDL MySQL real probado o blocker documentado explícitamente.
- [ ] Informe de dependencias y riesgos del código existente.

## F1 — Proyecto base y seguridad multiempresa
- [ ] F1.01 Crear Laravel 12 y frontend Vue 3/TS/Vite/Tailwind (o adaptar existente sin sobrescribir).
- [ ] F1.02 Configurar `.env.example` sin secretos, APP_DEBUG, sesiones y CSRF.
- [ ] F1.03 Implementar autenticación y recuperación de acceso seguras.
- [ ] F1.04 Tenant context activo, memberships, roles, políticas y sucursales autorizadas.
- [ ] F1.05 Implementar middleware tenant/sucursal para endpoints, jobs y audit logs.
- [ ] F1.06 Usuario dueño y platform admin separados; auditoría de acciones privilegiadas.
- [ ] F1.07 CRUD tenant/store/warehouse y asistente inicial del negocio.
- [ ] F1.08 Tests cross-tenant/branch y roles.

### Criterio de salida F1
- [ ] QA-001 y QA-002 y acciones no autorizadas sin fuga.

## F2 — Migraciones MySQL, seeds y consistencia
- [ ] F2.01 Convertir referencia SQL v3 en migraciones Laravel ordenadas y reversibles.
- [ ] F2.02 Registrar versiones de constraints e índices únicos compuestos.
- [ ] F2.03 `migrate` desde BD vacía + `rollback` en BD de testing, sin usar producción.
- [ ] F2.04 Crear factories/seeders con 2 tenants, 2 almacenes, 2 clientes, varias variantes y empleados.
- [ ] F2.05 Confirmar enforcement real de CHECK/FK en MySQL, no SQLite.
- [ ] F2.06 Revisar logs de SQL y `EXPLAIN` de consultas críticas.
- [ ] F2.07 Actualizar diagrama ER y diccionario de datos con estado probado.

### Criterio de salida F2
- [ ] Migraciones MySQL reales y pruebas FK pasan.

## F3 — Catálogo genérico
- [ ] F3.01 CRUD productos tipos físico/servicio, activo/archivado y categorías jerárquicas.
- [ ] F3.02 CRUD marcas, atributos y valores por tenant.
- [ ] F3.03 Crear variantes canónicas y SKU/barcode únicos.
- [ ] F3.04 Validar que precio/costo sean DECIMAL con snapshots de líneas.
- [ ] F3.05 Listados paginados, búsqueda por SKU/nombre y filtros.
- [ ] F3.06 Importación CSV con preview/dry-run, errores fila a fila, reversión controlada.
- [ ] F3.07 Tests producto sin variante, varias tallas/colores, servicio sin stock, SKU repetido y aislamiento.

### Criterio de salida F3
- [ ] CRUD e importación seguros, datos de rubros distintos.

## F4 — Cloudflare R2 e imágenes
- [ ] F4.01 Verificar configuración R2 Standard/buckets/credenciales de alcance mínimo y costos.
- [ ] F4.02 Configurar `r2_catalog`, `media_staging` privado, `media_private` y fallback de testing.
- [ ] F4.03 Implementar `ObjectStorage` y `ImageProcessor`, sin credenciales cliente.
- [ ] F4.04 Endpoint de subida con límite 10 MiB, validación de bytes reales y de píxeles.
- [ ] F4.05 Generar WebP thumb/card/detail y borrar EXIF/GPS.
- [ ] F4.06 Implementar sesión de upload, estados, fallos y reconciliación.
- [ ] F4.07 Cuota 2 GiB por tenant, bytes_pending, bytes_ready y máximo 5 fotos por producto.
- [ ] F4.08 Bloquear concurrencia del conteo de imágenes y cuota bajo FOR UPDATE.
- [ ] F4.09 Conectar custom domain de imágenes publicadas; archivos privados nunca públicos.
- [ ] F4.10 UX de galería, carga, reordenación, foto principal, errores, borrar.
- [ ] F4.11 Tests QA-018...QA-024 y smoke test Safari iPad.

### Criterio de salida F4
- [ ] Foto real llega a R2, tres derivados, URL pública y cuota verificada; fallo no expone archivos.

## F5 — Inventario, almacenes y kardex
- [ ] F5.01 Implementar stock_balances, inventory_movements con event_key.
- [ ] F5.02 Entradas y ajustes auditados, nunca modificar stock sin movimiento.
- [ ] F5.03 Reservas por variante y almacén con DB transaction + FOR UPDATE.
- [ ] F5.04 Liberar, expirar y entregar parcial de modo idempotente.
- [ ] F5.05 Transferencias de almacén y control de sucursal.
- [ ] F5.06 Servicio sin stock y artículo no rastreable bypass controlado.
- [ ] F5.07 Reconciliar `reserved` vs reservas activas y kardex vs balances.
- [ ] F5.08 Tests QA-003/004/005/028 y simulación concurrencia real.

### Criterio de salida F5
- [ ] Cero sobreventa y ledger consistente.

## F6 — Clientes, pedidos y apartados manuales
- [ ] F6.01 CRUD clientes y búsqueda por nombre/teléfono.
- [ ] F6.02 Crear pedido borrador; líneas con snapshot, montos y vendedor.
- [ ] F6.03 Reserva atómica con item variant, warehouse, qty y event key.
- [ ] F6.04 Editar apartados autorizados sin reservas duplicadas; manejar cambios parciales.
- [ ] F6.05 Cancelar/entregar y log de auditoría.
- [ ] F6.06 Vincular cuenta semanal, evitando periodos superpuestos.
- [ ] F6.07 API de ventas rápidas para interfaz táctil.
- [ ] F6.08 Tests de permisos, precio incorrecto, cancelaciones y concurrencia.

### Criterio de salida F6
- [ ] Flujo manual cliente→producto→apartar funcionando con inventario real.

## F7 — Carrito por enlace temporal
- [ ] F7.01 Generar token secreto seguro, guardar hash, caducidad, revocación.
- [ ] F7.02 Catálogo público filtrado por tenant y visibilidad/autorización.
- [ ] F7.03 Carrito con variantes y cantidades; validación de precios al confirmar.
- [ ] F7.04 Confirmación única bajo lock, marca link used al commit, error seguro.
- [ ] F7.05 Cliente identificado/nuevo según decisión aprobada, sin acceso a cuentas ajenas.
- [ ] F7.06 Resumen de pedido y compartir manualmente por WhatsApp.
- [ ] F7.07 Tests QA-010/011/026, expiry, revoked, GET preview, replay/rate limits.

### Criterio de salida F7
- [ ] GET inocuo y POST único incluso en doble toque/concurrencia.

## F8 — Cuentas semanales y facturas PDF
- [ ] F8.01 Configurar zona horaria, día de cierre y ventanas semanales.
- [ ] F8.02 Agregar pedidos a cuentas sin repetir líneas ni cruzar sucursales.
- [ ] F8.03 Pantalla revisión/cierre con autorización explícita.
- [ ] F8.04 Cierre idempotente y factura única con secuencia bajo lock.
- [ ] F8.05 Factura de pedido directo excluye pedido del cierre semanal.
- [ ] F8.06 PDF con nombre tienda, cliente, artículos, descuentos, envío, abonos, saldo.
- [ ] F8.07 Guardar documento histórico y políticas de modificación/ajuste.
- [ ] F8.08 Compartir PDF vía hoja de compartir/WhatsApp sin API de pago.
- [ ] F8.09 Tests QA-008/009/012 y fechas de cierre alrededor de medianoche.

### Criterio de salida F8
- [ ] La misma operación de cierre repetida devuelve única factura con PDF correcto.

## F9 — Cobros, crédito y devoluciones
- [ ] F9.01 Registro de pagos recibidos, estado y validación decimal.
- [ ] F9.02 Ledger de crédito del cliente por pago y sucursal.
- [ ] F9.03 Asignaciones facturas con bloqueo, suma y moneda/sucursal.
- [ ] F9.04 Abonos previos y posteriores a factura y conciliación.
- [ ] F9.05 Devoluciones/returns, reintegro de stock y reembolso idempotente.
- [ ] F9.06 Auditoría de ajustes, anulaciones y reportes de CxC.
- [ ] F9.07 Tests QA-006/007/029 y devolución mayor que venta.

### Criterio de salida F9
- [ ] Saldo a favor/cobrado/pending nunca se contradicen.

## F10 — Vendedores, contratos y nómina
- [ ] F10.01 CRUD empleados y permisos/sucursal/vínculo user opcional.
- [ ] F10.02 Plan salario/periodo o comisión/porcentaje, validación de vigencia sin solapamientos.
- [ ] F10.03 Asociación de vendedor por venta/línea y snapshot de contrato.
- [ ] F10.04 Devengar comisiones proporcionalmente a cobros aplicados y elegibles.
- [ ] F10.05 Reversión por devolución y rechazo de duplicados.
- [ ] F10.06 Nominas por periodo, salary amount/commission entries/ajustes/pago parcial.
- [ ] F10.07 Una entrada de comisión se vincula a una sola nómina.
- [ ] F10.08 Historial y reporte vendedor con permisos granulares.
- [ ] F10.09 Tests QA-013/014/015/016/017/030 y cambio modalidad en fecha.

### Criterio de salida F10
- [ ] No se paga por apartar, ni se paga comisión dos veces; historial persistente.

## F11 — Frontend tablet/PWA y accesibilidad
- [ ] F11.01 Definir tokens de diseño y layout adaptativo en Vue.
- [ ] F11.02 Dashboard y menú; datos tenant/branch visibles.
- [ ] F11.03 Catálogo táctil productos y variantes, galería de imágenes.
- [ ] F11.04 Pantalla tres zonas seleccionar cliente, productos y resumen de apartado.
- [ ] F11.05 Cierre semanal, facturas, pagos y vendedor.
- [ ] F11.06 Carrito público y confirmación protegida.
- [ ] F11.07 Pantallas loading/empty/error/403/409/429 y conexión lenta.
- [ ] F11.08 PWA manifest/icons/service worker sin mutaciones offline de inventario.
- [ ] F11.09 Tests QA-025/026 en Safari iPad vertical/horizontal y Chrome Android.
- [ ] F11.10 Accesibilidad, targets táctiles >=44px, foco y errores claros.

### Criterio de salida F11
- [ ] Flujo principal usable por persona no técnica desde iPad, sin scroll lateral obligatorio.

## F12 — SaaS comercial, reportes y operación
- [ ] F12.01 Planes y suscripciones con snapshots de precio y límites.
- [ ] F12.02 Feature flags por plan/tenant, cuota R2 y límites de usuarios/sucursales.
- [ ] F12.03 Panel platform admin separado del panel comercial.
- [ ] F12.04 Suspender contratación sin secuestrar/exportar datos.
- [ ] F12.05 Reportes ventas cobrado/facturado/reservado, stock y vendedor.
- [ ] F12.06 Outbox dispatcher, jobs/retry, limpieza media, expiración enlaces/reservas.
- [ ] F12.07 Auditoría trazable de cambios de precios, ventas, stock, payroll.
- [ ] F12.08 Alertas operativas y métricas de facturación/almacenamiento.

### Criterio de salida F12
- [ ] Multiempresa operable con roles, cuotas y reportes sin mezclar datos.

## F13 — QA integral y rendimiento
- [ ] F13.01 Ejecutar matriz QA-001 a QA-030 con evidencia verificable.
- [ ] F13.02 Ejecutar smoke y end-to-end en MySQL real y R2 staging.
- [ ] F13.03 Pruebas de seguridad/aislamiento y control de secretos.
- [ ] F13.04 Prueba con 500+ productos y 100 clientes piloto, sintéticos.
- [ ] F13.05 Medir consultas, p95, consumo de memoria y tamaños WebP.
- [ ] F13.06 Ejecutar restauración de BD + imágenes y comprobar consistencia.
- [ ] F13.07 Resolver blockers críticos y preparar reporte de release.

### Criterio de salida F13
- [ ] Cero bloqueantes; QA y restore probados en staging.

## F14 — Despliegue y entrega controlada
- [ ] F14.01 Confirmar aprobación de salida, términos comerciales y reglas del piloto.
- [ ] F14.02 Backup de VPS/BD y estrategia rollback; probar en staging.
- [ ] F14.03 Configurar Apache/HTTPS/.env/colas/scheduler sin afectar otros sitios.
- [ ] F14.04 Crear/configurar R2 catálogo y `media.bsolutions.dev` según permiso y cuenta.
- [ ] F14.05 Deploy versión con migraciones revisadas, sin `migrate:fresh`.
- [ ] F14.06 Smoke iPad producción: crear cliente, producto, foto, apartar, facturar, cobrar.
- [ ] F14.07 Guía usuario y guía técnico/soporte.
- [ ] F14.08 Monitoreo de backups, fallos y cuotas activo.
- [ ] F14.09 Hito aceptación firmado y lista de mejoras de siguiente release.

### Criterio de salida F14
- [ ] Producción estable, restore probado, soporte documentado.

## Plantilla de avance (por cada iteración)
```
Fecha UTC:
Fase y task IDs:
Cambios realizados:
Commit/rama:
Pruebas/comandos exactos:
Resultado real y evidencia:
Riesgos y bloqueos:
Checklist actualizado:
Siguiente fase/tarea:
```
