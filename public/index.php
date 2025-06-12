<?php

//подключение автозагрузки через composer
require __DIR__.'/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Slim\Middleware\MethodOverrideMiddleware; //поддержка переопределения метода в Слим (html post->patch)
use App\UserValidator;
use App\Car;
use App\CarRepository;
use App\CarValidator;

// Старт PHP сессии
session_start();

$container = new Container();
$container->set('renderer', function () {
    // Параметром передается базовая директория, в которой будут храниться шаблоны
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

$container->set(\PDO::class, function () {
    $conn = new \PDO('sqlite:database.sqlite');
    $conn->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    return $conn;
});

$initFilePath = implode('/', [dirname(__DIR__), 'init.sql']);
$initSql = file_get_contents($initFilePath);
$container->get(\PDO::class)->exec($initSql);

$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);
$app->add(MethodOverrideMiddleware::class); // поддержка переопределения метода в Слим (html post->patch)

$app->get('/', function ($request, $response) {
	$response->getBody()->write('Welcome to my TEST project!');
	return $response;
    // Благодаря пакету slim/http этот же код можно записать короче
    // return $response->write('Welcome to Slim!');
});


// обработчик для страницы /users с формой для поиска 
$app->get('/users', function ($request, $response) {
    $term = $request->getQueryParam('term'); // получаем из запроса параметры поиска
    $users = json_decode($request->getCookieParam('users', json_encode([])), true);
    $emails = array_values(array_map(fn($user) => $user['email'], $users));

    $messages = $this->get('flash')->getMessages(); // получаем сообщение (обработчик post /users)
    $authUser = $_SESSION['user'] ?? null;
    // фильрация пользователей 
    if ($term) {
        $filteredUsers = array_filter($users, function($user) use ($term) {
            if ($term) {
                return (str_contains($user['nickname'], $term));
            }
            return $user; // фильтруем пользователей согласно условиям 
        });
        $users = $filteredUsers;
    }
    $params = ['authUser' => $authUser, 'users' => $users, 'term' => $term, 'flash' => $messages]; //записываем параметры в массив и передаем 
	return $this->get('renderer')->render($response, 'users/index.phtml', $params);
})->setName('users');

$app->get('/users/new', function ($request, $response) {
    $user = [];
    $authUser = $_SESSION['user'] ?? null;
    $params = [
        'user' => ['nickname' => '', 'email' => ''],
        'errors' => [],
        'authUser' => $authUser
    ];
    return $this->get('renderer')->render($response, "users/new_user.phtml", $params);
})->setName('newUser');

$app->get('/users/{id}', function ($request, $response, $args) {
   $id = $args['id'];
   $users = json_decode($request->getCookieParam('users', json_encode([])), true);
   $user = $users[$id];

   $authUser = $_SESSION['user'] ?? null;

   $messages = $this->get('flash')->getMessages(); // получаем сообщение (обработчик patch /users/id/edit)
   if (($user) and ($authUser)) { 
        // Указанный путь считается относительно базовой директории для шаблонов, заданной на этапе конфигурации
        // $this доступен внутри анонимной функции благодаря https://php.net/manual/ru/closure.bindto.php
        // $this в Slim это контейнер зависимостей
        $params = ['user' => $user, 'flash' => $messages, 'authUser' => $authUser];
        return $this->get('renderer')->render($response, 'users/show.phtml', $params);
   }

    return $response->withStatus(404);  
})->setName('person');

// Получаем роутер — объект, отвечающий за хранение и обработку маршрутов
$router = $app->getRouteCollector()->getRouteParser();

$app->post('/users', function ($request, $response) use ($router){
    $user = $request->getParsedBodyParam('user'); // так можно получать параметры, если в поле name в шаблоне указать '$user['nickname]'
    $users = json_decode($request->getCookieParam('users', json_encode([])), true);

    $user['id'] = uniqid();
    $id = $user['id'];

    $validator = New UserValidator();
    $errors = $validator->validate($user);

    if (count($errors) === 0) {
        $users[$id] = $user;
        $encodedUsers = json_encode($users);
        $this->get('flash')->addMessage('success', "User was added successfully");
        $url = $router->urlFor('users', ['users' => $users]);
        return $response->withHeader('Set-Cookie', "users={$encodedUsers};Path=/")->withRedirect($url);
    }
    $params = ['user' => $user, 'errors' => $errors];
    $response = $response->withStatus(422);
	return $this->get('renderer')->render($response, '/users/new_user.phtml', $params);
})->setName('postUsers');

// редактирование пользователя 
$app->get('/users/{id}/edit', function($request, $response, $args) {
    $id = $args['id'];
    $users = json_decode($request->getCookieParam('users', json_encode([])), true);
    $user = $users[$id];

    $authUser = $_SESSION['user'] ?? null;

    $params = ['user' => $user, 'errors' => [], 'authUser' => $authUser];

    return $this->get('renderer')->render($response, 'users/edit.phtml', $params);

})->setName('editUser'); 

// Получаем роутер — объект, отвечающий за хранение и обработку маршрутов
$router = $app->getRouteCollector()->getRouteParser();

$app->patch('/users/{id}', function ($request, $response, $args) use ($router){
    $id = $args['id'];
    $users = json_decode($request->getCookieParam('users', json_encode([])), true);
    $user = $users[$id];
    $newNickname = $request->getParsedBodyParam('nickname');
    $newEmail = $request->getParsedBodyParam('email');

    $authUser = $_SESSION['user'] ?? null;

    $updateUser = ['nickname' => $newNickname, 'email' => $newEmail, 'id' => $id];

    $validator = New UserValidator();
    $errors = $validator->validate($updateUser);

    if (count($errors) === 0) {
        $user['nickname'] = $newNickname;
        $user['email'] = $newEmail;
        $users[$id] = $user;
        $encodedUsers = json_encode($users);
        $this->get('flash')->addMessage('success', 'User has been updated');
        $url = $router->urlFor('person', ['id' => $id]);
        return $response->withHeader('Set-Cookie', "users={$encodedUsers};Path=/")->withRedirect($url);
    }
    $params = ['user' => $user, 'errors' => $errors, 'authUser' => $authUser];
    $response = $response->withStatus(422);
	return $this->get('renderer')->render($response, '/users/edit.phtml', $params);
});

$app->get('/users/{id}/delete', function($request, $response, $args) {
    $id = $args['id'];
    $users = json_decode($request->getCookieParam('users', json_encode([])), true);
    $user = $users[$id];

    $authUser = $_SESSION['user'] ?? null;

    $params = ['user' => $user, 'authUser' => $authUser];
    return $this->get('renderer')->render($response, 'users/delete.phtml', $params);
}); 
// обработчик удаления сессии 
$app->delete('/users/logout', function($request, $response) use ($router){
    $_SESSION = [];
    session_destroy();
    $url = $router->urlFor('users');
    return $response->withRedirect($url);
});

$app->delete('/users/{id}', function($request, $response, $args) use ($router) {
    $id = $args['id'];
    $users = json_decode($request->getCookieParam('users', json_encode([])), true);
    //$user = $users[$id];
    unset($users[$id]); // так скорее всего не нужно делать, но пока пусть будет так
    /*$updateUsers = [];

    foreach ($users as $curUser) {
        if (!($curUser === $user)) {
            $updateUsers[$curUser['id']] = $curUser;
        }
    }*/
    $encodedUsers = json_encode($users);
    $this->get('flash')->addMessage('success', 'user deleted');

    $url = $router->urlFor('users');
    return $response->withHeader('Set-Cookie', "users={$encodedUsers};Path=/")->withRedirect($url);
});

// обработчики для аутентификации 
$app->post('/users/login', function($request, $response) use ($router) {
    $currUser = $request->getParsedBodyParam('user');
    $users = json_decode($request->getCookieParam('users', json_encode([])), true);
    $emails = array_map(fn($user) => $user['email'], $users);
    if (in_array($currUser['email'], $emails)) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['user'] = $currUser;
        $url = $router->urlFor('users');
        return $response->withRedirect($url);
    } else {
        $this->get('flash')->addMessage('error', 'user does not exist');
    }
    $url = $router->urlFor('users');
    return $response->withRedirect($url);
});

// обработчики для car

$app->get('/cars', function ($request, $response) {
    $carRepository = $this->get(CarRepository::class);
    $cars = $carRepository->getEntities();

    $messages = $this->get('flash')->getMessages();

    $params = [
      'cars' => $cars,
      'flash' => $messages
    ];

    return $this->get('renderer')->render($response, 'cars/index.phtml', $params);
})->setName('cars.index');

$app->get('/cars/new', function ($request, $response) {
    $params = [
        'car' => new Car(),
        'errors' => []
    ];

    return $this->get('renderer')->render($response, 'cars/new.phtml', $params);
})->setName('cars.create');

$app->get('/cars/{id}', function ($request, $response, $args) {
    $carRepository = $this->get(CarRepository::class);
    $id = $args['id'];
    $car = $carRepository->find($id);

    if (is_null($car)) {
        return $response->write('Page not found')->withStatus(404);
    }

    $messages = $this->get('flash')->getMessages();

    $params = [
        'car' => $car,
        'flash' => $messages
    ];

    return $this->get('renderer')->render($response, 'cars/show.phtml', $params);
})->setName('cars.show');

$app->post('/cars', function ($request, $response) use ($router) {
    $carRepository = $this->get(CarRepository::class);
    $carData = $request->getParsedBodyParam('car');

    $validator = new CarValidator();
    $errors = $validator->validate($carData);

    if (count($errors) === 0) {
        $car = Car::fromArray([$carData['make'], $carData['model']]);
        $carRepository->save($car);
        $this->get('flash')->addMessage('success', 'Car was added successfully');
        return $response->withRedirect($router->urlFor('cars.index'));
    }

    $params = [
        'car' => $carData,
        'errors' => $errors
    ];

    return $this->get('renderer')->render($response->withStatus(422), 'cars/new.phtml', $params);
})->setName('cars.store');

$app->get('/cars/{id}/edit', function ($request, $response, $args) {
    $carRepository = $this->get(CarRepository::class);
    $messages = $this->get('flash')->getMessages();
    $id = $args['id'];
    $car = $carRepository->find($id);

    $params = [
        'car' => $car,
        'errors' => [],
        'flash' => $messages
    ];

    return $this->get('renderer')->render($response, 'cars/edit.phtml', $params);
})->setName('cars.edit');

$app->patch('/cars/{id}', function ($request, $response, $args) use ($router) {
    $carRepository = $this->get(CarRepository::class);
    $id = $args['id'];

    $car = $carRepository->find($id);

    if (is_null($car)) {
        return $response->write('Page not found')->withStatus(404);
    }

    $carData = $request->getParsedBodyParam('car');
    $validator = new CarValidator();
    $errors = $validator->validate($carData);

    if (count($errors) === 0) {
        $car->setMake($carData['make']);
        $car->setModel($carData['model']);
        $carRepository->save($car);
        $this->get('flash')->addMessage('success', "Car was updated successfully");
        return $response->withRedirect($router->urlFor('cars.show', $args));
    }

    $params = [
        'car' => $car,
        'errors' => $errors
    ];

    return $this->get('renderer')->render($response->withStatus(422), 'cars/edit.phtml', $params);
});

$app->run();
