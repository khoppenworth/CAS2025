<?php
require_once __DIR__ . '/../config.php';
auth_required(['staff', 'supervisor', 'admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$user = current_user();
$brandPalette = site_brand_palette($cfg);
$chartPalette = site_chart_palette($cfg);
$chartDefaults = [
    'background' => tint_color($brandPalette['primary'], 0.92),
    'text' => shade_color($brandPalette['primary'], 0.6),
];
$chartColor = static function (array $palette, string $key, string $fallback) {
    try {
        return color_components($palette[$key] ?? $fallback);
    } catch (\Throwable $exception) {
        return color_components($fallback);
    }
};
$targetUserId = (int) ($user['id'] ?? 0);

if ($user['role'] === 'admin' && isset($_GET['user'])) {
    $candidate = (int) $_GET['user'];
    if ($candidate > 0) {
        $targetUserId = $candidate;
    }
}

if ($targetUserId <= 0) {
    http_response_code(400);
    header('Content-Type: image/png');
    $img = imagecreatetruecolor(640, 200);
    [$bgR, $bgG, $bgB] = $chartColor($chartPalette, 'margin', $chartDefaults['background']);
    $bg = imagecolorallocate($img, $bgR, $bgG, $bgB);
    imagefilledrectangle($img, 0, 0, 640, 200, $bg);
    [$textR, $textG, $textB] = $chartColor($chartPalette, 'labels', $chartDefaults['text']);
    $text = imagecolorallocate($img, $textR, $textG, $textB);
    $message = t($t, 'no_user_selected', 'No user selected');
    imagestring($img, 4, 160, 90, $message, $text);
    imagepng($img);
    imagedestroy($img);
    exit;
}

$stmt = $pdo->prepare("SELECT qr.score, qr.created_at, pp.label AS period_label, pp.period_start
    FROM questionnaire_response qr
    LEFT JOIN performance_period pp ON pp.id = qr.performance_period_id
    WHERE qr.user_id = ?
    ORDER BY qr.created_at ASC");
$stmt->execute([$targetUserId]);
$rows = $stmt->fetchAll();

if (!$rows) {
    header('Content-Type: image/png');
    $img = imagecreatetruecolor(640, 220);
    [$bgR, $bgG, $bgB] = $chartColor($chartPalette, 'margin', $chartDefaults['background']);
    $bg = imagecolorallocate($img, $bgR, $bgG, $bgB);
    imagefilledrectangle($img, 0, 0, 640, 220, $bg);
    [$textR, $textG, $textB] = $chartColor($chartPalette, 'labels', $chartDefaults['text']);
    $textColor = imagecolorallocate($img, $textR, $textG, $textB);
    $message = t($t, 'no_trend_data', 'Submit assessments to generate your performance trend.');
    $wrapped = [];
    $words = preg_split('/\s+/', $message) ?: [];
    $line = '';
    foreach ($words as $word) {
        $next = $line === '' ? $word : $line . ' ' . $word;
        if (strlen($next) > 38) {
            $wrapped[] = $line;
            $line = $word;
        } else {
            $line = $next;
        }
    }
    if ($line !== '') {
        $wrapped[] = $line;
    }
    if (!$wrapped) {
        $wrapped = [$message];
    }
    $startY = (int) (110 - (count($wrapped) * 7));
    foreach ($wrapped as $offset => $lineText) {
        $textWidth = imagefontwidth(4) * strlen($lineText);
        $x = (int) ((640 - $textWidth) / 2);
        imagestring($img, 4, max(20, $x), $startY + $offset * 18, $lineText, $textColor);
    }
    imagepng($img);
    imagedestroy($img);
    exit;
}

require_once __DIR__ . '/../lib/jpgraph-lite.php';

$labels = [];
$dataPoints = [];
foreach ($rows as $row) {
    $periodYear = '';
    if (!empty($row['period_start'])) {
        $periodTime = strtotime((string) $row['period_start']);
        if ($periodTime) {
            $periodYear = date('Y', $periodTime);
        }
    }
    if ($periodYear === '' && !empty($row['period_label'])) {
        $candidate = trim((string) $row['period_label']);
        if (preg_match('/(20\d{2}|19\d{2})/u', $candidate, $match)) {
            $periodYear = $match[1];
        } else {
            $periodYear = $candidate;
        }
    }
    if ($periodYear === '') {
        $timestamp = strtotime((string) ($row['created_at'] ?? 'now'));
        $periodYear = $timestamp ? date('Y', $timestamp) : (string) ($row['created_at'] ?? '');
    }
    $labels[] = $periodYear;
    $dataPoints[] = $row['score'] !== null ? (float) $row['score'] : null;
}

try {
    $graph = new \MiniJpGraph\Graph(820, 340);
    $graph->applyTheme($chartPalette);
} catch (\RuntimeException $e) {
    header('Content-Type: image/png');
    $img = imagecreatetruecolor(640, 220);
    [$bgR, $bgG, $bgB] = $chartColor($chartPalette, 'margin', $chartDefaults['background']);
    $bg = imagecolorallocate($img, $bgR, $bgG, $bgB);
    imagefilledrectangle($img, 0, 0, 640, 220, $bg);
    [$textR, $textG, $textB] = $chartColor($chartPalette, 'labels', $chartDefaults['text']);
    $textColor = imagecolorallocate($img, $textR, $textG, $textB);
    imagestring($img, 4, 40, 100, 'Charts unavailable: ' . $e->getMessage(), $textColor);
    imagepng($img);
    imagedestroy($img);
    exit;
}
$graph->SetScale('textlin');
$graph->SetMargin(80, 40, 70, 90);
$graph->SetTitle(t($t, 'performance_timeline_title', 'Performance timeline'));
$graph->xaxis->SetTickLabels($labels);
$graph->xaxis->SetTitle(t($t, 'performance_period', 'Asessment Period'));
$graph->yaxis->SetTitle(t($t, 'score_percentage', 'Score (%)'));

$plot = new \MiniJpGraph\LinePlot($dataPoints);
$plot->SetColor($chartPalette['line']);
$plot->SetWeight(3);
$graph->Add($plot);

header('Content-Type: image/png');
header('Cache-Control: no-store, private, max-age=0');
$graph->Stroke();
exit;
