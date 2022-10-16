# workerman/crontab定时任务管理组件

## 概述

基于 **webman** + **workerman/crontab** 的动态定时任务管理组件<br>
本组件代码基于 **webman crontab任务管理组件(多类型)** https://www.workerman.net/plugin/42 <br>
衍生出来的。<br>

## 介绍
跟原组件区别,去除think-orm依赖,重写任务锁逻辑,解决未知情况下出现死锁，改写多进程任务执行逻辑,代码优化。

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
注意: 示例代码里的6个方法都必须实现.
```shell
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
     * @param $insertData
     * @return mixed 这个方法负责写入任务执行日志
     */
    public function writeRunLog($insertData = [])
    {
        CrontabLogModel::insertGetId($insertData);
    }

    /**
     * @return \Redis 这个方法返回redis实例
     */
    public function getRedisHandle()
    {
        return redisCache();
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

    /**
     * @return \Workerman\Redis\Client 返回workerman异步redis实例
     */
    public function getWorkermanRedis()
    {
        $config= config('redis.default');
        $address = "redis://{$config['host']}:{$config['port']}";
        $redis = new Client($address);
        if ($config['password']){
            $redis->auth($config['password']);
        }
        return $redis;
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
注意,只提供了 添加,重启,删除 三个接口,对任务进行了修改的话,进行重启就好.
## 添加任务
````shell
$param = [
    'method' => 'crontabCreate',
    'args' => ['id' => $id]
];
$result = \FlyCms\WebmanCrontab\Client::instance()->request($param);
````
## 重启任务
````shell
$param = [
        'method' => 'crontabReload',
        'args' => ['id' => $id]
    ];
$result = \FlyCms\WebmanCrontab\Client::instance()->request($param);
````

## 删除任务
````shell
$param = [
    'method' => 'crontabDelete',
    'args' => ['id' => $id]
];
$result = \FlyCms\WebmanCrontab\Client::instance()->request($param);
````
