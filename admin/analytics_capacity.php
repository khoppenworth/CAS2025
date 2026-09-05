<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/profile_completion.php';
require_once __DIR__ . '/../lib/analytics_capacity.php';
require_once __DIR__ . '/../lib/work_functions.php';
require_once __DIR__ . '/../lib/department_teams.php';

auth_required(['admin', 'supervisor']);
refresh_current_user($pdo);
cas_require_profile_completion($pdo);

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$currentYear = (int)date('Y');

$questionnaires = [];
$qStmt = $pdo->query("SELECT id, title FROM questionnaire WHERE status <> 'inactive' ORDER BY title ASC");
if ($qStmt) {
    $questionnaires = $qStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$filters = analytics_capacity_normalize_filters([
    'year' => $_GET['year'] ?? $currentYear,
    'questionnaire_id' => $_GET['questionnaire_id'] ?? 0,
    'department' => $_GET['department'] ?? '',
    'team' => $_GET['team'] ?? '',
    'work_function' => $_GET['work_function'] ?? '',
]);

$departmentOptions = department_options($pdo);
$teamCatalog = department_team_catalog($pdo);
$teamOptions = [];
foreach ($teamCatalog as $departmentTeams) {
    foreach ($departmentTeams as $team) {
        $slug = (string)($team['slug'] ?? '');
        if ($slug !== '') {
            $teamOptions[$slug] = (string)($team['label'] ?? $slug);
        }
    }
}
$workFunctionOptions = work_function_choices($pdo);

$filteredRows = analytics_capacity_latest_per_employee(
    analytics_capacity_fetch_response_rows($pdo, $filters, true, true)
);
$capacityRows = analytics_capacity_section_rows($pdo, $filteredRows, $t);
$heatmap = analytics_capacity_department_heatmap($pdo, $filters, $t);
$trend = analytics_capacity_annual_trend($pdo, $filters, 2026, 2028);
$overallAverage = analytics_capacity_average($filteredRows);
$overallAttainment = analytics_capacity_attainment($filteredRows);

$selectedCapacityKey = trim((string)($_GET['capacity'] ?? ''));
if ($selectedCapacityKey === '' && $capacityRows) {
    $selectedCapacityKey = (string)($capacityRows[0]['capacity_key'] ?? '');
}
$selectedCapacityRow = null;
foreach ($capacityRows as $row) {
    if ((string)($row['capacity_key'] ?? '') === $selectedCapacityKey) {
        $selectedCapacityRow = $row;
        break;
    }
}
if ($selectedCapacityRow === null && $capacityRows) {
    $selectedCapacityRow = $capacityRows[0];
    $selectedCapacityKey = (string)$selectedCapacityRow['capacity_key'];
}

$selectedCapacityLabel = (string)($selectedCapacityRow['label'] ?? '');
$selectedCapacityContext = (string)($selectedCapacityRow['questionnaire_title'] ?? '');

$staffBelow = [];
if ($selectedCapacityRow) {
    foreach (($selectedCapacityRow['responses'] ?? []) as $response) {
        if ((float)($response['score'] ?? 100) < 80.0) {
            $response['gap'] = round((float)$response['score'] - 80.0, 1);
            $staffBelow[] = $response;
        }
    }
    usort($staffBelow, static fn(array $a, array $b): int => ((float)$a['score'] <=> (float)$b['score']));
}

$courseMatches = [];
if ($selectedCapacityRow) {
    $courseFilters = $filters;
    $courseFilters['questionnaire_id'] = (int)($selectedCapacityRow['questionnaire_id'] ?? $filters['questionnaire_id']);
    $courseMatches = analytics_capacity_course_matches(
        $pdo,
        $selectedCapacityLabel,
        $courseFilters,
        (float)$selectedCapacityRow['average_score']
    );
}

$trendYears = array_column($trend, 'year');
$trendScores = array_column($trend, 'average_score');
$trendAttainment = array_column($trend, 'attainment_percent');
$availableTrendScores = array_values(array_filter($trendScores, static fn($v) => $v !== null));
$availableTrendAttainment = array_values(array_filter($trendAttainment, static fn($v) => $v !== null));
$scoreChange = count($availableTrendScores) >= 2
    ? round((float)end($availableTrendScores) - (float)reset($availableTrendScores), 1)
    : null;
$attainmentChange = count($availableTrendAttainment) >= 2
    ? round((float)end($availableTrendAttainment) - (float)reset($availableTrendAttainment), 1)
    : null;

$selectedQuestionnaireTitle = t($t, 'all_questionnaires', 'All questionnaires');
foreach ($questionnaires as $q) {
    if ((int)($q['id'] ?? 0) === $filters['questionnaire_id']) {
        $selectedQuestionnaireTitle = (string)($q['title'] ?? '');
        break;
    }
}

$filterQuery = array_filter([
    'year' => $filters['year'],
    'questionnaire_id' => $filters['questionnaire_id'],
    'department' => $filters['department'],
    'team' => $filters['team'],
    'work_function' => $filters['work_function'],
], static fn($value) => $value !== '' && $value !== 0);

$scoreTone = static function (float $score): string {
    if ($score < 60.0) return 'critical';
    if ($score < 70.0) return 'high';
    if ($score < 80.0) return 'develop';
    if ($score < 85.0) return 'target';
    return 'strategic';
};

$apexUrl = 'https://cdn.jsdelivr.net/npm/apexcharts@4.7.0/dist/apexcharts.min.js';
$pageHelpKey = 'team.analytics';
$drawerKey = 'team.analytics';
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=htmlspecialchars(t($t, 'capacity_areas_gaps', 'Capacity Areas & Gaps'), ENT_QUOTES, 'UTF-8')?></title>
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
  <style nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
    :root {
      --analytics-blue:#1769c2;
      --analytics-blue-dark:#0f4f98;
      --analytics-green:#237a4b;
      --analytics-ink:#14283f;
      --analytics-muted:#64748b;
      --analytics-border:#dbe4ef;
      --analytics-surface:#ffffff;
      --analytics-bg:#f4f7fb;
    }
    body { background:var(--analytics-bg); }
    .capacity-page { max-width:1540px; margin:0 auto; padding:1.15rem 1.25rem 2rem; }
    .capacity-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; margin-bottom:.8rem; }
    .capacity-heading h1 { margin:0; font-size:1.65rem; color:var(--analytics-ink); letter-spacing:-.02em; }
    .capacity-heading p { margin:.28rem 0 0; color:var(--analytics-muted); font-size:.92rem; }
    .capacity-actions { display:flex; gap:.55rem; flex-wrap:wrap; }
    .analytics-tabs { display:flex; gap:.15rem; border-bottom:1px solid var(--analytics-border); margin-bottom:1rem; overflow-x:auto; }
    .analytics-tabs a { padding:.78rem 1rem; text-decoration:none; color:#334155; border-bottom:3px solid transparent; white-space:nowrap; font-weight:650; }
    .analytics-tabs a:hover { color:var(--analytics-blue); background:#f8fbff; }
    .analytics-tabs a.is-active { color:var(--analytics-blue); border-bottom-color:var(--analytics-blue); }
    .capacity-filters { display:grid; grid-template-columns:110px minmax(210px,1.35fr) minmax(180px,1fr) minmax(170px,1fr) minmax(170px,1fr) auto; gap:.7rem; align-items:end; margin-bottom:1rem; padding:.9rem; background:#fff; border:1px solid var(--analytics-border); border-radius:14px; box-shadow:0 4px 16px rgba(15,35,60,.04); }
    .capacity-filters label { display:flex; flex-direction:column; gap:.34rem; font-size:.76rem; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.025em; }
    .capacity-filters select { width:100%; min-height:38px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; color:#1e293b; padding:.4rem .5rem; }
    .metric-strip { display:grid; grid-template-columns:minmax(260px,1.8fr) repeat(3,minmax(150px,.65fr)); gap:.75rem; margin-bottom:1rem; }
    .metric-item { background:#fff; border:1px solid var(--analytics-border); border-radius:13px; padding:.82rem .95rem; min-width:0; box-shadow:0 3px 12px rgba(15,35,60,.045); }
    .metric-item small { display:block; color:#718096; text-transform:uppercase; font-size:.68rem; letter-spacing:.055em; font-weight:700; margin-bottom:.2rem; }
    .metric-item strong { color:var(--analytics-ink); font-size:1.18rem; line-height:1.25; }
    .metric-item.context strong { font-size:.9rem; display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .metric-item.target strong { color:var(--analytics-green); }
    .capacity-grid { display:grid; grid-template-columns:repeat(12,minmax(0,1fr)); gap:1rem; }
    .capacity-card { background:var(--analytics-surface); border:1px solid var(--analytics-border); border-radius:16px; box-shadow:0 5px 20px rgba(15,35,60,.055); padding:1.08rem; min-width:0; break-inside:avoid; }
    .capacity-card h2 { margin:0; font-size:1.02rem; color:var(--analytics-blue-dark); letter-spacing:.005em; }
    .card-subtitle { margin:.25rem 0 .85rem; color:var(--analytics-muted); font-size:.8rem; }
    .trend-card { grid-column:1 / -1; border-top:4px solid var(--analytics-blue); }
    .gaps-card { grid-column:span 5; }
    .heatmap-card { grid-column:1 / -1; }
    .learning-card { grid-column:span 7; }
    .staff-card { grid-column:span 5; }
    .trend-layout { display:grid; grid-template-columns:minmax(0,1fr) 200px; gap:1rem; align-items:stretch; }
    #annualTrend { min-height:350px; }
    .trend-kpis { display:flex; flex-direction:column; gap:.8rem; justify-content:center; }
    .trend-kpi { border:1px solid #cfe0f4; background:linear-gradient(180deg,#fbfdff,#f2f7fd); border-radius:14px; text-align:center; padding:1.05rem .6rem; }
    .trend-kpi strong { display:block; font-size:1.85rem; color:var(--analytics-blue); letter-spacing:-.03em; }
    .trend-kpi.green { border-color:#cae6d6; background:linear-gradient(180deg,#fbfefc,#f1f9f4); }
    .trend-kpi.green strong { color:var(--analytics-green); }
    .trend-kpi span { color:#526174; font-size:.78rem; font-weight:600; }
    .capacity-table { width:100%; border-collapse:separate; border-spacing:0; font-size:.85rem; }
    .capacity-table th, .capacity-table td { border-bottom:1px solid #e7edf4; padding:.58rem .48rem; text-align:left; vertical-align:middle; }
    .capacity-table th { font-size:.69rem; color:#607086; background:#f7f9fc; text-transform:uppercase; letter-spacing:.04em; }
    .capacity-table tr:hover td { background:#fafcff; }
    .capacity-name { font-weight:700; color:#1e3a5f; text-decoration:none; }
    .capacity-context { display:block; margin-top:.13rem; color:#7a8798; font-size:.69rem; line-height:1.25; }
    .score-pill { display:inline-flex; align-items:center; justify-content:center; min-width:62px; padding:.25rem .5rem; border-radius:999px; font-weight:800; }
    .score-good { background:#e3f5e9; color:#17653a; } .score-mid { background:#fff3c4; color:#6b4f00; }
    .score-low { background:#ffead5; color:#9a3412; } .score-critical { background:#fee2e2; color:#991b1b; }
    .gap-negative { color:#b42318; font-weight:800; } .gap-positive { color:#18794e; font-weight:800; }
    .rank-badge { display:inline-grid; place-items:center; width:27px; height:27px; border-radius:8px; background:#d92d20; color:#fff; font-weight:800; }
    .benchmark-note { display:flex; gap:.8rem; flex-wrap:wrap; align-items:center; font-size:.72rem; color:#536174; margin-top:.75rem; }
    .legend-chip { display:inline-flex; align-items:center; gap:.35rem; }
    .legend-swatch { width:.8rem; height:.8rem; border-radius:4px; box-shadow:inset 0 0 0 1px rgba(0,0,0,.08); }
    .legend-swatch.critical{background:#b42318}.legend-swatch.high{background:#b54708}.legend-swatch.develop{background:#f4c430}.legend-swatch.target{background:#238a57}.legend-swatch.strategic{background:#14532d}
    .heatmap-intro { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; margin-bottom:.8rem; }
    .heatmap-note { max-width:820px; color:#64748b; font-size:.79rem; line-height:1.45; }
    .directorate-bands { display:grid; gap:.85rem; }
    .directorate-band { border:1px solid #dce5ef; border-radius:13px; overflow:hidden; background:#fbfcfe; }
    .directorate-band__header { display:flex; justify-content:space-between; gap:1rem; align-items:center; padding:.65rem .8rem; background:#f3f7fb; border-bottom:1px solid #dce5ef; }
    .directorate-band__header strong { color:#17365d; font-size:.85rem; }
    .directorate-band__header span { color:#7b8796; font-size:.7rem; }
    .heat-tiles { display:grid; grid-template-columns:repeat(auto-fit,minmax(165px,1fr)); gap:.55rem; padding:.7rem; }
    .heat-tile { min-height:92px; border-radius:10px; padding:.68rem .72rem; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 2px 7px rgba(15,35,60,.09); }
    .heat-tile__label { font-size:.8rem; font-weight:800; line-height:1.2; }
    .heat-tile__context { font-size:.62rem; line-height:1.18; margin-top:.2rem; opacity:.88; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
    .heat-tile__bottom { display:flex; justify-content:space-between; align-items:flex-end; gap:.5rem; margin-top:.45rem; }
    .heat-tile__score { font-size:1.35rem; font-weight:900; letter-spacing:-.04em; }
    .heat-tile__staff { font-size:.62rem; opacity:.88; text-align:right; }
    .heat-tile.critical { background:#b42318; color:#fff; }
    .heat-tile.high { background:#b54708; color:#fff; }
    .heat-tile.develop { background:#f4c430; color:#1f2937; }
    .heat-tile.target { background:#238a57; color:#fff; }
    .heat-tile.strategic { background:#14532d; color:#fff; }
    .capacity-selector { display:flex; gap:.75rem; flex-wrap:wrap; align-items:end; margin-bottom:.8rem; }
    .capacity-selector label { display:flex; flex-direction:column; gap:.3rem; color:#526174; font-size:.73rem; font-weight:700; }
    .capacity-selector select { min-width:280px; max-width:520px; min-height:38px; border:1px solid #cbd5e1; border-radius:8px; }
    .learning-list { display:grid; gap:.6rem; }
    .learning-row { display:grid; grid-template-columns:minmax(180px,1.4fr) .7fr .65fr minmax(190px,1.45fr) auto; gap:.65rem; align-items:center; padding:.75rem; border:1px solid #e2e8f0; border-radius:10px; background:#fbfcfe; }
    .learning-row small { color:#66758a; }
    .empty-note { padding:1rem; border:1px dashed #b8c4d4; border-radius:10px; background:#f8fafc; color:#5b6472; }
    .capacity-footnote { color:#66758a; font-size:.74rem; margin:.75rem 0 0; line-height:1.4; }
    @media (max-width:1100px) {
      .capacity-filters { grid-template-columns:repeat(2,minmax(160px,1fr)); }
      .metric-strip { grid-template-columns:repeat(2,minmax(0,1fr)); }
      .gaps-card,.learning-card,.staff-card { grid-column:1/-1; }
      .trend-layout { grid-template-columns:1fr; }
      .trend-kpis { display:grid; grid-template-columns:repeat(2,1fr); }
    }
    @media (max-width:680px) {
      .capacity-page { padding:.8rem; }
      .capacity-heading { display:block; }
      .capacity-actions { margin-top:.7rem; }
      .metric-strip { grid-template-columns:1fr; }
      .heat-tiles { grid-template-columns:1fr; }
    }
    @page { size:A4 landscape; margin:9mm; }
    @media print {
      body { background:#fff !important; font-size:9pt; }
      header, footer, .no-print, .analytics-tabs, .capacity-filters { display:none !important; }
      .capacity-page { max-width:none; padding:0; }
      .capacity-card,.metric-item { box-shadow:none; }
      .capacity-grid { display:block; }
      .capacity-card { margin-bottom:5mm; page-break-inside:avoid; }
      .trend-card { page-break-after:always; }
      .heat-tile { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
      .heat-tiles { grid-template-columns:repeat(4,1fr); }
      a { color:inherit; text-decoration:none; }
    }
  </style>
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__ . '/../templates/header.php'; ?>
<main class="capacity-page">
  <div class="capacity-heading">
    <div>
      <h1><?=t($t, 'capacity_areas_gaps', 'Analytics – Capacity Areas & Gaps')?></h1>
      <p><?=t($t, 'capacity_areas_gaps_hint', 'Identify the competency gaps within each directorate and connect them to targeted learning.')?></p>
    </div>
    <div class="capacity-actions no-print">
      <button type="button" class="md-button md-outline" data-print-dashboard><?=t($t, 'print_a4', 'Print / Save PDF')?></button>
      <a class="md-button md-outline" href="<?=htmlspecialchars(url_for('admin/analytics_data_viewer_export.php') . '?' . http_build_query($filterQuery), ENT_QUOTES, 'UTF-8')?>"><?=t($t, 'export_csv', 'Export CSV')?></a>
    </div>
  </div>

  <nav class="analytics-tabs no-print" aria-label="<?=htmlspecialchars(t($t, 'analytics_tabs', 'Analytics sections'), ENT_QUOTES, 'UTF-8')?>">
    <a href="<?=htmlspecialchars(url_for('admin/analytics_dashboard.php') . '?' . http_build_query($filterQuery), ENT_QUOTES, 'UTF-8')?>">1. <?=t($t, 'analytics_overview', 'Overview')?></a>
    <a class="is-active" href="<?=htmlspecialchars(url_for('admin/analytics_capacity.php') . '?' . http_build_query($filterQuery), ENT_QUOTES, 'UTF-8')?>">2. <?=t($t, 'capacity_areas_gaps_short', 'Capacity Areas & Gaps')?></a>
    <a href="<?=htmlspecialchars(url_for('admin/analytics.php?view=drilldown&questionnaire_id=' . (int)$filters['questionnaire_id']), ENT_QUOTES, 'UTF-8')?>">3. <?=t($t, 'questionnaire_analysis', 'Questionnaire Analysis')?></a>
    <a href="<?=htmlspecialchars(url_for('admin/course_mappings.php'), ENT_QUOTES, 'UTF-8')?>">4. <?=t($t, 'learning_recommendations', 'Learning Recommendations')?></a>
    <a href="<?=htmlspecialchars(url_for('admin/analytics_data_viewer.php') . '?' . http_build_query($filterQuery), ENT_QUOTES, 'UTF-8')?>">5. <?=t($t, 'analytics_report_explorer_title', 'Report Explorer')?></a>
  </nav>

  <form method="get" class="capacity-filters no-print">
    <label><?=t($t, 'assessment_year', 'Year')?><select name="year">
      <?php for ($year=2026; $year<=max(2028,$currentYear); $year++): ?>
        <option value="<?=$year?>" <?=$filters['year']===$year?'selected':''?>><?=$year?></option>
      <?php endfor; ?>
    </select></label>
    <label><?=t($t, 'questionnaire', 'Questionnaire')?><select name="questionnaire_id">
      <option value="0" <?=$filters['questionnaire_id']===0?'selected':''?>><?=t($t,'all_questionnaires','All questionnaires')?></option>
      <?php foreach ($questionnaires as $q): $qid=(int)($q['id']??0); ?><option value="<?=$qid?>" <?=$filters['questionnaire_id']===$qid?'selected':''?>><?=htmlspecialchars((string)($q['title']??''),ENT_QUOTES,'UTF-8')?></option><?php endforeach; ?>
    </select></label>
    <label><?=t($t, 'department', 'Directorate')?><select name="department"><option value=""><?=t($t, 'all_departments', 'All directorates')?></option>
      <?php foreach ($departmentOptions as $value=>$label): ?><option value="<?=htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8')?>" <?=$filters['department']===(string)$value?'selected':''?>><?=htmlspecialchars((string)$label,ENT_QUOTES,'UTF-8')?></option><?php endforeach; ?>
    </select></label>
    <label><?=t($t, 'cadre', 'Team')?><select name="team"><option value=""><?=t($t, 'all_teams', 'All teams')?></option>
      <?php foreach ($teamOptions as $value=>$label): ?><option value="<?=htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8')?>" <?=$filters['team']===(string)$value?'selected':''?>><?=htmlspecialchars((string)$label,ENT_QUOTES,'UTF-8')?></option><?php endforeach; ?>
    </select></label>
    <label><?=t($t, 'work_function', 'Work Role')?><select name="work_function"><option value=""><?=t($t, 'all_work_roles', 'All work roles')?></option>
      <?php foreach ($workFunctionOptions as $value=>$label): ?><option value="<?=htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8')?>" <?=$filters['work_function']===(string)$value?'selected':''?>><?=htmlspecialchars((string)$label,ENT_QUOTES,'UTF-8')?></option><?php endforeach; ?>
    </select></label>
    <button class="md-button md-primary" type="submit"><?=t($t, 'apply_filters', 'Apply filters')?></button>
  </form>

  <div class="metric-strip">
    <div class="metric-item context"><small><?=t($t,'questionnaire','Questionnaire')?></small><strong title="<?=htmlspecialchars($selectedQuestionnaireTitle,ENT_QUOTES,'UTF-8')?>"><?=htmlspecialchars($selectedQuestionnaireTitle,ENT_QUOTES,'UTF-8')?></strong></div>
    <div class="metric-item"><small><?=t($t,'average_score','Average Score')?></small><strong><?=$overallAverage!==null?number_format($overallAverage,1).'%':'—'?></strong></div>
    <div class="metric-item"><small><?=t($t,'staff_assessed','Staff Assessed')?></small><strong><?=count($filteredRows)?></strong></div>
    <div class="metric-item target"><small><?=t($t,'epss_kpi_title','≥80% Target Attainment')?></small><strong><?=$overallAttainment['percent']!==null?number_format((float)$overallAttainment['percent'],1).'%':'—'?></strong></div>
  </div>

  <div class="capacity-grid">
    <section class="capacity-card trend-card">
      <h2>A. <?=t($t, 'annual_performance_trend', 'Annual Competency Performance Trend')?></h2>
      <p class="card-subtitle"><?=t($t,'annual_trend_subtitle','Annual assessments only: average competency score and ≥80% target attainment are separate measures.')?></p>
      <div class="trend-layout">
        <div id="annualTrend" aria-label="<?=htmlspecialchars(t($t,'annual_performance_trend','Annual performance trend'),ENT_QUOTES,'UTF-8')?>"></div>
        <div class="trend-kpis">
          <div class="trend-kpi"><strong><?=$scoreChange!==null?sprintf('%+.1f',$scoreChange).' pts':'—'?></strong><span><?=t($t,'three_year_change_score','Average Score Change')?></span></div>
          <div class="trend-kpi green"><strong><?=$attainmentChange!==null?sprintf('%+.1f',$attainmentChange).' pts':'—'?></strong><span><?=t($t,'three_year_change_target','≥80% Target Change')?></span></div>
        </div>
      </div>
      <p class="capacity-footnote"><?=t($t,'annual_trend_method_note','Trend uses the latest completed assessment per employee, questionnaire family, and assessment year. Future years remain blank until assessments are completed.')?></p>
    </section>

    <section class="capacity-card gaps-card">
      <h2>B. <?=t($t, 'top_capacity_gaps', 'Priority Capacity Gaps')?> (<?=$filters['year']?>)</h2>
      <p class="card-subtitle"><?=t($t,'capacity_gap_context_note','Capacity Areas are kept within their questionnaire/framework context; identical codes in different directorates are not merged.')?></p>
      <?php if ($capacityRows): ?>
      <table class="capacity-table"><thead><tr><th>#</th><th><?=t($t,'capacity_area','Capacity Area')?></th><th><?=t($t,'average_score','Score')?></th><th><?=t($t,'gap','Gap')?></th><th><?=t($t,'staff_below_target','Below 80%')?></th></tr></thead><tbody>
      <?php foreach (array_slice($capacityRows,0,10) as $index=>$row): $score=(float)$row['average_score']; $class=$score>=80?'score-good':($score>=70?'score-mid':($score>=60?'score-low':'score-critical')); ?>
        <tr>
          <td><span class="rank-badge"><?=$index+1?></span></td>
          <td><a class="capacity-name" href="?<?=htmlspecialchars(http_build_query(array_merge($filterQuery,['capacity'=>$row['capacity_key']])),ENT_QUOTES,'UTF-8')?>"><?=htmlspecialchars((string)$row['label'],ENT_QUOTES,'UTF-8')?></a><span class="capacity-context"><?=htmlspecialchars((string)$row['questionnaire_title'],ENT_QUOTES,'UTF-8')?></span></td>
          <td><span class="score-pill <?=$class?>"><?=number_format($score,1)?>%</span></td>
          <td class="<?=$row['gap']<0?'gap-negative':'gap-positive'?>"><?=sprintf('%+.1f',(float)$row['gap'])?> pts</td>
          <td><?= (int)$row['staff_below_target'] ?> / <?= (int)$row['staff_assessed'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table>
      <?php else: ?><div class="empty-note"><?=t($t,'no_capacity_scores','No Capacity Area scores are available for the selected filters.')?></div><?php endif; ?>
    </section>

    <section class="capacity-card learning-card">
      <h2>C. <?=t($t, 'gap_details_learning', 'Gap Details & Recommended Learning')?></h2>
      <p class="card-subtitle"><?=t($t,'learning_context_note','Learning is linked to the selected Capacity Area within its questionnaire context.')?></p>
      <form method="get" class="capacity-selector no-print">
        <?php foreach ($filterQuery as $key=>$value): ?><input type="hidden" name="<?=htmlspecialchars((string)$key,ENT_QUOTES,'UTF-8')?>" value="<?=htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8')?>"><?php endforeach; ?>
        <label><?=t($t,'capacity_area','Capacity Area')?> <select name="capacity" onchange="this.form.submit()">
          <?php foreach ($capacityRows as $row): ?><option value="<?=htmlspecialchars((string)$row['capacity_key'],ENT_QUOTES,'UTF-8')?>" <?=$selectedCapacityKey===(string)$row['capacity_key']?'selected':''?>><?=htmlspecialchars((string)$row['label'].' — '.(string)$row['questionnaire_title'],ENT_QUOTES,'UTF-8')?></option><?php endforeach; ?>
        </select></label>
        <?php if ($selectedCapacityRow): ?><span><small><?=t($t,'average_score','Average Score')?></small><br><strong><?=number_format((float)$selectedCapacityRow['average_score'],1)?>%</strong></span><span><small><?=t($t,'gap_to_target','Gap to Target')?></small><br><strong class="<?=$selectedCapacityRow['gap']<0?'gap-negative':'gap-positive'?>"><?=sprintf('%+.1f',(float)$selectedCapacityRow['gap'])?> pts</strong></span><?php endif; ?>
      </form>
      <?php if ($selectedCapacityRow): ?><p class="capacity-context"><?=htmlspecialchars($selectedCapacityContext,ENT_QUOTES,'UTF-8')?></p><?php endif; ?>
      <?php if ($courseMatches): ?><div class="learning-list">
        <?php foreach ($courseMatches as $course): ?>
          <div class="learning-row"><div><strong><?=htmlspecialchars((string)$course['title'],ENT_QUOTES,'UTF-8')?></strong><br><small><?=htmlspecialchars((string)($course['code']??''),ENT_QUOTES,'UTF-8')?></small></div><div><?=htmlspecialchars((string)($course['mode_of_delivery']??'eLearning'),ENT_QUOTES,'UTF-8')?></div><div><?=htmlspecialchars((string)($course['duration']??'—'),ENT_QUOTES,'UTF-8')?></div><div><small><?=htmlspecialchars((string)($course['expected_competency']??$course['course_objective']??''),ENT_QUOTES,'UTF-8')?></small></div><div class="no-print"><?php if (!empty($course['moodle_url'])): ?><a class="md-button md-outline" href="<?=htmlspecialchars((string)$course['moodle_url'],ENT_QUOTES,'UTF-8')?>" target="_blank" rel="noopener"><?=t($t,'view','View')?></a><?php endif; ?></div></div>
        <?php endforeach; ?>
      </div><?php else: ?><div class="empty-note"><?=t($t,'no_explicit_learning_mapping','No active course has an explicit thematic-area or expected-competency match for this Capacity Area yet. Add the mapping in Learning Recommendations rather than showing an inferred course.')?></div><?php endif; ?>
    </section>

    <section class="capacity-card heatmap-card">
      <div class="heatmap-intro">
        <div><h2>D. <?=t($t, 'capacity_heatmap_directorate', 'Capacity Area Heatmap – Directorate Context')?></h2><p class="card-subtitle"><?=t($t,'capacity_heatmap_context_note','Each directorate is displayed with its own Capacity Areas. CA1, CA2, etc. are not assumed to be equivalent across directorates or questionnaire families.')?></p></div>
        <div class="benchmark-note" aria-label="Heatmap legend">
          <span class="legend-chip"><i class="legend-swatch critical"></i>&lt;60 Critical</span>
          <span class="legend-chip"><i class="legend-swatch high"></i>60–69 High</span>
          <span class="legend-chip"><i class="legend-swatch develop"></i>70–79 Develop</span>
          <span class="legend-chip"><i class="legend-swatch target"></i>80–84 On target</span>
          <span class="legend-chip"><i class="legend-swatch strategic"></i>85–100 Strategic</span>
        </div>
      </div>
      <?php if (!empty($heatmap['groups'])): ?>
      <div class="directorate-bands">
        <?php foreach ($heatmap['groups'] as $group): ?>
          <section class="directorate-band">
            <div class="directorate-band__header"><strong><?=htmlspecialchars((string)$group['department'],ENT_QUOTES,'UTF-8')?></strong><span><?=count($group['capacities'])?> <?=t($t,'capacity_areas','Capacity Areas')?></span></div>
            <div class="heat-tiles">
              <?php foreach ($group['capacities'] as $area): $tone=$scoreTone((float)$area['average_score']); ?>
                <article class="heat-tile <?=$tone?>" title="<?=htmlspecialchars((string)$area['questionnaire_title'],ENT_QUOTES,'UTF-8')?>">
                  <div><div class="heat-tile__label"><?=htmlspecialchars((string)$area['label'],ENT_QUOTES,'UTF-8')?></div><div class="heat-tile__context"><?=htmlspecialchars((string)$area['questionnaire_title'],ENT_QUOTES,'UTF-8')?></div></div>
                  <div class="heat-tile__bottom"><span class="heat-tile__score"><?=number_format((float)$area['average_score'],1)?>%</span><span class="heat-tile__staff"><?= (int)$area['staff_assessed'] ?> <?=t($t,'staff','staff')?></span></div>
                </article>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
      <?php else: ?><div class="empty-note"><?=t($t,'no_capacity_heatmap','No directorate Capacity Area scores are available for the selected filters.')?></div><?php endif; ?>
    </section>

    <section class="capacity-card staff-card">
      <h2>E. <?=t($t,'staff_below_benchmark','Staff Below Benchmark')?><?= $selectedCapacityLabel!=='' ? ' – '.htmlspecialchars($selectedCapacityLabel,ENT_QUOTES,'UTF-8') : '' ?></h2>
      <p class="card-subtitle"><?=htmlspecialchars($selectedCapacityContext,ENT_QUOTES,'UTF-8')?></p>
      <?php if ($staffBelow): ?><table class="capacity-table"><thead><tr><th>#</th><th><?=t($t,'staff_member','Staff Member')?></th><th><?=t($t,'cadre','Team')?></th><th><?=t($t,'latest_score','Score')?></th><th><?=t($t,'gap','Gap')?></th></tr></thead><tbody>
        <?php foreach (array_slice($staffBelow,0,12) as $index=>$person): $name=trim((string)($person['full_name']??'')); if($name===''){$name=(string)($person['username']??'');} ?><tr><td><?=$index+1?></td><td><?=htmlspecialchars($name,ENT_QUOTES,'UTF-8')?></td><td><?=htmlspecialchars(team_label($pdo,(string)($person['team']??'')),ENT_QUOTES,'UTF-8')?></td><td><span class="score-pill score-critical"><?=number_format((float)$person['score'],1)?>%</span></td><td class="gap-negative"><?=sprintf('%+.1f',(float)$person['gap'])?> pts</td></tr><?php endforeach; ?>
      </tbody></table><?php else: ?><div class="empty-note"><?=t($t,'no_staff_below_benchmark','No staff are below the benchmark for this Capacity Area under the selected filters.')?></div><?php endif; ?>
    </section>
  </div>

  <p class="capacity-footnote"><?=t($t,'capacity_page_methodology','Method: actual scores use the latest completed Submitted / Approved / Approved Late assessment per employee. Capacity Areas correspond to scored questionnaire sections and are identified within their questionnaire family; matching codes across different contexts are not merged.')?></p>
</main>
<?php include __DIR__ . '/../templates/footer.php'; ?>
<script src="<?=htmlspecialchars($apexUrl,ENT_QUOTES,'UTF-8')?>"></script>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
(function(){
  const trendYears = <?=json_encode($trendYears, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
  const trendScores = <?=json_encode($trendScores, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
  const trendAttainment = <?=json_encode($trendAttainment, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
  const blue = '#1769c2';
  const green = '#237a4b';

  function renderTrend(){
    if (typeof ApexCharts !== 'function') return;
    const trendNode = document.querySelector('#annualTrend');
    if (!trendNode) return;

    const trendChart = new ApexCharts(trendNode, {
      chart:{type:'line',height:350,toolbar:{show:true,tools:{download:true}},animations:{enabled:false},fontFamily:'inherit'},
      series:[
        {name:'Average Competency Score',data:trendScores},
        {name:'Staff Meeting ≥80% Target',data:trendAttainment}
      ],
      colors:[blue,green],
      stroke:{curve:'smooth',width:[4,3],dashArray:[0,6],lineCap:'round'},
      markers:{size:5,strokeWidth:3,strokeColors:'#fff',hover:{size:7}},
      grid:{borderColor:'#e5ebf2',strokeDashArray:3,padding:{left:10,right:12,top:4,bottom:0}},
      xaxis:{categories:trendYears,title:{text:'Assessment Year',style:{fontWeight:700,color:'#64748b'}},labels:{style:{colors:'#64748b'}}},
      yaxis:{min:0,max:100,tickAmount:5,labels:{formatter:(v)=>Math.round(v)+'%',style:{colors:'#64748b'}},title:{text:'Percent (%)',style:{fontWeight:700,color:'#64748b'}}},
      dataLabels:{enabled:true,formatter:(v)=>v===null||typeof v==='undefined'?'':Number(v).toFixed(1)+'%',offsetY:-8,style:{fontSize:'11px',fontWeight:700,colors:['#fff']},background:{enabled:true,borderRadius:4,padding:4,opacity:.95}},
      annotations:{yaxis:[{y:80,borderColor:'#237a4b',strokeDashArray:5,label:{text:'80% benchmark',position:'right',style:{fontSize:'10px',fontWeight:700,background:'#237a4b',color:'#fff'}}}]},
      legend:{position:'bottom',fontWeight:600,labels:{colors:'#475569'}},
      tooltip:{shared:true,theme:'light',y:{formatter:(v)=>v===null||typeof v==='undefined'?'No assessment data':Number(v).toFixed(1)+'%'}},
      noData:{text:'No annual assessment data for the selected filters.'}
    });
    trendChart.render();
    window.__casTrendChart = trendChart;
  }

  document.querySelector('[data-print-dashboard]')?.addEventListener('click',()=>{
    const chart=window.__casTrendChart;
    if (chart) {
      chart.updateOptions({chart:{animations:{enabled:false}}},false,false).finally(()=>window.print());
    } else {
      window.print();
    }
  });

  renderTrend();
})();
</script>
</body>
</html>
