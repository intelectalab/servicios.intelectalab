<?php

namespace App\Services;

use GdImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marca de una empresa: logo, colores y datos de contacto para sus documentos (PDF, Excel,
 * correos al cliente y portal).
 *
 * El logo nunca se guarda tal como llegó: se abre con GD, se vuelve a dibujar como PNG en un
 * tamaño acotado (lo que quita metadatos o contenido oculto) y de esa imagen limpia se sacan sus
 * colores principales. Sin marca, todo sigue con el azul de siempre.
 */
class Branding
{
    public const DEFAULT_PRIMARY = '#1B8FC9';

    /** Tamaño máximo del archivo que se recibe y de la imagen que se guarda. */
    public const MAX_UPLOAD_KB = 2048;

    private const MAX_WIDTH = 800;

    private const MAX_HEIGHT = 400;

    /** Datos de marca de la empresa (para la web, el portal y los correos). */
    public static function of(string $tenantId, bool $withLogo = true): array
    {
        $row = DB::table('company_branding')->where('tenant_id', $tenantId)->first();
        $company = DB::table('companies')->where('tenant_id', $tenantId)->orderBy('created_at')
            ->first(['legal_name', 'trade_name', 'tax_identifier', 'address']);
        $tenant = DB::table('tenants')->where('id', $tenantId)->first(['name', 'legal_name']);
        $address = json_decode((string) ($company->address ?? ''), true);
        $address = is_array($address) ? $address : [];
        // Sin datos propios en la marca, el pie usa la dirección y el teléfono registrados de la empresa.
        $fallbackAddress = implode(', ', array_filter([$address['direccion'] ?? null, $address['distrito'] ?? null, $address['departamento'] ?? null]));

        $primary = $row?->primary_color ?? self::DEFAULT_PRIMARY;
        $hasLogo = (bool) ($row?->logo_sha256 ?? null);

        return [
            'name' => $company->legal_name ?? $tenant->legal_name ?? $tenant->name ?? 'Empresa',
            'trade_name' => $company->trade_name ?? $tenant->name ?? null,
            'tax_identifier' => $company->tax_identifier ?? null,
            'logo' => $withLogo && $row?->logo ? 'data:image/png;base64,'.base64_encode(self::bytes($row->logo)) : null,
            'has_logo' => $hasLogo,
            'logo_width' => $row?->logo_width,
            'logo_height' => $row?->logo_height,
            'primary_color' => $primary,
            'secondary_color' => $row?->secondary_color,
            'palette' => json_decode((string) ($row?->palette ?? '[]'), true) ?: [],
            'address' => $row?->address ?: ($fallbackAddress ?: null),
            'phone' => $row?->phone ?: ($address['telefono'] ?? null),
            'email' => $row?->email,
            'website' => $row?->website,
            'customized' => $hasLogo || strtoupper($primary) !== self::DEFAULT_PRIMARY || $row?->secondary_color !== null,
            'updated_at' => $row?->updated_at,
        ];
    }

    /**
     * Marca para los correos al cliente: nombre, logo (bytes) y colores legibles sobre blanco.
     * null si la empresa no definió su marca (el correo sale con la de la plataforma).
     *
     * @return array{name: string, logo: ?string, logo_width: int, accent: string, soft: string}|null
     */
    public static function forMail(string $tenantId): ?array
    {
        $row = DB::table('company_branding')->where('tenant_id', $tenantId)->first(['primary_color', 'logo_width', 'logo_height']);
        if (! $row) {
            return null;
        }
        $brand = self::of($tenantId, false);
        $width = $row->logo_width ? (int) min(200, round(56 * $row->logo_width / max(1, $row->logo_height))) : 0;

        return [
            'name' => $brand['trade_name'] ?: $brand['name'],
            'logo' => self::logoBytes($tenantId),
            'logo_width' => max(48, $width),
            'accent' => self::readable($row->primary_color),
            'soft' => self::tint($row->primary_color, 0.1),
        ];
    }

    /** El color oscurecido lo justo para leerse sobre blanco (contraste 4.5:1), como en la web. */
    public static function readable(string $hex): string
    {
        $rgb = sscanf($hex, '#%02x%02x%02x');
        for ($i = 0; $i < 20 && self::contrastWithWhite($rgb) < 4.5; $i++) {
            $rgb = array_map(fn ($v) => $v * 0.88, $rgb);
        }

        return sprintf('#%02X%02X%02X', ...array_map(fn ($v) => (int) round($v), $rgb));
    }

    /** Un tono suave del color, mezclado con blanco. */
    public static function tint(string $hex, float $amount): string
    {
        $rgb = sscanf($hex, '#%02x%02x%02x');

        return sprintf('#%02X%02X%02X', ...array_map(fn ($v) => (int) round(255 - (255 - $v) * $amount), $rgb));
    }

    private static function contrastWithWhite(array $rgb): float
    {
        $lin = function (float $v): float {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $lin($rgb[0]) + 0.7152 * $lin($rgb[1]) + 0.0722 * $lin($rgb[2]);

        return 1.05 / ($l + 0.05);
    }

    /** Los bytes del logo limpio (para incrustarlo en un correo), o null. */
    public static function logoBytes(string $tenantId): ?string
    {
        $logo = DB::table('company_branding')->where('tenant_id', $tenantId)->value('logo');

        return $logo === null ? null : self::bytes($logo);
    }

    /**
     * Vuelve a dibujar la imagen recibida como PNG acotado y devuelve [png, ancho, alto, paleta].
     *
     * @return array{0: string, 1: int, 2: int, 3: string[]}
     */
    public static function cleanLogo(string $raw): array
    {
        $info = @getimagesizefromstring($raw);
        if (! $info || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages(['logo' => ['El logo debe ser una imagen PNG, JPG o WEBP.']]);
        }
        if ($info[0] < 16 || $info[1] < 16 || $info[0] > 6000 || $info[1] > 6000) {
            throw ValidationException::withMessages(['logo' => ['La imagen debe medir entre 16 y 6000 píxeles por lado.']]);
        }
        $source = @imagecreatefromstring($raw);
        if (! $source instanceof GdImage) {
            throw ValidationException::withMessages(['logo' => ['No se pudo leer la imagen.']]);
        }

        [$width, $height] = [imagesx($source), imagesy($source)];
        $scale = min(1, self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height);
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $clean = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($clean, false);
        imagesavealpha($clean, true);
        imagefill($clean, 0, 0, imagecolorallocatealpha($clean, 0, 0, 0, 127));
        imagecopyresampled($clean, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $palette = self::palette($clean);

        ob_start();
        imagepng($clean, null, 9);
        $png = (string) ob_get_clean();

        if (strlen($png) > 524288) {
            throw ValidationException::withMessages(['logo' => ['El logo es demasiado pesado aun reducido. Prueba con una imagen más simple.']]);
        }

        return [$png, $newWidth, $newHeight, $palette];
    }

    /**
     * Colores principales del logo, del más representativo al menos: se cuentan los píxeles
     * visibles por grupos de color, se descartan el blanco y el fondo transparente, y los grises
     * pesan menos que los colores con tono (el color de una marca rara vez es gris).
     *
     * @return string[] hexadecimales #RRGGBB
     */
    public static function palette(GdImage $image): array
    {
        $sample = imagecreatetruecolor(64, 64);
        imagealphablending($sample, false);
        imagesavealpha($sample, true);
        imagefill($sample, 0, 0, imagecolorallocatealpha($sample, 0, 0, 0, 127));
        imagecopyresampled($sample, $image, 0, 0, 0, 0, 64, 64, imagesx($image), imagesy($image));

        $buckets = [];
        for ($x = 0; $x < 64; $x++) {
            for ($y = 0; $y < 64; $y++) {
                $color = imagecolorat($sample, $x, $y);
                $alpha = ($color >> 24) & 0x7F;
                if ($alpha > 80) {
                    continue;
                }
                [$r, $g, $b] = [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
                if (min($r, $g, $b) > 235) {
                    continue;
                }
                $key = ($r >> 4).'-'.($g >> 4).'-'.($b >> 4);
                $buckets[$key] ??= ['n' => 0, 'r' => 0, 'g' => 0, 'b' => 0];
                $buckets[$key]['n']++;
                $buckets[$key]['r'] += $r;
                $buckets[$key]['g'] += $g;
                $buckets[$key]['b'] += $b;
            }
        }

        // Los grupos con muy pocos píxeles son mezclas de los bordes (antialias), no colores de la marca.
        $visible = array_sum(array_column($buckets, 'n'));
        $candidates = [];
        foreach ($buckets as $bucket) {
            if ($bucket['n'] < max(3, $visible * 0.02)) {
                continue;
            }
            $rgb = [(int) round($bucket['r'] / $bucket['n']), (int) round($bucket['g'] / $bucket['n']), (int) round($bucket['b'] / $bucket['n'])];
            $saturation = (max($rgb) - min($rgb)) / 255;
            $candidates[] = ['rgb' => $rgb, 'score' => $bucket['n'] * (0.25 + $saturation)];
        }
        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

        // Colores bien distintos entre sí: uno parecido a otro ya elegido no suma.
        $chosen = [];
        foreach ($candidates as $candidate) {
            foreach ($chosen as $rgb) {
                if (self::distance($rgb, $candidate['rgb']) < 60) {
                    continue 2;
                }
            }
            $chosen[] = $candidate['rgb'];
            if (count($chosen) === 6) {
                break;
            }
        }

        return array_map(fn (array $rgb) => sprintf('#%02X%02X%02X', ...$rgb), $chosen);
    }

    /** El color principal sugerido: el primero con tono de la paleta; si el logo es gris, el primero. */
    public static function suggested(array $palette): array
    {
        $colored = array_values(array_filter($palette, fn (string $hex) => self::saturation($hex) > 0.18));
        $primary = $colored[0] ?? $palette[0] ?? self::DEFAULT_PRIMARY;
        $secondary = collect($palette)->first(fn (string $hex) => $hex !== $primary);

        return [$primary, $secondary];
    }

    public static function normalizeColor(?string $hex): ?string
    {
        if ($hex === null || trim($hex) === '') {
            return null;
        }
        $hex = strtoupper(trim($hex));

        return preg_match('/^#[0-9A-F]{6}$/', $hex) ? $hex : null;
    }

    private static function saturation(string $hex): float
    {
        $rgb = sscanf($hex, '#%02x%02x%02x');

        return (max($rgb) - min($rgb)) / 255;
    }

    private static function distance(array $a, array $b): float
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }

    /** PostgreSQL entrega bytea como recurso de flujo. */
    private static function bytes(mixed $value): string
    {
        return is_resource($value) ? (string) stream_get_contents($value) : (string) $value;
    }
}
