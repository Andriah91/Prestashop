<?php

include(dirname(__FILE__).'/../../config/config.inc.php');
include(dirname(__FILE__).'/../../init.php');

include(dirname(__FILE__).'/yooshop.php');

$f = new Yooshop();
$id = $_GET['id'];
$f->lastProduct($id);