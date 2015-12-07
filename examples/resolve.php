#!/usr/bin/env php
<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Icicle\Coroutine;
use Icicle\Dns;
use Icicle\Exception\InvalidArgumentError;
use Icicle\Loop;

if (2 > $argc) {
    throw new InvalidArgumentError('Too few arguments provided. Usage: {DomainName}');
}

$domain = $argv[1];

$coroutine = Coroutine\create(function ($query, $timeout = 1) {
    printf("Query: %s\n", $query);
    
    $ips = (yield Dns\resolve($query, ['timeout' => $timeout]));
    
    foreach ($ips as $ip) {
        printf("IP: %s\n", $ip);
    }
}, $domain);

$coroutine->capture(function (Exception $e) {
    printf("Exception: %s\n", $e->getMessage());
})->done();

Loop\run();
