# =============================================================================
# DGAC · Registro de Drones — services/solicitud_service.py
#
# Lógica de negocio de las solicitudes de registro de drones:
#   · validación de los datos del formulario
#   · generación del código único de registro
#   · manejo del estado del registro (REGISTRADO / CORREO_ENVIADO / CORREO_FALLIDO)
#
# SIN BASE DE DATOS: la persistencia la está implementando el responsable
# de la base de datos sobre MariaDB (tabla registro_drones, ver
# sql/001_create_registro_drones.sql y config/database.py).
# En lo único que hay que tocar cuando la BD esté lista es en
# _crear_repositorio(): reemplazar el respaldo en memoria por el MySQL.
# Las validaciones y el flujo NO cambian.
# =============================================================================

import logging
import secrets
from dataclasses import dataclass
from datetime import datetime
from typing import Any, Protocol
from zoneinfo import ZoneInfo

from dotenv import load_dotenv

from services.email_service import EmailError, EmailService, correo_valido

load_dotenv()

log = logging.getLogger(__name__)

# Zona horaria institucional (ver .env)
try:
    TZ = ZoneInfo("America/Guatemala")
except Exception:  # pragma: no cover
    TZ = datetime.now().astimezone().tzinfo

# Estados alineados con el ENUM de la tabla registro_drones
ESTADO_REGISTRADO = "REGISTRADO"
ESTADO_CORREO_ENVIADO = "CORREO_ENVIADO"
ESTADO_CORREO_FALLIDO = "CORREO_FALLIDO"


class ValidationFailed(Exception):
    """Los datos de la solicitud no pasan la validación."""


@dataclass
class Solicitud:
    """Espejo de la tabla `registro_drones` (solo los campos del formulario)."""

    marca_dron: str = ""
    modelo: str = ""
    numero_serie: str = ""
    nombre_propietario: str = ""
    correo_contacto: str = ""
    codigo: str = ""
    estado: str = ESTADO_REGISTRADO
    fecha: datetime | None = None
    intentos_envio: int = 0
    ultimo_error: str = ""

    def a_dict(self) -> dict[str, Any]:
        return {
            "marca_dron": self.marca_dron,
            "modelo": self.modelo,
            "numero_serie": self.numero_serie,
            "nombre_propietario": self.nombre_propietario,
            "correo_contacto": self.correo_contacto,
            "codigo": self.codigo,
            "estado": self.estado,
            "fecha": self.fecha,
            "intentos_envio": self.intentos_envio,
            "ultimo_error": self.ultimo_error,
        }


def generar_codigo_unico() -> str:
    """Código único de registro, formato DGAC-XXXXXXXX."""
    return f"DGAC-{secrets.token_hex(4).upper()}"


def validar_solicitud(datos: dict[str, Any]) -> dict[str, str]:
    """
    Valida los datos del formulario (mismas reglas que la API PHP).
    Devuelve un dict campo -> mensaje de error. Vacío si todo es válido.
    """
    errores: dict[str, str] = {}

    texto = lambda v: (v or "").strip()  # noqa: E731  # normaliza a texto limpio

    if not (4 <= len(texto(datos.get("marca_dron"))) <= 80):
        errores["marca_dron"] = "La marca debe tener entre 4 y 80 caracteres."
    if not texto(datos.get("modelo")) or len(texto(datos.get("modelo"))) > 80:
        errores["modelo"] = "El modelo es obligatorio (máximo 80 caracteres)."

    serie = texto(datos.get("numero_serie"))
    if not (6 <= len(serie) <= 40) or not all(c.isalnum() or c in "._-" for c in serie):
        errores["numero_serie"] = (
            "Entre 6 y 40 caracteres: letras, números, puntos, guiones y guiones bajos."
        )

    if not texto(datos.get("nombre_propietario")) or len(texto(datos.get("nombre_propietario"))) > 150:
        errores["nombre_propietario"] = "El nombre del propietario es obligatorio (máximo 150 caracteres)."

    if not correo_valido(str(datos.get("correo_contacto", ""))):
        errores["correo_contacto"] = "Indique un correo electrónico válido."

    return errores


# =============================================================================
# Repositorio de solicitudes
# Interfaz que la implementación de MariaDB deberá cumplir.
# =============================================================================
class RepositorioSolicitudes(Protocol):
    def guardar(self, solicitud: Solicitud) -> None: ...
    def actualizar_estado(self, codigo: str, estado: str, intentos: int, ultimo_error: str) -> None: ...
    def listar_pendientes(self) -> list[Solicitud]: ...
    def obtener_por_serie(self, numero_serie: str) -> Solicitud | None: ...


class RepositorioEnMemoria:
    """
    Respaldo TEMPORAL sin base de datos (solo pruebas y demostración).
    Cuando la implementación de MariaDB esté lista, reemplazar por la real.
    """

    def __init__(self) -> None:
        self._datos: dict[str, Solicitud] = {}

    def guardar(self, solicitud: Solicitud) -> None:
        self._datos[solicitud.numero_serie] = solicitud

    def actualizar_estado(self, codigo: str, estado: str, intentos: int, ultimo_error: str) -> None:
        for sol in self._datos.values():
            if sol.codigo == codigo:
                sol.estado = estado
                sol.intentos_envio = intentos
                sol.ultimo_error = ultimo_error
                return

    def listar_pendientes(self) -> list[Solicitud]:
        return [
            sol
            for sol in self._datos.values()
            if sol.estado in (ESTADO_REGISTRADO, ESTADO_CORREO_FALLIDO)
        ]

    def obtener_por_serie(self, numero_serie: str) -> Solicitud | None:
        return self._datos.get(numero_serie)


class ServicioSolicitudes:
    """Orquesta el registro: valida, asigna código y coordina el correo."""

    def __init__(
        self,
        email_service: EmailService | None = None,
        repositorio: RepositorioSolicitudes | None = None,
    ) -> None:
        self.email = email_service or EmailService()
        self.repositorio = repositorio
        self._repo: RepositorioSolicitudes
        if repositorio is not None:
            self._repo = repositorio
        else:
            # TODO (BD): el equipo de base de datos proveerá el repositorio
            # de MariaDB, p. ej.  repositorio_bd.RepositorioMariaDB()
            self._repo = RepositorioEnMemoria()
            log.warning(
                "Usando respaldo en memoria: la conexión a MariaDB no está "
                "implementada todavía (config/database.py)."
            )

    # ---------------------------------------------------------------
    # Registro principal
    # ---------------------------------------------------------------
    def registrar(self, datos: dict[str, Any]) -> Solicitud:
        """
        Valida los datos, crea la solicitud y envía el correo de confirmación.
        Si el correo falla, la solicitud conserva el estado CORREO_FALLIDO
        para que email_worker.py reintente más tarde.
        """
        errores = validar_solicitud(datos)
        if errores:
            detalle = "; ".join(f"{c}: {m}" for c, m in errores.items())
            raise ValidationFailed(detalle)

        solicitud = Solicitud(
            marca_dron=str(datos.get("marca_dron", "")).strip(),
            modelo=str(datos.get("modelo", "")).strip(),
            numero_serie=str(datos.get("numero_serie", "")).strip().upper(),
            nombre_propietario=str(datos.get("nombre_propietario", "")).strip(),
            correo_contacto=str(datos.get("correo_contacto", "")).strip(),
            codigo=generar_codigo_unico(),
            fecha=datetime.now(TZ),
        )

        existente = self._repo.obtener_por_serie(solicitud.numero_serie)
        if existente is not None:
            raise ValidationFailed(
                "El número de serie ya se encuentra registrado "
                f"(código {existente.codigo})."
            )

        self._enviar_confirmacion(solicitud)
        self._repo.guardar(solicitud)
        log.info("Registrado dron %s %s (%s)", solicitud.marca_dron, solicitud.modelo, solicitud.codigo)
        return solicitud

    # ---------------------------------------------------------------
    # Correo
    # ---------------------------------------------------------------
    def _enviar_confirmacion(self, solicitud: Solicitud) -> None:
        asunto = f"DGAC - Comprobante de registro de dron ({solicitud.codigo})"
        error = ""
        try:
            self.email.enviar(
                destinatario=solicitud.correo_contacto,
                asunto=asunto,
                contexto_html=solicitud.a_dict() | {"fecha": self._fmt_fecha(solicitud)},
                adjunto=self._ruta_comprobante(solicitud),
            )
            solicitud.estado = ESTADO_CORREO_ENVIADO
        except EmailError as exc:
            solicitud.estado = ESTADO_CORREO_FALLIDO
            error = str(exc)
        solicitud.ultimo_error = error

    def _ruta_comprobante(self, _solicitud: Solicitud) -> str | None:
        """
        Ruta del PDF del comprobante a adjuntar, si existe en storage/.
        La generación del PDF la realiza la API PHP (api/pdf.php).
        """
        return None

    # ---------------------------------------------------------------
    # Reintentos (usado por worker/email_worker.py)
    # ---------------------------------------------------------------
    def reintentar_envio(self, solicitud: Solicitud, max_reintentos: int = 5) -> bool:
        """Reintenta el envío del correo de una solicitud fallida."""
        if solicitud.estado != ESTADO_CORREO_FALLIDO:
            return True
        if solicitud.intentos_envio >= max_reintentos:
            log.warning("Solicitud %s agotó sus reintentos.", solicitud.codigo)
            return False

        solicitud.intentos_envio += 1
        self._enviar_confirmacion(solicitud)
        self._repo.actualizar_estado(
            solicitud.codigo, solicitud.estado, solicitud.intentos_envio, solicitud.ultimo_error
        )
        return solicitud.estado == ESTADO_CORREO_ENVIADO

    def listar_pendientes(self) -> list[Solicitud]:
        return self._repo.listar_pendientes()

    def _fmt_fecha(self, solicitud: Solicitud) -> str:
        return (
            solicitud.fecha.strftime("%d/%m/%Y %H:%M")
            if solicitud.fecha is not None
            else datetime.now(TZ).strftime("%d/%m/%Y %H:%M")
        )
