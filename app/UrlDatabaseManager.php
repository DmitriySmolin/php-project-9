<?php

namespace Database;

use Carbon\Carbon;
use DiDom\Exceptions\InvalidSelectorException;
use Exception;
use InvalidArgumentException;
use Parser\Parser;
use PDO;

/**
 * Создание в PostgreSQL таблицы из демонстрации PHP
 */
class UrlDatabaseManager
{
    private PDO $pdoInstance;

    public function __construct(PDO $pdoInstance)
    {
        $this->pdoInstance = $pdoInstance;
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

    public function getIdByUrlName(string $urlName): ?string
    {
        $sqlQuery = 'SELECT id FROM urls WHERE name = :name';
        $statement = $this->pdoInstance->prepare($sqlQuery);
        $statement->execute([':name' => $urlName]);
        $result = $statement->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['id'] : null;
    }

    public function getUrlById(int $id): ?array
    {
        $sqlQuery = "SELECT * FROM urls WHERE id = :id";
        $statement = $this->pdoInstance->prepare($sqlQuery);
        $statement->execute([':id' => $id]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    public function getAllUrls(): bool|array
    {
        $sqlQueryUrls = 'SELECT id, name FROM urls ORDER BY id DESC';
        $urlsData = $this->pdoInstance->query($sqlQueryUrls)->fetchAll();

        $sqlQueryUrlsCheck = 'SELECT 
                         DISTINCT ON (url_id) url_id, created_at, status_code 
                         FROM url_checks 
                         ORDER BY url_id, created_at DESC';
        $urlChecksData = $this->pdoInstance->query($sqlQueryUrlsCheck)->fetchAll();

        $urlChecks = collect($urlChecksData)->keyBy('url_id');
        $urls = collect($urlsData);

        return $urls->map(function ($url) use ($urlChecks) {
            return array_merge($url, $urlChecks->get($url['id'], []));
        })->all();
    }

    /**
     * @throws InvalidSelectorException
     */
    public function insertCheckUrl(int $urlId, int $statusCode, string $body): void
    {
        $currentDateTime = Carbon::now();

        $sql = "INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at) 
            VALUES (:url_id, :status_code, :h1, :title, :description, :created_at)";
        $statement = $this->pdoInstance->prepare($sql);

        if ($body === '') {
            throw new InvalidArgumentException('HTML body is empty.');
        }

        $parsedData = Parser::parseHtml($body);

        $statement->execute([
            ':description' => $parsedData['description'],
            ':title' => $parsedData['title'],
            ':h1' => $parsedData['h1'],
            ':url_id' => $urlId,
            ':status_code' => $statusCode,
            ':created_at' => $currentDateTime,
        ]);
    }

    public function getCheckUrlById(int $id): bool|array
    {
        $sql = "SELECT * FROM url_checks WHERE url_id = :id
        ORDER BY created_at DESC";
        $statement = $this->pdoInstance->prepare($sql);
        $statement->execute([':id' => $id]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
