# =============================================================================
# DGAC · Registro de Drones — services/email_service.py
#
# Servicio de correo electrónico: envía el comprobante de registro por
# SMTP, renderizando la plantilla HTML (templates/confirmacion.html)
# y adjuntando el PDF del comprobante cuando así se configura.
#
# Reutiliza el mismo servidor/configuración que la aplicación PHP
# (variables MAIL_* del archivo .env, ver .env.example).
# =============================================================================

import logging
import mimetypes
import os
import re
import smtplib
import ssl
from email.message import EmailMessage
from email.utils import formataddr
from pathlib import Path
from typing import Any

from dotenv import load_dotenv
from jinja2 import Environment, FileSystemLoader, select_autoescape

BASE_DIR = Path(__file__).resolve().parents[1]

log = logging.getLogger(__name__)

_EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")


class EmailError(Exception):
    """Error al preparar o enviar el correo de confirmación."""


def correo_valido(correo: str) -> bool:
    """Valida el formato básico de una dirección de correo."""
    return bool(_EMAIL_RE.match((correo or "").strip()))


class EmailService:
    """Envío de correos SMTP con plantilla HTML y adjunto opcional."""

    def __init__(self) -> None:
        load_dotenv(BASE_DIR / ".env")

        self.host = os.getenv("MAIL_HOST", "")
        self.port = int(os.getenv("MAIL_PORT", "587"))
        self.usuario = os.getenv("MAIL_USERNAME", "")
        self.password = os.getenv("MAIL_PASSWORD", "")
        self.cifrado = os.getenv("MAIL_ENCRYPTION", "tls").lower()
        self.remitente = os.getenv("MAIL_FROM", self.usuario)
        self.nombre_remitente = os.getenv("MAIL_FROM_NAME", "DGAC")
        self.reply_to = os.getenv("MAIL_REPLY_TO", "")
        self.timeout = int(os.getenv("MAIL_TIMEOUT", "20"))
        self.adjuntar_pdf = os.getenv("MAIL_ATTACH_PDF", "1") == "1"

        self._jinja = Environment(
            loader=FileSystemLoader(str(BASE_DIR / "templates")),
            autoescape=select_autoescape(["html"]),
        )

        if not self.host:
            raise EmailError("MAIL_HOST no está configurado (revise el archivo .env).")

    # ---------------------------------------------------------------
    # Plantilla
    # ---------------------------------------------------------------
    def renderizar_confirmacion(self, datos: dict[str, Any]) -> str:
        """Renderiza templates/confirmacion.html con los datos del registro."""
        plantilla = self._jinja.get_template("confirmacion.html")
        contexto = {
            "codigo": datos.get("codigo", ""),
            "fecha": datos.get("fecha", ""),
            "estado": datos.get("estado", "REGISTRADO"),
            "marca_dron": datos.get("marca_dron", ""),
            "modelo": datos.get("modelo", ""),
            "numero_serie": datos.get("numero_serie", ""),
            "nombre_propietario": datos.get("nombre_propietario", ""),
            "correo_contacto": datos.get("correo_contacto", ""),
            "url_pdf": datos.get("url_pdf", ""),
        }
        return plantilla.render(**contexto)

    # ---------------------------------------------------------------
    # Envío
    # ---------------------------------------------------------------
    def enviar(
        self,
        destinatario: str,
        asunto: str,
        contexto_html: dict[str, Any],
        adjunto: str | Path | None = None,
    ) -> bool:
        """
        Envía un correo HTML al destinatario indicado.

        adjunto: ruta de un archivo (p. ej. el comprobante PDF). Opcional.
        Devuelve True si el envío fue aceptado por el servidor.
        Lanza EmailError si no es posible enviar.
        """
        if not correo_valido(destinatario):
            raise EmailError(f"Correo inválido: '{destinatario}'")

        mensaje = EmailMessage()
        mensaje["Subject"] = asunto
        mensaje["From"] = formataddr((self.nombre_remitente, self.remitente))
        mensaje["To"] = destinatario
        if self.reply_to:
            mensaje["Reply-To"] = self.reply_to

        mensaje.set_content(
            "Comprobante de registro DGAC. Active la vista HTML para verlo.",
        )
        mensaje.add_alternative(
            self.renderizar_confirmacion(contexto_html), subtype="html"
        )

        if adjunto and self.adjuntar_pdf:
            self._adjuntar(mensaje, Path(adjunto))

        try:
            with self._conectar() as smtp:
                smtp.send_message(mensaje)
            log.info("Correo enviado a %s (%s)", destinatario, asunto)
            return True
        except (smtplib.SMTPException, ssl.SSLError, OSError) as exc:
            log.error("Fallo el envío a %s: %s", destinatario, exc)
            raise EmailError(f"No se pudo enviar el correo a {destinatario}: {exc}") from exc

    def _adjuntar(self, mensaje: EmailMessage, ruta: Path) -> None:
        """Adjunta un archivo al mensaje (p. ej. el comprobante PDF)."""
        if not ruta.is_file():
            log.warning("Adjunto inexistente, se omite: %s", ruta)
            return
        tipo, _ = mimetypes.guess_type(ruta.name)
        if tipo is None:
            tipo = "application/octet-stream"
        principal, subtipo = tipo.split("/", 1)
        mensaje.add_attachment(
            ruta.read_bytes(),
            maintype=principal,
            subtype=subtipo,
            filename=ruta.name,
        )

    def _conectar(self) -> smtplib.SMTP:
        """Abre la conexión SMTP según el cifrado configurado (tls/ssl/none)."""
        if self.cifrado == "ssl":
            smtp = smtplib.SMTP_SSL(self.host, self.port, timeout=self.timeout)
            smtp.login(self.usuario, self.password)
            return smtp

        smtp = smtplib.SMTP(self.host, self.port, timeout=self.timeout)
        try:
            smtp.ehlo()
            if self.cifrado == "tls":
                smtp.starttls(context=ssl.create_default_context())
                smtp.ehlo()
            if self.usuario and self.password:
                smtp.login(self.usuario, self.password)
            return smtp
        except Exception:
            smtp.quit()
            raise
