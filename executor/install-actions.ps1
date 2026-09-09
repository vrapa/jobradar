param([string]$Container = 'JobRadar-web', [Parameter(Mandatory = $true)][int]$OwnerId, [switch]$ReplaceRevokedCredential, [switch]$Applications)
$ErrorActionPreference = 'Stop'
$credentialDirectory = Join-Path $env:LOCALAPPDATA 'JobRadar'
$credentialName = if ($Applications) { 'application-workflow-token.xml' } else { 'action-sync-token.xml' }
$serverName = if ($Applications) { 'jobradar-applications' } else { 'jobradar-actions' }
$applicationMode = if ($Applications) { '1' } else { '0' }
$credentialPath = Join-Path $credentialDirectory $credentialName
if (Test-Path -LiteralPath $credentialPath) {
    if (!$ReplaceRevokedCredential) { throw 'Action sync credential already exists; use the existing installation.' }
    $resolvedCredential = (Resolve-Path -LiteralPath $credentialPath).Path
    $expectedCredential = [IO.Path]::GetFullPath((Join-Path $credentialDirectory $credentialName))
    if ($resolvedCredential -ne $expectedCredential) { throw 'Unexpected credential path.' }
    Move-Item -LiteralPath $resolvedCredential -Destination ($resolvedCredential + '.revoked-' + [DateTime]::UtcNow.ToString('yyyyMMddHHmmss'))
}
$pythonExe = (Get-Command python -CommandType Application | Select-Object -First 1).Source
$registrationText = & docker exec -u www-data -e APP_RUNTIME_DIR=/tmp/jobradar-action-sync-admin -e "JOBRADAR_ACTION_SYNC_OWNER_ID=$OwnerId" -e "JOBRADAR_APPLICATION_WORKFLOW=$applicationMode" $Container php bin/action-sync-admin.php create
if ($LASTEXITCODE -ne 0) { throw 'Action sync registration failed.' }
$registration = $registrationText | ConvertFrom-Json
try {
    New-Item -ItemType Directory -Force -Path $credentialDirectory | Out-Null
    $registration.token | ConvertTo-SecureString -AsPlainText -Force | Export-Clixml -LiteralPath $credentialPath
    & codex mcp add $serverName -- $pythonExe (Join-Path $PSScriptRoot 'actions_stdio.py') --credential-file $credentialPath --container $Container
    if ($LASTEXITCODE -ne 0) { throw 'MCP registration failed.' }
    Write-Output "Action sync MCP configured. Token ID: $($registration.token_id); expires: $($registration.expires_at). Credential is protected with Windows DPAPI."
} catch {
    & docker exec -u www-data -e APP_RUNTIME_DIR=/tmp/jobradar-action-sync-admin -e "JOBRADAR_ACTION_SYNC_OWNER_ID=$OwnerId" $Container php bin/action-sync-admin.php revoke $registration.token_id
    throw 'Installation failed; the issued token was revoked.'
} finally {
    $registrationText = $null
    $registration = $null
}
