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
        $params = parse_ini_file('database.ini');

        if ($params === false) {
            throw new Exception("Error when reading database configuration file: ");
        }

        $connectionString = sprintf(
            "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
            $params ['host'],
            $params ['port'],
            $params ['database'],
            $params ['user'],
            $params ['password']
        );

        $this->pdoConnection = new PDO($connectionString);
        $this->pdoConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getConnection(): PDO
    {
        return $this->pdoConnection;
    }
}
