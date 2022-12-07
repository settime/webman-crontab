<?php
return [
    'enable' => true,
    'listen'            => '0.0.0.0:2345',
    'debug'             => true, //控制台输出日志
    'write_log'         => true,// 任务计划日志
    'redis' => [
        'host' => 'redis://127.0.0.1:6379',
        'options' => [
            'auth' => null,       // 密码，字符串类型，可选参数
        ]
    ]
];
