<?php

namespace Tests\Feature;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\TestCase;

/*
 * LWS-BE-05 — configuration SMTP explicite (MAIL_SCHEME + hôte/port).
 *
 * config/mail.php est évalué avec des variables d'environnement contrôlées
 * (posées puis restaurées : le .env local n'influence pas le résultat) et le
 * vrai transport Symfony est construit en mémoire. Aucune connexion, aucun
 * envoi : seul le constructeur du transport est appelé, jamais start()/send().
 */
class MailConfigurationTest extends TestCase
{
    private const MAIL_ENV = [
        'APP_URL', 'MAIL_MAILER', 'MAIL_SCHEME', 'MAIL_URL', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_ENCRYPTION',
        'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_TIMEOUT', 'MAIL_EHLO_DOMAIN', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
    ];

    // ------------------------------------------------------------------ forme de la configuration

    public function test_the_smtp_mailer_exposes_only_the_explicit_model(): void
    {
        $smtp = $this->mailConfig([])['mailers']['smtp'];

        foreach (['scheme', 'host', 'port', 'username', 'password', 'timeout', 'local_domain'] as $key) {
            $this->assertArrayHasKey($key, $smtp);
        }
        $this->assertArrayNotHasKey('encryption', $smtp, 'MAIL_ENCRYPTION is not part of the contract');
        $this->assertArrayNotHasKey('url', $smtp, 'MAIL_URL is intentionally unsupported');

        // Configuration réellement chargée (fusion Laravel 13 comprise).
        $this->assertArrayNotHasKey('encryption', config('mail.mailers.smtp'));
        $this->assertArrayNotHasKey('url', config('mail.mailers.smtp'));
    }

    public function test_environment_values_map_to_the_smtp_and_sender_config(): void
    {
        $config = $this->mailConfig([
            'MAIL_SCHEME' => 'smtps',
            'MAIL_HOST' => 'smtp.example.test',
            'MAIL_PORT' => '465',
            'MAIL_TIMEOUT' => '7',
            'MAIL_EHLO_DOMAIN' => 'ehlo.example.test',
            'MAIL_FROM_ADDRESS' => 'sender@example.test',
            'MAIL_FROM_NAME' => 'Config Test Sender',
        ]);

        $this->assertSame('smtps', $config['mailers']['smtp']['scheme']);
        $this->assertSame('smtp.example.test', $config['mailers']['smtp']['host']);
        $this->assertSame('7', $config['mailers']['smtp']['timeout']);
        $this->assertSame('ehlo.example.test', $config['mailers']['smtp']['local_domain']);
        $this->assertSame(['address' => 'sender@example.test', 'name' => 'Config Test Sender'], $config['from']);
    }

    public function test_mail_scheme_null_string_means_no_explicit_scheme(): void
    {
        $this->assertNull($this->mailConfig(['MAIL_SCHEME' => 'null'])['mailers']['smtp']['scheme']);
    }

    // ------------------------------------------------------------------ transport de production (LWS)

    public function test_production_like_smtps_transport_uses_implicit_tls_without_connecting(): void
    {
        $smtp = $this->mailConfig([
            'MAIL_SCHEME' => 'smtps',
            'MAIL_HOST' => 'mail.fluxmoneva.com',
            'MAIL_PORT' => '465',
            'MAIL_USERNAME' => 'mailbox@example.test',
            'MAIL_PASSWORD' => 'not-a-real-secret',
            'MAIL_TIMEOUT' => '20',
        ])['mailers']['smtp'];

        [$transport, $stream] = $this->buildTransport($smtp);

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame('mail.fluxmoneva.com', $stream->getHost());
        $this->assertSame(465, $stream->getPort());
        $this->assertTrue($stream->isTLS(), 'implicit TLS (smtps)');
        $this->assertSame(20.0, $stream->getTimeout());
        $this->assertArrayNotHasKey('verify_peer', $stream->getStreamOptions()['ssl'] ?? []);
        $this->assertArrayNotHasKey('verify_peer_name', $stream->getStreamOptions()['ssl'] ?? []);
        $this->assertArrayNotHasKey('allow_self_signed', $stream->getStreamOptions()['ssl'] ?? []);
    }

    public function test_explicit_scheme_host_and_port_suffice_without_mail_url(): void
    {
        $smtp = $this->mailConfig([
            'MAIL_SCHEME' => 'smtps',
            'MAIL_HOST' => 'mail.fluxmoneva.com',
            'MAIL_PORT' => '465',
            'MAIL_URL' => 'smtp://url-host.example.test:2525', // ignorée : aucune clé « url »
        ])['mailers']['smtp'];

        $this->assertArrayNotHasKey('url', $smtp);

        [, $stream] = $this->buildTransport($smtp);
        $this->assertSame('mail.fluxmoneva.com', $stream->getHost());
        $this->assertSame(465, $stream->getPort());
        $this->assertTrue($stream->isTLS());
    }

    public function test_legacy_mail_encryption_no_longer_controls_the_transport(): void
    {
        $smtp = $this->mailConfig([
            'MAIL_HOST' => 'mail.fluxmoneva.com',
            'MAIL_PORT' => '587',
            'MAIL_ENCRYPTION' => 'ssl',
        ])['mailers']['smtp'];

        $this->assertArrayNotHasKey('encryption', $smtp);

        [, $stream] = $this->buildTransport($smtp);
        $this->assertFalse($stream->isTLS(), 'only MAIL_SCHEME (or port 465) selects implicit TLS');
    }

    // ------------------------------------------------------------------ délai d'attente

    public function test_timeout_defaults_to_twenty_seconds_and_is_configurable(): void
    {
        $default = $this->mailConfig(['MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => '1025'])['mailers']['smtp'];
        $this->assertSame(20, $default['timeout']);
        $this->assertSame(20.0, $this->buildTransport($default)[1]->getTimeout());

        $custom = $this->mailConfig(['MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => '1025', 'MAIL_TIMEOUT' => '7'])['mailers']['smtp'];
        $this->assertSame(7.0, $this->buildTransport($custom)[1]->getTimeout());
    }

    // ------------------------------------------------------------------ Mailpit local

    public function test_local_mailpit_transport_stays_plain_smtp(): void
    {
        $smtp = $this->mailConfig([
            'MAIL_SCHEME' => 'null',
            'MAIL_HOST' => '127.0.0.1',
            'MAIL_PORT' => '1025',
            'MAIL_TIMEOUT' => '20',
        ])['mailers']['smtp'];

        [$transport, $stream] = $this->buildTransport($smtp);

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame('127.0.0.1', $stream->getHost());
        $this->assertSame(1025, $stream->getPort());
        $this->assertFalse($stream->isTLS(), 'no implicit TLS for local Mailpit');
        $this->assertFalse($transport->isTlsRequired());
    }

    // ------------------------------------------------------------------ EHLO

    public function test_ehlo_domain_defaults_to_the_app_url_host_and_can_be_overridden(): void
    {
        $default = $this->mailConfig(['APP_URL' => 'https://api.fluxmoneva.com', 'MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => '1025'])['mailers']['smtp'];
        $this->assertSame('api.fluxmoneva.com', $default['local_domain']);
        $this->assertSame('api.fluxmoneva.com', $this->buildTransport($default)[0]->getLocalDomain());

        $override = $this->mailConfig([
            'APP_URL' => 'https://api.fluxmoneva.com',
            'MAIL_EHLO_DOMAIN' => 'ehlo.example.test',
            'MAIL_HOST' => '127.0.0.1',
            'MAIL_PORT' => '1025',
        ])['mailers']['smtp'];
        $this->assertSame('ehlo.example.test', $this->buildTransport($override)[0]->getLocalDomain());
    }

    // ------------------------------------------------------------------ cache de configuration

    public function test_mail_configuration_survives_a_config_cache_round_trip(): void
    {
        $config = $this->mailConfig([
            'APP_URL' => 'https://api.fluxmoneva.com',
            'MAIL_SCHEME' => 'smtps',
            'MAIL_HOST' => 'mail.fluxmoneva.com',
            'MAIL_PORT' => '465',
        ]);

        // config:cache écrit la configuration avec var_export puis la relit.
        $cached = eval('return '.var_export($config, true).';');

        $this->assertSame($config, $cached);
        $this->assertSame('smtps', $cached['mailers']['smtp']['scheme']);
        $this->assertSame(20, $cached['mailers']['smtp']['timeout']);
        $this->assertSame('api.fluxmoneva.com', $cached['mailers']['smtp']['local_domain']);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Évalue config/mail.php avec exactement les variables données (les autres
     * variables MAIL_* et APP_URL sont absentes), puis restaure l'environnement.
     *
     * @param  array<string, string>  $vars
     */
    private function mailConfig(array $vars): array
    {
        $saved = [];
        foreach (self::MAIL_ENV as $name) {
            $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
            $this->setEnv($name, $vars[$name] ?? null);
        }

        try {
            return require config_path('mail.php');
        } finally {
            foreach ($saved as $name => [$server, $env, $putenv]) {
                unset($_SERVER[$name], $_ENV[$name]);
                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }
                if ($env !== null) {
                    $_ENV[$name] = $env;
                }
                putenv($putenv === false ? $name : "{$name}={$putenv}");
            }
        }
    }

    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);

            return;
        }

        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv("{$name}={$value}");
    }

    /**
     * Construit le vrai transport SMTP de Laravel (sans jamais le démarrer).
     *
     * @return array{0: EsmtpTransport, 1: SocketStream}
     */
    private function buildTransport(array $smtp): array
    {
        $transport = $this->app->make('mail.manager')->createSymfonyTransport($smtp);
        $stream = $transport->getStream();

        $this->assertInstanceOf(SocketStream::class, $stream);

        return [$transport, $stream];
    }
}
