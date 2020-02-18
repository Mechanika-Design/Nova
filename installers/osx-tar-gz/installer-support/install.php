<?php

// Main installer for Mac OSX


if (!isset($_SERVER["argc"]) || !$_SERVER["argc"]) {
	echo "This file is intended to be run from the command-line.";

	exit();
}

// Temporary root.
$rootpath = str_replace("\\", "/", dirname(__FILE__));

require_once $rootpath . "/cli.php";
require_once $rootpath . "/dir_helper.php";

$args = CLI::ParseCommandLine(array());

// Determine the OS.
$os      = php_uname("s");
$windows = (strtoupper(substr($os, 0, 3)) == "WIN");
$mac     = (strtoupper(substr($os, 0, 6)) == "DARWIN");

if (!$mac) {
	CLI::DisplayError("This script only runs on Mac OSX.");
}

// Verify that this is a package.
$basepath = str_replace("\\", "/", rtrim(realpath($rootpath . "/../app/"), "/"));
if (!file_exists($basepath . "/Contents/MacOS/server.php")) {
	CLI::DisplayError("This application does not appear to be packaged properly. Missing 'server.php'.");
}

$appname = false;
$dir     = opendir($basepath . "/Contents/MacOS");
while (($file = readdir($dir)) !== false) {
	if (substr($file, - 7) === ".nova") {
		$appname = substr($file, 0, - 7);
	}
}
closedir($dir);
if ($appname === false) {
	CLI::DisplayError("This application does not appear to be packaged properly. Missing the required .nova file.");
}

$packageinfo = json_decode(file_get_contents($basepath . "/Contents/MacOS/package.json"), true);
if (!is_array($packageinfo)) {
	CLI::DisplayError("The 'package.json' file is missing or not valid JSON.");
}

echo "\n\n";
echo "Starting " . $packageinfo["business_name"] . " " . $packageinfo["app_name"] . " " . $packageinfo["app_ver"] . " Installer";
usleep(500000);
echo ".";
usleep(500000);
echo ".";
usleep(500000);
echo ".";
usleep(500000);
echo "\n\n";

// Has the user agreed to the legal agreements.
if (file_exists($basepath . "/Contents/MacOS/" . $appname . "-license.txt")) {
	echo "Please read the following End User License Agreement:\n\n";
	sleep(1);

	echo file_get_contents($basepath . "/Contents/MacOS/" . $appname . "-license.txt") . "\n";

	$agree = CLI::GetYesNoUserInputWithArgs($args, "i_agree", "I agree to the above", false);
	if (!$agree) {
		echo "Installation cancelled.\n";

		exit();
	}

	// Determine if the user is an admin.
	$testdir = "nova_" . microtime(true);
	$admin   = @mkdir("/Applications/" . $testdir);
	@rmdir("/Applications/" . $testdir);

	// Split the installation based on whether or not the app is being installed with admin rights.
	if ($admin) {
		echo "Installing for all users...\n";

		$installpath = "/Applications/" . $packageinfo["app_name"] . ".app";
	} else {
		echo "Installing for just your user account...\n";
		if (getenv("HOME") === false) {
			CLI::DisplayError("Unable to install due to missing environment variable.  Expected 'HOME'.");
		}

		$homepath = getenv("HOME");
		$homepath = rtrim($homepath, "/");

		$installpath = $homepath . "/Applications";
		@mkdir($installpath, 0700);
		@chmod($installpath, 0700);

		$installpath .= "/" . $packageinfo["app_name"] . ".app";
	}

	DirHelper::Delete($installpath);
	@mkdir($installpath, ($admin ? 0755 : 0700));
	@chmod($installpath, ($admin ? 0755 : 0700));

	DirHelper::Copy($basepath, $installpath);
	DirHelper::SetPermissions($installpath, false, false, ($admin ? 0755 : 0700), false, false, ($admin ? 0644 : 0600));

	// Modify the .nova file.
	$filename = $installpath . "/Contents/MacOS/" . $appname . ".nova";
	$data     = file_get_contents($filename);
	$data     = "#!" . PHP_BINARY . "\n" . $data;
	file_put_contents($filename, $data);
	chmod($filename, ($admin ? 0755 : 0700));

	// Remove quarantine so that the user isn't annoyed at having to approve another app.
	system("xattr -r -d com.apple.quarantine " . escapeshellarg($installpath));

	// Optional dock icon.
	if ($packageinfo["user_dock_icon"]) {
		system("defaults write com.apple.dock persistent-apps -array-add \"<dict><key>tile-data</key><dict><key>file-data</key><dict><key>_CFURLString</key><string>" . htmlspecialchars($installpath) . "</string><key>_CFURLStringType</key><integer>0</integer></dict></dict></dict>\"");
		system("killall Dock");
	} else {
		system("open " . escapeshellarg(dirname($installpath)));
	}

	echo "Installation completed. Close this window to exit the installer.\n";
}

?>