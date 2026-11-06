# =============================================================================
# DGAC · Registro de Drones — worker/email_worker.py
#
# Proceso en segundo plano que revisa periódicamente las solicitudes con
# envío de correo pendiente o fallido y reintenta el envío del comprobante.
#
# Ejecución (desde la carpeta phyton/):
#     python -m worker.email_worker
#
# Intervalo y límite de reintentos configurables en .env
# (WORKER_INTERVAL y WORKER_MAX_RETRIES, ver .env.example).
#
# NOTA: cuando la conexión a MariaDB esté lista la usará automáticamente
# a través del repositorio configurado en services/solicitud_service.py.
# =============================================================================

import logging
import os
import time

from dotenv import load_dotenv

from services.email_service import EmailService
from services.solicitud_service import ESTADO_CORREO_FALLIDO, ServicioSolicitudes

BASE_DIR = __import__("pathlib").Path(__file__).resolve().parents[1]

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(name)s: %(message)s",
)
log = logging.getLogger("email_worker")


def procesar_pendientes(servicio: ServicioSolicitudes, max_reintentos: int) -> int:
    """Reintenta el envío de todas las solicitudes pendientes. Devuelve enviadas."""
    pendientes = servicio.listar_pendientes()
    if not pendientes:
        return 0

    log.info("Procesando %d solicitud(es) pendiente(s)...", len(pendientes))
    enviadas = 0
    for solicitud in pendientes:
        if solicitud.estado != ESTADO_CORREO_FALLIDO:
            # Recién registrada: su primer envío se hace en `registrar()`;
            # aquí solo se reintenta si figura como fallida.
            continue
        try:
            ok = servicio.reintentar_envio(solicitud, max_reintentos=max_reintentos)
            if ok:
                enviadas += 1
                log.info("Correo enviado: %s (intento %d)", solicitud.codigo, solicitud.intentos_envio)
            else:
                log.warning("Sigue fallando: %s (intento %d)", solicitud.codigo, solicitud.intentos_envio)
        except Exception as exc:  # un registro no debe detener al worker
            log.exception("Error inesperado con %s: %s", solicitud.codigo, exc)
    return enviadas


def main() -> None:
    load_dotenv(BASE_DIR / ".env")

    intervalo = int(os.getenv("WORKER_INTERVAL", "60"))
    max_reintentos = int(os.getenv("WORKER_MAX_RETRIES", "5"))

    servicio = ServicioSolicitudes(email_service=EmailService())

    log.info(
        "email_worker iniciado (intervalo=%ds, max_reintentos=%d)",
        intervalo,
        max_reintentos,
    )

    while True:
        try:
            procesar_pendientes(servicio, max_reintentos)
        except KeyboardInterrupt:
            log.info("email_worker detenido por el usuario.")
            break
        except Exception:
            log.exception("Ciclo falló; se reintentará en %ds.", intervalo)
        time.sleep(intervalo)


if __name__ == "__main__":
    # Permite también un ciclo único de prueba:  python -m worker.email_worker --una-vez
    import sys

    if "--una-vez" in sys.argv:
        load_dotenv(BASE_DIR / ".env")
        _servicio = ServicioSolicitudes(email_service=EmailService())
        _n = procesar_pendientes(_servicio, int(os.getenv("WORKER_MAX_RETRIES", "5")))
        print(f"Enviados {_n} correo(s).")
    else:
        main()
