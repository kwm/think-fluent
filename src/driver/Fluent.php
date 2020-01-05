<?php

namespace think\log\driver;

use Exception;
use think\App;
use think\contract\LogHandlerInterface;

class Fluent implements LogHandlerInterface
{
    protected $config = [
        // Fluent 服务器地址及协议
        'host'       => 'tcp://127.0.0.1',
        //端口号
        'port'       => 24224,
        //超时时间
        'timeout'    => 3,
        //是否长链接
        'persistent' => true,
        //是否异步模式
        'async'      => false,
        //写入失败后是否重试
        'retry'      => true,
        //重试次数
        'retryMax'   => 2,
        //重试时的等待时间，单位：微秒，1000 = 0.001秒
        'retryWait'  => 1000,
        //标签前缀，系统会把没有.分隔的标签统一加上此前缀
        'prefix'     => '',
        //写入失败后是否保存到文件
        'saveFile'   => true,
        //保存到文件的路径
        'path'       => '',
        //单次写入数据长度
        'limit'      => 10240,
        //请求ID，可定义为
        'requestId'  => '',
        //序列化方法
        'serialize'  => 'json_encode',
    ];

    /** @var resource */
    protected $handler;

    /** @var string */
    protected $destination;

    /** @var string */
    protected $requestId;

    public function __construct(App $app, array $config = [])
    {
        if (!empty($config)) {
            $this->config = array_merge($this->config, $config);
        }
        if (empty($this->config['path'])) {
            $this->config['path'] = $app->getRuntimePath() . 'log/fluent';
        }

        if (substr($this->config['path'], -1) != DIRECTORY_SEPARATOR) {
            $this->config['path'] .= DIRECTORY_SEPARATOR;
        }
    }

    /**
     * 日志写入接口
     * @access public
     * @param array $log 日志信息
     * @return bool
     */
    public function save(array $log): bool
    {
        $this->requestId = $this->getRequestId();

        foreach ($log AS $tag => $item) {
            $this->send(strpos($tag, '.') ? $tag : $this->config['prefix'] . $tag, $item);
        }

        return true;
    }

    /**
     * 连接Fluent服务器
     * @throws
     */
    protected function connect()
    {
        if (!is_null($this->handler)) {
            return $this->handler;
        }

        $flags = STREAM_CLIENT_CONNECT;

        if ($this->config['persistent']) {
            $flags |= STREAM_CLIENT_PERSISTENT;
        }

        if ($this->config['async']) {
            $flags |= STREAM_CLIENT_ASYNC_CONNECT;
        }

        try {
            $this->handler = stream_socket_client($this->config['host'] . ':' . $this->config['port'], $errno, $errstr, $this->config['timeout'], $flags);

            // 设置 socket 的读写超时时间
            stream_set_timeout($this->handler, $this->config['timeout']);
        } catch (Exception $e) {
            //保存一个 fluent 错误日志
            $this->error('#' . $e->getLine() . ' ' . $e->getMessage());

            throw $e;
        }

        return $this->handler;
    }

    /**
     * 关闭连接
     */
    protected function close()
    {
        if (is_resource($this->handler)) {
            fclose($this->handler);
            $this->handler = null;
        }
    }

    /**
     * 写入数据到 fluent
     *
     * @param string $tag  标签名称
     * @param array  $data 数据
     * @return bool
     */
    protected function send($tag, $data)
    {
        $packed   = $this->pack($tag, $data);
        $len      = strlen($packed);
        $writeLen = 0; //已写入长度
        $retryNum = 0; //重试次数
        try {
            $this->connect();
            while ($writeLen < $len) {
                $ret = fwrite($this->handler, $packed, $this->config['limit']);
                // 写入成功
                if ($ret) {
                    //增加已写入长度
                    $writeLen += $ret;
                    //新的字符串
                    $packed = substr($packed, $ret);
                    continue;
                }

                // 未开启写入失败后重试，或超过重试次数
                if (!$this->config['retry'] || $retryNum >= $this->config['retryMax']) {
                    throw new Exception("未开启写入失败后重试，或超过重试次数");
                }

                // 还有最后一次重试机会前，先断掉重连
                if ($retryNum === $this->config['retryMax'] - 1) {
                    $this->close();
                    $this->connect();
                }

                usleep($this->config['retryWait']);
                $retryNum++;
            }
        } catch (Exception $e) {
            //保存一个 fluent 错误日志
            $this->error('#' . $e->getLine() . ' ' . $e->getMessage());

            //写入失败，且开启了保存到文件功能
            if ($this->enableSaveFile()) {
                error_log($this->pack($tag, $data, 0, "\r") . "\r", 3, $this->destination);
            }

            return false;
        }

        return true;
    }

    /**
     * 数据打包
     *
     * @param string $tag  标签名称
     * @param array  $data 数据
     * @param int    $time 时间
     * @param string $glue 多条数据的分隔符
     * @return string
     */
    protected function pack($tag, $data, $time = 0, $glue = '')
    {
        $time = $time ?: time();
        if (empty($data[0]) || is_string($data[0])) {
            $data['requestId'] = $this->requestId;

            return $this->config['serialize']([$tag, $time, $data]);
        } else {
            $packed = [];
            foreach ($data AS $idx => $item) {
                $item['requestId']  = $this->requestId;
                $item['requestIdx'] = $idx + 1;
                $packed[]           = $this->config['serialize']([$tag, $time, $item]);
            }

            return implode($glue, $packed);
        }
    }

    /**
     * 是否开启错误后写入到文件功能
     * @return bool
     */
    protected function enableSaveFile(): bool
    {
        if ($this->destination) {
            return true;
        } elseif (!$this->config['saveFile']) {
            return false;
        }
        $destination = $this->config['path'] . date('Ym') . DIRECTORY_SEPARATOR . date('d') . '.log';
        $path        = dirname($destination);
        try {
            !is_dir($path) && mkdir($path, 0755, true);
            $this->destination = $destination;
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * 获取请求ID
     * @return string
     */
    protected function getRequestId()
    {
        if (is_callable($this->config['requestId'])) {
            $this->config['requestId'] = $this->config['requestId']();
        }

        return $this->config['requestId'] ?: str_replace('.', '', uniqid('', true));
    }

    /**
     * 保存错误日志
     * @param string $msg 日志内容
     */
    protected function error($msg)
    {
        error_log('[' . date('c') . '] ' . $msg . "\r", 3, $this->config['path'] . 'fluent_error.log');
    }
}