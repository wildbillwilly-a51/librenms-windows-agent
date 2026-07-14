[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$ComputerName,

    [int]$Port = 6556,
    [int]$TimeoutSeconds = 10,
    [string[]]$RequireSection = @('windows_agent', 'local')
)

$ErrorActionPreference = 'Stop'

$client = New-Object Net.Sockets.TcpClient
$async = $client.BeginConnect($ComputerName, $Port, $null, $null)
if (-not $async.AsyncWaitHandle.WaitOne([TimeSpan]::FromSeconds($TimeoutSeconds))) {
    $client.Close()
    throw "Timed out connecting to ${ComputerName}:$Port"
}

$client.EndConnect($async)
$client.ReceiveTimeout = $TimeoutSeconds * 1000
$stream = $client.GetStream()
$reader = New-Object IO.StreamReader($stream, [Text.Encoding]::UTF8)
$output = $reader.ReadToEnd()
$reader.Dispose()
$client.Dispose()

foreach ($section in $RequireSection) {
    $header = "<<<$section>>>"
    if ($output -notlike "*$header*") {
        throw "Missing required section $header"
    }
}

$output
