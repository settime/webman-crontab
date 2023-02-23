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
        $result = true;
        try {
            $exception = shell_exec($crontab['target']);
        } catch (\Throwable $e) {
            $result = false;
            $code = 1;
            $exception = $e->getMessage();
        }
        return ['result' => $result, 'code' => $code, 'exception' => $exception];
    }

}
