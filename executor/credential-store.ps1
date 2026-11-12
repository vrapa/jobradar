function Protect-JobRadarCredentialDirectory([string]$Directory) {
    New-Item -ItemType Directory -Force -Path $Directory | Out-Null
    $credentialSid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    & icacls $Directory /inheritance:r /grant:r "*$($credentialSid):(OI)(CI)F" '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not protect the credential directory.' }
}
