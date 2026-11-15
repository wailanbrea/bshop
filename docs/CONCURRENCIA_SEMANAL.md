# Cuentas semanales — carreras MySQL multiproceso

## Ejecutar en el destino QA

```powershell
& ".runtime\php83\php.exe" scripts/verify-weekly-concurrency-mysql.php --preflight
& ".runtime\php83\php.exe" scripts/verify-weekly-concurrency-mysql.php --rounds=2
```

Usa la guardia `import-test-connection.php`: exclusivamente `testing`, MySQL8.4,
`127.0.0.1:33078`, base `bshop_testing_single`, bind local y datadir real del proyecto
`.runtime/mysql-data`. Credenciales privadas en runtime; no recibe destinos por CLI.
Requiere esquema revisado y un propietario activo. Preflight sólo consulta esquema,
permisos y visibilidad de sesiones propias; no crea fixtures ni inicializa MySQL.

El harness comparte mutex QA con el financiero, guarda copia íntegra schema+filas
de todas las tablas y comprueba SHA-256 antes de escribir fixtures. Cada ejecución
crea sucursal/almacén/producto/actor/clientes exclusivamente sintéticos. No refresca,
borra ni trunca tablas. No conecta al VPS ni utiliza la demo SQLite.

## Solapamiento demostrado

`weekly-concurrency-worker.php` carga un job validado contra manifiesto/contexto y
una whitelist fija de operaciones. Revisa mediante los modelos/servicios existentes,
publica conexión/PID/hash de payload y llama a `WeeklyAccounts` o `SalesService`.
No recibe código, ejecutables o clases desde JSON.

El padre mantiene una barrera de dominio mientras los dos workers revisan el mismo
estado: mutex de cliente para crear ciclos, cuenta para membresía/cierre/anulación,
producto para cobros, o pedido para carreras mixtas con facturación/cobro. Exige
observar simultáneamente las consultas SQL pendientes en `SHOW FULL PROCESSLIST`,
con conexiones/PID distintos. Sólo entonces libera su transacción. No basta medir
tiempos ni iniciar procesos juntos. En escenarios mixtos encola la primera operación
antes de lanzar la otra y en la segunda ronda invierte el orden; exige ambos ganadores.

## Dieciséis escenarios, dos rondas

| Escenario | Resultado esperado |
|---|---|
| Mismo cliente/período, UUID distintos | 200/409; un ciclo |
| Períodos distintos pero solapados | 200/409; un ciclo con fechas del ganador |
| Crear con mismo actor/UUID/payload | 200/200; una escritura y un replay |
| Agregar dos pedidos con la misma revisión de cuenta | 200/409; un miembro nuevo |
| Agregar con mismo actor/UUID/payload | 200/200; un miembro y un replay |
| Agregar frente a facturar individualmente | 200/409; un único estado compatible |
| Doble cierre con UUID distintos | 200/409; una factura agregada |
| Cerrar con mismo actor/UUID/payload | 200/200; misma cuenta/factura y un replay |
| Cierre frente a agregar otro apartado | 200/409; miembros coherentes |
| Cierre frente a retirar un miembro | 200/409; miembros coherentes |
| Cierre frente a cobrar a un miembro | 200/409; la revisión antigua no se aplica |
| Cierre frente a facturación individual de un miembro | 200/409; sin doble factura |
| Dos cobros del saldo completo del mismo miembro | 200/409; sin sobreabono |
| Cobros del saldo completo de miembros distintos | 200/200; se aceptan ambos |
| Cobro con mismo actor/UUID/payload | 200/200; un ingreso y un replay |
| Anular cuenta vacía frente a agregar apartado | 200/409; estado y miembros compatibles |

Después de los cuatro conflictos de cierre donde ganó el cambio, vuelve a consultar
la revisión y cierra explícitamente: incorpora el abono previo, respeta miembros
agregados/retirados y excluye la factura individual conservándola intacta.

## Invariantes

- Un ciclo, período de siete días y contexto de moneda/zona horaria del ganador.
- Un documento pendiente por factura, fuentes y artículos exactos, sin emitir PDF.
- Asignación única de cada pago a la factura correcta, por su importe exacto.
- Una entrada de cobro y, cuando corresponde, una de asignación en el ledger.
- Conservación decimal por pedido/factura y efectivo total igual al solicitado.
- El agregado queda pagado sólo cuando todos sus miembros están pagados.
- Contacto y abonado al cierre congelados; pagos posteriores no reescriben la cuenta.
- Pagos originales, snapshots fiscales previos, stock/reservas y kardex intactos
  durante la carrera/cierre. No se consume numeración por operaciones rechazadas.
- Todas las filas anteriores a la ejecución permanecen iguales al finalizar.

Al terminar se completan **sólo las entregas sintéticas** mediante el servicio real
para liberar reservas; se retira compensatoriamente el remanente de stock y se
concilia. Se archivan producto/clientes y se desactivan actor/ubicaciones QA. Las
cuentas, facturas y movimientos sintéticos se conservan por trazabilidad.

## Evidencia local, 15 de noviembre de 2026

- MySQL8.4.13 y destino/datadir verificados; copia íntegra verificada de82 tablas.
- `5DB9113ED12F`:32/32 carreras. `AF2AD6097F8D`:32/32 con recierre revisado.
- Revalidación final `BD64A632AA27`:32/32, ambos ganadores en seis escenarios mixtos,
  cuatro recierres verificados; cero fallos, fixtures archivados y filas previas intactas.
- Informes privados `.runtime/weekly-race-{tag}/report.json`, backup, jobs, overlap,
  salidas de workers y after con posiciones por pedido/recierre. No se versionan.
- La primera instrumentación `E64F2CD89D59` rechazó su barrera tras15 casos: anular
  una cuenta vacía no espera por un pedido sin agrupar. Se corrigió el harness para
  bloquear la cuenta; no fue un fallo de negocio y las filas previas se preservaron.
- Suite PHP:258 pruebas/3.490 aserciones,47,02s, `testing/sqlite/:memory:` protegido.
- Pint con caché nueva `.runtime/pint-weekly-20261115-d.cache`:PASS.

No se detectó un defecto productivo nuevo en estos escenarios; no hay migraciones
ni cambios a las reglas comerciales. El trabajo añade verificación ejecutable.

## CI y límites

El job `financial-concurrency` ejecuta primero22 carreras de crédito/compensaciones
y después32 semanales en el mismo MySQL8.4 desechable. Publica report/overlap/out/err
de ambas suites y excluye backups/credenciales; detiene su contenedor al terminar.
Ejecución remota verificada:run 43729717065 de`f094e89` en success,weekly-race-
A727BCD2A0E7 passed 32/32 y financial-race-7D4576CB97F1 passed 22/22 en el artefacto
`financial-concurrency-evidence-1`. Enlace y detalle en PROGRESO.

Quedan pendientes política global de corte/automatización, carga sostenida, carreras
de enlaces/vencimientos, comisiones, tablet física y entrega operativa. Estas carreras
de servicios no miden HTTP, PDF, proveedores, throughput ni latencias de producción.
