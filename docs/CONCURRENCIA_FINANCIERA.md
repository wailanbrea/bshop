# Concurrencia financiera real — MySQL 8.4

## Destino y ejecución

Desde la raíz del proyecto, con PHP 8.3 y dependencias instaladas:

```powershell
& ".runtime\php83\php.exe" scripts/verify-financial-concurrency-mysql.php --preflight
& ".runtime\php83\php.exe" scripts/verify-financial-concurrency-mysql.php --rounds=2
```

La conexión está fijada por `scripts/import-test-connection.php`: entorno `testing`,
MySQL 8.4, host `127.0.0.1`, puerto `33078`, base `bshop_testing_single`, bind local
y datadir real `.runtime/mysql-data`. Las credenciales son privadas y locales en
`.runtime/mysql-test-credentials.json`. No acepta otro destino por argumentos.
El preflight sólo comprueba esquema, permisos y visibilidad de sesiones propias.
No inicia ni inicializa el servidor. Requiere las migraciones revisadas hasta003100.

## Protocolo y cobertura

El padre guarda y verifica por SHA-256 una copia de esquema y filas de todas las
tablas antes de crear una sucursal, almacén, usuario, producto y clientes sintéticos.
Un advisory lock impide dos ejecuciones del mismo harness. Cada worker tiene proceso
y conexión SQL independientes y llama a los servicios reales de la aplicación.

El padre retiene el lock de producto mientras los workers revisan el mismo estado.
Antes de soltarlo exige observar simultáneamente sus consultas pendientes mediante
`SHOW FULL PROCESSLIST`: producto `FOR UPDATE` o barrera de idempotencia. Guardar
timestamps o lanzar procesos juntos no cuenta como prueba suficiente de solapamiento.
En escenarios mixtos se encola primero una operación y en la segunda ronda la otra;
el informe exige que ambas hayan ganado al menos una vez.

Once escenarios por ronda:

1. Dos aplicaciones que consumen todo el mismo crédito, con destinos distintos.
2. Aplicación frente a registro de reembolso.
3. Aplicación frente a compensación del ajuste de origen.
4. Dos compensaciones del mismo ajuste con UUID distintos.
5. Mismo actor/UUID/payload al compensar: una escritura y un replay.
6. Dos correcciones del mismo reembolso erróneo, sin entrega real de dinero.
7. Dos reversiones de la misma aplicación de crédito.
8. Dos compensaciones de la misma recepción física.
9. Cobro frente a compensación de ajuste que reabre la retención de cobro.
10. Mismo UUID/payload con actores distintos: no comparte replay.
11. Cuatro aplicaciones del mismo crédito completo.

Salvo el replay idéntico, sólo una operación confirma; las otras reciben409.
Se comprueban importes solicitados, conservación exacta por pago/pedido, ausencia de
efectivo ficticio, stock/kardex y unicidad; originales de pago/ajuste/reembolso,
totales/snapshots de pedidos/facturas y snapshots de comprobantes permanecen intactos.

Los fixtures se conservan por trazabilidad. Al acabar se retira compensatoriamente
el stock sobrante, se concilia, se archivan producto/clientes y se desactivan las
ubicaciones y el usuario sintético. Se compara cada fila preexistente con la copia.
No se borran ni se refrescan tablas. Un fallo conserva informe y evidencia.

## Defecto reproducido y corrección

La ejecución `716E42DF4819` reprodujo dos éxitos incompatibles: compensar el ajuste
y después aplicar su crédito con una revisión anterior. InnoDB podía mantener una
lectura consistente antigua dentro de `NOT EXISTS`, aunque el SELECT exterior
usase `FOR UPDATE`. El bloqueo exterior no convierte automáticamente una subconsulta
en lectura actual. Referencia: [MySQL, Locking Reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html).

Las consultas financieras de escritura propagan ahora su bandera de bloqueo hasta
las subconsultas que excluyen compensaciones de ajustes, reembolsos, aplicaciones
y recepciones. Así revalidan la evidencia vigente después de esperar el lock.
Las consultas de lectura conservan su comportamiento por defecto. No hay migración
ni cambio de importes, snapshots o aislamiento global para corregirlo.

## Evidencia del 15 de noviembre de 2026

- `AF1CBE7E4A53`:22 carreras aprobadas después de corregir el defecto.
- Revalidación `CF387AFF4AF6`:22/22 aprobadas; ambos ganadores en los tres escenarios
  mixtos; replay único; cuatro solicitantes200/409/409/409. MySQL8.4.13 local.
- Copia íntegra verificada de82 tablas antes de la revalidación; informe con
  `fixtures_archived=true` y `preexisting_rows_preserved=true`.
- Artefactos privados: `.runtime/financial-race-{tag}/report.json`, `backup.json`,
  manifiesto, jobs, before/after, overlap y salidas de workers. No se versionan.
- Suite PHP protegida SQLite`:memory:`:258 pruebas/3.490 aserciones,52,93s.
- Verificador nativo de ajustes/DECIMAL extremo:PASS, fixtures revertidos por transacción.
- Build y Pint con caché nueva:PASS. Demo verificada sólo lectura:98/8/90 unidades,
  US$480=US$257+US$223;2 facturas/5 pagos.

## GitHub Actions

`financial-concurrency` usa MySQL8.4 desechable en Docker, con puerto/bind/datadir
validados por la misma guardia. Credenciales aleatorias enmascaradas. El inicializador
`bootstrap-financial-concurrency-ci.php` exige GitHub Actions y una base QA vacía,
migra sin refresh e instala un único negocio sintético. Ejecuta dos rondas y publica
sólo report/overlap/out/err, excluyendo copia íntegra y credenciales. El contenedor
propio se detiene al terminar. La primera ejecución remota se registra en PROGRESO.

Primera ejecución comprobada: [CI43719059152](https://github.com/wailanbrea/bshop/actions/runs/43719059152),
commit7923cc9. Application, financial-concurrency y seis perfiles browser aprobados.
Informe del runner `B6ED2E775727`:22/22, cobertura de ambos órdenes y filas previas
preservadas. Evidencia descargada e inspeccionada localmente;718/718 browser aprobados.

## Alcance pendiente

Estas son carreras sintéticas acotadas de servicios financieros, no un benchmark de
carga ni una aceptación HTTP/PDF/proveedores o de tablet física. Faltan carga sostenida,
concurrencia específica de cierres semanales/enlaces/vencimientos, comisiones,
programador persistente y validación de entrega. No se prueba contra VPS/producción.
