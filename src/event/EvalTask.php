<?php

namespace FlyCms\WebmanCrontab\event;

class EvalTask implements EventBootstrap
{

    /**
     * @param $crontab
     * @return array
     */
    public static function parse($crontab){
        $result = true;
        $code = 0;
        $exception = '';
        try {
            eval($crontab['target']);
        } catch (\Throwable $throwable) {
            $result = false;
            $code = 1;
            $exception = $throwable->getMessage();
        }
        return ['result' => $result, 'code' => $code, 'exception' => $exception];
    }

}
