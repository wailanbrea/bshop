# Cuentas por cobrar

Ruta de pantalla: `/app/cxc`. Menú: **Cuentas por cobrar**.

La pantalla lista saldos positivos de pedidos y apartados de la sucursal activa.
Los pedidos facturados aparecen una sola vez con su número de factura. Se excluyen
pedidos cancelados, facturas anuladas/reembolsadas y documentos totalmente pagados.
Solo se descuentan pagos confirmados. Los importes se suman con BCMath y los
resúmenes se separan por moneda; corresponden a todos los resultados filtrados,
no solamente a la página visible.

Filtros: cliente, teléfono o documento; factura/apartado sin facturar;
con/sin abonos; antigüedad mínima de 30, 60 o 90 días. Antigüedad desde la emisión
de factura o creación del apartado, según la zona horaria del negocio. No se
presenta como mora porque no existe una fecha contractual de vencimiento.

**Ver pedido y abonar** abre el detalle existente, donde **Registrar depósito**
usa el flujo de cobro con permisos, versión del pedido, idempotencia y auditoría.
Los usuarios sin `payments.record` solo ven **Ver pedido**. Volver a CxC o
**Actualizar saldos** consulta de nuevo los importes del servidor.

API, bajo sesión y sucursal autorizada:

- `GET /api/v1/business/stores/{store}/receivables`
- `GET /api/v1/business/stores/{store}/receivables/export`

Ambas rutas requieren `sales.view` y `customers.view`. El CSV incluye todos los
resultados filtrados y protege las celdas de texto que podrían interpretarse como
fórmulas. El módulo no modifica el esquema ni crea saldos paralelos.

## Validación vigente

Compilación TypeScript/Vite aprobada. Suite completa: 112 pruebas PHP y 1,001
aserciones; 262 pruebas de navegador en seis perfiles de móvil, tablet y escritorio.
CxC verifica exclusión de cancelados/liquidados, ausencia de duplicados al facturar,
actualización tras abono, precisión decimal, filtros, CSV, sucursales y permisos.

Las descargas PDF y las imágenes locales se transmiten mediante el almacenamiento
de Laravel. Se verificó que el PDF descargado coincide con los bytes históricos y
que un documento alterado se rechaza. El cliente descarga el PDF con la sesión
actual, valida tipo/origen y muestra los fallos dentro de la aplicación; reintentar
una descarga no emite otra venta. La recuperación de una venta ya confirmada se
prueba esperando la confirmación simulada antes de recargar.

Las pruebas de navegador usan los archivos compilados y API simulada. Se ejecutan
sin HTTP local con `BSHOP_BROWSER_OFFLINE=1`; las pruebas PHP usan SQLite en memoria.
Esto no sustituye la aceptación sobre la demo autenticada o una tablet física.

Servidor real confirmado en Chrome: `http://127.0.0.1:8027/app/apartados`, con
pantalla de inicio de sesión visible y una miniatura pública del catálogo cargada.
Recorrido autenticado pendiente de que el propietario inicie sesión.

## Arranque y pruebas

```powershell
.\scripts\dev.ps1 -Port 8027 -Open
```

El script confirma HTTP 200 antes de abrir Chrome. El proceso queda en segundo
plano. El puerto anterior 8017 presentó una instancia sin respuesta; el puerto
8027 fue iniciado por el propietario y confirmado en el navegador.

```powershell
npm run build
$env:BSHOP_BROWSER_OFFLINE='1'
npm run test:tablet
```