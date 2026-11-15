# Experiencia de usuario y pantallas — tablet-first

## Identidad visual
Usar diseño SaaS profesional, espacioso y táctil. Las imágenes de `assets/REFERENCIA_UI_DASHBOARD.png` y `assets/REFERENCIA_UI_APARTADOS.png` son **inspiración visual**, no sustituyen el contrato funcional ni imponen colores/estructura exactos. No emplear imágenes como componentes funcionales.

## Sistema de diseño
- Tipografía legible, alto contraste, espacios generosos, componentes consistentes; objetivos táctiles >=44x44 px.
- Sidebar colapsable en tablet; navegación principal visible; acceso a búsqueda universal.
- En iPad horizontal la pantalla puede dividirse en zonas; en vertical y móvil deben apilarse sin cortar acciones.
- Tablas de existencias con cabeceras fijas, paginación y filtros; nunca depender solo de swipe horizontal para una acción crítica.
- Para importes usar separador de miles y moneda de negocio; fechas desde zona horaria del negocio; diálogos de confirmación para acciones irreversibles.
- Estados visuales claros: borrador, reservado, parcialmente entregado, cerrado, facturado, cobrado, cancelado.
- Loading, skeleton, sin stock, errores, conexión perdida, conflicto 409 y permisos 403 deben tener vistas diseñadas.

## Mapa de navegación
`Dashboard` / `Venta rápida` / `Apartados` / `Cuentas semanales` / `Pedidos por enlace` / `Facturas` / `Clientes` / `Productos` / `Inventario` / `Vendedores` / `Nómina` / `Reportes` / `Configuración`.

## Vista 1: Panel general
KPI de ventas cobradas, apartados activos, facturas pendientes, existencias bajas, ventas de semana. Separar cobradas de facturadas; no mezclar dinero cobrado con reservas. Mostrar sucursal activa.

## Vista 2: Catálogo de productos
- Barra buscar por nombre/SKU/código de barras.
- Filtros de categoría, estado, bajo stock, marca.
- Tarjetas con foto principal (card.webp), nombre, precio, disponible, etiqueta de variante; indicación clara si servicio sin stock.
- Acciones: Nuevo producto, Editar, Ajustar inventario (permiso), Gestionar fotos; eliminar/desactivar sin destruir historial.
- Formulario producto: datos comunes + atributos opcionales + tabla de variantes. Imagen principal + 4 fotos adicionales, reordenar.

## Vista 3: **Nuevo apartado / venta rápida** (CRÍTICA)
Diseño 3 paneles en iPad horizontal:

**Izquierdo: cliente**
- Buscador por nombre/teléfono; lista de clientes recientes; botón `+ Nuevo cliente`.
- Mostrar cliente seleccionado, teléfono, cuenta semanal vigente y saldo sin mezclar saldos de otra sucursal.
- Permitir seleccionar vendedor responsable (según permiso) y sucursal.

**Centro: productos**
- Buscador, categorías chips, lista/cuadrícula táctil con miniaturas, precio y `Disponible` por variante.
- Tocar un producto abre selector talla/color/presentación, cantidad y precio autorizado; botón `Agregar`.
- Indicar stock real y errores de reserva; producto sin stock deshabilita reserva, no oculta.

**Derecha: resumen**
- Encabezado `Apartado de [Cliente]` con estado y periodo semanal.
- Líneas: foto, nombre, variante, qty +/- y monto. Totales: subtotal, descuento, impuesto, envío, abono, pendiente.
- Botones en jerarquía: `Guardar borrador`, **`Reservar productos`** y `Facturar ahora` (solo si cumple reglas). Separar `Entregar` y `Cobrar abono` como acciones explícitas.
- En vertical el panel resumen puede ser drawer inferior o pestaña persistente con importe; siempre accesible.

## Vista 4: Cuenta semanal
Ficha cliente, fecha de inicio/fin, ventas o pedidos añadidos, líneas, estado de reserva/entrega, abonos, total pendiente; `Revisar cierre` muestra resumen antes de emitir factura. Día de cierre configurable. Si cierre vuelve a intentarse, muestra factura anterior.

## Vista 5: Carrito mediante enlace
Interfaz pública con marca de la tienda, catálogo y variantes, carrito, total y botón `Confirmar pedido`. No mostrar precios de costo, otras cuentas, nombres de clientes, ni inventario físico por almacén. La confirmación muestra número de pedido y evita duplicación por doble toque. Enlace utilizado muestra estado adecuado sin reiniciar carrito.

## Vista 6: Vendedores y remuneraciones
Lista vendedores: modalidad (sueldo/comisión), plan vigente, ventas atribuidas, comisiones devengadas, pagadas y pendientes. Editar contrato desde una fecha futura sin recalcular historiales. Pantalla de nómina: periodo, base calculada, ajustes, aprobar, registrar desembolso, historial.

## Vista 7: Imágenes
Hasta cinco fotos con indicadores `1/5`, `2/5`... Barra de consumo `1.3 GiB de 2 GiB`, miniaturas, estado processing y errores. No confundir contar 5 fotos con contar 5 * 3 rendiciones; son 5 imágenes lógicas.

## Rutas sugeridas
`/app/{tenantSlug}/dashboard`, `/products`, `/products/{id}`, `/sales/new`, `/apartados`, `/accounts/{id}`, `/customers`, `/invoices`, `/vendors`, `/payroll`, `/settings`;
`/s/{token}` para enlace público no autenticado. Evaluar `tenantSlug` como localizador amigable, no como autorización.

## Escenarios manuales de UX
1. Seleccionar clienta, añadir camiseta negra talla M, apartar y ver `Disponible -1`.
2. Agregar perfume sin talla, cambiar precio solo con permiso, abonar 30, mostrar saldo.
3. Cambiar a iPad vertical; todas las acciones críticas deben seguir accesibles.
4. Señal lenta: spinner, retry seguro, no crear pedido doble.
5. Cliente abre link por WhatsApp preview, luego lo abre verdaderamente: sigue disponible hasta confirmar.
6. Catálogo con 500 productos: búsqueda rápida y scroll fluido sin descargar todas las fotos grandes.

## Accesibilidad
Labels, controles con focus, navegación teclado opcional, errores asociados al campo, confirmaciones descriptivas, estados no expresados solo con color.
