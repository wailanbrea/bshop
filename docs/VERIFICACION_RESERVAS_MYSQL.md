# Verificación de reservas y reconciliación en MySQL

```powershell
.\.runtime\php83\php.exe scripts/verify-reservations-mysql.php
```

El verificador reutiliza la guardia de `import-test-connection.php`: entorno testing,
MySQL 8.4, base `bshop_testing_single`, bind 127.0.0.1, puerto 33078 y datadir
propio `.runtime/mysql-data`. Una conexión o identidad incorrecta termina con código
2 antes de cualquier escritura de QA. No usa la demo SQLite, el VPS ni MariaDB
compartido. No crea instancias, cambia permisos ni modifica firewalls.

Requiere el esquema de pruebas ya migrado y su propietario/sucursal de QA. No
migra, trunca ni cambia la configuración global del plazo. Crea productos, clientes,
pedidos y movimientos sintéticos identificados con un tag aleatorio. Solo asigna
fechas de reserva pasadas a esos nuevos pedidos. Los fixtures de carreras quedan
conservados para inspección; no se borran registros de negocio.

## Casos preparados

1. Vencimiento con instantánea anterior a un abono concurrente: el abono confirmado
   protege el pedido y su reservado.
2. Vencimiento con instantánea anterior a una entrega parcial: conserva el restante.
3. Edición concurrente de cantidades: el vencimiento usa la cantidad pendiente
   actual y la conciliación final coincide.
4. Vencimiento que se confirma antes de un cobro en espera: cobro rechazado con
   409, sin pago creado.
5. Dos procesos de vencimiento para un mismo pedido: una liberación y un segundo
   resultado omitido, sin duplicar el movimiento.
6. Reconciliación bloqueada por el escritor: observa el saldo confirmado completo,
   sin diferencias parciales de kardex/reservas.
7. Físico máximo `999999999999999.9999`, reserva fraccionaria y vencimiento:
   conserva exactamente el físico y libera reservado; fixture extremo revertido.

Los procesos usan barreras de archivos y bloqueos reales. Para los casos de
instantánea se señala después de la primera lectura consistente del pedido, con
el producto bloqueado por el proceso coordinador. La carrera del lector comprueba
su consulta en `SHOW FULL PROCESSLIST` antes de liberar el escritor. No se toma
una espera de tiempo fija como evidencia de concurrencia.

Resultados y manifiestos privados en `.runtime/reservation-qa-{tag}`. Los manifiestos
contienen IDs sintéticos, sin contraseñas; cada trabajador vuelve a validar el
destino y la identidad de su producto. Una barrera tiene límite de 12 segundos.
En fallo se revierten transacciones abiertas y se terminan solo los trabajadores
creados por esa ejecución. Salida 0 únicamente al completar las invariantes; 1 si
falla una prueba. No se considera aprobado por superar solo la guardia de conexión.

## Estado real

**Pendiente de ejecución completa.** La sintaxis PHP y Pint aprobaron, pero la
conexión actual falla por permisos de socket del entorno. El verificador devolvió
2 y se detuvo antes de escribir fixtures. No se acreditan aún rango extremo ni
concurrencia de estas nuevas fases en MySQL.
