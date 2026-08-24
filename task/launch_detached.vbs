' launch_detached.vbs
' Launches one command as a fully detached background process (window hidden,
' does not wait). Used by system_update_action.php to start task\update.bat
' from a web request.
'
' Why this exists: PHP's proc_open()+proc_close() on Windows ties the child
' process to a Job Object that gets torn down (killing the child) as soon as
' the PHP request finishes/closes the handle - so a plain proc_open("start
' /B ...") never actually survives long enough to run update.bat. WScript.Shell.Run
' with the 3rd argument (bWaitOnReturn) set to False creates a genuinely
' independent process that keeps running after this script exits.
'
' Usage: wscript.exe //B launch_detached.vbs "C:\path\to\command.bat"
Set objShell = CreateObject("WScript.Shell")
objShell.Run """" & WScript.Arguments(0) & """", 0, False
