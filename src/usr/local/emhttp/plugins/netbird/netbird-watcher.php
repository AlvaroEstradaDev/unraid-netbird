#!/usr/bin/php -q
<?php

namespace Netbird;

$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
require_once "{$docroot}/plugins/netbird/include/common.php";

$watcher = new Watcher();
$watcher->run();
