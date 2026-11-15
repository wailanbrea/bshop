# Estrategia integral de pruebas y QA — KeylaShop

## Principio
No declarar un módulo finalizado por compilar o cargar una pantalla. Un `DONE` requiere tests del dominio, integración MySQL, autorización, errores y uso real según aplique. El SQL se considera **no ejecutado** hasta que haya evidencia MySQL real.

## Pirámide
1. **Unitarias**: dinero decimal, redondeo, combinación canónica de atributos, contratos de transición, cálculo comisión/sueldo.
2. **Integración MySQL real**: FKs compuestas, transacciones, `FOR UPDATE`, tablas de crédito, rollback, índices, uniqueness y aislamiento.
3. **HTTP feature tests**: validación JSON, auth, roles, tenant/store, idempotencia, 404/403 y rate limits.
4. **E2E browser**: Safari iPad real/simulador validado, Chrome tablet, escritorio; teclado virtual, taps, orientación.
5. **Integración externa**: R2 bucket de prueba, CDN/custom domain en staging, caída de proveedor y reintentos.
6. **Operación**: backup/restore, worker, scheduler y alertas.

## Casos CRÍTICOS (no negociables)
| ID | Preparación y acción | Resultado esperado |
|---|---|---|
| QA-001 | Dos tenants A/B con IDs de cliente/producto reales | Acceso cruzado sin datos ni modificaciones; 403/404 |
| QA-002 | Sucursal A intenta operar inventario/pago de B | Denegado aunque mismo tenant |
| QA-003 | Stock 1; dos sesiones simultáneas reservan 1 | Éxito 1, conflicto 1, no stock negativo |
| QA-004 | Reserva 2, entrega 1, cancela 1 | `on_hand` -1, `reserved` 0; kardex completo |
| QA-005 | Dos almacenes aportan a un pedido | reservas por almacén correctas, sin duplicar cantidad |
| QA-006 | Pago 100; dos asignaciones simultáneas por 80 | Solo 1 aplica o suma <=100; crédito sin negativos |
| QA-007 | Anticipo 100 antes de factura 80; se asigna 50 | crédito restante 50, saldo factura 30 |
| QA-008 | Cuenta semanal cerrada 2 veces por doble click/retry | Una factura/una numeración; respuesta idempotente |
| QA-009 | Orden incluida en cierre semanal intenta factura individual | Error explícito, sin factura doble |
| QA-010 | Enlace abierto por GET preview; después comprador confirma | Sigue válido hasta POST; solo un pedido |
| QA-011 | Dos POST casi simultáneos con mismo token | Una orden y enlace usado después de commit |
| QA-012 | Cambiar precio de variante después de factura | snapshot histórico intacto |
| QA-013 | Comisión 5%, cobro aplicado 100 sobre base elegible 200 | comisión devengada 5 (no 10) en proporcionalidad del ejemplo |
| QA-014 | Reembolso parcial después de nómina pagada | nueva comisión negativa a periodo futuro; no borra histórico |
| QA-015 | Contratos sueldo/comisión solapados | Rechazados antes de guardar |
| QA-016 | Intentar liquidar comisión en dos nóminas | Constraint/servicio impide doble vinculación |
| QA-017 | Cambiar contrato futuro | comisiones anteriores conservan snapshot |
| QA-018 | Producto tiene 4 imágenes, 2 subidas concurrentes | como máximo 5 y cuota consistente |
| QA-019 | Imagen `.jpg` con archivo no imagen | Rechazada, no objeto público |
| QA-020 | Archivo mayor de 10 MiB o mayor de 20MP | Rechazado sin crash y sin fuga de cuota |
| QA-021 | Imagen JPEG con EXIF GPS | Derivado WebP sin GPS y `ready` solo al publicar |
| QA-022 | Falla R2 subiendo segunda rendición | No publicar imagen parcial; reintento/limpieza |
| QA-023 | Intentar superar cuota de 2 GiB con 2 cargas | Una o ambas rechazadas; saldo nunca excede límite |
| QA-024 | Leer bucket privado mediante URL pública | No se puede leer ni listar |
| QA-025 | Safari iPad horizontal y vertical, nuevo apartado | táctil/legible, sin acciones recortadas |
| QA-026 | Conexión interrumpida al confirmar pedido | reintento idempotente, mismo pedido/ningún duplicado |
| QA-027 | Restaurar backup SQL + objetos de imagen | Catálogo consistente y facturas recuperables |
| QA-028 | Servicio sin stock (`track_stock=false`) | Se vende sin crear stock_reservation |
| QA-029 | Intentar aplicar un pago de sucursal A a factura B | Denegado según política del MVP |
| QA-030 | Usuario vendedor intenta cambiar porcentajes/nómina | 403 y auditoría si aplica |

## Concurrencia: cómo demostrarla
- Usar **procesos/conexiones reales separadas** de MySQL y una barrera de inicio; no probar ambas operaciones secuenciales en un mismo proceso pretendiendo concurrency.
- Utilizar test de integración que lance dos solicitudes simultáneas y verifique base de datos después de ambos commits.
- Ejecutar con la conexión `testing_mysql`, no SQLite en memoria, porque SQLite no replica locking InnoDB.
- Loguear `request_id`, event_key y resultado de ambos sin secretos; documentar deadlocks y reintentos.

## Seguridad y pruebas negativas
- Matriz usuario-rol-permiso-recurso cross-tenant y cross-store.
- CSRF para escritura de SPA, XSS en descripciones, SQLi en filtros, rate limiting en login y enlace.
- Tokens capturados en logs/no redirigir URLs con tokens a sitios de terceros vía `Referer`; usar Referrer-Policy estricto.
- Archivo SVG/HTML/zip renombrado como `.jpg`, EXIF, HEIC, pixel bomb, caracteres raros de filename.
- Ensayar carga con cliente que envía `tenant_id` diferente y URL manipulada.
- Comprobar credenciales R2 nunca llegan a `resources/js` bundle ni al JSON HTTP.

## Rendimiento
- Catálogo 5k, 25k, 100k variantes: medir p95 de búsquedas, listado y scroll; no declarar límite sin medir.
- R2 CDN: cache-hit de thumbnails, payload total de la pantalla, costo de operaciones.
- Documentar presupuesto de rendimiento propuesto: p95 API de búsqueda <400 ms en staging representativo; no prometer SLA hasta medir.

## Evidencia en `docs/PROGRESO.md`
Anotar comando real, rama/commit, fecha, MySQL versión, resultado, cobertura relevante, screenshots iPad, respuesta de R2 o mock, fallas y asuntos abiertos. `php artisan test` sobre un mock no sustituye test real de R2.
