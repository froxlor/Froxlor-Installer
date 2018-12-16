<?php
// creating the phar archive:
try {
	$phar = new Phar(dirname(__DIR__) . '/bin/froxlor-install.phar', FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_FILENAME, "froxlor-install.phar");
	$phar->addFile(dirname(__DIR__) . '/src/installer.php', 'installer.php');
	$phar->addFile(dirname(__DIR__) . '/src/classes/class.CmdLineHandler.php', '/classes/class.CmdLineHandler.php');
	$stub = $phar->createDefaultStub('installer.php');
	$phar->setStub($stub);
	$phar->compressFiles(Phar::GZ);
} catch (Exception $e) {
	// handle error here
	echo $e->getMessage() . "\n" . $e->getTraceAsString();
}
