<?php

namespace FlyCms\WebmanCrontab;

use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Crontab\Crontab;
use Workerman\Lib\Timer;
use Workerman\Worker;
use Workerman\Redis\Client as RedisClient;

/**
 */
abstract class Server implements CrontabBootstrap
{
    //修改类常量,兼容7.0
    public static $FORBIDDEN_STATUS = '0';

    public static $NORMAL_STATUS = '1';

    // 命令任务
    public static $COMMAND_CRONTAB = '1';
    // 类任务
    public static $CLASS_CRONTAB = '2';
    // URL任务
    public static $URL_CRONTAB = '3';
    // EVAL 任务
    public static $EVAL_CRONTAB = '4';
    //shell 任务
    public static $SHELL_CRONTAB = '5';

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
     * @var array
     * redis 配置
     */
    protected $redisConfig = [
        'host' => 'redis://127.0.0.1:6379',
        'options' => [
            'auth' => null,       // 密码，字符串类型，可选参数
        ]
    ];

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
        // 创建订阅实例
        if(!$this->subscribeClient){
            $address = $this->redisConfig['host'];
            $redis = new RedisClient($address);
            if ($this->redisConfig['options']['auth']){
                $redis->auth($this->redisConfig['options']['auth']);
            }
            $this->subscribeClient =   $redis;
        }
        //创建发布实例
        if(!$this->publishClient){
            $address = $this->redisConfig['host'];
            $redis = new RedisClient($address);
            if ($this->redisConfig['options']['auth']){
                $redis->auth($this->redisConfig['options']['auth']);
            }
            $this->publishClient = $redis;
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
        $this->redisConfig = $config['redis'] ?? $this->redisConfig;

        $this->worker = $worker;

        //订阅事件
        $this->subscribeEvent();
        //初始化任务
        $this->crontabInit();
    }


    public function onMessage(TcpConnection $connection, $data)
    {

        $data = json_decode($data, true);
        $method = $data['method'] ?? '';
        $args = $data['args'] ?? '';

        // 这里只有保留一个重启方法,对任务进行任何操作,直接调用重启解决
        if(!in_array($method,['crontabCreate','crontabDelete','crontabReload'])){
            $connection->send(json_encode(['code' => 400, 'msg' => "{$method} is not found"]));
            return;
        }
        //通知所有进程该任务进行重启
        $this->publishClient->publish('change_contrab', json_encode(['method'=>'crontabReload','args' => $args]));

        $connection->send(json_encode(['code' => 200, 'msg' => "ok"]));
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
        $parameter = $data['parameter'] ?? [];
        if ($parameter){
            $parameter = json_decode($parameter, true);
        }
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

        $result = true;
        $code = 0;
        try {
            if ( !(class_exists($class) && method_exists($class, $method)) ) {
                throw new \Exception('class or method not found');
            }
            $instance = new $class();
            $exception = call_user_func([$instance, $method], $parameter);

        } catch (\Throwable $e) {
            $result = false;
            $code = 1;
            $exception = $e->getMessage();
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
        if ($data['status'] != self::$NORMAL_STATUS){
            return;
        }

        $crontab = new Crontab($data['rule'], function () use (&$data) { //这里传参必须传引用
            //运行次数加1,很重要,多进程情况下用来检测当前次数是否已执行
            $data['running_times'] += 1;

            $startTime = microtime(true);
            //获取锁失败,说明有进程把任务执行了
            if (!$this->lockTask($data)) {
                $this->isSingleton($data);
                return;
            }

            switch ($data['type']) {
                case self::$COMMAND_CRONTAB:
                    $resultData = $this->parseCommand($data);
                    break;
                case self::$CLASS_CRONTAB:
                    $resultData = $this->parseClass($data);
                    break;
                case self::$URL_CRONTAB:
                    $resultData = $this->parseUrl($data);
                    break;
                case self::$SHELL_CRONTAB:
                    $resultData = $this->parseShell($data);
                    break;
                case self::$EVAL_CRONTAB:
                    $resultData = $this->parseEval($data);
                    break;
                default:
                    //任务类型不正确,
                    $resultData = [
                        'result' => false, 'code' => 1, 'exception'  => '执行任务失败, 任务类型错误-----任务id: ' . $data['id']
                    ];
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
     *
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
        //锁id1秒
        if(!$this->getRedisHandle()->set($crontab['id'],true,['nx', 'ex' => 1])){
            return false;
        }
        $key_name = $this->createTaskUuid($crontab);
        if (!$this->getRedisHandle()->set($key_name, true,['nx', 'ex' => 86400]) ) {
            return false;
        }
        return true;
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
    private function crontabRunLog($data, $startTime, $endTime, $code = 0, $exception = '')
    {
        if ($this->writeLog) {
            $this->writeRunLog([
                'crontab_id' => $data['id'] ?? '',
                'target' => $data['target'] ?? '',
                'parameter' => $data['parameter'] ?? '',
                'exception' => '进程id: ' . $this->worker->id . '--执行次数:' . $data['running_times'] . '---' . $exception,
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

}
