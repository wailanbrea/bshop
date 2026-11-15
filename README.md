# BShop — gestión de un negocio y sus sucursales

BShop se entrega como una instalación independiente para un cliente. En esta
instalación el negocio es **KeylaShop**. Sus usuarios, sucursales, almacenes,
clientes, catálogo y documentos pertenecen a ese único negocio.

La decisión vigente está en [ARQUITECTURA_VIGENTE.md](docs/ARQUITECTURA_VIGENTE.md).
Moneda de KeylaShop confirmada: **USD**, presentada como **US$**.
Repositorio: https://github.com/wailanbrea/bshop.

## Funcionalidad actual

- Dashboard comercial con indicadores reales, gráficos interactivos y un historial
  filtrable con detalle cronológico y exportación CSV por sucursal.
- Login/sesión/CSRF y recuperación de acceso (SMTP real pendiente).
- Perfil único del negocio; configuración de nombre y zona horaria.
- Sucursales y almacenes con permisos y acceso positivo por usuario.
- Roles de propietario, administrador y vendedor; invitaciones y suspensión de usuarios.
- Catálogo de productos/servicios, categorías, marcas, atributos y variantes; precios
  exactos, permisos de costo, búsqueda, archivo y protección de ediciones concurrentes.
- Importador CSV con plantilla, revisión por fila, historial y reversión controlada.
- Fotografías con derivados WebP, galería táctil, principal/orden/variantes,
  cuota de 2 GiB y recuperación de cargas; almacenamiento local activo y adaptador R2 configurable.
- Inventario por almacén, entradas/salidas/conteos, transferencias y kardex auditable.
- Clientes, venta rápida y apartados con reservas reales, canastas reabribles,
  abonos, entrega parcial y facturación individual idempotente.
- Facturas con snapshots y PDF privado; compartir manualmente y recuperar peticiones
  interrumpidas sin duplicar cobros/documentos. Interfaz responsive basada en la referencia.
- Apartados: tablero único con tarjetas arriba y detalle editable debajo, edición
  con depósitos/reservas diferenciales, número visible y filtros independientes.
- Enlaces temporales para consultar un apartado, copiar manualmente y revocar,
  con caducidad y resumen público limitado a ese pedido.
- Pedidos por enlace para un cliente existente: catálogo autorizado, carrito y
  confirmación única con reservas reales; recuperación de respuesta perdida.
- Cuentas semanales: agrupación por cliente/período y cierre manual con factura
  agregada única, abonos antes/después, PDF histórico y recuperación de operaciones.
- Historial y reversión contable de pagos por error, con permiso administrativo,
  motivo, crédito conciliado y actualización de facturas individuales/semanales.
- Recepción física de mercancía devuelta con cupo por entrega, reposición opcional
  trazada en kardex y ajuste económico pendiente identificado; cobro retenido hasta conciliar.
- Conciliación de devoluciones recibidas sobre importes originales, crédito/saldo
  neto y registro manual de reembolsos realizados, sin reescribir factura ni PDF.
- Aplicación de saldo liberado a otro pedido facturado del mismo cliente/sucursal/
  moneda, con procedencia por liberación y sin registrar un nuevo cobro.
- Comprobantes PDF privados e históricos de ajustes y aplicaciones de crédito,
  descargables desde su historial con integridad y reintento independiente.
- Reversión compensatoria de aplicaciones de crédito por error, con restauración
  de procedencia, deuda del destino, motivo y recuperación sin repetir movimientos.
- Anulación de recepciones físicas pendientes mediante kardex compensatorio,
  restaurando su cupo sin borrar historia ni modificar dinero.
- Corrección de reembolsos registrados por error, con confirmación de no entrega
  de dinero, saldo restaurado e historial compensatorio sin ingresos ficticios.
- Compensación de ajustes económicos y re-conciliación versionada de una recepción,
  conservando importes, procedencia y PDF históricos.
- WhatsApp automático opcional desde servidor, configurable con claves cifradas
  y confirmaciones del proveedor; desactivado por defecto.
- Auditoría e integridad del modelo de datos. Los módulos comerciales restantes
  tienen su esquema preparado, pero todavía requieren servicios y pantallas.

## Abrir la demo

http://127.0.0.1:8017

```powershell
.\scripts\dev.ps1
```

Correo local de demostración: `demo@bshop.test`. Se conserva la contraseña que
ya se entregó; no se publica en archivos del proyecto.

La demo usa `database/bshop-business.sqlite`. La base anterior permanece como
respaldo histórico y no se utiliza por la aplicación.
Incluye ocho productos con fotos, 19 variantes, cuatro clientes ficticios y operaciones
coherentes creadas mediante formularios reales. Ver [guía de la demo](docs/DEMO_KEYLASHOP.md).
Venta: http://127.0.0.1:8017/app/ventas · Apartados: http://127.0.0.1:8017/app/apartados.

## Entrega de una instalación nueva

Configurar su `.env` y MySQL propios, ejecutar las migraciones en el destino
verificado y después `php artisan bshop:install`. El asistente de consola crea
el único negocio, propietario y sucursal inicial. Rechaza una segunda instalación
en la misma base. No existe alta ni selector de otras empresas en la interfaz/API.

## Documentación

- [Desarrollo e instalación](docs/DESARROLLO.md)
- [API actual](docs/API.md)
- [Catálogo y reglas de variantes](docs/CATALOGO.md)
- [Importar catálogo desde CSV](docs/IMPORTACION_CSV.md)
- [Fotografías, cuota y configuración R2](docs/IMAGENES.md)
- [Inventario y kardex](docs/INVENTARIO.md)
- [Revisión y reconciliación de inventario](docs/RECONCILIACION_INVENTARIO.md)
- [Plazo y vencimiento de reservas](docs/EXPIRACION_RESERVAS.md)
- [Programador de Windows y seguimiento de tareas](docs/PROGRAMADOR_WINDOWS.md)
- [Verificación de reservas en MySQL aislado](docs/VERIFICACION_RESERVAS_MYSQL.md)
- [Ventas, apartados, pagos y PDF](docs/VENTAS_APARTADOS.md)
- [Dashboard comercial, gráficos e historial de ventas](docs/CONTROL_VENTAS.md)
- [Clientes, edición, archivo y reactivación](docs/CLIENTES.md)
- [Tablero único y WhatsApp opcional](docs/TABLERO_APARTADOS.md)
- [Compartir un pedido mediante enlace temporal](docs/ENLACES_CONSULTA.md)
- [Catálogo y pedidos mediante enlace](docs/PEDIDOS_POR_ENLACE.md)
- [Cuentas semanales y factura agregada](docs/CUENTAS_SEMANALES.md)
- [Historial y reversión contable de pagos](docs/REVERSION_PAGOS.md)
- [Recepción de mercancía devuelta y reintegro de stock](docs/RECEPCION_DEVOLUCIONES.md)
- [Anular una recepción física errónea](docs/REVERSION_RECEPCIONES.md)
- [Crédito y conciliación económica de devoluciones](docs/CONCILIACION_DEVOLUCIONES.md)
- [Compensar ajustes económicos y revisar versiones](docs/COMPENSACION_AJUSTES.md)
- [Corregir un registro erróneo de reembolso](docs/CORRECCION_REEMBOLSOS.md)
- [Aplicar saldo a favor entre pedidos](docs/APLICACION_CREDITO.md)
- [Revertir una aplicación de crédito por error](docs/REVERSION_APLICACIONES_CREDITO.md)
- [Comprobantes PDF de ajuste y crédito](docs/COMPROBANTES_FINANCIEROS.md)
- [Porcentaje de avance y trabajo restante](docs/ESTADO_DEL_SISTEMA.md)
- [Datos y recorrido de demostración](docs/DEMO_KEYLASHOP.md)
- [Checklist de la referencia visual](docs/TODO_UI_VENTAS_APARTADOS.md)
- [Arquitectura vigente](docs/ARQUITECTURA_VIGENTE.md)
- [Avance y evidencia histórica](docs/PROGRESO.md)
- [Tablero actualizado](qa/12_TODO_MAESTRO.md)
- [Verificación tablet y pruebas de navegador](qa/TABLET.md)
- [ER actual](docs/ER_BSHOP.md)

Stack: Laravel 12, PHP 8.3+, Vue 3/TypeScript, Vite/Tailwind. MySQL 8 para la
entrega; SQLite aislado para vista previa y pruebas rápidas.
