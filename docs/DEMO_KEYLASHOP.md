# Demostración coherente de KeylaShop — USD

URL: http://127.0.0.1:8017 · usuario `demo@bshop.test`, contraseña ya entregada.
Sucursal principal / Almacén principal. La vista recuerda la sucursal de esta pestaña
y rechaza preferencias que hayan perdido autorización. Los datos son **ficticios** y
la cuenta demo muestra una banda identificadora.

## Cómo se preparó

Con respaldo íntegro previo, usando formularios reales de la aplicación: organización
de categorías/atributos, alta de ocho productos y 19 variantes, carga de ocho fotos,
19 entradas justificadas, cuatro altas de cliente, ventas y apartados. No se cargaron
stocks, pagos o facturas por un seeder que evitara servicios/permisos/validaciones.

Los recortes de fotos se obtuvieron de la referencia generada aportada por el usuario;
se excluyeron los corazones del mockup y se procesaron por la carga privada normal.
No son fotografías definitivas del inventario de la clienta. Descripciones/notas
marcan los ejemplos; teléfonos usan el rango ficticio 202-555-01xx y correos
`example.test`. No se enviaron mensajes a esos contactos.

## Colección inicial

| Producto | Variantes | Precio USD | Unidades recibidas |
|---|---|---:|---:|
| Camiseta Básica | S/M/L, negro | 25.00 | 14 |
| Jeans Mom Fit | 28/30/32, azul | 45.00 | 22 |
| Vestido Lazo | S/M/L, rosa | 55.00 | 3 |
| Tenis Casual | 36/37/38/39, blanco | 65.00 | 10 |
| Bolso Clásico | Café | 38.00 | 8 |
| Gafas de Sol | Negro/café | 22.00 | 15 |
| Aretes Dorados | Dorado | 15.00 | 24 |
| Perfume Floral | 50/100 ml | 29.00 / 49.00 | 7 |

Total inicial: **103 unidades**, con entradas auditadas. Los ejemplos QA anteriores
se conservan archivados y no aparecen como mercancía activa ni se borró su historial.

## Operaciones verificadas

| Pedido | Cliente ficticio | Caso | Total | Abonado | Pendiente |
|---:|---|---|---:|---:|---:|
| 1 | María Demo | Venta camiseta + jeans + gafas, envío 5; FAC-1-000001 | 97.00 | 97.00 | 0.00 |
| 2 | María Demo | Apartado anterior + perfume 100 ml, envío 5; retiro 20/11 | 146.00 | 50.00 | 96.00 |
| 3 | Carlos Demo | Tenis talla38 + aretes, retiro en tienda; FAC-1-000002 | 80.00 | 80.00 | 0.00 |
| 4 | Ana Demo | Selección inicial; retiró perfume50 y canceló sin abonos | 55.00 histórico | 0.00 | 0.00 |
| 5 | Ana Demo | Selección nueva: vestido M + perfume100, retiro 20/11 | 104.00 | 0.00 | 104.00 |
| 6 | Luis Demo | Bolso + aretes; abonos 20 y 10, retiro 20/11 | 53.00 | 30.00 | 23.00 |

El pedido4 empezó en 84.00; su línea retirada y los movimientos de liberación
quedaron guardados. No se lo incluye como deuda/cobro del negocio.

En el nuevo tablero el pedido interno2 tiene número visible **12**, cambiado desde
«Cambiar #» sin alterar referencias. Se editó/restauró una cantidad y se añadió/retiró
un artículo con depósito50: volvió a total146/pendiente96. El teléfono ficticio se
formateó +1 (202) 555-0101 mediante Editar cliente; el PDF emitido del pedido1 se conserva.

Conciliación final de ejemplos activos: **US$480.00 = US$257.00 recibidos +
US$223.00 pendientes**. Dos facturas, cinco pagos. Stock físico **98**, reservado
**8**, disponible **90** en 19 saldos. Crédito no asignado aún a factura corresponde
a los depósitos de las canastas abiertas; no se reutiliza automáticamente en otra.

## Errores recorridos como cliente

- Al retirar una línea de una canasta recién creada se detectó que faltaba la versión
  inicial en su DTO. Se corrigió `SalesOrder` y se añadió aserción explícita de versión0.
- Retiro de perfume50 y cancelación de canasta4 liberaron sus reservas sin borrar datos.
- Abono1000 al pedido2 devolvió **422**, preservó el valor escrito y mantuvo pendiente96.
- En el abono10 del pedido6 se dejó confirmar al servidor y se interrumpió únicamente
  su respuesta HTTP. Recargar recuperó el mismo pago: abonado30, pendiente23, sin un
  segundo POST ni pago duplicado. El journal de recuperación quedó limpio.
- Descarga real de FAC-1-000001.pdf correcta. No se afirmó envío de WhatsApp.
- La reconciliación `PHP83 scripts/verify-demo.php` verificó saldos, reservas, kardex,
  importes, crédito y hashes de PDFs sin modificar datos.

Los borradores usados para capturas no se confirmaron como pedidos adicionales.
Las operaciones se fecharon con la sesión real; no se copiaron fechas antiguas del
mockup ni se fabricaron compras pasadas. No se modificaron bases remotas.
