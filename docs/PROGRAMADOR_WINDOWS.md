# Programador de tareas de BShop en Windows

El negocio dispone de tareas declaradas para vencer reservas cada cinco minutos
y revisar inventario cada hora. Declararlas en Laravel no instala el programador
del sistema operativo. La instalación y aceptación en el equipo destino siguen
pendientes.

## Ejecutor con estado verificable

```powershell
# Consulta de solo lectura; no ejecuta tareas ni genera un latido ficticio
.\.runtime\php83\php.exe artisan bshop:scheduler

# Ejecuta las tareas que correspondan en ese momento
.\.runtime\php83\php.exe artisan bshop:scheduler --run
```

El segundo comando debe ejecutarse cada minuto desde el directorio del proyecto.
Utiliza un bloqueo de archivo por instalación para excluir ejecuciones simultáneas.
Si hay otra ejecución, devuelve `already_running` sin sobrescribir su estado.
El bloqueo se libera al terminar o cerrar el proceso, sin caducidad artificial que
pueda permitir dos ejecuciones mientras una tarea lenta sigue activa.

Se guarda estado privado en `storage/app/private/operations/scheduler.json` por
escritura temporal y renombrado, para que la consulta no lea un JSON a medio escribir.
Contiene fecha UTC, PID, resultado del ejecutor y último resultado por tarea.
No registra comandos completos, credenciales ni mensajes privados de excepciones.

La consulta devuelve `health`:

| Valor | Interpretación |
|---|---|
| `never_run` | No existe evidencia de ejecución |
| `running` | Ejecución iniciada recientemente y aún sin finalizar |
| `ok` | Ejecutor reciente y últimos resultados registrados sin fallos |
| `failed` | Falló el ejecutor o el último intento de alguna tarea |
| `stale` | No hay inicio/fin reciente en los últimos cinco minutos |

Salida 0 para `ok`/`running`, 1 para otros estados. Con `--run`, salida 1 si falla
el ejecutor o permanece un fallo de tarea. Laravel puede completar `schedule:run`
con salida 0 aunque un comando hijo falle: BShop observa los eventos de finalización
y fallo para conservar el resultado real. Una ejecución posterior sin tareas
pendientes no borra el fallo; debe recuperarse esa misma tarea.

Un JSON corrupto o un estado incompleto o incoherente detiene la ejecución antes
de iniciar tareas; se validan estado, fechas, PID y resultados por tarea y se
conserva el archivo para diagnóstico. Si se retira definitivamente una tarea fallida, archivar/revisar el
estado antes de restablecerlo bajo control operativo. `ok` acredita el ejecutor y
los resultados registrados, no la aceptación funcional de servicios externos ni
la instalación de una tarea de Windows. Los errores detallados permanecen en los
logs privados de Laravel y de cada tarea.

## Preparación e instalación

```powershell
# Predeterminado: genera solo XML revisable, no registra ninguna tarea
.\scripts\scheduler-task.ps1 -Action Plan

# Registra únicamente si no existe una tarea de esta instalación
.\scripts\scheduler-task.ps1 -Action Install

# Consulta Windows y el estado real de BShop
.\scripts\scheduler-task.ps1 -Action Status
```

El nombre identifica la ruta del proyecto mediante un hash. El plan se guarda en
`storage/app/private/operations/scheduler-task.xml`. Utiliza rutas absolutas,
directorio de trabajo explícito, privilegios normales, intervalo de un minuto y
política `IgnoreNew`. No reemplaza tareas existentes ni pide/almacena contraseñas.
Usa `php-win.exe`, junto al PHP seleccionado, para evitar una consola visible;
la consulta manual utiliza `php.exe`. Admite `-Php` para elegir otro runtime.

**El plan usa la sesión interactiva del usuario actual:** solo corre mientras ese
usuario está conectado. Para un servidor que deba operar sin sesión, preparar una
cuenta de servicio y sus permisos en la fase de despliegue. Este plan no acredita
ejecución permanente tras cerrar sesión ni reiniciar el servidor.

Antes de instalar, verificar la base efectiva con `bshop:environment` y el destino
de entrega. No activar un programador sobre una base equivocada. Cada tarea sigue
sus propias reglas: el plazo de reservas de la demo permanece desactivado, y la
reconciliación de inventario solo consulta.

Después de instalar, confirmar al menos dos ejecuciones, latido reciente y resultado
de las tareas en sus horarios; no basta ver una tarea registrada o `LastTaskResult=0`.
Definir retención de los logs de vencimiento y reconciliación durante despliegue.

## Evidencia actual y límites

- Suite PHP: 132 pruebas / 1,245 aserciones sobre SQLite `:memory:`; cuatro nuevas
  de estado, fallo persistente/recuperación, exclusión y estado corrupto.
- Pint aprobado para los archivos nuevos y el proveedor modificado.
- Sintaxis PowerShell aprobada por el parser. La ejecución del script fue rechazada
  por AuthorizationManager del entorno; no se instaló una tarea ni se generó XML
  mediante su ejecución. No se modificaron políticas de seguridad.
- Consulta local real: `never_run`, sin ejecutar tareas ni modificar reservas.
- Regresión de interfaz existente: 286 casos; esta fase no modifica la interfaz.
- Verificación de reservas y reconciliación en MySQL aislado aprobada; ver
  `VERIFICACION_RESERVAS_MYSQL.md`.
- Validación de estados incompletos/incoherentes: suite completa 259 pruebas y
  3.517 aserciones en SQLite `:memory:`; Pint aprobado en archivos modificados.
