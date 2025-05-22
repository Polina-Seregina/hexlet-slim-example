<?php

//подключение автозагрузки через composer
require __DIR__.'/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Slim\Middleware\MethodOverrideMiddleware; //поддержка переопределения метода в Слим (html post->patch)

// Старт PHP сессии
session_start();


function getUser($id) {
    $users = json_decode(file_get_contents(__DIR__ . '/usersdata'), $associative = true);
    foreach ($users as $user) {
        if ($user['id'] === $id) {
            return $user;
        }
    }
    return null;
}

$container = new Container();
$container->set('renderer', function () {
    // Параметром передается базовая директория, в которой будут храниться шаблоны
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);
$app->add(MethodOverrideMiddleware::class); // поддержка переопределения метода в Слим (html post->patch)

$app->get('/', function ($request, $response) {
	$response->getBody()->write('Welcome to my TEST project!');
	return $response;
    // Благодаря пакету slim/http этот же код можно записать короче
    // return $response->write('Welcome to Slim!');
});

// получение данных о пользователях из файла, потом из БД
$users = json_decode(file_get_contents(__DIR__ . '/usersdata'), $associative = true);

class Validator

{
    public function validate($user) {
        $errors = [];

        if (empty($user['nickname'])) {
            $errors['nickname'] = 'Can`t be blank';
        };
        if (strlen($user['nickname']) < 5) {
            $errors['nickname'] = 'must be more than 4 characters';
        };
        if (empty($user['email'])) {
            $errors['email'] = 'Can`t be blank';
        };
        return $errors;
    }
} 

// обработчик для страницы /users с формой для поиска 
$app->get('/users', function ($request, $response) use ($users) {
    $term = $request->getQueryParam('term'); // получаем из запроса параметры поиска
    $messages = $this->get('flash')->getMessages(); // получаем сообщение (обработчик post /users)
    
    if (empty($users)) {
        $params = ['users' => $users, 'term' => $term, 'flash' => $messages]; //записываем параметры в массив и передаем 
        return $this->get('renderer')->render($response, 'users/index.phtml', $params);
    }
    // фильрация пользователей 
    $filteredUsers = array_filter($users, function($user) use ($term) {
        if ($term) {
            return (str_contains($user['nickname'], $term));
        }
        return $user; // фильтруем пользователей согласно условиям 
    });
    $params = ['users' => $filteredUsers, 'term' => $term, 'flash' => $messages]; //записываем параметры в массив и передаем 
	return $this->get('renderer')->render($response, 'users/index.phtml', $params);
})->setName('users');

$app->get('/users/new', function ($request, $response) {
    $user = [];
    $params = [
        'user' => ['nickname' => '', 'email' => ''],
        'errors' => []
    ];
    return $this->get('renderer')->render($response, "users/new_user.phtml", $params);
})->setName('newUser');

$app->get('/users/{id}', function ($request, $response, $args) use ($users) {
   $id = $args['id'];
   $user = getUser($id);
   $messages = $this->get('flash')->getMessages(); // получаем сообщение (обработчик patch /users/id/edit)
   if ($user) { 
        // Указанный путь считается относительно базовой директории для шаблонов, заданной на этапе конфигурации
        // $this доступен внутри анонимной функции благодаря https://php.net/manual/ru/closure.bindto.php
        // $this в Slim это контейнер зависимостей
        $params = ['user' => $user, 'flash' => $messages];
        return $this->get('renderer')->render($response, 'users/show.phtml', $params);
   }

    return $response->withStatus(404);  
})->setName('person');

// Получаем роутер — объект, отвечающий за хранение и обработку маршрутов
$router = $app->getRouteCollector()->getRouteParser();

$app->post('/users', function ($request, $response) use ($users, $router){
    $validator = New Validator();
    $user = $request->getParsedBodyParam('user');
    $user['id'] = uniqid();
    // $user = $request->getParsedBodyParam('user'); так можно получать параметры, если в поле name в шаблоне указать '$user['nickname]'
    $errors = $validator->validate($user);

    if (count($errors) === 0) {
        $users[] = $user;
        file_put_contents(__DIR__ . '/usersdata', json_encode($users));
        //добавляем сообщение об успешности создания пользователя
        $this->get('flash')->addMessage('success', 'User was added successfully');
        return $response->withRedirect($router->urlFor('users'), 302);
    }
    $params = ['user' => $user, 'errors' => $errors];
    $response = $response->withStatus(422);
	return $this->get('renderer')->render($response, '/users/new_user.phtml', $params);
})->setName('postUsers');

// редактирование пользователя 
$app->get('/users/{id}/edit', function($request, $response, $args) use ($users) {
    $id = $args['id'];
    $user = getUser($id);
    $params = ['user' => $user, 'errors' => []];

    return $this->get('renderer')->render($response, 'users/edit.phtml', $params);

})->setName('editUser'); 

// Получаем роутер — объект, отвечающий за хранение и обработку маршрутов
$router = $app->getRouteCollector()->getRouteParser();

$app->patch('/users/{id}', function ($request, $response, $args) use ($users, $router){
    $id = $args['id'];
    $user = getUser($id);
    $newNickname = $request->getParsedBodyParam('nickname');
    $newEmail = $request->getParsedBodyParam('email');

    $updateUser = ['nickname' => $newNickname, 'email' => $newEmail, 'id' => $id];
    $numOfUser = array_search($user, $users);

    $validator = New Validator();
    $errors = $validator->validate($updateUser);

    if (count($errors) === 0) {
        $users[$numOfUser] = $updateUser;
        file_put_contents(__DIR__ . '/usersdata', json_encode($users));
        $this->get('flash')->addMessage('success', 'User has been updated');
        $url = $router->urlFor('person', ['id' => $id]);
        return $response->withRedirect($url);
    }
    $params = ['user' => $user, 'errors' => $errors];
    $response = $response->withStatus(422);
	return $this->get('renderer')->render($response, '/users/edit.phtml', $params);
});

$app->get('/users/{id}/delete', function($request, $response, $args) use ($users) {
    $id = $args['id'];
    $user = getUser($id);
    $params = ['user' => $user];
    return $this->get('renderer')->render($response, 'users/delete.phtml', $params);
}); 

$app->delete('/users/{id}', function($request, $response, $args) use ($users, $router) {
    $id = $args['id'];
    $user = getUser($id);
    $updateUsers = [];

    foreach ($users as $curUser) {
        if (!($curUser === $user)) {
            $updateUsers[] = $curUser;
        }
    }
    file_put_contents(__DIR__ . '/usersdata', json_encode($updateUsers));
    $this->get('flash')->addMessage('success', 'user deleted');

    $url = $router->urlFor('users');
    return $response->withRedirect($url);
});



$app->run();
