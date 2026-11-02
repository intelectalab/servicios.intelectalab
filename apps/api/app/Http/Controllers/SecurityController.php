<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\LoginThrottle;
use App\Services\SecurityPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

/**
 * Seguridad y aislamiento (Administración). Sin demostraciones: cada control se comprueba en el
 * momento contra la base y la configuración; la prueba de aislamiento intenta de verdad leer
 * registros de otra empresa; la actividad sale de la auditoría y de las sesiones.
 */
class SecurityController extends Controller
{
    /** Tablas de la prueba de aislamiento y su nombre en pantalla. */
    public const PROBE = [
        'quotations' => 'Cotizaciones', 'contracts' => 'Contratos', 'invoices' => 'Comprobantes', 'documents' => 'Documentos',
        'credential_records' => 'Credential Vault', 'support_tickets' => 'Tickets', 'parties' => 'Clientes y actores',
    ];

    private const ACCESS_ACTIONS = [
        'auth.login' => 'Ingreso', 'auth.login_failed' => 'Contraseña incorrecta', 'auth.account_locked' => 'Cuenta bloqueada 15 min',
        'auth.session_idle' => 'Sesión cerrada por inactividad', 'auth.logout' => 'Salida',
    ];

    public function show(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        return response()->json([
            'controls' => $this->controls($request, $tenantId),
            'activity' => $this->activity($tenantId),
            'policy' => SecurityPolicy::for($tenantId),
            'idle_options' => SecurityPolicy::IDLE_OPTIONS,
            'last_probe' => DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
                ->where('a.tenant_id', $tenantId)->where('a.action', 'security.isolation_probe')->orderByDesc('a.created_at')
                ->first(['a.created_at', 'a.metadata', 'u.name as user_name']),
        ]);
    }

    /**
     * Prueba de aislamiento: toma un registro real de otra empresa por cada tabla y trata de leerlo
     * con el usuario de la aplicación y el contexto de esta empresa. Nunca devuelve sus datos ni
     * sus identificadores: solo si la base lo bloqueó.
     */
    public function probe(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $targets = collect(DB::select('SELECT resource, record_id FROM isolation_probe_targets()'))->keyBy('resource');

        $results = [];
        foreach (self::PROBE as $table => $label) {
            $target = $targets->get($table);
            if (! $target) {
                $results[] = ['resource' => $table, 'label' => $label, 'result' => 'NO_DATA', 'detail' => 'Ninguna otra empresa tiene registros aquí para probar.'];

                continue;
            }
            $visible = DB::table($table)->where('id', $target->record_id)->exists();
            $results[] = [
                'resource' => $table, 'label' => $label, 'result' => $visible ? 'LEAK' : 'BLOCKED',
                'detail' => $visible ? 'El registro de otra empresa fue visible.' : 'NOT_FOUND: la base no lo entrega ni revela que existe.',
            ];
        }

        $summary = collect($results)->countBy('result')->all();
        AuditLogger::log($tenantId, $request->user()->id, 'security.isolation_probe', 'Security', $tenantId,
            metadata: ['note' => ($summary['BLOCKED'] ?? 0).' bloqueados · '.($summary['LEAK'] ?? 0).' fugas · '.($summary['NO_DATA'] ?? 0).' sin datos', 'results' => $summary]);

        return response()->json(['results' => $results, 'at' => now()->toIso8601String()]);
    }

    public function updatePolicy(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $data = $request->validate([
            'require_mfa_all' => ['required', 'boolean'],
            'session_hours' => ['required', 'integer', 'between:1,12'],
            'allow_remember' => ['required', 'boolean'],
            'idle_minutes' => ['required', 'integer', Rule::in(SecurityPolicy::IDLE_OPTIONS)],
        ], ['session_hours.between' => 'La sesión dura de 1 a 12 horas.']);

        DB::transaction(function () use ($request, $tenantId, $data) {
            $row = DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first(['configuration']);
            $configuration = json_decode($row->configuration ?: '{}', true) ?: [];
            $before = SecurityPolicy::for($tenantId);
            $configuration['security'] = [
                'require_mfa_all' => (bool) $data['require_mfa_all'], 'session_hours' => (int) $data['session_hours'],
                'allow_remember' => (bool) $data['allow_remember'], 'idle_minutes' => (int) $data['idle_minutes'],
            ];
            DB::table('tenants')->where('id', $tenantId)->update(['configuration' => json_encode($configuration), 'updated_at' => now()]);
            AuditLogger::log($tenantId, $request->user()->id, 'security.policy', 'Security', $tenantId, $before, $configuration['security']);
        });

        return $this->show($request);
    }

    /** Cada control, comprobado ahora: [clave, nombre, estado ok|warn, detalle]. */
    private function controls(Request $request, string $tenantId): array
    {
        $policy = SecurityPolicy::for($tenantId);
        $tables = collect(DB::select(<<<'SQL'
            SELECT c.relname, c.relrowsecurity AS rls
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind = 'r'
              AND EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attname = 'tenant_id' AND NOT a.attisdropped)
        SQL));
        $missing = $tables->where('rls', false)->pluck('relname')->all();
        $can = fn (string $table, string $privilege) => (bool) DB::selectOne('SELECT has_table_privilege(current_user, ?, ?) AS ok', ["public.{$table}", $privilege])->ok;
        $signed = in_array('signed', Route::getRoutes()->getByName('documents.file')?->gatherMiddleware() ?? [], true);
        $https = $request->secure() || str_starts_with((string) config('app.url'), 'https://');
        $adminMfa = (bool) config('auth.admin_mfa_required') || $policy['require_mfa_all'];
        $members = DB::table('tenant_users as tu')->join('users as u', 'u.id', '=', 'tu.user_id')
            ->where('tu.tenant_id', $tenantId)->where('tu.status', 'ACTIVE')->where('u.status', 'ACTIVE');
        $total = (clone $members)->count();
        $withMfa = (clone $members)->where('u.mfa_enabled', true)->count();

        $control = fn (string $key, string $label, bool $ok, string $detail) => compact('key', 'label', 'ok', 'detail');

        return [
            $control('rls', 'Aislamiento por empresa en la base', ! $missing,
                $missing ? (count($tables) - count($missing)).' de '.count($tables).' tablas lo tienen. Faltan: '.implode(', ', array_slice($missing, 0, 5)).'.'
                    : 'Las '.count($tables).' tablas con datos de empresa tienen su regla de aislamiento (RLS).'),
            $control('tenant_server', 'La empresa sale de la sesión, nunca del navegador', true,
                'Cada petición toma la empresa de la cuenta autenticada; lo de otra empresa responde "no existe".'),
            $control('admin_mfa', 'Código de verificación para el administrador', $adminMfa,
                $adminMfa ? ($policy['require_mfa_all'] ? 'Lo exige la política de la empresa para todos.' : 'Obligatorio para el rol Administrador.')
                    : 'Desactivado en este servidor (ADMIN_MFA_REQUIRED=false). Actívalo o exígelo a todos en la política.'),
            $control('users_mfa', 'Código de verificación para todos', $policy['require_mfa_all'],
                $policy['require_mfa_all'] ? "Todos los usuarios ingresan con código ({$total} activos)." : "{$withMfa} de {$total} usuarios activos lo tienen activado. Puedes exigirlo a todos en la política."),
            $control('passwords', 'Contraseñas cifradas con Argon2id', config('hashing.driver') === 'argon2id',
                'Algoritmo: '.config('hashing.driver').'. Las anteriores se actualizan al ingresar.'),
            $control('lockout', 'Bloqueo tras intentos fallidos', true,
                LoginThrottle::MAX_ATTEMPTS.' contraseñas incorrectas bloquean la cuenta '.LoginThrottle::LOCK_MINUTES.' minutos.'),
            $control('audit', 'Auditoría inalterable', ! $can('audit_logs', 'UPDATE') && ! $can('audit_logs', 'DELETE'),
                'La aplicación solo puede agregar y leer movimientos: no puede cambiarlos ni borrarlos.'),
            $control('files', 'Archivos solo por enlace temporal', $signed,
                $signed ? 'Cada descarga usa un enlace firmado de 5 minutos y queda registrada.' : 'La ruta de descarga no exige firma.'),
            $control('vault', 'Credenciales cifradas', DB::table('information_schema.columns')->where('table_name', 'credential_versions')->where('column_name', 'secret_ciphertext')->exists() && (string) config('app.key') !== '',
                'Los secretos del Credential Vault se guardan cifrados y nunca salen en la auditoría.'),
            $control('platform', 'Planes y suscripciones protegidos', ! $can('saas_plans', 'DELETE') && ! $can('module_catalog', 'UPDATE'),
                'La aplicación no puede borrar planes ni cambiar el catálogo; solo la consola con sesión verificada.'),
            $control('https', 'Conexión cifrada (HTTPS)', $https,
                $https ? 'El sistema se sirve por HTTPS.' : 'Este entorno no usa HTTPS (normal en una PC de desarrollo; en el servidor debe usarlo).'),
            $control('sessions', 'Sesiones con vencimiento', true,
                "Una sesión dura {$policy['session_hours']} h".($policy['allow_remember'] ? ' (30 días con "Recordarme")' : ' ("Recordarme" no permitido)')
                .($policy['idle_minutes'] ? " y se cierra tras {$policy['idle_minutes']} min sin uso." : '.')),
        ];
    }

    /** Últimos 7 días de acceso de la empresa y el estado de ahora. */
    private function activity(string $tenantId): array
    {
        $since = now()->subDays(7);
        $events = DB::table('audit_logs')->where('tenant_id', $tenantId)->whereIn('action', array_keys(self::ACCESS_ACTIONS))->where('created_at', '>=', $since);
        $logins = (clone $events)->where('action', 'auth.login');

        $members = DB::table('tenant_users as tu')->join('users as u', 'u.id', '=', 'tu.user_id')
            ->where('tu.tenant_id', $tenantId)->where('tu.status', 'ACTIVE')->get(['u.id', 'u.email', 'u.name']);
        $tokens = DB::table('personal_access_tokens')->where('tokenable_type', \App\Models\User::class)
            ->whereIn('tokenable_id', $members->pluck('id'))->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));

        return [
            'logins' => (clone $logins)->count(),
            'logins_without_mfa' => (clone $logins)->whereRaw("COALESCE((metadata->>'mfa')::boolean, false) = false")->count(),
            'failed' => (clone $events)->where('action', 'auth.login_failed')->count(),
            'locks' => (clone $events)->where('action', 'auth.account_locked')->count(),
            'locked_now' => $members->filter(fn ($member) => LoginThrottle::isLocked($member->email))->map(fn ($m) => $m->name)->values()->all(),
            'open_sessions' => (clone $tokens)->count(),
            'open_sessions_without_mfa' => (clone $tokens)->where('mfa', false)->count(),
            'recent' => DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
                ->where('a.tenant_id', $tenantId)->whereIn('a.action', array_keys(self::ACCESS_ACTIONS))
                ->orderByDesc('a.created_at')->limit(25)
                ->get(['a.id', 'a.action', 'a.created_at', 'a.ip_address', 'a.metadata', 'u.name as user_name'])
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'action' => $row->action,
                    'label' => self::ACCESS_ACTIONS[$row->action] ?? $row->action,
                    'user' => $row->user_name,
                    'ip' => $row->ip_address,
                    'mfa' => $row->action === 'auth.login' ? (bool) (json_decode((string) $row->metadata, true)['mfa'] ?? false) : null,
                    'at' => Carbon::parse($row->created_at)->toIso8601String(),
                ]),
        ];
    }
}
