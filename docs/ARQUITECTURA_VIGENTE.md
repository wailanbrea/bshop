# Arquitectura vigente — BShop entregable a un solo negocio

Decisión explícita del propietario, 2026-11-12: cada instalación de BShop se entrega
a un cliente y administra únicamente su negocio y sus sucursales. KeylaShop es
el negocio de esta instalación. Esta decisión sustituye el diseño multiempresa
de la documentación histórica.

- Un perfil del negocio por instalación, sin alta ni selector de empresas.
- Usuarios y roles de la instalación, con acceso explícito a sucursales.
- Sin tenants, memberships, suscripciones SaaS ni administrador de plataforma.
- Inventario por almacén/sucursal; ventas, pagos y permisos conservan su contexto
  de sucursal. La entrega a otro cliente usa una instalación y base separadas.
- Fotos/cuota, ajustes, secuencias y documentos pertenecen al único negocio.
- Moneda confirmada por el propietario el 2026-11-13: **USD (dólares estadounidenses)**,
  presentación **US$**, importes exactos DECIMAL/BCMath y snapshots por documento.
- No basta ocultar el selector: esquema, API, autorización y pruebas deben reflejar
  el modelo de negocio único.

Los documentos anteriores son antecedentes. Ante contradicción prevalece esta
decisión y su evidencia en PROGRESO. La conversión conserva respaldos y la base
anterior; no borra datos para cambiar de arquitectura.
