# DGAC · Registro de Drones

Módulo web para registrar aeronaves pilotadas a distancia (RPA / dron) ante la
Dirección General de Aeronáutica Civil. El formulario valida los datos, guarda el
registro en MariaDB/MySQL, genera un comprobante PDF con código único y lo envía
automáticamente al correo del propietario.

> **Nota de integración.** En el entorno de trabajo no se encontró el código
> fuente de la aplicación existente (el repositorio disponible solo contenía
> commits vacíos). Por eso este módulo se entrega como una pieza autocontenida y
> *integration-ready*: usa la misma base de datos de la aplicación y no crea un
> proyecto, servidor ni esquema paralelo. Las secciones
> [Integración con la aplicación existente](#11-integración-con-la-aplicación-existente)
> explican cómo conectarlo al backend actual conservando el contrato JSON.

---

## 1. Estructura

```text
registro-drones/
├── index.html                     Página del formulario
├── css/
│   └── styles.css                 Estilos institucionales responsive
├── js/
│   └── registro.js                Validación, API, confirmación y reintento
├── assets/
│   ├── img/logo-dgac.png          Logotipo oficial DGAC
│   └── icons/*.svg                Iconografía (sin dependencias)
├── api/
│   ├── bootstrap.php              Entorno, sesión, CSRF, CORS, errores, rate limit
│   ├── csrf.php                   GET  · token CSRF
│   ├── registro.php               POST · crear registro + PDF + correo
│   ├── estado.php                 GET  · consultar registro por token
│   ├── reintentar.php             POST · reintentar envío de correo
│   ├── pdf.php                    GET  · descargar comprobante PDF
│   └── lib/
│       ├── Database.php           Conexión PDO a la base existente
│       ├── Validator.php          Validación y normalización en servidor
│       ├── RegistroService.php    Lógica de negocio y persistencia
│       ├── PdfDocument.php        Generador de PDF sin dependencias
│       ├── Mailer.php             Cliente SMTP / mail() con adjunto PDF
│       └── ApiException.php       Error controlado de la API
├── sql/
│   └── 001_create_registro_drones.sql   Migración de la tabla
├── storage/                       PDFs, logs y rate limit (no se sirve por HTTP)
├── .env.example                   Plantilla de configuración
├── .env                           Configuración local de pruebas (ignorada por Git)
├── .gitignore
├── .htaccess                      Protección de .env y storage
└── README.md
```

No se modificó ningún archivo preexistente: todos los archivos listados son
nuevos dentro de `registro-drones/`.

---

## 2. Requisitos

- PHP **8.1+** (probado con PHP 8.3.36) con extensiones `pdo_mysql`, `mbstring`,
  `openssl` y `sockets`.
- MariaDB 10.x o MySQL 8.x (probado con MariaDB 10.11.16).
- Servidor web con PHP (Apache, Nginx + PHP-FPM o el servidor integrado de PHP
  para pruebas).
- Un servidor SMTP institucional, o un MTA local si usa `MAIL_TRANSPORT=mail`.
- No requiere Composer, Node.js ni librerías de PDF.

---

## 3. Instalación en la aplicación existente

1. Copie la carpeta `registro-drones/` dentro del directorio público del sitio
   (por ejemplo, junto a las demás páginas de la DGAC).
2. Ejecute **solo la sección `TABLA`** de
   `sql/001_create_registro_drones.sql` sobre la base de datos que ya usa la
   aplicación. **No ejecute la sección `BASE DE DATOS LOCAL`** en producción.
3. Copie `.env.example` como `.env` y complete los datos reales de la base,
   correo y URL. Si la aplicación ya administra variables de entorno, defina
   esas mismas claves en el mecanismo existente; `bootstrap.php` respeta las
   variables ya presentes en el proceso.
4. Verifique permisos de escritura para `storage/` (PDFs, logs y rate limit).
   En Apache/Nginx, bloquee el acceso HTTP directo a `storage/`; el `.htaccess`
   incluido lo hace en Apache con `AllowOverride` habilitado.
5. Abra `https://<dominio>/registro-drones/` y pruebe un registro real.

### 3.1 Variables de entorno

| Variable | Obligatoria | Descripción |
| --- | --- | --- |
| `APP_ENV` | No | `production` o `local` (en `local` se muestran detalles de error). |
| `APP_TIMEZONE` | No | Zona horaria, por defecto `America/Guatemala`. |
| `APP_URL` | Sí | URL pública del módulo, sin barra final. Se usa en el PDF y en `pdf_url`. |
| `ALLOWED_ORIGINS` | No | Orígenes CORS separados por coma. Vacío = mismo origen (recomendado). |
| `DB_HOST`, `DB_PORT` | Sí | Servidor MariaDB/MySQL existente. |
| `DB_DATABASE` | Sí | Base de datos existente. No se crea una nueva. |
| `DB_USERNAME`, `DB_PASSWORD` | Sí | Usuario con `SELECT, INSERT, UPDATE` sobre `registro_drones`. |
| `DB_CHARSET` | No | Por defecto `utf8mb4`. |
| `DB_SSL_CA` | No | Ruta al certificado CA si el servidor exige TLS. |
| `MAIL_TRANSPORT` | No | `smtp` (recomendado) o `mail`. |
| `MAIL_HOST`, `MAIL_PORT` | Sí (smtp) | Servidor SMTP institucional. |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | No | Credenciales SMTP si el servidor las exige. |
| `MAIL_ENCRYPTION` | No | `tls` (STARTTLS), `ssl` (TLS directo) o `none`. |
| `MAIL_FROM`, `MAIL_FROM_NAME` | Sí | Remitente del comprobante. |
| `MAIL_REPLY_TO` | No | Responder-a; si está vacío se usa `MAIL_FROM`. |
| `MAIL_TIMEOUT` | No | Timeout SMTP en segundos (5–60). |
| `STORAGE_PATH` | No | Ruta absoluta para PDFs y logs. Vacío = `registro-drones/storage`. |
| `RATE_LIMIT_MAX`, `RATE_LIMIT_WINDOW` | No | Límite de registros por IP y ventana en segundos. |
| `MAIL_MAX_RETRIES` | No | Máximo de reintentos de correo por registro. |
| `PDF_RETENTION_DAYS` | No | Reservado para políticas de limpieza de PDFs. |

---

## 4. Flujo funcional

```text
Navegador
  │  1. GET api/csrf.php            → cookie de sesión + token CSRF
  │  2. POST api/registro.php       → JSON validado + X-CSRF-Token
  ▼
Backend (PHP)
  │  3. Valida y normaliza (Validator)
  │  4. INSERT en registro_drones (estado REGISTRADO, token único)
  │  5. Genera PDF (PdfDocument) y lo guarda en storage/pdf
  │  6. Envía correo con PDF adjunto (Mailer)
  │       ├─ éxito → estado CORREO_ENVIADO
  │       └─ fallo → estado CORREO_FALLIDO (el registro se conserva)
  ▼
Respuesta JSON (201): datos + token_comprobante + estado + pdf_url

Navegador
  │  7. Muestra confirmación, descarga PDF y, si aplica, botón Reintentar
  └  8. POST api/reintentar.php     → reintenta el correo sin duplicar el registro
```

### Estados

| Estado | Significado |
| --- | --- |
| `REGISTRADO` | Registro almacenado; el envío aún no se ha intentado o está en curso. |
| `CORREO_ENVIADO` | PDF generado y correo aceptado por el servidor SMTP. |
| `CORREO_FALLIDO` | Registro guardado; el correo falló y puede reintentarse. |

El fallo de correo **no revierte** el registro: se conserva para reintento y
para descarga manual del comprobante.

---

## 5. Contrato de la API

Todas las respuestas son JSON con la forma:

```json
{ "ok": true, "mensaje": "...", "data": { } }
{ "ok": false, "mensaje": "...", "errores": { "campo": "mensaje" } }
```

### `GET api/csrf.php`

Crea la sesión y devuelve:

```json
{ "ok": true, "csrf_token": "<64 hex>" }
```

El token también debe enviarse en la cabecera `X-CSRF-Token` en cada `POST`.

### `POST api/registro.php`

Cabeceras: `Content-Type: application/json`, `X-CSRF-Token: <token>`.

```json
{
  "marca_dron": "DJI",
  "modelo": "Mavic 3 Pro",
  "numero_serie": "1ZNBJ7CP0A2B3C",
  "nombre_propietario": "María Fernanda López García",
  "correo_contacto": "maria.lopez@example.com"
}
```

Respuestas:

- `201` registro creado (aunque el correo falle).
- `409` número de serie duplicado.
- `419` token CSRF inválido o sesión expirada.
- `422` errores de validación por campo.
- `429` límite de solicitudes por IP.
- `405` método incorrecto.
- `400` JSON inválido o ausente.

```json
{
  "ok": true,
  "mensaje": "Registro completado. El comprobante fue enviado a su correo electrónico.",
  "data": {
    "id": 1,
    "codigo": "DGAC-RPA-2026-000001",
    "marca_dron": "DJI",
    "modelo": "Mavic 3 Pro",
    "numero_serie": "1ZNBJ7CP0A2B3C",
    "nombre_propietario": "María Fernanda López García",
    "correo_contacto": "maria.lopez@example.com",
    "fecha_registro": "2026-11-06 11:28:08",
    "estado_registro": "CORREO_ENVIADO",
    "correo_enviado": true,
    "intentos_envio": 1,
    "reintento_disponible": false,
    "token_comprobante": "<64 hex>",
    "pdf_url": "https://<dominio>/registro-drones/api/pdf.php?token=<64 hex>"
  }
}
```

### `GET api/estado.php?token=<token>`

Devuelve el registro público asociado al token (útil para restaurar la
confirmación al recargar la página). `404` si el token no existe.

### `POST api/reintentar.php`

```json
{ "token": "<64 hex>", "csrf_token": "<64 hex>" }
```

Reintenta el correo sin crear otro registro. `200` con el estado actualizado;
`429` si se alcanzó `MAIL_MAX_RETRIES`.

### `GET api/pdf.php?token=<token>`

Descarga el comprobante `application/pdf`, regenerado desde los datos vigentes.
`404` si el token no existe. El token es una capacidad aleatoria de 64
caracteres; no se expone el ID solo.

---

## 6. Base de datos

Tabla `registro_drones` (ver `sql/001_create_registro_drones.sql`):

| Campo | Tipo | Notas |
| --- | --- | --- |
| `id` | `BIGINT UNSIGNED` | PK autoincremental. |
| `marca_dron` | `VARCHAR(80)` | Obligatorio. |
| `modelo` | `VARCHAR(80)` | Obligatorio. |
| `numero_serie` | `VARCHAR(40)` | Obligatorio, **UNIQUE**. |
| `nombre_propietario` | `VARCHAR(150)` | Obligatorio. |
| `correo_contacto` | `VARCHAR(190)` | Obligatorio, indexado. |
| `fecha_registro` | `DATETIME` | Por defecto `CURRENT_TIMESTAMP`. |
| `estado_registro` | `ENUM(...)` | `REGISTRADO`, `CORREO_ENVIADO`, `CORREO_FALLIDO`. |
| `pdf_token` | `CHAR(64)` | UNIQUE; capacidad de descarga. |
| `correo_enviado_en` | `DATETIME` | Fecha del envío exitoso. |
| `intentos_envio` | `TINYINT UNSIGNED` | Conteo de intentos. |
| `ultimo_error` | `VARCHAR(500)` | Último error SMTP (sin datos sensibles). |
| `actualizado_en` | `DATETIME` | `ON UPDATE CURRENT_TIMESTAMP`. |

Los campos solicitados se conservan con sus nombres exactos; los campos
operativos adicionales son necesarios para el comprobante, la descarga y el
reintento.

---

## 7. Validación

La misma lógica se aplica en el navegador y en el servidor:

- Todos los campos son obligatorios; se rechazan valores vacíos o solo espacios.
- Se eliminan etiquetas HTML, caracteres de control y espacios repetidos.
- **Marca / modelo:** 2–80 caracteres, letras, números y puntuación habitual.
- **Número de serie:** 6–40 caracteres; se eliminan espacios y se convierte a
  mayúsculas; solo `A-Z 0-9 . _ -`; único en la base.
- **Nombre del propietario:** 5–150 caracteres, al menos nombre y apellido.
- **Correo:** formato válido y máximo 190 caracteres.
- Errores en línea por campo, foco en el primer campo inválido y bloqueo del
  botón mientras se envía para evitar dobles registros.
- El backend **siempre** repite la validación; la del navegador es solo para
  respuesta inmediata.

---

## 8. Comprobante PDF y correo

- PDF A4 de una página, generado sin librerías externas (`PdfDocument`).
- Incluye encabezado institucional, código único, fecha, datos del dron,
  propietario, correo, estado y nota de validez.
- Se guarda en `storage/pdf/registro-<id>-<token>.pdf` y se regenera al
  descargarlo.
- El correo incluye versión texto y HTML, más el PDF adjunto.
- Si el SMTP falla, el registro queda en `CORREO_FALLIDO`, se guarda
  `ultimo_error` y el usuario puede descargar el PDF o reintentar el envío.
- `MAIL_TRANSPORT=mail` permite usar la función `mail()` si la institución ya
  tiene un MTA configurado.

---

## 9. Seguridad

- Consultas preparadas con PDO y `ATTR_EMULATE_PREPARES=false`.
- Token CSRF por sesión, cookie `HttpOnly`, `SameSite=Lax` y `Secure` en HTTPS.
- Verificación de mismo origen para escrituras y CORS opcional por lista blanca.
- Rate limit por IP y acción basado en archivos con `flock`.
- Validación y normalización en servidor; salida con `textContent` en el
  navegador y `htmlspecialchars` en el correo.
- Token de comprobante aleatorio de 256 bits; no se usan IDs secuenciales como
  capacidad de descarga.
- `.env` y `storage/` protegidos por `.htaccess` y excluidos en `.gitignore`.
- Errores internos registrados en `storage/logs` sin exponer detalles en
  producción.

---

## 10. Pruebas locales realizadas

Entorno: Windows, PHP 8.3.36, MariaDB 10.11.16 en `127.0.0.1:3307`, servidor
integrado de PHP en `127.0.0.1:8080` y un SMTP de prueba local en
`127.0.0.1:2525`.

Resultados verificados:

1. **PDF aislado:** 5.5 KB, cabecera `%PDF-1.4`, xref y offsets válidos,
   `stream /Length` correcto y texto con todos los campos.
2. **Validación vacía:** `422` con los cinco errores por campo.
3. **Correo inválido, serie corta y nombre incompleto:** `422` con el error
   correspondiente.
4. **Serie con espacios:** se normaliza (`" edge space 0009 "` →
   `EDGESPACE0009`) y se guarda correctamente.
5. **Registro válido:** `201`, fila en MariaDB con estado `CORREO_ENVIADO`,
   PDF en `storage/pdf` y correo `.eml` recibido por el SMTP de prueba.
6. **Duplicado:** `409` con error en `numero_serie`.
7. **CSRF inválido:** `419`; **método incorrecto:** `405`; **JSON inválido:**
   `400`; **token inexistente:** `404`.
8. **SMTP caído:** `201` con estado `CORREO_FALLIDO`, `intentos_envio=1` y
   `reintento_disponible=true`; registro y PDF conservados.
9. **Reintento:** `200`, estado `CORREO_ENVIADO`, `intentos_envio=2`,
   `ultimo_error=NULL`.
10. **Navegador real:** validación en línea, envío, panel de confirmación,
    enlace de descarga, estado de correo pendiente y botón de reintento; sin
    errores de consola.

### Reproducir las pruebas localmente

```powershell
# 1. Base de datos de prueba (solo local)
C:\xampp\mysql\bin\mysqld.exe --datadir="C:\xampp\mysql\data" --port=3307 --bind-address=127.0.0.1 --skip-grant-tables --console

# 2. Crear tabla local (incluye CREATE DATABASE solo para pruebas)
Get-Content .\registro-drones\sql\001_create_registro_drones.sql -Raw |
  & C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -P 3307 -u root

# 3. Servidor web
C:\xampp\php\php.exe -S 127.0.0.1:8080 -t C:\ruta\a\DGAC

# 4. Abrir
# http://127.0.0.1:8080/registro-drones/
```

Para verificar el correo sin un SMTP real, apunte `MAIL_HOST`/`MAIL_PORT` a un
servidor SMTP de pruebas local o use `MAIL_TRANSPORT=mail` con un MTA de prueba.
Un fallo de SMTP también es un caso válido: debe producir `CORREO_FALLIDO` y
permitir el reintento.

---

## 11. Integración con la aplicación existente

El módulo está separado en capas para reutilizar lo que ya exista:

| Responsabilidad | Archivo actual | Cómo integrarlo |
| --- | --- | --- |
| Conexión a datos | `api/lib/Database.php` | Reemplace `connection()` por el cliente/ORM de la app. |
| Persistencia | `api/lib/RegistroService.php` | Mueva las consultas al repositorio existente y conserve el contrato JSON. |
| Validación | `api/lib/Validator.php` | Reutilice el validador del backend si ya existe. |
| PDF | `api/lib/PdfDocument.php` | Sustituya `buildRegistro()` por el generador institucional. |
| Correo | `api/lib/Mailer.php` | Sustituya `sendRegistro()` por el mailer de la app. |
| Rutas | `api/*.php` | Registre las rutas en el router actual con los mismos métodos y respuestas. |

Recomendaciones:

- Mantenga los nombres de campos y los códigos HTTP del contrato para no tocar
  el frontend.
- Si la app ya tiene un endpoint para PDF o correo, llámelo desde
  `RegistroService` en lugar de duplicar lógica.
- Si la app usa otro origen para la API, defina antes de `js/registro.js`:

```html
<script>
  window.DGAC_DRONES_CONFIG = { apiBase: 'https://api.dgac.gob.gt/registro-drones/api' };
</script>
```

y agregue ese origen en `ALLOWED_ORIGINS`.

---

## 12. Operación y mantenimiento

- Revise `storage/logs/app-AAAA-MM-DD.log` para errores de SMTP o base de datos.
- Los PDFs se pueden archivar o depurar según `PDF_RETENTION_DAYS`.
- Si cambia el dominio, actualice `APP_URL` para que los enlaces del PDF y de
  la API sean correctos.
- Los registros con `CORREO_FALLIDO` pueden reenviarse desde la misma página
  mientras no se alcance `MAIL_MAX_RETRIES`; después, la DGAC debe completar el
  envío manualmente.
- Para producción, use HTTPS, un usuario de base con permisos mínimos y
  credenciales SMTP entregadas por el área de TI.

---

## 13. Propiedad y uso

Este módulo fue preparado para la Dirección General de Aeronáutica Civil de
Guatemala. El logotipo e identidad institucional pertenecen a la DGAC. Ajuste
textos legales, políticas de retención y datos de contacto según la normativa
vigente antes de publicarlo.
