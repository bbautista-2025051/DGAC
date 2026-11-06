# DGAC · Registro de Drones — módulo Python

Módulo Python del sistema de registro de drones de la Dirección General
de Aeronáutica Civil (Guatemala). Encargado del **envío del comprobante
por correo** y del **reintento automático** de envíos fallidos.

> **Estado de la base de datos:** la conexión a **MariaDB** la está
> implementando el responsable de la base de datos (tabla `registro_drones`,
> migración `sql/001_create_registro_drones.sql`). Este módulo funciona
> con un respaldo en memoria hasta que la BD esté lista.

## Estructura

```
phyton/
├── config/
│   └── database.py            # Interfaz/config de la BD (la implementación la hace el equipo de BD)
├── services/
│   ├── email_service.py       # Envío por SMTP + plantilla HTML + adjunto PDF
│   └── solicitud_service.py   # Validación, código único y estados del registro
├── worker/
│   └── email_worker.py        # Proceso que reintenta correos fallidos
├── templates/
│   └── confirmacion.html      # Plantilla del correo de confirmación
├── .env.example               # Variables de entorno (copiar a .env)
├── requirements.txt           # Dependencias
└── README.md
```

## Configuración

1. Copie `.env.example` a `.env` y complete los valores reales:
   - `MAIL_*`: servidor SMTP (el mismo que usa la aplicación PHP).
   - `DB_*`: quedan listos para el equipo de base de datos.
2. Dependencias:

   ```bash
   pip install -r requirements.txt
   ```

## Uso

```python
from services.solicitud_service import ServicioSolicitudes
from services.email_service import EmailError, ValidationFailed

servicio = ServicioSolicitudes()

try:
    sol = servicio.registrar({
        "marca_dron": "DJI",
        "modelo": "Mavic 3 Pro",
        "numero_serie": "1ZNBJ7CP0A2B3C",
        "nombre_propietario": "María Fernanda López García",
        "correo_contacto": "propietario@ejemplo.com",
    })
    print("Registrada:", sol.codigo, "-", sol.estado)
except ValidationFailed as e:
    print("Datos inválidos:", e)
except EmailError as e:
    print("El registro quedó guardado, correo pendiente:", e)
```

Worker de reintentos (ejecutar desde `phyton/`):

```bash
python -m worker.email_worker            # cicla cada WORKER_INTERVAL segundos
python -m worker.email_worker --una-vez  # un solo ciclo de prueba
```

## Estados del registro

| Estado              | Significado                                        |
| ------------------- | -------------------------------------------------- |
| `REGISTRADO`        | Guardado, correo aún no confirmado                 |
| `CORREO_ENVIADO`    | Comprobante enviado al correo de contacto          |
| `CORREO_FALLIDO`    | Envío falló; el worker lo reintenta automáticamente|

Los nombres coinciden con el `ENUM` de la tabla `registro_drones`.
