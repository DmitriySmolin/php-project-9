<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
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
$container = new Container();

$container->set('database_connection', function () {
    return new DatabaseConnection();
});

$container->set('table_manager', function ($container) {
    $databaseConnection = $container->get('database_connection');
    $pdo = $databaseConnection->getConnection();
    return new UrlDatabaseManager($pdo);
});

$container->set('renderer', function () use ($container) {
    // Параметром передается базовая директория, в которой будут храниться шаблоны
    $phpView = new PhpRenderer(__DIR__ . '/../templates');

    // Добавляем объект маршрутизатора в контекст шаблона
    $phpView->addAttribute('router', $container->get('router'));
    $phpView->setLayout('layout.phtml');
    return $phpView;
});


$container->set('flash', function () {
    return new Messages();
});

$app = AppFactory::createFromContainer($container);

$app->add(function ($request, $handler) use ($container) {
    $renderer = $container->get('renderer');
    $renderer->addAttribute('currentPath', $request->getUri()->getPath());
    return $handler->handle($request);
});

$app->add(MethodOverrideMiddleware::class);
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$customErrorHandler = function ($request, $exception, $displayDebug) use ($app) {

    $response = $app->getResponseFactory()->createResponse();
    if ($exception->getCode() === 404) {
        return $this->get('renderer')->render($response, "404.phtml")
            ->withStatus(404);
    }

    if ($displayDebug) {
        throw $exception;
    }

    return $response;
};

$errorMiddleware->setDefaultErrorHandler($customErrorHandler);

$container->set('router', $app->getRouteCollector()->getRouteParser());
session_start();

$app->get('/', function ($request, $response) {
    $params = [
        'errors' => []
    ];

    return $this->get('renderer')->render($response, 'index.phtml', $params);
})->setName('index');

$app->post('/urls', function ($request, $response) {
    $tableManager = $this->get('table_manager');
    $router = $this->get('router');
    $url = $request->getParsedBodyParam('url');
    $validator = new Validator($url);
    $validator->rule('required', 'name')->message('URL не должен быть пустым')
        ->rule('url', 'name')->message('Некорректный URL')
        ->rule('lengthMax', 'name', 255)->message('URL не должен превышать 255 символов');

    if (!$validator->validate()) {
        $errors = $validator->errors();
        $params = [
            'url' => $url['name'],
            'errors' => $errors,
            'invalidForm' => 'is-invalid'
        ];
        return $this->get('renderer')->render($response->withStatus(422), 'index.phtml', $params);
    }

    $url = mb_strtolower($url['name']);
    $parsedUrl = parse_url($url);
    $urlName = "{$parsedUrl["scheme"]}://{$parsedUrl["host"]}";

    $flashMessage = 'Страница уже существует';

    if (!$tableManager->urlExists($urlName)) {
        $flashMessage = 'Страница успешно добавлена';
        $tableManager->insertUrl($urlName);
    }

    $this->get('flash')->addMessage('success', $flashMessage);

    $id = $tableManager->getUrlByName($urlName);
    $url = $router->urlFor('urls.show', ['id' => $id]);

    return $response->withRedirect($url);
})->setName('urls.create');

$app->get('/urls', function ($request, $response) {
    $tableManager = $this->get('table_manager');
    $urls = $tableManager->getAllUrls();
    $params = [
        'urls' => $urls
    ];
    return $this->get('renderer')->render($response, 'urls.phtml', $params);
})->setName('urls.index');

$app->get('/urls/{id:[0-9]+}', function ($request, $response, array $args) {
    $tableManager = $this->get('table_manager');
    $id = $args['id'];
    $url = $tableManager->getUrlById($id);
    if (!$url) {
        return $this->get('renderer')->render($response, '404.phtml')
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
    return $this->get('renderer')->render($response, 'show.phtml', $params);
})->setName('urls.show');

$app->post('/urls/{id}/checks', function ($request, $response, array $args) {
    $tableManager = $this->get('table_manager');
    $id = $args['id'];
    $client = new Client();
    $router = $this->get('router');
    $urlName = $tableManager->getUrlById($id)['name'];
    $flashMessages = $this->get('flash');

    try {
        $successResponse = $client->request('GET', $urlName);
        $statusCode = $successResponse->getStatusCode();
        $body = (string) $successResponse->getBody();
        $tableManager->insertCheckUrl($id, ['statusCode' => $statusCode, 'body' => $body]);
        $flashMessages->addMessage('success', 'Страница успешно проверена');
    } catch (RequestException $exception) {
        if ($exception->hasResponse()) {
            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();
        }
        $tableManager->insertCheckUrl($id, ['statusCode' => $statusCode, 'body' => $body]);
        $flashMessages->addMessage('error', 'Проверка была выполнена успешно, но сервер ответил с ошибкой');
    } catch (ConnectException) {
        $errorMessage = 'Произошла ошибка при проверке, не удалось подключиться';
        $flashMessages->addMessage('danger', $errorMessage);
        $url = $router->urlFor('urls.show', ['id' => $id]);
        return $response->withRedirect($url, 302);
    }

    $url = $router->urlFor('urls.show', ['id' => $id]);
    return $response->withRedirect($url, 302);
})->setName('urls.checks.create');

$app->run();
