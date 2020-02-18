<?php

// Nova support functions.

function Nova_LoadServerExtensions() {
	global $rootpath;

	$serverexts = array();
	$dir        = opendir($rootpath . "/extensions");
	if ($dir !== false) {
		while (($file = readdir($dir)) !== false) {
			if (substr($file, - 4) === ".php") {
				require_once $rootpath . "/extensions/" . $file;

				$key              = substr($file, 0, - 4);
				$classname        = "Nova_Extension_" . $key;
				$serverexts[$key] = new $classname;
			}
		}

		closedir($dir);
	}

	ksort($serverexts, SORT_NATURAL);

	return $serverexts;
}

function Nova_DisplayErrorDialog($title, $msg, $result = false) {
	global $rootpath;

	$os      = php_uname("s");
	$windows = (strtoupper(substr($os, 0, 3)) == "WIN");
	$mac     = (strtoupper(substr($os, 0, 6)) == "DARWIN");

	if ($windows) {
		system(escapeshellarg($rootpath . "/support/messagebox-win.exe") . " /f=MB_ICONERROR " . escapeshellarg($msg) . " " . escapeshellarg($title));
	} else if ($mac && ($execfile = ProcessHelper::FindExecutable("osascript", "/usr/bin")) !== false) {
		system(escapeshellarg($execfile) . " -e " . escapeshellarg("display dialog \"" . $msg . "\" with title \"" . $title . "\" buttons {\"OK\"} default button \"OK\""));
	} else if (($execfile = ProcessHelper::FindExecutable("zenity", "/usr/bin")) !== false) {
		system(escapeshellarg($execfile) . " --error --title " . escapeshellarg($title) . " --text " . escapeshellarg($msg) . " --width=400 2>/dev/null");
	}

	CLI::DisplayError($title . " - " . $msg, $result);
}

function Nova_GetAdminPHPBinary($rootpath) {
	$os      = php_uname("s");
	$windows = (strtoupper(substr($os, 0, 3)) == "WIN");
	$mac     = (strtoupper(substr($os, 0, 6)) == "DARWIN");

	if ($windows) {
		$cmd = escapeshellarg(ProcessHelper::FindExecutable("cmd.exe")) . " /C start \"\" " . escapeshellarg(dirname(PHP_BINARY) . "\\php-win-elevated.exe");
	} else if ($mac) {
		@chmod($rootpath . "/support/mac-gksudo.sh", 0777);
		$cmd = escapeshellarg($rootpath . "/support/mac-gksudo.sh") . " " . escapeshellarg(PHP_BINARY);
	} else if (($execfile = ProcessHelper::FindExecutable("pkexec", "/usr/bin")) !== false) {
		$cmd = escapeshellarg($execfile) . " " . escapeshellarg(PHP_BINARY);
	} else if (($execfile = ProcessHelper::FindExecutable("gksudo", "/usr/bin")) !== false) {
		$cmd = escapeshellarg($execfile) . " " . escapeshellarg(PHP_BINARY);
	} else {
		$cmd = escapeshellarg("/usr/bin/gksudo") . " " . escapeshellarg(PHP_BINARY);
	}

	return $cmd;
}

function Nova_StartServer($options) {
	global $rootpath;

	$os      = php_uname("s");
	$windows = (strtoupper(substr($os, 0, 3)) == "WIN");
	$mac     = (strtoupper(substr($os, 0, 6)) == "DARWIN");

	$appname = (isset($options["appname"]) && is_string($options["appname"]) ? $options["appname"] : "Nova");

	if (isset($options["runasroot"]) && $options["runasroot"]) {
		// Run PHP as root/admin.
		$cmd = Nova_GetAdminPHPBinary($rootpath);

		$root = true;
	} else if (function_exists("posix_geteuid") && posix_geteuid() == 0) {
		Nova_DisplayErrorDialog("Server Startup Error", "The web server portion of " . $appname . " was attempted to be started as the root user.  " . $appname . " is not designed to be run as root.  Error 100");
	} else {
		// Run PHP as the current user.
		$cmd = escapeshellarg(PHP_BINARY);

		$root = false;
	}

	$cmd .= " " . escapeshellarg($rootpath . "/server.php");

	if (isset($options["userhome"]) && is_dir($options["userhome"])) {
		$cmd .= " " . escapeshellarg("-home=" . $options["userhome"]);
	}
	if (isset($options["appname"]) && is_string($options["appname"])) {
		$cmd .= " " . escapeshellarg("-app=" . $appname);
	}
	if (isset($options["business"]) && is_string($options["business"])) {
		$cmd .= " " . escapeshellarg("-biz=" . $options["business"]);
	}
	if (isset($options["host"]) && is_string($options["host"])) {
		$cmd .= " " . escapeshellarg("-host=" . $options["host"]);
	}
	if (isset($options["port"]) && $options["port"] > 0) {
		$cmd .= " " . escapeshellarg("-port=" . (int) $options["port"]);
	}
	if (isset($options["user"]) && is_string($options["user"])) {
		$cmd .= " " . escapeshellarg("-user=" . $options["user"]);
	}
	if (isset($options["group"]) && is_string($options["group"])) {
		$cmd .= " " . escapeshellarg("-group=" . $options["group"]);
	}
	if (isset($options["quitdelay"]) && $options["quitdelay"] > 0) {
		$cmd .= " " . escapeshellarg("-quit=" . $options["quitdelay"] * 60);
	}

	// Have the server write the results of its startup sequence to a file, which will contain the URL to point a web browser at on successful startup.
	$sdir = sys_get_temp_dir();
	$sdir = str_replace("\\", "/", $sdir);
	if (substr($sdir, - 1) !== "/") {
		$sdir .= "/";
	}
	$sdir .= "php_app_server_start_" . getmypid() . "_" . microtime(true) . "/";
	@mkdir($sdir, 0750, true);
	$sfile = $sdir . "info.json";
	file_put_contents($sfile, "");
	@chmod($sfile, 0640);

	$cmd .= " " . escapeshellarg("-sfile=" . $sfile);
	//echo $cmd . "\n";

	$options2 = array(
		"stdin"  => false,
		"stdout" => false,
		"stderr" => false,
		"dir"    => $rootpath
	);

	$result = ProcessHelper::StartProcess($cmd, $options2);
	//var_dump($result);
	if (!$result["success"]) {
		@unlink($sfile);
		@rmdir($sdir);

		Nova_DisplayErrorDialog("Server Startup Error", "Unable to start the web server portion of " . $appname . ". Contact the developer of the application. Error 101", $result);
	}

	// Wait for the process to exit OR the startup sequence file to be written to.
	$ts = time();
	do {
		usleep(50000);

		$sinfo = @json_decode(file_get_contents($sfile), true);
		$pinfo = @proc_get_status($result["proc"]);
	} while (!is_array($sinfo) && ($pinfo["running"] || ($root && $ts + 120 > time())));

	$sinfo = @json_decode(file_get_contents($sfile), true);
	@unlink($sfile);
	@rmdir($sdir);
	if (!is_array($sinfo)) {
		Nova_DisplayErrorDialog("Server Startup Error", "Unable to start the web server portion of " . $appname . ". Contact the developer of the application. Error 102");
	}
	if (!$sinfo["success"]) {
		if (isset($options["port"]) && $options["port"] > 0 && $sinfo["errorcode"] === "bind_failed") {
			// Only remove the security token extension if you are absolutely 100% sure that you know what you are doing and fully understand the risks. Hint: You don't.
			if (file_exists($rootpath . "/extensions/security_token.php")) {
				Nova_DisplayErrorDialog("Server Still Running", "The web server portion of " . $appname . " is still running from a previous launch on port " . (int) $options["port"] . ".  Try again in a few minutes.  If this message continues to appear, contact the developer of the application.  Error 103");
			} else {
				// Fake a valid response so the web browser launches.  Someone other than Mechanika Design has taken legal responsibility if it all goes sideways.
				if (!isset($options["host"]) || !is_string($options["host"])) {
					$options["host"] = "127.0.0.1";
				}

				return array(
					"success" => true,
					"url"     => "http://" . $options["host"] . ":" . (int) $options["port"],
					"port"    => (int) $options["port"]
				);
			}
		}
	} else {
		Nova_DisplayErrorDialog("Server Startup Error", "Unable to start the web server portion of " . $appname . ". Contact the developer of the application. Error 104", $sinfo);
	}

	return $sinfo;
}

function Nova_LaunchWebBrowser($url) {
	$os      = php_uname("s");
	$windows = (strtoupper(substr($os, 0, 3)) == "WIN");
	$mac     = (strtoupper(substr($os, 0, 6)) == "DARWIN");

	$options = array(
		"stdin"  => false,
		"stdout" => false,
		"stderr" => false
	);

	if ($windows) {
		$result = ProcessHelper::StartProcess(escapeshellarg(ProcessHelper::FindExecutable("cmd.exe")) . " /C start \"\" " . escapeshellarg($url), $options);
		//var_dump($result);
	} else if ($mac && file_exists("/usr/bin/open")) {
		ProcessHelper::StartProcess(escapeshellarg("/usr/bin/open") . " " . escapeshellarg($url), $options);
	} else if (file_exists("/usr/bin/xdg-open")) {
		ProcessHelper::StartProcess(escapeshellarg("/usr/bin/xdg-open") . " " . escapeshellarg($url), $options);
	} else {
		Nova_DisplayErrorDialog("Web Browser Launch Error", "Unable to launch the preferred web browser. Launch your web browser manually and go to:  " . $url);
	}
}

?>