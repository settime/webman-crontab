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
    ],
    'getAllTask' => function(){
        //获取所有任务

    },
    'getTask' => function($id){
        //获取某个任务

    },
    'writeRunLog' => function($insert_data){
        //写入运行日志

    },
    'updateTaskRunState' => function($id, $last_running_time){
        //更新任务最后运行时间,这里要把运行次数加 1

    }
];
