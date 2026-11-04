<?php

namespace Tests\Feature;

use Illuminate\Database\Connectors\MySqlConnector;
use Mockery;
use PDO;
use Tests\TestCase;

/*
 * LWS-BE-02 — fuseau horaire déterministe (partie sans base de données) :
 * l'application reste en UTC et la connexion mysql fixe le fuseau de session
 * à +00:00 au lieu de dépendre du SYSTEM du serveur (CEST chez LWS).
 * La vérification sur une vraie connexion est dans DatabaseTimezoneSessionTest.
 */
class DatabaseTimezoneConfigTest extends TestCase
{
    public function test_application_timezone_stays_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
    }

    public function test_mysql_connection_pins_the_session_timezone_to_utc(): void
    {
        $this->assertSame('+00:00', config('database.connections.mysql.timezone'));
    }

    public function test_the_value_is_a_literal_of_the_config_file_and_survives_config_cache(): void
    {
        // Valeur écrite dans config/database.php (aucune variable d'environnement).
        $file = require config_path('database.php');
        $this->assertSame('+00:00', $file['connections']['mysql']['timezone']);

        // config:cache écrit la configuration avec var_export puis la relit.
        $cached = eval('return '.var_export(config('database'), true).';');
        $this->assertSame('+00:00', $cached['connections']['mysql']['timezone']);
    }

    public function test_the_mysql_connector_sets_the_session_timezone_on_connect(): void
    {
        $statements = [];
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('getAttribute')->with(PDO::ATTR_SERVER_VERSION)->andReturn('10.11.19-MariaDB-deb12');
        $pdo->shouldReceive('exec')->andReturnUsing(function (string $sql) use (&$statements) {
            $statements[] = $sql;

            return 0;
        });

        $config = config('database.connections.mysql');
        (fn () => $this->configureConnection($pdo, $config))->call(new MySqlConnector);

        $this->assertCount(1, $statements);
        $this->assertStringStartsWith('SET ', $statements[0]);
        $this->assertStringContainsString("time_zone='+00:00'", $statements[0]);
        $this->assertStringContainsString("NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'", $statements[0], 'collation unchanged');
    }
}
