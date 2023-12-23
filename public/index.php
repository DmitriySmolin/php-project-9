<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use http\Client\Response;
use Slim\Factory\AppFactory;
use DI\Container;
use Slim\Flash\Messages;
use Slim\Views\PhpRenderer;
use Database\DatabaseConnection;
use Database\UrlDatabaseManager;
use Valitron\Validator;
use Slim\Middleware\MethodOverrideMiddleware;

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
$app->add(MethodOverrideMiddleware::class);
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$customErrorHandler = function ($req, $e) use ($app) {

    $res = $app->getResponseFactory()->createResponse();
    if ($e->getCode() === 404) {
        return $this->get('renderer')->render($res, "404.phtml")
        ->withStatus(404);
    }

    return $res;
};
$errorMiddleware->setDefaultErrorHandler($customErrorHandler);

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

    $urls = $req->getParsedBodyParam('url');
    $validator = new Validator($urls);
    $validator->rule('required', 'name')->message('URL не должен быть пустым');
    $validator->rule('url', 'name')->message('Некорректный URL');
    $validator->rule('lengthMax', 'name', 255)->message('Некорректный URL');


    if (!$validator->validate()) {
        $errors = $validator->errors();
        $params = [
            'url' => $urls['name'],
            'errors' => $errors,
            'invalidForm' => 'is-invalid'
        ];
        return $this->get('renderer')->render($res->withStatus(422), 'index.phtml', $params);
    }

    $url = strtolower($urls['name']);
    $parsedUrl = parse_url($url);
    $urlName = "{$parsedUrl["scheme"]}://{$parsedUrl["host"]}";

    $flashMessage = 'Страница уже существует';

    if (!$tableManager->urlExists($urlName)) {
        $flashMessage = 'Страница успешно добавлена';
        $tableManager->insertUrl($urlName);
    }

    $this->get('flash')->addMessage('success', $flashMessage);

    $id = $tableManager->getUrlByName($urlName);
    $url = $router->urlFor('url', ['id' => $id]);

    return $res->withRedirect($url);
});

$app->get('/urls', function ($req, $res) use ($tableManager) {

    $urls = $tableManager->getAllUrls();
    $params = [
        'urls' => $urls
    ];
    return $this->get('renderer')->render($res, 'urls.phtml', $params);
})->setName('urls');

$app->get('/urls/{id:[0-9]+}', function ($req, $res, array $args) use ($tableManager) {

    $id = $args['id'];
    $url = $tableManager->getUrlById($id);
    if (empty($url)) {
        return $this->get('renderer')->render($req, '404.phtml')
            ->withStatus(404);
    }
    $dataChecks = $tableManager->getCheckUrlById($id);

    $messages = $this->get('flash')->getMessages();
    $alert = '';
    switch (key($messages)) {
        case 'success':
            $alert = 'success';
            break;
        case 'error':
            $alert = 'warning';
            break;
        case 'danger':
            $alert = 'danger';
            break;
    }
    $params = [
        'url' => $url,
        'flash' => $messages,
        'checks' => $dataChecks,
        'alert' => $alert
    ];
    return $this->get('renderer')->render($res, 'url.phtml', $params);
})->setName('url');

$app->post('/urls/{url_id}/checks', function ($req, $res, array $args) use ($tableManager, $router) {
    $id = $args['url_id'];
    $client = new Client();

    $urlName = $tableManager->getUrlById($id)['name'];

    try {
        $response = $client->request('GET', $urlName);
        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();
        $tableManager->insertCheckUrl($id, ['statusCode' => $statusCode, 'body' => $body]);
        $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    } catch (RequestException $e) {
        $response = $e->getResponse();
        if ($response instanceof Psr\Http\Message\ResponseInterface) {
            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();
        }
        $tableManager->insertCheckUrl($id, ['statusCode' => $statusCode, 'body' => $body]);
        $this->get('flash')->addMessage('error', 'Проверка была выполнена успешно, но сервер ответил с ошибкой');

        if (!$res) {
            $res = new Response($statusCode);
        }
    } catch (ConnectException $e) {
        $errorMessage = 'Произошла ошибка при проверке, не удалось подключиться';
        $this->get('flash')->addMessage('danger', $errorMessage);
        return $this->get('renderer')->render($res, '500.phtml')->withStatus(500);
    }

    $url = $router->urlFor('url', ['id' => $id]);
    return $res->withRedirect($url, 302);
})->setName('checkUrl');

$app->run();
