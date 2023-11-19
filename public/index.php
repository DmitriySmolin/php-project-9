<?php

use Slim\Factory\AppFactory;
use DI\Container;
use Slim\Flash\Messages;
use Slim\Views\PhpRenderer;
use Database\DatabaseConnection;
use Database\UrlDatabaseManager;
use Carbon\Carbon;
use Valitron\Validator;

require __DIR__ . '/../vendor/autoload.php';

$database = new DatabaseConnection();
$pdo = $database->getConnection();
$tableCreator = new UrlDatabaseManager($pdo);

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
$currentDateTime = Carbon::now();
session_start();

$app->get('/', function ($req, $res) use ($tableCreator) {

    if (!$tableCreator->tableExists('urls')) {
        $tableCreator->createTables();
    }

    $params = [
        'errors' => []
    ];

    return $this->get('renderer')->render($res, 'index.phtml', $params);
})->setName('home');

$app->post('/urls', function ($req, $res) use ($router, $tableCreator, $currentDateTime) {

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

    if (!$tableCreator->urlExists($urlName)) {
        $flashMessage = 'Страница созданна';
        $tableCreator->insertUrl($urlName, $currentDateTime);
    }

    $this->get('flash')->addMessage('success', $flashMessage);

    $id = $tableCreator->getUrlById($urlName);
    $url = $router->urlFor('urls', ['id' => $id]);

    return $res->withRedirect($url);
});

$app->get('/urls', function ($req, $res) use ($tableCreator) {

    $urls = $tableCreator->selectAllUrls();
    $params = [
        'urls' => $urls
    ];
    return $this->get('renderer')->render($res, 'urls.phtml', $params);
});

$app->get('/urls/{id}', function ($req, $res, array $args) use ($tableCreator) {

    $id = $args['id'];
    $url = $tableCreator->selectUrlById($id);

    $messages = $this->get('flash')->getMessages();
    $params = [
        'url' => $url,
        'flash' => $messages
    ];
    return $this->get('renderer')->render($res, 'url.phtml', $params);
})->setName('urls');

$app->run();
