<?php

namespace FlyCms\WebmanCrontab\event;

class UrlTask implements EventBootstrap
{
    /**
     * @param $crontab
     * @return array
     */
    public static function parse($crontab){
        $url = trim($crontab['target'] ?? '');

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

}
