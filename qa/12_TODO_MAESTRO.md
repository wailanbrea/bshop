# BShop — tablero de negocio único

Decisión vigente: una instalación para KeylaShop y sus sucursales. Ver
`docs/ARQUITECTURA_VIGENTE.md`. Evidencia en `docs/PROGRESO.md`.

## Base y acceso
- [x] Laravel/Vue/TypeScript, runtime PHP dedicado y entorno de pruebas protegido.
- [x] Perfil único de negocio, sin alta/selector de otras empresas.
- [x] Usuarios, roles y permisos por acción; acceso explícito a sucursales.
- [x] Administración de sucursales y almacenes.
- [x] Invitaciones caducables y control del último propietario activo.
- [x] Migraciones de negocio único aplicadas en SQLite y MySQL aislados.
- [x] Demo KeylaShop convertida conservando usuarios, ubicaciones, permisos y auditoría.
- [ ] SMTP real y recuperación de acceso con entrega verificada.
- [ ] Datos definitivos y aceptación del propietario del negocio.

## Esquema y catálogo
- [x] Modelo financiero/inventario adaptado a un único negocio, conservando contexto de sucursal.
- [x] Factories/fixtures para un negocio con dos sucursales.
- [x] Pruebas que rechazan un segundo perfil y accesos a sucursales no autorizadas.
- [x] Base tablet-first: controles táctiles, sucursal persistente y diálogos adaptables a teclado/orientación.
- [x] Regresión actual: 718 casos aprobados en seis perfiles, incluidas compensaciones/versiones de ajustes, correcciones de reembolsos, compensaciones físicas, reversiones de crédito, dashboard/historial/gráficos/tabla, comprobantes de ajuste/crédito, aplicaciones de crédito, conciliaciones/reembolsos, recepciones, reversiones, cuentas semanales, clientes, inventario y enlaces.
- [ ] Aceptación con la tablet física del cliente y sus navegadores/teclado nativo.
- [ ] Revalidación integral financiera, concurrencia y reversibilidad sobre la arquitectura nueva.
- [x] Catálogo de productos/servicios, categorías jerárquicas y marcas.
- [x] Atributos/valores y variantes canónicas con SKU/barras únicos.
- [x] Precios decimales, costos por permiso y control de versiones al editar.
- [x] Consulta paginada, búsqueda/filtros y archivo/reactivación sin borrar historial.
- [x] Catálogo tablet-first y regresión de formularios/giro/errores.
- [x] Importación CSV con preview/dry-run, errores por fila y confirmación atómica/idempotente.
- [x] Reversión controlada por archivo, bloqueada ante cambios/uso y sin borrar historial.
- [x] Integración CSV validada en MySQL, procesos concurrentes y demo tablet.

## Módulos de negocio siguientes
- [x] Procesamiento seguro JPEG/PNG/WebP, EXIF y derivados 320/640/1600 sin ampliar.
- [x] Galería tablet, principal/orden/variante, idempotencia y cuota del negocio con retención.
- [x] Almacenamiento local y adaptador R2 configurable; migración, HTTP y concurrencia MySQL verificados.
- [ ] Activar/verificar bucket, dominio y permisos R2 reales con autorización.
- [ ] Instalar y verificar tarea persistente de reconciliación/limpieza en el destino de entrega.
- [x] Inventario, kardex, reservas, entrega parcial y transferencias con locks/idempotencia.
- [x] Alta/búsqueda/consulta de clientes, venta rápida y apartados reabribles con referencia visual.
- [x] Facturación individual idempotente, snapshots y PDF privado; compartir manualmente.
- [x] Abonos, asignación a la factura y crédito vinculado al pedido, sin sobreabonos ni duplicados.
- [x] Cuentas por cobrar por sucursal: saldos decimales, filtros, resumen por moneda, CSV y acceso al cobro existente.
- [x] PDF e imágenes por streaming; errores de descarga y reintentos sin duplicar ventas. Suite PHP actual: 261 pruebas / 3.569 aserciones.
- [x] Demo USD coherente creada mediante formularios; conciliación de stock/dinero/PDF y recorridos reales.
- [x] F5.07: diagnóstico de inventario por almacén en pantalla/API/CLI, comparando saldos, reservas y kardex sin escrituras; programación horaria declarada.
- [x] Validar reconciliación con concurrencia y rango extremo en MySQL aislado (ver `docs/VERIFICACION_RESERVAS_MYSQL.md`).
- [ ] Instalar/verificar programador de reconciliación en el destino.
- [x] F5.04: plazo configurable para nuevas reservas y vencimiento idempotente de apartados sin pagos/factura/entregas; conservando fechas históricas, con diagnóstico y programación declarada.
- [x] Validar vencimientos concurrentes con cobro/edición/entrega en MySQL aislado (ver `docs/VERIFICACION_RESERVAS_MYSQL.md`).
- [ ] Activar/verificar ejecución persistente de vencimientos; política de cancelación con abonos por fase de devoluciones.
- [x] Ejecutor del programador con estado privado, protección de solapamiento y fallo persistente por tarea; consulta sin ejecutar tareas.
- [x] Preparados plan/instalador Windows y verificador MySQL de carreras/rango extremo; verificador ejecutado en MySQL aislado. Instalación real pendiente.
- [x] Gestión interna de clientes: editar, archivar/reactivar con confirmación, filtros, versiones, auditoría y recuperación; conservar deuda/documentos y sincronizar la venta en memoria.
- [x] Consultas de clientes recuperables: reintento de página fallida y bloqueo de resultados anteriores durante búsqueda; 102 casos clientes/ventas y 78 Apartados revalidados.
- [ ] Edición de pedidos guardados mediante enlace público; concurrencia MySQL de enlaces/gestión de clientes y aceptación en tablet física.
- [x] Catálogo autorizado y confirmación única de apartados por enlace temporal para un cliente existente, con precios/reservas de servidor y recuperación tras pérdida de respuesta.
- [x] Enlaces temporales de consulta de un pedido guardado: caducidad/revocación, privacidad, replay y acceso invitado.
- [x] Cuentas semanales de siete días, agrupación y cierre manual con factura agregada única, asignación de abonos, PDF histórico y CxC integrado.
- [x] Concurrencia MySQL específica semanal:32 carreras/dos rondas, creación/solapamientos, membresía, cierre/cobro/facturación individual, ambos órdenes mixtos, replay y recierre revisado; sin duplicar fuentes/asignaciones.
- [ ] Política global de día de corte y automatización de cuentas semanales; carga sostenida y aceptación operativa.
- [x] Historial y reversión completa de pagos por error, permiso administrativo, motivo/UUID/versión, crédito y facturas individuales/semanales conciliados sin borrar historia.
- [x] Recepción física de mercancía devuelta, límite por entrega, reposición opcional con kardex trazado, ajuste económico pendiente y cobros retenidos hasta conciliar.
- [x] Conciliación económica de recepciones con importes originales, prorrata/redondeo acumulado, crédito liberado y registro manual de reembolso con límites/referencia/UUID; saldos netos integrados.
- [x] Aplicación explícita de saldo liberado a otro pedido facturado del mismo cliente/sucursal/moneda, sin nuevo cobro; procedencia y reembolso posterior trazados incluso en factura semanal compartida.
- [x] Comprobantes PDF privados de ajuste/crédito, snapshots históricos, integridad y recuperación de publicación sin repetir movimientos.
- [x] Reversión compensatoria completa de aplicaciones de crédito por error, con procedencia restaurada, historial/PDF preservados y recuperación sin repetir movimientos.
- [x] Anulación compensatoria de recepciones físicas pendientes, stock inverso trazado, cupo restaurado y retención de factura semanal coherente; dinero/PDF preservados.
- [x] Corrección compensatoria de reembolsos registrados por error y sin entrega real de dinero, confirmación explícita, saldo efectivo/restauración/procedencia y recuperación; sin ingresos ficticios.
- [x] Compensación completa de ajustes económicos, revisión LIFO/consumos, recepción retenida, re-conciliación versionada y PDF/historia preservados; esquema y flujo nativos MySQL con DECIMAL extremo verificados.
- [x] Carreras financieras MySQL multiproceso:22 casos/dos rondas, ambos órdenes mixtos, idempotencia y conservación; corrección de lecturas obsoletas InnoDB. Ver docs/CONCURRENCIA_FINANCIERA.md.
- [ ] Carga sostenida MySQL de los flujos financieros y conciliación avanzada con comisiones.
- [x] Tablero Apartados único (grid/detalle), edición con depósitos, alias y estados separados.
- [x] Selector 2×2/4×4/6×6 persistente, páginas de 4/16/36 y adaptación al giro conservando selección/borradores.
- [x] Integración opcional Meta WhatsApp con PDF privado, claves cifradas y webhook; sin reenvío ciego de resultados desconocidos.
- [ ] Activar/validar cuenta real WhatsApp y webhook HTTPS cuando se configure; SMTP y colas según despliegue.
- [ ] Vendedores, contratos versionados, comisiones y remuneraciones.
- [x] Dashboard comercial real e historial por sucursal/fechas, gráficos interactivos SVG, textos centralizados, títulos únicos, tabla/tarjetas, actividad financiera y CSV; sin repetir crédito/cobros/factura semanal.
- [ ] Reportes y configuración operativa del negocio/sucursales.
- [ ] PWA/tablet, accesibilidad, Safari/iPad y QA de flujos completos.
- [ ] Respaldo/restauración, instalación entregable y puesta en servicio autorizada.

No se incluyen suscripciones SaaS, gestión de otros negocios ni administrador de
plataforma. Los resultados previos al cambio de arquitectura se conservan como
históricos y no sustituyen las pruebas del modelo actual.

## Solicitud de ropa y fotografías
- [x] Alta guiada de ropa con colores y tallas habituales, opciones propias y foto de referencia visible al completar el formulario.
- [x] Selección color/talla y disponibilidad por combinación en ventas, apartados y catálogo por enlace.
- [x] Creación desde fotografías y existencia inicial opcional por variante con recuperación idempotente.
- [x] Inventario visual por variante con miniatura y atributos en lista y ajuste.
- [ ] Aceptación física, R2 real y publicación; evidencia y límites en `docs/ROPA_FOTOS_INVENTARIO.md`.
