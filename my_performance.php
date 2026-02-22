<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/scoring.php';
require_once __DIR__ . '/lib/performance_sections.php';
require_once __DIR__ . '/lib/course_recommendations.php';
auth_required(['staff','supervisor','admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$user = current_user();
$userWorkFunctionLabel = work_function_label($pdo, (string)($user['work_function'] ?? ''));
ensure_course_recommendation_schema($pdo);

$hasPeriodStartColumn = false;
try {
    $periodColumnsStmt = $pdo->query('SHOW COLUMNS FROM performance_period');
    $periodColumns = $periodColumnsStmt ? $periodColumnsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($periodColumns as $periodColumn) {
        if (strcasecmp((string)($periodColumn['Field'] ?? ''), 'period_start') === 0) {
            $hasPeriodStartColumn = true;
            break;
        }
    }
} catch (Throwable $periodSchemaError) {
    error_log('my_performance performance_period schema lookup failed: ' . $periodSchemaError->getMessage());
}

$periodStartSelect = $hasPeriodStartColumn ? 'pp.period_start' : 'NULL AS period_start';

function resolve_timeline_label(array $row): string
{
    $periodStart = $row['period_start'] ?? null;
    if ($periodStart) {
        $startTime = strtotime((string)$periodStart);
        if ($startTime) {
            return date('Y', $startTime);
        }
    }

    $periodLabel = trim((string)($row['period_label'] ?? ''));
    if ($periodLabel !== '') {
        if (preg_match('/(20\d{2}|19\d{2})/u', $periodLabel, $matches)) {
            return $matches[1];
        }

        return $periodLabel;
    }

    $createdAt = (string)($row['created_at'] ?? '');
    $createdTime = strtotime($createdAt);
    if ($createdTime) {
        return date('Y', $createdTime);
    }

    return $createdAt;
}

$stmt = $pdo->prepare(
    "SELECT qr.id, qr.questionnaire_id, qr.performance_period_id, qr.status, qr.score, qr.created_at, " .
    "q.title, COALESCE(pp.label, '') AS period_label, {$periodStartSelect} " .
    "FROM questionnaire_response qr " .
    "JOIN questionnaire q ON q.id = qr.questionnaire_id " .
    "LEFT JOIN performance_period pp ON pp.id = qr.performance_period_id " .
    "WHERE qr.user_id = ? AND (qr.status IS NULL OR qr.status <> 'draft') ORDER BY qr.created_at ASC, qr.id ASC"
);
$stmt->execute([$user['id']]);

$draftStmt = $pdo->prepare(
    "SELECT qr.questionnaire_id, qr.performance_period_id, q.title, COALESCE(pp.label, '') AS period_label " .
    "FROM questionnaire_response qr " .
    "JOIN questionnaire q ON q.id = qr.questionnaire_id " .
    "LEFT JOIN performance_period pp ON pp.id = qr.performance_period_id " .
    "WHERE qr.user_id = ? AND qr.status = 'draft' ORDER BY qr.created_at DESC, qr.id DESC"
);
$draftStmt->execute([$user['id']]);
$draftResponses = $draftStmt->fetchAll(PDO::FETCH_ASSOC);

$responses = [];
$latestScores = [];
$latestEntry = null;
$belowThreshold = [];
$chartLabels = [];
$chartScores = [];
$timelinePoints = [];

while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
    $responses[] = $row;

    $latestScores[$row['questionnaire_id']] = $row;
    $latestEntry = $row;

    if (isset($row['score']) && $row['score'] !== null && (int)$row['score'] < 100) {
        $belowThreshold[] = $row;
    }

    $chartLabels[] = resolve_timeline_label($row);
    $chartScores[] = $row['score'] !== null ? (int)$row['score'] : null;

    $createdAtRaw = (string)($row['created_at'] ?? '');
    $createdIso = null;
    if ($createdAtRaw !== '') {
        $dtCreated = DateTime::createFromFormat('Y-m-d H:i:s', $createdAtRaw);
        if ($dtCreated instanceof DateTime) {
            $createdIso = $dtCreated->format(DateTime::ATOM);
        }
    }
    $timelinePoints[] = [
        'label' => resolve_timeline_label($row),
        'score' => $row['score'] !== null ? (float)$row['score'] : null,
        'timestamp' => $createdAtRaw,
        'timestamp_iso' => $createdIso,
        'questionnaire' => (string)($row['title'] ?? ''),
        'period' => (string)($row['period_label'] ?? ''),
        'order' => count($timelinePoints),
    ];
}
$nextAssessmentRaw = $user['next_assessment_date'] ?? null;
$nextAssessmentDisplay = null;
if ($nextAssessmentRaw) {
    $dt = DateTime::createFromFormat('Y-m-d', (string)$nextAssessmentRaw);
    if ($dt instanceof DateTime) {
        $nextAssessmentDisplay = $dt->format('F j, Y');
    } else {
        $nextAssessmentDisplay = $nextAssessmentRaw;
    }
}

$sectionBreakdowns = compute_section_breakdowns($pdo, array_values($latestScores), $t);
$chartDataFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
if (defined('JSON_THROW_ON_ERROR')) {
    $chartDataFlags |= JSON_THROW_ON_ERROR;
}

$timelinePoints = array_values($timelinePoints);
usort($timelinePoints, static function ($a, $b) {
    $timeA = isset($a['timestamp_iso']) ? strtotime((string)$a['timestamp_iso']) : false;
    $timeB = isset($b['timestamp_iso']) ? strtotime((string)$b['timestamp_iso']) : false;
    if ($timeA && $timeB && $timeA !== $timeB) {
        return $timeA <=> $timeB;
    }
    if ($timeA && !$timeB) {
        return -1;
    }
    if (!$timeA && $timeB) {
        return 1;
    }
    return ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0));
});
$timelinePoints = array_map(static function ($point) {
    unset($point['order']);
    return $point;
}, $timelinePoints);

$recommendedCourses = [];
if (!empty($user['work_function'])) {
    $courseStmt = $pdo->prepare('SELECT * FROM course_catalogue WHERE recommended_for=? AND min_score <= ? AND max_score >= ? AND (questionnaire_id = ? OR questionnaire_id IS NULL) ORDER BY questionnaire_id IS NULL ASC, min_score ASC');
    foreach ($latestScores as $scoreRow) {
        if ($scoreRow['score'] === null) {
            continue;
        }
        $score = (int)$scoreRow['score'];
        $courseStmt->execute([$user['work_function'], $score, $score, (int)($scoreRow['questionnaire_id'] ?? 0)]);
        foreach ($courseStmt->fetchAll() as $course) {
            $recommendedCourses[$course['id']] = $course;
        }
    }
}
$recommendedCourses = array_values($recommendedCourses);
$statusLabels = [
    'draft' => t($t, 'status_draft', 'Draft'),
    'submitted' => t($t, 'status_submitted', 'Submitted'),
    'approved' => t($t, 'status_approved', 'Approved'),
    'rejected' => t($t, 'status_rejected', 'Rejected'),
];

$flash = $_GET['msg'] ?? '';
$flashMessage = '';
if ($flash === 'submitted') {
    $flashMessage = t($t, 'submission_success', 'Assessment submitted successfully.');
}
$pageHelpKey = 'workspace.my_performance';
?>
<!doctype html><html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>"><head>
<meta charset="utf-8"><title><?=htmlspecialchars(t($t,'my_performance','My Performance'), ENT_QUOTES, 'UTF-8')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<link rel="manifest" href="<?=asset_url('manifest.php')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head><body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/templates/header.php'; ?>
<section class="md-section">
  <?php if ($flashMessage): ?><div class="md-alert success"><?=htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
  <div class="md-card md-elev-2">
    <div class="md-card-title-row">
      <h2 class="md-card-title"><?=t($t,'performance_overview','Performance Overview')?></h2>
      <a
        class="md-button md-outline md-card-action"
        href="<?=htmlspecialchars(url_for('my_performance_download.php'), ENT_QUOTES, 'UTF-8')?>"
      >
        <?=t($t,'download_performance_pdf','Download PDF')?>
      </a>
    </div>
    <p><?=t($t,'current_work_function','Current work function:')?> <?=htmlspecialchars($userWorkFunctionLabel !== '' ? $userWorkFunctionLabel : (string)($user['work_function'] ?? ''), ENT_QUOTES, 'UTF-8')?></p>
    <?php if ($latestEntry): ?>
      <p><?=t($t,'latest_submission','Latest submission:')?> <?=htmlspecialchars($latestEntry['period_label'])?> · <?=htmlspecialchars($latestEntry['title'])?> (<?= is_null($latestEntry['score']) ? '-' : (int)$latestEntry['score'] ?>%)</p>
    <?php else: ?>
      <p><?=t($t,'no_submissions_yet','No submissions recorded yet. Complete your first assessment to see insights.')?></p>
    <?php endif; ?>
    <?php if ($nextAssessmentDisplay): ?>
      <p><?=t($t,'next_assessment_scheduled','Next assessment scheduled:')?> <?=htmlspecialchars($nextAssessmentDisplay)?></p>
    <?php else: ?>
      <p class="md-muted"><?=t($t,'next_assessment_not_set','Your next assessment date has not been scheduled yet.')?></p>
    <?php endif; ?>
    <?php if ($draftResponses): ?>
      <div class="md-alert warning md-draft-alert">
        <strong><?=t($t,'draft_pending_title','Saved drafts awaiting submission')?>:</strong>
        <ul class="md-draft-list">
          <?php foreach ($draftResponses as $draft): ?>
            <li>
              <a href="<?=htmlspecialchars(url_for('submit_assessment.php?qid=' . $draft['questionnaire_id'] . '&performance_period_id=' . $draft['performance_period_id']), ENT_QUOTES, 'UTF-8')?>">
                <?=htmlspecialchars($draft['title'])?> · <?=htmlspecialchars($draft['period_label'])?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
  <?php if ($sectionBreakdowns): ?>
    <div class="md-card md-elev-2">
      <h2 class="md-card-title"><?=t($t,'section_breakdown','Section score radar')?></h2>
      <p><?=t($t,'section_breakdown_hint','Each radar shows how your latest submission performed across questionnaire sections.')?></p>
      <div class="md-radar-grid">
        <?php foreach ($sectionBreakdowns as $qid => $radar): ?>
          <div class="md-radar-card">
            <h3 class="md-radar-title"><?=htmlspecialchars($radar['title'], ENT_QUOTES, 'UTF-8')?></h3>
            <?php if (!empty($radar['period'])): ?>
              <p class="md-radar-meta"><?=htmlspecialchars($radar['period'], ENT_QUOTES, 'UTF-8')?></p>
            <?php endif; ?>
            <div class="md-radar-canvas">
              <canvas id="radar-chart-<?=$qid?>" role="img" aria-label="<?=htmlspecialchars(sprintf(t($t,'section_score_chart_alt','Section scores for %s'), $radar['title']), ENT_QUOTES, 'UTF-8')?>"></canvas>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'your_trend','Your Score Trend')?></h2>
  <?php if ($chartLabels): ?>
      <div class="trend-chart-wrap">
        <canvas
          id="performance-timeline-chart"
          role="img"
          aria-label="<?=htmlspecialchars(t($t,'performance_timeline_alt','Line chart showing your performance timeline'), ENT_QUOTES, 'UTF-8')?>"
        ></canvas>
      </div>
  <?php else: ?>
      <p><?=t($t,'no_trend_data','Submit assessments to generate your performance trend.')?></p>
    <?php endif; ?>
    <table class="md-table">
      <thead><tr><th><?=t($t,'date','Date')?></th><th><?=t($t,'questionnaire','Questionnaire')?></th><th><?=t($t,'performance_period','Performance Period')?></th><th><?=t($t,'score','Score (%)')?></th><th><?=t($t,'status','Status')?></th><th><?=t($t,'actions','Actions')?></th></tr></thead>
      <tbody>
      <?php foreach ($responses as $r): ?>
        <?php
          $statusKey = $r['status'] ?? 'submitted';
          $statusLabel = $statusLabels[$statusKey] ?? ucfirst($statusKey);
        ?>
        <tr>
          <td><?=htmlspecialchars($r['created_at'])?></td>
          <td><?=htmlspecialchars($r['title'])?></td>
          <td><?=htmlspecialchars($r['period_label'])?></td>
          <td><?= is_null($r['score']) ? '-' : (int)$r['score']?></td>
          <td><?=htmlspecialchars($statusLabel)?></td>
          <td><span class="md-muted">—</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'training_focus','Training Focus Areas')?></h2>
    <?php if ($belowThreshold): ?>
      <table class="md-table">
        <thead><tr><th><?=t($t,'questionnaire','Questionnaire')?></th><th><?=t($t,'performance_period','Period')?></th><th><?=t($t,'score','Score (%)')?></th></tr></thead>
        <tbody>
        <?php foreach ($belowThreshold as $item): ?>
          <tr>
            <td><?=htmlspecialchars($item['title'])?></td>
            <td><?=htmlspecialchars($item['period_label'])?></td>
            <td><?= (int)$item['score']?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p><?=t($t,'no_training_gaps','All recorded submissions achieved full marks. Great job!')?></p>
    <?php endif; ?>
  </div>
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'recommended_courses','Recommended Courses')?></h2>
    <?php if ($recommendedCourses): ?>
      <table class="md-table">
        <thead><tr><th><?=t($t,'course','Course')?></th><th><?=t($t,'link','Link')?></th><th><?=t($t,'score_band','Score Band')?></th></tr></thead>
        <tbody>
        <?php foreach ($recommendedCourses as $course): ?>
          <tr>
            <td><?=htmlspecialchars($course['title'])?></td>
            <td><a href="<?=htmlspecialchars($course['moodle_url'])?>" target="_blank" rel="noopener">Moodle</a></td>
            <td><?= (int)$course['min_score'] ?> - <?= (int)$course['max_score'] ?>%</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p><?=t($t,'no_courses_available','No targeted courses found for your current scores. Please contact your supervisor for tailored learning paths.')?></p>
    <?php endif; ?>
  </div>
</section>
<?php $hasChartJs = !empty($chartLabels) || !empty($sectionBreakdowns); ?>
<?php if ($hasChartJs): ?>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
  (function () {
    const timelineData = <?=json_encode([
      'labels' => $chartLabels,
      'scores' => array_map(static fn($score) => $score === null ? null : (float)$score, $chartScores),
      'points' => $timelinePoints,
    ], $chartDataFlags)?>;
    const radarData = <?=json_encode($sectionBreakdowns, $chartDataFlags)?>;
    const rootStyles = getComputedStyle(document.documentElement);

    const cssVar = (name, fallback) => {
      const value = rootStyles.getPropertyValue(name);
      if (value && value.trim()) {
        return value.trim();
      }
      if (fallback) {
        const fallbackValue = rootStyles.getPropertyValue(fallback);
        if (fallbackValue && fallbackValue.trim()) {
          return fallbackValue.trim();
        }
      }
      return '';
    };

    const radarPalette = [
      { bg: cssVar('--app-primary-soft'), border: cssVar('--app-primary') },
      { bg: cssVar('--status-warning-soft'), border: cssVar('--status-warning') },
      { bg: cssVar('--status-success-soft'), border: cssVar('--status-success') },
      { bg: cssVar('--status-info-soft'), border: cssVar('--status-info', '--app-secondary') }
    ].filter((entry) => entry.bg && entry.border);
    if (!radarPalette.length) {
      radarPalette.push({ bg: cssVar('--app-primary-soft'), border: cssVar('--app-primary') });
    }

    const heatStops = [
      { stop: 0, color: [211, 47, 47] },
      { stop: 0.5, color: [249, 168, 37] },
      { stop: 1, color: [46, 125, 50] }
    ];

    const clamp = (value, min, max) => Math.min(Math.max(value, min), max);
    const mix = (start, end, ratio) => Math.round(start + (end - start) * ratio);

    const prepareChartLibrary = (chartLib) => {
      if (!chartLib) {
        return null;
      }
      if (chartLib.register && Array.isArray(chartLib.registerables) && chartLib.registerables.length) {
        try {
          chartLib.register(...chartLib.registerables);
        } catch (err) {
          // Ignore duplicate registration errors.
        }
      }
      return chartLib;
    };

    const parseMajorVersion = (chartLib) => {
      if (!chartLib || !chartLib.version) {
        return 0;
      }
      const parts = String(chartLib.version).split('.');
      const major = parseInt(parts[0], 10);
      return Number.isNaN(major) ? 0 : major;
    };

    function heatColor(score, alpha = 0.85) {
      if (typeof score !== 'number' || Number.isNaN(score)) {
        score = 0;
      }
      const normalized = clamp(score / 100, 0, 1);
      let left = heatStops[0];
      let right = heatStops[heatStops.length - 1];
      for (let i = 0; i < heatStops.length - 1; i += 1) {
        const current = heatStops[i];
        const next = heatStops[i + 1];
        if (normalized >= current.stop && normalized <= next.stop) {
          left = current;
          right = next;
          break;
        }
      }
      const range = right.stop - left.stop || 1;
      const ratio = clamp((normalized - left.stop) / range, 0, 1);
      const r = mix(left.color[0], right.color[0], ratio);
      const g = mix(left.color[1], right.color[1], ratio);
      const b = mix(left.color[2], right.color[2], ratio);
      return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }

    function resolveSegmentScore(context, scores) {
      const forward = scores[context.p1DataIndex];
      if (typeof forward === 'number') {
        return forward;
      }
      const backward = scores[context.p0DataIndex];
      if (typeof backward === 'number') {
        return backward;
      }
      return 0;
    }

    function renderTimeline(chartLib) {
      const rawPoints = Array.isArray(timelineData.points) ? timelineData.points : [];
      let chronological = rawPoints.map((point, index) => {
        const isoCandidate = typeof point.timestamp_iso === 'string' && point.timestamp_iso
          ? point.timestamp_iso
          : (typeof point.timestamp === 'string' ? point.timestamp.replace(' ', 'T') : '');
        const parsedTime = isoCandidate ? Date.parse(isoCandidate) : Number.NaN;
        const rawScore = point.score;
        const numericScore = typeof rawScore === 'number'
          ? rawScore
          : (typeof rawScore === 'string' ? Number.parseFloat(rawScore) : Number.NaN);
        return {
          label: point.label || '',
          score: Number.isFinite(numericScore) ? numericScore : null,
          dateValue: Number.isFinite(parsedTime) ? parsedTime : null,
          period: point.period || '',
          questionnaire: point.questionnaire || '',
          timestamp: point.timestamp || '',
          index,
        };
      });

      if (!chronological.length) {
        const fallbackLabels = Array.isArray(timelineData.labels) ? timelineData.labels : [];
        const fallbackScores = Array.isArray(timelineData.scores) ? timelineData.scores : [];
        chronological = fallbackLabels.map((label, index) => {
          const rawScore = fallbackScores[index];
          const numericScore = typeof rawScore === 'number'
            ? rawScore
            : (typeof rawScore === 'string' ? Number.parseFloat(rawScore) : Number.NaN);
          return {
            label: label || '',
            score: Number.isFinite(numericScore) ? numericScore : null,
            dateValue: index,
            period: '',
            questionnaire: '',
            timestamp: '',
            index,
          };
        });
      }

      if (!chronological.length) {
        return;
      }

      chronological.sort((a, b) => {
        if (a.dateValue !== null && b.dateValue !== null && a.dateValue !== b.dateValue) {
          return a.dateValue - b.dateValue;
        }
        if (a.dateValue !== null && b.dateValue === null) {
          return -1;
        }
        if (a.dateValue === null && b.dateValue !== null) {
          return 1;
        }
        return a.index - b.index;
      });

      const labels = chronological.map((point) => {
        if (point.label) {
          return point.label;
        }
        if (point.timestamp) {
          return point.timestamp;
        }
        return '';
      });
      const scores = chronological.map((point) => point.score);

      if (!labels.length) {
        return;
      }

      const canvas = document.getElementById('performance-timeline-chart');
      if (!canvas) {
        return;
      }
      const context = canvas.getContext('2d');
      if (!context) {
        return;
      }

      const neutralFill = 'rgba(148, 163, 184, 0.25)';
      const neutralStroke = 'rgba(148, 163, 184, 0.55)';
      const barBackground = scores.map((score) => (typeof score === 'number' ? heatColor(score, 0.75) : neutralFill));
      const barBorders = scores.map((score) => (typeof score === 'number' ? heatColor(score, 0.95) : neutralStroke));

      const dataset = {
        data: scores,
        backgroundColor: barBackground,
        borderColor: barBorders,
        borderWidth: 1.5,
      };

      const major = parseMajorVersion(chartLib);
      const isModern = major >= 3;
      if (isModern) {
        dataset.borderRadius = 6;
      }

      const tooltipFormatter = (index, labelText) => {
        const point = chronological[index] || {};
        const valueText = typeof point.score === 'number' ? `${point.score.toFixed(1)}%` : '—';
        const metaParts = [];
        if (point.period) {
          metaParts.push(point.period);
        }
        if (point.questionnaire) {
          metaParts.push(point.questionnaire);
        }
        const meta = metaParts.length ? ` (${metaParts.join(' · ')})` : '';
        return `${labelText}: ${valueText}${meta}`;
      };

      const gridColor = cssVar('--app-border', '--brand-border') || 'rgba(17, 56, 94, 0.08)';
      const yAxisLabel = <?=json_encode(t($t,'score','Score (%)'), $chartDataFlags)?>;
      const xAxisLabel = <?=json_encode(t($t,'performance_period','Performance Period'), $chartDataFlags)?>;

      let chartConfig;
      if (isModern) {
        chartConfig = {
          type: 'bar',
          data: {
            labels,
            datasets: [dataset],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'x',
            interaction: { intersect: false, mode: 'nearest' },
            plugins: {
              legend: { display: false },
              tooltip: {
                callbacks: {
                  label: (context) => tooltipFormatter(context.dataIndex, context.label || labels[context.dataIndex] || ''),
                },
              },
            },
            scales: {
              y: {
                beginAtZero: true,
                max: 100,
                ticks: {
                  callback: (value) => `${value}%`,
                },
                title: { display: true, text: yAxisLabel },
                grid: { color: gridColor },
              },
              x: {
                ticks: { maxRotation: 45, minRotation: 0, autoSkip: false },
                title: { display: true, text: xAxisLabel },
                grid: { display: false },
                reverse: false,
              },
            },
          },
        };
      } else {
        chartConfig = {
          type: 'bar',
          data: {
            labels,
            datasets: [dataset],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            legend: { display: false },
            tooltips: {
              callbacks: {
                label: (tooltipItem) => tooltipFormatter(tooltipItem.index, tooltipItem.label || labels[tooltipItem.index] || ''),
              },
              mode: 'nearest',
              intersect: false,
            },
            scales: {
              yAxes: [{
                ticks: {
                  beginAtZero: true,
                  max: 100,
                  callback: (value) => `${value}%`,
                },
                gridLines: { color: gridColor },
                scaleLabel: { display: true, labelString: yAxisLabel },
              }],
              xAxes: [{
                ticks: { autoSkip: false, maxRotation: 45, minRotation: 0, reverse: false },
                gridLines: { display: false },
                scaleLabel: { display: true, labelString: xAxisLabel },
              }],
            },
          },
        };
      }

      new chartLib(canvas, chartConfig);
    }

    function renderRadars(chartLib) {
      if (!radarData) {
        return;
      }
      let paletteIndex = 0;
      const major = parseMajorVersion(chartLib);
      const isModern = major >= 3;

      Object.keys(radarData).forEach((qid) => {
        const canvas = document.getElementById(`radar-chart-${qid}`);
        if (!canvas) {
          return;
        }
        const dataset = radarData[qid];
        if (!dataset || !Array.isArray(dataset.sections) || !dataset.sections.length) {
          return;
        }
        const labels = dataset.sections.map((section) => section.label);
        const values = dataset.sections.map((section) => Number(section.score) || 0);
        const colors = radarPalette[paletteIndex % radarPalette.length];
        paletteIndex += 1;

        const radarOptions = {
          responsive: true,
          maintainAspectRatio: false,
        };

        if (isModern) {
          radarOptions.plugins = {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: (context) => {
                  const raw = context.parsed && typeof context.parsed.r === 'number' ? context.parsed.r : context.parsed;
                  const rounded = typeof raw === 'number' ? raw.toFixed(1) : raw;
                  return `${context.label}: ${rounded}%`;
                },
              },
            },
          };
          radarOptions.scales = {
            r: {
              suggestedMin: 0,
              suggestedMax: 100,
              ticks: {
                stepSize: 20,
                showLabelBackdrop: false,
                callback: (value) => `${value}%`,
              },
              grid: { color: 'rgba(32, 115, 191, 0.15)' },
              angleLines: { color: 'rgba(32, 115, 191, 0.2)' },
            },
          };
        } else {
          radarOptions.legend = { display: false };
          radarOptions.tooltips = {
            callbacks: {
              label: (tooltipItem) => {
                const value = typeof tooltipItem.yLabel === 'number' ? tooltipItem.yLabel.toFixed(1) : tooltipItem.yLabel;
                const label = tooltipItem.label || '';
                return `${label}: ${value}%`;
              },
            },
          };
          radarOptions.scale = {
            ticks: {
              beginAtZero: true,
              min: 0,
              max: 100,
              stepSize: 20,
              showLabelBackdrop: false,
              callback: (value) => `${value}%`,
            },
            gridLines: { color: 'rgba(32, 115, 191, 0.15)' },
            angleLines: { color: 'rgba(32, 115, 191, 0.2)' },
          };
        }

        new chartLib(canvas, {
          type: 'radar',
          data: {
            labels,
            datasets: [{
              label: dataset.title,
              data: values,
              fill: true,
              backgroundColor: colors.bg,
              borderColor: colors.border,
              borderWidth: 2,
              pointBackgroundColor: colors.border,
              pointBorderColor: cssVar('--app-surface', '--brand-bg'),
              pointRadius: 4,
              pointHoverRadius: 5,
            }],
          },
          options: radarOptions,
        });
      });
    }

    document.addEventListener('DOMContentLoaded', () => {
      const chartLib = prepareChartLibrary(window.Chart || null);
      if (!chartLib) {
        return;
      }
      renderTimeline(chartLib);
      renderRadars(chartLib);
    });
  })();
</script>
<?php endif; ?>
<?php include __DIR__.'/templates/footer.php'; ?>
</body></html>
