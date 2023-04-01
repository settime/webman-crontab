<?php

namespace FlyCms\WebmanCrontab;

class Client
{

    /**
     * @param array $param
     * @return bool
     */
    public static function request(array $param)
    {
        try{
            $client = stream_socket_client('tcp://' . config('plugin.fly-cms.webman-crontab.app.listen'));
            fwrite($client, json_encode($param) . "\n"); // text协议末尾有个换行符"\n"
            $result = fgets($client);
            $arr = json_decode($result,true);
            if ($arr['code'] == 200){
                return true;
            }
            return false;
        }catch (\Throwable $e){
            throw new \Exception("请求任务server失败,请检查防火墙或者配置端口是否一致");
        }
    }


}
