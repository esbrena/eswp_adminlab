# Custom Admin Dashboard (WordPress)

Plugin para que el **superadmin** defina exactamente que ven los admins operativos
dentro de `wp-admin`, con panel custom y branding.

## Objetivo

Controlar de forma granular:

- Que seccion de **usuarios** pueden ver.
- Que seccion de **posts** pueden ver (incluyendo seleccion por `post_type`).
- Que **menus de plugins instalados** pueden usar.
- Que contenido de WordPress se oculta para simplificar el trabajo.

Ademas, personalizar visualmente el panel:

- Logo de cabecera.
- Titulo/subtitulo.
- Colores del panel.
- CSS custom opcional.

## Funcionalidades principales

1. **Control por superadmin**
   - En multisite, solo superadmin puede guardar configuracion de visibilidad.
   - En single-site, se usa `manage_options`.

2. **Visibilidad granular de wp-admin**
   - Restriccion de menus top-level y submenus.
   - Acceso selectivo a usuarios.
   - Acceso selectivo a posts por tipo de contenido.
   - Acceso selectivo a menus de plugins detectados.
   - Redireccion a dashboard custom cuando intentan abrir una pantalla no permitida.

3. **Limpieza de interfaz**
   - Ocultar widgets del dashboard.
   - Limpiar items de admin bar.
   - Ocultar notices/context help/screen options para un panel operativo mas simple.

4. **Dashboard custom**
   - Vista resumen para equipo operativo.
   - Acceso a gestion simplificada de usuarios y metadatos.

5. **Branding**
   - Logo, textos de cabecera y colores.
   - CSS custom.

## Instalacion

1. Copia la carpeta del plugin en:
   `wp-content/plugins/custom-admin-dashboard`
2. Activa el plugin.
3. Entra en **Panel operativo > Configuracion**.
4. Define:
   - Roles operativos afectados.
   - Que ven en Plugins / Usuarios / Posts.
   - Que elementos de WP ocultar.
   - Colores y logo.

## Estructura

- `custom-admin-dashboard.php` - bootstrap.
- `includes/class-cad-access-control.php` - settings, permisos y sanitizacion.
- `includes/class-cad-admin-panel.php` - dashboard, configuracion y restricciones UI.
- `includes/class-cad-user-manager.php` - gestion simplificada de usuarios/metadatos.
- `assets/admin.css` - estilos y branding.
