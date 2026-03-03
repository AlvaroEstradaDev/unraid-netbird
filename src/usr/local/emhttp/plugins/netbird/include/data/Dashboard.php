<?php

namespace Netbird;

$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
require_once "{$docroot}/plugins/netbird/include/page.php";

echo getPage("Dashboard", false);
