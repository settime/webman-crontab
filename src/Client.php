<?php

namespace FlyCms\WebmanCrontab;

class Client
{

    private $client;


    public static function instance()
    {
        return new static();
    }

    /**
     * @param array $param
     * @return mixed
     */
    public function request(array $param)
    {
        try{
            $this->client = stream_socket_client('tcp://' . config('plugin.fly-cms.webman-crontab.app.listen'));
            fwrite($this->client, json_encode($param) . "\n"); // text协议末尾有个换行符"\n"
            $result = fgets($this->client);
            return json_decode($result,true);

        }catch (\Throwable $e){
            throw new \Exception("请求server失败,请检查请求端口与配置端口是否一致");
        }
    }


}
