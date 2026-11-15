# Comprobantes históricos de ajuste y aplicación de crédito

En Ventas/Apartados, abrir **Devoluciones de mercancía → Conciliar recepción**
para descargar el comprobante de un ajuste aprobado. En **Saldo a favor del cliente**,
cada aplicación recibida ofrece su comprobante. Requiere `sales.view`, `invoices.view`
y acceso a la sucursal. Son comprobantes internos, no nuevas facturas fiscales.

## Conservación

- El ajuste/aplicación captura su snapshot y hash dentro de la transacción financiera.
  El PDF se prepara después del commit, sin repetir dinero, mercancía ni facturación.
- Conserva datos de negocio/cliente de la factura, sucursal al momento de operar,
  referencias AJU-/CRE-, fecha, autor, motivo, importes y procedencia del crédito.
- Almacén privado `private_invoices`, clave fija por tipo/ID/versión, SHA256 y tamaño.
  Descarga autenticada, `private, no-store`; sin URL pública ni recursos remotos.
- Bloqueo de archivo por documento. Publica un temporal verificado, sella su metadata
  y mueve a la clave definitiva antes de marcar ready. Un reintento recupera bytes
  sellados tras una interrupción sin renderizarlos nuevamente.
- Un ready faltante/alterado devuelve503 y exige restaurar el original. Un archivo
  definitivo sin sello válido se conserva para revisión y tampoco se sobrescribe.
- Operaciones anteriores sin snapshot se capturan al primer prepare con
  `legacy_capture=true`: negocio/cliente salen de la factura; sucursal se identifica
  como `Sucursal #ID`, porque no puede reconstruirse su nombre histórico.
- Respaldar conjuntamente base de datos y `storage/app/invoices`, incluyendo metadata
  y temporales sellados. `scripts/verify-demo.php` verifica snapshots y PDFs ready
  sin preparar archivos ni modificar operaciones.

## API

`/api/v1/business/stores/{store}/sales/orders/{order}/documents/{kind}/{record}`

- kind: `adjustment` o `credit`; record es el ID del ajuste/aplicación.
- POST: prepara/reutiliza, devuelve status/url/filename; sesión/CSRF y20 solicitudes/minuto.
- GET: descarga sólo ready;404 si no se preparó/contexto ajeno,403 sin permiso.
- POST:409 si otra preparación tiene el bloqueo,503 por fallo/integridad.
- Ante error de preparación o descarga, reintentar el botón del comprobante.

## Evidencia

205 pruebas PHP/2.558 aserciones en SQLite :memory:, diez escenarios específicos
de snapshot histórico, replay, integridad, permisos, CSRF y recuperación interrumpida.
538/538 casos browser en seis perfiles;36 nuevos de descarga/reintentos/permisos.
Build y Pint aprobados. Migración002700 aplicada sólo en SQLite local tras respaldo
`.runtime/bshop-backup-20261115-013116.sqlite` íntegro (75 tablas antes de migrar).
Demo conciliada: físico98/reservado8/disponible90;480=257+223 USD;2 facturas/5 pagos.
Sin ajustes/aplicaciones de demo, por lo que aún no hay comprobantes financieros locales.
Concurrencia MySQL de este módulo y aceptación en tablet física pendientes.
