<?php

include(dirname(__FILE__).'/../../config/config.inc.php');
include(dirname(__FILE__).'/../../init.php');

include(dirname(__FILE__).'/yooshop.php');

ini_set('display_errors', 'off');

header ('Content-Type:text/json');

$f = new Yooshop();
echo $f->generateFeed();
