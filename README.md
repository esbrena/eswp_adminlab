# Custom Admin Dashboard (WordPress)

Plugin para dos funciones principales en `wp-admin`:

1. **CSS personalizado por rol**.
2. **Gestion avanzada de usuarios** (datos base, metadatos ACF y relaciones).

## Que hace

- Permite guardar CSS distinto para cada rol de WordPress.
- Aplica automaticamente ese CSS solo a usuarios que tienen ese rol.
- Si un usuario tiene varios roles, combina el CSS de todos esos roles.
- Nueva pantalla de gestion de usuarios con:
  - listado y edicion de usuarios,
  - edicion de datos base (email, nombre, nickname, bio, rol, password),
  - edicion de metadatos de usuario (incluyendo metadatos ACF),
  - paneles de relaciones detectadas: cursos, lecciones, examenes y reservas.
- Ajustes configurables para relaciones:
  - post types de cursos/lecciones/examenes/reservas,
  - meta keys para detectar la relacion del usuario con contenido.

## Configuracion

1. Activa el plugin.
2. Ve a **Ajustes > CSS por rol** para:
   - configurar estilos por rol,
   - configurar post types y meta keys de relaciones de usuario.
3. Ve a **Herramientas > Gestion usuarios CAD** para editar usuarios y relaciones.

## Notas

- Solo usuarios con `manage_options` pueden editar la configuracion.
- El CSS se aplica en el admin (`wp-admin`), no en el frontend.
- La gestion de usuarios requiere permisos de WordPress (`list_users` / `edit_user`).

## Estructura

- `custom-admin-dashboard.php` - bootstrap.
- `includes/class-cad-plugin.php` - carga principal del plugin.
- `includes/class-cad-access-control.php` - almacenamiento y sanitizacion de CSS por rol.
- `includes/class-cad-admin-panel.php` - pantalla de ajustes y salida de CSS en admin.
- `includes/class-cad-user-manager.php` - listado/edicion de usuarios, metadatos y relaciones.
