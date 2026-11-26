# Rutas Seguras - CBTis 258 General Escobedo

Aplicación web responsiva para consultar el mapa, publicar reportes con ubicación y moderarlos desde una cuenta administrativa. Los reportes se guardan en MySQL.

## Estructura

```
index.php                 Página pública y mapa
api/reports.php           API JSON para reportes
api/locations.php         API de lectura y administración de ubicaciones
api/sos.php               API de contactos y seguimiento SOS
track.php                 Vista privada de ubicación en vivo para el contacto
admin/login.php           Redirige al acceso compartido
admin/index.php           Moderación (requiere iniciar sesión)
admin/locations.php       Edición del plantel y puntos seguros
config/database.php       Conexión PDO
config/admin_auth.php     Sesión y protección CSRF de administración
assets/css/style.css      Estilos responsivos y tema oscuro
assets/css/login.css      Diseño responsivo de inicio de sesión
assets/js/app.js          Mapa y funcionalidades interactivas
assets/js/live-tracking.js Actualización del enlace de seguimiento SOS
scripts/create_admin.php  Alta de administradores desde la consola
scripts/migrate_safe_point_images.php  Agrega compatibilidad de fotos a puntos seguros existentes
scripts/migrate_campus_images.php  Agrega compatibilidad de fotos al plantel existente
scripts/migrate_emergency_sos.php  Agrega contacto y sesiones de seguimiento SOS
scripts/migrate_route_proposals.php  Agrega solicitudes de rutas seguras vecinales
database.sql              Esquema sin reportes de ejemplo
```

## Instalación local (XAMPP en Windows)

1. Coloca el proyecto en `C:\xampp\htdocs\RUTA-SEGURA`.
2. Desde el panel de XAMPP inicia **Apache** y **MySQL**.
3. En `http://localhost/phpmyadmin`, abre la pestaña **SQL** e importa el contenido de `database.sql`.
4. Ejecuta las migraciones desde PowerShell o CMD para preparar las cuentas de usuario y las fotografías sin borrar los datos existentes:

   ```powershell
   cd C:\xampp\htdocs\RUTA-SEGURA
   C:\xampp\php\php.exe scripts\migrate_user_accounts.php
   C:\xampp\php\php.exe scripts\migrate_safe_point_images.php
   C:\xampp\php\php.exe scripts\migrate_campus_images.php
   C:\xampp\php\php.exe scripts\migrate_emergency_sos.php
   C:\xampp\php\php.exe scripts\migrate_route_proposals.php
   ```

5. Verifica las credenciales de MySQL en `config/database.php`. El valor `root` sin contraseña es solo el predeterminado de XAMPP local; configura credenciales propias fuera de desarrollo.
6. Abre `http://localhost/RUTA-SEGURA/`. Alumnos y administradores usan el mismo formulario de inicio de sesión. El alumno ingresa su nombre de usuario; el administrador, el correo usado al crear su cuenta. Al autenticarlo, será dirigido al panel administrativo.
7. Crea la cuenta administrativa desde PowerShell o CMD:

   ```powershell
   cd C:\xampp\htdocs\RUTA-SEGURA
   C:\xampp\php\php.exe scripts\create_admin.php
   ```

   Escribe un correo electrónico válido de hasta 80 caracteres y una contraseña de al menos 12 caracteres. La herramienta guarda solo el hash de contraseña. El correo puede aparecer en pantalla mientras se escribe; no uses la misma contraseña de otros servicios.
8. Las cuentas de usuario se crean desde **Regístrate aquí** en la página principal. Cada persona puede consultar solo su propio historial; su usuario no aparece en los reportes públicos. Los administradores se crean únicamente con el comando anterior y, al autenticarse, acceden a su panel separado.

## Configuración de SOS automático

1. Configura una cuenta de Twilio con un número emisor habilitado para SMS. En cuentas de prueba, Twilio puede exigir que el destinatario esté verificado.
2. Define estas variables en el entorno del servidor web o en la configuración privada de Apache/hosting; no las guardes en el repositorio ni en archivos públicos:
   - `TWILIO_ACCOUNT_SID`
   - `TWILIO_AUTH_TOKEN`
   - `TWILIO_FROM_NUMBER` (formato E.164, por ejemplo `+15551234567`)
   - `RS_PUBLIC_BASE_URL` (URL HTTPS pública de la aplicación, por ejemplo `https://ejemplo.mx/RUTA-SEGURA`; sin `/` al final)
3. Reinicia Apache y confirma que la extensión `curl` de PHP esté habilitada.
4. Cada usuario registra su número de emergencia en **Cuenta** con prefijo internacional, por ejemplo `+528112345678`.

Al activar el SOS, el navegador solicita permiso GPS, el servidor envía automáticamente un SMS por Twilio y el contacto recibe un enlace privado. La página consulta la ubicación cada 5 segundos; el dispositivo la envía al detectar movimiento y hace una actualización de respaldo cada 15 segundos. El seguimiento dura como máximo 2 horas; se puede detener desde la página y cambiar el contacto revoca el enlace anterior. La ubicación se actualiza mientras la página permanezca abierta, con GPS e Internet disponibles. El enlace funciona como una llave privada: compártelo solo con personas de confianza. Se requiere HTTPS y una URL pública; `localhost` no permite que el contacto abra el seguimiento.

El mapa normal y sus selecciones siguen limitados a General Escobedo. La ubicación SOS puede reportarse fuera del municipio si la persona se desplaza durante una emergencia.

## Credenciales administrativas predeterminadas

El proyecto incluye una cuenta administrativa predeterminada. Se crea automáticamente al iniciar sesión o al importar `database.sql`:

- Usuario/correo: `rutas.seguras@cbtis258.edu.mx`
- Contraseña: `rutas.seguras`

Cambiar esta contraseña antes de publicar la aplicación en Internet.

No existe registro público de administradores. Para crear otro, vuelve a ejecutar el comando. Las cuentas se almacenan por separado en `users` y `admin_users`.

## Funcionalidades

- Mapa Leaflet/OpenStreetMap con ubicación del plantel y puntos seguros administrables; inicia sin marcadores precargados.
- En `admin/locations.php`, guardar el punto principal del plantel y agregar/eliminar puntos seguros; se pueden elegir en el mapa o ingresar coordenadas.
- Agregar, reemplazar o quitar fotografías de puntos seguros desde administración; la imagen aparece al abrir el marcador en el mapa.
- En esa misma pantalla, trazar una ruta con clics sobre el mapa; al terminar se agrega el plantel como destino, y la ruta se guarda y publica con su color.
- Los usuarios pueden proponer una ruta marcando puntos en el mapa; el administrador la aprueba o rechaza y las aprobadas aparecen en amarillo para la comunidad.
- Crear una cuenta de usuario, iniciar sesión y consultar el historial propio de reportes.
- Dar un solo apoyo por reporte y cuenta de usuario; el servidor evita duplicados.
- Crear reportes con ubicación seleccionada en el mapa o GPS, tipo, nivel de riesgo y descripción; requiere iniciar sesión. El nombre del usuario no se publica.
- Guardar reportes y apoyos en MySQL; búsqueda, filtros, estadísticas y estado del reporte.
- En escritorio, desplazar el menú lateral de forma independiente al mapa.
- Activar o desactivar alertas para nuevos reportes de acoso o robo; con permiso del navegador también se muestran como notificaciones del sistema.
- Los reportes de tipo Acoso / Robo se registran automáticamente con riesgo alto; las alertas activas aparecen como notificaciones compactas con ubicación, descripción y fotografía adjunta.
- El mapa y el servidor solo aceptan reportes dentro del municipio de General Escobedo. El límite se obtiene de OpenStreetMap y se incluye en `data/escobedo-boundary.geojson` (© OpenStreetMap contributors, ODbL).
- Marcar reportes como resueltos/reactivarlos únicamente desde el panel de administración protegido por contraseña.
- Inicio de sesión con contraseñas hash, cookie `HttpOnly`/`SameSite=Strict`, regeneración de sesión y token CSRF para acciones administrativas.
- Modo oscuro, botón SOS con líneas de emergencia y enlace de WhatsApp para avisar a un familiar.
- Registro de contacto de emergencia y SMS automático por Twilio con enlace de seguimiento GPS de hasta 2 horas; permite revocar el enlace y dejar de compartir la ubicación.

## API

Todas las respuestas están en JSON. Las acciones de escritura requieren `Content-Type: application/json`, excepto la carga de fotografías, que usa `multipart/form-data`.

| Método | Endpoint | Acceso |
|---|---|---|
| GET | `api/reports.php?action=list&status=activo&type=...&q=...` | Público |
| GET | `api/reports.php?action=stats` | Público |
| POST | `api/reports.php?action=create` | Usuario autenticado; requiere sesión y CSRF; valida ubicación, campos y límites |
| POST | `api/reports.php?action=like` | Usuario autenticado; requiere sesión y cabecera `X-CSRF-Token`; un apoyo por reporte y cuenta |
| POST | `api/reports.php?action=resolve` | Solo administrador; requiere sesión y cabecera `X-CSRF-Token` |
| GET | `api/locations.php?action=list` | Público |
| POST | `api/locations.php?action=campus-save` | Solo administrador; requiere sesión y CSRF |
| POST | `api/locations.php?action=safe-point-create` | Solo administrador; requiere sesión y CSRF |
| POST | `api/locations.php?action=safe-point-delete` | Solo administrador; requiere sesión y CSRF |
| POST | `api/locations.php?action=safe-point-upload-photo` | Solo administrador; requiere sesión, CSRF y `multipart/form-data` |
| POST | `api/locations.php?action=safe-point-remove-photo` | Solo administrador; requiere sesión y CSRF |
| POST | `api/locations.php?action=route-create` | Solo administrador; requiere sesión y CSRF |
| POST | `api/locations.php?action=route-delete` | Solo administrador; requiere sesión y CSRF |
| GET | `api/route_proposals.php?action=approved` | Público; solo devuelve propuestas aprobadas |
| GET | `api/route_proposals.php?action=pending` | Solo administrador; requiere sesión y CSRF |
| POST | `api/route_proposals.php?action=create` | Usuario autenticado; requiere sesión y CSRF; limita los puntos a General Escobedo |
| POST | `api/route_proposals.php?action=review` | Solo administrador; requiere sesión y CSRF; permite aprobar o rechazar |
| GET/POST | `api/sos.php?action=contact` | Usuario autenticado; requiere sesión y CSRF |
| GET | `api/sos.php?action=active` | Usuario autenticado; requiere sesión y CSRF |
| POST | `api/sos.php?action=start` | Usuario autenticado; requiere sesión, CSRF, GPS y configuración Twilio |
| POST | `api/sos.php?action=update` / `stop` | Usuario autenticado; requiere sesión y CSRF |
| GET | `api/sos.php?action=track` | Enlace privado; token enviado en la cabecera `X-SOS-Tracking-Token` |

La aplicación necesita servir los archivos mediante Apache/PHP; abrir `index.php` directamente como archivo no activa el backend ni la conexión MySQL.
