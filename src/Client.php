<?php

namespace FlyCms\WebmanCrontab;

class Client
{

    private $client;

    public function __construct()
    {
        $this->client = stream_socket_client('tcp://' . config('plugin.fly-cms.webman-crontab.app.listen'));
    }

    public static function instance()
    {
        return new static(); //这里不要单例模式,不做心跳链接资源无法确定什么时候会断,麻烦
    }

    /**
     * @param array $param
     * @return mixed
     */
    public function request(array $param)
    {
        fwrite($this->client, json_encode($param) . "\n"); // text协议末尾有个换行符"\n"
        $result = fgets($this->client);
        return json_decode($result,true);
    }


}
