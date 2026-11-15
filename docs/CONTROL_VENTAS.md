# Dashboard comercial e historial de ventas

## Pantallas

- **Vista general** (`/`), título único **Resumen de ventas**: indicadores comerciales de la sucursal activa, gráficos
  y cinco operaciones recientes. **Ver historial completo** abre la consulta paginada.
- **Historial de ventas** (`/app/historial-ventas`): filtros por fechas, cliente,
  referencia/factura, tipo y estado;20 operaciones/página y exportación del conjunto
  filtrado completo, con neutralización de fórmulas CSV.
- El historial tiene su propio resumen compacto y tabla; no vuelve a presentar el
  dashboard de gráficos. Un solo h1 por pantalla; sin cabecera genérica con fecha
  ni bloque de bienvenida/configuración repetidos bajo el dashboard comercial.
- Detalle privado: artículos/precios históricos, cobros/reversiones, recepciones,
  ajustes, créditos entrantes/salientes y reembolsos. Actividad cronológica y acceso
  al flujo existente mediante **Abrir operación**.

Requiere `sales.view` y acceso explícito a sucursal. Ninguna consulta modifica stock,
pagos, facturas o saldos. Los documentos PDF mantienen sus permisos independientes.

## Lectura financiera

- Mes actual por defecto; **Hoy** y **Este mes** usan la zona del negocio.
  Fechas locales inclusivas, límite366 fechas; límites UTC calculados después de
  resolver cada medianoche local, incluidos días de cambio de horario.
- Los filtros seleccionan pedidos por su creación, no por emisión de factura.
  Se muestran **saldos actuales**, no reconstrucción contable a una fecha pasada.
- Neto facturado y vigente sin facturar separados. Aplicado incluye crédito;
  no significa ingreso nuevo. Borradores/anulados siguen visibles, sin sumar dinero.
- Factura semanal contada una vez, con importes atribuidos a cada pedido una vez.
  Ajustes/crédito proceden de OrderSettlement; sin SUM de floats en SQLite.
- **Cobros del período** usa fecha de pago/reembolso y toda la sucursal, aunque
  los pedidos sean anteriores. No cambia con búsqueda/tipo/estado del historial.
  Confirmados excluye recibos ahora revertidos; reembolsos confirmados se restan
  una vez. Se muestra el estado actual de recibos, no un arqueo de caja histórico.
- Se mantienen monedas separadas. No convierte divisas ni suma USD y EUR.

## Rediseño y bibliotecas

Revisión de documentación oficial Context7 y npm:

| Biblioteca | Versión consultada | Evaluación |
|---|---|---|
| Chart.js |4.6.1| Canvas, buena integración Vue mediante vue-chartjs5.3.4; accesibilidad/fallback a cargo del integrador |
| Apache ECharts + Vue-ECharts |6.1.0 +8.3.2| Elegida: módulos ESM, Vue3/TypeScript, SVG, tooltips, zoom y resize |

Referencias: https://github.com/ecomfe/vue-echarts,
https://echarts.apache.org/ y https://www.chartjs.org/docs/latest/.
Instalación fijada por package-lock.json; sin CDN. Módulo de gráficos cargado bajo
demanda, separado de acceso, ventas y catálogo. Sólo se registran área/línea,
barras, dona, grid, tooltip, aria, zoom y SVG. El chunk de gráficos ronda609kB
minificado/210kB gzip; Vite informa su umbral500kB, aunque ya es una carga diferida.

- Área/barras intercambiables, tooltip con importe exacto, días sin operaciones
  completados con cero dentro del rango elegido y zoom para más de31 fechas.
- Dona de métodos de cobro con leyenda/importe real, reembolsos y neto registrados.
- Selector de moneda, autoresize al girar y movimiento reducido respetado.
- Tabla accesible de datos exactos; las geometrías reciben proporciones enteras
  normalizadas con BigInt. Nunca se usa float para calcular dinero o saldos.
- Historial: tabla alineada en escritorio, tarjetas en tablet/móvil, estados
  semánticos, importes jerarquizados, contexto de consulta y estados vacíos.
- Selector explícito Tabla/Tarjetas, conservado al girar. Nombres largos se parten
  por líneas, referencias extensas se ajustan y los importes permanecen completos,
  alineados y sin pasar por Number. Cuando hace falta, sólo la tabla desplaza
  horizontalmente; la página mantiene su ancho. Encabezado accesible contenido
  correctamente también en WebKit y acciones táctiles de44px o más.
- Detalle: identidad del cliente, resumen monetario y línea temporal trazable.

Textos del módulo centralizados en `resources/js/locales/es/salesControl.ts`;
validación/cabeceras CSV en `lang/es/sales_control.php` y su alternativa inglesa.
Los componentes no contienen frases de interfaz incrustadas. Clientes, sucursal,
períodos, importes y series vienen del contexto/API; no se insertan cifras de demo.

## API privada

Tras `/api/v1/business/stores/{store}`:

| Método | Ruta | Resultado |
|---|---|---|
|GET|`/sales/control`| data paginada, summary por moneda, cash, daily, filters y meta |
|GET|`/sales/control/export`| CSV de todos los pedidos filtrados;10 exportaciones/minuto/usuario |
|GET|`/sales/orders/{order}/activity`| pedido y actividad original, sin escrituras |

Parámetros: `from`, `to` (Y-m-d), `q` (hasta120), `kind=all/sale/reservation`,
`state=all/active/invoiced/unbilled/reserved/confirmed/fulfilled/cancelled/draft`,
`page`. Respuestas privadas/no-store. Sesión activa, permisos y StoreContext.
422 para filtros inválidos;401/403/404 para sesión/permiso/contexto ajeno.

Pruebas PHP cubren sumas/saldos, crédito/ajustes/reembolsos, factura semanal única,
cobros de pedidos anteriores, reversión, monedas, DST de25 horas, CSV/paginación,
datos históricos, permisos y ausencia de escrituras. Browser usa build real con
API interceptada en seis perfiles; no equivale a aceptación en hardware físico.
Resultado final:214 pruebas PHP/2.741 aserciones,598/598 casos browser, Pint y build
aprobados. Sesenta escenarios browser del módulo: títulos únicos/pantallas separadas,
filtros/giro, historial/CSV, recuperación, permisos, SVG área/barras, cambio de moneda,
tabla con datos extensos/importes extremos y selector de vista. Se revisaron capturas
de escritorio, iPad y móvil; emulación WebKit/Chromium, no tablet física.

## Demo conciliada

`scripts/verify-demo.php` compara el resumen mensual de cada sucursal con las
posiciones de sus pedidos. Principal:15 registros (9 borradores y1 anulado),
2 facturas únicas;177 facturados netos +303 sin facturar =480 USD;
257 aplicados +223 pendientes =480; cobros confirmados257. Segunda sucursal sin
operaciones. Inventario98 físico/8 reservado/90 disponible. Consulta sólo lectura.

Pendientes de entrega: carga/perfilado y concurrencia MySQL específicos, reportes
adicionales/configuración operativa y aceptación en tablet física. No se requiere
otra migración ni se crean operaciones comerciales para mostrar el dashboard.
