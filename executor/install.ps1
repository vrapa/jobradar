param([string]$Container = 'JobRadar-web', [int]$OwnerId = 0, [switch]$ReplaceRevokedCredential, [switch]$RepairRegistration)
$ErrorActionPreference = 'Stop'
$credentialDirectory = Join-Path $env:LOCALAPPDATA 'JobRadar'
$credentialPath = Join-Path $credentialDirectory 'executor-token.xml'
$pythonCommand = Get-Command python -CommandType Application -All | Where-Object { $_.Source -notlike '*\WindowsApps\python.exe' } | Select-Object -First 1
if (!$pythonCommand) { throw 'A real Python executable was not found; the Windows Store alias is not sufficient.' }
$pythonExe = $pythonCommand.Source
if ($RepairRegistration) {
    if ($ReplaceRevokedCredential) { throw 'Registration repair cannot replace credentials.' }
    & $pythonExe (Join-Path $PSScriptRoot 'configure_mcp.py') --server jobradar-executor --credential-file $credentialPath
    if ($LASTEXITCODE -ne 0) { throw 'MCP registration repair failed.' }
    return
}
if (Test-Path -LiteralPath $credentialPath) {
    if (!$ReplaceRevokedCredential) { throw 'Executor credential already exists; use the existing installation.' }
    $resolvedCredential = (Resolve-Path -LiteralPath $credentialPath).Path
    $expectedCredential = [IO.Path]::GetFullPath((Join-Path $env:LOCALAPPDATA 'JobRadar/executor-token.xml'))
    if ($resolvedCredential -ne $expectedCredential) { throw 'Unexpected credential path.' }
    Move-Item -LiteralPath $resolvedCredential -Destination ($resolvedCredential + '.revoked-' + [DateTime]::UtcNow.ToString('yyyyMMddHHmmss'))
}
# Capture the one-time token without printing it to the console or command line.
$ownerArguments = @()
if ($OwnerId -gt 0) { $ownerArguments = @('-e', "JOBRADAR_EXECUTOR_OWNER_ID=$OwnerId") }
$registrationText = & docker exec -u www-data -e JOBRADAR_MCP_TOKEN @ownerArguments $Container php bin/executor-admin.php create
if ($LASTEXITCODE -ne 0) { throw 'Executor registration failed.' }
$registration = $registrationText | ConvertFrom-Json
try {
    New-Item -ItemType Directory -Force -Path $credentialDirectory | Out-Null
    $registration.token | ConvertTo-SecureString -AsPlainText -Force | Export-Clixml -LiteralPath $credentialPath
    & $pythonExe (Join-Path $PSScriptRoot 'configure_mcp.py') --server jobradar-executor --credential-file $credentialPath
    if ($LASTEXITCODE -ne 0) { throw 'MCP registration failed.' }
    Write-Output "Executor MCP configured. Token ID: $($registration.token_id); expires: $($registration.expires_at). Credential is protected with Windows DPAPI."
} catch {
    & docker exec -u www-data -e JOBRADAR_MCP_TOKEN @ownerArguments $Container php bin/executor-admin.php revoke $registration.token_id
    throw 'Installation failed; the issued token was revoked. Check the local credential file before retrying.'
} finally {
    $registrationText = $null
    $registration = $null
}
