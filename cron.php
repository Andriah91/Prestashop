<?php

include(dirname(__FILE__).'/../../config/config.inc.php');
include(dirname(__FILE__).'/../../init.php');

include(dirname(__FILE__).'/yooshop.php');

ini_set('display_errors', 'off');

$f = new Yooshop();

if (Tools::getValue('token') == '' || Tools::getValue('token') != Configuration::get('YOOSHOP_TOKEN'))
	die('{ "error" : "Invalid Token" }');

$current = Tools::getValue('current');

if (empty($current))
	$f->initFeed();
else
	$f->writeFeed( Tools::getValue('total'), Tools::getValue('current'), Tools::getValue('lang'));
