<?php

declare(strict_types=1);

namespace CRM\Config;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;
    private static bool $envArquivoCarregado = false;

    /**
     * Antes criava uma conexao NOVA a cada chamada - algumas paginas
     * (campanhas.php, configuracoes/usuarios.php, configuracoes/cargas.php)
     * instanciam mais de um repositorio e acabavam abrindo 2 conexoes
     * MySQL pra a MESMA requisicao. Agora cacheia uma conexao por processo
     * PHP (reaproveitada entre requisicoes no mesmo worker do PHP-FPM
     * tambem, o que e desejavel). Antes de reusar, testa com um SELECT 1 -
     * mesma logica de "nao confiar em conexao cacheada sem checar" ja
     * aplicada a RedisConnection (2026-09-16): sem isso, uma conexao
     * cacheada que sobreviveu a um restart do mysqld ficaria presa em erro
     * pro resto da vida do worker.
     */
    public static function pdo(): PDO
    {
        self::loadEnvFile(dirname(__DIR__, 2) . '/.env.local');

        if (self::$pdo instanceof PDO) {
            try {
                self::$pdo->query('SELECT 1');
                return self::$pdo;
            } catch (\Throwable) {
                self::$pdo = null;
            }
        }

        $host = self::env('DB_HOST', 'mysql');
        $port = self::env('DB_PORT', '3306');
        $database = self::env('DB_DATABASE', 'crm_dev');
        $username = self::env('DB_USERNAME', 'crm_app');
        $password = self::env('DB_PASSWORD', '');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);

        return self::$pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci, time_zone = '-03:00'",
        ]);
    }

    public static function env(string $key, ?string $default = null): ?string
    {
        // Variavel que so existe no arquivo (ex.: API_TRUSTED_PROXIES) vale
        // mesmo antes do primeiro pdo() - o bootstrap le config antes dele.
        self::loadEnvFile(dirname(__DIR__, 2) . '/.env.local');
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    private static function loadEnvFile(string $path): void
    {
        if (self::$envArquivoCarregado) {
            return;
        }
        self::$envArquivoCarregado = true;

        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $value;
            }

            if (!isset($_SERVER[$key])) {
                $_SERVER[$key] = $value;
            }
        }
    }
}
