# Gestión de clientes

Las fichas son compartidas por el negocio y sus sucursales. En **Clientes** se
puede buscar, crear, consultar contacto/notas, editar, archivar y reactivar. Los
saldos y pedidos del resumen corresponden exclusivamente a la sucursal autorizada.

## Archivo y conservación

Archivar cambia `status` a `archived`, sin borrar la ficha ni usar `deleted_at`.
Requiere confirmación e informa que afecta la selección de nuevas ventas en todas
las sucursales. Los filtros Activos/Archivados/Todos permiten localizarla después.
Reactivar cambia el estado a `active` y vuelve a habilitar nuevas ventas.

El archivo no cancela pedidos ni libera reservas. Conserva pagos, deuda, créditos,
facturas e inventario. CxC y el resumen siguen mostrando los saldos pendientes;
los pedidos existentes pueden recibir abonos. Ventas y creación de apartados
rechazan clientes archivados al confirmar en el servidor.

Editar contacto/notas utiliza el flujo existente con versión e idempotencia.
No reescribe snapshots de pedidos ni documentos emitidos. La ficha archivada
también puede actualizarse, conservando su estado hasta una reactivación explícita.

Cuando una ficha cambia desde esta pantalla, las selecciones de nuevas ventas
que estén conservadas en memoria reciben el cambio. Un cliente archivado bloquea
la confirmación de una nueva venta sin descartar el carrito. Reactivarlo vuelve
a habilitarla. Los pedidos históricos conservan su presentación. Esta actualización
es de la página actual; otros navegadores se protegen mediante la validación del
servidor y el control de versiones, no por un evento local.

## API

Después de `/api/v1/business/stores/{store}`:

| Método | Ruta | Permiso / comportamiento |
|---|---|---|
| GET | `/customers?status=active` | customers.view; active por defecto, archived/all opcionales |
| GET | `/customers/{customer}` | customers.view; ficha actual activa o archivada |
| PATCH | `/customers/{customer}` | customers.manage; edición de contacto/notas, flujo existente |
| PATCH | `/customers/{customer}/status` | customers.manage; archivo o reactivación |
| GET | `/customers/requests/{uuid}?action=status` | customers.view; recuperación propia del cambio de estado |

Todas exigen sesión activa y acceso a `{store}`. El permiso se revalida dentro
de la transacción para escribir. Listado paginado de 15, búsqueda literal por
nombre/teléfono con escape de comodines. No existe eliminación física en esta API.

El cambio de estado recibe:

```json
{"request_key":"<uuid>","version":0,"status":"archived"}
```

Solo admite active/archived. Bloquea la ficha, exige versión actual e incrementa
una vez al cambiar. Mismo UUID/payload devuelve el resultado original sin repetir
auditoría. Una petición nueva con el estado ya actual no incrementa la versión.
Una versión obsoleta o UUID reutilizado con otros datos devuelve 409. Auditoría:
`customer.archived` / `customer.reactivated`, usuario y estados/versiones.

La respuesta de repetición conserva el resultado original; la consulta de recuperación
devuelve la ficha actual. Solo el mismo usuario y sucursal de la operación pueden
consultar esa recuperación. Se normalizan UUID mayúsculos/minúsculos en la consulta.

## Interfaz, reintento y dispositivos

Con permisos de venta, la ficha ofrece **Enlace para nuevo pedido**: se eligen
almacén, variantes autorizadas, envío y duración para el cliente existente. El
destinatario prepara un carrito y confirma un apartado sin acceder a la gestión.
Generación/revocación e integridad se describen en `PEDIDOS_POR_ENLACE.md`.

Si falla el listado, **Reintentar búsqueda de clientes** conserva búsqueda, estado
y página solicitada, incluso si falló el avance a una página distinta. La ficha
seleccionada permanece visible. Durante una consulta y desde el inicio de la
espera de búsqueda (200 ms), no se pueden seleccionar resultados anteriores ni
avanzar páginas. Las respuestas obsoletas se ignoran; una consulta fallida no se
presenta como una búsqueda vacía. Reintentar sólo consulta, sin modificar fichas.

La vista separa un directorio lateral compacto de la ficha principal. La cabecera
presenta iniciales, nombre, estado y edición destacada; contacto con iconos,
notas y apartado de archivo discreto. El resumen comercial y los apartados abiertos
se integran en la ficha, sin duplicarse bajo el listado. Los importes y contadores
provienen del resumen existente de la sucursal; no se inventan indicadores.
Los roles sin sales.view no solicitan ni muestran ese resumen.

La distribución se adapta al ancho disponible del panel: tarjetas de indicadores,
contacto en columnas cuando caben y apilado en pantallas estrechas. Los importes
largos reciben ancho adicional y el texto de contacto permite salto de línea.

Editar y confirmar archivo/reactivación usan AppDialog, campos legibles y acciones
de 48 px fuera del cuerpo desplazable. El giro no destruye el borrador. Los lectores
sin `customers.manage` consultan fichas, sin controles de mutación.

Los cambios pendientes se conservan por pestaña/usuario/sucursal en sessionStorage,
sin credenciales. Si se pierde la respuesta, se bloquea la edición de ese payload
y se reintenta el mismo UUID. Al volver al módulo tras recargar, se consulta primero
el resultado; nunca se reenvía automáticamente. Cerrar el diálogo no borra una
operación pendiente. Su estado puede revisarse desde la ficha.

Los conflictos conservan lo escrito y ofrecen **Descartar cambios y actualizar**;
no se sustituye la versión silenciosamente para sobrescribir a otro usuario.
Una búsqueda/filtro o respuesta antigua no debe cambiar la ficha elegida por una
consulta de actualización anterior. El listado vuelve a una página válida si se
retira el último cliente visible de una página.

## Alcance y evidencia

- Cinco pruebas de backend nuevas, 78 aserciones: idempotencia/versiones, permisos,
  identidad de recuperación, filtros/paginación, deuda/documentos/inventario
  conservados, abono de pedido existente y rechazo de nuevas operaciones archivadas.
- Treinta casos nuevos de navegador en seis perfiles: edición/giro, archivo/
  reactivación, conflicto, respuesta perdida/recarga, lector y venta en memoria.
- Demo real: cuatro clientes activos, cinco campos de edición y confirmación de
  archivo revisados a 1024×768. No se guardaron cambios ni se archivó un cliente.

Esta fase completa gestión interna de fichas. Los enlaces públicos temporales,
la edición pública de pedidos y su seguridad son una fase pendiente separada.
Concurrencia real MySQL y aceptación en tablet física siguen pendientes.
