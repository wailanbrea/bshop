# Plazo y vencimiento de reservas

F5.04: nuevas reservas con plazo configurable y vencimiento idempotente. El plazo
está desactivado inicialmente (`reserve_expiry_hours = NULL`); no se impone un
vencimiento a los apartados históricos de la demo.

## Operación

En **Configuración → Plazo de reservas**, el propietario con `business.manage`
puede habilitar un plazo entre 1 y 8760 horas. Se informa expresamente que cambiarlo
o desactivarlo afecta nuevas reservas: las fechas ya guardadas permanecen.
La pantalla carga el valor real, valida, permite reintentar y conserva el borrador
al girar. Una actualización concurrente devuelve conflicto y exige consultar el
valor actual antes de guardar.

La fecha se fija al crear la reserva en Ventas o al guardar por primera vez
artículos con stock en un apartado vacío. Todas las reservas de ese apartado
comparten fecha. Editar cantidades o añadir otra variante conserva el primer plazo.
Apartados con reservas históricas sin fecha conservan esa condición; no se les
asigna un plazo por una edición posterior. Se guarda en UTC y se muestra en la
zona horaria del negocio, tanto en Apartados como en el pedido de Ventas.

Solo se cancela automáticamente un apartado `reserved`, abierto, sin envío,
sin factura, sin pagos pendientes o confirmados y sin ninguna entrega parcial. Todas sus
reservas activas deben tener fecha explícita alcanzada. Si falta una fecha o hay
una futura, no se vence parcialmente. Reservas con pagos activos/facturas/entregas
requieren resolución comercial explícita y conservan el stock pendiente.
Los pagos revertidos o fallidos conservan su historial y no impiden el vencimiento;
esto no realiza una devolución de dinero.

Los apartados compuestos solo por servicios sin control de stock no tienen
reservas físicas y no se cancelan con este proceso. La fecha indica elegibilidad;
la liberación ocurre cuando corre la tarea. Si un abono se confirma antes de esa
liberación, el apartado queda protegido, aunque el plazo indicado ya haya pasado.

El vencimiento libera la cantidad pendiente exacta a cuatro decimales mediante
`StockLedger`, con movimiento `expire`, clave única por reserva y actor Sistema.
No cambia el físico. Marca las reservas `expired` y el pedido `cancelled`, conserva
sus líneas/importes históricos e incrementa versiones del pedido y productos.
Por ser cancelado, deja de aparecer como deuda en CxC. No crea ni revierte dinero,
créditos o facturas. El detalle distingue un apartado vencido de otras cancelaciones.

## API

- `GET /api/v1/business/reservations/settings`: devuelve `data.hours` (entero o null).
- `PATCH` a la misma ruta: `{ "hours": 24, "previous_hours": null }`.
  Ambos campos son obligatorios y admiten null; enteros entre 1 y 8760.
  `previous_hours` debe coincidir con el valor actual; 409 si cambió.
- Ambas rutas requieren sesión activa y `business.manage`. Los cambios generan
  auditoría `reservations.policy.updated`, sin alterar otras configuraciones.
- Presentación del pedido: `reservation_expires_at` ISO UTC o null y
  `reservation_expired` booleano. No se confunde este plazo con vencimiento de deuda.

## Comando y ejecución periódica

```powershell
# Diagnóstico sin escrituras (predeterminado)
.\.runtime\php83\php.exe artisan bshop:reservations-expire

# Aplicar solo los vencimientos elegibles
.\.runtime\php83\php.exe artisan bshop:reservations-expire --apply --limit=100

# Continuar manualmente después del ID revisado en un lote anterior
.\.runtime\php83\php.exe artisan bshop:reservations-expire --apply --limit=100 --after-id=123
```

Lote de 1 a 1000 pedidos; JSON con modo, fecha de corte, revisados y resultados.
Salida 0 normal, 1 con fallos/inconsistencias, 2 si el límite no es válido. Una
excepción revierte íntegramente ese pedido y permite seguir con los demás; el
detalle se registra en el log de errores. Los pedidos protegidos se excluyen de
la selección del lote y vuelven a verificarse dentro de la transacción.
El JSON incluye `after_id`, `next_after_id` (último ID revisado o null si no hubo
resultados) y `attention` con los IDs cuyo resultado fue `failed`, `inconsistent`
o `changed`. Una excepción o inconsistencia conserva el código de fallo del lote.
`--after-id` permite continuar manualmente sin volver a procesar los IDs anteriores;
no repara ni resuelve esos pedidos. El cursor no se guarda y cada ejecución usa
una nueva fecha de corte: hay que revisar los casos señalados y volver a ejecutar
desde el principio después. El programador normal sigue comenzando desde cero.

Cada pedido usa transacción y reintentos de deadlock, bloqueando productos,
variantes, sucursal, almacén, saldos, pedido y reservas en el orden del escritor.
Si cambiaron sus variantes mientras esperaba, se omite para reintentar después.
No crea saldos faltantes en diagnóstico ni al omitir. Al repetirse, una reserva
ya vencida no genera otro movimiento. Pagos, facturación y edición se serializan
con el mismo bloqueo del pedido y de su ubicación.
Las comprobaciones de facturas y pagos dentro de la transacción son lecturas
con bloqueo, para observar confirmaciones ocurridas mientras esperaba, incluso
bajo REPEATABLE READ de MySQL.

Laravel declara ejecución cada cinco minutos con `--apply`, sin solapamiento,
y log `storage/logs/reservation-expiry.log`. **No está instalada/verificada la tarea
persistente del sistema operativo**: configurar y validar el programador en el
destino antes de ofrecer ejecución automática en servicio. La retención de logs
se configura en despliegue.

## Validación y pendientes

Ocho pruebas de backend nuevas cubren valor desactivado, permisos, límites,
conflictos, fecha exacta, cantidades fraccionarias, reintento, conciliación,
CxC, pagos/facturas/entrega parcial protegidos, edición sin prórroga, reservas
históricas, apartado vacío, lotes y rollback completo. Pruebas sobre SQLite
`:memory:`; no acreditan concurrencia real ni rango DECIMAL(19,4) completo en MySQL.
Doce casos nuevos de navegador cubren configuración y detalle en seis perfiles.

La demo real se consultó sin aplicar vencimientos ni cambiar la configuración.
El verificador en MySQL aislado aprobó sus ocho casos, incluido el selector tras
reversión de pago; ver
`VERIFICACION_RESERVAS_MYSQL.md`. Pendientes: programador del destino, aceptación
en tablet física y política comercial para cancelaciones con abonos.

Ejecutor verificable del programador, estado y plan Windows preparados:
`PROGRAMADOR_WINDOWS.md`. El verificador de carreras y rango extremo se ejecutó
en MySQL aislado; la instalación del programador sigue pendiente.
