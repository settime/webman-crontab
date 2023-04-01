# workerman/crontab实现类似宝塔的任务管理

## 概述

基于 **webman** + **workerman/crontab** 的定时任务组件<br>
本组件代码参考 **webman crontab任务管理组件(多类型)** https://www.workerman.net/plugin/42 <br>
重构出来的。<br>


## 介绍
基于php实现类似宝塔一样的计划任务。<br>


## 注意事项
***仅支持linux，仅支持linux，仅支持linux。***<br>
***秒级任务不要小于5秒***


安装

```shell
composer require fly-cms/webman-crontab
```

## 创建数据表
 创建任务数据表,这里数据表名称无限制.
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
    'getAllTask' => function(){
        //获取所有任务
        return \app\model\CrontabModel::select()->toArray();
    },
    'getTask' => function($id){
        //获取某个任务
        return \app\model\CrontabModel::where('id',$id)->find();
    },
    'writeRunLog' => function($insert_data){
        //写入运行日志
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
***这里的端口要与上面配置的端口进行对应***<br>
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
第二步,创建控制器文件<br>
***这里你需要做的功能是***<br>
1 导入对应模型类<br>
2 edit方法添加对应的参数校验
```shell
<?php

namespace app\admin\controller;

use app\model\CrontabLogModel;
use app\model\CrontabModel;
use FlyCms\WebmanCrontab as Task;

class TaskSet
{


    public function index()
    {
        return view('taskSet', []);
    }

    public function list()
    {
        $page =(int) request()->input('page', 1);
        $limit =(int) request()->input('limit', 10);

        $data = CrontabModel::order(['id' => 'desc'])->append(['rule_name'])->page($page, $limit)->select();
        $count = CrontabModel::count();

        return json([
            'code' => 0,
            'data' => $data,
            'count' => $count,
        ]);
    }

    public function edit()
    {

        $id =(int) request()->input('id');
        $title = request()->input('title');
        $type = request()->input('type');
        $target = request()->input('target');
        $parameter = request()->input('parameter');
        $remark = request()->input('remark');
        $sort = request()->input('sort', 0);
        $status = request()->input('status', 1);
        $singleton = request()->input('singleton', 1);

        $task_cycle = (int)request()->input('task_cycle');
        $month = request()->input('month');
        $week = request()->input('week');
        $day = request()->input('day');
        $hour = request()->input('hour');
        $minute = request()->input('minute');
        $second = request()->input('second');


        // 这里参数验证自己重新实现
//        Validate::make()->isRequire('请输入任务名称')->check($title);
//        Validate::make()->isRequire('请选择任务类型')->check($type);
//        Validate::make()->isRequire('请输入调用目标')->check($target);

        $check_arr = [
            'second' => function () use ($second) {
                //  Validate::make()->isRequire("请输入执行秒数")->isInteger('秒数必须为整数')->isEgt('1','秒数不能小于1')
                //     ->isElt(59, "秒数不能大于59")->check($second);
            },
            'minute' => function () use ($minute) {
                //  Validate::make()->isRequire("请输入执行分钟")->isInteger('分钟必须为整数')->isElt(59, "分钟不能大于59")->check($minute);
            },
            'hour' => function () use ($hour) {
                //    Validate::make()->isRequire("请输入执行小时")->isInteger('小时必须为整数')->isElt(59, "小时不能大于59")->check($hour);
            },
            'day' => function () use ($day) {
                //  Validate::make()->isRequire("请输入执行天数")->isInteger('天数必须为整数')->isElt(31, "天数不能大于31")->check($day);
            },
            'week' => function () use ($week) {
                //   Validate::make()->isRequire("请输入星期几执行")->isInteger('星期几必须为整数')->isElt(6, "星期几不能大于6")->check($week);
            },
            'month' => function () use ($month) {
                //   Validate::make()->isRequire("请输入执行月份")->isInteger('月份必须为整数')->isElt(12, "月份不能大于12")->check($month);
            }
        ];

        //解析规则
        switch ($task_cycle) {
            case 1:
                $check_arr['minute']();
                $check_arr['hour']();
                $rule = "{$minute} {$hour} * * *";
                break;
            case 2:
                $check_arr['minute']();
                $rule = "{$minute} * * * *";
                break;
            case 3:
                $check_arr['minute']();
                $check_arr['hour']();
                $rule = "{$minute} */{$hour} * * *";
                break;
            case 4:
                $check_arr['minute']();
                $rule = "*/{$minute} * * * *";
                break;
            case 5:
                $check_arr['second']();
                $rule = "*/{$second} * * * * *";
                break;
            case 6:
                $check_arr['week']();
                $check_arr['hour']();
                $check_arr['minute']();
                $rule = "{$minute} {$hour} * * {$week}";
                break;
            case 7:
                $check_arr['day']();
                $check_arr['hour']();
                $check_arr['minute']();
                $rule = "{$minute} {$hour} {$day} * *";
                break;
            case 8:
                $check_arr['month']();
                $check_arr['day']();
                $check_arr['hour']();
                $check_arr['minute']();
                $rule = "{$minute} {$hour} {$day} {$month} *";
                break;
            default:
                throw new  \Exception("任务周期不正确");
        }
        $now_time = time();

        if ($id) {
            CrontabModel::where('id', $id)->update([
                'title' => $title, 'type' => $type, 'rule' => $rule, 'target' => $target,
                'status' => $status, 'remark' => $remark, 'singleton' => $singleton, 'sort' => $sort,
                'parameter' => $parameter, 'create_time' => $now_time, 'update_time' => $now_time,
                'task_cycle' => $task_cycle, 'cycle_rule' => json_encode([
                    'month' => $month, 'week' => $week, 'day' => $day, 'hour' => $hour, 'minute' => $minute, 'second' => $second,
                ])//保存周期规则,这样方便编辑的时候重新渲染回去
            ]);
        } else {
            $id = CrontabModel::insertGetId([
                'title' => $title, 'type' => $type, 'rule' => $rule, 'target' => $target,
                'status' => $status, 'remark' => $remark, 'singleton' => $singleton, 'sort' => $sort,
                'parameter' => $parameter, 'create_time' => $now_time, 'update_time' => $now_time,
                'task_cycle' => $task_cycle, 'cycle_rule' => json_encode([
                    'month' => $month, 'week' => $week, 'day' => $day, 'hour' => $hour, 'minute' => $minute, 'second' => $second,
                ])
            ]);
        }
        $this->requestData($id);
        return json([
            'code' => 0,
            'msg' => '编辑成功'
        ]);
    }


    public function updateOne()
    {
        $id =(int) request()->input('id');
        $status = (int) request()->input('status');

        CrontabModel::where('id', $id)->update([
            'status' => $status,
        ]);
        $this->requestData($id);

        return json([
            'code' => 0,
            'msg' => '修改成功',
        ]);
    }

    /**
     * @return \support\Response
     */
    public function get()
    {
        $id =(int) request()->input('id');

        $data = CrontabModel::where('id', $id)->find();
        if ($data) {
            $data['cycle_rule'] = json_decode($data['cycle_rule'], true);
        }

        return json([
            'code' => 0,
            'msg' => '',
            'data' => $data
        ]);
    }

    /**
     * @return \support\Response
     */
    public function reloadTask()
    {
        $id =(int)  request()->input('id');

        $this->requestData($id);

        return json([
            'code' => 0,
            'msg' => '重启成功'
        ]);

    }


    /**
     * @return \support\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * 获取日志
     */
    public function getLog()
    {
        $id =(int) request()->input('id');
        $page =(int) request()->input('page', 1);
        $limit =(int) request()->input('limit', 10);

        $data = CrontabLogModel::where('crontab_id', $id)->page($page, $limit)->order(['id' => 'desc'])->select();
        $count = CrontabLogModel::where('crontab_id', $id)->count();
        return json([
            'code' => 0,
            'data' => $data,
            'count' => $count,
        ]);

    }

    public function delete()
    {
        $id =(int) request()->input('id');

        //先关闭再删除,避免删了后直接连不上服务的情况出现
        CrontabModel::where('id', $id)->update(['status' => 0,]);
        $this->requestData($id);
        CrontabModel::destroy($id);

        return json([
            'code' => 0,
            'msg' => '删除成功'
        ]);
    }


    /**
     * @param $id_str string|int 需要重启的任务id,多个id用，拼接，例：1,2,3,4,5
     * @return mixed|void
     */
    private function requestData($id_str)
    {
        //重启任务
        $param = ['method' => 'crontabReload', 'args' => ['id' => $id_str]];

        $result = Task\Client::instance()->request($param);

        $code = $result['code'] ?? 0;
        if ($code == 200) {
            return $result['msg'];
        }
        throw new \Exception($result['msg'] ?? '请求异常');
    }

}

```



## 常见错误
1 未正确配置redis信息<br>
2 未正确配置端口信息<br>
3 未实现配置信息里面的 getAllTask ,getTask ,writeRunLog,updateTaskRunState四个方法<br>
4 端口未放行导致无法通讯

