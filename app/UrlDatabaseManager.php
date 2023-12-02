<?php

namespace Database;

use Carbon\Carbon;
use DiDom\Document;
use Exception;
use PDO;

/**
 * Создание в PostgreSQL таблицы из демонстрации PHP
 */
class UrlDatabaseManager
{
    private $pdoInstance;

    public function __construct(PDO $pdoInstance)
    {
        $this->pdoInstance = $pdoInstance;
    }

    public function createUrlsTable(): static
    {
        $sqlQuery = 'CREATE TABLE IF NOT EXISTS urls (
               id bigint PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
               name varchar(255),
               created_at timestamp
               )';

        $this->pdoInstance->prepare($sqlQuery)->execute();

        return $this;
    }

    public function createUrlChecksTable(): static
    {
        $sqlQuery = 'CREATE TABLE IF NOT EXISTS url_checks (
               id bigint PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
               url_id bigint REFERENCES urls (id),
               status_code int,
               h1 varchar(255),
               title varchar(255),
               description varchar(255),
               created_at timestamp
              )';

        $this->pdoInstance->prepare($sqlQuery)->execute();

        return $this;
    }

    public function insertUrl(string $urlName): void
    {
        $currentDateTime = Carbon::now();
        $sqlQuery = 'INSERT INTO urls (name, created_at) VALUES (:name, :created_at)';
        $this->pdoInstance->prepare($sqlQuery)->execute([':name' => $urlName, ':created_at' => $currentDateTime]);
    }

    public function tableExists(string $tableName): bool
    {
        try {
            $queryResult = $this->pdoInstance->query("SELECT 1 FROM {$tableName} LIMIT 1");
        } catch (Exception $exception) {
            return false;
        }

        return $queryResult !== false;
    }

    public function urlExists(string $name): bool
    {
        $sqlQuery = 'SELECT * FROM urls WHERE name = :name';
        $statement = $this->pdoInstance->prepare($sqlQuery);
        $statement->execute([':name' => $name]);
        return $statement->rowCount() > 0;
    }

    public function getUrlByName(string $urlName): mixed
    {
        $sqlQuery = 'SELECT id FROM urls WHERE name = :name';
        $statement = $this->pdoInstance->prepare($sqlQuery);
        $statement->execute([':name' => $urlName]);
        $result = $statement->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['id'] : null;
    }

    public function getUrlById(int $id): mixed
    {
        $sqlQuery = "SELECT * FROM urls WHERE id = :id";
        $statement = $this->pdoInstance->prepare($sqlQuery);
        $statement->execute([':id' => $id]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    public function getAllUrls(): bool|array
    {
        $sqlQuery = "SELECT urls.name, urls.id, MAX(url_checks.created_at) AS created_at, url_checks.status_code 
        FROM urls LEFT JOIN url_checks ON urls.id = url_checks.url_id
        GROUP BY (urls.name, urls.id, url_checks.status_code);";
        $statement = $this->pdoInstance->query($sqlQuery);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertCheckUrl(int $urlId, $resUrl): void
    {
        $currentDateTime = Carbon::now();

        $sql = "INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at) 
        VALUES (:url_id, :status_code, :h1, :title, :description, :created_at)";
        $statement = $this->pdoInstance->prepare($sql);

        $statusCode = $resUrl->getStatusCode();
        $body = $resUrl->getBody()->getContents();
        $document = new Document($body);

        $h1 = $document->has('h1') ? $document->find('h1')[0]->text() : null;
        $title = $document->has('title') ? $document->find('title')[0]->text() : null;
        $description = $document->has('meta[name=description]')
            ? $document->find('meta[name=description]')[0]
                ->attr('content') : null;

        $statement->execute([
            ':description' => $description,
            ':title' => $title,
            ':h1' => $h1,
            ':url_id' => $urlId,
            ':status_code' => $statusCode,
            ':created_at' => $currentDateTime,
        ]);
    }

    public function getCheckUrlById(int $id): bool|array
    {
        $sql = "SELECT * FROM url_checks WHERE url_id = :id
        ORDER BY url_checks.id DESC";
        $statement = $this->pdoInstance->prepare($sql);
        $statement->execute([':id' => $id]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
