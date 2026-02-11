# Custom Admin Dashboard (WordPress)

Plugin simplificado para definir **CSS personalizado por rol** en `wp-admin`.

## Que hace

- Permite guardar CSS distinto para cada rol de WordPress.
- Aplica automaticamente ese CSS solo a usuarios que tienen ese rol.
- Si un usuario tiene varios roles, combina el CSS de todos esos roles.

## Configuracion

1. Activa el plugin.
2. Ve a **Ajustes > CSS por rol**.
3. Escribe el CSS en el textarea de cada rol.
4. Guarda cambios.

## Notas

- Solo usuarios con `manage_options` pueden editar la configuracion.
- El CSS se aplica en el admin (`wp-admin`), no en el frontend.

## Estructura

- `custom-admin-dashboard.php` - bootstrap.
- `includes/class-cad-plugin.php` - carga principal del plugin.
- `includes/class-cad-access-control.php` - almacenamiento y sanitizacion de CSS por rol.
- `includes/class-cad-admin-panel.php` - pantalla de ajustes y salida de CSS en admin.
