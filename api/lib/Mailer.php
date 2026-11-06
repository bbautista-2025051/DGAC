<?php

declare(strict_types=1);

namespace Dgac\Drones;

use RuntimeException;

/**
 * Envío del comprobante por correo.
 *
 * Se implementa un cliente SMTP mínimo (sin Composer) para poder conectarse al
 * servidor de correo que ya use la institución. Si la aplicación existente
 * expone un servicio de correo (por ejemplo, un Mailer de Laravel/Symfony/Node),
 * sustituya sendRegistro() por una llamada a ese servicio y conserve la firma.
 */
final class Mailer
{
    private string $transport;
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $encryption;
    private string $from;
    private string $fromName;
    private string $replyTo;
    private int $timeout;

    public function __construct()
    {
        $this->transport = strtolower(env_value('MAIL_TRANSPORT', 'smtp') ?? 'smtp');
        $this->host = env_value('MAIL_HOST', '') ?? '';
        $this->port = env_int('MAIL_PORT', 587, 1, 65535);
        $this->username = env_value('MAIL_USERNAME', '') ?? '';
        $this->password = env_value('MAIL_PASSWORD', '') ?? '';
        $this->encryption = strtolower(env_value('MAIL_ENCRYPTION', 'tls') ?? 'tls');
        $this->from = env_value('MAIL_FROM', '') ?? '';
        $this->fromName = env_value('MAIL_FROM_NAME', 'DGAC - Registro de Drones') ?? 'DGAC - Registro de Drones';
        $this->replyTo = env_value('MAIL_REPLY_TO', '') ?? '';
        $this->timeout = env_int('MAIL_TIMEOUT', 20, 5, 60);

        if (!filter_var($this->from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('MAIL_FROM no está configurado con un correo válido.');
        }
    }

    /**
     * @param array<string, mixed> $registro
     */
    public function sendRegistro(array $registro, string $pdfBytes, string $filename): void
    {
        $recipient = (string) ($registro['correo_contacto'] ?? '');
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El correo del propietario no es válido.');
        }

        $codigo = (string) ($registro['codigo'] ?? '');
        $subject = 'Registro de dron ' . $codigo . ' · Comprobante DGAC';
        $message = $this->buildMimeMessage($registro, $pdfBytes, $filename);

        log_event('info', 'Enviando comprobante de registro.', [
            'codigo' => $codigo,
            'destinatario' => mask_email($recipient),
            'transporte' => $this->transport,
        ]);

        if ($this->transport === 'mail') {
            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: multipart/mixed; boundary="' . $this->currentBoundary . '"',
                'From: ' . $this->encodeAddress($this->from, $this->fromName),
                'Reply-To: ' . ($this->replyTo !== '' ? $this->replyTo : $this->from),
                'Date: ' . date('r'),
                'X-Mailer: DGAC-Registro-Drones',
            ];

            $ok = @mail(
                $recipient,
                $this->encodeHeader($subject),
                $message,
                implode("\r\n", $headers),
                '-f' . $this->from
            );

            if (!$ok) {
                throw new RuntimeException('La función mail() no pudo entregar el mensaje. Revise el MTA del servidor.');
            }

            return;
        }

        if ($this->transport !== 'smtp') {
            throw new RuntimeException('MAIL_TRANSPORT debe ser "smtp" o "mail".');
        }

        if ($this->host === '') {
            throw new RuntimeException('MAIL_HOST no está configurado.');
        }

        $this->sendSmtp($recipient, $subject, $message);
    }

    /**
     * Mensaje MIME completo (sin cabeceras de sobre) para SMTP o mail().
     *
     * @param array<string, mixed> $registro
     */
    private function buildMimeMessage(array $registro, string $pdfBytes, string $filename): string
    {
        $boundary = $this->boundary();
        $altBoundary = $boundary . '-alt';
        $codigo = (string) ($registro['codigo'] ?? '');
        $nombre = (string) ($registro['nombre_propietario'] ?? '');
        $marca = (string) ($registro['marca_dron'] ?? '');
        $modelo = (string) ($registro['modelo'] ?? '');
        $serie = (string) ($registro['numero_serie'] ?? '');
        $fecha = (string) ($registro['fecha_registro'] ?? '');

        $plain = <<<TEXT
DGAC · Registro de Drones
República de Guatemala

Estimado(a) {$nombre}:

Su registro de dron se completó correctamente.

Código único: {$codigo}
Fecha de registro: {$fecha}
Marca: {$marca}
Modelo: {$modelo}
Número de serie: {$serie}

Adjuntamos el comprobante de registro en formato PDF. Conserve este documento.

Si usted no realizó este registro, comuníquese con la Dirección General de
Aeronáutica Civil (DGAC).

Este mensaje fue generado automáticamente; no responda a este correo.
TEXT;

        $html = $this->buildHtml($registro);

        $message = '--' . $boundary . "\r\n";
        $message .= 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"' . "\r\n\r\n";

        $message .= '--' . $altBoundary . "\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($plain), 76, "\r\n") . "\r\n";

        $message .= '--' . $altBoundary . "\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($html), 76, "\r\n") . "\r\n";

        $message .= '--' . $altBoundary . "--\r\n\r\n";

        $message .= '--' . $boundary . "\r\n";
        $message .= 'Content-Type: application/pdf; name="' . $this->escapeMime($filename) . '"' . "\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= 'Content-Disposition: attachment; filename="' . $this->escapeMime($filename) . '"' . "\r\n\r\n";
        $message .= chunk_split(base64_encode($pdfBytes), 76, "\r\n") . "\r\n";
        $message .= '--' . $boundary . "--\r\n";

        return $message;
    }

    /**
     * @param array<string, mixed> $registro
     */
    private function buildHtml(array $registro): string
    {
        $codigo = htmlspecialchars((string) ($registro['codigo'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $nombre = htmlspecialchars((string) ($registro['nombre_propietario'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $marca = htmlspecialchars((string) ($registro['marca_dron'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $modelo = htmlspecialchars((string) ($registro['modelo'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $serie = htmlspecialchars((string) ($registro['numero_serie'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fecha = htmlspecialchars((string) ($registro['fecha_registro'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $correo = htmlspecialchars((string) ($registro['correo_contacto'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!doctype html>
<html lang="es">
<head><meta charset="UTF-8"><title>Comprobante de registro</title></head>
<body style="margin:0;padding:0;background:#eef3fa;font-family:Arial,Helvetica,sans-serif;color:#172033;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef3fa;padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #d9e1ee;">
        <tr>
          <td style="background:#0b3b8c;padding:24px 32px;color:#ffffff;">
            <div style="font-size:11px;letter-spacing:1px;color:#dbe7ff;">DIRECCIÓN GENERAL DE AERONÁUTICA CIVIL</div>
            <div style="font-size:22px;font-weight:bold;margin-top:6px;">Registro de Drones</div>
            <div style="font-size:13px;color:#dbe7ff;margin-top:4px;">Comprobante de registro</div>
          </td>
        </tr>
        <tr><td style="height:4px;background:#c9a227;font-size:0;">&nbsp;</td></tr>
        <tr>
          <td style="padding:28px 32px 8px 32px;">
            <p style="margin:0 0 14px 0;font-size:15px;">Estimado(a) <strong>{$nombre}</strong>:</p>
            <p style="margin:0 0 18px 0;font-size:14px;line-height:1.55;color:#3a465a;">
              Su registro de dron se completó correctamente. Adjuntamos el comprobante en formato PDF.
              Conserve este documento para futuras consultas.
            </p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f7fd;border-left:4px solid #1f6fe5;">
              <tr><td style="padding:16px 18px;">
                <div style="font-size:11px;color:#5b667a;text-transform:uppercase;letter-spacing:.5px;">Código único de registro</div>
                <div style="font-size:20px;font-weight:bold;color:#0b3b8c;margin-top:4px;">{$codigo}</div>
                <div style="font-size:12px;color:#5b667a;margin-top:8px;">Fecha de registro: {$fecha}</div>
              </td></tr>
            </table>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:18px;font-size:13px;border-collapse:collapse;">
              <tr><td style="padding:8px 0;color:#5b667a;width:150px;">Marca</td><td style="padding:8px 0;">{$marca}</td></tr>
              <tr><td style="padding:8px 0;color:#5b667a;border-top:1px solid #e6ecf5;">Modelo</td><td style="padding:8px 0;border-top:1px solid #e6ecf5;">{$modelo}</td></tr>
              <tr><td style="padding:8px 0;color:#5b667a;border-top:1px solid #e6ecf5;">Número de serie</td><td style="padding:8px 0;border-top:1px solid #e6ecf5;">{$serie}</td></tr>
              <tr><td style="padding:8px 0;color:#5b667a;border-top:1px solid #e6ecf5;">Correo de contacto</td><td style="padding:8px 0;border-top:1px solid #e6ecf5;">{$correo}</td></tr>
            </table>
            <p style="margin:22px 0 0 0;font-size:12px;line-height:1.55;color:#5b667a;">
              Si usted no realizó este registro, comuníquese con la Dirección General de Aeronáutica Civil (DGAC).
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:20px 32px 26px 32px;border-top:1px solid #e6ecf5;font-size:11px;color:#8a94a6;">
            Dirección General de Aeronáutica Civil · Guatemala<br>
            Este mensaje fue generado automáticamente; no responda a este correo.
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    private function sendSmtp(string $recipient, string $subject, string $message): void
    {
        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $remote = $scheme . '://' . $this->host . ':' . $this->port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException(sprintf('No se pudo conectar al servidor SMTP (%s): %s', $remote, $errstr ?: 'error de red'));
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            $this->expect($this->readResponse($socket), [220], 'saludo inicial');

            $hostname = gethostname() ?: 'localhost';
            $this->command($socket, 'EHLO ' . $hostname, [250], 'EHLO');
            $ehlo = $this->lastResponse;

            if ($this->encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220], 'STARTTLS');
                $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (!@stream_socket_enable_crypto($socket, true, $crypto)) {
                    throw new RuntimeException('No se pudo iniciar TLS con el servidor SMTP.');
                }
                $this->command($socket, 'EHLO ' . $hostname, [250], 'EHLO después de TLS');
                $ehlo = $this->lastResponse;
            }

            if ($this->username !== '') {
                if (stripos($ehlo, 'AUTH') !== false && stripos($ehlo, 'PLAIN') !== false && stripos($ehlo, 'LOGIN') === false) {
                    $token = base64_encode("\0" . $this->username . "\0" . $this->password);
                    $this->command($socket, 'AUTH PLAIN ' . $token, [235], 'AUTH PLAIN');
                } else {
                    $this->command($socket, 'AUTH LOGIN', [334], 'AUTH LOGIN');
                    $this->command($socket, base64_encode($this->username), [334], 'usuario SMTP');
                    $this->command($socket, base64_encode($this->password), [235], 'contraseña SMTP');
                }
            }

            $this->command($socket, 'MAIL FROM:<' . $this->from . '> BODY=8BITMIME', [250], 'MAIL FROM');
            $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251], 'RCPT TO');
            $this->command($socket, 'DATA', [354], 'DATA');

            $headers = [
                'Date: ' . date('r'),
                'From: ' . $this->encodeAddress($this->from, $this->fromName),
                'To: <' . $recipient . '>',
                'Reply-To: ' . ($this->replyTo !== '' ? $this->replyTo : $this->from),
                'Subject: ' . $this->encodeHeader($subject),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->domainFromEmail($this->from) . '>',
                'MIME-Version: 1.0',
                'Content-Type: multipart/mixed; boundary="' . $this->currentBoundary . '"',
                'X-Mailer: DGAC-Registro-Drones',
            ];

            $data = implode("\r\n", $headers) . "\r\n\r\n" . $message;
            $data = preg_replace('/^\./m', '..', $data) ?? $data;
            fwrite($socket, $data . "\r\n.\r\n");
            $this->expect($this->readResponse($socket), [250], 'envío del mensaje');
            $this->command($socket, 'QUIT', [221], 'QUIT');
        } finally {
            fclose($socket);
        }
    }

    private string $lastResponse = '';
    private string $currentBoundary = '';

    /**
     * @param resource $socket
     */
    private function command($socket, string $command, array $expected, string $label): void
    {
        fwrite($socket, $command . "\r\n");
        $this->expect($this->readResponse($socket), $expected, $label);
    }

    /**
     * @param resource $socket
     */
    private function readResponse($socket): string
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
            if (strlen($line) < 4 && str_ends_with($line, "\n")) {
                break;
            }
        }

        $this->lastResponse = $response;

        if ($response === '') {
            throw new RuntimeException('El servidor SMTP cerró la conexión inesperadamente.');
        }

        return $response;
    }

    /**
     * @param list<int> $expected
     */
    private function expect(string $response, array $expected, string $label): void
    {
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            $clean = trim(preg_replace('/\s+/', ' ', $response) ?? $response);
            throw new RuntimeException(sprintf('SMTP rechazó %s (%d): %s', $label, $code, $clean));
        }
    }

    private function boundary(): string
    {
        $this->currentBoundary = '=_dgac_' . bin2hex(random_bytes(12));

        return $this->currentBoundary;
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function encodeAddress(string $email, string $name): string
    {
        return $this->encodeHeader($name) . ' <' . $email . '>';
    }

    private function domainFromEmail(string $email): string
    {
        $parts = explode('@', $email, 2);

        return $parts[1] ?? 'localhost';
    }

    private function escapeMime(string $value): string
    {
        return str_replace(["\r", "\n", '"'], ['', '', "'"], $value);
    }
}
