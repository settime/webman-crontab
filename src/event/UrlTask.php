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
        $respond = '';

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->get($url);
            $result = $response->getStatusCode() === 200;
            $respond = $response->getBody()->getContents();
        } catch (\Throwable $throwable) {
            $result = false;
            $code = 1;
            $exception = $throwable->getMessage();
        }
        return ['result' => $result,'respond' => $respond, 'code' => $code, 'exception' => $exception];
    }

}
