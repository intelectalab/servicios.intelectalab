<?php

return [
    /*
    | Motor antivirus que analiza cada archivo antes de guardarlo (ver Documents\Antivirus).
    |
    |   simulated  No analiza de verdad: solo reconoce el archivo de prueba EICAR, el estándar con
    |              el que se comprueba que un antivirus responde. Cada archivo queda marcado como
    |              analizado por "SIMULATED", para saber cuáles hay que volver a revisar con uno real.
    |
    | Cuando se instale uno real (p. ej. ClamAV en el servidor), se añade su clase y su nombre aquí.
    */
    'antivirus' => env('DOCUMENTS_ANTIVIRUS', 'simulated'),
];
