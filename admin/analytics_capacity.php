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

if ($filters['questionnaire_id'] <= 0 && $questionnaires) {
    $filters['questionnaire_id'] = (int)($questionnaires[0]['id'] ?? 0);
}

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

$selectedCapacity = trim((string)($_GET['capacity'] ?? ''));
if ($selectedCapacity === '' && $capacityRows) {
    $selectedCapacity = (string)($capacityRows[0]['label'] ?? '');
}
$selectedCapacityRow = null;
foreach ($capacityRows as $row) {
    if ((string)$row['label'] === $selectedCapacity) {
        $selectedCapacityRow = $row;
        break;
    }
}
if ($selectedCapacityRow === null && $capacityRows) {
    $selectedCapacityRow = $capacityRows[0];
    $selectedCapacity = (string)$selectedCapacityRow['label'];
}

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

$courseMatches = $selectedCapacityRow
    ? analytics_capacity_course_matches($pdo, $selectedCapacity, $filters, (float)$selectedCapacityRow['average_score'])
    : [];

$trendYears = array_column($trend, 'year');
$trendScores = array_column($trend, 'average_score');
$trendAttainment = array_column($trend, 'attainment_percent');
$firstTrendScore = null;
$lastTrendScore = null;
$firstTrendAttainment = null;
$lastTrendAttainment = null;
foreach ($trend as $trendRow) {
    if ($trendRow['average_score'] !== null) {
        $firstTrendScore ??= (float)$trendRow['average_score'];
        $lastTrendScore = (float)$trendRow['average_score'];
    }
    if ($trendRow['attainment_percent'] !== null) {
        $firstTrendAttainment ??= (float)$trendRow['attainment_percent'];
        $lastTrendAttainment = (float)$trendRow['attainment_percent'];
    }
}
$scoreChange = ($firstTrendScore !== null && $lastTrendScore !== null) ? round($lastTrendScore - $firstTrendScore, 1) : null;
$attainmentChange = ($firstTrendAttainment !== null && $lastTrendAttainment !== null) ? round($lastTrendAttainment - $firstTrendAttainment, 1) : null;

$selectedQuestionnaireTitle = '';
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
    :root { --analytics-blue:#1565c0; --analytics-green:#2e7d32; --analytics-red:#c62828; --analytics-orange:#ef6c00; --analytics-yellow:#f9a825; }
    .capacity-page { max-width: 1500px; margin: 0 auto; padding: 1rem; }
    .capacity-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; margin-bottom:.75rem; }
    .capacity-heading h1 { margin:0; font-size:1.55rem; color:#10233f; }
    .capacity-heading p { margin:.25rem 0 0; color:#5b6472; }
    .capacity-actions { display:flex; gap:.5rem; flex-wrap:wrap; }
    .analytics-tabs { display:flex; gap:.25rem; border-bottom:1px solid #d9e1ec; margin-bottom:1rem; overflow-x:auto; }
    .analytics-tabs a { padding:.75rem .95rem; text-decoration:none; color:#2d3748; border-bottom:3px solid transparent; white-space:nowrap; font-weight:600; }
    .analytics-tabs a.is-active { color:var(--analytics-blue); border-bottom-color:var(--analytics-blue); }
    .capacity-filters { display:grid; grid-template-columns:repeat(5,minmax(150px,1fr)) auto; gap:.65rem; align-items:end; margin-bottom:1rem; }
    .capacity-filters label { display:flex; flex-direction:column; gap:.3rem; font-size:.82rem; font-weight:600; color:#4a5568; }
    .capacity-filters select, .capacity-filters input { width:100%; }
    .capacity-grid { display:grid; grid-template-columns:repeat(12,minmax(0,1fr)); gap:1rem; }
    .capacity-card { background:#fff; border:1px solid #dfe6ef; border-radius:12px; box-shadow:0 2px 8px rgba(15,35,60,.06); padding:1rem; min-width:0; break-inside:avoid; }
    .capacity-card h2 { margin:0 0 .75rem; font-size:1rem; color:#0d47a1; letter-spacing:.01em; }
    .trend-card { grid-column:1 / -1; }
    .gaps-card { grid-column:span 5; }
    .heatmap-card { grid-column:span 7; }
    .learning-card { grid-column:span 7; }
    .staff-card { grid-column:span 5; }
    .trend-layout { display:grid; grid-template-columns:minmax(0,1fr) 180px; gap:1rem; align-items:stretch; }
    #annualTrend { min-height:330px; }
    #capacityHeatmap { min-height:360px; }
    .trend-kpis { display:flex; flex-direction:column; gap:.75rem; justify-content:center; }
    .trend-kpi { border:2px solid #d7e4f6; border-radius:12px; text-align:center; padding:1rem .5rem; }
    .trend-kpi strong { display:block; font-size:1.8rem; color:var(--analytics-blue); }
    .trend-kpi.green { border-color:#c9e6ce; }
    .trend-kpi.green strong { color:var(--analytics-green); }
    .metric-strip { display:flex; gap:1rem; flex-wrap:wrap; margin:.35rem 0 .75rem; color:#4a5568; font-size:.9rem; }
    .metric-strip strong { color:#152a45; }
    .capacity-table { width:100%; border-collapse:collapse; font-size:.88rem; }
    .capacity-table th, .capacity-table td { border-bottom:1px solid #e8edf3; padding:.55rem .45rem; text-align:left; vertical-align:middle; }
    .capacity-table th { font-size:.77rem; color:#536174; background:#f8fafc; }
    .score-pill, .priority-pill { display:inline-flex; align-items:center; justify-content:center; min-width:58px; padding:.23rem .45rem; border-radius:6px; font-weight:700; }
    .score-good { background:#e8f5e9; color:#1b5e20; } .score-mid { background:#fff8e1; color:#8d6e00; }
    .score-low { background:#fff3e0; color:#e65100; } .score-critical { background:#ffebee; color:#b71c1c; }
    .gap-negative { color:#c62828; font-weight:700; } .gap-positive { color:#2e7d32; font-weight:700; }
    .rank-badge { display:inline-grid; place-items:center; width:25px; height:25px; border-radius:6px; background:#c62828; color:#fff; font-weight:700; }
    .capacity-selector { display:flex; gap:.75rem; flex-wrap:wrap; align-items:center; margin-bottom:.8rem; }
    .capacity-selector select { min-width:230px; }
    .learning-list { display:grid; gap:.55rem; }
    .learning-row { display:grid; grid-template-columns:minmax(180px,1.5fr) .8fr .7fr minmax(180px,1.5fr) auto; gap:.6rem; align-items:center; padding:.65rem; border:1px solid #e4e9f0; border-radius:8px; }
    .learning-row small { color:#637083; }
    .empty-note { padding:1rem; border:1px dashed #b8c4d4; border-radius:8px; background:#f8fafc; color:#5b6472; }
    .capacity-footnote { color:#5f6b7a; font-size:.78rem; margin:.75rem 0 0; }
    .benchmark-note { display:flex; gap:1rem; flex-wrap:wrap; font-size:.75rem; color:#4b5563; margin-top:.5rem; }
    .dot { width:.65rem; height:.65rem; border-radius:50%; display:inline-block; margin-right:.25rem; }
    .dot.red{background:#c62828}.dot.orange{background:#ef6c00}.dot.yellow{background:#f9a825}.dot.green{background:#43a047}.dot.darkgreen{background:#1b5e20}
    @media (max-width:1050px) { .capacity-filters{grid-template-columns:repeat(2,minmax(160px,1fr));}.gaps-card,.heatmap-card,.learning-card,.staff-card{grid-column:1/-1}.trend-layout{grid-template-columns:1fr}.trend-kpis{display:grid;grid-template-columns:repeat(2,1fr)} }
    @page { size:A4 landscape; margin:9mm; }
    @media print {
      body { background:#fff !important; font-size:9pt; }
      header, footer, .no-print, .analytics-tabs, .capacity-filters { display:none !important; }
      .capacity-page { max-width:none; padding:0; }
      .capacity-heading { margin-bottom:4mm; }
      .capacity-heading h1 { font-size:16pt; }
      .capacity-grid { display:block; }
      .capacity-card { box-shadow:none; border:1px solid #bbc6d3; margin-bottom:5mm; padding:4mm; page-break-inside:avoid; }
      .trend-card { page-break-after:always; }
      .trend-layout { grid-template-columns:minmax(0,1fr) 38mm; }
      #annualTrend { min-height:120mm; }
      #capacityHeatmap { min-height:110mm; }
      .gaps-card, .heatmap-card, .learning-card, .staff-card { width:100%; }
      .learning-row { grid-template-columns:1.4fr .7fr .6fr 1.4fr; }
      .learning-row .no-print { display:none !important; }
      a { color:#000; text-decoration:none; }
    }
  </style>
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__ . '/../templates/header.php'; ?>
<main class="capacity-page">
  <div class="capacity-heading">
    <div>
      <h1><?=t($t, 'capacity_areas_gaps', 'Analytics – Capacity Areas & Gaps')?></h1>
      <p><?=t($t, 'capacity_areas_gaps_hint', 'Identify competency gaps by Capacity Area and link them to recommended learning.')?></p>
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
    <label><?=t($t, 'assessment_year', 'Assessment Year')?><select name="year">
      <?php for ($year=max(2020,$currentYear-8); $year<=max(2028,$currentYear); $year++): ?>
        <option value="<?=$year?>" <?=$filters['year']===$year?'selected':''?>><?=$year?></option>
      <?php endfor; ?>
    </select></label>
    <label><?=t($t, 'department', 'Directorate')?><select name="department"><option value=""><?=t($t, 'all_departments', 'All Directorates')?></option>
      <?php foreach ($departmentOptions as $value=>$label): ?><option value="<?=htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8')?>" <?=$filters['department']===(string)$value?'selected':''?>><?=htmlspecialchars((string)$label,ENT_QUOTES,'UTF-8')?></option><?php endforeach; ?>
    </select></label>
    <label><?=t($t, 'cadre', 'Team')?><select name="team"><option value=""><?=t($t, 'all_teams', 'All Teams')?></option>
      <?php foreach ($teamOptions as $value=>$label): ?><option value="<?=htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8')?>" <?=$filters['team']===(string)$value?'selected':''?>><?=htmlspecialchars((string)$label,ENT_QUOTES,'UTF-8')?></option><?php endforeach; ?>
    </select></label>
    <label><?=t($t, 'work_function', 'Work Role')?><select name="work_function"><option value=""><?=t($t, 'all_work_roles', 'All Roles')?></option>
      <?php foreach ($workFunctionOptions as $value=>$label): ?><option value="<?=htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8')?>" <?=$filters['work_function']===(string)$value?'selected':''?>><?=htmlspecialchars((string)$label,ENT_QUOTES,'UTF-8')?></option><?php endforeach; ?>
    </select></label>
    <label><?=t($t, 'questionnaire', 'Questionnaire')?><select name="questionnaire_id">
      <?php foreach ($questionnaires as $q): $qid=(int)($q['id']??0); ?><option value="<?=$qid?>" <?=$filters['questionnaire_id']===$qid?'selected':''?>><?=htmlspecialchars((string)($q['title']??''),ENT_QUOTES,'UTF-8')?></option><?php endforeach; ?>
    </select></label>
    <button class="md-button md-primary" type="submit"><?=t($t, 'apply_filters', 'Apply')?></button>
  </form>

  <div class="metric-strip">
    <span><strong><?=htmlspecialchars($selectedQuestionnaireTitle,ENT_QUOTES,'UTF-8')?></strong></span>
    <span><?=t($t,'average_score','Average Score')?>: <strong><?=$overallAverage!==null?number_format($overallAverage,1).'%':'—'?></strong></span>
    <span><?=t($t,'staff_assessed','Staff Assessed')?>: <strong><?=count($filteredRows)?></strong></span>
    <span><?=t($t,'epss_kpi_title','Staff ≥80%')?>: <strong><?=$overallAttainment['percent']!==null?number_format((float)$overallAttainment['percent'],1).'%':'—'?></strong></span>
  </div>

  <div class="capacity-grid">
    <section class="capacity-card trend-card">
      <h2>A. <?=t($t, 'annual_performance_trend', '3-Year Performance Trend (Annual Assessments)')?></h2>
      <div class="trend-layout">
        <div id="annualTrend" aria-label="<?=htmlspecialchars(t($t,'annual_performance_trend','Annual performance trend'),ENT_QUOTES,'UTF-8')?>"></div>
        <div class="trend-kpis">
          <div class="trend-kpi"><strong><?=$scoreChange!==null?sprintf('%+.1f',$scoreChange).' pts':'—'?></strong><span><?=t($t,'three_year_change_score','Change in Average Score')?></span></div>
          <div class="trend-kpi green"><strong><?=$attainmentChange!==null?sprintf('%+.1f',$attainmentChange).' pts':'—'?></strong><span><?=t($t,'three_year_change_target','Change in Target Attainment')?></span></div>
        </div>
      </div>
      <p class="capacity-footnote"><?=t($t,'annual_trend_method_note','Trend uses the latest completed assessment per employee, questionnaire family, and assessment year. Future years remain blank until assessments are completed.')?></p>
    </section>

    <section class="capacity-card gaps-card">
      <h2>B. <?=t($t, 'top_capacity_gaps', 'Top Capacity Gaps')?> (<?=$filters['year']?>)</h2>
      <?php if ($capacityRows): ?>
      <table class="capacity-table"><thead><tr><th>#</th><th><?=t($t,'capacity_area','Capacity Area')?></th><th><?=t($t,'average_score','Avg Score')?></th><th><?=t($t,'benchmark','Benchmark')?></th><th><?=t($t,'gap','Gap')?></th><th><?=t($t,'staff_below_target','Staff Below Target')?></th></tr></thead><tbody>
      <?php foreach (array_slice($capacityRows,0,8) as $index=>$row): $score=(float)$row['average_score']; $class=$score>=80?'score-good':($score>=70?'score-mid':($score>=60?'score-low':'score-critical')); ?>
        <tr><td><span class="rank-badge"><?=$index+1?></span></td><td><a href="?<?=htmlspecialchars(http_build_query(array_merge($filterQuery,['capacity'=>$row['label']])),ENT_QUOTES,'UTF-8')?>"><?=htmlspecialchars((string)$row['label'],ENT_QUOTES,'UTF-8')?></a></td><td><span class="score-pill <?=$class?>"><?=number_format($score,1)?>%</span></td><td>80%</td><td class="<?=$row['gap']<0?'gap-negative':'gap-positive'?>"><?=sprintf('%+.1f',(float)$row['gap'])?></td><td><?= (int)$row['staff_below_target'] ?> / <?= (int)$row['staff_assessed'] ?> (<?=number_format((float)$row['below_percent'],0)?>%)</td></tr>
      <?php endforeach; ?>
      </tbody></table>
      <?php else: ?><div class="empty-note"><?=t($t,'no_capacity_scores','No Capacity Area scores are available for the selected filters.')?></div><?php endif; ?>
      <div class="benchmark-note"><span><i class="dot red"></i>&lt;60 Critical</span><span><i class="dot orange"></i>60–69 High</span><span><i class="dot yellow"></i>70–79 Develop</span><span><i class="dot green"></i>80–84 On target</span><span><i class="dot darkgreen"></i>85–100 Strategic</span></div>
    </section>

    <section class="capacity-card heatmap-card">
      <h2>C. <?=t($t, 'capacity_heatmap_directorate', 'Capacity Area Heatmap – by Directorate')?> (<?=$filters['year']?>)</h2>
      <div id="capacityHeatmap"></div>
      <p class="capacity-footnote"><?=t($t,'capacity_heatmap_note','Cells show actual average Capacity Area score; the directorate filter is intentionally ignored here so leaders can compare directorates side by side.')?></p>
    </section>

    <section class="capacity-card learning-card">
      <h2>D. <?=t($t, 'gap_details_learning', 'Gap Details & Recommended Learning')?></h2>
      <form method="get" class="capacity-selector no-print">
        <?php foreach ($filterQuery as $key=>$value): ?><input type="hidden" name="<?=htmlspecialchars((string)$key,ENT_QUOTES,'UTF-8')?>" value="<?=htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8')?>"><?php endforeach; ?>
        <label><?=t($t,'capacity_area','Capacity Area')?> <select name="capacity" onchange="this.form.submit()">
          <?php foreach ($capacityRows as $row): ?><option value="<?=htmlspecialchars((string)$row['label'],ENT_QUOTES,'UTF-8')?>" <?=$selectedCapacity===(string)$row['label']?'selected':''?>><?=htmlspecialchars((string)$row['label'],ENT_QUOTES,'UTF-8')?></option><?php endforeach; ?>
        </select></label>
        <?php if ($selectedCapacityRow): ?><span><?=t($t,'average_score','Average Score')?>: <strong><?=number_format((float)$selectedCapacityRow['average_score'],1)?>%</strong></span><span><?=t($t,'gap_to_target','Gap to Target')?>: <strong class="gap-negative"><?=sprintf('%+.1f',(float)$selectedCapacityRow['gap'])?> pts</strong></span><?php endif; ?>
      </form>
      <?php if ($courseMatches): ?><div class="learning-list">
        <?php foreach ($courseMatches as $course): ?>
          <div class="learning-row"><div><strong><?=htmlspecialchars((string)$course['title'],ENT_QUOTES,'UTF-8')?></strong><br><small><?=htmlspecialchars((string)($course['code']??''),ENT_QUOTES,'UTF-8')?></small></div><div><?=htmlspecialchars((string)($course['mode_of_delivery']??'eLearning'),ENT_QUOTES,'UTF-8')?></div><div><?=htmlspecialchars((string)($course['duration']??'—'),ENT_QUOTES,'UTF-8')?></div><div><small><?=htmlspecialchars((string)($course['expected_competency']??$course['course_objective']??''),ENT_QUOTES,'UTF-8')?></small></div><div class="no-print"><?php if (!empty($course['moodle_url'])): ?><a class="md-button md-outline" href="<?=htmlspecialchars((string)$course['moodle_url'],ENT_QUOTES,'UTF-8')?>" target="_blank" rel="noopener"><?=t($t,'view','View')?></a><?php endif; ?></div></div>
        <?php endforeach; ?>
      </div><?php else: ?><div class="empty-note"><?=t($t,'no_explicit_learning_mapping','No active course has an explicit thematic-area or expected-competency match for this Capacity Area yet. Add the mapping in Learning Recommendations rather than showing an inferred course.')?></div><?php endif; ?>
      <p class="capacity-footnote"><?=t($t,'learning_mapping_method','Recommendations are shown only when course catalogue metadata explicitly matches the selected Capacity Area and score band.')?></p>
    </section>

    <section class="capacity-card staff-card">
      <h2>E. <?=t($t,'staff_below_benchmark','Staff Below Benchmark')?><?= $selectedCapacity!=='' ? ' – '.htmlspecialchars($selectedCapacity,ENT_QUOTES,'UTF-8') : '' ?></h2>
      <?php if ($staffBelow): ?><table class="capacity-table"><thead><tr><th>#</th><th><?=t($t,'staff_member','Staff Member')?></th><th><?=t($t,'cadre','Team')?></th><th><?=t($t,'latest_score','Latest Score')?></th><th><?=t($t,'gap','Gap')?></th></tr></thead><tbody>
        <?php foreach (array_slice($staffBelow,0,12) as $index=>$person): $name=trim((string)($person['full_name']??'')); if($name===''){$name=(string)($person['username']??'');} ?><tr><td><?=$index+1?></td><td><?=htmlspecialchars($name,ENT_QUOTES,'UTF-8')?></td><td><?=htmlspecialchars(team_label($pdo,(string)($person['team']??'')),ENT_QUOTES,'UTF-8')?></td><td><span class="score-pill score-critical"><?=number_format((float)$person['score'],1)?>%</span></td><td class="gap-negative"><?=sprintf('%+.1f',(float)$person['gap'])?></td></tr><?php endforeach; ?>
      </tbody></table><?php else: ?><div class="empty-note"><?=t($t,'no_staff_below_benchmark','No staff are below the benchmark for this Capacity Area under the selected filters.')?></div><?php endif; ?>
    </section>
  </div>

  <p class="capacity-footnote"><?=t($t,'capacity_page_methodology','Method: actual scores use the latest completed Submitted / Approved / Approved Late assessment per employee. Capacity Areas currently correspond to scored questionnaire sections.')?></p>
</main>
<?php include __DIR__ . '/../templates/footer.php'; ?>
<script src="<?=htmlspecialchars($apexUrl,ENT_QUOTES,'UTF-8')?>"></script>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
(function(){
  const trendYears = <?=json_encode($trendYears, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
  const trendScores = <?=json_encode($trendScores, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
  const trendAttainment = <?=json_encode($trendAttainment, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
  const heatmap = <?=json_encode($heatmap, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
  const blue = '#1565c0';
  const green = '#2e7d32';

  function renderCharts(){
    if (typeof ApexCharts !== 'function') return;
    const trendNode = document.querySelector('#annualTrend');
    if (trendNode) {
      const trendChart = new ApexCharts(trendNode, {
        chart:{type:'line',height:330,toolbar:{show:true,tools:{download:true}},animations:{enabled:false}},
        series:[
          {name:'Average Competency Score',data:trendScores},
          {name:'80% Target Attainment',data:trendAttainment}
        ],
        colors:[blue,green],
        stroke:{curve:'straight',width:[4,3],dashArray:[0,6]},
        markers:{size:5,strokeWidth:0},
        xaxis:{categories:trendYears,title:{text:'Assessment Year'}},
        yaxis:{min:0,max:100,tickAmount:5,labels:{formatter:(v)=>Math.round(v)+'%'},title:{text:'Percent (%)'}},
        dataLabels:{enabled:true,formatter:(v)=>v===null||typeof v==='undefined'?'':Number(v).toFixed(1)+'%',offsetY:-8,style:{fontSize:'11px'}},
        annotations:{yaxis:[{y:80,borderColor:'#78909c',strokeDashArray:4,label:{text:'80% benchmark',style:{fontSize:'10px'}}}]},
        legend:{position:'bottom'},
        tooltip:{shared:true,y:{formatter:(v)=>v===null||typeof v==='undefined'?'No assessment data':Number(v).toFixed(1)+'%'}},
        noData:{text:'No annual assessment data for the selected filters.'}
      });
      trendChart.render();
      window.__casTrendChart = trendChart;
    }

    const heatNode = document.querySelector('#capacityHeatmap');
    if (heatNode && heatmap.capacities && heatmap.capacities.length && heatmap.departments && heatmap.departments.length) {
      const series = heatmap.capacities.map((capacity)=>({
        name:capacity,
        data:heatmap.departments.map((department)=>({x:department,y:(heatmap.matrix[capacity]&&typeof heatmap.matrix[capacity][department]!=='undefined')?heatmap.matrix[capacity][department]:null}))
      }));
      const heatChart = new ApexCharts(heatNode, {
        chart:{type:'heatmap',height:Math.max(320,heatmap.capacities.length*52),toolbar:{show:true,tools:{download:true}},animations:{enabled:false}},
        series:series,
        dataLabels:{enabled:true,formatter:(v)=>v===null||typeof v==='undefined'?'—':Number(v).toFixed(0)+'%'},
        plotOptions:{heatmap:{enableShades:false,colorScale:{ranges:[
          {from:0,to:49.99,color:'#ef9a9a',name:'Below Basics'},
          {from:50,to:59.99,color:'#ffccbc',name:'Introductory'},
          {from:60,to:69.99,color:'#ffe0b2',name:'Essential'},
          {from:70,to:79.99,color:'#fff59d',name:'Develop'},
          {from:80,to:84.99,color:'#c8e6c9',name:'On target'},
          {from:85,to:100,color:'#81c784',name:'Strategic'}
        ]}}},
        xaxis:{type:'category'},
        legend:{show:false},
        tooltip:{y:{formatter:(v)=>v===null||typeof v==='undefined'?'No data':Number(v).toFixed(1)+'%'}}
      });
      heatChart.render();
      window.__casCapacityHeatmap = heatChart;
    } else if (heatNode) {
      heatNode.innerHTML = '<div class="empty-note">No directorate Capacity Area scores are available for the selected filters.</div>';
    }
  }

  document.querySelector('[data-print-dashboard]')?.addEventListener('click',()=>{
    const charts=[window.__casTrendChart,window.__casCapacityHeatmap].filter(Boolean);
    Promise.all(charts.map((chart)=>chart.updateOptions({chart:{animations:{enabled:false}}},false,false))).finally(()=>window.print());
  });
  renderCharts();
})();
</script>
</body>
</html>
