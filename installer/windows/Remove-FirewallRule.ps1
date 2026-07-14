[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

Get-NetFirewallRule -DisplayName 'LibreNMS Windows Agent TCP 6556' -ErrorAction SilentlyContinue |
    Remove-NetFirewallRule
