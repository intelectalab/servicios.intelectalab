<?php

namespace App\Services;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Avisos a las personas de la empresa: campana del sistema y, si la persona lo pidió, correo.
 *
 * A quién le llega se decide por permiso y alcance, nunca por el nombre del rol (cada empresa
 * arma sus roles): `withPermission('purchases.approve', $orden)` son quienes pueden aprobar
 * esa orden Y la verían en su pantalla. Nadie recibe aviso de lo que hizo él mismo.
 *
 * Las preferencias de Mi perfil mandan: sin "En la aplicación" no aparece en la campana; sin
 * "Correo" no se envía correo. El aviso se guarda con la operación (si se deshace, no hay
 * aviso); el correo lo envía MessageCenter después de responder y, si falla, la operación no se
 * pierde: queda Fallido en la bitácora, para reintentar.
 */
class Notifier
{
    /**
     * @param iterable<string|null> $userIds
     */
    public static function send(string $event, string $title, string $body, ?string $link, iterable $userIds): void
    {
        $request = request();
        $tenantId = (string) $request->attributes->get('tenant_id');
        $actorId = $request->user()?->id;
        if ($tenantId === '') {
            return;
        }

        $ids = collect($userIds)->filter()->unique()->reject(fn ($id) => $id === $actorId)->values();
        if ($ids->isEmpty()) {
            return;
        }

        // Solo personas con acceso vigente a la empresa.
        $members = DB::table('tenant_users')
            ->join('users', 'users.id', '=', 'tenant_users.user_id')
            ->where('tenant_users.tenant_id', $tenantId)
            ->where('tenant_users.status', 'ACTIVE')
            ->whereIn('tenant_users.user_id', $ids)
            ->get(['users.id', 'users.email', 'users.name']);
        $preferences = DB::table('notification_preferences')
            ->where('tenant_id', $tenantId)
            ->whereIn('user_id', $members->pluck('id'))
            ->get()->keyBy('user_id');

        $rows = [];
        $mails = [];
        foreach ($members as $member) {
            $preference = $preferences->get($member->id);
            $inApp = (bool) ($preference->in_app ?? true);
            $email = (bool) ($preference->email ?? true);
            if (! $inApp && ! $email) {
                continue;
            }

            $rows[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'user_id' => $member->id,
                'channel' => $inApp ? 'IN_APP' : 'EMAIL',
                'event' => $event,
                'subject' => Str::limit($title, 250),
                'body' => $body,
                'link' => $link,
                'actor_user_id' => $actorId,
                'status' => 'SENT',
                'sent_at' => now(),
                'metadata' => json_encode(['email' => $email]),
                'created_at' => now(),
            ];
            if ($email && $member->email) {
                $mails[] = ['email' => $member->email, 'name' => $member->name];
            }
        }

        if ($rows) {
            DB::table('notifications')->insert($rows);
        }
        if ($mails) {
            // Plantilla de la empresa, canal y bitácora: Administración → Notificaciones.
            MessageCenter::internal($tenantId, $event, $mails, $title, $body, $link);
        }
    }

    /**
     * Personas con el permiso que, además, alcanzan el registro según su rol.
     *
     * @return string[]
     */
    public static function withPermission(string $permission, ?Model $record = null): array
    {
        $tenantId = (string) request()->attributes->get('tenant_id');

        $holders = DB::table('user_roles')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'user_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->join('tenant_users', fn ($join) => $join->on('tenant_users.user_id', '=', 'user_roles.user_id')
                ->on('tenant_users.tenant_id', '=', 'user_roles.tenant_id'))
            ->where('user_roles.tenant_id', $tenantId)
            ->where('tenant_users.status', 'ACTIVE')
            ->where('permissions.code', $permission)
            ->distinct()
            ->pluck('user_roles.user_id')
            ->all();

        if (! $record || ! in_array(ScopedByAccess::class, class_uses_recursive($record), true)) {
            return $holders;
        }

        return array_values(array_filter($holders, function (string $userId) use ($record, $tenantId) {
            $query = $record::ignoringAccessScope()->whereKey($record->getKey());
            AccessScope::applyFor($query, $record::ACCESS_RESOURCE, $tenantId, $userId);

            return $query->exists();
        }));
    }

    /** "S/ 1,250.00" o "US$ 300.00", para el texto del aviso. */
    public static function money($amount, ?string $currency = 'PEN'): string
    {
        return ($currency === 'USD' ? 'US$ ' : 'S/ ').number_format((float) $amount, 2);
    }
}
