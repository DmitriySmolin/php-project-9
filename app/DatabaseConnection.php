<?php

namespace Database;

use Exception;
use PDO;

class DatabaseConnection
{
    private PDO $pdoConnection;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        isset($_ENV['DATABASE_URL'])
            ? $connectionString = $this->getConnectionStringFromEnv()
            : $connectionString = $this->getConnectionStringFromIni();

        $this->pdoConnection = new PDO($connectionString);
        $this->pdoConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @throws Exception
     */
    private function getConnectionStringFromEnv(): string
    {
        $databaseUrl = parse_url((string)$_ENV['DATABASE_URL']);

        if (
            !$databaseUrl || !isset(
                $databaseUrl['host'],
                $databaseUrl['path'],
                $databaseUrl['user'],
                $databaseUrl['pass']
            )
        ) {
            throw new Exception("Invalid DATABASE_URL format");
        }

        return sprintf(
            "pgsql:host=%s;dbname=%s;user=%s;password=%s",
            $databaseUrl['host'],
            ltrim($databaseUrl['path'], '/'),
            $databaseUrl['user'],
            $databaseUrl['pass']
        );
    }

    /**
     * @throws Exception
     */
    private function getConnectionStringFromIni(): string
    {
        $params = parse_ini_file('database.ini');

        if ($params === false) {
            throw new Exception("Error when reading database configuration file");
        }

        return sprintf(
            "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
            $params['host'],
            $params['port'],
            $params['database'],
            $params['user'],
            $params['password']
        );
    }

    public function getConnection(): PDO
    {
        return $this->pdoConnection;
    }
}
