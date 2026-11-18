# Progreso y evidencia

**Arquitectura vigente:** negocio único por instalación, por instrucción posterior
del propietario. Los registros anteriores al apartado de conversión conservan
la evidencia histórica; sus pruebas multiempresa no describen la versión actual.
Consultar `ARQUITECTURA_VIGENTE.md` y el último registro antes de continuar.

## 2026-11-12 — F0 / análisis inicial

### Cambios

Se crearon `README.md`, `docs/ANALISIS_INICIAL.md` y este registro en la carpeta
de destino inicialmente vacía. Fuentes originales en `E:\KEYLASHOP`.
Rama/commit: no disponibles; no se ha inicializado repositorio ni creado commit.

### Comandos ejecutados y resultados

Directorio de comprobaciones: `C:\xampp\php\www\BShop`.

| Comando / inspección | Resultado |
|---|---|
| Lectura de directorio con Read | BShop vacío antes de la documentación |
| Read de cinco Markdown y SQL completo; inspección del duplicado | Requisitos y hallazgos en análisis |
| `Get-FileHash -LiteralPath "E:\KEYLASHOP\03_KEYLASHOP_MODELO_RELACIONAL_REFERENCIA_V3.sql","E:\KEYLASHOP\03_KEYLASHOP_MODELO_RELACIONAL_REFERENCIA_V3 (1).sql" -Algorithm SHA256` | Ambos C60EA722763BB9B114E3F6B2B1B58E9DD6766461ACC93327829EA62D05A89FA5 |
| `php -v` / `php -m` | PHP 8.2.33, BCMath/PDO MySQL/GD/Fileinfo disponibles |
| `composer --version` | 2.8.11 usando PHP 8.2.33 |
| `node --version` / `npm --version` | v24.4.0 / 11.4.2 |
| `git --version` | 2.55.0.windows.2 |
| `& "C:\xampp\mysql\bin\mysqld.exe" --version` | Binario MariaDB 11.4.12, no conexión a servidor |
| `& "C:\xampp\apache\bin\httpd.exe" -v` | Binario Apache 2.4.58 Win64 |
| `[System.Environment]::OSVersion.VersionString` | Microsoft Windows NT 10.0.26300.0 local |
| `php -r "echo 'GD WebP: ', (gd_info()['WebP Support'] ? 'yes' : 'no'), PHP_EOL; echo 'memory_limit: ', ini_get('memory_limit'), PHP_EOL; echo 'upload_max_filesize: ', ini_get('upload_max_filesize'), PHP_EOL; echo 'post_max_size: ', ini_get('post_max_size'), PHP_EOL; echo 'php.ini: ', php_ini_loaded_file(), PHP_EOL;"` | WebP yes; 512M / 10M / 60M; C:\xampp\php\php.ini |
| `rg --count '^CREATE TABLE ' "E:\KEYLASHOP\03_KEYLASHOP_MODELO_RELACIONAL_REFERENCIA_V3.sql"` | 51 |
| `rg --count 'FOREIGN KEY' "E:\KEYLASHOP\03_KEYLASHOP_MODELO_RELACIONAL_REFERENCIA_V3.sql"` | 100 |
| Context7 `/laravel/docs/__branch__12.x`, requisitos y directorio público | Laravel >=8.2; servir desde public, mínimo de producto sigue siendo 8.3+ |

No hubo comandos de base de datos ni conexión SQL. No se ejecutaron suites de
pruebas: aún no existe aplicación. No se modificaron Apache, PHP compartido ni VPS.

### Checklist F0 local

Este resumen referencia los IDs del TODO original, que permanece intacto.

- [ ] F0.01 Auditoría global de repositorios previos: BShop vacío y listado de
  carpetas hermanas comprobados; proyectos relacionados no investigados en profundidad.
- [ ] F0.02 Versiones: herramientas locales comprobadas; Laravel inexistente,
  conexión efectiva a MySQL y Windows Server del VPS pendientes.
- [ ] F0.03 Leídos todos los documentos entregados y registradas inconsistencias;
  faltan fuentes citadas por el paquete, por lo que no se acredita lectura completa.
- [ ] F0.04 DDL en MySQL 8 de pruebas: pendiente de entorno aislado.
- [ ] F0.05 ER completo e INFORMATION_SCHEMA: pendiente; sólo mapa conceptual.
- [x] F0.06 Decisiones prioritarias registradas en `ANALISIS_INICIAL.md`, sección 6.
- [x] F0.07 Registro de evidencia y plan de fases creados.
- [ ] F0.08 Repositorio, develop y CI: pendientes de definición.

### Bloqueos y siguiente paso

PHP 8.2.33 activo frente al requisito 8.3+; motor instalado MariaDB frente a
MySQL 8; fuentes faltantes y decisiones comerciales pendientes. Siguiente tarea:
confirmar nombre/base documental y entorno dedicado PHP/MySQL para cerrar F0 y
arrancar F1. Revisión estática SQL-01…13 preparada para migraciones.

## Decisión posterior del propietario — identidad

- Nombre del sistema confirmado: **BShop**.
- Primera empresa usuaria (tenant): **KeylaShop**.
- Se actualizaron README y análisis para reflejar la separación entre plataforma
  y empresa. El nombre del tenant será configurable; los datos del usuario dueño
  todavía no se han proporcionado.
- Verificación: revisión del parche documental; no requiere pruebas de aplicación.
- Esta decisión resuelve la duda de nombre registrada en el análisis inicial.

## 2026-11-12 — F1 inicial: aplicación base y administración multiempresa

### Alcance y decisiones

Se inició el desarrollo por autorización del propietario. Identidad BShop y
primera empresa KeylaShop. F0 conserva el bloqueo explícito de MySQL 8 real;
se avanzó en base/autorización con SQLite aislado sin afirmar equivalencia InnoDB.
PHP 8.3+ resuelto mediante runtime dedicado, sin cambiar PHP de XAMPP.

Plan aplicado: base Laravel/entorno → migraciones de identidad/contexto/acceso
→ servicios/endpoints → UI Vue → tests/QA/build → registro de evidencia.
Dependencias: Composer/Sanctum y Vue/Vite/Tailwind. Riesgos: FKs y locks aún sin
MySQL, SMTP sin configurar y reglas comerciales/documentos faltantes.

### Código y archivos principales

- `composer.json`/lock: PHP ^8.3, Laravel 12.72.0, Sanctum 4.4.0, BCMath.
- `package.json`/lock, `vite.config.js`, `tsconfig.json`: Vue 3 + TS + Vite + Tailwind.
- `database/migrations/2026_11_12_000100_create_tenancy_tables.php`: empresas,
  memberships, roles/pivot, sucursales, almacenes, accesos y auditoría con FKs compuestas.
- `app/Domain/Tenancy/*`: contexto scoped, autorización de sucursal, onboarding
  transaccional y auditoría. `app/Models/*` con scopes cerrados y protección de tenant.
- `app/Http/Middleware/*`, Requests, Controllers/Api/V1 y `routes/api.php`:
  autenticación por sesión, recuperación, permisos por acción y administración inicial.
- `app/Jobs/Middleware/WithTenantContext.php`: revalidación de acceso al ejecutar
  jobs y limpieza de contexto incluso si fallan.
- `resources/js/App.vue`, `components/*`, `api.ts`, `types.ts`, CSS y vista Blade:
  acceso, recuperación, onboarding, selector empresa/sucursal, resumen real,
  crear/editar/desactivar ubicaciones y editar identidad de empresa.
- `app/Console/Commands/*`: diagnóstico de destino y demo estrictamente local.
- `tests/Feature/AuthenticationTest.php`, `TenancyTest.php`, protección TestCase,
  `phpunit.xml`; workflow `.github/workflows/checks.yml`.
- `docs/API.md`, `DESARROLLO.md`, copia histórica `docs/referencia/`, tablero
  `qa/12_TODO_MAESTRO.md` y smoke browser.

### Conexiones verificadas antes de operar

Vista previa: entorno **local**, conexión/driver **sqlite**, sin host/puerto,
base **C:/xampp/php/www/BShop/database/bshop-local.sqlite**.
Pruebas: entorno **testing**, driver **sqlite**, base **:memory:**, DB_URL vacío.
TestCase aborta antes de migrar si el destino no coincide.
No se abrió conexión MySQL/MariaDB ni se ejecutaron comandos en el VPS.

### Comandos y resultados reales

Comandos desde `C:\xampp\php\www\BShop` salvo descarga inicial. El alias de lectura
`PHP83` en este registro corresponde al ejecutable
`C:\xampp\php\www\BShop\.runtime\php83\php.exe` (en las primeras ejecuciones se
usó la misma distribución desde el temporal opencode).

| Operación | Resultado |
|---|---|
| `composer create-project laravel/laravel "C:\xampp\php\www\BShop\.bootstrap" "^12.0" --no-install --no-scripts --no-interaction` | Skeleton 12 creado en staging, integrado sin sobrescribir documentación |
| PHP portable descargado desde windows.php.net y hash verificado | PHP 8.3.36 NTS; copia dedicada en `.runtime/php83`; sin reemplazar XAMPP |
| `PHP83 C:\ProgramData\ComposerSetup\bin\composer.phar install --no-interaction --prefer-dist` | Dependencias resueltas y lockfile creado |
| `PHP83 artisan key:generate --no-interaction` | APP_KEY local generada, excluida de Git |
| `PHP83 artisan bshop:environment` | Destino local SQLite comprobado |
| `PHP83 artisan migrate --no-interaction` | Cuatro migraciones aplicadas en archivo local nuevo; no hubo fresh/refresh/rollback |
| `PHP83 artisan bshop:demo` | KeylaShop + propietario sintético; contraseña aleatoria, no guardada en docs |
| `PHP83 artisan route:list --path=api --except-vendor` | 13 rutas API iniciales registradas |
| `PHP83 artisan test --compact` (última ejecución) | **20 passed, 90 assertions**; 18 pruebas de autenticación/tenancy/jobs y 2 de skeleton |
| `PHP83 vendor/bin/pint`, seguido de `PHP83 vendor/bin/pint --test` | Estilo aplicado; verificación final passed |
| `npm install`, `npm run build` | Typecheck + build correctos; JS 157.45 kB, gzip 58.40 kB; CSS 52.78 kB, gzip 13.24 kB |
| `npm audit` | 0 vulnerabilidades tras actualizar vue-tsc a 3.3.14 |
| `PHP83 C:\ProgramData\ComposerSetup\bin\composer.phar audit` | Sin avisos de vulnerabilidad |
| `git init -b develop` | Repositorio local en develop; sin commits ni remoto |
| `git check-ignore .env .runtime/php83/php.exe database/bshop-local.sqlite .bootstrap/composer.json` | Los cuatro destinos están excluidos de Git |
| `PHP83 C:\ProgramData\ComposerSetup\bin\composer.phar check-platform-reqs` | Todos los requisitos del lockfile satisfechos |
| Playwright en servidor http://127.0.0.1:8017 | Login, alta/edición de ubicaciones, onboarding, cambio de empresa, PATCH de empresa y navegación móvil comprobados |

### Fallos detectados y corregidos

- Middleware de sesiones estándar no era compatible con RequestGuard de Sanctum;
  se usó AuthenticateSession específico de Sanctum.
- Un filtro dinámico de tenant en la relación de roles fallaba con eager loading;
  se sustituyó por comparación entre columnas de pivot/rol, respaldada por FK.
- Atributos default del usuario/almacén se hicieron explícitos en modelo para
  consistencia antes de refrescar desde BD.
- Se separó rate limit de solicitud de recuperación y consumo de token.
- Se actualizó vue-tsc tras avisos moderados de su árbol de dependencias.
- Se corrigió generación de slug al escribir el nombre, foco inicial y retención
  de foco de diálogos. Verificación browser final: slug manual conservado y foco correcto.

### Evidencia funcional y seguridad

Suite: invitado 401; credenciales erróneas/inactivo rechazados; logout; rate limit;
CSRF real con 419 sin token; recuperación genérica y token de un solo uso;
onboarding completo/auditado; aislamiento tenant y sucursal; vendedor sólo lectura;
payload tenant_id rechazado; códigos únicos; principal de almacén único en ejecución
secuencial; suspensión; platform admin sin bypass; FK compuesta negativa en SQLite;
contexto requerido y sin fugas HTTP/jobs; usuario revocado no ejecuta job.

Browser: demo local con segunda empresa sintética Empresa QA y sucursal/almacén
de prueba; cambio de empresa no mezcla listados. Sin overflow horizontal en
1024×768, 768×1024 y 390×844; navegación móvil probada. Sin errores JS en la
última página. Esto no equivale a Safari/iPad real ni QA integral F13.

### Checklist y pendientes

Marcados en tablero local con esta evidencia: F0.06/07/08 y F1.01/02/04/05/08.
F1.03 queda parcial: autenticación y recuperación backend verificadas, falta SMTP
real. F1.06 parcial: flag de plataforma separado y sin bypass; panel/operación
privilegiada de plataforma pendientes. F1.07 parcial: alta/lectura/edición de empresa
y ubicaciones, desactivación de ubicaciones; administración de accesos por UI y
ciclo completo del tenant pendientes. QA-001/002 sólo cubiertos para recursos
existentes, no marcar aceptación global antes de tener catálogo/inventario/pagos.

Próximo bloque: MySQL 8 de pruebas separado, revisión de constraints/ER y completar
F1; continuar F2 y catálogo F3 respetando dependencias. No se ha implementado
inventario, apartados, facturación, pagos, nómina, R2, PWA instalada ni producción.

## 2026-11-12 — Continuación F1: equipo, invitaciones y MySQL real

### Plan, cambios y alcance

Se continuó F1 con gestión de equipo y validación de la infraestructura de datos.
Archivos principales: `TeamService`, `AcceptInvitation`, `TenantInvitation`,
`TeamAccessRequest`, `TeamController`, `InvitationController`, migración aditiva
`2026_11_12_000200_create_team_invitations.php`, `TeamView.vue`, `InviteView.vue`,
rutas/API/contexto de navegación y `tests/Feature/TeamAccessTest.php`.

Propietario puede invitar, asignar rol/sucursales y suspender/reactivar membresías.
No cambia identidad/contraseña/estado global de otra cuenta. El último propietario
activo no puede retirarse. Se revalida permiso del actor después de adquirir el
lock del tenant, evitando usar autorizaciones revocadas durante la espera.

Invitación: 64 caracteres aleatorios, sólo hash persistido, 72 h de vigencia,
enlace manual mostrado una vez, GET inocuo, POST transaccional con lock,
revalidación de emisor y acceso, registro de cuenta nueva o autenticación previa
de cuenta existente. Sesión de contraseña invalidada tampoco puede aceptar.
Una colisión concurrente de email global se rechaza sin asociar una cuenta ajena.
No hubo envío SMTP; enlaces de invitación se comparten manualmente.

### Respaldo y migración de la demo

Destino mostrado antes de operar: local / sqlite / sin host / archivo
`C:/xampp/php/www/BShop/database/bshop-local.sqlite`.

- `PHP83 artisan bshop:environment`: conexión local verificada.
- `PHP83 scripts/backup-local.php`: copia creada en
  `.runtime/bshop-backup-20261112-161442.sqlite`; integridad **ok**, 18 tablas.
- `PHP83 artisan migrate --no-interaction`: sólo nueva migración aplicada;
  agregó tablas de invitación/acceso y permiso members.manage a roles owner.
- No se refrescó ni reemplazó la demo; sus usuarios/datos previos se conservaron.

### MySQL 8 aislado

Docker CLI existía pero no había motor activo. Se descargó la distribución
portable oficial MySQL 8.4.13 y se comprobó MD5 publicado
`7b94489d5524dea9b27097068bea25a8`. No se instaló servicio ni se modificó XAMPP.

Destino previo explícito: **testing**, **127.0.0.1:33078**, bases nuevas
**bshop_testing_f1** / **bshop_testing_reference**, datadir nuevo
`C:/xampp/php/www/BShop/.runtime/mysql-data`.
Credenciales aleatorias privadas dentro de `.runtime`, no versionadas ni impresas.
Root quedó con contraseña antes de escuchar en red, mediante init-file de arranque.
Las ejecuciones normales posteriores no repiten ese archivo.

`scripts/mysql-test-connection.php` exige host/puerto/base/version permitidos y
comprueba la conexión efectiva, bind **127.0.0.1** y datadir exacto del proyecto.
Usuario de pruebas tiene acceso únicamente a esas dos bases. No se abrió conexión
a base remota, VPS ni MariaDB compartido. La aplicación de vista previa sigue
usando SQLite, separada de los fixtures MySQL.

### Comandos y evidencia MySQL

`PHP83` = `C:\xampp\php\www\BShop\.runtime\php83\php.exe`.

| Comando/operación | Resultado real |
|---|---|
| `PHP83 scripts/prepare-test-mysql.php` | Metadatos privados para instancia nueva; rechaza datos/credenciales existentes |
| `mysqld.exe --defaults-file="C:\xampp\php\www\BShop\.runtime\mysql-test.ini" --initialize-insecure --console` | Inicialización de datadir nuevo; sin servicio compartido |
| Arranque con mismo defaults-file e init-file exclusivo inicial | Servidor efectivo 8.4.13 en 127.0.0.1:33078; raíz protegida y usuario de pruebas configurados |
| `PHP83 scripts/verify-mysql.php bshop_testing_f1` | Cinco migraciones aplicadas; 19 tablas, 16 FK, 45 índices; FK cruzada rechazada con 1452; onboarding/invitar/aceptar correctos |
| `PHP83 scripts/verify-mysql.php bshop_testing_reference` | DDL recibido ejecutado: 51 tablas, 100 FK, 56 CHECK, 246 índices |
| `PHP83 scripts/verify-mysql-constraints.php bshop_testing_reference` | CHECK rechaza decimales de moneda >4, reserva > físico y stock negativo; código MySQL 3819 |
| `PHP83 scripts/document-reference-schema.php bshop_testing_reference` | 100 FK comparadas por columnas/destinos, 136 índices explícitos por nombre/orden/unicidad; ER/diccionario/manifiesto generados |
| `PHP83 scripts/verify-mysql-concurrency.php bshop_testing_f1` (última ejecución) | Tres carreras reales con procesos/conexiones separados y barrera común; resultados abajo |
| `PHP83 scripts/mysql-test-connection.php bshop_testing_f1` (final) | Versión 8.4.13, base/puerto correctos, bind 127.0.0.1 y datadir exacto verificados |

El DDL de referencia se ejecutó eliminando únicamente CREATE DATABASE/USE del
preámbulo en memoria para respetar el esquema aislado. El original no cambió.
Las pruebas de constraints usan filas sintéticas en transacción y rollback.
Las pruebas de concurrencia retienen fixtures sólo en la BD de pruebas para inspección.
No se ejecutaron fresh/refresh/truncate/drop.

#### Concurrencia demostrada

1. Dos propietarios intentan degradarse simultáneamente: resultados **422/200**;
   queda exactamente un propietario activo.
2. Dos procesos aceptan el mismo token anónimamente: resultados **200/410**;
   exactamente una cuenta y una membership.
3. Dos empresas invitan al mismo correo nuevo y se aceptan simultáneamente:
   resultados **422/200**; la cuenta queda asociada a una sola empresa, la otra
   requiere iniciar sesión antes de aceptar. No se secuestra una cuenta global.

No son simulaciones secuenciales ni pruebas de inventario/pagos; esos módulos
siguen pendientes. El modelo de referencia válido como DDL conserva las brechas
de dominio SQL-01…13 para resolver al convertirlo a migraciones F2.

### Pruebas y navegador

- `PHP83 artisan test --compact` final: **34 passed, 179 assertions** en SQLite
  `:memory:` con protección de destino previa a migraciones.
- `PHP83 vendor/bin/pint --test` y `PHP83 vendor/bin/pint scripts --test`: passed.
- `npm run build`: typecheck/build correctos; JS 170.33 kB (gzip 61.82 kB),
  CSS 53.65 kB (gzip 13.44 kB).
- `npm audit`: 0 vulnerabilidades. Sin nuevas dependencias Composer.
- Verificación Git: `.env`, datos SQLite, datos/credenciales/init-file de MySQL
  y fixtures con tokens están excluidos. Sigue develop sin commits ni remoto.
- Prueba navegador: propietario invita a `equipo.qa@example.test`; contexto
  aislado registra `Vendedora QA`; sólo ve Sucursal principal, API equipo 403.
  Suspensión de membership → siguiente workspace 404; impedir retiro del último
  propietario y revocación de segunda invitación comprobados desde UI.
- Equipo sin overflow en 1024×768, 768×1024 y 390×844.
  Captura: `E:\KEYLASHOP\.playwright-mcp\bshop-team-mobile.png`.

Los primeros tests de cambio de actor compartían el cache del guard Sanctum y
hash de sesión en el mismo cliente de pruebas. Se corrigió la simulación de
identidades separadas; el flujo también se verificó con contextos reales aislados.
Se agregó regresión para sesiones invalidadas por cambio de contraseña.

### Checklist y siguiente bloque

F0.04 y F0.05 acreditados, junto a sus criterios de DDL e informe técnico. F1
avanzó con gestión de equipo; pendientes SMTP, configuración real del propietario,
panel/operación de plataforma y ciclo comercial completo de tenant. No marcar
F1/F2 completos: las 51 tablas de referencia no equivalen a módulos implementados.

Siguiente: convertir modelo restante a migraciones F2 con correcciones de
integridad del análisis, ampliar factories/datos sintéticos y preparar catálogo
F3. Mantener la suite de autorización al incorporar cada recurso.

## 2026-11-12 — Decisión del propietario: entrega para un negocio y sus sucursales

### Cambio de arquitectura autorizado

El propietario aclaró que la entrega es una instalación para un cliente, sin
tenants ni administración de múltiples empresas. KeylaShop es el único negocio
de esta instalación. Esta indicación sustituye las partes SaaS de la referencia.
Se creó `ARQUITECTURA_VIGENTE.md` y protocolo local para conservar la decisión.

Se sustituyó la arquitectura activa, no sólo la visibilidad del selector:

- Perfil único `business_profile`, protegido por CHECK id=1; configuración y
  cuota del negocio como filas únicas.
- Usuarios de la instalación, `roles`/`role_user` y acceso `store_user`.
- `StoreContext` y autorización por sucursal, sin contexto de empresa.
- API singular `/api/v1/business/...`; no hay rutas de alta de empresas/slugs.
- Sin tablas de tenants/membresías/suscripciones, sin columna tenant_id y sin
  is_platform_admin. Jobs revalidan usuario, permiso y sucursal.
- Conservadas las relaciones de cliente/sucursal/moneda de inventario y finanzas.
- Instalación por consola `bshop:install`: un negocio, propietario y sucursal
  inicial. Rechaza repetir el alta. Interfaz sin creación/selección de empresas.

### Rescate, conversión y verificación

Destino inicial mostrado: local / sqlite / sin host / archivo
`C:/xampp/php/www/BShop/database/bshop-local.sqlite`.
`scripts/backup-local.php` generó y verificó
`.runtime/bshop-backup-20261112-183402.sqlite`, integridad ok, 64 tablas.

`export-legacy-business.php` leyó exclusivamente ese respaldo en modo sólo lectura
y seleccionó KeylaShop (ID histórico 1). Exportó dos usuarios, tres roles, dos
sucursales, dos almacenes, tres accesos a sucursal, doce auditorías y dos
invitaciones con sus vínculos. Contraseñas se conservaron como hashes, nunca se
imprimieron ni se cambiaron.

Se mostró simulación de archivo reversible para 47 archivos del código anterior,
con rutas, destinos y hashes; se verificó el plan antes de moverlos a
`.runtime/legacy-multibusiness`. Además se conservó snapshot del código/vistas y
se archivaron controladores/documentación sustituidos. No se eliminó la base
anterior ni las filas de otros perfiles sintéticos.

Nuevo destino mostrado y utilizado: local / sqlite / sin host /
`C:/xampp/php/www/BShop/database/bshop-business.sqlite` (archivo nuevo).
Migraciones nuevas aplicadas, seguidas de `import-single-business.php`.
Importación verificada por conteos, claves foráneas y hash de contraseña de cada
usuario. `PRAGMA foreign_key_check` sin errores. Settings/cuota inicializados.
El usuario suspendido conservó su estado; la cuenta de demo conserva su contraseña.

### Código y documentación actuales

`app/Domain/Business`, modelos BusinessProfile/Role/TeamInvitation, gates sobre
usuario, controladores de negocio/equipo/invitaciones, middleware de sucursal,
`WithStoreContext`, factories y `BusinessFixtureSeeder` para un negocio con dos
sucursales. Migraciones activas regeneradas sin scopes de empresa ni SaaS.

README, API, DESARROLLO y TODO fueron sustituidos conservando sus versiones
anteriores en el archivo privado. ER/diccionario/manifiesto actuales se generaron
desde la nueva base MySQL. Las fuentes y resultados anteriores quedan como
evidencia histórica, no como funcionalidades activas.

### Pruebas actuales

| Comando / verificación | Resultado real |
|---|---|
| `PHP83 artisan migrate --no-interaction` | Esquema nuevo aplicado a archivo SQLite separado |
| `PHP83 scripts/import-single-business.php` | Conteos y hashes conservados; integridad referencial correcta |
| `PHP83 scripts/verify-single-mysql.php provision` | MySQL 8.4.13 aislado, 127.0.0.1:33078, base NUEVA bshop_testing_single; 60 tablas, 99 FK, 60 CHECK |
| Inspección INFORMATION_SCHEMA | Cero columnas tenant_id, cero tablas multiempresa/SaaS, un perfil |
| Casos nativos MySQL | Segundo perfil rechazado 3819; sucursal inexistente 1452; principal duplicado 1062; periodo salarial NULL y cuota >5 rechazados 3819 |
| `PHP83 artisan test --compact` | **19 passed, 146 assertions** sobre arquitectura actual y SQLite :memory: protegido |
| `PHP83 vendor/bin/pint --test` | Verificación final passed |
| `PHP83 composer.phar validate --no-check-publish` | composer.json válido |
| Inspección final de app y resources/js | Sin referencias activas a tenants, memberships ni multiempresa |
| `npm run build` | TypeScript/build correctos; JS 166.52 kB (gzip 60.63), CSS 53.71 kB (gzip 13.46) |
| `PHP83 artisan route:list --path=api --except-vendor` | 18 rutas; ninguna ruta de tenant o alta de empresas |
| Navegador | KeylaShop único negocio, 0 selectores de empresas, 0 botones crear empresa; dos sucursales anteriores y usuarios conservados |
| Configuración del negocio | PATCH /api/v1/business devuelve 200 |
| Alta por invitación en contexto aislado | Nuevo usuario QA entra sólo a Sucursal principal; administración de equipo responde 403 |
| Responsive | Sin overflow en 1024×768, 768×1024 y 390×844 |

PHP83 corresponde al runtime dedicado del proyecto. La instancia MySQL y datos
anteriores permanecen intactos; sólo se creó una base exclusiva para el modelo
nuevo. No hubo comandos contra VPS/producción. Se reinició únicamente el servidor
de desarrollo propio en 8017, tras identificar/simular sus procesos, porque había
heredado la ruta SQLite anterior al cambio de .env.

Las suites multiempresa se archivaron al quedar fuera de alcance; no se cuentan
como pruebas de la versión actual. La revalidación financiera/concurrencia y la
prueba completa de reversibilidad del nuevo modelo siguen explícitamente en TODO.

Captura actual: `E:\KEYLASHOP\.playwright-mcp\bshop-single-business.png`.
Siguiente trabajo: continuar catálogo/operación para un negocio y sus sucursales,
manteniendo esta arquitectura y la instalación independiente por cliente.

## 2026-11-12 — Revisión y adaptación tablet-first

### Solicitud y alcance

El propietario indicó que la tablet será el dispositivo principal del cliente.
Se auditó y corrigió la UI existente, conservando negocio único y permisos por
sucursal. No se declararon terminados los módulos comerciales/PWA todavía pendientes.

### Hallazgos y cambios

- Selector de sucursal de 11 px/campos de 13 px: ahora 16 px; texto operativo
  legible, mejor contraste y acciones principales de 48 px. Zoom de usuario intacto.
- Navegación compacta con iconos y etiquetas en tablets 768–1199 px; menú completo
  desplegable con cierre, Escape, foco e inert del contenido de fondo.
- Sucursal activa en cabecera sticky, accesible incluso tras scroll de listas largas.
- `AppDialog.vue` nativo/Teleport: fondo inerte, cuerpo desplazable y acciones
  separadas siempre accesibles. `scrollLock.ts` coordina bloqueos anidados.
- `useVisualViewport.ts`: ajusta alto/offset ante teclado/resize, limpia listeners
  y no interfiere con pinch zoom. Meta viewport admite safe areas y resizes-content.
- Borradores conservados al girar; título con foco al abrir para no desplegar el
  teclado sin que el usuario toque un campo; correo/teléfono con teclado adecuado.
- Confirmación de guardado conservada al refrescar datos sin desmontar la pantalla.
- Guardias busy impiden doble envío antes del siguiente render; validaciones
  mantienen valores y muestran/enfocan el error dentro del diálogo.
- Revocación de invitación usa el mismo diálogo táctil en lugar de confirm nativo.
- Corrección específica WebKit: restauración de foco mediante referencia explícita
  al botón de origen y siguiente animation frame.
- Corrección de patrón HTML del código: guion escapado para el modo Unicode v
  de navegadores actuales; se prueban códigos válidos e inválidos.

Archivos: `App.vue`, CSS, BusinessSettings/TeamView/AuthView, nuevo AppDialog,
composable VisualViewport, scrollLock, meta viewport; configuración Playwright,
fixtures/specs en `tests/Browser`, runner Node y workflow de CI. Protocolo local
actualizado para aplicar estas reglas a cada pantalla futura.

### Verificación reproducible

| Comando | Resultado real final |
|---|---|
| `npm install --save-dev @playwright/test` | Dependencia de desarrollo local y lockfile actualizados |
| `PLAYWRIGHT_BROWSERS_PATH=.runtime/playwright-browsers npx playwright install chromium webkit` | Motores locales instalados; Chromium 158 y WebKit 27.2; ignorados por Git |
| `npm run build` | Typecheck/build correctos; JS 176.12 kB/gzip 64.07, CSS 67.47 kB/gzip 16.06 |
| `npm run test:tablet` | **32 passed**, 8 escenarios × 4 perfiles, 16.6 s en ejecución final |
| `PHP83 artisan test --compact` | **19 passed, 146 assertions**; destino protegido testing/SQLite :memory:, sin host remoto |
| `PHP83 vendor/bin/pint --test` | passed |
| `npm audit` | 0 vulnerabilidades |

Perfiles: WebKit/iPad 768×1024 y 1024×768; Chromium táctil 800×1280 y 1280×800.
También se giró pantalla con un formulario abierto y se redujo viewport a
1024×380 / 390×340 para simular espacio disponible con teclado.

Escenarios: navegación, controles >=44×44, campos >=16 px, giro/borrador, menú
con fondo aislado y foco, 60 sucursales en selector, rechazo/doble envío seguro,
sucursal accesible con 30 usuarios y scroll, login/invitación y fallback móvil.
La primera ejecución tuvo 30/32: ambas fallas de foco WebKit fueron corregidas;
la ejecución final y regresión de pattern pasaron los 32 casos.

**Límite de la evidencia:** esta suite intercepta todas las rutas API con fixtures
controlados y no escribe datos del cliente. Prueba la UI real en motores reales,
pero los perfiles no son tablets físicas ni reproducen un teclado nativo real.
Se agregó la misma regresión a CI; no se ha ejecutado remotamente.

### Comprobación adicional sin mocks en la demo

Destino comprobado al inicio: entorno local, driver sqlite, sin host,
`C:/xampp/php/www/BShop/database/bshop-business.sqlite`.
Se abrió la edición de Sucursal de prueba con sus valores existentes; tras girar
y reducir viewport se guardó el mismo contenido: PATCH 200 y confirmación visible.
No se recreó base ni se alteraron identidades/configuración de negocio.

Con alto visible 380 px, el diálogo quedó entre y=16 y y=364, sus acciones entre
y=294 y y=363, Cancelar con alto 48 px. Campos a 16 px y página de fondo bloqueada.
Verificación final de código válido y navegación con consola sin errores.

Capturas en `E:\KEYLASHOP\.playwright-mcp`: `bshop-tablet-portrait.png`,
`bshop-tablet-landscape-final.png`, `bshop-tablet-login-portrait-final.png`.
Matriz/criterios y comandos en `qa/TABLET.md`.

### Pendiente de aceptación

Probar en la tablet física del cliente (Safari/Chrome reales, teclado/selector
nativos, safe areas y giro físico) y repetir al implementar catálogo, fotos,
ventas, apartados y cobros. La cámara y PWA instalada siguen pendientes de sus
módulos. Continuar desde ahora con prioridad tablet, no sólo al final del proyecto.

## 2026-11-12 — Catálogo operativo: productos, organización y variantes

### Alcance y código

Se continuó el núcleo de F3 sobre la arquitectura de negocio único. Catálogo
compartido dentro de KeylaShop; inventario sigue perteneciendo a sus sucursales
y almacenes y no se simula disponibilidad desde este módulo.

- Migración aditiva `001000_add_catalog_versions_and_permissions`: versiones
  de producto/variante, estado de referencias y permisos de catálogo.
- Modelos Category/Brand/ProductAttribute/AttributeValue y relaciones de producto/variante.
- `ProductService`, `VariantCombinationService`, `DecimalAmount`, Requests y
  resources: mutaciones transaccionales, importes exactos, identidad de variante,
  control optimista, referencias y privacidad de costos.
- CatalogController/LookupController y rutas bajo `/api/v1/business`: consulta
  paginada, detalle, altas/ediciones, categorías jerárquicas y valores.
- UI CatalogView/ProductEditor/VariantEditor/VariantFields/OptionsManager con
  AppDialog, filtros, tarjetas, archivo y precios. Nueva entrada Catálogo en menú.

### Reglas verificadas

Propietario/administrador consultan y gestionan; vendedor sólo consulta, sin
cost_price en JSON ni controles de gestión. Un editor sin catalog.costs tampoco
puede borrar un costo enviando null. Dinero requerido como texto decimal no
negativo, hasta 15 enteros/4 decimales; BCMath antes de persistir. UI sin conversión
de precios a Number y admite coma decimal del teclado como entrada.

SKU/barras normalizados a mayúsculas y únicos incluso tras archivo. Combinación
canónica con IDs ordenados; no admite dos valores del mismo atributo ni repetir
combinaciones por otro orden. Atributos de una variante existente no se reasignan.
Variantes activas comparten atributos; datos anteriores permanecen al desactivar.

Versiones desactualizadas responden 409 sin sobrescribir; ruta de variante exige
el producto correcto. Archivo no elimina historial. Cambio de tipo/seguimiento
bloqueado con stock/movimientos/ventas. Reservas activas impiden desactivar variante.
Categorías no pueden formar ciclos y conservan padre si no se envía en PATCH.

### Destinos y respaldos

Demo: local / sqlite / sin host /
`C:/xampp/php/www/BShop/database/bshop-business.sqlite`.
Antes de aplicar la migración, `scripts/backup-local.php` creó y verificó
`.runtime/bshop-backup-20261112-215259.sqlite`, integridad ok, 61 tablas SQLite
incluyendo su metadata. `artisan migrate --no-interaction` aplicó sólo la nueva
migración; no recreó ni sembró la base del negocio.

Integración: testing / mysql / **127.0.0.1:33078 / bshop_testing_single**.
La instancia propia estaba detenida: se arrancó con su defaults-file existente,
sin reinicializar datadir ni credenciales. Script valida versión, bind y datadir.
Respaldo de roles verificado en `.runtime/catalog-test-role-backup-20261112-215338.json`.
Migración aditiva aplicada en esa base; QA dentro de transacción revertida.
No hubo acceso al VPS/producción, fresh, refresh, truncate ni eliminación de datos.

### Pruebas y comandos reales

PHP83 = runtime dedicado `.runtime/php83/php.exe`.

| Comando | Resultado final |
|---|---|
| `PHP83 artisan test --compact` | **35 passed, 262 assertions**; testing + SQLite :memory: protegido |
| `PHP83 scripts/verify-catalog-mysql.php` | Rango completo 999999999999999.9999 y costo 0.0001 exactos; snapshot de pedido intacto al cambiar precio; índice SKU rechaza duplicado |
| `npm run build` | Typecheck/build correctos; JS 204.40 kB/gzip 71.68, CSS 71.04 kB/gzip 16.70 |
| `npm run test:tablet` | **60 passed**, 15 escenarios × 4 perfiles, 33.2 s en ejecución final |
| `PHP83 vendor/bin/pint --test` | passed |
| `npm audit` | 0 vulnerabilidades |
| `PHP83 scripts/document-single-schema.php` | ER/diccionario/manifiesto actuales regenerados con nuevas columnas; 60 tablas, 99 FK |
| `git check-ignore` de respaldo de roles, SQLite, .env y reporte browser | Destinos privados/artefactos excluidos |

Los nuevos tests HTTP cubren decimales inválidos/float, SKU/barras, transacción
fallida sin producto huérfano, atributos/canonicalización, costo oculto/protegido,
archivo, versiones, ruta producto/variante, servicios, búsqueda/paginación,
taxonomía y preservación de barcode/costo omitidos. Las pruebas anteriores de
usuario/sucursal siguen aprobadas.

La suite de navegador intercepta API con fixtures: suma 28 casos de catálogo a
los 32 base. Cubre alta con varias variantes, valores compartidos, teclado/giro,
409 conservando borrador, precios string, organización, vendedor y refresco lento.
En revisión real se detectó que volver a la lista durante un refresco podía
reabrir el detalle: se corrigió el orden de cargas y se añadió regresión en los
cuatro perfiles. Una omisión de llave en esa fixture se corrigió antes de la
ejecución final de 60 casos.

### Comprobación sin mocks en la demo

Desde la UI se crearon categoría `Catálogo de demostración`, atributo `Talla QA`
y valores M/L. Alta de `Camiseta de demostración QA` con SKU DEMO-CAM-M y
DEMO-CAM-L: **201**, dos variantes, sin overflow en 768×1024. Edición de precio M
a 1300.0001: **200**, representación DOP 1,300.0001. No se crearon existencias,
pedidos ni pagos en la demo. Son datos sintéticos identificados como demostración.

Archivo y reactivación conservaron variantes; se confirmó después estado active
y búsqueda DEMO-CAM-L con respuesta **200**, un producto coincidente. El primer
script regresó al detalle por la carrera de refresco ya descrita; la regresión
de red lenta valida la corrección. Consola observada sin errores.

Capturas: `E:\KEYLASHOP\.playwright-mcp\bshop-catalog-tablet.png` y
`bshop-catalog-detail-tablet.png`. Reglas de uso/API en `docs/CATALOGO.md`.

### Checklist y siguiente bloque

Marcados el CRUD/archivo de catálogo, referencias/variantes, búsqueda, permisos
y UI tablet-first. **F3 no se declara completo:** falta importación CSV con
preview/dry-run y errores por fila. Fotos/R2, inventario, ventas/cobros y aceptación
en tablet física permanecen en sus fases. Continuar sin gestión multiempresa.

## 2026-11-12 (UTC 2026-11-13) — Importación CSV completa del catálogo

### Alcance implementado

Se cerró el pendiente de importación: plantilla, lector UTF-8 estricto, preview
privado, errores físicos por fila, paginación, revalidación, confirmación de todo
el lote, reintentos idempotentes, historial propio y reversión mediante archivo.
Sigue siendo una instalación para un negocio; no hay tenant_id ni suscripciones.

CSV añade productos nuevos, con grupos para variantes y referencias existentes.
No actualiza productos anteriores ni crea stock, pedidos, pagos o taxonomías.
Límites: 2 MiB/500 filas, 100 variantes por producto y 12 valores por variante;
guardias tempranas frente a celdas/columnas patológicas. Precios como strings,
sin float; ceros literales no se sustituyen por defaults. Soporta coma/punto y
coma, BOM, comillas escapadas, multiline y coma decimal válida.

Código: CsvCatalogReader/Export, CatalogImportValidator/Service, modelo
CatalogImport, controller/rutas, migraciones 001100/001200, CatalogImportView y
tipos; integración desde Catálogo con AppDialog y barra inferior persistente.
Payload/result cifrados, ocultos en serialización; actor/permiso de costo revalidado.
Reportes CSV neutralizan fórmulas. Confirmación no acepta datos sustitutos del cliente.

Reversión compara versiones/huellas y uso de productos bajo locks antes de
archivar ninguno. Conserva identidades, SKUs y auditoría, y rechaza todo el lote
ante cambios/stock/ventas/imágenes. Repetición es idempotente, no recrea datos.
La generación de preview y su auditoría también son una sola transacción.

### Destinos y respaldos

Local comprobado: local/sqlite/sin host,
`C:/xampp/php/www/BShop/database/bshop-business.sqlite`.

- Respaldo previo a 001100: `.runtime/bshop-backup-20261113-023111.sqlite`,
  integridad ok, 61 tablas SQLite incluyendo metadata.
- Respaldo previo a ampliar auditoría: `.runtime/bshop-backup-20261113-023537.sqlite`,
  integridad ok, 62 tablas SQLite incluyendo metadata.
- Migraciones aplicadas sin refrescar, reemplazar ni sembrar la base existente.

MySQL comprobado: testing/mysql/**127.0.0.1:33078/bshop_testing_single**,
MySQL 8.4.13, bind local y datadir del proyecto. Respaldos de esquema/auditoría
verificados en `.runtime/import-schema-backup-*.json` e
`import-audit-data-backup-*.json`. No se usó VPS/producción ni fresh/truncate.

### Hallazgos corregidos

- Primera prueba MySQL detectó que audit_logs.entity_id BIGINT no admite ULID de
  importación. Se amplió a VARCHAR(64), sin alterar valores anteriores (comparación
  nativa antes/después), y se stringify el identificador al auditar. Rollback a
  BIGINT se niega si truncaría un ID opaco; prueba de regresión incluida.
- El intento nativo previo al fix no creó productos; quedó una revisión sintética
  en la base de QA al fallar su auditoría. Se conservó para diagnóstico. Preview y
  audit ahora son atómicos, incluido un test de fallo intencional de auditoría.
- 82/84 casos browser iniciales: barra de confirmación fuera de vista al girar
  desde vertical. Barra fija identificada por archivo, con espacio inferior medido
  por ResizeObserver: los 84 casos finales pasaron.
- Seleccionar archivo nuevo descarta sólo la revisión visible, evitando confirmar
  por error el archivo anterior; revisión previa sigue recuperable por historial.

### Evidencia final

| Comando / verificación | Resultado real |
|---|---|
| `PHP83 artisan test --compact` | **59 passed, 416 assertions**, testing/SQLite :memory: protegido |
| `npm run build` | Typecheck/build correctos; JS 220.86 kB/gzip 76.05; CSS 73.71 kB/gzip 17.22 |
| `npm run test:tablet` | **84 passed**, 21 escenarios ×4 perfiles, 52.3 s final |
| `PHP83 vendor/bin/pint --test` | passed |
| `npm audit` | 0 vulnerabilidades |
| `PHP83 scripts/verify-import-mysql.php` | Preview limpio, confirmación/retry, DECIMAL completo/costo 0.0001 y reversión por archivo correctos; QA transaccional revertida |
| `PHP83 scripts/verify-import-concurrency.php` | Dos procesos/conexiones con barrera; resultados abajo, sin duplicados/huérfanos |
| `PHP83 scripts/document-single-schema.php` | ER/diccionario/manifiesto actualizados; 61 tablas, 100 FK y 62 CHECK |

Pruebas PHP cubren parser, límites, UTF-8/BOM/multiline, grupos/duplicados,
permisos/cifrado/autoría, expiración, paginación, costos, transacción fallida a
mitad de lote, recuperación/revalidación, datos sustitutos rechazados, fórmulas
y reversión usada/editada/idempotente. Siguen aprobadas las pruebas previas.
Browser usa API interceptada y no modifica DB del cliente; prueba teclado/giro,
historial, descargas, desconexión/retry y bloqueo de reversión con razón accesible.

Concurrencia MySQL final: mismo lote → **200/200**, IDs **[14,15]/[14,15]**;
dos lotes con SKU coincidentes → **200/422**, ganador **[16,18]**, rechazado sin
IDs. Se verificó aumento total exacto de productos, no sólo conteo de SKU.
Fixtures sintéticos retenidos únicamente en base de pruebas, sin limpieza destructiva.

### Flujo real desde la demo (sin mocks)

Se subió `bshop-import-smoke.csv`, dos filas físicas/servicio, precio 129.9001 y
500.00. Preview **201**, dos productos propuestos, cero errores y sin overflow.
Confirmación **200**, dos productos nuevos. Reintentos correctos con XSRF actual
**200/200**, mismos IDs; total conservado en tres productos (original más dos).
El primer replay manual usó meta-CSRF previo al login y recibió 419; no duplicó
datos. La UI Axios utiliza la cookie XSRF actual y su flujo fue correcto.

Revertir desde UI **200**: tres registros conservados, dos archivados y sólo
`Camiseta de demostración QA` anterior activa. No se crearon existencias, ventas
ni pagos en la demo. Captura `E:\KEYLASHOP\.playwright-mcp\bshop-csv-import-tablet.png`.

Guía en `docs/IMPORTACION_CSV.md`, API/README/tablero actualizados. Catálogo/CSV
implementados y probados; continuar con fotografías/R2. Permanecen aceptación
en tablet física, medición con piloto grande y módulos posteriores de inventario,
ventas y finanzas. No se declara terminado el sistema completo.

## 2026-11-13 — Fotografías, galería y contabilidad de almacenamiento

Implementados upload síncrono seguro, staging privado, EXIF, derivados WebP
320/640/1600 sin ampliar, principal/orden/texto/variante, galería táctil, permisos,
auditoría, UUID/hash idempotente, límite de 5 incluyendo pendientes y cuota única
de 2 GiB. Retiro por soft delete, 24 h de retención y liberación sólo tras ausencia
verificada de objetos. Compensación y reconciliación acotadas; locks de proceso
para impedir solapamiento con un upload vivo. Estado y operación en `IMAGENES.md`.

Se instaló Flysystem S3/AWS SDK y configuración R2, sin credenciales ni llamadas
remotas. GD/WebP y EXIF del PHP dedicado verificados. HTTP fue reiniciado porque
el proceso anterior aún no cargaba EXIF; el PHP compartido de XAMPP no se modificó.

### Destinos y respaldos

- Pruebas PHP: testing, SQLite `:memory:`, guardia antes de migrar.
- Demo: local, SQLite, host local, `database/bshop-business.sqlite`.
  Respaldo íntegro `.runtime/bshop-backup-20261113-042648.sqlite`, 62 tablas
  incluyendo interna SQLite; migración 001300 aplicada sin refrescar datos.
- MySQL: testing, `127.0.0.1:33078`, `bshop_testing_single`, versión 8.4.13,
  bind/datadir verificados. Respaldo completo de esquema/datos de 61 tablas:
  `.runtime/media-mysql-backup-20261113-042648-c599e1.json`, tamaño/hash verificados.
  Migración aplicada y valores preexistentes comparados antes/después: conservados.
- Sin operaciones sobre VPS ni almacenamiento remoto.

### Evidencia final

| Comando / comprobación | Resultado real |
|---|---|
| `PHP83 artisan bshop:media-status` | local, GD WebP/EXIF ready; R2 no configurado; sin DB/red |
| `PHP83 artisan test --compact` | **74 passed / 544 assertions**, 12.50 s final |
| `PHP83 vendor/bin/pint --test` | passed |
| `npm run build` | Typecheck/build correctos; JS232.22 kB/gzip79.53; CSS75.63+1.55 kB |
| `npm run test:tablet` | **104 passed**, 26 escenarios ×4 perfiles; 20 casos nuevos de galería |
| `PHP83 scripts/verify-media-mysql.php` | Backup/migración/preservación, upload/replay, principal, cuota/retiro aprobados; QA SQL revertida |
| `PHP83 scripts/verify-media-concurrency.php` | Dos procesos disputan quinto cupo: **201/422**, exactamente 5 reservas/5120 bytes; reservas liberadas, fixture archivado |
| `PHP83 scripts/document-single-schema.php` | ER/diccionario/manifiesto regenerados desde MySQL: 61 tablas /100 FK |
| HTTP real con sesión/CSRF demo | JPEG80×40:201, WebP200, retiro200; 192 bytes retenidos; sin ampliar |

Las 15 pruebas PHP nuevas cubren derivados/local delivery, original privado,
replay/hash, cupos/pending/quota, formatos/píxeles/animación, EXIF, originales
pequeños, fallo parcial de proveedor, compensación pendiente/recovery, lock activo,
principal/orden/conflicto, retiro/purga idempotente y permisos. Los tests browser
usan API interceptada; el smoke HTTP final sí utiliza la aplicación local real.

### Hallazgos corregidos y límites

- La primera ejecución browser dio 100/104: guardar reemplazaba el botón de apertura
  y se perdía el foco al cerrar. Refresco de detalle en segundo plano conserva el
  nodo; ejecución final 104/104, incluidos giro/teclado/footer/foco.
- Se sustituyeron conteos con snapshot por lecturas bloqueantes actuales: tras
  esperar un lock bajo REPEATABLE READ no se puede usar un conteo antiguo de cupos.
- El primer arnés MySQL concurrente se bloqueó por pipes de Windows y venció a
  60 s. Se recuperaron sus 4 reservas sintéticas, archivando el fixture y conservando
  historial. Arnés corregido con archivos de sincronización/salida; final 201/422.
- El primer JPEG HTTP dio422 por EXIF ausente en el proceso viejo, antes de crear
  reserva. Se reinició sólo la vista previa local y el flujo final fue correcto.
- `Camiseta de demostración QA` conserva su estado activo y ninguna foto temporal
  visible. La foto smoke retirada retiene 192 bytes hasta ser elegible para limpieza.
- R2 real, tablet física y tarea persistente de reconciliación siguen pendientes.
  El procesamiento actual es síncrono y los locks asumen un único servidor PHP.

Continuación: completar activación operativa de fotos cuando se autoricen R2/tarea;
seguir con servicios y UI de inventario/kardex. No se declaran terminados los módulos
de ventas/finanzas por existir su esquema. Sin commits ni publicación remota.

## 2026-11-13 — Inventario, referencia visual, ventas/apartados y demo USD

### Instrucciones incorporadas y auditoría

Se leyó el paquete `E:/KEYLASHOP/Nueva carpeta` completo (dos Markdown y ambas
imágenes) y se registró F0 en `TODO_UI_VENTAS_APARTADOS.md`. No se localizaron prompts
separados v3.0/v3.1; se usaron arquitectura vigente, código/esquema y referencia V3.
El propietario confirmó **dólares estadounidenses**: USD, presentación US$.

Antes de configurar la moneda se verificó copia íntegra de la demo y ausencia de
documentos financieros en otra moneda. Configuración auditada, sin convertir valores
históricos ni cambiar credenciales. Nuevas instalaciones/demo proponen USD.

### Implementación

- Tema marino/violeta central, iconos lineales, topbar/sidebar adaptables y marca
  tipográfica temporal (no había logo oficial independiente). Acciones reales;
  sin plan SaaS, corazones/escáner/notificaciones ficticios ni marco/barra del iPad.
- Inventario: saldos por almacén, entradas/salidas/conteos, transferencias balanceadas,
  versión y motivo, kardex inmutable. `StockLedger` coordina todos los escritores.
- Clientes: alta rápida idempotente, búsqueda y ficha con saldos/compras/canastas
  autorizados; vistas Clientes y Facturas integradas a la navegación.
- Venta: catálogo/filtros/grid-lista/fotos, selector de variante, cliente y resumen.
  Apartado: tres zonas en horizontal; cliente/pedido por pestañas en espacios menores.
- Cotización sólo lectura. Confirmación revalida precio/stock y reserva o entrega en
  transacción. Cliente obligatorio conforme al esquema. No se impone canasta única.
- Reapertura, retiros/cancelación compatibles sin borrar historial, entrega parcial,
  abonos sin sobrepago, asignación y crédito vinculados a pedido/factura.
- Factura única con snapshots, secuencia por sucursal y PDF privado Dompdf 3.1.6 /
  wrapper 3.1.2. Render posterior al commit; error de PDF no repite emisión/pago.
- UUID/hash por acción, locks ordenados y versión optimista. Registro de recuperación
  por usuario/sucursal en sessionStorage, GET de estado propio y reintento explícito
  con el mismo cuerpo. Sin mutaciones automáticas offline.
- Compartir nativo o copiar resumen manualmente. WhatsApp oficial no configurado;
  no se afirma envío automático ni se enviaron mensajes externos.

Archivos principales: `app/Domain/Inventory/*`, `app/Domain/Sales/*`,
`app/Domain/Business/IdempotentAction.php`, controllers Inventory/Customer/Sales/
InvoiceDocument, modelos Customer/InventoryMovement/SalesOrder/SalesOrderItem,
migraciones 001400/001500, `resources/js/components/Sales*.vue`, CustomersView/
InvoicesView/InventoryView, `useSalesDraft.ts`, `pendingRequests.ts`, `sales.ts`,
`resources/css/keylashop-theme.css`, `sales.css`, rutas y permisos de instalación.
Se mantuvo compatibilidad de `Customer::factory()` detectada por regresión.

### Destinos y copias verificados

- PHP: `testing`, SQLite `:memory:`, guardia de TestCase antes de migrar.
- Demo: `local`, SQLite, host local, `database/bshop-business.sqlite`.
  Copias íntegras previas a moneda, migraciones y ejemplos; entre ellas
  `.runtime/bshop-backup-20261113-080325.sqlite`, `...081007.sqlite`,
  `...081904.sqlite`, `...090418.sqlite`. Copia final verificada:
  **`.runtime/bshop-backup-20261113-150458.sqlite`**, 63 tablas con interna SQLite.
- MySQL: `testing`, `127.0.0.1:33078`, `bshop_testing_single`, 8.4.13, bind y datadir
  exclusivos del proyecto comprobados. Respaldos completos esquema/datos con hash:
  `.runtime/inventory-mysql-backup-20261113-081007-c3e345.json` y
  `.runtime/checked-mysql-backup-20261113-090418-eccb6f.json`. Valores anteriores
  comparados y conservados; permisos sólo aditivos. QA transaccional revertida;
  fixtures concurrentes archivados/desactivados con movimientos compensatorios.
- Ningún refresh/truncate/reset de datos existentes, operación de VPS o cambio R2 remoto.

### Pruebas y resultados reales

| Comando / recorrido | Resultado |
|---|---|
| `PHP83 artisan test --compact` | **99 passed /784 assertions**, 9.42 s final PHP |
| `PHP83 vendor/bin/pint --test` | passed |
| `npm run build` | Typecheck/Vite correctos; JS304.46 kB/gzip100.19, CSS75.73+20.99 kB |
| `npm run test:tablet` | **184 passed**, última regresión integral 2.0 min |
| `npm run test:tablet -- sales.spec.ts` | **54 passed**, 42.9 s tras último ajuste móvil/geometry |
| `PHP83 scripts/verify-inventory-mysql.php` | Migración/preservación, DECIMAL(19,4) completo, replay, transferencias y kardex correctos |
| `PHP83 scripts/verify-inventory-concurrency.php` | Dos usuarios/procesos consumen última unidad: **201/409**, saldo0, entrada+salida exactas |
| `PHP83 scripts/verify-sales-mysql.php` | USD146, abono50, pendiente96, reserva/replay, factura única, pago final y entrega sin doble descuento |
| `PHP83 scripts/verify-sales-concurrency.php` | Dos usuarios/procesos reservan última unidad: **201/409**, físico1/reservado1 y una sola canasta/reserva |
| `PHP83 scripts/document-single-schema.php` | ER/diccionario/manifiesto actualizados: **62 tablas /103 FK** |
| `PHP83 composer.phar audit --no-interaction` | Sin avisos de vulnerabilidad |
| `PHP83 scripts/verify-demo.php` | PASS: USD, kardex/saldos/reservas, importes/pagos/crédito y hashes PDF; sólo lectura |

Perfiles: WebKit iPad 768×1024 y 1024×768, Chromium táctil 800×1280 y 1280×800,
Chromium escritorio1440×1000 y WebKit iPhone390×844. API interceptada en regresión
browser para proteger la demo; los recorridos siguientes sí usan HTTP/sesión/CSRF reales.
No equivale a aceptación en la tablet física.

### Demo recorrida como cliente, no sembrada directamente

Con formularios reales: cuatro categorías, atributos/valores reutilizables, ocho
productos con fotos, 19 variantes, 19 entradas que justifican **103 unidades**,
cuatro clientes ficticios y seis pedidos. Fotos derivadas de la referencia aportada,
notas/descripciones de demostración y teléfonos/correos no reales. Se archivó el
producto QA previo conservando su historial. No se generaron compras antiguas falsas.

Venta María: 25+45+22+envío5 = **US$97**, pagada, FAC-1-000001/PDF descargado.
Apartado María: 25+45+22+49+5 = **US$146**, abono50, **pendiente96**.
Venta Carlos80; selección Ana84 → retiró perfume50 → histórico55 → cancelada;
canasta nueva Ana104; canasta Luis53, abonos20 y10, pendiente23.
Detalles y SKUs/cantidades en `DEMO_KEYLASHOP.md`.

Conciliación final: **US$480 activos = US$257 recibidos + US$223 pendientes**,
2 facturas y 5 pagos. Físico98, reservado8, disponible90, 19 saldos coherentes con
kardex y reservas. No se incluyen importes de la canasta cancelada como deuda.

### Errores detectados y corregidos

- Primeros tests de inventario: gates ausentes; añadidos y permiso validado en servicio.
- Primeros tests PDF: método findOrFail usado en Query Builder; sustituido por
  búsqueda soportada. Symfony download marcaba public pese al header: `setPrivate()`
  explícito y prueba de Cache-Control privado/no-store.
- Regresión completa detectó pérdida de HasFactory en Customer; restituida sin
  modificar los fixtures anteriores. Los 99 tests finales pasan.
- Formularios con IDs repetidos al conservar vistas podían asociarse al contexto
  equivocado: IDs únicos y caso browser creando cliente desde Clientes con Venta
  cacheada. Borradores/sucursal/título también se conservan y revalidan por contexto.
- El recorrido HTTP de retiro encontró DTO inicial sin versión. Se corrigió el
  default de SalesOrder a 0 y se añadió aserción de respuesta inicial. Retiro/cancelación
  siguientes dieron200 y liberaron reservas manteniendo líneas/auditoría.
- Abono1000 al pedido2 dio **422**, conservó campo1000 y no modificó pendiente96.
- Abono10 al pedido6: se confirmó en servidor y se abortó sólo la respuesta.
  Recargar hizo GET del UUID propio y recuperó abonado30/pendiente23 **sin segundo
  POST ni otro pago**. Journal limpiado. Se probó también replay y pérdida de respuesta
  con API interceptada en seis perfiles.
- Capturas reales detectaron falta de altura definida/contracción de acciones y
  superposición móvil por flex/sticky. Corregidas con cuerpo desplazable en tablet
  y flujo normal líneas → totales → acciones en móvil. Prueba de geometría añadida;
  última revalidación54/54. Transiciones deshabilitadas sólo al capturar para no
  fotografiar el menú a mitad de una rotación.
- Fallback manual de compartir probado con Web Share no disponible: portapapeles
  contenía cliente ficticio, total146, abono50 y pendiente96. Cero mensajes enviados.

### Capturas y continuidad

Capturas finales bajo `E:/KEYLASHOP/.playwright-mcp`:
`keylashop-venta-{1440,1024,768}-final.png`,
`keylashop-apartado-{1440,1024,768}-final.png`,
`keylashop-apartado-mobile-{productos,resumen}-final.png`.
Sin scroll horizontal en las vistas revisadas. Se preservó total97/pendiente96 al
girar; controles principales48px y campos16px. En móvil se comprobó última línea
antes de totales y acciones después, sin superposición. Los borradores fotográficos
no crearon pedidos extra.

Documentación nueva: `VENTAS_APARTADOS.md`, `INVENTARIO.md`, `DEMO_KEYLASHOP.md`,
`REFERENCIA_VISUAL.md`, checklist específico; README/API/tablero/QA actualizados.
Rama develop, sin commits ni remoto; el árbol sigue sin seguimiento inicial.

Pendientes: tablet física; R2/SMTP/WhatsApp oficial y tareas/colas persistentes;
cuentas semanales agregadas, enlaces temporales, edición/archivo de clientes,
devoluciones/reembolsos/reversión de pagos/crédito entre operaciones, comisiones,
reportes y PWA. La cancelación con dinero/entregas incompatibles permanece bloqueada
en lugar de borrar datos. No se declara terminado el sistema entero.

## 2026-11-13 — Tablero único Apartados y WhatsApp opcional

### Corrección funcional del propietario

Canasta y apartado son el mismo concepto. Menú **Apartados**, ruta `/app/apartados`,
una sola vista con grid superior y detalle inferior reactivo. El propietario indicó
expresamente que WhatsApp quede **configurable y opcional**; no se solicitaron ni
introdujeron credenciales reales y no se enviaron mensajes a contactos ficticios.
La referencia anterior de tres columnas queda como historial de la iteración previa.

### Entrega

- `BasketBoard` y los cinco componentes pedidos: BasketCard/Grid/DetailPanel/
  ItemsList/Summary. Búsqueda, filtros, selección resaltada, depósito/importe/cantidad,
  edición, alta de apartado vacío, contacto, alias, cierre/PDF, entrega/despacho.
- Drafts por tarjeta, versión/409, descarte explícito para actualizar, recuperación
  de peticiones, estados refrescables sin reload. Las acciones de contacto/número/
  depósito se deshabilitan mientras haya cambios de artículos sin guardar para
  evitar perder el borrador o cobrar sobre un total no confirmado.
- `BasketService` comparte locks y StockLedger: reservas diferenciales, precios
  históricos, no sobreventa, no reducción de total por debajo del depósito. Las
  retiradas conservan filas/auditoría. El alias nunca cambia el ID/FK del pedido.
- Metadatos aditivos: order_kind, basket_number, closed_at, shipped_at, versión de
  cliente e invoice_deliveries. Cierre, factura, pago, envío WhatsApp y despacho son
  dimensiones diferentes, no colores ficticios del frontend.
- Meta Cloud API opcional con formulario de Configuración, secretos cifrados,
  diagnóstico sin valores privados, upload multipart del PDF verificado y plantilla
  aprobada. HMAC raw-body para webhook, correlación remitente/destinatario/mensaje,
  estados monotónicos, sin retry ciego de resultados desconocidos ni factura repetida.
- El botón de cierre genera la factura y, si la integración está habilitada/completa,
  envía desde servidor sin salir de la aplicación. Un fallo conserva el documento;
  la UI ofrece reintentar el envío confirmado fallido contra la misma factura.

Archivos: `resources/js/components/Basket*.vue`, `WhatsAppSettings.vue`, `baskets.ts`,
`baskets.css`, puente de navegación App/SalesWorkspace; `BasketService`,
`WhatsAppConfiguration`, `WhatsAppInvoice`, controllers Basket/WhatsAppSettings/
WhatsAppInvoice/WhatsAppWebhook, ampliación Customer/SalesService, config/whatsapp,
migración001600 y rutas. `.env.example` sólo contiene variables vacías/valores públicos.
Se dio formato a los componentes nuevos sin agregar dependencia de runtime.

### Migración y destinos

- SQLite local `C:/xampp/php/www/BShop/database/bshop-business.sqlite`, host local.
  Respaldo íntegro previo `.runtime/bshop-backup-20261113-153421.sqlite`, 63 tablas;
  migración001600 aplicada conservando dinero/stock/documentos. Respaldo final
  verificado **`.runtime/bshop-backup-20261113-203209.sqlite`**, 64 tablas.
- MySQL8.4.13 testing `127.0.0.1:33078`, `bshop_testing_single`, bind/datadir exclusivos
  comprobados. Copia esquema/datos con hash
  `.runtime/checked-mysql-backup-20261113-193108-e692b2.json`, 62 tablas antes de migrar;
  comparación de valores preexistentes aprobada. Esquema final **63 tablas/106 FK**.
- Pruebas Laravel sólo `testing/sqlite/:memory:` con guardia previa. Pruebas de
  proveedor con HTTP fake + preventStrayRequests, nunca el número real de la clienta.
- Sin VPS, refresh/truncate/reset, despliegue, cambios de secretos reales o recursos remotos.

### Comandos y evidencia

| Comando / recorrido | Resultado real |
|---|---|
| `PHP83 vendor/bin/pint --test` | passed |
| `PHP83 artisan test --compact` | **108 passed/926 assertions**, 9.74 s final PHP |
| `npm run build` | Typecheck/build correctos; JS340.79 kB/gzip110.24; CSS75.73+26.51 kB |
| `npm run test:tablet` | **232 passed**, 2.3 min, seis perfiles |
| `npm run test:tablet -- baskets.spec.ts` | **48 passed**, 30.6 s tras guardas finales de borrador |
| `PHP83 scripts/mysql-migration-check.php` | Respaldo/migración/preservación aprobados |
| `PHP83 scripts/verify-baskets-mysql.php` | Agregación/filtros, alias, precio25 conservado ante catálogo99, depósito20, reserva2→3→1→3 y cierre único |
| `PHP83 scripts/verify-basket-edit-concurrency.php` | Dos usuarios editan dos apartados por última unidad → **200/409**, exactamente una reserva; QA compensada/cancelada/archivada, sin provider calls |
| `PHP83 scripts/document-single-schema.php` | 63 tablas/106 relaciones; ER/diccionario/manifiesto actualizados |
| `PHP83 scripts/verify-demo.php` | PASS, sólo lectura: kardex/reservas/importes/crédito/hashes PDF coherentes |

Cobertura WhatsApp: PDF subido por ID (no URL pública), aceptación distinta de sent,
UUID repetido/otro UUID sin mensaje extra activo, timeout desconocido no reenviado,
desactivado sin conexiones, claves cifradas/redactadas, firma inválida403 y callbacks
sent→delivered sin regresión a sent. Guardar configuración no envía mensajes.
Meta real no fue configurado ni se atribuye una entrega real a estos tests.

### Recorrido real como usuaria

Sobre la demo existente, con sesión/CSRF/formularios reales:

1. Elegir María desde grid cargó abajo el detalle. Cambiar a Luis y volver a María
   produjo **0 navegaciones de frame**, mismo URL y datos del cliente correcto.
2. Cantidad camiseta1→2: total171/depósito50/pendiente121. Guardar y volver2→1:
   total146/depósito50/pendiente96. Reserva diferencial, sin alterar físico.
3. Cambiar número visible2→**12**, conservando ID2 y referencias; tarjeta/detalle
   actualizados. El primer patrón HTML usaba guion no escapado para modo Unicode v
   de Chromium: corregido y validado en navegador.
4. Agregar aretes15 y guardar: cinco líneas, total161, depósito50 intacto; retirar
   aretes y guardar: cuatro líneas, total146/pendiente96. Línea/reserva retirada
   preservadas históricamente, sin pedido/factura/pago adicional.
5. Editar contacto formateó teléfono ficticio +1 (202) 555-0101 y actualizó detalle;
   no se reescribió el PDF existente del pedido1.

Balance final sin cambios económicos: ocho productos, cuatro clientes, seis pedidos,
dos facturas, cinco pagos. **480 = 257 recibidos +223 pendientes USD**, físico98,
reservado8, disponible90; todos conciliados después de las ediciones.

Capturas reales `E:/KEYLASHOP/.playwright-mcp/keylashop-tablero-apartados-{1440,1024,768,390}.png`.
Grid.bottom antes de detail.top en todas; cero overflow horizontal, selección12
conservada al girar. Aceptación en tablet física pendiente, no sustituida por emulación.

Documentación/checklist: `TABLERO_APARTADOS.md`, `TODO_TABLERO_APARTADOS.md`, README,
API, QA/tablet, protocolo local, tablero y guía demo actualizados. Rama develop sin
commits/publicación remota. Pendiente opcional: cuenta/número/plantilla Meta reales,
webhook HTTPS público y verificación de entrega con esa cuenta al activarla.

## Plantilla para la siguiente iteración

```text
Fecha UTC:
Fase y task IDs:
Cambios realizados:
Commit/rama:
Pruebas/comandos exactos:
Conexión efectiva si aplica (entorno, host, puerto, base, driver; sin secretos):
Resultado real y evidencia:
Riesgos y bloqueos:
Checklist actualizado:
Siguiente fase/tarea:
```

## 2026-11-13 — CxC, descarga de documentos y regresión completa

Se integró CxC al menú y a `/app/cxc`, con listado de saldos por documento,
resúmenes por moneda, filtros, exportación CSV y acceso al depósito existente.
Sin nuevos saldos paralelos ni cambios de esquema. Se conservan permisos,
sucursal, cálculos BCMath, versiones, idempotencia y auditoría del cobro.

Se corrigió la entrega de PDF e imágenes locales mediante el almacenamiento de
Laravel, evitando el fallo `File must be readable` de BinaryFileResponse en este
entorno. Las comprobaciones incluyen bytes históricos exactos y rechazo de un
PDF alterado. Las tres pantallas que descargan facturas usan una descarga autenticada
con Blob; los errores permanecen en la app y reintentar no factura de nuevo.

Se corrigió la sincronización de la prueba de recuperación: espera que la venta
simulada se confirme y termine la respuesta perdida antes de recargar. En Chromium
la prueba anterior podía interrumpir la solicitud antes de confirmarse.

Validación final: `npm run build` aprobado; 112 pruebas PHP / 1,001 aserciones y
262 pruebas de navegador aprobadas (seis perfiles). Las pruebas PHP se ejecutaron
con el autoload precargado y las variables de phpunit.xml aplicadas, sin configuración
adicional de PHPUnit, sobre `tests/Feature` y `tests/Unit`; SQLite `:memory:`.
Browser: `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet`, build real y API interceptada.

Servidor real: Chrome muestra login en `http://127.0.0.1:8027/app/apartados` y carga
una miniatura WebP pública existente. El propietario inicia el servidor fuera del
entorno aislado con `scripts/dev.ps1 -Port 8027 -Open`. No se reinició la demo ni se
cambiaron credenciales. Recorrido autenticado pendiente de login del propietario;
aceptación en tablet física y servicios externos siguen pendientes.

Rama develop, sin commits ni despliegue. Checklist QA y guía CXC actualizados.

## 2026-11-14 - F5.07: revisión de inventario y limpieza de textos

Según ARQUITECTURA_VIGENTE, INVENTARIO y QA maestro, se implementó el diagnóstico
F5.07: Inventario → Revisar inventario, API por sucursal/almacén y comando
`bshop:inventory-reconcile`. Compara saldos físicos/reservados, reserva pendiente,
versiones y cadena del kardex. Consulta sin escrituras, sin reparar diferencias,
sin generar saldos vacíos y conservando productos archivados. Paginación, filtro,
reintento, resumen global y protección frente a respuestas obsoletas.

La lectura usa transacción y bloqueos compartidos Store/Warehouse siguiendo el
orden del escritor. CLI devuelve 0 sin diferencias, 1 con diferencias y 2 para
ámbito inválido. Programación horaria declarada, sin solapamiento y con registro;
no está instalado/verificado el programador del sistema operativo.

Validación: build TypeScript/Vite aprobado; suite PHP completa 120 pruebas /
1,071 aserciones, SQLite :memory: con el autoload y variables de phpunit.xml
precargados (tests/Feature y tests/Unit, --no-configuration --do-not-cache-result).
Después de ajustar únicamente formato PHP, InventoryTest: 18 pruebas / 142
aserciones. Pint --test aprobado para servicio, comando, controlador y pruebas.
La ejecución del formateador encontró la limitación local de detección de escritura;
el formato se corrigió mediante las herramientas de edición del proyecto.

Navegador: `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet`, 274 casos aprobados
sobre el build real y API interceptada, seis perfiles; 12 nuevos de revisión.
Se repite la suite tras la limpieza de textos y accesos del inicio; resultado final
registrado a continuación al terminar.

Recorrido real autenticado con Playwright, servidor del propietario en
http://127.0.0.1:8027, SQLite local database/bshop-business.sqlite. Cuenta demo
recuperada por autorización explícita del propietario; sin secretos en documentos.
Almacén principal: 22 variantes, 40 movimientos, 0 diferencias. Segundo almacén:
22 variantes, 0 movimientos, 0 diferencias. Stock, pedidos y pagos de la demo
conservados. Captura de QA: .playwright-mcp/inventory-review-live.png.

A solicitud del propietario se retiró el aviso de demostración, el texto obsoleto
sobre imágenes futuras y las explicaciones técnicas innecesarias de servidor e
identificadores. Inicio muestra Vista general y accesos operativos a Apartados y
Catálogo según permisos; conserva datos reales de sucursales y almacenes.
Se mantuvieron instrucciones necesarias de importación CSV y configuración WhatsApp.
Playwright recorrió diez módulos sin detectar los textos obsoletos buscados ni
desbordamiento horizontal. Revisión de inventario quedó abierta para inspección.

Documentación: RECONCILIACION_INVENTARIO, INVENTARIO, API, README y QA maestro
actualizados. Sin commits ni publicación. Pendientes explícitos: expiración de
reservas, instalación del programador, prueba extrema DECIMAL(19,4) y concurrencia
de la nueva lectura en MySQL aislado; aceptación en tablet física. SQLite NUMERIC
no acredita el rango decimal extremo por su conversión a REAL.

Cierre de validación tras limpiar textos y actualizar el inicio: build aprobado,
274/274 pruebas de navegador aprobadas en seis perfiles (2.4 minutos).
`git diff --check` sin incidencias. `artisan schedule:list` confirma la declaración
horaria; no acredita ejecución persistente del programador del sistema operativo.

## 2026-11-14 - F5.04: plazo y vencimiento conservador de reservas

Se agregó Configuración → Plazo de reservas con permiso business.manage, valor
nullable desactivado, límite 1..8760 horas, validación, auditoría, control de valor
anterior, carga/reintento y borrador conservado en giro de tablet. Rutas GET/PATCH
/api/v1/business/reservations/settings. No se habilitó la política en la demo.

Ventas y primer guardado de apartado vacío fijan expires_at para reservas nuevas.
Editar no prorroga fechas. Reservas históricas sin fecha no se modifican al cambiar
la política ni al editar sus líneas. Apartados/Ventas muestran fecha en zona del
negocio; detalle identifica el vencimiento aplicado sin textos de maqueta.

ReservationExpiry y bshop:reservations-expire verifican pedido abierto/reserved,
fecha explícita alcanzada en todas sus reservas activas, ausencia de pagos,
factura, entregas y envío. Los movimientos expire se registran por StockLedger,
con clave única, actor Sistema y auditoría explícita sin atribuirla a un usuario
conectado. Liberación exacta del reservado, físico intacto, cancelación histórica,
versiones y exclusión de CxC. No se resuelven abonos/crédito implícitamente.

Una transacción por pedido, locks en orden compartido con escritores de stock,
revalidación de variantes tras espera y lecturas actuales con bloqueo de pagos/
facturas para REPEATABLE READ. Un fallo revierte todo el pedido. CLI es de solo
lectura salvo --apply; lotes 1..1000, códigos 0/1/2 y resultados JSON; excluye pedidos
protegidos y fechas mixtas al seleccionar para no ocupar el lote repetidamente.
Schedule declara cada cinco minutos con --apply, sin solapamiento. No se instaló
ni verificó el programador persistente del sistema operativo.

Validación:
- npm run build: TypeScript/Vite aprobado.
- PHP completo: 128 pruebas / 1,221 aserciones, SQLite :memory:. Autoload precargado,
  variables de phpunit.xml aplicadas y PHPUnit --no-configuration
  --do-not-cache-result tests/Feature tests/Unit.
- Después del ajuste final de lecturas actuales/selección: ReservationExpiryTest,
  8 pruebas / 150 aserciones; incluye rollback, exactitud fraccionaria, reservas
  históricas, fecha exacta, pagos/facturas/parcial protegidos, CxC, reintento y lotes.
- BSHOP_BROWSER_OFFLINE=1 npm run test:tablet: 286/286 en seis perfiles (2.5 minutos),
  build real y API interceptada. Doce nuevos de configuración/detalle. Los primeros
  fallos eran expectativas de fixture: cancelados requieren filtro Todos y el
  mensaje 409 utiliza el traductor común; corregidos y repetidos satisfactoriamente.
- Pint --test aprobado en los cuatro archivos nuevos PHP.
- artisan schedule:list muestra ambas tareas declaradas, sin acreditar ejecución.
- Demo real autenticada, http://127.0.0.1:8027, database/bshop-business.sqlite:
  configuración visible, plazo desactivado, sin overflow. CLI sin --apply:
  read_only=true, checked=0. Sin aplicar vencimientos ni modificar reservas/pagos.

Documentos EXPIRACION_RESERVAS, INVENTARIO, VENTAS_APARTADOS, API, README, QA maestro
actualizados. Sin migraciones nuevas, commits ni publicación. Pendientes: pruebas
concurrentes de esta fase en MySQL aislado, programador persistente, retención de
logs, tablet física y flujo comercial de cancelaciones con abonos/reembolsos.

## 2026-11-13 (America/Santo_Domingo) - seguimiento del programador y QA MySQL preparado

Se continuó el cierre operativo de F5.04/F5.07. La conexión al MySQL aislado fue
rechazada por permisos de socket del entorno. No se intentó cambiar firewall,
políticas ni iniciar otro servidor tras ese rechazo. No se declara concurrencia
MySQL ni precisión extrema verificadas en estas fases.

Cambios:
- bshop:scheduler consulta de solo lectura por defecto; --run ejecuta las tareas
  vencidas mediante schedule:run. Bloqueo de archivo sin caducidad artificial,
  estado privado escrito por temporal/renombrado, latido UTC y resultado por tarea.
- Se observan eventos reales de tarea finalizada/fallida: schedule:run puede
  devolver 0 aunque falle un hijo. El fallo permanece hasta que esa tarea se
  recupera; un ciclo sin trabajos no lo borra. Estado never_run/running/ok/failed/
  stale, rechazo de estado corrupto sin iniciar tareas. No expone comandos,
  mensajes de excepción ni credenciales en el JSON de estado.
- scripts/scheduler-task.ps1 prepara XML revisable por defecto, instala solo con
  modo explícito y rechaza reemplazar tareas existentes. Ruta absoluta, usuario
  actual, privilegios normales, IgnoreNew y php-win.exe sin consola visible.
  Es una tarea interactiva: requiere sesión abierta y no acredita servicio 24/7.
- scripts/verify-reservations-mysql.php prepara barreras multiproceso para pago,
  entrega, edición, vencimiento ganador, dos vencimientos, lector/escritor y rango
  DECIMAL(19,4). Guardia de instancia/base/datadir antes de escribir. Fixtures
  sintéticos identificados, sin migraciones, truncados ni cambios globales de plazo;
  preserva las carreras y revierte la prueba extrema. Ejecución completa pendiente.

Validación realizada:
- PHP completo: 132 pruebas / 1,245 aserciones, SQLite :memory:, sin datos reales.
  Autoload precargado y env de phpunit.xml; PHPUnit --no-configuration
  --do-not-cache-result tests/Feature tests/Unit. Cuatro nuevas pruebas del ejecutor.
- Pint --test aprobado: SchedulerMonitor, RunScheduler, SchedulerTest,
  verify-reservations-mysql.php y AppServiceProvider.
- php -l scripts/verify-reservations-mysql.php: sin errores.
- Parser PowerShell del instalador: sin errores de sintaxis.
- Intento de ejecutar powershell.exe -NoProfile -File scripts/scheduler-task.ps1
  -Action Plan: rechazado por AuthorizationManager. No se ejecutó el plan, no se
  generó XML por ese script ni se instaló ninguna tarea. No se cambió la política.
- Verificador MySQL: salida 2, BLOCKED antes de escrituras de QA por permisos de
  socket al destino 127.0.0.1:33078, bshop_testing_single.
- Consulta real artisan bshop:scheduler: never_run, salida 1, sin iniciar tareas.
- La interfaz no cambia en esta fase; se conserva la regresión anterior de 286
  casos, sin atribuir una nueva ejecución de navegador.

Documentos PROGRAMADOR_WINDOWS, VERIFICACION_RESERVAS_MYSQL, EXPIRACION_RESERVAS,
RECONCILIACION_INVENTARIO, DESARROLLO, README y QA maestro actualizados. Sin
instalación del sistema operativo, commits ni publicación. Continúan pendientes la
prueba real MySQL, plan/instalación/aceptación de Windows, cuenta de servicio si se
requiere operar sin sesión y retención de logs. La demo y sus reservas no se cambian.

## 2026-11-13 (America/Santo_Domingo) - gestión interna de clientes

Se completó la ficha de Clientes con edición de contacto/notas y archivo/reactivación
explícitos, sin usar borrado físico ni deleted_at. Filtros Activos/Archivados/Todos,
consulta de ficha actual, confirmación con alcance de todas las sucursales,
customers.manage revalidado, bloqueo/versionado e idempotencia con auditoría.
La recuperación propia admite action=status y UUID en mayúsculas/minúsculas.

El archivo no cancela pedidos, no libera inventario ni modifica deuda o documentos.
Nuevas ventas/apartados rechazan clientes archivados en el servidor. Las operaciones
existentes conservan cobros, documentos y saldos: prueba con factura, contacto
histórico y nuevo abono confirma deuda 6.0000 USD, sin movimiento de inventario extra.
Reactivar devuelve disponibilidad para nuevas operaciones, sin reescribir documentos.

Interfaz AppDialog, acciones de 48 px, borrador durante giro y journal por pestaña/
usuario/sucursal antes de enviar. Ante resultado desconocido se conserva el payload
para reintentar; recargar consulta primero, sin enviar automáticamente. Conflicto
conserva borrador y ofrece descarte/actualización explícitos. Los roles lectores
no ven controles de mutación. Respuesta atrasada de actualización de ficha no debe
sustituir una selección posterior. El listado corrige páginas vacías tras archivar.

Eventos locales mantienen actualizado un cliente de una venta nueva conservada en
memoria: archivo bloquea confirmación sin perder carrito, reactivación la habilita.
No reemplazan el control del servidor ni alteran snapshots de pedidos existentes.
Se corrigió también el texto Canastas abiertas a Apartados abiertos en Clientes.

Validación:
- PHP completo: 137 pruebas / 1,323 aserciones, SQLite :memory:. Autoload precargado,
  variables de phpunit.xml aplicadas, PHPUnit --no-configuration
  --do-not-cache-result tests/Feature tests/Unit. Cinco pruebas nuevas / 78 aserciones.
- npm run build: TypeScript/Vite aprobado.
- BSHOP_BROWSER_OFFLINE=1 npm run test:tablet: 316/316 casos en seis perfiles,
  build real/API interceptada, 2.8 minutos. Treinta nuevos de ficha, conflicto,
  permisos, pérdida/recarga y venta en memoria. Una comprobación inicial de
  reactivación se adelantaba a la respuesta; ahora espera cierre y confirmación.
- Pint --test aprobado: CustomerController y CustomerManagementTest.
- Playwright real autenticado en 127.0.0.1:8027, viewport 1024x768: cuatro clientes,
  ficha, cinco campos de edición y confirmación de archivo con conservación de
  deudas/documentos; cero overflow. Se cerraron los diálogos sin guardar/archivar.
- Tras los últimos ajustes menores de selección y mensaje de recuperación, build
  repetido y pruebas específicas en curso; resultado final se registra al terminar.

Documentos CLIENTES, API, README y QA maestro actualizados. Sin migraciones,
commits, publicación ni cambios de clientes de la demo. Enlaces públicos temporales
siguen pendientes como fase distinta. Concurrencia real MySQL y tablet física no
se consideran acreditadas por SQLite ni por navegadores emulados. Se mantienen
separados los bloqueos previos del programador Windows y red de MySQL.

Cierre de esta fase: build final aprobado y 30/30 pruebas específicas repetidas
sobre ese build, incluidas recuperación y venta en memoria (23.3 segundos).
Regresión integral previa: 316/316. git diff --check sin incidencias. Pantalla real
recargada con el build final y abierta en Clientes, viewport tablet 1024x768, cuatro
clientes activos y controles de edición/archivo habilitados. Sin cambios guardados.

## 2026-11-13 (America/Santo_Domingo) - rediseño visual de ficha del cliente

Se rediseñó Clientes a petición del propietario: directorio lateral compacto con
contador real del filtro, perfil principal con iniciales/nombre/estado, edición
principal destacada, actualización por icono y archivo separado al pie. Contacto
con iconos y tarjetas, notas agrupadas y resumen comercial/apartados integrados en
la ficha. Se eliminó la repetición de ficha y notas bajo el directorio.

El resumen conserva la consulta existente por sucursal y el permiso sales.view.
SalesCustomers emite resultados identificados por cliente y CustomersView ignora
respuestas ajenas a la selección actual. Sin indicadores inventados ni solicitudes
nuevas de escritura. Abrir apartado utiliza el flujo existente del pedido.
AppIcon amplió los iconos compartidos; estilos del directorio limitados a gestión
de clientes para conservar el selector de Ventas.

Tarjetas y contactos responden al ancho del panel con container queries; textos
largos pueden partir, los importes no separan moneda/cantidad, y montos largos
obtienen ancho extra. Se ajustaron contraste de textos secundarios, espaciado de
notas y tamaño táctil de las acciones (48 px). Sin cambios de permisos, endpoints,
datos, cálculos, esquema ni backend de negocio.

Validación:
- npm run build: vue-tsc/Vite aprobado.
- BSHOP_BROWSER_OFFLINE=1 npm run test:tablet: 316/316 casos en seis perfiles,
  2.9 minutos, build real con API interceptada. Después del pulido final de CSS,
  build y 30/30 casos específicos de clientes/venta en memoria aprobados (23.3 s).
- Playwright autenticado en la demo real: 1440, 1024, 768 y 390 px sin overflow.
  Saldo Ana 104.00 USD, cero compras y un apartado, iguales al resumen existente.
  Desde la ficha se abrió el apartado #5 y su detalle; se volvió a Clientes sin
  guardar cambios. Acción de edición 48 px, notas sin el espaciado heredado extra.
- Capturas de QA: .playwright-mcp/customer-before.png,
  customer-after-desktop.png, customer-after-tablet.png y
  customer-modern-tablet-final.png (mismo directorio).
- Backend no modificado ni repetido en esta iteración visual; se conserva la
  evidencia anterior de 137 pruebas / 1,323 aserciones.

CLIENTES.md actualizado. Vista final abierta y al frente en tablet 1024x768,
ficha de un cliente seleccionado. No se modificaron clientes de la demo, pedidos,
pagos ni inventario. Sin commits ni publicación.

## 2026-11-14 — Revalidación actual y recuperación del directorio de clientes

Se contrastó el estado actual con los últimos registros: incluye CxC, revisión
de inventario, vencimientos y rediseño de Clientes posteriores al selector.
Se corrigió el formato detectado por Pint en SalesService, ReceivablesController,
routes/api.php y SalesTest; luego se verificó la suite PHP completa.

Continuación funcional en SalesCustomers, compartido por Clientes/Ventas/Apartados:
- Error de consulta separado de los errores de alta/resumen, con reintento explícito.
- Conserva la página solicitada al reintentar y mantiene la ficha seleccionada.
- Desde que cambia la búsqueda se invalidan respuestas anteriores y se bloquean
  resultados/paginación hasta resolver la consulta; incluye la espera de 200 ms.
- Un fallo no muestra un falso estado vacío. Reintentar no emite mutaciones.

Resultados ejecutados en C:/xampp/php/www/BShop:

| Comando | Resultado |
|---|---|
| `npm run build` | vue-tsc/Vite aprobados; JS373,99 kB/gzip119,47 |
| `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet` antes de la mejora | 316/316; seis perfiles; 2,8 min |
| `PHP83 vendor/bin/pint --test` tras corregir formato | Aprobado |
| `PHP83 artisan test --compact` | 137 pruebas / 1.323 aserciones; 11,60 s |
| `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- customers.spec.ts sales.spec.ts` tras la mejora | 102/102; seis perfiles; 1 min |
| `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- baskets.spec.ts` tras la mejora | 78/78; seis perfiles; 37,9 s |

PHP83: .runtime/php83/php.exe. Destino comprobado antes de las pruebas:
APP_ENV=testing, conexión sqlite, host local, base :memory:, variables forzadas
en phpunit.xml y guardia TestCase antes de migrar. Navegadores usan build real
y API interceptada; no acreditan proveedor remoto ni hardware físico.

Se cerró la documentación pendiente del selector: hasta 4/16/36 tarjetas,
preferencia por usuario/navegador, adaptación 6/4/2 columnas en 1024/768/390 px,
selección y borradores preservados. Evidencia previa específica: 109/951 PHP y
244 browser; los números de la tabla corresponden a la versión actual ampliada.
La sesión de la demo en 8017 requiere iniciar sesión; no se tomaron nuevas
capturas autenticadas en esta revalidación. No hubo migraciones sobre demo,
operaciones MySQL/VPS ni cambios comerciales. Pendiente: aceptación física y
las fases del QA maestro; pruebas de concurrencia MySQL siguen sin revalidar aquí.

## 2026-11-14 — Enlaces temporales de consulta de un apartado guardado

Continuación del desarrollo: botón Compartir enlace en el detalle de Apartados,
diálogo táctil con duración1/24/72/168 horas, copiar URL, historial de20 y revocar.
Máximo5 activos. Se bloquea compartir con borradores pendientes de guardar y se
conserva UUID/duración al reintentar una generación de respuesta perdida.

Servicio OrderViewLinks, API privada por sucursal y página pública Blade independiente
`/s/order/{token}`. Tokens opacos HMAC separados por dominio, con SHA256 almacenado;
el journal idempotente/auditoría no guardan secretos ni URL. Consulta vigente sin
consumir el enlace, con importes BCMath/snapshots y campos públicos explícitos.
Contacto/notas/costos/otros pedidos/PDF no se publican. Revocación repetible, expiry,
rate limits y cabeceras no-store/no-referrer/noindex/CSP. La cancelación o inactivación
de sucursal invalida la consulta. GET no altera filas financieras ni inventario.

No sustituye el F7 de catálogo/carrito público y confirmación/edición del cliente:
esas acciones siguen pendientes. Nueva tabla order_view_links independiente de los
share_links ya previstos para nuevas órdenes. Esquema aditivo inspeccionado, sin
modificar tablas comerciales; rollback bloquea eliminación de historial.

Comandos ejecutados desde C:/xampp/php/www/BShop:
- `npm run build`: vue-tsc/Vite aprobados; build final JS378,49 kB/gzip120,75.
- `PHP83 vendor/bin/pint --test`: aprobado.
- `PHP83 artisan test --compact`: **144 pruebas / 1.470 aserciones**, 13,32 s.
  Destino mostrado antes de ejecutar: testing/sqlite/host local/:memory:, con
  phpunit.xml forzado y guardia TestCase antes de migrar.
- `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet`: **340/340**, seis perfiles,
  3 min; build real y API interceptada. Dos nuevos escenarios por seis perfiles.
  Corrigido un selector inicial de prueba que usaba el nombre antiguo del stepper.
- Pulido final: se priorizan enlaces activos sobre el historial caducado para que
  ninguno quede fuera de la lista revocable (test de25 históricos). Revocar otro
  enlace conserva la URL que acaba de generarse. Build repetido y
  `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- baskets.spec.ts --grep "order view link|lost link generation"`:
  **12/12**, 8,8 s. Backend completo repetido con los resultados arriba indicados.
- Render CLI de shared-order.blade.php con datos sintéticos (sin SQL), HTML
  transferido a Playwright: 1440/1024/768/390/320 sin overflow, cantidad10 y total255.
  Captura E:/KEYLASHOP/.playwright-mcp/shared-order-qa-390.png. Es QA de plantilla,
  no una sesión autenticada ni un enlace comercial generado en la demo.
- Destino de migración verificado: local/sqlite/host local,
  C:/xampp/php/www/BShop/database/bshop-business.sqlite. `PHP83 scripts/backup-local.php`:
  copia SQLite íntegra `.runtime/bshop-backup-20261114-040415.sqlite` (64 tablas).
- `PHP83 artisan migrate --path=database/migrations/2026_11_14_002000_create_order_view_links.php --force`:
  aplicada, sólo nueva tabla/índices/FK. Sin fresh/refresh ni migraciones ajenas.
- `PHP83 scripts/verify-demo.php`: PASS de USD/kardex/reservas/dinero/PDF; físico98,
  reservado8, disponible90; activos480 = recibido257 + pendiente223; 2 facturas/5 pagos.

Actualizados README, API, guía ENLACES_CONSULTA, tablero y QA. Sin nuevos enlaces
comerciales enviados, commits, llamadas a proveedor ni despliegue. MySQL/concurrencia
de enlaces, HTTPS/logs redactados en entrega y aceptación física siguen pendientes.

## 2026-11-14 — Catálogo y confirmación de pedidos por enlace; avance global

Continuación solicitada por el propietario, incluyendo porcentaje de avance.
Se implementó el flujo de compra por enlace para un cliente existente:
Clientes → ficha → Enlace para nuevo pedido → almacén/variantes/envío/duración.
La autorización pública limita hasta50 variantes y conserva contexto de sucursal,
almacén y cliente. Catálogo público paginado, carrito editable, cotización vigente
de servidor y confirmación única de un apartado. Resumen y copia manual del enlace.

OrderRequestLinks reutiliza SalesService/StockLedger y la política de plazos de
reservas. Fija modo reservation, cliente/almacén, descuento0/envío acordado/pago0;
no acepta contexto/importes del navegador como autoridad. Valida whitelist, precios
y stock; bloqueo de enlace, UUID y hash, FK y unique de orders.share_link_id. Consumo
used al commit junto con stock/pedido; expiry revalidado después de esperar locks.
La revocación de un enlace usado no cancela el pedido ni libera su reserva.

La entrada /s/{token} carga PublicOrderRequest sin montar la sesión/navegación interna.
Carrito y confirmación pendiente por pestaña bajo hash del token. La recarga tras
respuesta perdida consulta GET y no confirma automáticamente. Cambio de precio409
conserva cantidades y exige revisión nueva, incluyendo precio unitario actualizado.
AppDialog conserva acciones al girar/teclado. CSRF web de mismo origen continúa
activo en escrituras públicas; límites públicos60/min y confirmación10/min/IP,
cabeceras no-store/no-referrer/noindex/CSP. Sin pagos online ni proveedor externo.

Verificación ejecutada desde C:/xampp/php/www/BShop:
- `PHP83 artisan test --compact --filter=OrderRequestLinksTest`: 6 pruebas /98
  aserciones. Catálogo/preview sin consumo, manipulación de contexto/precio/pago,
  confirmación/replay, cambio de precios/sobrestock con rollback, autorización
  de variantes, expiry/revocación, permisos/sucursal y emisor suspendido.
- `PHP83 artisan test --compact`: **150 pruebas /1.569 aserciones**,32,94 s.
  Destino mostrado antes: testing/sqlite/host local/:memory:; guardia TestCase y
  variables forzadas de phpunit.xml antes de migraciones.
- `npm run build`: vue-tsc/Vite aprobados. Build final: entrada387,14 kB/gzip123,68;
  componente público lazy10,55 kB/gzip3,95 y CSS1,81 kB.
- `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- public-orders.spec.ts customers.spec.ts`:
  **60/60**,48,6 s; seis perfiles, build real y API interceptada.
- Primera invocación integral terminó con error del ejecutor ChildProcess.kill;
  se verificó que no había procesos de pruebas activos antes de repetir.
  `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- --reporter=dot`:
  **364/364**,4,5 min, seis perfiles.
- Tras pulir precio unitario de revisión: build y
  `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- public-orders.spec.ts --reporter=dot`:
  **18/18**,21,8 s. Pint global detectó y corrigió espaciado de un argumento en
  OrderRequestLinksTest; `PHP83 vendor/bin/pint --test` final aprobado.

Habilitación local:
- Destino mostrado/verificado: local/sqlite/host local,
  C:/xampp/php/www/BShop/database/bshop-business.sqlite.
- `PHP83 scripts/backup-local.php`: respaldo íntegro verificado
  `.runtime/bshop-backup-20261114-045143.sqlite`,65 tablas antes del cambio.
- `PHP83 artisan migrate --path=database/migrations/2026_11_14_002100_create_share_link_catalogs.php --force`:
  aplicada; únicamente tabla nueva/FK/índices, sin alterar datos comerciales.
- `PHP83 scripts/verify-demo.php`: PASS; físico98/reservado8/disponible90,
  activos480 = recibido257 + pendiente223 USD,2 facturas/5 pagos, PDF íntegros.
  Se conservaron todos los pedidos y borradores existentes. No se crearon pedidos
  comerciales de demostración por scripts ni se enviaron enlaces a destinatarios.

Avance: checklist inicial40/56 (71,4%); con este hito41/57 (71,9%). Valoración global
ponderada por módulos: **64,45%, expresada como aproximadamente65%**, detallada en
ESTADO_DEL_SISTEMA.md. Estimación técnica de planificación: incluye cuentas semanales,
devoluciones, nómina/comisiones, reportes y puesta en servicio aún pendientes.

Actualizados README/API/Clientes/QA y guías PEDIDOS_POR_ENLACE/ESTADO_DEL_SISTEMA.
Pendientes del bloque público: edición de pedidos ya guardados, concurrencia MySQL
real de este módulo, HTTPS/logs redactados y aceptación física. SQLite y emulación
no acreditan esas validaciones. Sin commits, VPS, configuración de proveedor ni
publicación remota. Siguiente bloque funcional: cuentas semanales/cierres agregados.

## 2026-11-14 — Cuentas semanales y cierre con factura agregada

Nuevo módulo privado `/app/cuentas-semanales`: creación por cliente/inicio de semana,
zona/moneda capturadas, siete días naturales, candidatos paginados, agregar/quitar
apartados, revisión de total/abonado/pendiente y cierre explícito con una factura.
Se excluyen pedidos cancelados o facturados individualmente, sin repetir líneas
ni pagos. Una cuenta vacía puede anularse; repetir creación de ese período exacto
reactiva la misma fila vacía anulada y conserva la auditoría/constraint de ciclo.

Se reutiliza invoice_orders para fuentes individuales y agregadas. Actualizados
Apartados, CxC, resumen de clientes, historial/PDF de facturas, protección de
reservas e integración WhatsApp opcional. La asignación de cobros recalcula estado
de la factura completa, no sólo del último pedido pagado. No hay nuevos movimientos
de stock al cerrar; quedan habilitados pagos/entregas y bloqueadas ediciones o
doble facturación. Snapshot de cliente y abonado al cierre conserva el PDF histórico.

UUID/versión por mutación, hash de revisión de pedidos/pagos, orden de locks y
lecturas actuales para REPEATABLE READ. Mutex de creación por cliente/sucursal
evita solapamientos sin tomar FK locks de ubicación antes de escanear períodos.
Cambios de artículos durante la espera producen409; copia de artículos y consultas
de pagos usan lecturas bloqueadas actuales. Límites DECIMAL validados antes de
emitir la secuencia. Cierre manual; corte global y programación automática pendientes.

UI AppDialog con acciones fuera del scroll; giro conserva cliente/fecha/revisión.
Journal por pestaña/usuario/sucursal; recarga consulta resultado de operación y
no reemite cierres. Se bloquea sucursal con operación pendiente y se permite
actualizar la revisión tras conflictos. Descarga prepara el mismo PDF por ID.

Comandos y evidencia, raíz C:/xampp/php/www/BShop:
- `PHP83 artisan test --compact --filter=WeeklyAccountsTest`: primer núcleo7/127.
  Se ampliaron a11 escenarios: abonos pre/post cierre, unicidad, PDF inmutable,
  exclusión individual, CxC, revisión obsoleta, fechas/moneda/versiones, alcance y
  recuperación, anulación/reapertura, medianoche/DST y límites monetarios/fechas.
- `PHP83 artisan test --compact` final: **161 pruebas /1.746 aserciones**,19,02 s.
  Antes de ejecutar se mostró testing/sqlite/host local/:memory:, protegido por
  phpunit.xml forzado y TestCase antes de migrar.
- `PHP83 vendor/bin/pint --test` final: aprobado.
- `npm run build`: vue-tsc/Vite aprobados, entrada401,34 kB/gzip127,25; CSS42,96+76,05.
- `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- --reporter=dot`: **388/388**,5 min,
  seis perfiles, build real/API interceptada. Una comprobación inicial de conflicto
  se adelantaba al segundo HTTP en Chromium; ahora espera la factura confirmada.
  La suite integral pasó tras corregir esa sincronización.
- Destino de entrega mostrado/verificado: local/sqlite/host local,
  C:/xampp/php/www/BShop/database/bshop-business.sqlite.
- `PHP83 scripts/backup-local.php`: copia íntegra
  `.runtime/bshop-backup-20261114-053136.sqlite`,66 tablas antes del cambio.
- `PHP83 artisan migrate --path=database/migrations/2026_11_14_002200_add_weekly_account_workflow.php --force`:
  aplicada: tabla weekly_cycle_locks y cinco columnas aditivas en weekly_accounts.
  No se alteraron filas comerciales ni se quitaron constraints de historia.
- `PHP83 scripts/verify-demo.php`: PASS; físico98/reservado8/disponible90,
  total activo480 = recibido257 + pendiente223 USD;2 facturas/5 pagos/PDF íntegros.
  Se conservaron pedidos y borradores existentes; no se sembraron cierres de demo.

README/API/QA, CUENTAS_SEMANALES y ESTADO_DEL_SISTEMA actualizados. Estimación de
planificación ponderada:69,70%, expresada como aproximadamente70%; checklist42/58.
No representa aceptación productiva. Pendientes: MySQL/concurrencia real semanal,
corte global/automatización y tablet física. Pruebas de SQLite y mocks no sustituyen
esas verificaciones. Sin VPS, proveedor real, commits ni publicación remota.
Siguiente bloque comercial: devoluciones/reembolsos y reversión de pagos.

## 2026-11-14 — Reversión contable e historial de pagos

Nuevo flujo Pagos y reversiones en Apartados y venta directa guardada. Permite
consultar cobros y corregir uno completo por error, con motivo5..500, versión y
UUID. payments.reverse se asigna a propietario/administrador; vendedor conserva
consulta/cobro sin reversión. El permiso/sucursal se revalida en transacción.

PaymentReversals conserva recibo, mapa a pedido, importe/método/fecha/notas y
asignaciones originales. Cambia estado a reversed y agrega un registro único de
reversión con autor/fecha/motivo e importe asignado/no asignado. El crédito sólo
se compensa por su remanente disponible; si estaba totalmente asignado no se
introduce un movimiento artificial de importe0 ni saldo negativo. Se verifican
contextos y ecuación asignado+disponible=pago con BCMath/strings y locks actuales.

Los saldos efectivos excluyen pagos revertidos. La facturación posterior no los
reasigna, y el estado de factura individual/semanal se recalcula sobre todos sus
pagos confirmados. Conserva el PDF histórico y snapshot semanal; no modifica stock,
total fiscal ni entrega. Reembolsos pendientes/confirmados, devoluciones, comisiones,
facturas ajustadas o crédito inconsistente bloquean la reversión para conciliación
específica. No se ejecutan devoluciones bancarias ni de tarjeta en este flujo.

UI AppDialog: motivo conservado al girar y en409, actualización explícita de versión,
journal actor/sucursal/pedido, consulta de recuperación al volver a abrir el pedido,
sin POST automático. El resultado actualiza tarjeta/saldo y conserva borradores de
artículos si se recupera una reversión anterior. Scripts/verify-demo concilia también
pagos revertidos, su metadata, asignaciones históricas y crédito neto0.

Verificación ejecutada en C:/xampp/php/www/BShop:
- `PHP83 artisan test --compact --filter=PaymentReversalsTest`: núcleo inicial6/94;
  ampliado con crédito parcialmente asignado y CSRF. Se mantuvo SQLite :memory:;
  el caso CSRF sólo sustituye el bypass de pruebas del middleware, no el entorno/BD.
- `PHP83 artisan test --compact` final: **169 pruebas /1.860 aserciones**,30,14 s;
  destino mostrado antes: testing/sqlite/host local/:memory:, guardia TestCase activa.
- `PHP83 vendor/bin/pint --test`: aprobado.
- `npm run build`: vue-tsc/Vite aprobados, JS408,78 kB/gzip129,25; CSS43,47+76,05.
- `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- baskets.spec.ts sales.spec.ts --reporter=dot`:
  **186/186**,2,4 minutos. Cinco escenarios nuevos por seis perfiles.
- `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- --reporter=dot`:
  **418/418**,6,4 minutos, seis perfiles, build real/API interceptada.

Habilitación local:
- Destino declarado/verificado: local/sqlite/host local,
  C:/xampp/php/www/BShop/database/bshop-business.sqlite.
- `PHP83 scripts/backup-local.php`: copia íntegra
  `.runtime/bshop-backup-20261114-062820.sqlite`,67 tablas antes de migrar.
- `PHP83 artisan migrate --path=database/migrations/2026_11_14_002300_create_payment_reversals.php --force`:
  tabla nueva, FK/checks y adición conservadora de payments.reverse a owner/manager.
- `PHP83 scripts/verify-demo.php`: PASS de USD/kardex/reservas/dinero/crédito/PDF;
  físico98/reservado8/disponible90; activos480=recibido257+pendiente223 USD;
  2 facturas/5 pagos. Ningún pago de la demo se revirtió y no se enviaron fondos.

README/API/tablero/QA y REVERSION_PAGOS actualizados. Checklist43/59; estimación
ponderada70,75%, redondeada aproximadamente70% con los pesos ya documentados.
Pendientes: devoluciones/reembolsos/crédito entre operaciones, conciliaciones con
comisiones, concurrencia MySQL específica y tablet física. Sin VPS, proveedor real,
commits ni publicación. Siguiente bloque: devolución de mercancía y reintegro de stock.

## 2026-11-14 — Recepción física de devoluciones y reintegro de stock

Continuación solicitada: Devoluciones de mercancía en pedido facturado de Ventas
y Apartados. Recibe cantidades entregadas pendientes de devolución, por artículo,
con motivo y reposición opcional en el almacén original. Conserva historial de
recepciones/actor/destino. Cantidades/destino y motivo persisten al girar o ante409.
Journal actor/sucursal/pedido recupera por GET al volver al pedido, sin POST automático.

Nueva SalesReturnReceipts: venta directa requiere la salida sale canónica del
artículo en kardex; apartado exige fulfill por reserva y coincidencia de contador
entregado. fulfilled del encabezado no basta para inventar mercancía. Histórico
recibido acumulado nunca supera entregado/facturado; servicios no generan stock.
StockLedger registra customer_return positivo con return_item_id, saldo/versión y
evento único; reserva delta0. No reposición consume cupo sin movimiento físico.
Lotes inválidos o desbordes DECIMAL revierten todo, sin cabeceras ni ingresos parciales.

Se reutilizan sales_returns/sales_return_items, con contexto nuevo fuertemente
referenciado a factura/pedido/almacén/actor. returns.receive sólo para owner/manager
por defecto, consulta para seller; permiso revalidado, CSRF, StoreContext, UUID,
versión y review_hash, locks ordenados y lecturas actuales bajo REPEATABLE READ.

Alcance económico explícito: status received y economic_status=pending_adjustment;
refund_amount0 aún no es un importe autorizado para reembolsar. Se conservan total
fiscal, pagos/asignaciones, reservas, entrega original y PDF. Recepciones pendientes
bloquean nuevos cobros y la vía de factura/entrega combinada; en factura semanal
abarca todos sus pedidos, sin afectar otras facturas del cliente. Ajuste económico,
crédito y reembolso reales siguen pendientes. UI y API de Ventas/Apartados, CxC/CSV,
cliente/cuenta semanal y consultas públicas identifican importes originales por
conciliar; no presentan una devolución monetaria inexistente como completada.

Verificación en C:/xampp/php/www/BShop:
- `PHP83 artisan test --compact --filter=SalesReturnReceiptsTest`: núcleo inicial6
  pruebas/118 aserciones. Ampliado a10 escenarios: stock/replay/historia fiscal,
  no reposición/cupo, lote ajeno, entrega parcial, retención semanal, permisos,
  capacidad DECIMAL/rollback completo, servicios, estado sin entrega trazada y CSRF.
- `PHP83 artisan test --compact` final: **179 pruebas /2.031 aserciones**,46,12 s;
  destino mostrado testing/sqlite/host local/:memory:, guardia TestCase y variables
  de phpunit.xml antes de migrar. El caso CSRF desactiva sólo su bypass de pruebas.
- `PHP83 vendor/bin/pint --test`: aprobado.
- `npm run build`: vue-tsc/Vite aprobados; entrada418,32 kB/gzip131,41, componente
  público10,81 kB/gzip4,07; CSS43,96+76,05.
- `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- baskets.spec.ts sales.spec.ts --reporter=dot`:
  **216/216**,3,4 minutos.
- `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- --reporter=dot`:
  **448/448**,5,4 minutos, seis perfiles; build real y API interceptada.

Habilitación local:
- Destino declarado/verificado: local/sqlite/host local,
  C:/xampp/php/www/BShop/database/bshop-business.sqlite.
- `PHP83 scripts/backup-local.php`: copia íntegra
  `.runtime/bshop-backup-20261114-171037.sqlite`,68 tablas antes de migrar.
- `PHP83 artisan migrate --path=database/migrations/2026_11_14_002400_create_sales_return_receipts.php --force`:
  aplicada; tabla de contexto/FK y adición conservadora de returns.receive a
  owner/manager. No cambia filas comerciales de inventario/dinero.
- `PHP83 scripts/verify-demo.php`: PASS; físico98/reservado8/disponible90,
  activos480=recibido257+pendiente223 USD,2 facturas/5 pagos/PDF íntegros. El script
  también verifica la relación de recepciones con kardex, delta y almacén. No se
  registraron devoluciones reales de demo ni se enviaron fondos/proveedor externo.

README/API/tablero/QA y RECEPCION_DEVOLUCIONES/ESTADO_DEL_SISTEMA actualizados.
Checklist44/60; ponderado71,80%, manteniendo redondeo aproximado70% y reconociendo
que F9 aún es parcial. Pendientes: ajuste económico, reembolso/crédito, correcciones
compensatorias, concurrencia MySQL de este flujo y tablet física. Sin VPS, commits
ni publicación. Siguiente bloque: conciliación económica de la mercancía recibida.

## 2026-11-14 — Conciliación económica, crédito y reembolsos manuales de devoluciones

Nuevo flujo desde recepción → Ver conciliación y reembolso. Propuesta sobre el
pedido/factura originales: valor neto de mercancía incluyendo sus descuentos e
impuestos, distribución proporcional acumulada y crédito por cantidades recibidas.
Envío0 por defecto, elegible explícitamente hasta su remanente original. No usa precio
actual del catálogo. Aprobación única con UUID/versión/review_hash, motivo y autor.

Primero reduce deuda; libera sólo la parte de asignaciones que excede el nuevo neto.
Se conserva la asignación original y se agrega payment_allocation_releases. El
crédito disponible es ledger original+liberaciones; no se inventa otro recibo para
restaurar fondos. Registro de reembolso independiente, autorizado por refunds.record,
limitado al remanente de recepción/pago y crédito disponible real. Método/referencia
obligatorios según canal; referencia deduplicada por sucursal/método, incluida0.
Es registro de una salida ya realizada; no llama a banco/procesador de tarjetas.

Cuatro tablas nuevas con FK/checks: return_adjustments, return_adjustment_items,
payment_allocation_releases y refund_confirmations. Permisos returns.adjust y
refunds.record añadidos conservadoramente a owner/manager. El provisional económico0
del artículo recibido se sella al crédito aprobado; cantidad/destino físicos no
cambian. La recepción pasa received→adjusted; el cobro se libera sólo si no quedan
otras recepciones pendientes de esa factura. Sin transferencia implícita entre pedidos.

OrderSettlement centraliza total original/crédito/neto, abono aplicado, disponibilidad,
recibido original y reembolsos. Integrado en Ventas/Apartados, filtros/tarjetas, CxC/CSV,
cliente, cuenta semanal, historial de facturas y consultas públicas. Se conserva
grand_total fiscal, recibos, asignaciones y PDF originales; la salida usa el ledger
existente unused_refund como débito de disponibilidad, vinculado a refunds(kind=return).
Lecturas financieras con snapshots transaccionales; escritores con locks actuales,
límites y rollback atómico. No se vuelve a mover mercancía al aprobar o reembolsar.

UI: diálogo económico secuencial a la recepción, acciones fuera del scroll, motivo
y envío conservados al girar/conflicto. Historial/fuentes y journal por actor/sucursal/
pedido/recepción; al reabrir se recupera por GET sin repetir movimientos.

Verificación, raíz C:/xampp/php/www/BShop:
- `PHP83 artisan test --compact --filter=ReturnSettlementsTest`: núcleo inicial5/100;
  ampliado a8 escenarios: crédito/reembolso/replay/referencia/capacidad, deuda primero,
  redondeo0,33/0,34/0,33, envío/precio histórico, factura semanal, CSRF, prorrata entre
  artículos y permisos/conflictos. Snapshot original/PDF/stock preservados.
- `PHP83 artisan test --compact` final: **187 pruebas /2.180 aserciones**,21,04 s.
  Destino mostrado testing/sqlite/host local/:memory:, validado por TestCase antes
  de migraciones y phpunit.xml forzado. Caso CSRF sólo desactiva su bypass de pruebas.
- `PHP83 vendor/bin/pint --test`: aprobado.
- `npm run build`: vue-tsc/Vite aprobados, entrada432,86 kB/gzip134,85; componente
  público11,24 kB/gzip4,19 y CSS44,38+76,05.
- `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- baskets.spec.ts --reporter=dot`:
  **162/162**,2,1 minutos, incluido el nuevo flujo financiero.
- `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- --reporter=dot`:
  **472/472**,4 minutos, seis perfiles, build real/API interceptada;24 casos nuevos.
  Después se reforzó lectura consistente y sello del campo económico provisional;
  se repitió PHP completo (resultado final arriba); sin cambios visuales posteriores.

Habilitación local:
- Destino declarado/verificado local/sqlite/host local,
  C:/xampp/php/www/BShop/database/bshop-business.sqlite.
- `PHP83 scripts/backup-local.php`: copia íntegra
  `.runtime/bshop-backup-20261114-181950.sqlite`,69 tablas antes de migrar.
- `PHP83 artisan migrate --path=database/migrations/2026_11_14_002500_create_return_settlements.php --force`:
  aplicada: cuatro tablas/FK/checks y permisos administrativos, sin reescribir
  facturas ni ejecutar ajustes/reembolsos comerciales.
- `PHP83 scripts/verify-demo.php`: PASS antes y después del refuerzo final de lectura.
  Incluye probe del DTO fuera de transacción y confirma su cierre sin mutaciones.
  Físico98/reservado8/disponible90; neto activo480=aplicado257+pendiente223 USD;
  2 facturas/5 pagos, créditos/reembolsos0 y PDF íntegros. No salieron fondos ni se
  registraron ajustes o reembolsos en la demo por el agente.

README/API/guías/QA actualizados con CONCILIACION_DEVOLUCIONES. Checklist45/61;
ponderado73,90%, aproximado75% de planificación, no aceptación productiva. Pendientes:
crédito entre operaciones, compensaciones/ajuste descargable, conciliación avanzada
con comisiones, concurrencia MySQL específica y tablet física. Sin VPS, commits,
proveedor financiero real ni publicación. Siguiente bloque: aplicación de saldo a favor.

## 2026-11-14 — Aplicación de saldo a favor entre pedidos facturados

Nuevo botón Saldo a favor del cliente en Ventas/Apartados. Consulta deuda neta y
fuentes liberadas por devoluciones conciliadas; con credits.apply se selecciona
pago origen, importe y motivo. Mismo cliente/sucursal/moneda, destino facturado,
sin recepción pendiente; depósitos aún ligados a pedidos sin factura no se ofrecen.
No hay cambio de moneda, cobro nuevo, modificación de pago original ni movimiento
de inventario. Asignación y débito de disponibilidad atómicos con UUID/versión/hash.

credit_applications y credit_application_sources registran origen de efectivo,
destino, liberaciones consumidas, importe, autor y razón. CreditPools distribuye
consumos/reembolsos por liberación. El saldo consumido deja de poder reembolsarse
en su devolución de origen; si el destino devuelve mercancía, su asignación se
libera con trazabilidad al mismo pago y se puede reembolsar o reutilizar desde allí.

Se corrigió la atribución entre pedidos: una asignación de crédito no se vuelve a
sumar en el origen cuando ambos pertenecen a la misma factura semanal. OrderSettlement
separa efectivo original, crédito recibido/aplicado, disponibilidad por pedido y
reembolso atribuido a su recepción. Cliente/CxC/tarjetas/cuenta semanal conservan
saldos sin duplicar dinero. El verificador comprueba efectivo+crédito entrante−saliente−
reembolsos=aplicado+disponible por pedido y la conservación global del pago.

Locks ordenados de productos/variantes/sucursal/pedidos/facturas/pagos/ledger,
revisión del conjunto de orígenes tras la espera, revalidación de permisos y límites.
Fuentes con reembolso pendiente se excluyen; estados/documentos/comisiones que
requieren conciliación bloquean escritura. Rol owner/manager recibe credits.apply;
seller consulta sin aplicación por defecto. CSRF/sesión/StoreContext/rate limit.

UI AppDialog con selección/importe/motivo conservados al girar o ante409, punto/coma
decimal, acciones fuera del scroll y journal por actor/sucursal/pedido. Al volver
al pedido tras recargar recupera por GET y no repite el débito. La venta directa
también integra historial y el saldo aplicado sin registrarlo como efectivo nuevo.

Verificación desde C:/xampp/php/www/BShop:
- `PHP83 artisan test --compact --filter=CreditApplicationsTest`: núcleo inicial6/164,
  ampliado a8 escenarios incluyendo retención por recepción pendiente y dos sucursales
  con acceso a ambas. Otros casos: unicidad, límites/saldos, reembolso antes/después,
  cadena y atribución en factura semanal, otro cliente/moneda/sin factura, permisos,
  revisión obsoleta y CSRF419. Sin modificar recibos/PDF/stock ni inventar cobros.
- `PHP83 artisan test --compact`: **195 pruebas /2.379 aserciones**,49,19 s.
  Destino mostrado testing/sqlite/host local/:memory:; TestCase antes de migrar y
  phpunit.xml forzado. Caso CSRF sólo sustituye el bypass de pruebas del middleware.
- `PHP83 vendor/bin/pint --test`: aprobado.
- `npm run build` final: vue-tsc/Vite aprobados; entrada441,23 kB/gzip136,41 y
  CSS44,63+76,05; componente público11,24 kB/gzip4,19.
- `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- baskets.spec.ts sales.spec.ts --reporter=dot`:
  **270/270**,2,9 minutos.
- `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- --reporter=dot`:
  **502/502**,4,5 minutos, seis perfiles, build real/API interceptada;30 casos nuevos.

Habilitación local:
- Destino declarado/verificado local/sqlite/host local,
  C:/xampp/php/www/BShop/database/bshop-business.sqlite.
- `PHP83 scripts/backup-local.php`: copia íntegra
  `.runtime/bshop-backup-20261114-220056.sqlite`,73 tablas antes de migrar.
- `PHP83 artisan migrate --path=database/migrations/2026_11_14_002600_create_credit_applications.php --force`:
  aplicada; dos tablas/FK/checks y adición conservadora de credits.apply a owner/manager.
- `PHP83 scripts/verify-demo.php`: PASS, incluido cierre del snapshot de lectura,
  conservación de crédito/efectivo y kardex/PDF. Físico98/reservado8/disponible90,
  neto activo480=aplicado257+pendiente223 USD,2 facturas/5 pagos, créditos/reembolsos0.
  No se aplicaron créditos comerciales, salidas de fondos ni cambios de stock en demo.

README/API/guías/QA actualizados con APLICACION_CREDITO. Checklist46/62 y ponderado
74,95%, aproximado75% de planificación. Pendientes: comprobantes descargables de
ajuste/crédito, anulaciones compensatorias, concurrencia MySQL específica, integración
avanzada con comisiones y aceptación física. Sin VPS, commits, proveedor bancario
real ni publicación. Siguiente bloque: comprobantes descargables de ajuste y aplicación.

## 2026-11-14 — Comprobantes PDF históricos de ajuste y crédito

FinancialDocument captura snapshot/hash durante el commit; prepare privado posterior
publica temporal verificado y sellado con recuperación tras interrupción. Ready se
verifica por clave fija/hash/tamaño sin regenerar archivos faltantes o alterados.
Botones integrados en conciliación e historial de aplicaciones de Ventas/Apartados.
Permisos sales.view/invoices.view, StoreContext, CSRF, no-store, bloqueo por documento.
Documentación: COMPROBANTES_FINANCIEROS.md; legacy sin snapshot usa factura histórica
y número de sucursal, sin atribuirle un nombre retrospectivo.

Verificaciones desde C:/xampp/php/www/BShop:
- `PHP83 artisan test --compact`:205/2.558,30,80s; testing/sqlite/:memory: forzado
  y conexión efectiva protegida por TestCase antes de migrar; sin host remoto.
- `PHP83 vendor/bin/pint`: aprobado; `npm run build`: vue-tsc/Vite aprobados,
  entrada442,89kB/gzip136,79;183 módulos.
- `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- baskets.spec.ts --grep "private PDF|PDF preparation|history hides PDF" --reporter=dot`:
  36/36,28,6s; controles táctiles/descarga/reintentos/permisos sin repetir dinero.
- `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- --reporter=dot`:538/538,5 minutos.
- Destino local/sqlite/host ninguno: database/bshop-business.sqlite. Respaldo íntegro
  `.runtime/bshop-backup-20261115-013116.sqlite`,75 tablas antes de migrar.
- `PHP83 artisan migrate --path=database/migrations/2026_11_14_002700_create_financial_documents.php --force`:
  aplicada tras inspección; sólo nueva tabla/FK/checks, sin refrescar datos.
- `PHP83 scripts/verify-demo.php`: PASS; físico98/reservado8/disponible90;
  neto480=aplicado257+pendiente223 USD;2 facturas/5 pagos; comprobantes financieros0.
  Verificador ampliado para snapshots/hash/contexto y PDFs ready; no genera archivos.

Checklist47/63; estimación ponderada74,95% ≈75% conservada. Pendientes MySQL específico,
compensaciones, comisiones y tablet física. El propietario pidió además revisar y
completar dashboard/historial para control comercial; siguiente trabajo activo.

## 2026-11-14 — Dashboard comercial, historial profesional y gráficos modernos

Se verificó que Vista general sólo tenía métricas de configuración y que no había
historial comercial unificado. Se implementaron SalesControl/Controller y consulta
privada por sucursal, filtros de fechas/cliente/referencia/tipo/estado, resumen por
moneda, evolución diaria, cobros por período, CSV completo y actividad de un pedido.
Reutiliza OrderSettlement; factura semanal única, crédito sin nuevo efectivo,
ajustes/reembolsos/reversiones y ausencia de escrituras comprobados. Períodos locales
inclusivos de hasta366 fechas, incluidos días DST de25 horas.

Por corrección del propietario se rediseñó la pantalla completa: un h1 por ruta,
Resumen de ventas en inicio e Historial de ventas en consulta; sin encabezado genérico
de fecha ni bloque de bienvenida/configuración bajo el dashboard. El historial sólo
presenta filtros/resumen compacto/operaciones, sin volver a mostrar los gráficos.
Tabla con columnas/estados/importes jerarquizados, selector Tabla/Tarjetas conservado
al girar, nombres y referencias completos, sin elipsis ni truncar dinero. Desplazamiento
horizontal contenido para tabla extensa; acciones mínimas44px. Se detectó y corrigió
un desbordamiento de Safari/WebKit por el encabezado accesible absoluto: su contenedor
ahora es el bloque de referencia de posicionamiento.

Bibliotecas verificadas en documentación oficial Context7/npm: Chart.js4.6.1 y
vue-chartjs5.3.4 frente a Apache ECharts6.1.0/Vue-ECharts8.3.2. Se eligió e instaló
ECharts/Vue-ECharts, módulos SVG diferidos, área/barras intercambiables, tooltips,
zoom en rangos largos, dona de cobros, selector de moneda, resize y movimiento reducido.
Dinero exacto en etiquetas/tabla: BigInt/strings; ECharts recibe geometrías enteras
normalizadas sin calcular saldos con floats. Sin CDN ni series de ejemplo en UI.
Textos centralizados en resources/js/locales/es/salesControl.ts y lang/es|en/sales_control.php.

Evidencia desde C:/xampp/php/www/BShop:
- Destino SQL de pruebas mostrado: testing/sqlite/:memory:, sin host remoto,
  phpunit.xml forzado y guardia efectiva en TestCase antes de migraciones.
- `PHP83 artisan test --compact`:214 pruebas/2.741 aserciones,33,97s en revisión final
  backend. Incluye nuevo reporte y regresiones de crédito/ajustes/reembolso/semanales.
- `PHP83 vendor/bin/pint --test`: aprobado.
- `npm run build`: vue-tsc/Vite aprobados,779 módulos; entrada473,95kB/gzip146,06,
  gráficos diferidos608,86kB/gzip210,38. Aviso Vite de chunk superior a500kB registrado;
  la dependencia de gráficos ya está fuera de la entrada y sólo carga en el dashboard.
- `npm ls echarts vue-echarts`:6.1.0/8.3.2; instalación npm sin vulnerabilidades reportadas.
- `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- sales-control.spec.ts --grep "table and card" --reporter=dot`:
  6/6,4,9s, tras corregir WebKit.
- `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- --reporter=dot`:598/598,5,1 minutos;
  build real, API interceptada, seis perfiles.60 casos del módulo. Se revisaron capturas
  de escritorio/iPad/móvil, títulos, SVG y tabla con cadenas decimales máximas.
- `PHP83 scripts/verify-demo.php`: PASS, destino local/sqlite/host local,
  database/bshop-business.sqlite. Resumen por sucursal contrastado contra posiciones:
  principal15 operaciones (9 borradores/1 anulado),2 facturas,177 facturados netos
  +303 sin facturar=480 USD;257 aplicados+223 pendientes=480; cobros confirmados257.
  Segunda sucursal0. Stock98/8/90. Sin nuevas operaciones comerciales ni migración.

Documentación CONTROL_VENTAS/API/README/QA/ESTADO actualizada. Checklist48/64;
se conserva estimación global aproximada75%, sin equiparar pruebas con aceptación.
Pendientes: carga/concurrencia MySQL del reporte y módulos financieros nuevos,
tablet física, compensaciones/comisiones, reportes adicionales/configuración y entrega.

Pulido final de pantalla: filtros de tipo/estado ampliados en móvil para evitar
opciones recortadas y resumen compacto en dos columnas en tablet. Build aprobado;
`BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- sales-control.spec.ts --reporter=dot`:
60/60,25,8s, sobre el build definitivo; regresión completa previa598/598.

## 2026-11-15 UTC — Reversión compensatoria de aplicaciones de crédito

Continuación del bloque financiero: CreditApplicationReversals permite corregir
una aplicación completa por error, con snapshot/hash/autor/motivo, permiso credits.reverse
y referencia única. Restaura las liberaciones efectivamente consumidas y reabre deuda
del destino; conserva aplicación/asignación/ledger/PDF/stock históricos. Disponible
en Ventas/Apartados, historial y actividad cronológica del control comercial.

OrderSettlement/CreditPools separan historia y vigencia: disponible=L+R+V y asignado
efectivo=A−R−V. Sin recibos positivos ficticios para restituir saldo. Factura semanal
se recalcula una vez; cadena A→B→C restaura a B si B era dueño de la liberación.
Bloquea aplicaciones ya revertidas/liberadas, recepción o reembolso pendiente,
comisiones y estados incompatibles. Locks ordenados, revisión actual, idempotencia
y conservación del pago antes/después. Reversión de pagos con aplicaciones históricas
sigue requiriendo su conciliación avanzada independiente.

UI con AppDialog secuencial, textos centralizados creditReversal.ts, motivo conservado
al girar/conflicto y journal por actor/sucursal/pedido. Respuesta perdida se recupera
por GET al volver; no reenvía POST automáticamente. Permiso owner/manager agregado
en instalación y migración; Gate/rate limiter propios. Seller sólo consulta.

Verificaciones desde C:/xampp/php/www/BShop:
- Destino SQL de pruebas: testing/sqlite/:memory:, sin host remoto, guardia efectiva
  de TestCase y phpunit.xml forzado, mostrado antes de ejecutar pruebas.
- `PHP83 artisan test --compact`:223 pruebas/2.944 aserciones,47,49s. Nueve casos
  específicos: inmutabilidad/replay/PDF, reembolso después, semanal, liberación/retención,
  revisión/scope, permiso/actor de recuperación, CSRF, cadena y reembolso pendiente.
- `PHP83 vendor/bin/pint`: aprobado tras normalización de los nuevos cambios.
- `npm run build`: vue-tsc/Vite aprobados; entrada481,43kB/gzip147,74,783 módulos;
  gráficos siguen diferidos608,86kB/gzip210,38 con aviso de umbral registrado anteriormente.
- `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- baskets.spec.ts --grep "credit reversal|consumed credit" --reporter=dot`:
  30/30,23,8s.
- `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- --reporter=dot`:628/628,5,8 minutos;
  seis perfiles, build real/API interceptada. No aceptación de tablet física.
- Destino demo declarado/verificado local/sqlite/host ninguno:
  C:/xampp/php/www/BShop/database/bshop-business.sqlite. Respaldo íntegro verificado
  `.runtime/bshop-backup-20261115-025251.sqlite`,76 tablas antes de migrar.
- `PHP83 artisan migrate --path=database/migrations/2026_11_15_002800_create_credit_application_reversals.php --force`:
  aplicada sólo esa tabla/permisos, sin refrescar datos ni otras migraciones.
- `PHP83 scripts/verify-demo.php`: PASS; hash/contexto/importe de compensaciones
  verificados,98 físico/8 reservado/90 disponible;480=257+223 USD,2 facturas/5 pagos.
  No se aplicaron ni revirtieron créditos comerciales en la demo.

Repositorio indicado por el propietario: https://github.com/wailanbrea/bshop.git.
`gh repo view` confirmó repositorio vacío y `git remote add origin` lo vinculó;
`git remote -v` verificado. Rama local develop sin commits; sin commit/push solicitado.

Guía REVERSION_APLICACIONES_CREDITO y README/API/QA/ESTADO actualizados. Checklist49/65;
aproximado75% conservado. Próximos bloques: otras compensaciones (recepciones/ajustes/
reembolsos), comisiones y revalidación MySQL específica, además de entrega operativa.

## 2026-11-15 UTC — Publicación inicial y compensación de recepciones pendientes

Commit inicial b494fcd creado por petición expresa. Se revisaron archivos publicables,
diff y patrones de credenciales; .env/datos SQLite/runtime/documentos/capturas no se
incluyen. .gitignore ampliado para capturas y variantes .env, conservando .env.example.
GitHub rechazó inicialmente el workflow por scope; tras autorización del propietario,
GitHub CLI recibió workflow y `git push -u origin develop` publicó el commit completo.
El primer CI detectó11 detalles de formato omitidos por caché local: se normalizó
con caché nueva y se verificó otra caché independiente, sin desactivar controles.

ReturnReceiptReversals: recepción received sin ajuste/reembolso, revisión de fuente
y stock disponible agregado, compensación StockLedger customer_return_reversal,
detalles original/inverso, snapshot/hash, actor/motivo y estado void. No se modifican
artículos originales, pagos, facturas ni PDF. Cupo restaura cantidades no vigentes;
retención sólo desaparece si no quedan otras recepciones received, incluso en semanal.
Permiso returns.reverse independiente, recuperación por GET y UI secuencial con
motivo conservado al girar/conflicto. Historia y actividad muestran compensación.

Pruebas: testing/sqlite/:memory:, sin host remoto; TestCase bloquea antes de migrar.
- `PHP83 artisan test --compact`:231/3.081,41,33s;8 escenarios nuevos de stock/PDF,
  no reposición, bloqueo económico, stock usado/revisión, múltiples recepciones,
  scope/permiso/recuperación, CSRF y retención semanal.
- `npm run build`: aprobado,787 módulos,entrada488,64kB/gzip148,91.
- `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- baskets.spec.ts --grep "physical receipt compensation" --reporter=dot`:
  30/30,23,8s. Completa `--reporter=dot`:658/658,5,8 minutos, seis perfiles interceptados.
- Pint normalizado; control frío `--test --cache-file=.runtime/pint-ci-verification.cache` aprobado.
- Destino local/sqlite/host ninguno, database/bshop-business.sqlite; respaldo íntegro
  `.runtime/bshop-backup-20261115-032624.sqlite`,77 tablas previas.
- `PHP83 artisan migrate --path=database/migrations/2026_11_15_002900_create_return_receipt_reversals.php --force`:
  aplicada tras inspección, sólo tablas/FK/checks/permisos nuevos.
- `PHP83 scripts/verify-demo.php`:PASS;98/8/90 stock y480=257+223 USD;2 facturas/5 pagos.
  Sin compensaciones comerciales de demo. Verificador contempla pares de kardex.

Guía REVERSION_RECEPCIONES y QA/README/API/ESTADO actualizados;50/66,aproximado75%.
El propietario confirmó que la futura corrección de reembolsos es sólo por registros
erróneos donde nunca se entregó dinero; no representa recuperación de efectivo real.

## 2026-11-15 — Corrección de reembolsos erróneos y cierre de publicación

Aplicada la regla confirmada: compensar sólo registros erróneos cuando nunca hubo
entrega de dinero. RefundReversals exige confirmed_no_money aceptado, razón, revisión,
permiso refunds.reverse, contexto y unicidad. Guarda snapshot/hash/autor/fecha y
mantiene refund/confirmación/ledger/facturas/PDF/stock originales. No crea pago positivo
ni revierte transferencias. OrderSettlement distingue reembolso histórico/efectivo;
RefundState excluye registros compensados de consumos/reembolsos netos y el disponible
agrega su contrapartida. Aplicar crédito/reembolsar/ajustar posteriormente conserva
el pago original y presupuesto, sin sumar nuevamente dinero.

UI de confirmación explícita en conciliación e historial/actividad conservados. Journal
con UUID, recepción/reembolso y casilla; recuperación GET al volver, sin reenvío automático,
motivo/casilla conservados ante giro/conflicto. Owner/manager reciben permiso independiente.

Pruebas testing/sqlite/:memory:, sin host remoto, guardia de TestCase vigente:
- `PHP83 artisan test --compact`:239 pruebas/3.228 aserciones,45,50s;8 casos nuevos.
- `npm run build`: aprobado,791 módulos, entrada496,73kB/gzip150,66; gráficos siguen
  diferidos608,86kB/gzip210,38 con aviso Vite de umbral ya registrado.
- `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- baskets.spec.ts --grep "refund correction" --reporter=dot`:
  30/30,27,7s. Completa `--reporter=dot`:688/688,6,2 minutos, seis perfiles interceptados.
- Pint verificado con caché independiente: `--test --cache-file=.runtime/pint-final-refunds-verified.cache` aprobado.
- Destino local/sqlite/host ninguno, database/bshop-business.sqlite. Copia íntegra
  `.runtime/bshop-backup-20261115-063846.sqlite`,79 tablas previas.
- `PHP83 artisan migrate --path=database/migrations/2026_11_15_003000_create_refund_reversals.php --force`:
  aplicada tras inspección, nueva tabla/FK/check/permisos; sin refrescar datos.
- `PHP83 scripts/verify-demo.php`:PASS;98/8/90 stock y480=257+223 USD;2 facturas/5 pagos.
  No se crearon ni compensaron reembolsos comerciales de demo.

Publicación anterior:30911b4 en origin/develop contiene compensaciones físicas y
normalización de formato; CI43672751795 pasó PHP/build y655/658 browser, detectando
tres problemas Linux. Evidencia descargada a .runtime/ci-43672751795. Se corrigió foco/
scroll del error después de busy, y la etiqueta/mensaje de reintento no aparece durante
el POST inicial. Prueba espera el error de red real antes del reintento. WebKit devolvía
ratio0.9999219 con botón completo: se comprueba ratio>=.99 y caja dentro del viewport
con tolerancia1px. Regresión688 local pasó después de esos cambios.

CI ahora separa backend/build y seis trabajos browser por perfil, fail-fast=false,
con los mismos assets reales/API interceptada usados localmente; sin excluir perfiles
ni pruebas. Permiso workflow autorizado por el propietario; sin omitir hooks ni checks.
Guía CORRECCION_REEMBOLSOS y QA/README/API/ESTADO actualizados;51/67,aproximado75%.
Pendientes: compensación de ajustes económicos, MySQL específico y comisiones/entrega.

## 2026-11-15 — Compensación y versiones de ajustes económicos

ReturnAdjustmentReversals compensa un ajuste íntegro con permiso adjustments.reverse,
motivo/actor/snapshot/hash, versión/UUID/revisión y recuperación privada. Retira su
crédito disponible y restituye asignaciones/deuda, sin nuevos pagos ni cambio físico.
Bloquea consumos de crédito/reembolso y estados/comisiones inciertos. Sólo el último
ajuste vigente del pedido: LIFO conserva el redondeo acumulado0,33/0,34/0,33.
La recepción vuelve a received y retiene nuevos cobros hasta revisión o anulación física.

003100 añade puntero de versión vigente con FK compuesta, líneas por ajuste/artículo
y compensaciones únicas. Copia líneas/ajustes iniciales y sustituye el antiguo índice
de una versión por recepción; no borra filas. Los ajustes/PDF y primeras líneas siguen
históricos; refund_amount de la recepción es una proyección vigente. Puede aprobarse
otra versión con ID/PDF distintos. AdjustmentState centraliza ajustes/liberaciones
efectivos en pedidos/facturas/pagos/pools, y se actualizaron filtros de Apartados.
Historia/actividad y descargas conservan versiones compensadas. UI con diálogos
secuenciales y textos centralizados, motivo retenido al girar/conflicto y journal GET
sin reenvío automático. Replay antiguo después de reaprobar no cancela la versión nueva.

Verificaciones desde C:/xampp/php/www/BShop:
- Destino PHP testing/sqlite/:memory: mostrado, phpunit.xml forzado y conexión efectiva
  validada por TestCase antes de migrar; sin host remoto.
- `PHP83 artisan test --compact`:251 pruebas/3.482 aserciones,58,78s.12 casos nuevos:
  historia/PDF/revisión, deuda, valor0, LIFO/céntimos, crédito/reembolso consumido,
  recepción física posterior, scope/permiso/CSRF, migración con filas previas, factura
  semanal y cadena de crédito resuelta en orden.
- `npm run build`: aprobado,795 módulos,entrada505,67kB/gzip152,02; gráficos diferidos
  608,86kB/gzip210,38. Aviso Vite de chunks>500kB registrado, no se elevó el umbral.
- `BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- baskets.spec.ts --grep "economic adjustment compensation" --reporter=dot`:
  30/30,27,4s. Completa `--reporter=dot`:718/718,7,3 minutos, seis perfiles interceptados.
- Pint normalizado y comprobado con caché nueva .runtime/pint-adjustments-final-verified.cache.

MySQL aislado:
- Se inició sólo el binario/configuración existentes del proyecto, sin inicializar ni
  limpiar datos. Host127.0.0.1:33078,bshop_testing_single,testing,MySQL8.4.13 y datadir
  .runtime/mysql-data verificados por import-test-connection.php antes de SQL de negocio.
- Simulación de pendientes002000–003100 antes de ejecutar. `PHP83 scripts/mysql-migration-check.php`
  guardó copia íntegra verificada de63 tablas en
  .runtime/checked-mysql-backup-20261115-073149-ae7260.json, aplicó las migraciones
  inspeccionadas y comprobó que filas previas/identidades/permisos existentes se conservaron.
- `PHP83 scripts/verify-adjustment-reversals-mysql.php`:PASS, flujo nativo de ajuste,
  reembolso erróneo corregido, compensación/nueva versión, stock/asignaciones intactos
  y extremo199999999999999,98 con precisión DECIMAL. Fixtures/perfil revertidos por
  transacción. Sin HTTP, archivos PDF ni proveedores reales. No simula concurrencia.

Demo SQLite:
- Destino local/sqlite/host ninguno,database/bshop-business.sqlite; copia íntegra
  .runtime/bshop-backup-20261115-073914.sqlite,80 tablas previas.
- `PHP83 artisan migrate --path=database/migrations/2026_11_15_003100_version_return_adjustments.php --pretend --force`:
  simulación mostrada; aplicación posterior de sólo esa migración completada.
- `PHP83 scripts/verify-demo.php`:PASS, proyecciones/versiones/hashes verificados;
  físico98/reservado8/disponible90;480=257+223 USD;2 facturas/5 pagos. No se crearon
  ni compensaron ajustes comerciales de demostración.

Guía COMPENSACION_AJUSTES y README/API/QA/ESTADO actualizados.52/68; aproximadamente75%
de planificación conservado. Pendientes: concurrencia/carga MySQL, comisiones y módulos
comerciales restantes, tablet física y entrega operativa.

### Seguimiento CI del hito de ajustes

569e60c publicado en origin/develop. CI43692872524 aprobó backend y cuatro perfiles,
pero ambos iPad fallaron en un caso previo de visibilidad del error de sucursales;
los casos financieros nuevos pasaron. Se añadió a AppDialog un slot notice fuera
del cuerpo desplazable y se trasladó el error de ubicación a esa zona fija. Se
mantiene foco sin scroll automático y la prueba original no se relajó.
`npm run build` aprobado (entrada505,96kB/gzip152,14); focalizado
`BSHOP_BROWSER_OFFLINE=1 npm run test:tablet -- tablet.spec.ts --grep "validation keeps the draft" --reporter=dot`:
4/4,4,6s. Se publica corrección separada y se verifica la regresión/CI nuevamente.

9b70244 publicado; CI43694069582 aprobó application y los seis perfiles browser.

## 2026-11-15 — Carreras financieras MySQL y lecturas actuales InnoDB

Se incorporaron harness, protocolo/worker multiproceso e inicializador CI restringido
a esquema QA vacío. Once escenarios/dos rondas con procesos y conexiones distintos;
el padre retiene producto y exige observar consultas SQL simultáneas antes de liberar.
Para los tres escenarios mixtos se invierte la cola y se exige ambos ganadores.
Incluye UUID idéntico/replay, actores distintos y cuatro consumidores del mismo saldo.

La ejecución716E42DF4819 detectó un defecto real: compensación primero y aplicación
después podían confirmar ambas. La subconsulta NOT EXISTS de compensaciones seguía
leyendo un snapshot previo, aunque el SELECT exterior tuviera FOR UPDATE. Se propaga
el bloqueo a las subconsultas en escritores de AdjustmentState/RefundState/CreditPools,
OrderSettlement y flujos de recepciones/ajustes/reversiones. Sin migración ni cambio
de aislamiento global. Referencia MySQL8.4 Locking Reads en CONCURRENCIA_FINANCIERA.

Verificación desde C:/xampp/php/www/BShop:
- Antes de SQL:testing/mysql/127.0.0.1:33078/bshop_testing_single; MySQL8.4.13,
  bind127.0.0.1/datadir .runtime/mysql-data verificados por guardia. Preflight:PASS.
- Copia íntegra schema+rows de82 tablas y SHA-256 verificados antes de fixtures;
  sólo sucursal/almacén/producto/usuario/clientes sintéticos exclusivos de cada run.
- `PHP83 scripts/verify-financial-concurrency-mysql.php --rounds=2`:AF1CBE7E4A53,
  22/22; revalidación CF387AFF4AF6 también22/22. Ambos ganadores en escenarios mixtos,
  replay único200/200; cuatro consumidores200/409/409/409; resto200/409.
  Conservación por pago/pedido, dinero solicitado, originales/snapshots/facturas/stock
  verificados. Fixtures archivados, stock compensado/conciliado y todas filas previas
  conservadas. Evidencia privada .runtime/financial-race-CF387AFF4AF6/report.json.
- `PHP83 artisan test`:258 pruebas/3.490 aserciones,52,93s; destino forzado
  testing/sqlite/:memory: validado por TestCase. Siete tests nuevos del protocolo
  Windows/Linux y rechazo de resultados inválidos/ambiguos o rutas ajenas.
- `PHP83 scripts/verify-adjustment-reversals-mysql.php`:PASS, ajuste/versiones,
  corrección de reembolso y DECIMAL extremo; fixtures/perfil revertidos por transacción.
- `PHP83 scripts/verify-demo.php`:local/sqlite/database/bshop-business.sqlite,
  sólo lectura;PASS98/8/90 stock y480=257+223 USD;2 facturas/5 pagos intactos.
- `npm run build`:PASS,796 módulos; entrada505,96kB/gzip152,14 y gráficos diferidos
  608,86kB/gzip210,38. Aviso chunks>500kB permanece registrado.
- Pint requirió segunda pasada en harness; `--test` con caché nueva
  .runtime/pint-finance-20261115-d.cache:PASS.

Nuevo job financial-concurrency de GitHub Actions:MySQL8.4 Docker desechable,
credenciales aleatorias enmascaradas, guardia de destino común, bootstrap sólo base
QA vacía, dos rondas y artifacts report/overlap/out/err sin backup ni credenciales.
Se comprobará la primera ejecución remota y la matriz de seis perfiles tras publicar.
No se repite suite browser local: sin cambios UI; se conserva718/718 del hito previo
y se ejecuta matriz completa en CI del nuevo commit.

Guía CONCURRENCIA_FINANCIERA, compensación, QA y ESTADO actualizados.53/69 puntos,
estimación aproximadamente75% conservada. Pendiente carga sostenida, carreras
específicas semanales/enlaces/vencimientos, comisiones, módulos restantes, tablet
física y entrega. Harness sin HTTP/PDF/proveedores ni uso de VPS/producción.

### Resultado remoto de concurrencia

7923cc9 publicado en origin/develop. CI43719059152 aprobado completo:
application, financial-concurrency y seis perfiles browser. Informe MySQL del runner
B6ED2E775727:22/22, ambos órdenes mixtos, cero fallos, fixtures archivados y filas
previas preservadas. Artefacto financial-concurrency-evidence-1 descargado a
.runtime/ci-43719059152-financial para inspección. Bootstrap/guardia/Docker y subida
selectiva de evidencia pasaron en Linux. Regresión browser completa718/718 aprobada.
Ejecución: https://github.com/wailanbrea/bshop/actions/runs/43719059152

## 2026-11-15 — Concurrencia MySQL de cuentas semanales

Se añadieron verify-weekly-concurrency-mysql.php, weekly-concurrency-support.php y
worker independiente.16 escenarios/dos rondas:crear ciclos iguales/solapados/replay,
agregar/replay y facturación individual, doble cierre/replay, cierre frente a agregar,
retirar, cobrar o facturar individualmente; cobros del mismo/distintos miembros/replay
y anulación de cuenta vacía frente a agrupación. Se exigen ambos ganadores en los
seis escenarios mixtos, conexiones/PID independientes y consultas SQL simultáneas
observadas antes de liberar la barrera de dominio. El job CI existente ejecuta22
carreras financieras y después32 semanales; artifacts selectivos para ambas suites.

Se verifican factura/fuentes/artículos únicos, asignaciones por pago, ledger, efectivo
solicitado, estados agregados, conservación decimal, snapshots/contacto/abonado al
cierre, stock/reservas/kardex y secuencia sin consumos por operaciones rechazadas.
Después de los cuatro cierres que perdieron ante un cambio, se revisa y cierra otra
vez explícitamente:incorpora el cobro, ajusta miembros y excluye la factura individual
sin reescribirla. No se detectó defecto productivo nuevo; no hay migraciones ni cambios
a las reglas de cuentas semanales.

Destino mostrado antes de operar:testing/mysql/127.0.0.1:33078/bshop_testing_single,
MySQL8.4.13,bind local/datadir .runtime/mysql-data validados por guardia común.
Se comparte mutex con harness financiero, se respalda schema+filas de82 tablas y se
verifica SHA-256 antes de fixtures. Sólo sucursal/almacén/actor/producto/clientes QA.
Al terminar se entregan los apartados sintéticos mediante el servicio real para
liberar reservas; salida compensatoria del remanente, conciliación y archivo de
fixtures. Historia intacta; comparación de cada fila preexistente con el respaldo.
No se utilizó la demo SQLite ni VPS; sin HTTP/PDF/proveedores.

Comandos/resultados desde C:/xampp/php/www/BShop:
- `PHP83 scripts/verify-weekly-concurrency-mysql.php --preflight`:PASS, sólo lecturas.
- `... --rounds=2`:primera instrumentación E64F2CD89D59 rechazó barrera tras15 casos;
  cancelar cuenta vacía no espera por pedido no agrupado. Se cambió la barrera del
  harness a la cuenta; no fue defecto comercial. Fixtures archivados/previos intactos.
- Ejecución5DB9113ED12F:32/32. AF2AD6097F8D:32/32 con recierres revisados.
- Revalidación final BD64A632AA27:32/32, ambos ganadores en seis escenarios mixtos,
  cuatro recierres, cero fallos;fixtures_archived/preexisting_rows_preserved=true.
  Evidencia privada .runtime/weekly-race-BD64A632AA27/report.json y after/overlap.
- `PHP83 artisan test --compact`:258 pruebas/3.490 aserciones,47,02s. Destino forzado
  testing/sqlite/:memory: y guardia TestCase antes de migrar. Sin pruebas sobre VPS.
- `PHP83 vendor/bin/pint --test --cache-file=.runtime/pint-weekly-20261115-d.cache`:
  PASS con caché nueva. La regresión build/browser completa se comprueba en CI al
  publicar; sin cambios de interfaz, no se repite la suite local718 del hito anterior.
- CI `f094e89`, run 43729717065: application (Pint, 258 pruebas, build),
  financial-concurrency (22 financieras + 32 semanales en MySQL8.4 Docker) y los
  seis perfiles browser en success.
  https://github.com/wailanbrea/bshop/actions/runs/43729717065
- Artefacto `financial-concurrency-evidence-1` verificado:weekly-race-A727BCD2A0E7
  status passed,32 casos,0 fallos,ambos ganadores en los seis escenarios mixtos,
  fixtures archivados y filas previas conservadas; financial-race-7D4576CB97F1 passed
  22/22. Copia privada .runtime/ci-43729717065-concurrency/.

CONCURRENCIA_SEMANAL/CUENTAS_SEMANALES/CONCURRENCIA_FINANCIERA/QA/ESTADO actualizados.
54/70 puntos,aproximadamente75% conservado. Pendientes:política de corte/automatización,
carga sostenida, enlaces/vencimientos, comisiones, tablet física y entrega operativa.

## 2026-11-15 - Verificación MySQL de vencimientos y reconciliación

Destino comprobado antes de operar: entorno `testing`, conexión `mysql`, host
`127.0.0.1:33078`, base `bshop_testing_single`, MySQL 8.4.13 y datadir local
`.runtime/mysql-data`. El guardia confirmó la identidad del servidor y la base.

`PHP83 scripts/verify-reservations-mysql.php`: PASS en los siete casos preparados:
vencimiento frente a cobro, entrega parcial y edición; vencimiento antes de cobro;
dos vencimientos; reconciliación frente a escritor; y precisión DECIMAL(19,4)
extrema con reserva fraccionaria. El fixture extremo se revirtió; los demás
fixtures sintéticos confirmados y artefactos se conservaron para inspección en
`.runtime/reservation-qa-C163DF204167`. No se usó la demo ni el VPS.

Queda pendiente instalar y verificar la ejecución persistente del programador en
el destino, además de la política de cancelación de apartados con abonos.

## 2026-11-15 - Validación del estado del programador

El monitor valida el esquema, estado, fechas ISO, PID y resultado coherente de
cada tarea antes de iniciar el programador. Un JSON sintácticamente válido pero
incompleto o incoherente detiene la ejecución y se conserva sin sobrescribir.
Se probaron estados incompletos, fechas inválidas y resultados contradictorios,
además de los flujos existentes de fallo persistente, recuperación y exclusión.

Destino de pruebas: `testing`, SQLite `:memory:`, forzado por `phpunit.xml` y
verificado por `tests/TestCase.php`. `PHP83 artisan test --compact`: 259 pruebas,
3.517 aserciones. Pint aprobado para `SchedulerMonitor.php` y `SchedulerTest.php`.
No se instaló ni ejecutó el programador en el destino de entrega.

## 2026-11-15 - Vencimiento tras reversión de pago

El selector del lote de vencimientos excluía cualquier pago histórico, aunque la
regla de reversión y la revalidación transaccional permiten vencer un apartado con
pagos solo revertidos o fallidos. El selector ahora excluye pagos pendientes o
confirmados, alineándose con la revalidación bajo bloqueo. Las facturas y entregas
siguen protegiendo el apartado; el proceso no crea ni devuelve dinero.

Una prueba crea apartados y revierte un pago mediante el flujo real; comprueba el
diagnóstico, vencimiento, repetición idempotente, conservación del historial,
protección de pagos pendiente/confirmado y conciliación de inventario. Destino:
`testing`, SQLite `:memory:`, forzado por `phpunit.xml` y guardia `TestCase`.
`PHP83 artisan test --compact`: 260 pruebas, 3.545 aserciones. Pint aprobado en
el comando y su prueba. Esta prueba nueva no acredita el caso específico en MySQL.

## 2026-11-15 - Selector de vencimiento y reversión en MySQL

Se extrajo la consulta de candidatos para que el comando y el verificador utilicen
exactamente el mismo selector. Destino comprobado antes de operar: `testing/mysql`,
`127.0.0.1:33078`, base `bshop_testing_single`, MySQL 8.4.13 y datadir local.
`PHP83 scripts/verify-reservations-mysql.php`: ocho casos aprobados. El caso nuevo
confirma que un pago confirmado excluye el pedido, su reversión lo hace elegible y
el vencimiento preserva la reversión y concilia el stock. Solo consulta y vence su
propio fixture; no ejecuta el comando global con `--apply`. Los siete casos previos
también aprobaron; fixture extremo revertido. Artefactos y fixtures sintéticos:
`.runtime/reservation-qa-DE8C727100F6`.

Regresión `PHP83 artisan test --compact`: 260 pruebas y 3.545 aserciones en
`testing/sqlite/:memory:`; Pint aprobado para los archivos PHP del cambio.

## 2026-11-15 - Continuación manual de lotes de vencimiento

El comando devuelve los IDs con resultado `failed`, `inconsistent` o `changed`
en `attention` y el último ID revisado en `next_after_id`. La opción explícita
`--after-id` permite continuar un lote después de un pedido que requiere revisión,
sin alterar ese pedido ni ocultar el fallo de su ejecución. No se guarda cursor:
cada ejecución vuelve a calcular el corte y el programador comienza desde cero.
Los casos anteriores requieren diagnóstico y reintento desde el principio.

Prueba funcional: error del primer pedido en lote de uno, rollback y salida de
fallo con su ID; continuación explícita que vence el segundo; límites inválidos y
fin de resultados. `PHP83 artisan test --compact`: 261 pruebas, 3.569 aserciones,
destino `testing/sqlite/:memory:` forzado por PHPUnit y comprobado por TestCase.
Pint completo aprobado. La continuación nueva no se ha ejecutado en MySQL.

## 2026-11-15 - Límite de enlaces públicos bajo concurrencia MySQL

CI del commit `da3d97f`: run 43780941921 completado con éxito:
https://github.com/wailanbrea/bshop/actions/runs/43780941921

En `testing/mysql`, host `127.0.0.1:33078`, base `bshop_testing_single`,
MySQL 8.4.13 con datadir local verificado, se reprodujo una carrera real: con
cuatro enlaces activos, dos trabajadores establecieron instantáneas y esperaron
simultáneamente el bloqueo del pedido; ambos superaron el límite de cinco. El caso
fallido utilizó únicamente fixtures sintéticos conservados para inspección.

La consulta de enlaces activos usa ahora lectura con bloqueo después de bloquear
el pedido, para ver confirmaciones posteriores a la instantánea anterior. El
verificador volvió a ejecutarse: un trabajador creó el quinto, el otro recibió
409 y quedaron cinco enlaces activos. Los otros ocho casos también aprobaron.
Fixtures y artefactos privados: `.runtime/reservation-qa-E59B65D732A3`.
No se registran tokens en los resultados del verificador.

Regresión: `PHP83 artisan test --compact` aprobó 261 pruebas / 3.569 aserciones
en `testing/sqlite/:memory:`; Pint completo aprobado. Es una carrera específica,
no cobertura integral de todas las operaciones de enlaces ni prueba de carga.

## 2026-11-15 - Límite concurrente de enlaces para nuevos pedidos

En MySQL 8.4.13 aislado, `testing/mysql`, `127.0.0.1:33078`, base
`bshop_testing_single`, datadir local verificado, se reprodujo una carrera análoga
en la emisión de autorizaciones de pedido: dos procesos con una instantánea previa
esperaron el bloqueo de sucursal y ambos pudieron superar cinco enlaces activos.
La consulta de enlaces del cliente usa ahora lectura con bloqueo después de la
adquisición de sucursal. Al repetir, uno creó el quinto enlace y el otro recibió
409; quedaron cinco activos. También aprobaron los nueve casos anteriores.
Fixtures y artefactos privados: `.runtime/reservation-qa-D28846BBAA97`.

`PHP83 artisan test --compact`: 261 pruebas / 3.569 aserciones en
`testing/sqlite/:memory:`; Pint completo aprobado tras corregir formato. Ambos
escenarios de límites de enlaces están validados en MySQL aislado; otras carreras
y aceptación en el destino siguen pendientes. No se imprimieron tokens.

## 2026-11-16 - Ropa, fotos y existencia inicial

Se incorporaron selección color/talla por variante en ventas, edición de apartados
y catálogo por enlace; miniaturas por variante/color en inventario y movimientos;
y creación desde fotos con existencia inicial opcional por variante. El alta de
producto y las entradas se confirman como una unidad idempotente; las fotos usan
sus claves de carga y conservan un aviso por pestaña si requieren revisión.

Verificación: `npm run build`, 287 pruebas PHP / 3.786 aserciones en SQLite de
memoria y 820/820 casos de navegador emulado con API simulada; Pint aprobado.
En MySQL 8.4 aislado, dos usuarios compitieron por la última unidad con resultado
201/409; la creación con stock verificó replay y rollback. Las fechas, usuario,
alcance, limitaciones y checklist están en `ROPA_FOTOS_INVENTARIO.md`. Pendientes
la aceptación física, R2 real, CI remota y publicación en el destino.

## 2026-11-16 - Alta guiada de ropa desde fotografías

El usuario confirmó que necesita colores y tallas habituales seleccionables y la
opción de añadir otros sin salir del formulario. Se agregó una modalidad de ropa
con botones por color, tallas por color, cantidades individuales y referencias
autogeneradas editables. Las fotos permanecen visibles como referencia al recorrer
el formulario. Los valores se reutilizan o crean solo al confirmar, dentro de la
transacción idempotente de producto e inventario; se preservan los flujos manuales.

`PHP83 artisan test --compact`: 290 pruebas / 3.818 aserciones en SQLite de
memoria. `npm run build`, Pint y 832/832 pruebas de navegador aprobados; tras
corregir el fixture `blob:` se repitieron 56 pruebas de fotos/catálogo. El flujo
de prueba incluye Verde/S=5, Verde/M=3, Negro/S=2 y Negro/M=0, y valores propios.
MySQL 8.4 aislado `testing/mysql/127.0.0.1:33078/bshop_testing_single`: alta con
stock/replay/rollback y dos creaciones simultáneas reutilizando los valores pasaron.
Evidencia privada `.runtime/clothing-options-638B42CBFD5A`.

La instalación pública no se modificó: por SSH el usuario `bsolutions` recibe
acceso denegado al listar `C:\xampp\htdocs\keylashop`. Se requiere acceso autorizado
al proyecto y verificación del estado antes de publicar; no se tocaron datos del VPS.

## 2026-11-18 - Variantes simplificadas, lote de 10 fotos, valoración de inventario, vendedores, cuentas semanales, PWA y atribución de comisiones

Se completaron y desplegaron en el VPS Linux (`https://keylashop.bsolutions.dev`, contenedor `keylashop-test-app-1`) las siguientes entregas sobre la rama `develop`:

- **Selector de variantes y tarjetas de venta (`80e5901`, `7e42a55`):** todas las tallas de una prenda permanecen visibles como chips (deshabilitadas en gris cuando no hay stock en el color elegido); cuando un producto tiene 1 sola variante disponible muestra directamente `Color: ... · Talla: ...` y `Disponible (X)` en la tarjeta y preselecciona esa combinación al abrirse.
- **Carga rápida de hasta 10 fotos y alta uno por uno (`ca633b3`):** componente `QuickPhotoProducts.vue` integrado en `ProductEditor.vue` y `CatalogView.vue` (`+ Subir hasta 10 fotos` y pestañas `Uno por uno`, `Hasta 10 fotos`, `Modo avanzado`), capturando **Nombre, Precio de compra (`cost_price`), Precio de venta (`sale_price`), Cantidad, Color, Size y Descripción (opcional)** debajo de cada foto.
- **Valoración contable de inventario y alertas de reposición (`1f5786a`, `b497f23`):** cálculo de **Capital invertido al costo**, **Valor en venta**, **Ganancia proyectada**, **Unidades** y filtro **Por reponer (`≤ 2`)** tanto en **Vista general** (`/`) como en **Inventario**.
- **Módulo de Vendedores/Contratos, Corte semanal, Cuentas semanales y PWA (`9a15020`):** alta, edición, activación/desactivación y asignación multisucursal de vendedores (`EmployeeController`, `EmployeesView.vue`), contratos inmutables de sueldo fijo o comisión, `PaymentDistribution` y `SalaryProration`, configuración del día de corte semanal (`WeeklySettingsController`, `WeeklySettings.vue`), desglose en cuentas semanales y soporte PWA (`manifest.webmanifest`, `pwa-icon.svg`, `pwa-worker.js`).
- **Costo de lo vendido, ganancia bruta del período y atribución de vendedor con comisión devengada:** cálculo de `cost_of_goods` y `gross_profit` en `SalesControl.php` y `SalesControlView.vue`; selector de **Vendedor que atiende** en **Ventas / Apartados** (`SalesSummary.vue`, `SalesWorkspace.vue`, `SalesService.php`) con instantánea de contrato y tasa de comisión activa en `order_items`, y **Resumen comercial del vendedor** (`orders_count`, `sales_net`, `collected_net`, `earned_commission`) en `EmployeeController::plans` y `EmployeesView.vue`.
