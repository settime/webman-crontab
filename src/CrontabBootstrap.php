<?php

namespace FlyCms\WebmanCrontab;

interface CrontabBootstrap
{

    /**
     * @return mixed 获取所有任务
     */
    public function getAllTask();

    /**
     * @param $id
     * @return mixed
     * 获取某个任务
     */
    public function getTask($id);

    /**
     * @param $insertData
     * @return mixed
     * 写入运行日志
     */
    public function writeRunLog($insertData = []);

    /**
     * @return \Redis
     * 获取redis
     */
    public function getRedisHandle();

    /**
     * @param $last_running_time
     * @return mixed
     * 更新任务最后运行时间,这里要把运行次数加 1
     */
    public function updateTaskRunState($id,$last_running_time);


}
