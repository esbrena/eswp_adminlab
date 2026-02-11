# Custom Admin Dashboard (WordPress)

Plugin para que el **superadmin** defina exactamente que ven los admins operativos
dentro de `wp-admin`, con panel custom y branding.

## Objetivo

Controlar de forma simple (seleccion directa):

- Que seccion de **usuarios** pueden ver.
- Que seccion de **posts** pueden ver (incluyendo seleccion por `post_type`).
- Que **plugins** pueden usar desde sidebar.
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
   - Aplicacion forzada de reglas de visibilidad (evita que vuelvan menus no permitidos).
   - El superadmin (o `manage_options` en single-site) mantiene vision completa del wp-admin.
   - Soporte de capabilities extra para que plugins terceros permitan acciones.
   - Acceso selectivo a usuarios.
   - Acceso selectivo a posts por tipo de contenido.
   - Acceso selectivo a menus de plugins detectados.
   - Detecta plugins con menu top-level, submenu y entradas basadas en custom post type.
   - Redireccion a dashboard custom cuando intentan abrir una pantalla no permitida.
   - Compatibilidad mejorada con acciones internas de plugins (POST/admin-post/options).

3. **Limpieza de interfaz**
   - Ocultar widgets del dashboard.
   - Limpiar items de admin bar.
   - Ocultar notices/context help/screen options para un panel operativo mas simple.

4. **Dashboard custom**
   - Vista resumen para equipo operativo.
   - Acceso a gestion simplificada de usuarios y metadatos.
   - Ficha de usuario con acceso a cursos y reservas relacionados.

5. **Usuarios mas usable**
   - Filtro de metadatos por clave/valor.
   - Ordenacion por clave meta.
   - Edicion mejorada de valores largos.
   - Tabla de cursos/reservas ligados por autor o por claves meta de relacion.

6. **Branding**
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
   - Post types y claves meta para integrar cursos/reservas por usuario.
   - Colores y logo.

## Estructura

- `custom-admin-dashboard.php` - bootstrap.
- `includes/class-cad-access-control.php` - settings, permisos y sanitizacion.
- `includes/class-cad-admin-panel.php` - dashboard, configuracion y restricciones UI.
- `includes/class-cad-user-manager.php` - gestion simplificada de usuarios, metadatos y actividad cursos/reservas.
- `assets/admin.css` - estilos y branding.
