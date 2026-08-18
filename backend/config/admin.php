<?php

return [
    /*
    | Initial operator login. Set these in the project `.env` next to
    | docker-compose.yml (ADMIN_EMAIL, ADMIN_PASSWORD, ADMIN_NAME).
    | The seeder creates the user on first boot if the password is set.
    */
    'email' => env('ADMIN_EMAIL', 'admin@epanel-build.com'),
    'password' => env('ADMIN_PASSWORD'),
    'name' => env('ADMIN_NAME', 'Администратор'),
];
