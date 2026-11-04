<?php

namespace Tests\Feature;

use App\Services\LocalTime;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** "Hoy" es el día del calendario de Lima, aunque en UTC ya sea el día siguiente. */
class LocalTimeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_late_at_night_in_peru_today_is_still_the_peruvian_day(): void
    {
        // 23:30 en Lima del 3 de noviembre = 04:30 UTC del 4.
        $this->travelTo(Carbon::parse('2026-11-04 04:30:00', 'UTC'));

        $this->assertSame('2026-11-04', now()->toDateString());
        $this->assertSame('2026-11-03', LocalTime::today()->toDateString());
        // Como las columnas date: medianoche en la zona de la app, para comparar sin correrse un día.
        $this->assertTrue(Carbon::parse('2026-11-03')->equalTo(LocalTime::today()));
        $this->assertSame('2026-11-03 05:00:00', LocalTime::startOfDay()->utc()->format('Y-m-d H:i:s'));
    }

    public function test_the_database_defaults_take_the_lima_date(): void
    {
        $defaults = DB::table('information_schema.columns')
            ->whereIn('table_name', ['orders', 'cost_entries'])->whereIn('column_name', ['order_date', 'incurred_at'])
            ->pluck('column_default', 'column_name');

        foreach ($defaults as $default) {
            $this->assertStringContainsString('America/Lima', (string) $default);
        }
        $this->assertSame(
            DB::selectOne("SELECT (now() AT TIME ZONE 'America/Lima')::date::text AS d")->d,
            DB::selectOne('SELECT '.LocalTime::SQL_TODAY.'::text AS d')->d,
        );
    }
}
