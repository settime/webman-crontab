# workerman/crontab定时任务管理组件

## 概述

基于 **webman** + **workerman/crontab** 的动态定时任务管理组件<br>
本组件代码基于 **webman crontab任务管理组件(多类型)** https://www.workerman.net/plugin/42 <br>
衍生出来的。<br>


## 介绍
当时在使用原组件时，我调试模式频繁重启项目，并且频繁添加修改任务，导致莫名其妙的出现redis死锁,导致任务一直无法正常运行。
基于此，我详细查看了该项目源码后，在他基础上彻底进行了重构，重写了并发加锁逻辑，添加了多进程间基于redis进行通讯，代码
进行了优化，减少一些非必要的依赖，使其适用性更广。<br>


安装

```shell
composer require fly-cms/webman-crontab
```

## 使用
 创建任务数据表,这里数据表名称无限制.
```shell
 CREATE TABLE IF NOT EXISTS `system_crontab`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '任务标题',
  `type` tinyint(1) NOT NULL DEFAULT 1 COMMENT '任务类型 (1 command, 2 class, 3 url, 4 eval)',
  `rule` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '任务执行表达式',
  `target` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '调用任务字符串',
  `parameter` varchar(500)  COMMENT '任务调用参数', 
  `running_times` int(11) NOT NULL DEFAULT '0' COMMENT '已运行次数',
  `last_running_time` int(11) NOT NULL DEFAULT '0' COMMENT '上次运行时间',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '备注',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序，越大越前',
  `status` tinyint(4) NOT NULL DEFAULT 0 COMMENT '任务状态状态[0:禁用;1启用]',
  `create_time` int(11) NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT 0 COMMENT '更新时间',
  `singleton` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否单次执行 (0 是 1 不是)',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `title`(`title`) USING BTREE,
  INDEX `create_time`(`create_time`) USING BTREE,
  INDEX `status`(`status`) USING BTREE,
  INDEX `type`(`type`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '定时器任务表' ROW_FORMAT = DYNAMIC
```
创建日志数据表
```shell
CREATE TABLE IF NOT EXISTS `system_crontab_log`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `crontab_id` bigint UNSIGNED NOT NULL COMMENT '任务id',
  `target` varchar(255) NOT NULL COMMENT '任务调用目标字符串',
  `parameter` varchar(500)  COMMENT '任务调用参数', 
  `exception` text  COMMENT '任务执行或者异常信息输出',
  `return_code` tinyint(1) NOT NULL DEFAULT 0 COMMENT '执行返回状态[0成功; 1失败]',
  `running_time` varchar(10) NOT NULL COMMENT '执行所用时间',
  `create_time` int(11) NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `create_time`(`create_time`) USING BTREE,
  INDEX `crontab_id`(`crontab_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '定时器任务执行日志表' ROW_FORMAT = DYNAMIC
```

到app\process 目录新建自定义进程文件,示例代码如下:<br>
注意: 示例代码里的4个方法都必须实现。
插件不在乎你使用何种orm，你只需要把插件要获取或者修改的数据在该接口进行实现就行。
```shell
<?php

namespace app\process;

use FlyCms\WebmanCrontab\Server;

class WebmanCrontab extends Server
{

    /**
     * @return mixed 这个方法需要返回数据库里所有任务
     */
    public function getAllTask()
    {
        return CrontabModel::select()->toArray();
    }

    /**
     * @param $id
     * @return mixed 这个方法需要根据id返回该条任务数据
     */
    public function getTask($id)
    {
        return CrontabModel::where('id',$id)->find();
    }

    /**
     * @param $insert_data
     * @return mixed 这个方法负责写入任务执行日志
     */
    public function writeRunLog($insert_data = [])
    {
        CrontabLogModel::insertGetId($insert_data);
    }

    /**
     * @param $id
     * @param $last_running_time
     * @return mixed 修改任务最后执行时间与执行次数
     */
    public function updateTaskRunState($id, $last_running_time)
    {
       return  CrontabModel::where('id',$id)
            ->update([
               'last_running_time' => $last_running_time,
                'running_times' => Db::raw(' running_times + 1')
            ]);
    }

}

```

接着到config/process.php 添加自定义进程任务,示例如下:<br>
注意事项,如果监听的地址不是2345端口,请注意修改插件配置端口与之对应
````shell
    'WebmanCrontab' => [
        'handler' => \app\process\WebmanCrontab::class,
        'listen' => 'text://0.0.0.0:2345',
        'count' => 4,
    ],
````
注意,只提供了重启接口,对任务进行任何的修改,直接调用重启接口
这里有必要强调一遍，该组件只提供了一个接口
## 修改任务
````shell
$param = [
        'method' => 'crontabReload',
        'args' => ['id' => '1,2,3']
    ];
$result = \FlyCms\WebmanCrontab\Client::instance()->request($param);
````
