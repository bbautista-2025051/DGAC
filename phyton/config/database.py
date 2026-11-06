# =============================================================================
# DGAC · Registro de Drones — config/database.py
#
# NOTA: la conexión a MariaDB la implementa el responsable de la base de
# datos (tabla `registro_drones`, migración sql/001_create_registro_drones.sql).
# Este módulo solo expone la interfaz que consumirá el resto del proyecto,
# para que al conectar la BD no haya que tocar services/ ni worker/.
#
# El objetivo, cuando se implemente, es:
#   obtener_conexion() -> conexión a la base `dgac` (MariaDB)
#
# Dependencia sugerida:  mariadb  (o pymysql como alternativa pura Python)
# =============================================================================

import os
from pathlib import Path

from dotenv import load_dotenv

BASE_DIR = Path(__file__).resolve().parents[1]


def _cargar_entorno() -> None:
    """Carga las variables del archivo .env (una sola vez)."""
    load_dotenv(BASE_DIR / ".env")


def obtener_config_bd() -> dict:
    """
    Devuelve la configuración de la base de datos leída del archivo .env.

    Ya queda alineada con el .env.example del proyecto (MariaDB):
    DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_CHARSET.
    """
    _cargar_entorno()
    return {
        "host": os.getenv("DB_HOST", "127.0.0.1"),
        "port": int(os.getenv("DB_PORT", "3306")),
        "database": os.getenv("DB_DATABASE", "dgac"),
        "user": os.getenv("DB_USERNAME", "dgac_drones"),
        "password": os.getenv("DB_PASSWORD", ""),
        "charset": os.getenv("DB_CHARSET", "utf8mb4"),
    }


def obtener_conexion():
    """
    Devuelve una conexión activa a MariaDB.

    PENDIENTE — implementación del responsable de la base de datos.
    Sugerencia con mariadb:

        import mariadb
        cfg = obtener_config_bd()
        return mariadb.connect(
            host=cfg["host"], port=cfg["port"], database=cfg["database"],
            user=cfg["user"], password=cfg["password"], charset=cfg["charset"],
        )
    """
    raise NotImplementedError(
        "La conexión a MariaDB la implementa el responsable de la base de datos."
    )
