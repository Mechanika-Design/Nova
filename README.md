# Nova
Create lightweight, installable applications written in HTML, CSS, Javascript, and PHP for Windows, Mac, and Linux desktop operating systems.

![nova-screenshot-installer](https://user-images.githubusercontent.com/16408188/74779451-5da34b00-5252-11ea-95dd-04c0f43faac4.png)

What can be created with Nova are real, installable software applications that take a fraction of the time to create when compared to traditional desktop application development.  Go from idea/concept to full deployment in 1/10th the time and support every major desktop OS with just one code base.

Nova is a fully-featured and extensible web server written in PHP with custom features specially designed to be used in a traditional desktop OS environment. When the user runs the software via their Start menu, application launcher, etc., the software starts the server and then launches the user's preferred web browser to access the application. PHP powers the backend while the web browser handles all the nitty-gritty details of displaying the user interface. The ready-made installer scripts simplify the process of creating final release packages for delivery to your user's computer systems.

Features
--------

* Fully extensible web server written in PHP.  (This is not the built-in web server found in PHP itself.)
* Relies on the user's preferred web browser instead of a traditional GUI.  Write applications with HTML, CSS, Javascript, and PHP.
* Long running process and high-performance localhost API support.
* WebSocket support.
* Virtual directory support.
* Dual document roots.
* Access and error logging.
* Zero configuration.
* Pre-made boilerplate installer scripts for Windows (EXE and MSI), Mac (.tar.gz), and Linux (.tar.gz) with custom EULA and clean update support.
* Tiny installer sizes.  From 85KB (Linux, .tar.gz) up to 10MB (Windows, .exe).
* Branded to look like your own software application.  Your app's custom icon is placed in the Start menu, application launcher, desktop/dock, etc.
* Fully isolated, zero conflict software.
* Designed for relatively painless integration into your project.

Getting Started
---------------

Download or clone the latest software release.  When cloning, be sure to use a fork and create a branch for your app before beginning development.  Doing so avoids accidentally overwriting your software whenever you fetch upstream updates for Nova itself.

For the rest of this guide, a recent version of PHP is assumed to be installed.  There are many ways to make that happen.

From a command-line, run the following to get a list of command-line options:

```
php server.php -?
```

Start a web server on port 9002 by running:

```
php server.php -port=9002
```

The directory structure of Nova is as follows:

* www - Where standard web server files go, including PHP files.  This is actually treated as a view of two separate directories.
* support - Contains files required by `server.php` to operate.
* extensions - Where long running processes, high performance APIs, and WebSocket responders can be registered.
* installers - Pre-made installer scripts for various OSes.

Create an `index.php` file in the 'www' directory:

```php
<?php
	phpinfo();
```

Connect to the running server with your web browser at:

```
http://127.0.0.1:9002/
```

The output of `phpinfo()` is displayed in the browser and the result of the request is written to the command-line.

Change the URL to:

```
http://127.0.0.1:9002/api/v1/account/info
```

The same `index.php` file runs.

Rename or copy the `index.php` file to `api.php` and reload the page.  Now `api.php` is being called.  The virtual directory feature of Nova is something that you might find useful as you develop your application.

Dual Document Roots
-------------------

Installed software applications cannot write to 'www'.  This is because applications are usually installed by a privileged user on the system while the person running the software typically does not have sufficient privileges to write to the 'www' directory.  This is an important consideration to keep in mind while developing a software application using Nova.  Fortunately, there is a solution to this problem already built into the server:  Dual document roots.

When PHP code is executing from the application's 'www' directory, it has access to four `$_SERVER` variables that are passed in by and are unique to the Nova environment:

* $_SERVER["DOCUMENT_ROOT_USER"] - A document root that can be written to and referenced by URLs.  Resides in the user's HOME directory on a per-OS basis.  Note that any '.php' files stored here are ignored by Nova for security reasons.
* $_SERVER["NOVA_USER_FILES"] - The parent directory of `DOCUMENT_ROOT_USER`.  Can also be written to but cannot be referenced by URLs.  Useful for storing private data for the application (e.g. a SQLite database).
* $_SERVER["NOVA_PROG_FILES"] - The directory containing the access and error log files for Nova.  Useful for providing a page in the application itself to view the error log file and other debugging information, which could be useful for debugging issues with the installed application on a user's system.
* $_SERVER["NOVA_SECRET"] - An internal, per-app instance session secret.  Useful for generating application XSRF tokens.

When a request is made to the web server, Nova looks first for files in the application's 'www' directory.  If it doesn't find a file there, it then checks for the file in the path specified by `DOCUMENT_ROOT_USER`.

Writing Secure Software
-----------------------

Writing a localhost server application that relies on a web browser can result in serious system security violations ranging from loss of data control to damaging the user's file system.  As long as the application is written correctly, the web browser's policies will generally protect the user from malicious websites and users that attempt to access Nova controlled content.

However, here are a few important, select security related items that all Nova based software applications must actively defend against (in order of importance):

* [Sensitive data exposure](https://www.owasp.org/index.php/Top_10-2017_A3-Sensitive_Data_Exposure) - Use `$_SERVER["NOVA_USER_FILES"]` or a user-defined location to store sensitive user data instead of `$_SERVER["DOCUMENT_ROOT_USER"]`.  Always ask the user what to do if they might consider something to be sensitive (e.g. asking could be as simple as displaying a checkbox to the user).  Privacy-centric individuals will generally speak their mind.
* [Cross-site request forgery attacks](https://www.owasp.org/index.php/Cross-Site_Request_Forgery_(CSRF)) - `$_SERVER["NOVA_SECRET"]` combined with other application frameworks help to handle this issue.
* [Session fixation attacks](https://www.owasp.org/index.php/Session_fixation) - The [security token extension](extensions/1_security_token.php) that is included with Nova automatically deals with this issue.
* [SQL injection attacks](https://www.owasp.org/index.php/SQL_Injection) - Relevant when using a database.  To avoid this, just don't run raw queries and use a good Database Access Layer (DAL) class like [MDDB](https://github.com/mechanika-design/mddb/).

There are many other security considerations that are in the [OWASP Top 10 list](https://www.owasp.org/index.php/Category:OWASP_Top_Ten_Project) and the [OWASP attacks list](https://www.owasp.org/index.php/Category:Attack) to also keep in mind, but those are the big ones.

Creating Extensions
-------------------

Writing an extension requires a little bit of knowledge about how Nova works:  Extensions are loaded early on during startup so they can get involved in the startup sequence if they need to (mostly just for security-related extensions).  Once the web server has started, every web request walks through the list of extensions and asks, "Can you handle this request?"  If an extension responds in the affirmative (i.e. returns true), then the rest of the request is passed off to the extension to handle.

Since extensions are run directly inline with the core server, they get a significant performance boost and can do things such as respond over WebSocket or start long-running processes that would normally be killed off after 30 seconds by the normal PHP path.

However, those benefits come with two major drawbacks.  The first is that if an extension raises an uncaught exception or otherwise crashes, it takes the whole web server with it.  The second is that making code changes to an extension requires restarting the web server to test the changes, which can be a bit of a hassle.  In general, the normal 'www' path is sufficient for most needs and extensions are for occasional segments of specialized logic.

The included [security token extension](extensions/1_security_token.php) is an excellent starting point for building an extension that can properly handle requests.  The security token extension is fairly short, well-commented, and works.

The server assumes that the filename is a part of the class name.  Whatever the PHP file is named, the class name within has to follow suit, otherwise Nova will fail to load the extension.  Extension names should start with a number, which indicates the expected order.

The variables available to normal PHP scripts are also available to extensions via the global `$baseenv` variable (e.g. `$baseenv["DOCUMENT_ROOT_USER"]` and `$baseenv["NOVA_USER_FILES"]`).  Please do not alter the `$baseenv` values as that will negatively affect the rest of the application.

Always use the `ProcessHelper::StartProcess()` static function when starting external, long-running processes.

Pre-Installer Tasks
-------------------

Before running the various scripts that generate installer packages, various files need to be created, renamed, and/or modified.  Every file that starts with "yourapp" needs to be renamed to your application name, preferably restricted to all lowercase a-z and hyphens.  This is done so that updates to the software don't accidentally overwrite your work and so that any nosy users poking around the directory structure see the application's actual name instead of "yourapp".

* yourapp.png - A 512x512 pixel PNG image containing your application icon.  It should be fairly easy to tell what the icon represents when shrunk to 24x24 pixels.  The default icon works for testing but should be replaced with your own icon before deploying.
* yourapp.ico - A Windows .ico file containing your application icon at as many resolutions and sizes as possible.  The default icon works for testing but should be replaced with your own icon before deploying.
* yourapp.phpapp - This file needs to be modified.  More on this file in a moment.
* yourapp-license.txt - Replace the text within with an actual End User License Agreement (EULA) written and approved by a real lawyer.

The 'yourapp.phpapp' file is a PHP file that performs the actual application startup sequence of starting the web server (server.php) and then launching the user's web browser.  There is an `$options` array in the file that should be modified for your application's needs:

* business - A string containing your business or your name (Default is "Mechanika Design", which is probably not really what is desired).  Shown under some OSes when displaying a program listing - notably Linux.
* appname - A boolean of false or a string containing your application's name (Default is false, which attempts to automatically determine the app's name based on the directory it is installed in).  Shown under some OSes when displaying a program listing - notably Linux.
* home - An optional string containing the directory to use as the "home" directory.  Could be useful for implementing a "portable" version of the application.
* host - A string containing the IP address to bind to (Default is "127.0.0.1").  In general, don't change this.
* port - An integer containing the port number to bind to (Default is 0, which selects a random port number).  In general, don't change this.
* quitdelay - An integer specifying the number of minutes after the last client disconnects to quit running the server (Default is 6).  In general, don't change this.

The last three options are intended for highly specialized scenarios.  Changing 'host' to something like "127.0.1.1" might be okay but don't use "0.0.0.0" or "::0", which binds the server publicly to the network interface.  Binding to a specific 'port' number might seem like a good idea until users start complaining about error messages when they try to restart the application.

The 'quitdelay' option is interesting.  The server portion of Nova will stick around until 'quitdelay' minutes after the last client disconnects.  The application should send a "heartbeat" request every five minutes to guarantee that the web server won't terminate itself before the user is finished using the application.

Installer Packaging
-------------------

Each platform packaging tool has its own instructions:

* [Windows, EXE](installers/win-innosetup/README.md) - Inno Setup based installer script with specialized support for the MSI build process.
* [Windows, MSI](installers/win-wix/README.md) - WiX Toolset based installer script.  Build the EXE with Inno Setup first.
* [Mac OSX, .tar.gz](installers/osx-tar-gz/README.md) - Rearranges the application into an .app format and then wraps the application up in a custom installer .app and finally puts the whole mess into a .tar.gz file.  Think Very Different.  This approach also doesn't require owning a Mac, which is kind of cool because not everyone can afford the expensive hardware.
* [Linux, .tar.gz](installers/nix-tar-gz/README.md) - Produces the smallest output file out of all of the application packagers.  The installer relies on the system package manager to install PHP and other dependencies on Debian, RedHat, and Arch-based systems - that is, there is fairly broad distro coverage.  The installer itself requires a Freedesktop.org-compliant window manager that supports `xdg-utils` (Gnome, KDE, XFCE, etc. are all fine).

There are some known packaging issues:

* Code signing support is missing - I don't like code signing and neither should anyone else until we can all use DNSSEC DANE TLSA Certificate usage 3 with Authenticode/Gatekeeper/etc.  (Hint to Microsoft/Apple:  Publisher = domain name).  Feel free to open a pull request if you implement really good support for optional code signing in the various packagers.  I'm not particularly interested in code signing given how pointless, fairly expensive, and obnoxious it tends to be.
* The current Mac installer script is not visually attractive - The installer currently launches the Terminal app to run the real installer. I'll let you discover what that means. I was trying to bring the Linux style installer to Mac and it didn't work out very well. The next release will hopefully be much better looking.  There's an interesting piece of software called Nova...
* The current Mac installer occasionally breaks thanks to App Translocation - When 'install.sh' prematurely terminates while the installer is still running, the `AppTranslocation` directory where the application is running from vanishes.  It just goes away.  This is thanks to Mac Gatekeeper not expecting apps to exit after transferring control to other programs on the system.