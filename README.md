# workerman/crontab实现类似宝塔的任务管理

## 概述

基于 **webman** + **workerman/crontab** 的定时任务组件<br>
本组件代码参考 **webman crontab任务管理组件(多类型)** https://www.workerman.net/plugin/42 <br>
重构出来的。<br>


## 注意事项
***仅支持linux，仅支持linux，仅支持linux。***<br>
***秒级任务不要小于5秒，每个进程计时器会有差异，将会导致任务在同一秒执行不同次数的任务***<br>
***秒级任务必须是60的因数，经过观察发现workerman/crontab处理秒级任务，每分钟后会直接重置任务时间从头开始计算时间***


安装

```shell
composer require fly-cms/webman-crontab
```

## 创建数据表
 创建任务数据表。
```shell
 CREATE TABLE IF NOT EXISTS `cms_crontab`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '任务标题',
  `type` tinyint(1) NOT NULL DEFAULT 1 COMMENT '任务类型 (1 url, 2 eval 3 shell)',
  `task_cycle` tinyint(1) NOT NULL DEFAULT 1 COMMENT '任务周期',
  `cycle_rule` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '任务周期规则',
  `rule` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '任务表达式',
  `target` text  COMMENT '调用任务字符串',
  `running_times` int(11) NOT NULL DEFAULT '0' COMMENT '已运行次数',
  `last_running_time` int(11) NOT NULL DEFAULT '0' COMMENT '上次运行时间',
  `status` tinyint(4) NOT NULL DEFAULT 0 COMMENT '任务状态状态[0:禁用;1启用]',
  `create_time` int(11) NOT NULL DEFAULT 0 COMMENT '创建时间',
  `delete_time` int(11) NOT NULL DEFAULT 0 COMMENT '软删除时间',
  `singleton` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否单次执行 (0 是 1 不是)',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `title`(`title`) USING BTREE,
  INDEX `status`(`status`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '定时器任务表' ROW_FORMAT = DYNAMIC
```
创建日志数据表
```shell
CREATE TABLE IF NOT EXISTS `cms_crontab_log`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `crontab_id` bigint UNSIGNED NOT NULL COMMENT '任务id',
  `target` varchar(255) COMMENT '任务调用目标字符串',
  `log` text  COMMENT '任务执行日志',
  `return_code` tinyint(1) NOT NULL DEFAULT 0 COMMENT '执行返回状态[0成功; 1失败]',
  `running_time` varchar(10) NOT NULL COMMENT '执行所用时间',
  `create_time` int(11) NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `create_time`(`create_time`) USING BTREE,
  INDEX `crontab_id`(`crontab_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '定时器任务执行日志表' ROW_FORMAT = DYNAMIC
```

## 修改配置信息
 请仔细观看下面 getAllTask ， getTask ， writeRunLog ，updateTaskRunState 四个方法，并按要求实现类似结果<br>
 示例代码如下:<br>
```shell

return [
    'enable' => true,
    'listen'            => '0.0.0.0:2345',
    'debug'             => true, //控制台输出日志
    'write_log'         => true,// 是否记录任务日志
    'redis' => [
        'host' => 'redis://127.0.0.1:6379',
        'options' => [
            'auth' => null,       // 密码，字符串类型，可选参数
        ]
    ],
    'task_handle' => [ //任务操作类
        1 => \FlyCms\WebmanCrontab\event\UrlTask::class,
        2 => \FlyCms\WebmanCrontab\event\EvalTask::class,
        3 => \FlyCms\WebmanCrontab\event\ShellTask::class
    ],
    'getAllTask' => function(){
        //获取所有任务
        return \app\model\CrontabModel::select()->toArray();
    },
    'getTask' => function($id){
        //获取某个任务
        return \app\model\CrontabModel::where('id',$id)->find();
    },
    'writeRunLog' => function($insert_data){
        //写入运行日志,注意，这个是日志模型，跟其它方法的模型不一样
        \app\model\CrontabLogModel::insertGetId($insert_data);
    },
    'updateTaskRunState' => function($id, $last_running_time){
        //更新任务最后运行时间,这里要把运行次数加 1
        return  \app\model\CrontabModel::where('id',$id)
            ->update([
                'last_running_time' => $last_running_time,
                'running_times' => \think\facade\Db::raw(' running_times + 1')
            ]);
    }
];

```

接着打开 process.php 示例如下:<br>
***count 设置定时任务进程数<br>***
***这里的端口要与上面配置的listen端口进行对应***<br>
***检查宝塔或者服务器对应防火墙端口是否打开***
````shell
return [
    'webman-crontab'  => [
        'handler'     => \FlyCms\WebmanCrontab\Server::class,
        'count'       => 1,
        'listen' => 'text://0.0.0.0:2345',
    ]
];
````

## 用法
第一步,创建路由
```shell
use app\admin\controller\TaskSet;
use Webman\Route;

Route::any('/admin/taskSet/index',[TaskSet::class,'index']);
Route::any('/admin/taskSet/list',[TaskSet::class,'list']);
Route::any('/admin/taskSet/edit',[TaskSet::class,'edit']);
Route::any('/admin/taskSet/updateOne',[TaskSet::class,'updateOne']);
Route::any('/admin/taskSet/get',[TaskSet::class,'get']);
Route::any('/admin/taskSet/getLog',[TaskSet::class,'getLog']);
Route::any('/admin/taskSet/reloadTask',[TaskSet::class,'reloadTask']);
Route::any('/admin/taskSet/delete',[TaskSet::class,'delete']);
```
第二步,导入插件test目录的TaskSet控制器类<br>
***这里你需要做的功能是***<br>
1 创建对应模型类<br>
2 edit方法添加对应的参数校验

第三步，导入插件test目录的taskSet.html文件<br>
因为删掉项目封装代码原因，该文件只实现部分功能，仅供参考，实际请根据自己项目功能去修改


## 扩展任务类型
插件app.php目录里的 task_handle 数组配置任务解析类。

```shell
   'task_handle' => [ //任务操作类
        1 => \FlyCms\WebmanCrontab\event\UrlTask::class,
        2 => \FlyCms\WebmanCrontab\event\EvalTask::class,
        3 => \FlyCms\WebmanCrontab\event\ShellTask::class,
        4 => 'xxx解析类'，
        5 => 'xxx解析类'，
    ],
```

任务解析类你必须实现下面方法，并且返回code与log字段，code 0 代表成功，1 失败，log字段必须为string类型
```shell
    /**
     * @param $crontab
     * @return array
     */
    public static function parse($crontab){

        return ['log'=> $log, 'code' => $code];
    }

```

## 常见错误
1 未正确配置redis信息<br>
2 未正确配置端口信息<br>
3 未实现配置信息里面的 getAllTask ,getTask ,writeRunLog,updateTaskRunState四个方法<br>
4 端口未放行导致无法通讯

