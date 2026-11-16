# Enlaces temporales de consulta de pedidos

## Operación

En **Apartados**, seleccionar un pedido guardado y pulsar **Compartir enlace**.
Elegir 1 hora, 24 horas, 3 días o 7 días; generar y copiar el enlace para enviarlo
manualmente al cliente. La generación se bloquea mientras hay artículos sin guardar.
Admite hasta cinco enlaces activos por pedido. El historial muestra hasta 20,
priorizando los activos para que siempre puedan revocarse;
**Revocar** impide nuevas consultas inmediatamente, sin borrar el registro.

La ruta pública `/s/order/{token}` muestra sucursal, número, artículos y variantes
históricos, cantidades, precios, envío, impuestos, descuento, total, abonado y
pendiente actual. Puede abrirse sin iniciar sesión. No muestra contacto, notas,
costos, identificadores de variantes ni otros pedidos del cliente, ni expone PDFs.
Abrirlo no modifica reservas, pagos, pedidos ni la vigencia del enlace.

Esta función cubre la consulta de un pedido ya guardado. El catálogo/carrito público
y confirmación de nuevos pedidos se incorporaron en `PEDIDOS_POR_ENLACE.md`; la
edición pública de un pedido ya guardado sigue pendiente. La
tabla `share_links` original conserva su propósito de creación de pedidos; esta
consulta utiliza `order_view_links`, con FK compuesta pedido/sucursal.

## API privada

Prefijo `/api/v1/business/stores/{store}/baskets/{order}`:

| Método | Ruta | Permiso |
|---|---|---|
| GET | `/view-links` | sales.view; metadatos, sin tokens ni URL |
| POST | `/view-links` | sales.create; request_key UUID y hours=1/24/72/168 |
| POST | `/view-links/{link}/revoke` | sales.create; revocación repetible sin duplicar auditoría |

Sesión/CSRF y acceso explícito a sucursal obligatorios. Sólo pedidos reserved,
confirmed o fulfilled de sucursal activa pueden compartirse. Creación 201, replay
200, conflicto de UUID/payload o estado/límite 409 y validación 422.

El POST de creación devuelve `data.link`, `data.path` y `data.replay`. La URL se
reconstruye en el origen actual; el listado no recupera secretos. Un resultado
perdido se reintenta con el mismo UUID y duración, sin generar otro enlace ni
extender su plazo. Un replay de enlace revocado/caducado no devuelve ruta activa.
La interfaz conserva ese reintento en memoria mientras permanece montada; al
recargar se puede consultar/revocar el historial y generar un enlace nuevo.

## Protección y persistencia

Token opaco HMAC-SHA256, separado por dominio y vinculado a usuario/sucursal/pedido
y UUID aleatorio. Usa APP_KEY para reproducir el secreto en reintentos sin guardarlo.
Sólo persiste su SHA256. El journal idempotente guarda el ID del enlace; auditoría
guarda ID/plazo, sin token ni URL. Mantener APP_KEY estable como en las restantes
funciones cifradas; una rotación no permite reconstruir las URL anteriores.

La consulta comprueba vigencia, revocación, sucursal y estado del pedido en cada
GET. Inexistente/caducado/revocado/cancelado/inactivo devuelve la misma página 404;
el token no es consumido ni renovado. Lee bajo locks compartidos pedido→enlace,
compatibles con el orden de revocación, y calcula dinero con strings/BCMath.

Límite público 30 consultas/minuto/IP y privado de generación 10/minuto/usuario.
HTML escapado, sin JavaScript ni recursos externos, `no-store`, `no-referrer`,
`noindex` y CSP restrictiva; 429 conserva las cabeceras de protección. En despliegue
usar HTTPS y redactar `/s/order/{token}` en access logs/telemetría del servidor.
La URL local de demo sólo es accesible desde su entorno local.

## Verificación y entrega

- PHP completo: 144 pruebas / 1.470 aserciones, SQLite :memory: protegido.
- Navegadores: 340/340 en seis perfiles, build real y API interceptada.
  Tras el último pulido se repitieron los 12 casos específicos de enlaces: 12/12.
- Casos de API: GET invitado sin consumo/escrituras financieras, privacidad/XSS,
  variantes, cantidad10, importes, hashes, replay/UUID, permisos/sucursal, revocación
  repetida, caducidad, cancelación, sucursal inactiva, validación/límite y rate limit.
- Casos UI: generar/revocar con giro, reintentar respuesta perdida con igual UUID,
  selección de duración y bloqueo ante artículos sin guardar.
- HTML real Blade renderizado con datos sintéticos de QA: 1440/1024/768/390/320 px
  sin overflow; captura `E:/KEYLASHOP/.playwright-mcp/shared-order-qa-390.png`.
- Migración aditiva `2026_11_14_002000_create_order_view_links` aplicada sólo en
  SQLite local, tras respaldo verificado `.runtime/bshop-backup-20261114-040415.sqlite`.
  Despliegue remoto pendiente.
- En MySQL 8.4 aislado se reprodujo y corrigió una carrera del límite de cinco
  enlaces: dos creaciones podían leer una instantánea anterior mientras esperaban
  el bloqueo del pedido. La lectura con bloqueo revalida los enlaces confirmados.
  Con cuatro enlaces previos y dos trabajadores bloqueados simultáneamente, uno
  creó el quinto y el otro recibió 409; quedaron cinco activos. Evidencia privada:
  `.runtime/reservation-qa-E59B65D732A3`. Esto no cubre todas las carreras de
  enlaces ni sustituye la aceptación operativa.
