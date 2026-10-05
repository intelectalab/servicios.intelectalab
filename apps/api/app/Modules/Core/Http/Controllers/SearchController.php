<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    private const PER_TYPE = 5;

    /**
     * Búsqueda global de la barra superior: clientes, proveedores y empleados
     * por nombre, razón social o documento. El aislamiento por tenant lo da RLS.
     */
    public function index(Request $request)
    {
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json(['results' => []]);
        }

        // Se escapan los comodines para buscar el texto tal cual.
        $like = '%'.addcslashes($term, '%_\\').'%';

        $results = collect()
            ->merge($this->parties('customers', 'customer', $like))
            ->merge($this->parties('suppliers', 'supplier', $like))
            ->merge($this->employees($like));

        return response()->json(['results' => $results->values()]);
    }

    /** Clientes o proveedores: su party puede ser persona u organización. */
    private function parties(string $table, string $type, string $like)
    {
        return DB::table($table)
            ->join('parties', 'parties.id', '=', "{$table}.party_id")
            ->leftJoin('persons', 'persons.party_id', '=', 'parties.id')
            ->leftJoin('organizations', 'organizations.party_id', '=', 'parties.id')
            ->where(function ($query) use ($like) {
                $query->whereRaw("concat_ws(' ', persons.given_names, persons.family_name) ILIKE ?", [$like])
                    ->orWhere('persons.document_number', 'ILIKE', $like)
                    ->orWhere('organizations.legal_name', 'ILIKE', $like)
                    ->orWhere('organizations.trade_name', 'ILIKE', $like)
                    ->orWhere('organizations.tax_identifier', 'ILIKE', $like);
            })
            ->orderByDesc("{$table}.created_at")
            ->limit(self::PER_TYPE)
            ->get([
                "{$table}.id",
                'parties.party_type',
                'persons.given_names',
                'persons.family_name',
                'persons.document_type',
                'persons.document_number',
                'organizations.legal_name',
                'organizations.tax_identifier',
            ])
            ->map(fn ($row) => [
                'type' => $type,
                'id' => $row->id,
                'title' => $row->party_type === 'ORGANIZATION'
                    ? $row->legal_name
                    : trim("{$row->given_names} {$row->family_name}"),
                'subtitle' => $row->party_type === 'ORGANIZATION'
                    ? ($row->tax_identifier ? "RUC {$row->tax_identifier}" : null)
                    : ($row->document_number ? trim(($row->document_type ?: 'DNI').' '.$row->document_number) : null),
            ]);
    }

    private function employees(string $like)
    {
        return DB::table('employees')
            ->join('persons', 'persons.party_id', '=', 'employees.person_party_id')
            ->where(function ($query) use ($like) {
                $query->whereRaw("concat_ws(' ', persons.given_names, persons.family_name) ILIKE ?", [$like])
                    ->orWhere('persons.document_number', 'ILIKE', $like)
                    ->orWhere('employees.employee_code', 'ILIKE', $like);
            })
            ->orderByDesc('employees.created_at')
            ->limit(self::PER_TYPE)
            ->get([
                'employees.id',
                'employees.employee_code',
                'employees.position',
                'persons.given_names',
                'persons.family_name',
            ])
            ->map(fn ($row) => [
                'type' => 'employee',
                'id' => $row->id,
                'title' => trim("{$row->given_names} {$row->family_name}"),
                'subtitle' => $row->position ?: $row->employee_code,
            ]);
    }
}
