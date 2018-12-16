<?php

if (version_compare(phpversion(), '5.3.2', '<')) {
	die('You need at least PHP 5.3.2 to execute .phar files.');
}

if (!extension_loaded('Phar')) {
	die('The PHP Phar extension is not enabled.');
}

if (false !== ($suhosin = ini_get('suhosin.executor.include.whitelist'))) {
	$allowed = array_map('trim', explode(',', $suhosin));
	
	if (!in_array('phar', $allowed) && !in_array('phar://', $allowed)) {
		die('The Suhosin extension does not allow to run .phar files.');
	}
}

if ('cgi-fcgi' === php_sapi_name() && extension_loaded('eaccelerator') && ini_get('eaccelerator.enable')) {
	die('The PHP eAccelerator extension cannot handle .phar files.');
}

Phar::interceptFileFuncs();
__HALT_COMPILER();
