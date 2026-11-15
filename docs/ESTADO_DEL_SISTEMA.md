# Avance del sistema — 15 de noviembre de 2026

## Estimación global: aproximadamente 75%

Estimación de planificación para el alcance de **un negocio y sus sucursales**,
incluyendo los módulos comerciales pendientes y la entrega operativa. No es una
certificación de puesta en producción. Se redondea a intervalos de cinco puntos
porque los tamaños de las tareas y su aceptación todavía pueden variar.

La base de cálculo es el QA maestro, los servicios/pantallas implementados y los
resultados registrados en PROGRESO. Los pesos y porcentajes por módulo son una
valoración técnica explícita; no se confunden con una medición automática de código.

| Área | Peso en el alcance | Avance estimado del área | Aporte |
|---|---:|---:|---:|
| Base, usuarios, permisos y sucursales | 10% | 95% | 9,50 |
| Catálogo, CSV e imágenes | 15% | 90% | 13,50 |
| Inventario, reservas y operación programada | 15% | 85% | 12,75 |
| Clientes, ventas, apartados, facturas y cobros | 20% | 90% | 18,00 |
| Enlaces públicos de consulta y pedido | 10% | 75% | 7,50 |
| Cuentas semanales y cierres agregados | 7% | 75% | 5,25 |
| Devoluciones, reembolsos y crédito entre operaciones | 7% | 85% | 5,95 |
| Vendedores, comisiones y remuneraciones | 6% | 0% | 0,00 |
| Reportes y configuración operativa restante | 5% | 20% | 1,00 |
| PWA, aceptación y entrega/operación final | 5% | 30% | 1,50 |
| **Total ponderado** | **100%** | | **74,95 ≈ 75%** |

Antes de esta entrega, el checklist tenía 40 puntos completos de 56 (71,4%).
Se añadió un hito concreto de pedidos públicos: 41/57 (71,9%, aproximadamente72%).
Tras las cuentas semanales:42/58,72,4%; se separó como pendiente la política global
de corte/automatización y su concurrencia MySQL. El hito funcional semanal aporta
5,25 puntos al cálculo ponderado, pasando de aproximadamente65% a70%.
La reversión de pagos añade un hito comprobado:43/59 puntos del checklist y
70,75% ponderado. Se mantiene el redondeo aproximado70%, porque devoluciones,
reembolsos y crédito entre operaciones todavía requieren desarrollo.
La recepción física añade otro hito:44/60 puntos y71,80% ponderado. Se identifica
como avance parcial del bloque económico: la mercancía está trazada, pero su
crédito, cargos/descuentos y reembolso todavía deben conciliarse.
La conciliación económica y registro manual de reembolso elevan el bloque F9
a70% estimado:45/61 puntos del checklist y73,90% ponderado, redondeado aproximadamente75%.
En ese hito aún quedaban pendientes crédito entre operaciones, compensaciones y QA MySQL.
La aplicación explícita de crédito está incorporada:46/62 puntos y74,95% ponderado,
manteniendo aproximadamente75%. Los comprobantes se incorporaron después:47/63 puntos;
se conserva74,95% ponderado. El dashboard/historial añade un hito funcional:48/64,
manteniendo la estimación aproximada75% hasta revisar el alcance restante de reportes
y entrega. Compensaciones y QA MySQL siguen pendientes.
La reversión de aplicaciones añade49/65 puntos del checklist; la estimación global
se mantiene aproximadamente75% por compensaciones restantes, comisiones y entrega.
Compensación de recepciones pendientes:50/66 puntos; se conserva aproximadamente75%.
Corrección de reembolsos erróneos:51/67; se conserva la estimación aproximada75%.
Compensación/versiones de ajustes:52/68; se mantiene aproximadamente75% hasta cerrar
concurrencia, comisiones, módulos restantes y entrega operativa.
Los puntos tienen tamaños distintos: cuentas semanales o devoluciones representan
mucho más trabajo que un ajuste de interfaz. Por eso el porcentaje global se basa
en el alcance ponderado y conserva los módulos grandes pendientes.

Concurrencia financiera multiproceso:53/69 puntos; se conserva aproximadamente75%.
El hito cubre carreras acotadas de servicios, no carga sostenida ni aceptación final.

## Entrega de esta iteración: concurrencia financiera MySQL

- 22 carreras reales por ejecución, dos rondas y ambos órdenes de los escenarios
  mixtos. Una escritura por conflicto, replay idéntico y conservación exacta.
- Corregida lectura obsoleta InnoDB en subconsultas de exclusión al escribir.
- 258 pruebas PHP/3.490 aserciones, build/Pint y verificación nativa DECIMAL aprobados.
  Demo conciliada sólo lectura; CI incorpora MySQL aislado y evidencia de solapamiento.
- Commit7923cc9:CI43719059152 completo aprobado, incluidos22 casos MySQL y718 browser.
- Detalles, artefactos y límites en `CONCURRENCIA_FINANCIERA.md` y PROGRESO.

### Entrega anterior: compensación/versiones de ajustes

- Compensación LIFO sin consumos pendientes, restauración de deuda/asignaciones,
  recepción retenida y nueva revisión con otro ID sin perder PDF original.
- 251 PHP/3.482 aserciones y718/718 browser. Esquema/flujo MySQL8.4 verificados con
  conservación de filas y DECIMAL extremo; concurrencia específica aún pendiente.

### Entrega anterior: corrección de reembolsos erróneos

- Sólo registros sin entrega real de dinero, con confirmación explícita. Restituye
  crédito sin reescribir ledger ni generar ingresos; historial y saldos coherentes.
- 239 PHP/3.228 aserciones y688/688 browser; copia/migración local verificadas.

### Entrega anterior: compensación de recepciones físicas

- Anula registros pendientes por error mediante stock inverso trazado, conservando
  artículos y dinero, y restaura cupo/retención según otras recepciones pendientes.
- 231 PHP/3.081 aserciones y658/658 browser; demo conciliada tras respaldo/migración.

### Entrega anterior: reversión compensatoria de crédito

- Conserva aplicación/ledger/PDF, restaura liberaciones consumidas y reabre deuda
  del destino; atribución probada en factura semanal y cadenas de crédito.
- Permiso administrativo, motivo, revisión, UUID y recuperación por consulta.
- 223 pruebas PHP/2.944 aserciones y628/628 browser; demo conciliada sin operaciones nuevas.

### Entrega anterior: dashboard comercial e historial

- Resumen real por sucursal/fechas, cobros separados de crédito, gráficos ECharts
  SVG interactivos y monedas separadas. Historial con tabla/tarjetas, CSV y actividad.
- Un título por ruta y pantallas diferenciadas; sin duplicar cabecera ni bienvenida.
  Textos centralizados, tabla legible con datos extensos y prueba de WebKit corregida.
- 214 pruebas PHP/2.741 aserciones y598/598 browser en seis perfiles. Demo conciliada.

### Entrega anterior: comprobantes privados de ajuste/crédito

- Snapshots al commit financiero, PDF privado posterior, hash/tamaño y recuperación
  de publicación interrumpida; no regenera un histórico ready ausente/alterado.
- Descarga desde historial de ajustes/aplicaciones, con permisos y reintentos propios.
- 205 pruebas PHP/2.558 aserciones y538/538 browser en seis perfiles.

### Entrega anterior: aplicación de saldo entre pedidos

- Crédito liberado por devoluciones conciliadas, aplicado a otra factura del mismo
  cliente/sucursal/moneda con permiso, revisión, límites, procedencia y recuperación.
- Sin cobros nuevos ni movimiento de mercancía. Atribución de abonos correcta entre
  miembros de una misma factura semanal y crédito reutilizable si el destino devuelve.
- 195 pruebas PHP /2.379 aserciones y502/502 casos browser en seis perfiles.

### Entrega anterior: ajuste económico y reembolso manual

- Propuesta sobre importes originales con descuento/impuesto proporcional y
  redondeo acumulado; envío acreditado explícitamente y con límite.
- Documento interno de ajuste, reducción de deuda/crédito liberado, fuentes por
  pago y registro separado de dinero ya devuelto al cliente. No ejecuta pagos bancarios.
- Saldos netos en pedido, cuenta semanal, CxC/CSV, cliente, facturas y consultas
  públicas. Factura/PDF/pagos/asignaciones originales e inventario conservados.
- 187 pruebas PHP /2.180 aserciones y472/472 casos browser en seis perfiles.

### Entrega anterior: recepción de mercancía devuelta

- Recibe unidades facturadas con entrega trazada; reposición opcional al almacén
  original, kardex vinculado y cupo acumulado contra devoluciones repetidas.
- Conserva factura/pagos/PDF, identifica ajuste económico pendiente y bloquea
  nuevos cobros de esa factura hasta conciliar, incluidos sus miembros semanales.
- Recuperación sin duplicar stock y UI adaptable.179 pruebas PHP /2.031 aserciones
  y448/448 casos browser aprobados en seis perfiles.

### Entrega anterior: reversión de pagos

- Historial en Ventas/Apartados y corrección de pago completo con motivo,
  permiso administrativo, versiones, UUID y recuperación por consulta.
- Actualiza crédito/saldo efectivo y facturas individuales/semanales; conserva
  documentos y registros originales. Bloquea conciliaciones avanzadas aún pendientes.
- 169 pruebas PHP /1.860 aserciones y418/418 casos browser en seis perfiles.

### Entrega anterior: cuentas semanales

- Agrupación por cliente/sucursal/moneda/período de siete días y revisión explícita.
- Cierre único con factura agregada, PDF privado histórico, abonos antes/después y
  CxC sin duplicación; exclusión de pedidos facturados individualmente.
- Recuperación por UUID tras respuesta perdida, anulación de cuentas vacías y
  reapertura controlada de ese mismo período sin borrar el historial.
- 161 pruebas PHP /1.746 aserciones y388/388 casos browser en seis perfiles.

### Entrega anterior: pedidos públicos

- Clientes → ficha → **Enlace para nuevo pedido**: elegir almacén, variantes
  autorizadas, envío y duración; generar/copiar/revocar.
- Cliente invitado: catálogo limitado, carrito, cotización de servidor y confirmación
  única de un apartado, con recuperación tras respuesta perdida.
- Reutiliza el escritor de stock y la política de reservas existentes.
- 150 pruebas PHP / 1.569 aserciones; regresión completa364/364 en seis perfiles.
  Tras pulir precios unitarios de revisión se repitieron18/18 casos públicos.

## Trabajo principal restante

1. Política global de corte/automatización semanal y QA MySQL de los cierres nuevos.
2. Carga sostenida financiera y conciliación avanzada con comisiones.
3. Vendedores, comisiones y remuneraciones.
4. Edición pública de pedidos ya guardados y QA de concurrencia MySQL de enlaces/vencimientos.
5. Reportes restantes, PWA y pruebas de aceptación con la tablet física.
6. Respaldo/restauración comprobados, programador persistente y despliegue autorizado.
   SMTP/R2/WhatsApp reales requieren su configuración y validación cuando se activen.

Recalcular esta estimación al cerrar cada módulo importante; no elevarla sólo por
añadir tablas o aumentar la cantidad de pruebas.
