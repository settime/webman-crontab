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
        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->get($url);
            $log = $response->getBody()->getContents();
        } catch (\Throwable $throwable) {
            $code = 1;
            $log = $throwable->getMessage();
        }
        return ['code' => $code, 'log' => $log];
    }

}
