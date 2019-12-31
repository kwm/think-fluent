<?php

namespace think\fluent\event;

use think\Container;
use think\Response;

class RequestLog
{
    public function handle(Response $response)
    {
        /** @var \think\App $app */
        $app = Container::getInstance();
        $app->log->record([
            'url'       => $app->request->url(true),
            'method'    => $_SERVER['REQUEST_METHOD'] ?? '',
            'code'      => $response->getCode(),
            'app'       => $app->http->getName(),
            'runtime'   => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
            'file_load' => count(get_included_files()),
            //服务器信息，方便分布式布署时查询
            'server'    => [
                'software' => $_SERVER["SERVER_SOFTWARE"] ?? '',
                'addr'     => $_SERVER['SERVER_ADDR'] ?? '',
            ],
            'request'   => [
                'val'         => $_REQUEST,
                'header'      => $this->getRequestHeaders(),
                'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? '',
            ],
            'response'  => [
                'header' => $response->getHeader(),
                'body'   => $this->getResponseBody($response),
            ],
        ], 'request');
    }

    /**
     * 用户发送的请求头信息
     * @return array
     */
    protected function getRequestHeaders()
    {
        $ret = [];
        foreach ($_SERVER as $K => $V) {
            $a = explode('_', $K);
            if (array_shift($a) == 'HTTP') {
                array_walk($a, function (&$v) {
                    $v = ucfirst(strtolower($v));
                });
                $ret[join('-', $a)] = $V;
            }
        }

        return $ret;
    }

    /**
     * 获取响应内容，单独出来以方便扩展
     * @param Response $response
     * @return string|array
     */
    protected function getResponseBody($response)
    {
        return $response->getContent();
    }
}