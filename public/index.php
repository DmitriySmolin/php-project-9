<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Slim\Factory\AppFactory;
use DI\Container;
use Slim\Flash\Messages;
use Slim\Views\PhpRenderer;
use Database\DatabaseConnection;
use Database\UrlDatabaseManager;
use Valitron\Validator;

require __DIR__ . '/../vendor/autoload.php';

$database = new DatabaseConnection();
$pdo = $database->getConnection();
$tableManager = new UrlDatabaseManager($pdo);

$container = new Container();
$container->set('renderer', function () {
    // Параметром передается базовая директория, в которой будут храниться шаблоны
    return new PhpRenderer(__DIR__ . '/../templates');
});

$container->set('flash', function () {
    return new Messages();
});

$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);

$router = $app->getRouteCollector()->getRouteParser();
session_start();

$app->get('/', function ($req, $res) use ($tableManager) {

    if (!$tableManager->tableExists('urls')) {
        $tableManager->createUrlsTable();
    }
    if (!$tableManager->tableExists('url_checks')) {
        $tableManager->createUrlChecksTable();
    }

    $params = [
        'errors' => []
    ];

    return $this->get('renderer')->render($res, 'index.phtml', $params);
})->setName('home');

$app->post('/urls', function ($req, $res) use ($router, $tableManager) {

    $urls = $req->getParsedBodyParam('urls');
    $validator = new Validator($urls);
    $validator->rules([
        'required' => ['name'],
        'lengthMax' => [['name', 255]],
        'url' => ['name']
    ]);

    if (!$validator->validate()) {
        $params = ['errors' => true];
        return $this->get('renderer')->render($res->withStatus(422), 'index.phtml', $params);
    }

    $parsedUrl = parse_url($urls['name']);
    $urlName = "{$parsedUrl["scheme"]}://{$parsedUrl["host"]}";

    $flashMessage = 'Страница уже существует';

    if (!$tableManager->urlExists($urlName)) {
        $flashMessage = 'Страница успешно добавлена';
        $tableManager->insertUrl($urlName);
    }

    $this->get('flash')->addMessage('success', $flashMessage);

    $id = $tableManager->getUrlByName($urlName);
    $url = $router->urlFor('urls', ['id' => $id]);

    return $res->withRedirect($url);
});

$app->get('/urls', function ($req, $res) use ($tableManager) {

    $urls = $tableManager->getAllUrls();
    $params = [
        'urls' => $urls
    ];
    return $this->get('renderer')->render($res, 'urls.phtml', $params);
})->setName('urls');

$app->get('/urls/{id}', function ($req, $res, array $args) use ($tableManager) {

    $id = $args['id'];
    $url = $tableManager->getUrlById($id);
    $dataChecks = $tableManager->getCheckUrlById($id);

    $messages = $this->get('flash')->getMessages();
    $params = [
        'url' => $url,
        'flash' => $messages,
        'checks' => $dataChecks
    ];
    return $this->get('renderer')->render($res, 'url.phtml', $params);
})->setName('url');

$app->post('/urls/{url_id}/checks', function ($req, $res, array $args) use ($tableManager, $router) {
    $id = $args['url_id'];
    $client = new Client();

    $urlName = $tableManager->getUrlById($id)['name'];

    try {
        $response = $client->request('GET', $urlName);
        $tableManager->insertCheckUrl($id, $response);
        $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    } catch (ClientException $e) {
        $this->get('flash')->addMessage('error', 'Ошибка при проверке страницы');
    }
    $url = $router->urlFor('url', ['id' => $id]);
    return $res->withRedirect($url, 302);
})->setName('checkUrl');

$app->run();
