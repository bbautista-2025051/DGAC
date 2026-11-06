<?php

declare(strict_types=1);

namespace Dgac\Drones;

/**
 * Generador de PDF sin dependencias externas.
 *
 * Produce un comprobante A4 de una página con las fuentes estándar Helvetica y
 * Helvetica-Bold (WinAnsi), suficiente para los caracteres del español. Se
 * mantiene deliberadamente pequeño para no añadir librerías al proyecto
 * existente. Si la aplicación ya cuenta con un generador de PDF, RegistroService
 * puede sustituir esta clase sin cambiar el contrato del endpoint.
 */
final class PdfDocument
{
    private const PAGE_WIDTH = 595.28;
    private const PAGE_HEIGHT = 841.89;

    /** @var list<string> */
    private array $content = [];

    /**
     * @param array<string, mixed> $registro
     */
    public function buildRegistro(array $registro, string $appUrl = ''): string
    {
        $this->content = [];

        $primary = '#0b3b8c';
        $accent = '#1f6fe5';
        $gold = '#c9a227';
        $ink = '#172033';
        $muted = '#5b667a';
        $line = '#d9e1ee';
        $soft = '#f3f7fd';
        $white = '#ffffff';

        $codigo = (string) ($registro['codigo'] ?? '');
        $marca = (string) ($registro['marca_dron'] ?? '');
        $modelo = (string) ($registro['modelo'] ?? '');
        $serie = (string) ($registro['numero_serie'] ?? '');
        $nombre = (string) ($registro['nombre_propietario'] ?? '');
        $correo = (string) ($registro['correo_contacto'] ?? '');
        $fecha = (string) ($registro['fecha_registro'] ?? date('Y-m-d H:i:s'));
        $estado = (string) ($registro['estado_registro'] ?? 'REGISTRADO');

        $fechaTexto = $this->formatDate($fecha);

        // ---------------------------------------------------------------------
        // Encabezado institucional
        // ---------------------------------------------------------------------
        $this->fillRect(0, 0, self::PAGE_WIDTH, 112, $primary);
        $this->fillRect(0, 112, self::PAGE_WIDTH, 4, $gold);

        $this->text(48, 38, 9, true, 'DIRECCIÓN GENERAL DE AERONÁUTICA CIVIL', '#dbe7ff');
        $this->text(48, 57, 15, true, 'República de Guatemala', $white);
        $this->text(48, 80, 9, false, 'Registro de aeronaves pilotadas a distancia (RPA)', '#c7d8f7');
        $this->text(48, 96, 8, false, 'www.dgac.gob.gt', '#c7d8f7');

        $this->textRight(547, 50, 21, true, 'REGISTRO DE DRONES', $white);
        $this->textRight(547, 70, 10, false, 'Comprobante de registro', '#dbe7ff');
        $this->textRight(547, 88, 9, true, 'DGAC · GUATEMALA', $gold);

        // ---------------------------------------------------------------------
        // Título del documento
        // ---------------------------------------------------------------------
        $this->textCentered(152, 16, true, 'CONSTANCIA DE REGISTRO', $primary);
        $this->textCentered(170, 10, false, 'Aeronave pilotada a distancia (RPA / Dron)', $muted);
        $this->strokeLine(48, 184, 547, 184, $line, 1);

        // ---------------------------------------------------------------------
        // Caja de código y fecha
        // ---------------------------------------------------------------------
        $this->fillRect(48, 198, 499, 64, $soft);
        $this->fillRect(48, 198, 5, 64, $accent);

        $this->text(66, 218, 8, true, 'CÓDIGO ÚNICO DE REGISTRO', $muted);
        $this->text(66, 241, 15, true, $codigo, $primary);

        $this->textRight(531, 218, 8, true, 'FECHA DE REGISTRO', $muted);
        $this->textRight(531, 241, 10, false, $fechaTexto, $ink);

        // ---------------------------------------------------------------------
        // Tabla: información del dron
        // ---------------------------------------------------------------------
        $nextTop = $this->drawSection('INFORMACIÓN DEL DRON', 286, $accent, $primary, $line);
        $nextTop = $this->drawTable([
            ['Marca del dron', $marca],
            ['Modelo', $modelo],
            ['Número de serie', $serie],
        ], $nextTop, $ink, $muted, $line);

        // ---------------------------------------------------------------------
        // Tabla: información del propietario
        // ---------------------------------------------------------------------
        $nextTop = $this->drawSection('INFORMACIÓN DEL PROPIETARIO', $nextTop + 24, $accent, $primary, $line);
        $nextTop = $this->drawTable([
            ['Nombre completo', $nombre],
            ['Correo electrónico', $correo],
        ], $nextTop, $ink, $muted, $line);

        // ---------------------------------------------------------------------
        // Estado del registro
        // ---------------------------------------------------------------------
        $nextTop = $this->drawSection('ESTADO DEL REGISTRO', $nextTop + 24, $accent, $primary, $line);

        $statusMap = [
            'CORREO_ENVIADO' => ['CORREO ENVIADO', '#157347', '#e7f5ee', 'El comprobante fue enviado al correo electrónico registrado.'],
            'CORREO_FALLIDO' => ['ENVÍO PENDIENTE', '#9a5b00', '#fff4df', 'El registro está guardado. El envío del correo quedó pendiente y puede reintentarse.'],
            'REGISTRADO' => ['REGISTRADO', '#0b3b8c', '#eaf1ff', 'El registro fue almacenado correctamente en el sistema.'],
        ];
        [$statusLabel, $statusColor, $statusBg, $statusDescription] = $statusMap[$estado] ?? $statusMap['REGISTRADO'];

        $pillWidth = max(150, $this->textWidth($statusLabel, 9, true) + 28);
        $this->fillRect(48, $nextTop + 4, $pillWidth, 26, $statusBg);
        $this->text(60, $nextTop + 21, 9, true, $statusLabel, $statusColor);
        $this->wrapText($statusDescription, 531 - 220, 9, false, 220, $nextTop + 18, $muted, 13);
        $nextTop += 46;

        // ---------------------------------------------------------------------
        // Nota de validez
        // ---------------------------------------------------------------------
        $notesTop = max($nextTop + 26, 604);
        $this->fillRect(48, $notesTop, 499, 92, $soft);
        $this->fillRect(48, $notesTop, 4, 92, $gold);

        $this->text(64, $notesTop + 21, 9, true, 'Este comprobante acredita el registro del dron en el sistema de la DGAC.', $ink);
        $this->text(64, $notesTop + 39, 8.5, false, 'Conserve este documento. La información registrada es responsabilidad del propietario.', $muted);
        $this->text(64, $notesTop + 55, 8.5, false, 'Verifique la autenticidad con el código único de registro: ' . $codigo, $muted);
        $this->text(64, $notesTop + 71, 8, false, 'Documento generado electrónicamente; no requiere firma manuscrita.', $muted);
        $this->text(64, $notesTop + 85, 8, false, 'Emitido el ' . $fechaTexto . '.', $muted);

        // ---------------------------------------------------------------------
        // Pie
        // ---------------------------------------------------------------------
        $this->strokeLine(48, 766, 547, 766, $line, 1);
        $this->text(48, 782, 8, false, 'Dirección General de Aeronáutica Civil · Guatemala', $muted);
        $this->textRight(547, 782, 8, false, 'Página 1 de 1', $muted);
        $footerUrl = $appUrl !== '' ? $appUrl : 'www.dgac.gob.gt';
        $this->text(48, 796, 8, false, 'Comprobante generado desde ' . $footerUrl, '#8a94a6');
        $this->textRight(547, 796, 8, false, 'Código: ' . $codigo, '#8a94a6');

        return $this->output($registro, $codigo);
    }

    /**
     * @return float Siguiente posición superior disponible.
     */
    private function drawSection(string $title, float $top, string $accent, string $primary, string $line): float
    {
        $this->fillRect(48, $top - 9, 4, 12, $accent);
        $this->text(58, $top, 10.5, true, $title, $primary);
        $this->strokeLine(48, $top + 8, 547, $top + 8, $line, 0.8);

        return $top + 16;
    }

    /**
     * @param list<array{0:string,1:string}> $rows
     */
    private function drawTable(array $rows, float $top, string $ink, string $muted, string $line): float
    {
        $current = $top;
        $index = 0;

        foreach ($rows as [$label, $value]) {
            $lines = $this->wrapLines($value, 300, 10, false);
            if ($lines === []) {
                $lines = [''];
            }

            $rowHeight = max(32, 10 + (count($lines) - 1) * 12 + 20);

            if ($index % 2 === 1) {
                $this->fillRect(48, $current, 499, $rowHeight, '#fbfcff');
            }

            $this->text(60, $current + 19, 9, false, $label, $muted);

            $valueTop = $current + 19;
            foreach ($lines as $i => $lineText) {
                $this->text(205, $valueTop + ($i * 12), 10, false, $lineText, $ink);
            }

            $this->strokeLine(48, $current + $rowHeight, 547, $current + $rowHeight, $line, 0.6);
            $current += $rowHeight;
            $index++;
        }

        return $current;
    }

    private function formatDate(string $fecha): string
    {
        $timestamp = strtotime($fecha);
        if ($timestamp === false) {
            $timestamp = time();
        }

        $meses = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
            5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
            9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ];

        $dia = (int) date('j', $timestamp);
        $mes = $meses[(int) date('n', $timestamp)] ?? '';
        $anio = date('Y', $timestamp);
        $hora = date('H:i', $timestamp);

        return sprintf('%d de %s de %s, %s horas (GMT-6)', $dia, $mes, $anio, $hora);
    }

    /* ---------------------------------------------------------------------- */
    /* Primitivas de dibujo                                                    */
    /* ---------------------------------------------------------------------- */

    private function fillRect(float $x, float $top, float $width, float $height, string $hex): void
    {
        [$r, $g, $b] = $this->rgb($hex);
        $y = self::PAGE_HEIGHT - $top - $height;
        $this->content[] = sprintf(
            '%s %s %s rg %s %s %s %s re f',
            $this->num($r),
            $this->num($g),
            $this->num($b),
            $this->num($x),
            $this->num($y),
            $this->num($width),
            $this->num($height)
        );
    }

    private function strokeLine(float $x1, float $top1, float $x2, float $top2, string $hex, float $width = 1.0): void
    {
        [$r, $g, $b] = $this->rgb($hex);
        $this->content[] = sprintf(
            '%s %s %s RG %s w %s %s m %s %s l S',
            $this->num($r),
            $this->num($g),
            $this->num($b),
            $this->num($width),
            $this->num($x1),
            $this->num(self::PAGE_HEIGHT - $top1),
            $this->num($x2),
            $this->num(self::PAGE_HEIGHT - $top2)
        );
    }

    private function text(float $x, float $baselineTop, float $size, bool $bold, string $text, string $hex): void
    {
        if ($text === '') {
            return;
        }

        [$r, $g, $b] = $this->rgb($hex);
        $font = $bold ? 'F2' : 'F1';
        $y = self::PAGE_HEIGHT - $baselineTop;

        $this->content[] = sprintf(
            'BT /%s %s Tf %s %s %s rg 1 0 0 1 %s %s Tm (%s) Tj ET',
            $font,
            $this->num($size),
            $this->num($r),
            $this->num($g),
            $this->num($b),
            $this->num($x),
            $this->num($y),
            $this->escape($text)
        );
    }

    private function textRight(float $right, float $baselineTop, float $size, bool $bold, string $text, string $hex): void
    {
        $x = $right - $this->textWidth($text, $size, $bold);
        $this->text($x, $baselineTop, $size, $bold, $text, $hex);
    }

    private function textCentered(float $baselineTop, float $size, bool $bold, string $text, string $hex): void
    {
        $x = (self::PAGE_WIDTH - $this->textWidth($text, $size, $bold)) / 2;
        $this->text($x, $baselineTop, $size, $bold, $text, $hex);
    }

    /**
     * @return list<string>
     */
    private function wrapLines(string $text, float $maxWidth, float $size, bool $bold): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($this->textWidth($candidate, $size, $bold) <= $maxWidth) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
                $current = '';
            }

            if ($this->textWidth($word, $size, $bold) <= $maxWidth) {
                $current = $word;
                continue;
            }

            // Palabra más ancha que la columna: se parte por caracteres.
            $chunk = '';
            foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
                if ($this->textWidth($chunk . $char, $size, $bold) > $maxWidth && $chunk !== '') {
                    $lines[] = $chunk;
                    $chunk = $char;
                } else {
                    $chunk .= $char;
                }
            }
            $current = $chunk;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private function wrapText(
        string $text,
        float $maxWidth,
        float $size,
        bool $bold,
        float $x,
        float $firstBaseline,
        string $hex,
        float $lineHeight = 12
    ): void {
        foreach ($this->wrapLines($text, $maxWidth, $size, $bold) as $i => $line) {
            $this->text($x, $firstBaseline + ($i * $lineHeight), $size, $bold, $line, $hex);
        }
    }

    private function textWidth(string $text, float $size, bool $bold): float
    {
        $normalized = strtr($text, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
            'Â' => 'A', 'Ê' => 'E', 'Î' => 'I', 'Ô' => 'O', 'Û' => 'U',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ÿ' => 'y',
            'Ä' => 'A', 'Ë' => 'E', 'Ï' => 'I', 'Ö' => 'O', 'Ÿ' => 'Y',
            'ç' => 'c', 'Ç' => 'C', 'ã' => 'a', 'õ' => 'o', 'Ã' => 'A', 'Õ' => 'O',
        ]);

        $width = 0.0;
        foreach (preg_split('//u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $ord = mb_ord($char, 'UTF-8');
            $width += $this->charWidth($ord ?: 63, $bold) / 1000 * $size;
        }

        return $width;
    }

    private function charWidth(int $code, bool $bold): int
    {
        $regular = [
            32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667, 39 => 191,
            40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
            58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584, 63 => 556, 64 => 1015,
            65 => 667, 66 => 667, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778, 72 => 722,
            73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778, 80 => 667,
            81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944, 88 => 667,
            89 => 667, 90 => 611, 91 => 278, 92 => 278, 93 => 278, 94 => 469, 95 => 556, 96 => 333,
            97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556, 104 => 556,
            105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556, 111 => 556,
            112 => 556, 113 => 556, 114 => 333, 115 => 500, 116 => 278, 117 => 556, 118 => 500,
            119 => 722, 120 => 500, 121 => 500, 122 => 500, 123 => 334, 124 => 260, 125 => 334, 126 => 584,
        ];

        $boldMap = [
            32 => 278, 33 => 333, 34 => 474, 35 => 556, 36 => 556, 37 => 889, 38 => 722, 39 => 238,
            40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
            58 => 333, 59 => 333, 60 => 584, 61 => 584, 62 => 584, 63 => 611, 64 => 975,
            65 => 722, 66 => 722, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778, 72 => 722,
            73 => 278, 74 => 556, 75 => 722, 76 => 611, 77 => 833, 78 => 722, 79 => 778, 80 => 667,
            81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944, 88 => 667,
            89 => 667, 90 => 611, 91 => 333, 92 => 278, 93 => 333, 94 => 584, 95 => 556, 96 => 333,
            97 => 556, 98 => 611, 99 => 556, 100 => 611, 101 => 556, 102 => 333, 103 => 611, 104 => 611,
            105 => 278, 106 => 278, 107 => 556, 108 => 278, 109 => 889, 110 => 611, 111 => 611,
            112 => 611, 113 => 611, 114 => 389, 115 => 556, 116 => 333, 117 => 611, 118 => 556,
            119 => 778, 120 => 556, 121 => 556, 122 => 500, 123 => 389, 124 => 280, 125 => 389, 126 => 584,
        ];

        if ($code >= 48 && $code <= 57) {
            return 556;
        }

        return $bold ? ($boldMap[$code] ?? 556) : ($regular[$code] ?? 556);
    }

    /**
     * @return array{0:float,1:float,2:float}
     */
    private function rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            $hex = '000000';
        }

        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    private function escape(string $text): string
    {
        $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($encoded === false) {
            $encoded = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
        }

        return str_replace(
            ["\\", '(', ')', "\r", "\n"],
            ["\\\\", '\\(', '\\)', ' ', ' '],
            $encoded
        );
    }

    /**
     * @param array<string, mixed> $registro
     */
    private function output(array $registro, string $codigo): string
    {
        $content = implode("\n", $this->content) . "\n";

        $creation = date('YmdHis');
        $offset = date('P');
        $pdfOffset = substr($offset, 0, 3) . "'" . substr($offset, 4, 2) . "'";
        $date = 'D:' . $creation . $pdfOffset;

        $title = 'Comprobante de registro de dron ' . $codigo;
        $author = 'Dirección General de Aeronáutica Civil';

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT . '] '
                . '/Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            6 => '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream",
            7 => '<< /Title (' . $this->escape($title) . ') /Author (' . $this->escape($author) . ') '
                . '/Subject (Registro de drones) /Creator (DGAC Registro de Drones) '
                . '/Producer (DGAC Registro de Drones v' . DGAC_MODULE_VERSION . ') '
                . '/CreationDate (' . $date . ') >>',
        ];

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $count = count($objects);
        $pdf .= 'xref' . "\n" . '0 ' . ($count + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        foreach (array_keys($objects) as $number) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }
        $pdf .= 'trailer' . "\n" . '<< /Size ' . ($count + 1) . ' /Root 1 0 R /Info 7 0 R >>' . "\n";
        $pdf .= 'startxref' . "\n" . $xref . "\n%%EOF";

        return $pdf;
    }
}
