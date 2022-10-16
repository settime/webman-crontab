<?php

namespace FlyCms\WebmanCrontab;

use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Crontab\Crontab;
use Workerman\Lib\Timer;
use Workerman\Worker;

/**
 */
abstract class Server implements CrontabBootstrap
{
    public const FORBIDDEN_STATUS = '0';

    public const NORMAL_STATUS = '1';

    // 命令任务
    public const COMMAND_CRONTAB = '1';
    // 类任务
    public const CLASS_CRONTAB = '2';
    // URL任务
    public const URL_CRONTAB = '3';
    // EVAL 任务
    public const EVAL_CRONTAB = '4';
    //shell 任务
    public const SHELL_CRONTAB = '5';

    protected $worker;

    /**
     * 调试模式
     * @var bool
     */
    protected $debug = false;

    /**
     * 记录日志
     * @var bool
     */
    protected $writeLog = true;

    /**
     * 任务进程池
     * @var Crontab[] array
     */
    private $crontabPool = [];

    /**
     * @var \Workerman\Redis\Client 订阅实例
     */
    protected $subscribeClient;

    /**
     * @var \Workerman\Redis\Client 通知实例
     */
    protected $publishClient;

    /**
     * @return void
     * 订阅事件
     */
    private function subscribeEvent(){
        if(!$this->subscribeClient){
            $this->subscribeClient = $this->getWorkermanRedis();
        }
        if(!$this->publishClient){
            $this->publishClient = $this->getWorkermanRedis();
            Timer::add(30,function (){//保持链接活跃
               $this->publishClient->publish('ping','ping');
            });
        }

        //订阅任务改变事件
        $this->subscribeClient->subscribe('change_contrab',function ($channel, $message){
            $data = json_decode($message, true);
            $method = $data['method'] ?? '';
            $args = $data['args'] ?? '';
            call_user_func([$this, $method], $args);
        });

        //订阅一个心跳链接,避免通讯断了
        $this->subscribeClient->subscribe('ping',function (){
            return;
        });
    }

    public function onWorkerStart(Worker $worker)
    {
        $config = config('plugin.fly-cms.webman-crontab.app');
        $this->debug = $config['debug'] ?? true;
        $this->writeLog = $config['write_log'] ?? true;
        $this->worker = $worker;

        $this->subscribeEvent();
        $this->crontabInit();
    }


    public function onMessage(TcpConnection $connection, $data)
    {

        $data = json_decode($data, true);
        $method = $data['method'] ?? '';
        $args = $data['args'] ?? '';

        // 这里只有3个方法, 添加,删除,重启, 如果修改的话,一样是调用重启任务
        if(!in_array($method,['crontabCreate','crontabDelete','crontabReload'])){
            $connection->send(json_encode(['code' => 400, 'msg' => "{$method} is not found"]));
            return;
        }

        //通知所有进程
        $this->publishClient->publish('change_contrab', json_encode(['method'=>$method,'args' => $args]));
        $connection->send(json_encode(['code' => 200, 'msg' => "ok"]));
    }


    /**
     * 创建定时任务
     * @param array $param
     */
    private function crontabCreate(array $param)
    {
        $id = $param['id'] ?? '';

        $this->crontabRun($id);
    }


    /**
     * 清除定时任务
     * @param array $param
     */
    private function crontabDelete(array $param)
    {
        $id = $param['id'] ?? '';
        $ids = explode(',', (string)$id);

        foreach ($ids as $item) {
            if (isset($this->crontabPool[$item])) {
                $this->crontabPool[$item]['crontab']->destroy();
                unset($this->crontabPool[$item]);
            }
        }
    }

    /**
     * 重启定时任务
     * @param array $param
     */
    private function crontabReload(array $param)
    {
        $ids = explode(',', (string)($param['id']??''));
        foreach ($ids as $id) {
            if (isset($this->crontabPool[$id])) {
                $this->crontabPool[$id]['crontab']->destroy();
                unset($this->crontabPool[$id]);
            }
            $this->crontabRun($id);
        }
    }


    /**
     * 初始化定时任务
     * @return void
     */
    private function crontabInit()
    {
        $data = $this->getAllTask();

        foreach ($data as $item){
            $this->crontabRun($item['id']);
        }
    }


    /**
     * @param $crontab array 任务数据
     * @return array
     * 解析 command 任务
     */
    private function parseCommand($crontab)
    {
        $code = 0;
        $result = true;
        try {
            if (strpos($crontab['target'], 'php webman') !== false) {
                $command = $crontab['target'];
            } else {
                $command = "php webman " . $crontab['target'];
            }
            $exception = shell_exec($command);
        } catch (Throwable $e) {
            $result = false;
            $code = 1;
            $exception = $e->getMessage();
        }
        return ['result' => $result, 'code' => $code, 'exception' => $exception];
    }

    /**
     * @param $data
     * @return array
     * 解析 class 任务
     */
    private function parseClass($data)
    {
        $class = trim($data['target'] ?? '');
        if (!$class) {
            $code = 1;
            $result = false;
            $exception = '方法或类不存在或者错误';
            return ['result' => $result, 'code' => $code, 'exception' => $exception];
        }

        $method = 'execute';
        if (strpos($class, '@') !== false) {
            $class = explode('@', $class);
            $method = end($class);
            array_pop($class);
            $class = implode('@', $class);
        }
        if (class_exists($class) && method_exists($class, $method)) {
            try {
                $result = true;
                $code = 0;

                $instance = new $class();

                $parameters = !empty($data['parameter']) ? json_decode($data['parameter'], true) : [];
                if (!empty($data['parameter']) && is_array($parameters)) {
                    $exception = $instance->{$method}($parameters);
                } else {
                    $exception = $instance->{$method}();
                }
            } catch (\Throwable $throwable) {
                $result = false;
                $code = 1;
                $exception = $throwable->getMessage();
            }
        } else {
            $result = false;
            $code = 1;
            $exception = "方法或类不存在或者错误";
        }
        return ['result' => $result, 'code' => $code, 'exception' => $exception];
    }

    /**
     * @param $data
     * @return array
     * 解析 url 任务
     */
    private function parseUrl($data)
    {
        $url = trim($data['target'] ?? '');

        $code = 0;
        $exception = '';
        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->get($url);
            $result = $response->getStatusCode() === 200;
        } catch (\Throwable $throwable) {
            $result = false;
            $code = 1;
            $exception = $throwable->getMessage();
        }
        return ['result' => $result, 'code' => $code, 'exception' => $exception];
    }

    /**
     * @param $data
     * @return array
     * 解析 eval 任务
     */
    private function parseEval($data)
    {
        $result = true;
        $code = 0;
        $exception = '';
        try {
            eval($data['target']);
        } catch (\Throwable $throwable) {
            $result = false;
            $code = 1;
            $exception = $throwable->getMessage();
        }
        return ['result' => $result, 'code' => $code, 'exception' => $exception];
    }

    /**
     * @param $data
     * @return array
     *  解析 shell 任务
     */
    private function parseShell($data)
    {
        $code = 0;
        $result = true;
        try {
            $exception = shell_exec($data['target']);
        } catch (\Throwable $e) {
            $result = false;
            $code = 1;
            $exception = $e->getMessage();
        }
        return ['result' => $result, 'code' => $code, 'exception' => $exception];
    }

    /**
     * 创建定时器
     * @param $id
     */
    private function crontabRun($id)
    {
        $data = $this->getTask($id);
        if (empty($data)) {
            return;
        }
        if ($data['status'] != self::NORMAL_STATUS){
            return;
        }

        $crontab = new Crontab($data['rule'], function () use (&$data) { //这里传参必须传引用
            //运行次数加1,很重要,多进程情况下用来检测当前次数是否已执行
            $data['running_times'] += 1;

            //获取锁失败,说明有进程把任务执行了
            if (!$this->lockTask($data)) {
                $this->isSingleton($data);
                return;
            }

            $startTime = microtime(true);
            switch ($data['type']) {
                case self::COMMAND_CRONTAB:
                    $resultData = $this->parseCommand($data);
                    break;
                case self::CLASS_CRONTAB:
                    $resultData = $this->parseClass($data);
                    break;
                case self::URL_CRONTAB:
                    $resultData = $this->parseUrl($data);
                    break;
                case self::SHELL_CRONTAB:
                    $resultData = $this->parseShell($data);
                    break;
                case self::EVAL_CRONTAB:
                    $resultData = $this->parseEval($data);
                    break;
                default:
                    //任务类型不正确,不执行
                    $this->writeln('执行定时器任务失败, 任务类型错误-----任务id: ' . $data['id']);
                    return;
            }
            $result = $resultData['result'];
            $code = $resultData['code'];
            $exception = $resultData['exception'];

            $this->writeln( 'worker:' . $this->worker->id .'  执行定时器任务#' . $data['id'] . ' ' . $data['rule'] . ' ' . $data['target'], $result);
            $this->isSingleton($data);
            $endTime = microtime(true);

            $this->updateTaskRunState($data['id'],$startTime);

            $this->crontabRunLog($data, $startTime, $endTime, $code, $exception);

        });

        $this->crontabPool[$data['id']] = [
            'id' => $data['id'],
            'target' => $data['target'],
            'rule' => $data['rule'],
            'parameter' => $data['parameter'],
            'singleton' => $data['singleton'],
            'create_time' => date('Y-m-d H:i:s'),
            'crontab' => $crontab,
        ];
    }

    /**
     * 是否单次
     * @param $crontab
     * @return void
     */
    private function isSingleton($crontab)
    {
        if ($crontab['singleton'] == 0 && isset($this->crontabPool[$crontab['id']])) {
            $this->writeln("定时器销毁", true);
            $this->crontabPool[$crontab['id']]['crontab']->destroy();
        }
    }


    /**
     * @param $crontab
     * @return string
     * 创建任务uuid
     */
    private function createTaskUuid($crontab)
    {
        // 以任务id + 运行次数作为唯一id, 这样在过期范围内,只会有一个进程能执行到该次任务,杜绝死锁的情况发生.
        return md5('task_' . $crontab['id'] . '_' . $crontab['running_times']);
    }

    /**
     * @param $crontab
     * @return bool
     * 锁定任务,解决多进程同时执行任务问题,不需要解锁,等过期直接释放
     */
    private function lockTask($crontab)
    {
        $key_name = $this->createTaskUuid($crontab);
        if ($this->getRedisHandle()->setnx($key_name, true)) {
            $this->getRedisHandle()->expire($key_name, 600);
            return true;
        }
        return false;
    }


    /**
     * @param $data array 任务数据
     * @param $startTime int 任务开始时间
     * @param $endTime int 任务执行结束时间
     * @param $code int 执行状态码,0成功 1异常
     * @param $exception string 异常信息
     * @return void
     * 记录执行日志
     */
    private function crontabRunLog($data, $startTime, $endTime, $code = 0, $exception = ''): void
    {
        if ($this->writeLog) {
            $this->writeRunLog([
                'crontab_id' => $data['id'] ?? '',
                'target' => $data['target'] ?? '',
                'parameter' => $data['parameter'] ?? '',
                'exception' => $exception,
                'return_code' => $code,
                'running_time' => round($endTime - $startTime, 6),
                'create_time' => $startTime,
                'update_time' => $startTime,
            ]);
        }
    }


    /**
     * 输出日志
     * @param $msg
     * @param bool $isSuccess
     */
    private function writeln($msg, bool $isSuccess)
    {
        if ($this->debug) {
            echo 'worker:' . $this->worker->id . ' [' . date('Y-m-d H:i:s') . '] ' . $msg . ($isSuccess ? " [Ok] " : " [Fail] ") . PHP_EOL;
        }
    }


    /**
     * 创建定时器任务表
     */
    private function createCrontabTable()
    {
        $sql = <<<SQL
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
SQL;

    }

    /**
     * 定时器任务流水表
     */
    private function createCrontabLogTable()
    {
        $sql = <<<SQL
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
SQL;

    }

}
