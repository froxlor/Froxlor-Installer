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
abstract class CmdLineHandler
{

	/**
	 * internal variable for passed arguments
	 *
	 * @var array
	 */
	private static $args = null;

	/**
	 * Action object read from commandline/config
	 *
	 * @var Action
	 */
	private $_action = null;

	/**
	 * Returns a CmdLineHandler object with given
	 * arguments from command line
	 *
	 * @param int $argc
	 * @param array $argv
	 *
	 * @return CmdLineHandler
	 */
	public static function processParameters($argc, $argv)
	{
		$me = get_called_class();
		return new $me($argc, $argv);
	}

	/**
	 * returns the Action object generated in
	 * the class constructor
	 *
	 * @return Action
	 */
	public function getAction()
	{
		return $this->_action;
	}

	/**
	 * class constructor, validates the command line parameters
	 * and sets the Action-object if valid
	 *
	 * @param int $argc
	 * @param string[] $argv
	 *
	 * @return null
	 * @throws Exception
	 */
	private function __construct($argc, $argv)
	{
		self::$args = $this->_parseArgs($argv);
		$this->_action = $this->_createAction();
	}

	/**
	 * Parses the arguments given via the command line;
	 * three types are supported:
	 * 1.
	 * --parm1 or --parm2=value
	 * 2. -xyz (multiple switches in one) or -a=value
	 * 3. parm1 parm2
	 *
	 * The 1. will be mapped as
	 * ["parm1"] => true, ["parm2"] => "value"
	 * The 2. as
	 * ["x"] => true, ["y"] => true, ["z"] => true, ["a"] => "value"
	 * And the 3. as
	 * [0] => "parm1", [1] => "parm2"
	 *
	 * @param array $argv
	 *
	 * @return array
	 */
	private function _parseArgs($argv)
	{
		array_shift($argv);
		$o = array();
		foreach ($argv as $a) {
			if (substr($a, 0, 2) == '--') {
				$eq = strpos($a, '=');
				if ($eq !== false) {
					$o[substr($a, 2, $eq - 2)] = substr($a, $eq + 1);
				} else {
					$k = substr($a, 2);
					if (! isset($o[$k])) {
						$o[$k] = true;
					}
				}
			} else if (substr($a, 0, 1) == '-') {
				if (substr($a, 2, 1) == '=') {
					$o[substr($a, 1, 1)] = substr($a, 3);
				} else {
					foreach (str_split(substr($a, 1)) as $k) {
						if (! isset($o[$k])) {
							$o[$k] = true;
						}
					}
				}
			} else {
				$o[] = $a;
			}
		}
		return $o;
	}

	/**
	 * Creates an Action-Object for the Action-Handler
	 *
	 * @return Action
	 * @throws Exception
	 */
	private function _createAction()
	{

		// Test for help-switch
		if (array_key_exists("help", self::$args) || array_key_exists("h", self::$args)) {
			static::printHelp();
			// end of execution
		}
		// check if no unknown parameters are present
		foreach (self::$args as $arg => $value) {

			if (is_numeric($arg)) {
				throw new Exception("Unknown parameter '" . $value . "' in argument list");
			} elseif (! in_array($arg, static::$params) && ! in_array($arg, static::$switches)) {
				throw new Exception("Unknown parameter '" . $arg . "' in argument list");
			}
		}

		// set debugger switch
		if (isset(self::$args["d"]) && self::$args["d"] == true) {
			// Debugger::getInstance()->setEnabled(true);
			// Debugger::getInstance()->debug("debug output enabled");
		}

		return new static::$action_class(self::$args);
	}

	public static function getInput($prompt = "#", $default = "", $hidden = false)
	{
		if (! empty($default)) {
			$prompt .= " [" . $default . "]";
		}
		if ($hidden) {
			system('stty -echo');
		}
		$result = readline($prompt . ":");
		if (empty($result) && ! empty($default)) {
			$result = $default;
		}
		if ($hidden) {
			system('stty echo');
		}
		return mb_strtolower($result);
	}

	public static function getDirectory($prompt = "#", $default = null)
	{
		$value = null;
		$_v = null;

		while (true) {
			$_v = self::getInput($prompt, $default);

			if ($_v == '' && $default != null) {
				$value = $default;
				$value = self::makeCorrectDir($value);
				if (! is_dir($value)) {
					$p = "Sorry, directory '" . $value . "' does not exist. Create it? [Y/n]";
					$cdir = self::getYesNo($p, 1);
					if ($cdir == 1) {
						exec("mkdir -p " . $value);
						break;
					} else {
						$value = null;
						continue;
					}
				} else {
					break;
				}
			} else {
				$_v = self::makeCorrectDir($_v);
				if (! is_dir($_v)) {
					$p = "Sorry, directory '" . $_v . "' does not exist. Create it? [y/N]";
					$cdir = self::getYesNo($p, 0);
					if ($cdir == 1) {
						exec("mkdir -p " . $_v);
						$value = $_v;
						break;
					} else {
						$value = null;
						continue;
					}
				} else {
					$value = $_v;
					break;
				}
			}
		}
		return $value;
	}

	/**
	 * Yes-No-Wrapper for STDIN input
	 *
	 * @param string $default
	 *        	optional default value
	 *        	
	 * @return string yes|no
	 */
	public static function getYesNo($prompt = "#", $default = null)
	{
		$value = null;
		$_v = null;

		while (true) {
			$_v = self::getInput($prompt);

			if (strtolower($_v) == 'y' || strtolower($_v) == 'yes') {
				$value = 1;
				break;
			} elseif (strtolower($_v) == 'n' || strtolower($_v) == 'no') {
				$value = 0;
				break;
			} else {
				if ($_v == '' && $default != null) {
					$value = $default;
					break;
				} else {
					echo "Sorry, response " . $_v . " not understood. Please enter 'yes' or 'no'\n";
					$value = null;
					continue;
				}
			}
		}

		return $value;
	}

	/**
	 * sed-wrapper for config-replacings
	 *
	 * @param string $haystack
	 *        	what to replace
	 * @param string $needle
	 *        	value for replacing
	 * @param string $file
	 *        	file in which to replace
	 *
	 * @return null
	 */
	public static function confReplace($haystack = null, $needle = null, $file = null)
	{
		if (file_exists($file)) {
			@exec('sed -e "s|' . $needle . '|' . $haystack . '|g" -i ' . $file);
		} else {
			self::printwarn("WARNING: File '" . $file . "' could not be found! Check paths!!!" . PHP_EOL . "Have to abort install process, could not perform a required action.\n");
			die();
		}
	}

	/**
	 * well-known function to clean a path value
	 *
	 * @param string $path
	 *        	path to secure
	 *        	
	 * @return string path
	 */
	private static function makeSecurePath($path)
	{
		$search = Array(
			'#/+#',
			'#\.+#',
			'#\0+#'
		);
		$replace = Array(
			'/',
			'.',
			''
		);
		$path = preg_replace($search, $replace, $path);
		$path = str_replace(" ", "\ ", $path);
		return $path;
	}

	/**
	 * well-known function to correct a path value
	 *
	 * @param string $dir
	 *        	path to clean
	 *        	
	 * @return string path
	 */
	private static function makeCorrectDir($dir)
	{
		if (substr($dir, - 1, 1) != '/') {
			$dir .= '/';
		}

		if (substr($dir, 0, 1) != '/') {
			$dir = '/' . $dir;
		}

		$dir = self::makeSecurePath($dir);
		return $dir;
	}

	public static function printnoln($msg = "")
	{
		print $msg;
	}

	public static function println($msg = "")
	{
		print $msg . PHP_EOL;
	}

	private static function _printcolor($msg = "", $color = "0")
	{
		print "\033[" . $color . "m" . $msg . "\033[0m" . PHP_EOL;
	}

	public static function printerr($msg = "")
	{
		self::_printcolor($msg, "31");
	}

	public static function printsucc($msg = "")
	{
		self::_printcolor($msg, "32");
	}

	public static function printwarn($msg = "")
	{
		self::_printcolor($msg, "33");
	}
}
