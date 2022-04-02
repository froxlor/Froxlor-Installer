<?php

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2018 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright (c) the authors
 * @author Froxlor team <team@froxlor.org> (2018-)
 * @license GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package Cron
 *         
 */

// Check if we're in the CLI
if (@php_sapi_name() !== 'cli') {
	die('This script will only work in the shell.');
}

require __DIR__ . '/classes/class.CmdLineHandler.php';

class InstallFroxlorCmd extends CmdLineHandler
{

	/**
	 * installer version
	 *
	 * @var string
	 */
	public static $VERSION = '0.4-beta';

	/**
	 * list of valid switches
	 *
	 * @var array
	 */
	public static $switches = array(
		'h'
	);

	/**
	 * list of valid parameters
	 *
	 * @var array
	 */
	public static $params = array(
		'skip-download',
		'local',
		'git',
		'import-settings',
		'parameters-file',
		'example-parameters',
		'help'
	);

	public static $action_class = 'Action';

	public static function printHelp()
	{
		self::println("");
		self::println("Help / command line parameters:");
		self::println("");
		// commands
		self::println("--skip-download\t\tDo not download tarball but use tarball specified via --local");
		self::println("");
		self::println("--local\t\t\tPath to froxlor-tarball to use for installation. If specified no files will be downloaded");
		self::println("\t\t\tExample: --local=/path/to/froxlor-latest.tar.gz");
		self::println("");
		self::println("--git\t\t\tDo not download tarball but clone the git repository of froxlor (development version). Be sure to have composer installed");
		self::println("");
		self::println("--import-settings\tImport settings from another froxlor installation.");
		self::println("\t\t\tExample: --import-settings=/path/to/Froxlor_settings-[version]-[dbversion]-[date].json or --import-settings=http://domain.tld/Froxlor_settings-[version]-[dbversion]-[date].json");
		self::println("");
		self::println("--parameters-file\tPass all required parameters via a file instead of asking for them");
		self::println("\t\t\tExample: --parameters-file=/path/to/someFile or --parameters-file=http://domain.tld/setup-params");
		self::println("");
		self::println("--example-parameters\toutput an example parameters-file structure");
		self::println("");
		self::println("--help | -h\t\tshow help screen (this)");
		self::println("");

		die(); // end of execution
	}
}

class Action
{

	private $_args = null;

	private $_name = null;

	private $_data = array();

	public function __construct($args)
	{
		$this->_args = $args;
		InstallFroxlorCmd::printsucc("*" . PHP_EOL . "* Starting Froxlor shell-installer v" . InstallFroxlorCmd::$VERSION . "..." . PHP_EOL . "*");
		$this->_run();
	}

	public function getActionName()
	{
		return $this->_name;
	}

	/**
	 * validates the parsed command line parameters
	 *
	 * @throws Exception
	 */
	private function _run()
	{
		$do_download = true;
		$do_git = false;
		$do_params_file = false;
		if (array_key_exists("local", $this->_args)) {
			if (!is_file($this->_args["local"])) {
				throw new Exception("Given file is not a file");
			} elseif (!file_exists($this->_args["local"])) {
				throw new Exception("Given file cannot be found ('" . $this->_args["local"] . "')");
			} elseif (!is_readable($this->_args["local"])) {
				throw new Exception("Given file cannot be read ('" . $this->_args["local"] . "')");
			}
			$do_download = false;
		} elseif (array_key_exists("git", $this->_args)) {
			$do_download = false;
			$do_git = true;
		} elseif (array_key_exists("parameters-file", $this->_args)) {
			$do_params_file = true;
		} elseif (array_key_exists("skip-download", $this->_args) && !array_key_exists("local", $this->_args) && !array_key_exists("git", $this->_args)) {
			InstallFroxlorCmd::printerr("If you skip downloading of froxlor, you need to specify the path to the tarbal using --local or use --git");
			exit();
		} elseif (array_key_exists("example-parameters", $this->_args)) {
			$this->showExampleParameters();
			die();
		}

		$this->_requirementCheck();

		$this->_getBasedir();

		$this->_extractFroxlor($do_download, $do_git);

		$db_root = $this->_getData($do_params_file);

		$this->_installBaseData($db_root);

		if (array_key_exists("import-settings", $this->_args)) {
			$this->_importSettings();
		}

		InstallFroxlorCmd::printsucc("Froxlor is set up. Please open http://" . $this->_data['sys']['ipaddress'] . "/froxlor in your browser" . (array_key_exists("import-settings", $this->_args) ? "," . PHP_EOL . "login as admin and adjust the settings to your needs" : "") . PHP_EOL . "To configure services, run 'php " . $this->_data['basedir'] . "install/scripts/config-services.php --froxlor-dir=" . $this->_data['basedir'] . " --create'");
	}

	private function _requirementCheck()
	{

		// indicator whether we need to abort or not
		$_die = false;

		InstallFroxlorCmd::printnoln("Checking PHP-version ...");
		if (version_compare("7.1.0", PHP_VERSION, ">=")) {
			InstallFroxlorCmd::printerr("You need at least PHP-7.1, you have '" . PHP_VERSION . "'");
			$_die = true;
		} else {
			if (version_compare("7.4.0", PHP_VERSION, ">=")) {
				InstallFroxlorCmd::printwarn("PHP version sufficient, recommened is PHP-7.4 and higher");
			} else {
				InstallFroxlorCmd::printsucc("[ok]");
			}
		}

		// check for php_pdo and pdo_mysql
		InstallFroxlorCmd::printnoln("Checking PHP PDO extension ...");
		if (!extension_loaded('pdo') || in_array("mysql", PDO::getAvailableDrivers()) == false) {
			InstallFroxlorCmd::printerr("Not found");
			$_die = true;
		} else {
			InstallFroxlorCmd::printsucc("[ok]");
		}

		// check for session-extension
		$this->_requirementCheckFor($_die, 'session');

		// check for ctype-extension
		$this->_requirementCheckFor($_die, 'ctype');

		// check for SimpleXML-extension
		$this->_requirementCheckFor($_die, 'simplexml');

		// check for xml-extension
		$this->_requirementCheckFor($_die, 'xml');

		// check for filter-extension
		$this->_requirementCheckFor($_die, 'filter');

		// check for posix-extension
		$this->_requirementCheckFor($_die, 'posix');

		// check for mbstring-extension
		$this->_requirementCheckFor($_die, 'mbstring');

		// check for curl extension
		$this->_requirementCheckFor($_die, 'curl');

		// check for json extension
		$this->_requirementCheckFor($_die, 'json');

		// check for bcmath extension
		$this->_requirementCheckFor($_die, 'bcmath', true);

		// check for zip extension
		$this->_requirementCheckFor($_die, 'zip', true);

		// check if we have unrecoverable errors
		if ($_die) {
			InstallFroxlorCmd::printerr("Aborting due to unmet requirements");
			die();
		}

		echo PHP_EOL;
	}

	private function _requirementCheckFor(&$_die, $ext = '', $optional = false)
	{
		InstallFroxlorCmd::printnoln("Checking PHP " . $ext . " extension ...");

		if (!extension_loaded($ext)) {
			if (!$optional) {
				InstallFroxlorCmd::printerr("[not found]");
				$_die = true;
			} else {
				InstallFroxlorCmd::printwarn("[not installed, but recommended]");
			}
		} else {
			InstallFroxlorCmd::printsucc("[ok]");
		}
	}

	private function _getBasedir()
	{
		$current_dir = getcwd();
		$p = "Please enter the directory where Froxlor shall be installed to";
		$this->_data['basedir'] = InstallFroxlorCmd::getDirectory($p, $current_dir);

		// we need to check if tere is already a installation
		if (file_exists($this->_data['basedir'] . "froxlor/lib/userdata.inc.php")) {
			InstallFroxlorCmd::printerr("Froxlor is already installed on this system!" . PHP_EOL . "Aborting...");
			die();
		}
	}

	private function _extractFroxlor($do_download = true, $do_git = false)
	{
		if ($do_download) {
			$p = "Would you like to download Froxlor now? [Y/n]";
			$proceed = InstallFroxlorCmd::getYesNo($p, 1);
			if ($proceed) {
				// download tarball
				exec("wget https://files.froxlor.org/releases/froxlor-latest.tar.gz -O /tmp/froxlor-latest.tar.gz");
				exec("wget https://files.froxlor.org/releases/froxlor-latest.tar.gz.sha1 -O /tmp/froxlor-latest.tar.gz.sha1");
				$sha1_checksum = file_get_contents("/tmp/froxlor-latest.tar.gz.sha1");
				$sha1_checksum = explode(" ", $sha1_checksum);
				$sha1_checksum = $sha1_checksum[0];

				if (sha1_file("/tmp/froxlor-latest.tar.gz") != $sha1_checksum) {
					InstallFroxlorCmd::printerr("Checksum of downloaded tarball does not seem to be correct. Please try again");

					echo sha1_file("/tmp/froxlor-latest.tar.gz") . "\n";
					echo $sha1_checksum . "\n";

					die();
				}
				// set local tarball
				$this->_args["local"] = "/tmp/froxlor-latest.tar.gz";
			} else {
				InstallFroxlorCmd::printerr("Aborting due to skipped download...");
				die();
			}
		}

		if (isset($this->_args["local"]) && !empty($this->_args["local"])) {
			// extract
			InstallFroxlorCmd::println("Extracting froxlor to " . $this->_data['basedir'] . "froxlor");
			exec("tar xzf " . $this->_args["local"] . " -C " . $this->_data['basedir']);
		} elseif ($do_git) {
			// check for git binary
			$result = null;
			exec("which git", $result);
			if (!empty($result) && is_array($result) && count($result) > 0) {
				$result = trim($result[0]);
			} else {
				InstallFroxlorCmd::printerr("Required git-binary not found for cloning repository. Aborting...");
				die();
			}
			exec($result . " clone https://github.com/Froxlor/Froxlor.git " . $this->_data['basedir'] . "froxlor");
		}
		// now froxlor lies within $basedir + 'froxlor';
		$this->_data['basedir'] .= 'froxlor/';

		if ($do_git) {
			// check for composer
			exec("which composer", $result);
			if (!empty($result) && is_array($result) && count($result) > 0) {
				$result = trim($result[0]);
			}
			while (true) {
				$result = InstallFroxlorCmd::getInput("Please provide the full path to composer or composer.phar", $result);
				if (!file_exists($result)) {
					InstallFroxlorCmd::printerr("File '" . $result . "' could not be found, please retry.");
				} else {
					chdir($this->_data['basedir']);
					exec('php ' . $result . ' install --no-dev');
					break;
				}
			}
		}

		if (!is_dir($this->_data["basedir"])) {
			InstallFroxlorCmd::printerr("Something seems to went wrong with extracting. Aborting...");
			die();
		}
	}

	private function _getData($do_params_file = false)
	{
		// ask for general information
		$this->_data['sql'] = array(
			'user' => null,
			'password' => null,
			'db' => null,
			'host' => null,
			'root_user' => 'root',
			'root_password' => null
		);

		$this->_data['sys'] = array(
			'hostname' => null,
			'ipaddress' => null,
			'mysqlaccess_hosts' => 'localhost',
			'nameservers' => '',
			'admin' => 'admin',
			'admin_password' => null,
			'webserver_user' => 'www-data',
			'webserver' => 'apache24'
		);

		if ($do_params_file) {
			$import_data = $this->importParametersFile();
			$imp_arr = explode("\n", $import_data);
			foreach ($imp_arr as $line) {
				if (!empty(trim($line))) {
					$keyval = explode("=", $line);
					array_map('trim', $keyval);
					$impkey = explode(".", $keyval[0]);
					array_map('trim', $impkey);
					// set the value
					$this->_data[$impkey[0]][$impkey[1]] = $keyval[1];
				}
			}
		} else {

			InstallFroxlorCmd::printwarn("Enter the domain under wich Froxlor shall be reached, this normally" . PHP_EOL . "is the FQDN (Fully Qualified Domain Name) of your system." . PHP_EOL . "If you don't know the FQDN of your system, execute 'hostname -f'." . PHP_EOL . "This installscript will try to guess your FQDN automatically if" . PHP_EOL . "you leave this field blank, setting it to the output of 'hostname -f'");

			$host = array();
			exec('hostname -f', $host);
			$p = "Enter your system's hostname";
			$this->_data['sys']['hostname'] = InstallFroxlorCmd::getInput($p, $host[0]);

			InstallFroxlorCmd::printwarn("Enter the IP address of your system, under wich all" . PHP_EOL . "websites shall then be reached. This must be the same" . PHP_EOL . "IP address the domain you inserted above points to." . PHP_EOL . "You *must* set this to your correct IP address.");

			$ips = array();
			exec('hostname -I', $ips);
			$ips = explode(" ", $ips[0]);
			$p = "Enter your system's ip-address";
			$this->_data['sys']['ipaddress'] = InstallFroxlorCmd::getInput($p, $ips[0]);

			InstallFroxlorCmd::printwarn("Enter the IP address of the MySQL server, if the MySQL" . PHP_EOL . "server is on the same machine, enter 'localhost' or" . PHP_EOL . "simply leave the field blank.");

			$p = "Enter mysql-host address";
			$this->_data['sql']['host'] = InstallFroxlorCmd::getInput($p, 'localhost');
			$this->_data['sql']['host'] = strtolower($this->_data['sql']['host']);

			if ($this->_data['sql']['host'] != 'localhost')
				$this->_data['sys']['mysqlaccess_hosts'] .= ',' . $this->_data['sql']['host'];

			InstallFroxlorCmd::printwarn("Enter the username of the MySQL root user." . PHP_EOL . "The default is 'root'.");

			$p = "MySQL root user";
			$this->_data['sql']['root_user'] = InstallFroxlorCmd::getInput($p, 'root');

			while (true) {
				$p = "Enter the password of the MySQL root user";
				$mrootpwd_a = InstallFroxlorCmd::getInput($p, null, true, true);

				$p = "Enter the password of the MySQL root user again";
				$mrootpwd_b = InstallFroxlorCmd::getInput($p, null, true, true);

				if ($mrootpwd_a == $mrootpwd_b) {
					$this->_data['sql']['root_password'] = $mrootpwd_a;
					break;
				} else {
					InstallFroxlorCmd::printerr("Passwords do not match, please enter again");
				}
			}

			while (true) {
				$p = "Select the webserver you would like to use (apache24, nginx or lighttpd)";
				$webserver = InstallFroxlorCmd::getInput($p, 'apache24');
				if (!in_array($webserver, ['apache24', 'nginx', 'lighttpd'])) {
					InstallFroxlorCmd::printerr("Please type one of the following: apache24, nginx, lighttpd");
				} else {
					$this->_data['sys']['webserver'] = $webserver;
					break;
				}
			}
		}

		InstallFroxlorCmd::printwarn("Testing MySQL root connection");
		// create DB connection
		$options = array(
			'PDO::MYSQL_ATTR_INIT_COMMAND' => 'SET names utf8'
		);
		$dsn = "mysql:host=" . $this->_data['sql']['host'] . ";";
		try {
			$db_root = new PDO($dsn, $this->_data['sql']['root_user'], $this->_data['sql']['root_password'], $options);
			// remove unix-socket plugin for user root to allow login not only from CLI
			$db_root->exec("UPDATE mysql.user SET `plugin` = '' WHERE `User` = '" . $this->_data['sql']['root_user'] . "';");
		} catch (PDOException $e) {
			InstallFroxlorCmd::printerr($e->getMessage());
			InstallFroxlorCmd::printwarn("Testing MySQL root connection without password");
			// possibly without passwd?
			try {
				$db_root = new PDO($dsn, $this->_data['sql']['root_user'], '', $options);
				// remove unix-socket plugin for user root to allow login not only from CLI
				$db_root->exec("UPDATE mysql.user SET `plugin` = '' WHERE `User` = '" . $this->_data['sql']['root_user'] . "';");
				// set the given password
				$db_root->exec("
					SET PASSWORD = PASSWORD('" . $this->_data['sql']['root_password'] . "');
				");
			} catch (PDOException $e) {
				// nope
				InstallFroxlorCmd::printerr("Database error: " . $e);
				die();
			}
		}
		InstallFroxlorCmd::printsucc("Database connection successful");

		$version_server = $db_root->getAttribute(PDO::ATTR_SERVER_VERSION);
		$sql_mode = 'NO_ENGINE_SUBSTITUTION';
		if (version_compare($version_server, '8.0.11', '<')) {
			$sql_mode .= ',NO_AUTO_CREATE_USER';
		}
		$db_root->exec('SET sql_mode = "' . $sql_mode . '"');

		// db check
		if (!$this->db_check($db_root, $this->_data['sql'])) {
			InstallFroxlorCmd::printerr("Aborting installation...\n");
			die();
		}

		if (!$do_params_file) {
			InstallFroxlorCmd::printwarn("Enter the username of the unprivileged MySQL user you want Froxlor to use." . PHP_EOL . "The default is 'froxlor'." . PHP_EOL . "CAUTION: any user with that name will be deleted!");

			$p = "MySQL unprivileged user";
			$this->_data['sql']['user'] = InstallFroxlorCmd::getInput($p, 'froxlor');

			while (true) {
				$p = "Enter the password of the MySQL unprivileged user";
				$musrpwd_a = InstallFroxlorCmd::getInput($p, null, true, true);

				$p = "Enter the password of the MySQL unprivileged user again";
				$musrpwd_b = InstallFroxlorCmd::getInput($p, null, true, true);

				if ($musrpwd_a == $musrpwd_b) {
					$this->_data['sql']['password'] = $musrpwd_a;
					break;
				} else {
					InstallFroxlorCmd::printerr("Passwords do not match, please enter again");
				}
			}

			InstallFroxlorCmd::printwarn("Enter the username of the admin user you want in your Froxlor panel." . PHP_EOL . "Default is 'admin'.");

			$p = "Froxlor admin user";
			$this->_data['sys']['admin'] = InstallFroxlorCmd::getInput($p, 'admin');

			while (true) {
				$p = "Enter the password of the Froxlor admin user";
				$madmpwd_a = InstallFroxlorCmd::getInput($p, null, true, true);

				$p = "Enter the password of the Froxlor admin user again";
				$madmpwd_b = InstallFroxlorCmd::getInput($p, null, true, true);

				if ($madmpwd_a == $madmpwd_b) {
					$this->_data['sys']['admin_password'] = $madmpwd_a;
					break;
				} else {
					InstallFroxlorCmd::printerr("Passwords do not match, please enter again");
				}
			}

			$p = "Webserver user name";
			$this->_data['sys']['webserver_user'] = InstallFroxlorCmd::getInput($p, 'www-data');
		}
		return $db_root;
	}

	private function db_check(&$db_root, &$sql)
	{
		if (isset($sql['db']) && !empty($sql['db'])) {
			// check for existence
			$qresult = $db_root->query("SHOW DATABASES LIKE '" . $sql['db'] . "'", PDO::FETCH_ASSOC);
			$result = $qresult->fetchAll();

			$check = null;
			if (is_array($result) && count($result) > 0) {
				$check = array_shift($result[0]);
			}

			if (!empty($check) && $check == $sql['db']) {
				InstallFroxlorCmd::printwarn("Database '" . $sql['db'] . "' already exists.");

				$p = "Would you like to enter a new database name? [Y/n]";
				$proceed = InstallFroxlorCmd::getYesNo($p, 1);

				if ($proceed == 1) {
					unset($sql['db']);
					return $this->db_check($db_root, $sql);
				} else {
					$p = "Would you like to keep the database name and delete the existing one? [y/N]";
					$proceed = InstallFroxlorCmd::getYesNo($p, 0);
					if ($proceed == 1) {
						return true;
					}
					return false;
				}
			}
			return true;
		}
		InstallFroxlorCmd::printwarn("Enter the name of the database you want to use for Froxlor. The default is 'froxlor'");

		$p = "MySQL database name";
		$sql['db'] = InstallFroxlorCmd::getInput($p, 'froxlor');
		return $this->db_check($db_root, $sql);
	}

	private function _installBaseData(&$db_root)
	{
		$sqltmp = $this->_data['basedir'] . 'install/froxlor.sql';

		$mysql_access_host_array = array_map('trim', explode(',', $this->_data['sys']['mysqlaccess_hosts']));
		if (in_array('127.0.0.1', $mysql_access_host_array) || !in_array('localhost', $mysql_access_host_array)) {
			$mysql_access_host_array[] = 'localhost';
		}
		if (!in_array('127.0.0.1', $mysql_access_host_array) && in_array('localhost', $mysql_access_host_array)) {
			$mysql_access_host_array[] = '127.0.0.1';
		}
		if (!in_array($this->_data['sys']['ipaddress'], $mysql_access_host_array)) {
			$mysql_access_host_array[] = $this->_data['sys']['ipaddress'];
		}
		$this->_data['sys']['mysqlaccess_hosts'] = implode(',', $mysql_access_host_array);

		// Basic SQL updates for install
		InstallFroxlorCmd::printwarn("Preparing SQL database files ...");
		InstallFroxlorCmd::confReplace($this->_data['sys']['hostname'], "SERVERNAME", $sqltmp);
		InstallFroxlorCmd::confReplace($this->_data['sys']['ipaddress'], "SERVERIP", $sqltmp);
		InstallFroxlorCmd::confReplace("'mysql_access_host', '" . $this->_data['sys']['mysqlaccess_hosts'] . "'", "'mysql_access_host', 'localhost'", $sqltmp);
		InstallFroxlorCmd::printsucc("[ok]");

		InstallFroxlorCmd::printwarn("Creating froxlor database ...");
		$db_root->exec('DROP DATABASE IF EXISTS ' . $this->_data['sql']['db'] . ';');
		$db_root->exec('CREATE DATABASE ' . $this->_data['sql']['db'] . ' CHARACTER SET=utf8 COLLATE=utf8_general_ci;');
		foreach ($mysql_access_host_array as $mysql_access_host) {
			$this->_grantPrivilegesTo($db_root, $this->_data['sql']['user'], $this->_data['sql']['password'], $mysql_access_host);
		}
		$db_root->exec("FLUSH PRIVILEGES;");
		InstallFroxlorCmd::printsucc("[ok]");

		InstallFroxlorCmd::printwarn("Installing SQL database files ...");
		exec("mysql -u " . $this->_data['sql']['root_user'] . " -p" . $this->_data['sql']['root_password'] . " " . $this->_data['sql']['db'] . " < " . $sqltmp);
		InstallFroxlorCmd::printsucc("[ok]");

		// now we only need the froxlor db and user
		$options = array(
			'PDO::MYSQL_ATTR_INIT_COMMAND' => 'SET names utf8'
		);
		$dsn = "mysql:host=" . $this->_data['sql']['host'] . ";dbname=" . $this->_data['sql']['db'] . ";";
		try {
			$db = new PDO($dsn, $this->_data['sql']['user'], $this->_data['sql']['password'], $options);
			$version_server = $db->getAttribute(PDO::ATTR_SERVER_VERSION);
			$sql_mode = 'NO_ENGINE_SUBSTITUTION';
			if (version_compare($version_server, '8.0.11', '<')) {
				$sql_mode .= ',NO_AUTO_CREATE_USER';
			}
			$db->exec('SET sql_mode = "' . $sql_mode . '"');
		} catch (PDOException $e) {
			// nope
			InstallFroxlorCmd::printerr("Database error: " . $e);
			die();
		}

		InstallFroxlorCmd::printwarn("Creating ip/port entry ...");
		$db->exec("INSERT INTO `panel_ipsandports` (`ip`, `port`, `vhostcontainer`, `vhostcontainer_servername_statement`) VALUES ('" . $this->_data['sys']['ipaddress'] . "', '80', '1', '1');");
		InstallFroxlorCmd::printsucc("[ok]");

		InstallFroxlorCmd::printwarn("Adding Froxlor admin-user...");
		$db->exec("INSERT INTO `panel_admins` SET
  			`loginname` = '" . $this->_data['sys']['admin'] . "',
  			`password` = '" . crypt($this->_data['sys']['admin_password'], '$5$' . md5(uniqid(microtime(), 1)) . md5(uniqid(microtime(), 1))) . "',
  			`name` = 'Siteadmin',
  			`email` = 'admin@" . $this->_data['sys']['hostname'] . "',
			`api_allowed` = 1,
  			`customers` = -1,
  			`customers_see_all` = 1,
  			`caneditphpsettings` = 1,
  			`domains` = -1,
  			`domains_see_all` = 1,
  			`change_serversettings` = 1,
  			`diskspace` = -1024,
  			`mysqls` = -1,
  			`emails` = -1,
  			`email_accounts` = -1,
  			`email_forwarders` = -1,
  			`email_quota` = -1,
  			`ftps` = -1,
  			`subdomains` = -1,
  			`traffic` = -1048576;
		");
		InstallFroxlorCmd::printsucc("[ok]");

		InstallFroxlorCmd::printwarn("Adjusting settings ...");
		$upd_stmt = $db->prepare("
			UPDATE `panel_settings` SET
			`value` = :value
			WHERE `settinggroup` = :group AND `varname` = :varname
		");
		$this->_updateSetting($upd_stmt, 'admin@' . $this->_data['sys']['hostname'], 'panel', 'adminmail');
		$this->_updateSetting($upd_stmt, $this->_data['sys']['webserver'], 'system', 'webserver');
		$this->_updateSetting($upd_stmt, $this->_data['sys']['webserver_user'], 'system', 'httpuser');
		$this->_updateSetting($upd_stmt, $this->_data['sys']['webserver_user'], 'system', 'httpgroup');
		if ($this->_data['sys']['webserver'] == 'apache24') {
			$this->_updateSetting($upd_stmt, 'apache2', 'system', 'webserver');
			$this->_updateSetting($upd_stmt, '1', 'system', 'apache24');
		} elseif ($this->_data['sys']['webserver'] == "lighttpd") {
			$this->_updateSetting($upd_stmt, '/etc/lighttpd/conf-enabled/', 'system', 'apacheconf_vhost');
			$this->_updateSetting($upd_stmt, '/etc/lighttpd/froxlor-diroptions/', 'system', 'apacheconf_diroptions');
			$this->_updateSetting($upd_stmt, '/etc/lighttpd/froxlor-htpasswd/', 'system', 'apacheconf_htpasswddir');
			$this->_updateSetting($upd_stmt, '/etc/init.d/lighttpd reload', 'system', 'apachereload_command');
			$this->_updateSetting($upd_stmt, '/etc/lighttpd/lighttpd.pem', 'system', 'ssl_cert_file');
			$this->_updateSetting($upd_stmt, '/var/run/lighttpd/', 'phpfpm', 'fastcgi_ipcdir');
		} elseif ($this->_data['sys']['webserver'] == "nginx") {
			$this->_updateSetting($upd_stmt, '/etc/nginx/sites-enabled/', 'system', 'apacheconf_vhost');
			$this->_updateSetting($upd_stmt, '/etc/nginx/sites-enabled/', 'system', 'apacheconf_diroptions');
			$this->_updateSetting($upd_stmt, '/etc/nginx/froxlor-htpasswd/', 'system', 'apacheconf_htpasswddir');
			$this->_updateSetting($upd_stmt, '/etc/init.d/nginx reload', 'system', 'apachereload_command');
			$this->_updateSetting($upd_stmt, '/etc/nginx/nginx.pem', 'system', 'ssl_cert_file');
			$this->_updateSetting($upd_stmt, '/var/run/', 'phpfpm', 'fastcgi_ipcdir');
			$this->_updateSetting($upd_stmt, 'error', 'system', 'errorlog_level');
		}
		InstallFroxlorCmd::printsucc("[ok]");

		InstallFroxlorCmd::printwarn("Installing Froxlor data file ...");
		exec("rm -f " . $this->_data['basedir'] . "/lib/userdata.inc.php");
		exec("touch " . $this->_data['basedir'] . "/lib/userdata.inc.php");
		exec("chmod 0640 " . $this->_data['basedir'] . "/lib/userdata.inc.php");
		exec("echo \"<?php
  //automatically generated userdata.inc.php for Froxlor
  \\\$sql['host']='" . $this->_data['sql']['host'] . "';
  \\\$sql['user']='" . $this->_data['sql']['user'] . "';
  \\\$sql['password']='" . $this->_data['sql']['password'] . "';
  \\\$sql['db']='" . $this->_data['sql']['db'] . "';
  \\\$sql_root[0]['caption']='Default';
  \\\$sql_root[0]['host']='" . $this->_data['sql']['host'] . "';
  \\\$sql_root[0]['user']='" . $this->_data['sql']['root_user'] . "';
  \\\$sql_root[0]['password']='" . $this->_data['sql']['root_password'] . "';
  // enable debugging to browser in case of SQL errors
  \\\$sql['debug'] = false;
  ?>\" > " . $this->_data['basedir'] . "/lib/userdata.inc.php");
		InstallFroxlorCmd::printsucc("[ok]");

		InstallFroxlorCmd::printnoln("Correcting permissions for webserver ...");
		exec("chown -R " . $this->_data['sys']['webserver_user'] . ":" . $this->_data['sys']['webserver_user'] . " " . $this->_data['basedir']);
		InstallFroxlorCmd::printsucc("[ok]");
	}

	private function _updateSetting(&$stmt = null, $value = null, $group = null, $varname = null)
	{
		$stmt->execute(array(
			'group' => $group,
			'varname' => $varname,
			'value' => $value
		));
	}

	private function _grantPrivilegesTo(&$db, $username = null, $password = null, $access_host = null, $p_encrypted = false)
	{
		// mysql8 compatibility
		if (version_compare($db->getAttribute(PDO::ATTR_SERVER_VERSION), '8.0.11', '>=')) {
			// create user
			$stmt = $db->prepare("
				CREATE USER '" . $username . "'@'" . $access_host . "' IDENTIFIED BY :password
			");
			$stmt->execute(array(
				"password" => $password
			));
			// grant privileges
			$stmt = $db->prepare("
				GRANT ALL ON `" . $username . "`.* TO :username@:host
			");
			$stmt->execute(array(
				"username" => $username,
				"host" => $access_host
			));
		} else {
			// grant privileges
			$stmt = $db->prepare("
				GRANT ALL PRIVILEGES ON `" . $username . "`.* TO :username@:host IDENTIFIED BY :password
			");
			$stmt->execute(array(
				"username" => $username,
				"host" => $access_host,
				"password" => $password
			));
		}
	}

	private function _importSettings()
	{
		InstallFroxlorCmd::printwarn("Importing settings...");
		exec("php " . $this->_data['basedir'] . "install/scripts/config-services.php --froxlor-dir=" . $this->_data['basedir'] . " --import-settings=" . $this->_args['import-settings']);
		InstallFroxlorCmd::printsucc("[ok]");
	}

	private function importParametersFile()
	{
		if (strtoupper(substr($this->_args["parameters-file"], 0, 4)) == 'HTTP') {
			echo "Settings file seems to be an URL, trying to download" . PHP_EOL;
			$target = "/tmp/parameters-files-" . time() . ".txt";
			if (@file_exists($target)) {
				@unlink($target);
			}
			$this->downloadFile($this->_args["parameters-file"], $target);
			$this->_args["parameters-file"] = $target;
		}
		if (!is_file($this->_args["parameters-file"])) {
			throw new \Exception("Given parameters file is not a file (" . $this->_args["parameters-file"] . ")");
		} elseif (!file_exists($this->_args["parameters-file"])) {
			throw new \Exception("Given parameters file cannot be found ('" . $this->_args["parameters-file"] . "')");
		} elseif (!is_readable($this->_args["parameters-file"])) {
			throw new \Exception("Given parameters file cannot be read ('" . $this->_args["parameters-file"] . "')");
		}
		$imp_content = file_get_contents($this->_args["parameters-file"]);
		return $imp_content;
	}

	private function downloadFile($src, $dest)
	{
		set_time_limit(0);
		// This is the file where we save the information
		$fp = fopen($dest, 'w+');
		// Here is the file we are downloading, replace spaces with %20
		$ch = curl_init(str_replace(" ", "%20", $src));
		curl_setopt($ch, CURLOPT_TIMEOUT, 50);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		// write curl response to file
		curl_setopt($ch, CURLOPT_FILE, $fp);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		// get curl response
		curl_exec($ch);
		curl_close($ch);
		fclose($fp);
	}

	private function showExampleParameters()
	{
		$example = [

			'sql' => array(
				'user' => 'admin',
				'password' => null,
				'db' => 'froxlor',
				'host' => 'localhost',
				'root_user' => 'root',
				'root_password' => null
			),
			'sys' => array(
				'hostname' => 'mydomain.tld',
				'ipaddress' => '123.123.123.123',
				'mysqlaccess_hosts' => 'localhost',
				'nameservers' => '',
				'admin' => 'admin',
				'admin_password' => null,
				'webserver_user' => 'www-data',
				'webserver' => 'apache24'
			)
		];

		InstallFroxlorCmd::println("");
		InstallFroxlorCmd::println("Example parameter file:");
		InstallFroxlorCmd::println("");

		foreach ($example as $key => $values) {
			foreach ($values as $subkey => $value) {
				InstallFroxlorCmd::println($key . "." . $subkey . "=" . ($value ?? ""));
			}
		}
	}
}

// give control to command line handler
try {
	InstallFroxlorCmd::processParameters($argc, $argv);
} catch (Exception $e) {
	InstallFroxlorCmd::printerr($e->getMessage());
}
