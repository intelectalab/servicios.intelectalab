<?php

namespace App\Services;

/** Dirección de webhook no permitida (el mensaje se muestra tal cual a quien la configura). */
final class UnsafeWebhookTarget extends \RuntimeException
{
}
