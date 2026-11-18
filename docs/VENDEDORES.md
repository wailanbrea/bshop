# Vendedores y contratos: primera etapa

En **Vendedores**, un usuario con `members.manage` y acceso explícito a la sucursal
puede registrar vendedores para esa sucursal, editar su nombre/código/estado
(`activo`/`inactivo`), asignarlos a varias sucursales autorizadas y consultar sus
contratos. El alta y la edición usan código único, UUID de solicitud y auditoría;
una respuesta perdida se reintenta con la misma solicitud, sin duplicar registros.

El contrato registra sueldo fijo semanal, quincenal o mensual, o porcentaje de
comisión, con moneda del negocio y fechas de vigencia. Los períodos no pueden
solaparse. Los contratos posteriores deben iniciar en una fecha futura local; si
hay un contrato abierto, se requiere marcar explícitamente su finalización el día
anterior al nuevo inicio. La operación conserva la tasa o sueldo histórico y
registra auditoría. No hay edición ni eliminación de contratos históricos.

## Reglas confirmadas, todavía no implementadas

- Comisión atribuida por artículo, sobre productos netos después de descuentos,
  sin impuestos ni envío, devengada por cobros aplicados.
- Los abonos parciales se reparten proporcionalmente a los importes netos.
- Cada abono se distribuye proporcionalmente entre productos netos, impuestos y
  envío; solo la parte atribuida a productos genera comisión. La comisión se
  redondea a centavos, conservando la suma exacta al distribuir diferencias.
- Sueldo fijo proporcional a los días de contrato vigentes en el período.
- Para sueldo quincenal, los períodos serán del día 1 al 15 y del 16 al último
  día de cada mes, según confirmó el usuario.
- Una devolución después de pagar la comisión genera una compensación negativa
  para una liquidación futura, conservando la historia.

Esta etapa incluye el módulo de dominio para distribución proporcional exacta en centavos (`PaymentDistribution`) y prorrateo salarial por días vigentes (`SalaryProration`), el alta, edición, cambio de estado y asignación multisucursal de vendedores y sus contratos, la atribución de vendedor en **Ventas / Apartados** y la asignación o cambio posterior desde el detalle de **Apartados** (`POST /sales/orders/{order}/seller`, capturando instantánea del contrato y tasa de comisión activa en `order_items` e `invoice_items`), el **Resumen comercial del vendedor** en **Vendedores** con filtro por rango de fechas (`from`/`to`) (**Pedidos atribuidos**, **Ventas netas**, **Cobrado**, **Comisión devengada**, **Sueldo devengado**, **Total devengado**, **Pagado al vendedor** y **Saldo por pagar**), el registro idempotente de pagos y liquidaciones a vendedores (`POST /employees/{employee}/disbursements` en `payroll_disbursements`) y la exportación CSV del resumen de vendedores (`GET /employees/export`).

## API y verificación

Rutas privadas bajo `/api/v1/business/stores/{store}/employees`: GET y POST para
listar/crear, y GET/POST `/{employee}/plans` para listar/crear contratos. Todas
requieren sesión activa, acceso a sucursal y `members.manage`. Las escrituras
revalidan autorización en transacción. POST requiere `request_key` UUID; contratos
requieren fechas, modalidad y valores decimales enviados como texto. El cierre de
un contrato abierto requiere `end_open_plan=true` explícito.

Las pruebas locales cubren permisos, sucursal, replay, decimales, solapamiento,
reemplazo futuro y conservación de tasas; una carrera MySQL 8.4 aislada confirmó
que dos procesos no crean contratos superpuestos. Los artefactos sintéticos están
en `.runtime/reservation-qa-616E2B1A4881`. La ejecución remota en CI y la aceptación
física están pendientes.

Si falla la consulta de contratos, la vista no afirma que el vendedor no tenga
contratos y ofrece reintentar. La pantalla mantiene bloqueada la creación hasta
obtener la consulta; no se envía una operación pendiente automáticamente.
