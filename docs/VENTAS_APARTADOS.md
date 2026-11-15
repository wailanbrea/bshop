# Ventas y apartados — KeylaShop

**Corrección vigente:** la pantalla de apartados de esta iteración fue sustituida
por el tablero único `TABLERO_APARTADOS.md`, grid arriba y detalle debajo en
`/app/apartados`. WhatsApp automático ahora está implementado como integración
opcional configurable; la demo lo mantiene desactivado. Las tres zonas históricas
no son otra pantalla/módulo de canastas.

## Abrir y operar

- **Ventas**: `/app/ventas`.
- **Nuevo apartado**: `/app/apartados/nuevo`, o botón «Nuevo apartado» en Ventas.
- **Canastas guardadas**: historial de la sucursal activa, con filtro abiertas/todas.
- **Clientes**: alta, búsqueda por nombre/teléfono, ficha y canastas autorizadas.
- **Facturas**: listado de documentos de la sucursal y descarga PDF privada.

La moneda confirmada del negocio es **USD**, presentada como **US$**. El servidor
calcula los importes con BCMath/DECIMAL, redondeo half-up a centavos y snapshots.
La interfaz conserva strings decimales y usa BigInt cuando necesita operar cantidades;
no calcula cobros mediante float. Los impuestos siguen `business_settings.sales_tax_rate`
(actualmente 0%); descuento/envío se introducen explícitamente, no se inventan tasas.

La interfaz sigue las dos imágenes aportadas: menú marino, acciones violeta,
catálogo y resumen en Venta; cliente/catálogo/resumen en Apartado. El logo independiente
no fue suministrado: la marca de texto/inicial es temporal. Las acciones sin soporte
real, como escáner o notificaciones, no se presentan como botones funcionales.

## Venta rápida

1. Seleccionar sucursal, almacén y cliente. El esquema actual requiere cliente.
2. Tocar producto y elegir variante cuando haya más de una; un producto de variante
   única se añade directamente. Se muestra disponibilidad de la variante/ubicación.
3. Ajustar cantidades. Abrir «Descuento, envío y pago» cuando corresponda.
4. Indicar sólo un pago realmente recibido; dejar 0 conserva una cuenta pendiente.
5. **Facturar y enviar** registra una sola venta, su salida física, factura y pago
   indicado. Prepara un PDF privado. **Compartir resumen** usa la función del dispositivo
   o copia el texto para envío manual; no afirma un envío automático de WhatsApp.

## Apartado y cierre individual

Agregar al borrador **no reserva**. «Guardar como apartado» revalida precios,
disponibilidad y cliente bajo transacción y sólo después muestra **Reserva activa**.
El precio/nombre/variante del documento se conservan aunque cambie el catálogo.

Se pueden abrir varias canastas del mismo cliente. El historial permite continuarlas
sin crear otra. Cada canasta se factura individualmente; no hay cierre forzado de
todos los clientes. Agrupación formal de cuentas semanales/ciclos sigue pendiente.

- **Registrar abono**: importe positivo, moneda de la operación, no mayor que el saldo.
  Crea pago, vínculo de pedido y movimiento de crédito; al facturar se asigna a la
  misma factura de forma idempotente.
- **Facturar ahora**: emite una sola factura. «Entregar los artículos ahora» es una
  elección explícita; sin marcarla, el stock sigue reservado.
- **Registrar entrega**: todo lo pendiente o una línea/cantidad parcial. Reduce físico
  y reservado en la misma cantidad, sin volver a descontar una salida ya realizada.
- **Retirar línea**: disponible en canastas no facturadas y sin abonos/entregas. Libera
  su reserva y conserva la línea histórica con `removed_at` y auditoría.
- **Cancelar canasta sin abonos**: libera reservas y conserva historial. No descarta
  dinero ni entregas previas. Devoluciones/reembolsos se implementarán en su módulo;
  mientras tanto esas transiciones incompatibles se bloquean con motivo explícito.

Una canasta entregada pero aún no facturada continúa entre las abiertas. El estado
cancelado mantiene importes históricos, pero su pendiente exigible se presenta en 0.

## Integridad y recuperación

UUID + hash del cuerpo/actor/sucursal para cada mutación. Doble pulsación, reintento
o respuesta perdida devuelven la misma operación. La clave no se reutiliza para otro
cuerpo. Versión de pedido/saldo obsoleta: 409, preservando los campos del usuario.

Las operaciones pendientes se conservan en **sessionStorage de la pestaña**, separadas
por usuario/sucursal. Una recarga consulta el resultado real del UUID. No se envían
mutaciones automáticamente ni se confirma stock offline. Una petición no confirmada
sólo se reenvía por acción explícita, con su UUID/cuerpo originales. No se almacenan
credenciales en ese registro. El cierre de pestaña no es una cola offline persistente.

Saldos, reservas, pedido, pagos y emisión se confirman atómicamente. El PDF se renderiza
**después** de confirmar la transacción. Si falla su generación, la factura sigue
emitida: reintentar el PDF usa el mismo documento y nunca repite venta/pago.
PDF v1 con SHA-256, acceso autenticado por sucursal, `private, no-store`, sin recursos
remotos ni ejecución PHP/JavaScript en Dompdf. Un archivo histórico alterado requiere
restauración; no se reemplaza silenciosamente.

Los locks coordinan producto → variante → sucursal/almacén → saldo/pedido, con lecturas
actuales después de esperar. Los cambios de stock/documentos incrementan versión de
producto y mantienen la protección de la reversión CSV.

## Permisos

`customers.view/manage`, `sales.view/create`, `invoices.view`, `payments.record`;
`sales.cancel` sólo en propietario/administrador por defecto. Acceso positivo por
`store_user` en todas las rutas de sucursal. El catálogo de venta entrega disponible,
no costos ni saldos físicos de otras ubicaciones. Los endpoints de recuperación
comprueban también el autor original de la petición.

## Estado de entrega

Probados localmente PHP, MySQL aislado, HTTP real y seis perfiles de navegador.
Ver `PROGRESO.md`, `DEMO_KEYLASHOP.md` y `TODO_UI_VENTAS_APARTADOS.md`.

Pendientes operativos: aceptación en dispositivos físicos; R2 real; SMTP/WhatsApp
oficial y workers persistentes cuando se configuren. Pendientes funcionales separados:
cuentas semanales agregadas, enlaces públicos, devoluciones/reembolsos, comisiones,
reportes y PWA completa. No se consideran resueltos por existir sus tablas.

Plazos opcionales de nuevas reservas: configuración por propietario, fecha fija
que no se extiende al editar y vencimiento solo sin pagos/factura/entregas. Sin
retroactividad sobre reservas históricas. Ver `EXPIRACION_RESERVAS.md` para la
operación y el programador del destino, aún pendiente de activación/verificación.
