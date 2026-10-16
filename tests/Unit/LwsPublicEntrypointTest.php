<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/*
 * LWS-BE-03 — point d'entrée public de l'API sur LWS (deploy/lws/api-webroot).
 *
 * Le fichier index.php est exécuté pour de vrai dans une arborescence
 * temporaire qui reproduit LWS (home/htdocs/api.fluxmoneva.com + home/
 * fluxmoneva-api) avec une application Laravel factice : il doit charger la
 * racine privée, jamais la racine web ni les chemins « ../ » du public/index.php
 * d'origine. Aucun Laravel réel, aucune base de données.
 */
class LwsPublicEntrypointTest extends TestCase
{
    private const WEBROOT_ARTIFACT = __DIR__.'/../../deploy/lws/api-webroot';

    private string $home;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir().'/lws-be-03-'.bin2hex(random_bytes(6)).'/home';
    }

    protected function tearDown(): void
    {
        $this->deleteTree(dirname($this->home));

        parent::tearDown();
    }

    // ------------------------------------------------------------------ artifact inventory

    public function test_the_webroot_artifact_contains_only_the_entrypoint_and_htaccess(): void
    {
        $files = array_values(array_diff(scandir(self::WEBROOT_ARTIFACT), ['.', '..']));
        sort($files);

        $this->assertSame(['.htaccess', 'index.php'], $files);
    }

    public function test_the_local_entrypoint_keeps_the_stock_laravel_layout(): void
    {
        // public/index.php sert toujours au développement local et aux tests.
        $local = file_get_contents(__DIR__.'/../../public/index.php');

        $this->assertStringContainsString("__DIR__.'/../vendor/autoload.php'", $local);
        $this->assertStringContainsString("__DIR__.'/../bootstrap/app.php'", $local);
    }

    // ------------------------------------------------------------------ index.php (static)

    public function test_the_entrypoint_is_valid_php_without_stock_relative_paths(): void
    {
        $code = $this->entrypoint();

        token_get_all($code, TOKEN_PARSE); // ParseError en cas de syntaxe invalide

        $this->assertDoesNotMatchRegularExpression("#__DIR__\s*\.\s*['\"]/\.\./(vendor|bootstrap|storage)#", $code);
        $this->assertStringContainsString("dirname(__DIR__, 2).'/fluxmoneva-api'", $code);
    }

    public function test_the_private_root_never_comes_from_request_or_environment_input(): void
    {
        $forbiddenVariables = ['$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_SERVER', '$_ENV', '$_FILES', '$GLOBALS'];
        $forbiddenFunctions = ['getenv', 'apache_getenv', 'filter_input', 'parse_str', 'extract', 'ini_get', 'realpath'];

        foreach (token_get_all($this->entrypoint()) as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text] = $token;

            if ($id === T_VARIABLE) {
                $this->assertNotContains($text, $forbiddenVariables, "superglobal {$text} used");
            }

            if ($id === T_STRING) {
                $this->assertNotContains(strtolower($text), $forbiddenFunctions, "function {$text} used");
            }
        }
    }

    // ------------------------------------------------------------------ index.php (executed)

    public function test_it_boots_the_private_application_and_never_the_webroot_or_stock_paths(): void
    {
        $this->buildPrivateApplication();

        // Leurres aux emplacements que viserait un public/index.php d'origine
        // (racine web elle-même, puis « ../ » depuis la racine web).
        foreach (['htdocs/api.fluxmoneva.com', 'htdocs'] as $decoy) {
            $this->put("{$decoy}/vendor/autoload.php", "<?php echo 'DECOY-AUTOLOAD:{$decoy}'; exit;");
            $this->put("{$decoy}/bootstrap/app.php", "<?php echo 'DECOY-BOOTSTRAP:{$decoy}'; exit;");
            $this->put("{$decoy}/storage/framework/maintenance.php", "<?php echo 'DECOY-MAINTENANCE:{$decoy}'; exit;");
        }

        $this->assertSame('AUTOLOAD|BOOTSTRAP|HANDLE|SEND|TERMINATE', $this->runEntrypoint());
    }

    public function test_maintenance_mode_is_read_from_the_private_storage(): void
    {
        $this->buildPrivateApplication();
        $this->put('fluxmoneva-api/storage/framework/maintenance.php', "<?php echo 'PRIVATE-MAINTENANCE'; exit;");

        $this->assertSame('PRIVATE-MAINTENANCE', $this->runEntrypoint());
    }

    public function test_private_maintenance_runs_without_either_bootstrap_dependency(): void
    {
        // Aucun vendor/autoload.php ni bootstrap/app.php : la maintenance doit
        // pouvoir terminer la requête avant le garde de démarrage normal.
        $this->put('fluxmoneva-api/storage/framework/maintenance.php', "<?php echo 'PRIVATE-MAINTENANCE'; exit;");

        foreach (['htdocs/api.fluxmoneva.com', 'htdocs'] as $decoy) {
            $this->put("{$decoy}/storage/framework/maintenance.php", "<?php echo 'DECOY-MAINTENANCE'; exit;");
        }

        $this->assertSame('PRIVATE-MAINTENANCE', $this->runEntrypoint());
    }

    public function test_a_missing_private_application_fails_without_exposing_any_path(): void
    {
        $this->put('fluxmoneva-api/vendor/autoload.php', "<?php echo 'AUTOLOAD';"); // bootstrap/app.php absent

        $this->assertSame('', $this->runEntrypoint(), 'no output, no PHP warning, no filesystem path');
    }

    // ------------------------------------------------------------------ .htaccess

    public function test_htaccess_keeps_every_laravel_front_controller_rule_in_order(): void
    {
        $stock = $this->directives(file_get_contents(__DIR__.'/../../public/.htaccess'));
        $lws = $this->directives(file_get_contents(self::WEBROOT_ARTIFACT.'/.htaccess'));

        // Toutes les directives du .htaccess Laravel du dépôt, dans le même ordre.
        $position = -1;
        foreach ($stock as $directive) {
            $found = array_search($directive, array_slice($lws, $position + 1, null, true), true);
            $this->assertNotFalse($found, "missing or reordered: {$directive}");
            $position = $found;
        }

        $this->assertContains('Options -MultiViews -Indexes', $lws);
        $this->assertContains('RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]', $lws);
        $this->assertContains('RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]', $lws);
        $this->assertSame(
            ['RewriteCond %{REQUEST_FILENAME} !-d', 'RewriteCond %{REQUEST_FILENAME} !-f', 'RewriteRule ^ index.php [L]'],
            array_slice($lws, -4, 3),
            'front controller is the last rule block'
        );
    }

    public function test_htaccess_adds_no_php_handler_or_permissive_directives(): void
    {
        $content = file_get_contents(self::WEBROOT_ARTIFACT.'/.htaccess');

        $this->assertDoesNotMatchRegularExpression('/^\s*(php_value|php_flag|php_admin_\w+|AddHandler|SetHandler|AddType|Action)\b/mi', $content);
        $this->assertDoesNotMatchRegularExpression('/\+\s*(Indexes|FollowSymLinks|ExecCGI)/i', $content);
    }

    // ------------------------------------------------------------------ helpers

    private function entrypoint(): string
    {
        return file_get_contents(self::WEBROOT_ARTIFACT.'/index.php');
    }

    /** @return list<string> */
    private function directives(string $htaccess): array
    {
        $lines = array_map('trim', preg_split('/\R/', $htaccess));

        return array_values(array_filter($lines, fn (string $line) => $line !== '' && ! str_starts_with($line, '#')));
    }

    // Application Laravel factice : trace l'ordre réel du cycle de la requête.
    private function buildPrivateApplication(): void
    {
        $this->put('fluxmoneva-api/vendor/autoload.php', <<<'PHP'
            <?php
            namespace Illuminate\Http;
            echo 'AUTOLOAD';
            class Request
            {
                public static function capture(): static
                {
                    return new static;
                }
            }
            PHP);

        $this->put('fluxmoneva-api/bootstrap/app.php', <<<'PHP'
            <?php
            echo '|BOOTSTRAP';
            return new class
            {
                public function make(string $abstract): object
                {
                    if ($abstract !== 'Illuminate\Contracts\Http\Kernel') {
                        throw new \LogicException('unexpected binding');
                    }

                    return new class
                    {
                        public function handle(\Illuminate\Http\Request $request): object
                        {
                            echo '|HANDLE';

                            return new class
                            {
                                public function send(): static
                                {
                                    echo '|SEND';

                                    return $this;
                                }
                            };
                        }

                        public function terminate(\Illuminate\Http\Request $request, object $response): void
                        {
                            echo '|TERMINATE';
                        }
                    };
                }
            };
            PHP);
    }

    private function runEntrypoint(): string
    {
        $index = $this->home.'/htdocs/api.fluxmoneva.com/index.php';
        $this->put('htdocs/api.fluxmoneva.com/index.php', $this->entrypoint());

        // Répertoire courant volontairement ailleurs : seul l'emplacement du fichier compte.
        $process = proc_open(
            [PHP_BINARY, '-n', '-d', 'display_errors=stdout', '-d', 'error_reporting=-1', $index],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            sys_get_temp_dir()
        );
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $this->assertSame('', $stderr);
        $this->assertSame(0, $exit, $stdout);

        return $stdout;
    }

    private function put(string $relative, string $content): void
    {
        $path = $this->home.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
