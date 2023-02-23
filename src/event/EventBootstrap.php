<?php

namespace FlyCms\WebmanCrontab\event;

interface EventBootstrap
{

    /**
     * @param $crontab
     * @return mixed
     * 解析任务
     */
    public static function parse($crontab);

}
