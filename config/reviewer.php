<?php

/*
|--------------------------------------------------------------------------
| Reviewer account
|--------------------------------------------------------------------------
|
| The account `php artisan reviewer:create` sets up for the assessment's
| reviewers. The password comes only from the environment, never from the
| repository; leave it empty to have the command generate and print one.
|
*/

return [
    'name' => env('REVIEWER_NAME', 'Reviewer'),
    'email' => env('REVIEWER_EMAIL', 'reviewer@example.com'),
    'password' => env('REVIEWER_PASSWORD'),
];
