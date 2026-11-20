$outputFile = Join-Path $PSScriptRoot "system_metrics_v2.csv"
"Timestamp,ElapsedSec,Phase,PlannedVUs,TotalCpuPercent,AvailableMemMB,MysqlCpu,MysqlMemMB,NginxCpu,NginxMemMB,PhpCgiCpu,PhpCgiMemMB,K6Cpu,K6MemMB" | Out-File -FilePath $outputFile -Encoding ascii

$cores = [Environment]::ProcessorCount
$startTime = [DateTime]::UtcNow

function Get-ProcSnapshot($names) {
    $snap = @{}
    foreach ($name in $names) {
        $procs = Get-Process -Name $name -ErrorAction SilentlyContinue
        $cpuSum = 0
        $memSum = 0
        if ($procs) {
            foreach ($p in $procs) {
                try {
                    $cpuSum += $p.CPU
                    $memSum += $p.WorkingSet64
                } catch {}
            }
        }
        $snap[$name] = @{
            CpuSec = $cpuSum
            MemMB = [math]::Round($memSum / 1MB, 1)
        }
    }
    $snap['Timestamp'] = [DateTime]::UtcNow
    return $snap
}

$trackedNames = @('mysqld', 'nginx', 'php-cgi', 'k6')
$prevSnap = Get-ProcSnapshot $trackedNames

while ($true) {
    Start-Sleep -Seconds 5

    $now = [DateTime]::UtcNow
    $elapsedSec = [math]::Round(($now - $startTime).TotalSeconds, 1)

    # Determine exact phase and planned VUs
    $phase = "cooldown"
    $plannedVUs = 0
    if ($elapsedSec -lt 5) {
        $phase = "ramp_warmup"
        $plannedVUs = 2
    } elseif ($elapsedSec -lt 65) {
        $phase = "steady_warmup"
        $plannedVUs = 2
    } elseif ($elapsedSec -lt 75) {
        $phase = "ramp_5vu"
        $plannedVUs = 5
    } elseif ($elapsedSec -lt 195) {
        $phase = "steady_5vu"
        $plannedVUs = 5
    } elseif ($elapsedSec -lt 205) {
        $phase = "ramp_10vu"
        $plannedVUs = 10
    } elseif ($elapsedSec -lt 325) {
        $phase = "steady_10vu"
        $plannedVUs = 10
    } elseif ($elapsedSec -lt 340) {
        $phase = "ramp_20vu"
        $plannedVUs = 20
    } elseif ($elapsedSec -lt 940) {
        $phase = "steady_20vu"
        $plannedVUs = 20
    }

    # OS Metrics
    $os = Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue
    $availMemMB = if ($os) { [math]::Round($os.FreePhysicalMemory / 1024, 1) } else { 0 }

    $counterSample = Get-Counter '\Processor(_Total)\% Processor Time' -ErrorAction SilentlyContinue
    $totalCpu = if ($counterSample) { [math]::Round($counterSample.CounterSamples[0].CookedValue, 1) } else { 0 }

    # Process Metrics
    $curSnap = Get-ProcSnapshot $trackedNames
    $dt = ($curSnap['Timestamp'] - $prevSnap['Timestamp']).TotalSeconds
    if ($dt -le 0) { $dt = 5.0 }

    $pStats = @{}
    foreach ($name in $trackedNames) {
        $dCpu = $curSnap[$name].CpuSec - $prevSnap[$name].CpuSec
        if ($dCpu -lt 0) { $dCpu = 0 }
        $pct = [math]::Round(($dCpu / ($dt * $cores)) * 100, 2)
        $pStats[$name] = @{
            Cpu = $pct
            MemMB = $curSnap[$name].MemMB
        }
    }
    $prevSnap = $curSnap

    $ts = [DateTime]::Now.ToString("yyyy-MM-dd HH:mm:ss")
    "$ts,$elapsedSec,$phase,$plannedVUs,$totalCpu,$availMemMB,$($pStats['mysqld'].Cpu),$($pStats['mysqld'].MemMB),$($pStats['nginx'].Cpu),$($pStats['nginx'].MemMB),$($pStats['php-cgi'].Cpu),$($pStats['php-cgi'].MemMB),$($pStats['k6'].Cpu),$($pStats['k6'].MemMB)" | Out-File -FilePath $outputFile -Append -Encoding ascii
}
