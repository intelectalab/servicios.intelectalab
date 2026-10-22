<?php

namespace App\Modules\Documents\Antivirus;

/**
 * Antivirus simulado, mientras no haya un motor real en el servidor: no analiza el contenido,
 * solo reconoce dos archivos de prueba que se tratan como amenaza, para probar la cuarentena sin
 * un virus de verdad:
 *
 * - EICAR, el estándar con el que se comprueba cualquier antivirus (ojo: el antivirus del propio
 *   equipo suele borrarlo antes de subirlo);
 * - un archivo de texto que empiece con TEST_MARKER, que ningún antivirus del equipo toca.
 */
class SimulatedScanner implements AntivirusScanner
{
    public const ENGINE = 'SIMULATED';

    public const TEST_MARKER = 'ERP-SAAS-PRUEBA-ANTIVIRUS';

    private const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    public function scan(string $path): ScanResult
    {
        // Los archivos de prueba van al principio; basta leer el comienzo.
        $head = (string) @file_get_contents($path, false, null, 0, 4096);

        if (str_contains($head, self::EICAR)) {
            return new ScanResult('INFECTED', self::ENGINE, 'EICAR-Test-File');
        }
        if (str_starts_with(ltrim($head, "\xEF\xBB\xBF \t\r\n"), self::TEST_MARKER)) {
            return new ScanResult('INFECTED', self::ENGINE, 'Prueba de cuarentena');
        }

        return new ScanResult('CLEAN', self::ENGINE);
    }
}
