# Custom Admin Dashboard (WordPress)

Plugin para crear un dashboard operativo custom en `wp-admin` para:

- Rol custom `admin` (u otros roles configurables).
- Rol `administrator`.
- `superadmin` (multisite), con acceso garantizado.

## Funcionalidades incluidas

1. **Dashboard operativo personalizado**
   - Resumen de usuarios, cursos y reservas.
   - Accesos rapidos a vistas de gestion.

2. **Gestion de usuarios simplificada**
   - Listado, busqueda y filtro por rol.
   - Edicion de datos base del usuario.
   - Edicion de metadatos desde un formulario simple.
   - Proteccion de metadatos sensibles de permisos/sesion para evitar escalados accidentales.
   - Envio de email de reset de password.

3. **Gestion de cursos y reservas (integrable)**
   - Vistas por `post_type` configurables.
   - Compatible con plugins externos que usen custom post types.
   - Filtros por estado, tipo y busqueda.

4. **UI personalizada para admins operativos**
   - Ocultacion de menus nativos de wp-admin.
   - Limpieza de admin bar.
   - Redireccion opcional al dashboard custom.

## Instalacion

1. Copia esta carpeta como plugin en:
   `wp-content/plugins/custom-admin-dashboard`
2. Activa el plugin desde WordPress.
3. Entra en **Panel operativo > Ajustes** y configura:
   - Roles permitidos.
   - Post types de cursos.
   - Post types de reservas.
   - Redireccion y ocultacion UI.

## Hooks para extender

- `cad_render_dashboard_widgets`
- `cad_render_courses_panel_after_table`
- `cad_render_bookings_panel_after_table`
- `cad_allowed_menu_slugs`
- `cad_admin_bar_keep_nodes`
- `cad_user_is_allowed`

## Estructura

- `custom-admin-dashboard.php` - bootstrap del plugin.
- `includes/class-cad-access-control.php` - acceso, permisos y roles.
- `includes/class-cad-user-manager.php` - vista/formulario de usuarios.
- `includes/class-cad-admin-panel.php` - dashboard, cursos, reservas, ajustes.
- `assets/admin.css` - estilos del panel.
