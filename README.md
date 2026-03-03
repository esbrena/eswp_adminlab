# Custom Admin Dashboard (WordPress)

Plugin para dos funciones principales en `wp-admin`:

1. **CSS personalizado por rol**.
2. **Gestion avanzada de usuarios** (datos base y metadatos ACF).

## Que hace

- Permite guardar CSS distinto para cada rol de WordPress.
- Aplica automaticamente ese CSS solo a usuarios que tienen ese rol.
- Roles editables de CSS: `admin` y `admin_laboratorio`.
- Nueva pantalla de gestion de usuarios con:
  - listado y edicion visual de usuarios tipo `cie_user` y `cie_new_user`,
  - muestra `use_period` en el listado con estado de periodo (`Activo`/`Caducado`),
  - formulario cerrado a campos ACF definidos (profile_pic, name, birthdate, email, phone, adscription_university, university_role, address, job_address, experimental_project, use_needs, planned_equipment, use_period, aval_name, aval_mail),
  - `User Type` mostrado como tag informativo (no editable),
  - oculta `Nombre del Aval` y `Email del Aval` cuando el user type es interno,
  - `Fecha de nacimiento` y `Periodo de uso` con flatpickr (rango en `use_period`).

## Configuracion

1. Activa el plugin.
2. Ve a **Ajustes > CSS por rol** para:
   - configurar estilos para `admin` y `admin_laboratorio`.
3. Ve al menu principal **CIE - Usuarios** para editar esos perfiles.

## Notas

- Solo usuarios con `manage_options` pueden editar la configuracion.
- El CSS se aplica en el admin (`wp-admin`), no en el frontend.
- La gestion de usuarios requiere permisos de WordPress (`list_users` / `edit_user`).

## Estructura

- `custom-admin-dashboard.php` - bootstrap.
- `includes/class-cad-plugin.php` - carga principal del plugin.
- `includes/class-cad-access-control.php` - almacenamiento y sanitizacion de CSS por rol.
- `includes/class-cad-admin-panel.php` - pantalla de ajustes y salida de CSS en admin.
- `includes/class-cad-user-manager.php` - listado/edicion de usuarios y metadatos.
