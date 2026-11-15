# Smoke inicial de navegador

**Histórico:** los flujos multiempresa descritos abajo fueron sustituidos por
negocio único. Evidencia actual al final de este archivo y en PROGRESO.

Fecha: 2026-11-12. Servidor http://127.0.0.1:8017; Chromium de Playwright;
base local SQLite `database/bshop-local.sqlite`; datos sintéticos.

## Comprobado

1. Login demo vía formulario con CSRF real → workspace KeylaShop.
2. Crear `Sucursal de prueba` / código `QA-NORTE` → aparece en selector/listado.
3. Seleccionarla y crear `Almacén de prueba` como principal.
4. Editarlo a `Almacén de prueba editado` → se conserva al recargar/listar.
5. Alta de empresa sintética `Empresa QA` desde el asistente → nueva membership.
6. Cambiar de empresa: Empresa QA sólo lista su sucursal principal; KeylaShop
   lista sus dos sucursales. Guardar datos de KeylaShop → PATCH 200.
7. Viewports 1024×768, 768×1024 y 390×844 → sin desbordamiento horizontal.
8. Abrir menú móvil y navegar a almacenes → funciona, sin alertas de error.
9. Build final: editar identificador sugerido manualmente y perder foco lo conserva;
   diálogo enfoca nombre al abrir, Escape lo cierra. Revalidación responsive sin overflow.

Primeros scripts de navegador necesitaron corregir selectores (option oculto y
texto repetido en etiqueta accesible); las escrituras respectivas sí se habían
completado. Se corrigió además la sugerencia de slug al escribir nombre para
evitar interferencia en el campo al perder foco.

Captura inicial de escritorio: `E:\KEYLASHOP\.playwright-mcp\bshop-dashboard-desktop.png`.
Captura móvil: `E:\KEYLASHOP\.playwright-mcp\bshop-dashboard-mobile.png`.
Las capturas no sustituyen QA funcional en Safari/iPad.

## Pendiente

Safari/iPad real, PWA instalada, flujos financieros/inventario/R2 y concurrencia.
Los tests HTTP negativos de empresa/sucursal/rol están en `tests/Feature/TenancyTest.php`.
No declarar QA-001/002 completos para módulos todavía inexistentes.

## Continuación F1 — equipo e invitaciones

2026-11-12, misma vista previa local SQLite, Chromium:

- Propietario entra a Equipo y genera invitación a `equipo.qa@example.test`.
- En contexto de navegador aislado, la persona crea su cuenta y acepta.
- Sólo ve `Sucursal principal`; endpoint de administración de equipo responde 403.
- Propietario suspende a `Vendedora QA`; siguiente petición de workspace del
  invitado responde 404. Cuenta global permanece activa (comprobación backend).
- Intento de degradar al último propietario muestra el error y no cambia el acceso.
- Nueva invitación de prueba revocada desde UI; desaparece enlace privado.
- Equipo sin overflow en 1024×768, 768×1024 y 390×844.
- Captura: `E:\KEYLASHOP\.playwright-mcp\bshop-team-mobile.png`.

Los selectores del primer script se ajustaron a combobox/checkbox por rol;
los flujos finales anteriores sí se completaron. El último error 422 del navegador
corresponde al rechazo esperado al intentar retirar el último propietario.

## Arquitectura actual — un negocio y sus sucursales

2026-11-12, después de la conversión conservadora:
- KeylaShop se muestra como negocio fijo; cero selectores de empresas y cero
  botones para crear otra empresa.
- Se conservan las dos sucursales y usuarios anteriores; credenciales de demo válidas.
- Guardar configuración de negocio responde 200 en PATCH `/api/v1/business`.
- Invitación nueva aceptada en navegador aislado: un usuario del negocio, una
  sucursal autorizada y 403 al intentar administrar el equipo.
- Sin overflow en 1024×768, 768×1024 y 390×844.
- Captura actual: `E:\KEYLASHOP\.playwright-mcp\bshop-single-business.png`.
