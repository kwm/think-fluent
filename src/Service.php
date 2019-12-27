<?php

namespace think\fluent;

use think\Service as BaseService;
use think\event\HttpEnd;

class Service extends BaseService
{
    public function register()
    {
        $this->app->event->listen(HttpEnd::class, event\RequestLog::class);
    }
}
