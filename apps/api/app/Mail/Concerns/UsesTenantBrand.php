<?php

namespace App\Mail\Concerns;

use App\Services\Branding;

/**
 * Correos que recibe el cliente de una empresa: llevan su logo y su color (Configuración → Marca
 * de la empresa). Sin marca definida, salen con los de la plataforma. Las propiedades públicas
 * llegan solas a la plantilla.
 */
trait UsesTenantBrand
{
    /** @var array{name: string, logo: ?string, logo_width: int}|null */
    public ?array $brand = null;

    public string $accent = '#1B8FC9';

    public string $accentSoft = '#E8F4FB';

    public function withBrand(string $tenantId): static
    {
        $mail = Branding::forMail($tenantId);
        if ($mail) {
            $this->brand = ['name' => $mail['name'], 'logo' => $mail['logo'], 'logo_width' => $mail['logo_width']];
            $this->accent = $mail['accent'];
            $this->accentSoft = $mail['soft'];
        }

        return $this;
    }
}
