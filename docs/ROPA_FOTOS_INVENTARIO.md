# Ropa, fotos y existencia inicial

## Operación

- En ventas, apartados y el catálogo por enlace, los productos cuyas variantes
  usan exactamente los atributos Color y Talla muestran siempre todas las tallas
  del producto como chips. Al elegir un color, las tallas disponibles en ese color
  quedan habilitadas y las tallas sin existencia (o no registradas para ese color)
  se muestran en gris con la etiqueta `No disponible`. En las tarjetas de **Ventas**,
  cuando un producto tiene una única variante disponible, la tarjeta muestra
  directamente `Color: ... · Talla: ...` y `Disponible (X)` en lugar de `1 variante`,
  y al abrir el selector esa combinación aparece preseleccionada.
- En **Catálogo** existen tres modos de creación de productos:
  1. **Uno por uno** (predeterminado al pulsar `+ Nuevo producto`): permite elegir la fotografía y completar debajo **Nombre, Precio de compra, Precio de venta, Cantidad, Color, Size y Descripción opcional**.
  2. **Hasta 10 fotos** (`+ Subir hasta 10 fotos`): permite seleccionar hasta 10 fotografías simultáneamente; debajo de cada foto se capturan **Nombre, Precio de compra, Precio de venta, Cantidad, Color, Size y Descripción opcional**, creando un producto independiente con su costo contable y existencia inicial por cada foto.
  3. **Modo avanzado**: conserva el formulario multipropósito para múltiples variantes por producto, SKU manuales, categorías y marcas.
- Las imágenes muestran la asociación de la variante exacta; si no existe, se
  busca otra del mismo color y con los mismos atributos salvo talla, y finalmente
  la principal. Así no se confunden, por ejemplo, materiales diferentes.
  Inventario utiliza el derivado `thumb`; ventas y catálogo comprador usan `card`.
- Inventario permite buscar por nombre de atributo o valor y muestra foto, SKU,
  atributos, físico, reservado y disponible, también en el formulario de ajuste.
  Si la imagen no está disponible se muestra `Sin foto`.
- En Nuevo producto se pueden elegir hasta cinco fotos del mismo artículo,
  previsualizarlas, cambiar la principal o retirarlas antes de guardar. Se siguen
  aplicando las validaciones y el procesamiento de imágenes existentes.
- Al activar **Esta prenda tiene colores y tallas**, se presentan colores y tallas
  habituales para seleccionar y campos para añadir otros desde el mismo formulario.
  La foto seleccionada permanece en una franja de referencia al desplazarse. Cada
  combinación recibe un SKU automático editable, precio y cantidad propios del
  producto; los valores de catálogo seleccionados se reutilizan o se crean de forma
  transaccional al guardar. No se crean por anticipado todas las opciones sugeridas.
- La sección opcional Existencia inicial requiere `inventory.manage`, sucursal y
  almacén autorizados. Las cantidades vacías o cero no producen movimientos. La
  creación y las entradas por variante se confirman atómicamente mediante el
  servicio de inventario; las claves de recuperación evitan duplicados.
- Las fotos se envían después de crear el producto. Si una falla, se reintenta
  con la misma clave sin crear otro producto. Tras recargar, la pestaña conserva
  un aviso para revisar la galería del producto guardado; los archivos del
  selector no sobreviven a la recarga y deben elegirse de nuevo si faltan.

## Checklist de aceptación

- [x] Color antes de talla, cambio de color, combinación inexistente y talla agotada.
- [x] Variante exacta en ventas, apartado guardado y catálogo autorizado por enlace.
- [x] Reserva, entrega y cancelación por variante; otros saldos conservados.
- [x] Competencia de dos procesos por la última unidad en MySQL aislado (201/409).
- [x] Fotos primero, principal, fallo de carga y reintento sin duplicar producto.
- [x] Miniatura, atributos y marcador sin imagen en lista y ajuste de inventario.
- [x] Existencia inicial por variante, movimientos y reintentos; permisos y rollback.
- [x] Build, pruebas PHP y navegador en perfiles tablet, escritorio y móvil emulados.
- [ ] Aceptación en los teléfonos y tablets físicos de la clienta.
- [ ] Verificación de carga contra el proveedor R2 real cuando esté configurado.
- [x] CI remota para el commit `559e688`, incluida la creación con stock en MySQL.
- [ ] Publicación en la instalación de la clienta.

## Evidencia local

`npm run build`: aprobado. Suite PHP: 287 pruebas / 3.786 aserciones en
`testing/sqlite/:memory:`. Navegadores: 820/820 en seis perfiles usando API
simulada. `scripts/verify-sales-concurrency.php`: dos usuarios/procesos por la
última unidad, 201/409, físico 1 y reservado 1. `scripts/verify-product-create-mysql.php`:
alta con existencia por variante, replay y rollback de lote inválido aprobados.
Ambos scripts verificaron MySQL 8.4 aislado en `127.0.0.1:33078`, base
`bshop_testing_single`; no utilizaron datos del VPS ni la demo de la clienta.
La prueba de concurrencia usa un producto sintético; la prueba de alta revierte
su transacción de QA. Estas suites se ejecutaron sobre el árbol de trabajo completo,
que también contenía cambios locales de fases anteriores; no son evidencia de CI
del commit separado de esta solicitud.

CI [43881840970](https://github.com/wailanbrea/bshop/actions/runs/43881840970),
intento 2: éxito en aplicación, MySQL y los seis perfiles de navegador. En el
primer intento falló una prueba táctil de invitación de equipo; esa prueba pasó
15 repeticiones locales y el perfil completo aprobó en el segundo intento. La
instalación pública y los dispositivos físicos no se verificaron con este commit.

### Mejora guiada posterior

El formulario guiado se verificó localmente con atributos inicialmente ausentes,
colores y tallas personalizados, reutilización de opciones, stock por combinación,
reintento y conservación de la foto en pantalla al desplazarse. La suite PHP aprobó
290 pruebas / 3.818 aserciones y la regresión de navegador 832/832 en seis perfiles;
después de ajustar el fixture de imágenes `blob:` se repitieron 56 pruebas de
catálogo y fotos. Dos procesos en MySQL 8.4 aislado crearon prendas simultáneas
reutilizando un único color y talla; evidencia privada
`.runtime/clothing-options-638B42CBFD5A`. La instalación pública no se actualizó:
el usuario técnico no tiene acceso a `C:\xampp\htdocs\keylashop` en el VPS.

CI del commit `b922154`: [43893213824](https://github.com/wailanbrea/bshop/actions/runs/43893213824)
completada con éxito, incluidos MySQL y los seis perfiles de navegador. La CI
verifica el código del repositorio; no acredita el despliegue en la página pública.
