<?php
namespace App;

class UserValidator
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