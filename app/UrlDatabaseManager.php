<?php

namespace Database;

use Exception;
use PDO;

/**
 * Создание в PostgreSQL таблицы из демонстрации PHP
 */
class UrlDatabaseManager
{
    private $pdoInstance;

    public function __construct($pdoInstance)
    {
        $this->pdoInstance = $pdoInstance;
    }

    public function createTables(): static
    {
        $sqlQuery = 'CREATE TABLE urls (
            id bigint PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
            name varchar(255),
            created_at timestamp
        );';

        $this->executeQuery($sqlQuery);
        return $this;
    }

    public function tableExists($tableName): bool
    {
        try {
            $queryResult = $this->pdoInstance->query("SELECT 1 FROM {$tableName} LIMIT 1");
        } catch (Exception $exception) {
            return false;
        }

        return $queryResult !== false;
    }

    public function urlExists($name): bool
    {
        $sqlQuery = 'SELECT * FROM urls WHERE name = :name';
        $queryResult = $this->fetchSingleRow($sqlQuery, [':name' => $name]);
        return $queryResult !== false;
    }

    public function getUrlById($urlName): mixed
    {
        $sqlQuery = 'SELECT id FROM urls WHERE name = :name';
        $queryResult = $this->fetchSingleRow($sqlQuery, [':name' => $urlName]);
        return $queryResult['id'] ?? null;
    }

    public function insertUrl($urlName, $creationDate): void
    {
        $sqlQuery = 'INSERT INTO urls (name, created_at) VALUES (:name, :created_at)';
        $this->executeQuery($sqlQuery, [':name' => $urlName, ':created_at' => $creationDate]);
    }

    public function selectUrlById($id)
    {
        $sqlQuery = "SELECT * FROM urls WHERE id = :id";
        return $this->fetchSingleRow($sqlQuery, [':id' => $id]);
    }

    public function selectAllUrls()
    {
        $sqlQuery = "SELECT * FROM urls ORDER BY created_at DESC";
        return $this->fetchAllRows($sqlQuery);
    }

    private function executeQuery($sqlQuery, $params = []): void
    {
        $pdoQuery = $this->pdoInstance->prepare($sqlQuery);
        $pdoQuery->execute($params);
    }

    private function fetchSingleRow($sqlQuery, $params = [])
    {
        $pdoQuery = $this->pdoInstance->prepare($sqlQuery);
        $pdoQuery->execute($params);
        return $pdoQuery->fetch(PDO::FETCH_ASSOC);
    }

    private function fetchAllRows($sqlQuery, $params = [])
    {
        $pdoQuery = $this->pdoInstance->prepare($sqlQuery);
        $pdoQuery->execute($params);
        return $pdoQuery->fetchAll(PDO::FETCH_ASSOC);
    }
}
