param([string]$PythonExe = 'python', [switch]$Probe)
$ErrorActionPreference = 'Stop'
[Console]::InputEncoding = [Text.UTF8Encoding]::new($false)
[Console]::OutputEncoding = [Text.UTF8Encoding]::new($false)
$env:PYTHONIOENCODING = 'utf-8'
$credentialPath = & $PythonExe (Join-Path $PSScriptRoot 'credentials.py') --storage-path executor-token.xml
if ($LASTEXITCODE -ne 0) { throw 'Could not resolve the protected credential location.' }
$secureToken = Import-Clixml -LiteralPath $credentialPath
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureToken)
try { $env:JOBRADAR_EXECUTOR_TOKEN = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) }
finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
try {
    if ($Probe) { & $PythonExe (Join-Path $PSScriptRoot 'probe.py') }
    else { & $PythonExe (Join-Path $PSScriptRoot 'server.py') }
    exit $LASTEXITCODE
} finally { Remove-Item Env:JOBRADAR_EXECUTOR_TOKEN -ErrorAction SilentlyContinue }
