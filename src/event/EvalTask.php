<?php

namespace FlyCms\WebmanCrontab\event;

class EvalTask implements EventBootstrap
{

    /**
     * @param $crontab
     * @return array
     */
    public static function parse($crontab){
        $code = 0;
        try {
            $log = eval($crontab['target']);
        } catch (\Throwable $throwable) {
            $code = 1;
            $log = $throwable->getMessage();
        }
        return ['log'=> $log, 'code' => $code];
    }

}
