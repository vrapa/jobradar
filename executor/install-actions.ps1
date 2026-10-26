param([string]$Container = 'JobRadar-web', [int]$OwnerId = 0, [switch]$ReplaceRevokedCredential, [switch]$RotateCredential, [switch]$Applications, [switch]$RepairRegistration)
$ErrorActionPreference = 'Stop'
$credentialDirectory = Join-Path $env:LOCALAPPDATA 'JobRadar'
$credentialName = if ($Applications) { 'application-workflow-token.xml' } else { 'action-sync-token.xml' }
$serverName = if ($Applications) { 'jobradar-applications' } else { 'jobradar-actions' }
$applicationMode = if ($Applications) { '1' } else { '0' }
$credentialPath = Join-Path $credentialDirectory $credentialName
$pythonCommand = Get-Command python -CommandType Application -All | Where-Object { $_.Source -notlike '*\WindowsApps\python.exe' } | Select-Object -First 1
if (!$pythonCommand) { throw 'A real Python executable was not found; the Windows Store alias is not sufficient.' }
$pythonExe = $pythonCommand.Source
if ($RepairRegistration) {
    if ($RotateCredential -or $ReplaceRevokedCredential) { throw 'Registration repair cannot rotate credentials.' }
    & $pythonExe (Join-Path $PSScriptRoot 'configure_mcp.py') --server $serverName --credential-file $credentialPath --container $Container
    if ($LASTEXITCODE -ne 0) { throw 'MCP registration repair failed.' }
    return
}
if ($OwnerId -le 0) { throw 'Credential registration requires an explicit positive OwnerId.' }
$registrationMode = 'create'
$backupPath = $null
if (Test-Path -LiteralPath $credentialPath) {
    if (!$ReplaceRevokedCredential -and !$RotateCredential) { throw 'Action sync credential already exists; use the existing installation or rotate it explicitly.' }
    $resolvedCredential = (Resolve-Path -LiteralPath $credentialPath).Path
    $expectedCredential = [IO.Path]::GetFullPath((Join-Path $credentialDirectory $credentialName))
    if ($resolvedCredential -ne $expectedCredential) { throw 'Unexpected credential path.' }
    $backupPath = $resolvedCredential + '.replaced-' + [DateTime]::UtcNow.ToString('yyyyMMddHHmmss')
    Copy-Item -LiteralPath $resolvedCredential -Destination $backupPath
    $registrationMode = 'rotate'
}
$registrationText = & docker exec -u www-data -e APP_RUNTIME_DIR=/tmp/jobradar-action-sync-admin -e "JOBRADAR_ACTION_SYNC_OWNER_ID=$OwnerId" -e "JOBRADAR_APPLICATION_WORKFLOW=$applicationMode" $Container php bin/action-sync-admin.php $registrationMode
if ($LASTEXITCODE -ne 0) { throw 'Action sync registration failed.' }
$registrationLine = @($registrationText) | Where-Object { $_ -match '^\s*\{.*\}\s*$' } | Select-Object -Last 1
if (!$registrationLine) { throw 'Action sync registration did not return its JSON result.' }
$registration = $registrationLine | ConvertFrom-Json
try {
    New-Item -ItemType Directory -Force -Path $credentialDirectory | Out-Null
    $registration.token | ConvertTo-SecureString -AsPlainText -Force | Export-Clixml -LiteralPath $credentialPath
    & $pythonExe (Join-Path $PSScriptRoot 'configure_mcp.py') --server $serverName --credential-file $credentialPath --container $Container
    if ($LASTEXITCODE -ne 0) { throw 'MCP registration failed.' }
    $failedPreviousTokenIds = @()
    foreach ($previousTokenId in @($registration.previous_token_ids)) {
        & docker exec -u www-data -e APP_RUNTIME_DIR=/tmp/jobradar-action-sync-admin -e "JOBRADAR_ACTION_SYNC_OWNER_ID=$OwnerId" $Container php bin/action-sync-admin.php revoke $previousTokenId | Out-Null
        if ($LASTEXITCODE -ne 0) { $failedPreviousTokenIds += $previousTokenId }
    }
    Write-Output "Action sync MCP configured. Token ID: $($registration.token_id); expires: $($registration.expires_at). Credential is protected with Windows DPAPI."
    if ($failedPreviousTokenIds.Count -gt 0) { Write-Warning "New credential is active, but previous token IDs could not be revoked: $($failedPreviousTokenIds -join ', ')." }
} catch {
    if ($registration -and $registration.token_id) {
        & docker exec -u www-data -e APP_RUNTIME_DIR=/tmp/jobradar-action-sync-admin -e "JOBRADAR_ACTION_SYNC_OWNER_ID=$OwnerId" $Container php bin/action-sync-admin.php revoke $registration.token_id
    }
    if ($backupPath -and (Test-Path -LiteralPath $backupPath)) { Copy-Item -LiteralPath $backupPath -Destination $credentialPath -Force }
    throw 'Installation failed; the issued token was revoked.'
} finally {
    $registrationText = $null
    $registration = $null
}
