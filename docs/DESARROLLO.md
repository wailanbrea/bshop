# Desarrollo y entrega — instalación para un negocio

Leer `ARQUITECTURA_VIGENTE.md`. La documentación inicial multiempresa quedó
sustituida por instrucción explícita del propietario.

## Modelo

- `business_profile`: una única fila, ID 1, protegida por CHECK.
- `users`, `roles`, `role_user`: usuarios y permisos de esta instalación.
- `stores`, `warehouses`, `store_user`: sucursales, almacenes y acceso autorizado.
- `StoreContext` sólo representa la sucursal validada para una operación.
- Configuración/cuota pertenecen al negocio único (`business_settings`, `storage_quota`).
- No hay tablas de tenants/membresías, columnas tenant_id ni suscripciones SaaS.
- Los vínculos de facturas/pagos/stock conservan cliente, sucursal, almacén,
  moneda y vendedor donde corresponde. Compartir negocio no elimina los permisos.

## Herramientas y comandos

PHP 8.3.36 portable en `.runtime/php83`, Laravel 12, Vue 3/TypeScript, Vite y
Tailwind. Windows/Apache son el destino previsto. El servidor debe publicar
solamente `public/`.

```powershell
.\scripts\dev.ps1
.\.runtime\php83\php.exe artisan test
.\.runtime\php83\php.exe vendor/bin/pint --test
npm run build
```

Vista previa: http://127.0.0.1:8017, base local `database/bshop-business.sqlite`.
Al cambiar `.env`, reiniciar el servidor de desarrollo para renovar su entorno.
La suite obliga testing + SQLite :memory: antes de migrar.

## Regresión tablet

La navegación y los formularios se desarrollan primero para tablet. Usar
`AppDialog.vue` en formularios superpuestos, manteniendo las acciones fuera del
cuerpo desplazable. Los campos son de 16 px y las acciones principales de 48 px.

```powershell
npm run browsers:install
npm run build
npm run test:tablet
```

La suite usa WebKit y Chromium táctil, con API completamente interceptada para
evitar escrituras en datos reales. Prueba orientación, viewport reducido por
teclado, foco, borradores, listas largas, validación y doble envío. Evidencia,
matriz y alcance en `../qa/TABLET.md`. La aceptación en tablet física sigue pendiente.

## Instalación entregada al cliente

1. Instalar PHP 8.3+ y dependencias de Composer/npm.
2. Crear `.env` con APP_NAME=BShop, URL/HTTPS, APP_KEY y una base MySQL 8 dedicada.
3. Ejecutar `php artisan bshop:environment` y verificar host, base y entorno.
4. Ejecutar las migraciones en ese destino previamente verificado.
5. Ejecutar `php artisan bshop:install`: nombre del negocio, propietario, correo,
   contraseña, moneda, zona horaria y primera sucursal. Sólo se ejecuta en una base
   sin perfil de negocio. No tiene un alta web para otros negocios.
6. Configurar SMTP, worker/scheduler, R2 privado/público según módulo, respaldos y
   verificaciones de aceptación antes de ponerla en servicio.

Cada cliente recibe su instalación y base independiente. Las credenciales reales
no forman parte del repositorio. `bshop:demo` sólo permite el archivo SQLite local
y nunca cambia cuentas/contraseñas si ya hay un negocio instalado.

## MySQL local de pruebas

Instancia portable propia en 127.0.0.1:33078, datadir `.runtime/mysql-data`.
Base actual: `bshop_testing_single`. El script `verify-single-mysql.php` comprueba
versión, bind, puerto y directorio de datos; la opción `provision` sólo crea una
base inexistente. Las pruebas no apuntan al VPS ni a MariaDB compartido.

Resultado actual: 61 tablas, 100 FK y 62 CHECK. Se verificaron perfil único,
referencia de sucursal, almacén principal único, periodo salarial obligatorio y
máximo de fotos. Los módulos financieros aún requieren su lógica de dominio.

## Importación CSV

CatalogImportService coordina revisión/commit/reversión. Payload/result usan
cifrado de Laravel con APP_KEY; CsvCatalogReader limita bytes/filas/celdas y el
validator reutiliza reglas del catálogo. Original no se publica ni sube a R2.
Auditoría ahora permite entity_id VARCHAR(64), conservando valores anteriores;
no ejecutar rollback a BIGINT con IDs opacos: la guardia lo rechaza.

```powershell
.\.runtime\php83\php.exe scripts/verify-import-mysql.php
.\.runtime\php83\php.exe scripts/verify-import-concurrency.php
```

Sólo base MySQL aislada. El primero respalda/verifica esquema y auditoría antes
de migrar, y revierte su QA transaccional. El segundo conserva fixtures sintéticos
para inspección, sin fresh/truncate. Se verifican host/base/bind/versión/datadir.

## Conversión conservadora de la demo

Respaldo verificado: `.runtime/bshop-backup-20261112-183402.sqlite`.
De ese respaldo se exportó sólo KeylaShop a `.runtime/single-business-source.json`
y se importó en un archivo nuevo. Se conservaron conteos, claves, estados de
usuarios y hashes de contraseña. PRAGMA foreign_key_check sin errores.
El archivo anterior y los demás perfiles de prueba siguen intactos.

El código anterior fue archivado reversiblemente en `.runtime/legacy-multibusiness`.
No se ejecutan sus migraciones, clases ni pruebas. Estos archivos son antecedentes,
no una función multiempresa desactivada en la aplicación actual.

## Programador verificable y QA de reservas

`php artisan bshop:scheduler` consulta estado sin ejecutar tareas. El instalador
Windows y las condiciones de operación están en `PROGRAMADOR_WINDOWS.md`.
La instalación persistente no está acreditada. La validación específica de reservas/
reconciliación en MySQL está preparada en `VERIFICACION_RESERVAS_MYSQL.md` y sigue
pendiente de ejecución por permisos de socket del entorno actual.
