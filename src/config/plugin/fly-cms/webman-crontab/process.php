<?php

return [
    'webman-crontab'  => [
        'handler'     => \FlyCms\WebmanCrontab\Server::class,
        'count'       => 1,
        'listen' => 'text://0.0.0.0:2345',
    ]
];
