<?php

namespace FlyCms\WebmanCrontab\event;

class ShellTask implements EventBootstrap
{

    /**
     * @param $crontab
     * @return array
     */
    public static function parse($crontab){
        $code = 0;
        try {
            $log = shell_exec($crontab['target']);
        } catch (\Throwable $e) {
            $code = 1;
            $log = $e->getMessage();
        }
        return ['code' => $code, 'log' => $log];
    }

}
