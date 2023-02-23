<?php

namespace FlyCms\WebmanCrontab\event;

class ClassTask implements EventBootstrap
{

    /**
     * @param $crontab
     * @return array
     * 解析 class 任务
     */
    public static function parse($crontab)
    {
        $class = trim($crontab['target'] ?? '');
        $parameter = $crontab['parameter'] ?? [];
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

}
