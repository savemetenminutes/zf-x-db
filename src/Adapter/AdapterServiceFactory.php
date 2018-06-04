<?php

namespace Smtm\Zfx\Db\Adapter;

class AdapterServiceFactory
{
    public function __invoke()
    {
        $config = $container->get('config');
        return new Adapter($config['db']);
    }
}
