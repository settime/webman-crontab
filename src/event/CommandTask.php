<?php

namespace FlyCms\WebmanCrontab\event;

use Throwable;

class CommandTask implements EventBootstrap
{

    /**
     * @param $crontab
     * @return array
     */
    public static function parse($crontab){
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

}
