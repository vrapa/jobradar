param([string]$Container = 'JobRadar-web', [string]$ConfigPath = (Join-Path $env:USERPROFILE '.codex/config.toml'))
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'credential-store.ps1')
$pythonCommand = Get-Command python -CommandType Application -All | Where-Object { $_.Source -notlike '*\WindowsApps\python.exe' } | Select-Object -First 1
if (!$pythonCommand) { throw 'A real Python executable is required.' }
$pythonExe = $pythonCommand.Source
$store = Join-Path $env:USERPROFILE '.jobradar/credentials'
$roles = [ordered]@{
    'jobradar-executor' = 'executor-token.xml'
    'jobradar-actions' = 'action-sync-token.xml'
    'jobradar-applications' = 'application-workflow-token.xml'
}
# Preflight all sources before changing configuration; DPAPI ciphertext never goes to stdout.
$sources = @{}
foreach ($role in $roles.Keys) {
    $source = & $pythonExe (Join-Path $PSScriptRoot 'credentials.py') --storage-path $roles[$role]
    if ($LASTEXITCODE -ne 0 -or !(Test-Path -LiteralPath $source -PathType Leaf)) { throw "Protected credential unavailable for $role. Nothing was issued or rotated." }
    $null = Import-Clixml -LiteralPath $source
    $sources[$role] = $source
}
Protect-JobRadarCredentialDirectory $store
foreach ($role in $roles.Keys) {
    $destination = Join-Path $store $roles[$role]
    if ([IO.Path]::GetFullPath($sources[$role]) -ne [IO.Path]::GetFullPath($destination)) {
        # Never overwrite an existing credential or delete the recoverable legacy copy.
        [IO.File]::Copy($sources[$role], $destination, $false)
    }
    if ((Get-FileHash -LiteralPath $sources[$role]).Hash -ne (Get-FileHash -LiteralPath $destination).Hash) { throw 'Protected copy verification failed.' }
    & $pythonExe (Join-Path $PSScriptRoot 'configure_mcp.py') --config $ConfigPath --server $role --credential-file $destination --container $Container
    if ($LASTEXITCODE -ne 0) { throw "Registration repair failed for $role." }
}
Write-Output 'JobRadar MCP registrations repaired with existing protected credentials. Reconnect the MCP sessions.'
