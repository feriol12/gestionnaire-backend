<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/*
 * LWS-BE-03 / LWS-BE-08-F1 — point d'entrée public de l'API sur LWS
 * (deploy/lws/api-webroot).
 *
 * Le fichier index.php est exécuté pour de vrai dans une arborescence
 * temporaire qui reproduit LWS avec une application Laravel factice. Sur LWS
 * réel, la même racine web a deux noms : /home/htdocs/api.fluxmoneva.com
 * (chemin physique, SSH/FTP) et /htdocs/api.fluxmoneva.com (nom canonique vu
 * par le PHP HTTP, observé en répétition LWS-BE-08). L'application privée
 * reste dans /home/fluxmoneva-api. Les deux vues sont reproduites ici ; la
 * racine privée absolue du fichier livré est redirigée vers l'arborescence
 * temporaire, comme le fait la répétition. Aucun Laravel réel, aucune base.
 */
class LwsPublicEntrypointTest extends TestCase
{
    private const WEBROOT_ARTIFACT = __DIR__.'/../../deploy/lws/api-webroot';

    // Invariant d'infrastructure LWS : racine Laravel privée en production.
    private const PRODUCTION_PRIVATE_ROOT = '/home/fluxmoneva-api';

    private const PRIVATE_ROOT = 'home/fluxmoneva-api';

    // Même racine web, deux noms (lien LWS /home/htdocs -> /htdocs).
    private const PHYSICAL_WEBROOT = 'home/htdocs/api.fluxmoneva.com';

    private const CANONICAL_WEBROOT = 'htdocs/api.fluxmoneva.com';

    // Ce que dirname(__DIR__, 2).'/fluxmoneva-api' donnait vu depuis /htdocs.
    private const ALIAS_DERIVED_ROOT = 'fluxmoneva-api';

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/lws-be-08-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->base);

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
    }

    public function test_the_production_artifact_targets_the_approved_absolute_private_root(): void
    {
        $code = $this->entrypoint();

        $this->assertMatchesRegularExpression("#^\\\$laravelRoot = '/home/fluxmoneva-api';$#m", $code);

        // Un seul littéral : point unique de reciblage pour la répétition.
        $this->assertSame(1, $this->rootLiteralCount($code));

        $rehearsal = str_replace("'/home/fluxmoneva-api'", "'/home/fluxmoneva-api-rehearsal'", $code, $replaced);
        token_get_all($rehearsal, TOKEN_PARSE);
        $this->assertSame(1, $replaced);
        $this->assertStringContainsString("\$laravelRoot = '/home/fluxmoneva-api-rehearsal';", $rehearsal);
    }

    public function test_the_private_root_is_not_derived_from_the_entrypoint_location(): void
    {
        // Sur LWS, __DIR__ vaut /htdocs/… et non /home/htdocs/… : toute
        // ascendance de l'emplacement du fichier donne une racine fausse.
        $forbiddenTokens = [T_DIR => '__DIR__', T_FILE => '__FILE__'];
        $forbiddenFunctions = ['dirname', 'realpath', 'getcwd', 'chdir', 'pathinfo', 'basename'];

        foreach (token_get_all($this->entrypoint()) as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text] = $token;

            $this->assertArrayNotHasKey($id, $forbiddenTokens, "{$text} used");

            if ($id === T_STRING) {
                $this->assertNotContains(strtolower($text), $forbiddenFunctions, "function {$text} used");
            }
        }
    }

    public function test_the_private_root_never_comes_from_request_or_environment_input(): void
    {
        $forbiddenVariables = ['$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_SERVER', '$_ENV', '$_FILES', '$GLOBALS'];
        $forbiddenFunctions = [
            'getenv', 'apache_getenv', 'filter_input', 'parse_str', 'extract', 'ini_get', 'get_cfg_var',
            'getallheaders', 'apache_request_headers',
        ];

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

            if (! in_array($id, [T_COMMENT, T_DOC_COMMENT], true)) {
                $this->assertStringNotContainsString('DOCUMENT_ROOT', $text);
            }
        }
    }

    // ------------------------------------------------------------------ index.php (executed)

    public function test_it_boots_the_private_application_and_never_the_webroot_or_stock_paths(): void
    {
        $this->buildPrivateApplication();

        // Leurres aux emplacements que viserait un public/index.php d'origine
        // (racine web, puis « ../ »), sous ses deux noms, et à la racine que
        // l'ancienne déduction dirname(__DIR__, 2) donnait vue depuis /htdocs.
        foreach ([self::CANONICAL_WEBROOT, 'htdocs', self::PHYSICAL_WEBROOT, 'home/htdocs', self::ALIAS_DERIVED_ROOT] as $decoy) {
            $this->put("{$decoy}/vendor/autoload.php", "<?php echo 'DECOY-AUTOLOAD:{$decoy}'; exit;");
            $this->put("{$decoy}/bootstrap/app.php", "<?php echo 'DECOY-BOOTSTRAP:{$decoy}'; exit;");
            $this->put("{$decoy}/storage/framework/maintenance.php", "<?php echo 'DECOY-MAINTENANCE:{$decoy}'; exit;");
        }

        $this->assertSame('AUTOLOAD|BOOTSTRAP|HANDLE|SEND|TERMINATE', $this->runEntrypoint());
    }

    public function test_the_real_lws_htdocs_alias_reaches_the_same_private_root_from_both_names(): void
    {
        // Régression LWS-BE-08 : PHP_DIR=/htdocs/api-rehearsal.fluxmoneva.com,
        // DERIVED_ROOT=//fluxmoneva-api-rehearsal, DERIVED_ROOT_EXISTS=NO.
        // Rien n'existe à la racine déduite ; seule /home/… porte l'application.
        $this->buildPrivateApplication();

        $this->assertDirectoryDoesNotExist($this->base.'/'.self::ALIAS_DERIVED_ROOT);
        $this->assertSame('AUTOLOAD|BOOTSTRAP|HANDLE|SEND|TERMINATE', $this->runEntrypoint(self::CANONICAL_WEBROOT), 'as seen by LWS HTTP PHP');
        $this->assertSame('AUTOLOAD|BOOTSTRAP|HANDLE|SEND|TERMINATE', $this->runEntrypoint(self::PHYSICAL_WEBROOT), 'as seen over SSH/FTP');
    }

    public function test_maintenance_mode_is_read_from_the_private_storage(): void
    {
        $this->buildPrivateApplication();
        $this->put(self::PRIVATE_ROOT.'/storage/framework/maintenance.php', "<?php echo 'PRIVATE-MAINTENANCE'; exit;");
        $this->put(self::ALIAS_DERIVED_ROOT.'/storage/framework/maintenance.php', "<?php echo 'DECOY-MAINTENANCE'; exit;");

        $this->assertSame('PRIVATE-MAINTENANCE', $this->runEntrypoint(self::CANONICAL_WEBROOT));
        $this->assertSame('PRIVATE-MAINTENANCE', $this->runEntrypoint(self::PHYSICAL_WEBROOT));
    }

    public function test_private_maintenance_runs_without_either_bootstrap_dependency(): void
    {
        // Aucun vendor/autoload.php ni bootstrap/app.php : la maintenance doit
        // pouvoir terminer la requête avant le garde de démarrage normal.
        $this->put(self::PRIVATE_ROOT.'/storage/framework/maintenance.php', "<?php echo 'PRIVATE-MAINTENANCE'; exit;");

        foreach ([self::CANONICAL_WEBROOT, 'htdocs', self::PHYSICAL_WEBROOT, 'home/htdocs', self::ALIAS_DERIVED_ROOT] as $decoy) {
            $this->put("{$decoy}/storage/framework/maintenance.php", "<?php echo 'DECOY-MAINTENANCE'; exit;");
        }

        $this->assertSame('PRIVATE-MAINTENANCE', $this->runEntrypoint());
    }

    public function test_a_missing_private_application_fails_without_exposing_any_path(): void
    {
        $this->put(self::PRIVATE_ROOT.'/vendor/autoload.php', "<?php echo 'AUTOLOAD';"); // bootstrap/app.php absent

        $this->assertSame('', $this->runEntrypoint(), 'no output, no PHP warning, no filesystem path');
    }

    public function test_an_absent_private_root_fails_without_exposing_any_path(): void
    {
        // État actuel de la production : /home/fluxmoneva-api n'existe pas.
        $this->assertSame('', $this->runEntrypoint(self::CANONICAL_WEBROOT), 'no output, no PHP warning, no filesystem path');
        $this->assertDirectoryDoesNotExist($this->base.'/'.self::PRIVATE_ROOT);
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

    private function isRootLiteral(mixed $token): bool
    {
        return is_array($token)
            && $token[0] === T_CONSTANT_ENCAPSED_STRING
            && $token[1] === var_export(self::PRODUCTION_PRIVATE_ROOT, true);
    }

    private function rootLiteralCount(string $code): int
    {
        return count(array_filter(token_get_all($code), fn ($token) => $this->isRootLiteral($token)));
    }

    // Fichier livré, racine privée absolue redirigée vers l'arborescence
    // temporaire (même transformation que la répétition LWS). Rien d'autre ne
    // change : un fichier qui déduirait sa racine de __DIR__ reste tel quel.
    private function deployedEntrypoint(): string
    {
        $code = '';
        foreach (token_get_all($this->entrypoint()) as $token) {
            if ($this->isRootLiteral($token)) {
                $token[1] = var_export($this->base.'/'.self::PRIVATE_ROOT, true);
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
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
        $this->put(self::PRIVATE_ROOT.'/vendor/autoload.php', <<<'PHP'
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

        $this->put(self::PRIVATE_ROOT.'/bootstrap/app.php', <<<'PHP'
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

    // Par défaut, le nom canonique : c'est celui que voit le PHP HTTP de LWS.
    private function runEntrypoint(string $webroot = self::CANONICAL_WEBROOT): string
    {
        $index = $this->base.'/'.$webroot.'/index.php';
        $this->put($webroot.'/index.php', $this->deployedEntrypoint());

        // Répertoire courant volontairement ailleurs : seul le chemin privé compte.
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
        $path = $this->base.'/'.$relative;
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
