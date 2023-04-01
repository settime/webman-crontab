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