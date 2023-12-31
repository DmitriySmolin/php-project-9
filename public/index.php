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
$container->set('renderer', function () use ($container) {
    // Параметром передается базовая директория, в которой будут храниться шаблоны
    $phpView = new PhpRenderer(__DIR__ . '/../templates');
    $router = $container->get('router');

    // Добавляем объект маршрутизатора в контекст шаблона
    $phpView->addAttribute('router', $router);
    $phpView->setLayout('layout.phtml');
    return $phpView;
});

$container->set('flash', function () {
    return new Messages();
});

$app = AppFactory::createFromContainer($container);
$app->add(MethodOverrideMiddleware::class);
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$customErrorHandler = function ($req, $e, $displayDebug) use ($app) {

    $res = $app->getResponseFactory()->createResponse();
    if ($e->getCode() === 404) {
        return $this->get('renderer')->render($res, "404.phtml")
            ->withStatus(404);
    }
    return $res;
};
$errorMiddleware->setDefaultErrorHandler($customErrorHandler);

$router = $app->getRouteCollector()->getRouteParser();
$container->set('router', $app->getRouteCollector()->getRouteParser());
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
})->setName('index');

$app->post('/urls', function ($req, $res) use ($tableManager) {
    $router = $this->get('router');
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

    $url = mb_strtolower($urls['name']);
    $parsedUrl = parse_url($url);
    $urlName = "{$parsedUrl["scheme"]}://{$parsedUrl["host"]}";

    $flashMessage = 'Страница уже существует';

    if (!$tableManager->urlExists($urlName)) {
        $flashMessage = 'Страница успешно добавлена';
        $tableManager->insertUrl($urlName);
    }

    $this->get('flash')->addMessage('success', $flashMessage);

    $id = $tableManager->getUrlByName($urlName);
    $url = $router->urlFor('show', ['id' => $id]);

    return $res->withRedirect($url);
})->setName('store');

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
    if (!$url) {
        return $this->get('renderer')->render($res, '404.phtml')
            ->withStatus(404);
    }
    $dataChecks = $tableManager->getCheckUrlById($id);

    $messages = $this->get('flash')->getMessages();

    $alert = match (key($messages)) {
        'success' => 'success',
        'error' => 'warning',
        'danger' => 'danger',
        default => empty($messages) ? 'default' : throw new Error("Unknown messages: {key($messages)}!"),
    };

    $params = [
        'url' => $url,
        'flash' => $messages,
        'checks' => $dataChecks,
        'alert' => $alert
    ];
    return $this->get('renderer')->render($res, 'show.phtml', $params);
})->setName('show');

$app->post('/urls/{id}/checks', function ($req, $res, array $args) use ($tableManager) {
    $id = $args['id'];
    $client = new Client();
    $router = $this->get('router');
    $urlName = $tableManager->getUrlById($id)['name'];

    try {
        $response = $client->request('GET', $urlName);
        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();
        $tableManager->insertCheckUrl($id, ['statusCode' => $statusCode, 'body' => $body]);
        $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    } catch (RequestException $e) {
        $statusCode = null;
        $body = '';
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
        $url = $router->urlFor('show', ['id' => $id]);
        return $res->withRedirect($url, 302);
    }

    $url = $router->urlFor('show', ['id' => $id]);
    return $res->withRedirect($url, 302);
})->setName('url-checks');

$app->run();
