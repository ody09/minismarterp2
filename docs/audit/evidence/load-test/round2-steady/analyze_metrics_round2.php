<?php

$k6SummaryFile = __DIR__.'/k6_summary_round2.json';
$systemMetricsFile = __DIR__.'/system_metrics_v2.csv';
$rowCountsPreFile = __DIR__.'/row_counts_round2_pre.json';
$rowCountsPostFile = __DIR__.'/row_counts_round2_post.json';
$mysqlStatusPreFile = __DIR__.'/mysql_status_pre.json';
$mysqlStatusPostFile = __DIR__.'/mysql_status_post.json';

$k6 = json_decode(file_get_contents($k6SummaryFile), true);
$m = $k6['metrics'];

// Parse CSV system metrics
$lines = file($systemMetricsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$header = str_getcsv(array_shift($lines), ',', '"', '\\');

$metricsByPhase = [];
$totalRows = 0;

foreach ($lines as $line) {
    $row = array_combine($header, str_getcsv($line, ',', '"', '\\'));
    $phase = $row['Phase'];
    if (! isset($metricsByPhase[$phase])) {
        $metricsByPhase[$phase] = [
            'count' => 0,
            'TotalCpuPercent' => [],
            'AvailableMemMB' => [],
            'MysqlCpu' => [],
            'MysqlMemMB' => [],
            'PhpCgiCpu' => [],
            'PhpCgiMemMB' => [],
            'NginxCpu' => [],
            'NginxMemMB' => [],
            'K6Cpu' => [],
            'K6MemMB' => [],
        ];
    }
    $metricsByPhase[$phase]['count']++;
    foreach (['TotalCpuPercent', 'AvailableMemMB', 'MysqlCpu', 'MysqlMemMB', 'PhpCgiCpu', 'PhpCgiMemMB', 'NginxCpu', 'NginxMemMB', 'K6Cpu', 'K6MemMB'] as $col) {
        $metricsByPhase[$phase][$col][] = (float) $row[$col];
    }
}

function stats(array $arr)
{
    if (empty($arr)) {
        return ['avg' => 0, 'min' => 0, 'max' => 0, 'p95' => 0];
    }
    sort($arr);
    $n = count($arr);
    $avg = array_sum($arr) / $n;
    $min = $arr[0];
    $max = $arr[$n - 1];
    $p95Index = (int) floor($n * 0.95);
    $p95 = $arr[min($p95Index, $n - 1)];

    return [
        'avg' => round($avg, 2),
        'min' => round($min, 2),
        'max' => round($max, 2),
        'p95' => round($p95, 2),
    ];
}

$systemSummary = [];
foreach ($metricsByPhase as $phase => $data) {
    $systemSummary[$phase] = [
        'samples' => $data['count'],
        'duration_sec' => $data['count'] * 5,
        'TotalCpu' => stats($data['TotalCpuPercent']),
        'AvailableMemMB' => stats($data['AvailableMemMB']),
        'MysqlCpu' => stats($data['MysqlCpu']),
        'MysqlMemMB' => stats($data['MysqlMemMB']),
        'PhpCgiCpu' => stats($data['PhpCgiCpu']),
        'PhpCgiMemMB' => stats($data['PhpCgiMemMB']),
        'NginxCpu' => stats($data['NginxCpu']),
        'NginxMemMB' => stats($data['NginxMemMB']),
        'K6Cpu' => stats($data['K6Cpu']),
        'K6MemMB' => stats($data['K6MemMB']),
    ];
}

function extractMetric($m, $key)
{
    if (! isset($m[$key])) {
        return null;
    }
    $val = $m[$key];
    if (isset($val['values'])) {
        $v = $val['values'];

        return [
            'avg_ms' => isset($v['avg']) ? round($v['avg'], 2) : null,
            'min_ms' => isset($v['min']) ? round($v['min'], 2) : null,
            'med_ms' => isset($v['med']) ? round($v['med'], 2) : null,
            'p90_ms' => isset($v['p(90)']) ? round($v['p(90)'], 2) : null,
            'p95_ms' => isset($v['p(95)']) ? round($v['p(95)'], 2) : null,
            'max_ms' => isset($v['max']) ? round($v['max'], 2) : null,
            'count' => $v['count'] ?? null,
            'rate' => isset($v['rate']) ? round($v['rate'], 4) : null,
        ];
    }

    return $val;
}

$phasesK6 = [
    'warmup_2vu' => extractMetric($m, 'erp_duration_steady_warmup'),
    'steady_5vu' => extractMetric($m, 'erp_duration_steady_5vu'),
    'steady_10vu' => extractMetric($m, 'erp_duration_steady_10vu'),
    'steady_20vu' => extractMetric($m, 'erp_duration_steady_20vu'),
    'overall' => extractMetric($m, 'erp_duration_overall'),
];

$operations20VU = [
    'dashboard' => extractMetric($m, 'erp_20vu_dashboard'),
    'products_known' => extractMetric($m, 'erp_20vu_products_known'),
    'products_empty' => extractMetric($m, 'erp_20vu_products_empty'),
    'batches' => extractMetric($m, 'erp_20vu_batches'),
    'inventory' => extractMetric($m, 'erp_20vu_inventory'),
    'inventory_tx' => extractMetric($m, 'erp_20vu_inventory_tx'),
    'purchases_index' => extractMetric($m, 'erp_20vu_purchases'),
    'purchases_filter' => extractMetric($m, 'erp_20vu_purchases_filter'),
    'sales_index' => extractMetric($m, 'erp_20vu_sales'),
    'sales_filter' => extractMetric($m, 'erp_20vu_sales_filter'),
    'report_sales' => extractMetric($m, 'erp_20vu_report_sales'),
    'report_purchases' => extractMetric($m, 'erp_20vu_report_purchases'),
    'report_inventory' => extractMetric($m, 'erp_20vu_report_inventory'),
    'po_write_cycle' => extractMetric($m, 'erp_20vu_po_write_cycle'),
    'so_write_cycle' => extractMetric($m, 'erp_20vu_so_write_cycle'),
];

$rowPre = json_decode(file_get_contents($rowCountsPreFile), true);
$rowPost = json_decode(file_get_contents($rowCountsPostFile), true);
$rowDelta = [];
foreach ($rowPost as $tbl => $cnt) {
    $rowDelta[$tbl] = [
        'pre' => $rowPre[$tbl] ?? 0,
        'post' => $cnt,
        'delta' => $cnt - ($rowPre[$tbl] ?? 0),
    ];
}

$mysqlPre = json_decode(file_get_contents($mysqlStatusPreFile), true);
$mysqlPost = json_decode(file_get_contents($mysqlStatusPostFile), true);
$mysqlDelta = [];
foreach ($mysqlPost['metrics'] as $k => $v) {
    $preVal = (int) ($mysqlPre['metrics'][$k] ?? 0);
    $postVal = (int) $v;
    $mysqlDelta[$k] = [
        'pre' => $preVal,
        'post' => $postVal,
        'delta' => $postVal - $preVal,
    ];
}

$fullSummary = [
    'test_metadata' => [
        'date' => date('Y-m-d H:i:s'),
        'total_duration_sec' => 951.9,
        'total_iterations' => $m['iterations']['count'],
        'total_http_requests' => $m['http_reqs']['count'],
        'http_req_failed_rate' => $m['http_req_failed']['value'] ?? 0,
        'critical_failures' => $m['erp_critical_failures']['count'] ?? 0,
        'success_rate' => $m['erp_rate_success']['value'] ?? 1.0,
        'total_reads' => $m['erp_count_reads']['count'] ?? 0,
        'total_writes' => $m['erp_count_writes']['count'] ?? 0,
    ],
    'phases_k6' => $phasesK6,
    'operations_20vu' => $operations20VU,
    'system_metrics' => $systemSummary,
    'row_counts' => $rowDelta,
    'mysql_status_delta' => $mysqlDelta,
];

file_put_contents(__DIR__.'/round2_metrics_summary.json', json_encode($fullSummary, JSON_PRETTY_PRINT));
echo "Successfully generated round2_metrics_summary.json\n";
