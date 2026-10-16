<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * LWS-BE-02 — fuseau horaire déterministe sur une vraie connexion mysql
 * (base de test uniquement) : la session est en +00:00 et une heure UTC qui
 * n'existe pas en heure d'Europe (saut de l'heure d'été) est stockée telle quelle.
 * Ne valide pas MariaDB 10.11 (carte LWS-BE-07).
 */
class DatabaseTimezoneSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_session_timezone_of_the_mysql_connection_is_utc(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());

        $row = DB::selectOne('SELECT @@session.time_zone AS tz, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS offset_seconds');

        $this->assertSame('+00:00', $row->tz);
        $this->assertSame(0, (int) $row->offset_seconds, 'NOW() / CURRENT_TIMESTAMP are UTC');
    }

    public function test_a_utc_timestamp_inside_the_european_dst_gap_is_stored_unchanged(): void
    {
        // 02:30 le 28/03/2027 n'existe pas en heure de Paris (02:00 -> 03:00) :
        // avec un fuseau de session local, MariaDB/MySQL décalerait la valeur.
        $utc = '2027-03-28 02:30:00';

        DB::table('users')->insert([
            'name' => 'Timezone Probe',
            'email' => 'timezone-probe@example.test',
            'password' => 'not-a-real-hash',
            'created_at' => $utc,
            'updated_at' => $utc,
        ]);

        $row = DB::table('users')
            ->where('email', 'timezone-probe@example.test')
            ->selectRaw('created_at, UNIX_TIMESTAMP(created_at) AS epoch')
            ->first();

        $this->assertSame($utc, (string) $row->created_at);
        $this->assertSame(Carbon::parse($utc, 'UTC')->getTimestamp(), (int) $row->epoch);
    }
}
