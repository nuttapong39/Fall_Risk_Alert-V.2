' launch_detached.vbs
' Launches one command in a VISIBLE console window, elevated (Run as
' administrator), without waiting for it to finish. Used by
' system_update_action.php to start task\update.bat from a web request.
'
' Why this script exists at all: PHP's exec() WAITS for the command to exit, and
' update.bat ends with `pause` - calling the .bat directly from PHP would hang the
' HTTP request forever. (proc_open()+proc_close() is no good either: on Windows it
' ties the child to a Job Object that is torn down - killing the child - as soon as
' the PHP request finishes, so even "start /B" never survives.) ShellExecute below
' returns immediately, leaving a genuinely independent process behind.
'
' The two trailing arguments are what make the update visible and privileged:
'   "runas" - elevate. If the caller is already elevated (XAMPP started as admin)
'             this runs straight through with no UAC prompt; otherwise Windows
'             shows the UAC consent dialog.
'   1       - SW_SHOWNORMAL: the console window is visible so the operator can
'             watch the update run and read the result.
'
' NOTE: both the window and the UAC prompt only appear when the calling process
' lives in the interactive desktop session. If Apache is installed as a Windows
' Service it runs in Session 0, which Windows isolates from the desktop, and
' nothing is shown at all - system_update_action.php checks for that before
' calling this script and tells the operator to run update.bat by hand instead.
'
' Usage: wscript.exe //B launch_detached.vbs "C:\path\to\command.bat"
Set objShell = CreateObject("Shell.Application")
objShell.ShellExecute WScript.Arguments(0), "", "", "runas", 1
