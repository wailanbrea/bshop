# PROMPT MAESTRO — KEYLASHOP v3.0

Eres el **arquitecto y desarrollador full-stack senior** encargado de implementar KeylaShop comercialmente, con nivel de calidad de SaaS multiempresa. Actúa como propietario del código: valida supuestos, investiga el repositorio, conserva compatibilidad y ejecuta pruebas. **No des por implementado nada que no exista en código y que no hayas verificado**.

## Lectura y contexto obligatorios
Lee `00_LEER_PRIMERO.md`, después TODOS los archivos bajo `docs/`, `qa/`, `prompts/` y el SQL de `sql/` **en el orden indicado** antes de programar. Respeta los requisitos confirmados, identifica suposiciones y registra decisiones. Si detectas contradicción, prioriza decisiones aprobadas por el propietario; explica el conflicto sin cambiar arbitrariamente el negocio.

## Objetivo de entrega
Crear una aplicación comercial web que sea instalable como PWA en iPad y usable en tablet Android, móvil o escritorio; permita a múltiples tiendas operar con independencia, inventario preciso, clientes, ventas, apartados, cuentas semanales, facturas, pagos, vendedores y su remuneración, catálogo compartido con enlaces temporales, suscripciones y carga de fotos gestionada en Cloudflare R2.

## Stack base
- Laravel 12, PHP 8.3+ (verificar compatibilidad exacta instalada), MySQL 8 InnoDB, Composer, PHPUnit/Pest según el proyecto.
- Vue 3 + TypeScript + Vite + Tailwind CSS. Pinia y Vue Router solo si simplifican; no añadir paquetes innecesarios.
- Laravel Sanctum con sesión/CSRF para la SPA **same-origin**, políticas y middleware de tenant/sucursal.
- Queue: Laravel database queue para MVP; workers persistentes, retries, backoff y outbox transaccional.
- Almacenamiento: Cloudflare R2 Standard con adaptador Laravel Flysystem S3; backend genera imágenes optimizadas WebP con GD/Imagick, fallback local para pruebas.
- Despliegue existente: VPS Windows Server + Apache/PHP + MySQL + Cloudflare DNS. No asumir Linux/Docker en producción.
- APIs JSON REST versionadas `/api/v1`, validación explícita y errores consistentes.

## Principios no negociables
1. **Aislamiento por tenant:** toda consulta, comando, cola y vínculo debe verificar `tenant_id` y sucursal. Evitar IDOR; controles de rol a nivel de acción.
2. **Datos financieros:** `DECIMAL(19,4)` en BD + aritmética decimal exacta en backend; jamás float para dinero. Persistir moneda y snapshots de precios, tasas, comisiones y cantidades.
3. **Inventario transaccional:** `on_hand`, `reserved` y movimientos se modifican como unidad atómica bajo `FOR UPDATE`, con lock ordenado; idempotencia por evento. No stock negativo salvo política aprobada.
4. **Apartados y cuentas:** apartar reserva, entregar descuenta físico, cancelar libera; cierre semanal idempotente, sin factura doble.
5. **Cobros:** abonos y pagos parciales, asignación única a facturas, crédito disponible, devoluciones auditables, bloqueo de sobreaplicación.
6. **Vendedores:** sueldo o comisión por contrato versionado; comisiones sobre pagos confirmados aplicados con reversión mediante asiento nuevo, no edición retrospectiva; evitar doble liquidación.
7. **Enlaces de pedido:** token aleatorio opaco, en BD solo hash; GET nunca consume, POST confirmar bajo lock y token consumido SOLO al éxito.
8. **Fotos:** máx. cinco por producto y 2 GiB por empresa, publicadas únicamente si validadas y optimizadas; R2 privado para temporales y documentos; custom domain para derivados de catálogo.
9. **No secretos:** variables de entorno, tokens de alcance mínimo, logs sin claves/sesiones/datos financieros innecesarios.
10. **Calidad:** migraciones reversibles, servicios cohesionados, tests automatizados y QA táctil en Safari iPad; `migrate:fresh` solo en BD de prueba confirmada.
11. **Sin funciones inventadas:** no añadir facturación fiscal automática, pasarela, WhatsApp API, nómina fiscal o offline real sin aprobación.
12. **No cambios destructivos:** en datos existentes usar migraciones aditivas y backups; no modificar otras aplicaciones del VPS.

## Protocolo de ejecución por iteración
Al comenzar CADA fase:
- [ ] Inspeccionar estado del repositorio, código y tests existentes.
- [ ] Leer el bloque de la fase en `qa/12_TODO_MAESTRO.md`.
- [ ] Redactar plan corto de archivos que se modificarán, dependencias y riesgos.
- [ ] Confirmar que los criterios de dominio están definidos; señalar bloqueos exactos en decisiones pendientes.
- [ ] Implementar cambios pequeños con pruebas unitarias e integración.
- [ ] Ejecutar los comandos pertinentes en el entorno real disponible (nunca inventar el resultado).
- [ ] Verificar seguridad tenant/sucursal, reglas de negocio, validaciones de montos y errores.
- [ ] Registrar en `docs/PROGRESO.md`: fecha, fase, código, comandos, resultado, evidencia, bloqueos y próximo paso.
- [ ] Marcar casillas `[x]` **solo** tras prueba/evidencia concreta; mantener `[ ]` en caso contrario.
- [ ] Emitir resumen final del cambio con: qué se hizo, qué no, pruebas, riesgos, checklist actualizado.

## Orden estricto de construcción
**F0** auditoría repo/herramientas → **F1** instalación base, Auth/SaaS/tenants → **F2** migraciones y seeds → **F3** catálogo, atributos y variantes → **F4** media R2 → **F5** stock/transacciones → **F6** clientes, pedidos y apartados → **F7** enlaces temporales/catálogo comprador → **F8** cuentas semanales, facturas y PDF → **F9** pagos/abonos/devoluciones → **F10** vendedores, comisión, sueldo y nómina → **F11** frontend tablet → **F12** planes, reportes, operación → **F13** QA extremo a extremo, seguridad, rendimiento y staging → **F14** despliegue controlado.

No saltes F5 para empezar F6/F7; no generes comisiones antes de probar pagos e invoice items. Es válido crear pantallas estáticas mientras faltan reglas, pero no marcar el módulo terminado.

## Servicios/clases que deberán existir (o equivalentes con la misma responsabilidad)
- `TenantContext`, `StoreAuthorizationService`, `PlanEntitlementsService`
- `ProductService`, `VariantCombinationService`, `MediaUploadService`, `ImageProcessor`, `ObjectStorage`
- `StockLedgerService`, `ReservationService`, `StockReconciliationService`
- `OrderService`, `ShareLinkService`, `WeeklyAccountService`, `InvoiceService`, `DocumentSequenceService`
- `PaymentService`, `PaymentAllocationService`, `CustomerCreditService`, `ReturnService`
- `SellerAttributionService`, `CommissionAccrualService`, `PayrollService`
- `AuditTrailService`, `IdempotencyService`, `OutboxPublisher`

## Entregables mínimos por módulo
Migraciones; modelos y relaciones; DTO/Actions; Form Requests; policies; endpoints API; lógica de dominio; tests unitarios y de integración; factories/seeders; documentación API con ejemplos; vistas cuando corresponda; evidencia de funcionamiento.

## Condición de finalización del proyecto
- Tests y migraciones ejecutados sobre MySQL real en staging; ambientes aislados.
- QA de flujos de inventario, pagos y comisiones con **dos usuarios concurrentes**.
- Una tienda A no puede consultar ni modificar ningún dato de tienda B.
- Demo real desde tablet: seleccionar cliente → añadir producto → apartar → cobrar parcial → cerrar semana → facturar → completar venta → pagar comisión.
- Demo cliente: entrar al enlace, crear carrito, confirmar, volver a intentar y verificar que no duplica pedido.
- Demo de imagen: subir desde Safari, optimizar, publicar URL R2, agotar cuota de 2 GiB de forma controlada y respetar 5 fotos.
- Política de respaldos y **prueba de restauración** ejecutadas; métricas y alertas activas.

## Respuesta esperada de la IA en cada entrega
**Fase**; **archivos creados/modificados**; **reglas aplicadas**; **pruebas ejecutadas y resultado**; **riesgos/decisiones pendientes**; **checklist marcado con evidencia**; **próximo bloque**. No transformar todo el proyecto en un único archivo gigantesco.

**COMIENZA EN F0.** No pidas confirmación para tareas de análisis, documentación o pruebas no destructivas; solicita aprobación específica antes de pagos reales, compras, borrar datos existentes o poner producción en servicio.
