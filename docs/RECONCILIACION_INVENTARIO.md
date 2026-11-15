# Revisión y reconciliación de inventario

Implementa el diagnóstico F5.07 para el negocio único y sus sucursales. En
**Inventario → Revisar inventario** se compara el almacén seleccionado sin cambiar
existencias, reservas, pedidos, movimientos ni auditoría. No hay reparación automática.

## Consulta y alcance

`GET /api/v1/business/stores/{store}/warehouses/{warehouse}/reconciliation`
requiere sesión, acceso a la sucursal y permiso `inventory.view`. Un almacén de
otra sucursal devuelve 404. Límite: 20 consultas por minuto.

Parámetros: `only_issues=1` por defecto; `only_issues=0` incluye variantes sin
diferencias. `page` empieza en 1; cada página contiene hasta 25 variantes. El
resumen siempre cubre todo el almacén, independientemente del filtro o la página:
`checked`, `consistent`, `with_differences`, `differences`, `movements`.
`generated_at` identifica el momento de la revisión.

Se incluyen variantes con seguimiento de stock y aquellas con saldo, movimientos
o reservas, conservando productos archivados. Consultar variantes sin saldo no
crea registros de existencias. Las cantidades se calculan con BCMath a cuatro
decimales; las reservas pendientes descuentan lo entregado y lo liberado.

| Código | Diferencia detectada |
|---|---|
| `physical_ledger` | Físico actual distinto de la suma de movimientos |
| `reserved_active` | Reservado actual distinto del pendiente de reservas activas |
| `reserved_ledger` | Reservado actual distinto de los movimientos de reserva |
| `balance_version` | Versión del saldo distinta de la última del kardex |
| `ledger_chain` | Saldos históricos o versiones inconsistentes en la cadena |
| `history_incomplete` | Movimiento histórico sin saldo posterior o versión |
| `reservation_state` | Reserva activa sin pendiente, en línea retirada o pedido cancelado |

El servicio mantiene una transacción de lectura con bloqueos compartidos de
sucursal y almacén, en ese orden. Los escritores de stock toman esos bloqueos
exclusivamente. No adquiere después bloqueos anteriores de producto/variante;
conserva el orden del escritor y evita leer un movimiento parcialmente confirmado.
SQLite usa su instantánea transaccional. La concurrencia de esta nueva revisión
con escritores MySQL aún requiere validación específica en la instancia aislada.

## Comando y programación

```powershell
.\.runtime\php83\php.exe artisan bshop:inventory-reconcile --json
.\.runtime\php83\php.exe artisan bshop:inventory-reconcile --warehouse=1 --json
```

Sin `--warehouse` consulta todos los almacenes. Salida 0: sin diferencias;
1: diferencias detectadas; 2: parámetro inválido o almacén inexistente. JSON incluye
`read_only: true`, resumen por almacén y hasta 100 variantes con diferencias por
almacén; `truncated` indica si hay más. La pantalla/API permiten consultar el resto.

`routes/console.php` declara ejecución horaria sin solapamiento, con salida en
`storage/logs/inventory-reconciliation.log`. **La tarea del programador del sistema
operativo no está instalada/verificada**: la declaración Laravel no activa por sí
sola la ejecución periódica. La retención del registro se definirá en despliegue.
No expira reservas ni envía alertas externas.

## Evidencia y límites

- Suite PHP completa: 120 pruebas / 1,071 aserciones sobre SQLite `:memory:`.
- Navegadores: 274 pruebas en seis perfiles; 12 cubren esta pantalla, errores,
  reintento, filtro, giro, foco y ausencia de escrituras.
- Build TypeScript/Vite aprobado.
- Demo real autenticada en puerto 8027: almacén principal, 22 variantes,
  40 movimientos y ninguna diferencia; segundo almacén, 22 variantes y 0 movimientos.
- Cantidades fraccionarias, corrupción controlada, permisos, entrega parcial y
  transferencias probadas en datos aislados; no se modificó stock de la demo.

SQLite NUMERIC puede convertir números grandes a REAL; estas pruebas no acreditan
todo el rango DECIMAL(19,4). La nueva revisión requiere todavía la prueba extrema
y de concurrencia sobre MySQL aislado. El vencimiento de reservas ya dispone de
fase funcional independiente; ver `EXPIRACION_RESERVAS.md` y sus pendientes operativos.

La activación del programador tiene ejecutor con seguimiento y plan Windows
revisable (`PROGRAMADOR_WINDOWS.md`). El verificador específico MySQL está
preparado (`VERIFICACION_RESERVAS_MYSQL.md`), pero su ejecución sigue pendiente.
