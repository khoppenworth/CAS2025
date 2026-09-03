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

$currentRows = analytics_capacity_latest_per_employee(
    analytics_capacity_fetch_response_rows($pdo, $filters, true, true)
);
$overallAverage = analytics_capacity_average($currentRows);
$overallAttainment = analytics_capacity_attainment($currentRows);
$trend = analytics_capacity_annual_trend($pdo, $filters, 2026, 2028);

$directorates = [];
foreach ($currentRows as $row) {
    $key = trim((string)($row['department'] ?? ''));
    $key = $key !== '' ? $key : 'Unknown';
    $directorates[$key][] = $row;
}
$directorateRows = [];
foreach ($directorates as $name => $rows) {
    $attainment = analytics_capacity_attainment($rows);
    $directorateRows[] = [
        'name' => $departmentOptions[$name] ?? $name,
        'average_score' => analytics_capacity_average($rows),
        'attainment_percent' => $attainment['percent'],
        'target_hit' => $attainment['hit'],
        'staff_assessed' => $attainment['total'],
    ];
}
usort($directorateRows, static function (array $a, array $b): int {
    return (($b['average_score'] ?? -1) <=> ($a['average_score'] ?? -1));
});

$questionnaireFilters = $filters;
$questionnaireFilters['questionnaire_id'] = 0;
$questionnaireRowsAll = analytics_capacity_latest_per_employee(
    analytics_capacity_fetch_response_rows($pdo, $questionnaireFilters, true, true)
);
$questionnaireGroups = [];
foreach ($questionnaireRowsAll as $row) {
    $family = trim((string)($row['questionnaire_family_key'] ?? ''));
    if ($family === '') {
        continue;
    }
    $questionnaireGroups[$family]['title'] = (string)($row['title'] ?? 'Questionnaire');
    $questionnaireGroups[$family]['rows'][] = $row;
}
$questionnairePerformance = [];
foreach ($questionnaireGroups as $group) {
    $attainment = analytics_capacity_attainment($group['rows'] ?? []);
    $questionnairePerformance[] = [
        'title' => (string)($group['title'] ?? 'Questionnaire'),
        'average_score' => analytics_capacity_average($group['rows'] ?? []),
        'attainment_percent' => $attainment['percent'],
        'staff_assessed' => $attainment['total'],
    ];
}
usort($questionnairePerformance, static function (array $a, array $b): int {
    return (($b['average_score'] ?? -1) <=> ($a['average_score'] ?? -1));
});

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

$trendYears = array_column($trend, 'year');
$trendScores = array_column($trend, 'average_score');
$trendAttainment = array_column($trend, 'attainment_percent');
$firstScore = null;
$lastScore = null;
$firstAttainment = null;
$lastAttainment = null;
foreach ($trend as $row) {
    if ($row['average_score'] !== null) {
        $firstScore ??= (float)$row['average_score'];
        $lastScore = (float)$row['average_score'];
    }
    if ($row['attainment_percent'] !== null) {
        $firstAttainment ??= (float)$row['attainment_percent'];
        $lastAttainment = (float)$row['attainment_percent'];
    }
}
$scoreChange = ($firstScore !== null && $lastScore !== null) ? round($lastScore - $firstScore, 1) : null;
$attainmentChange = ($firstAttainment !== null && $lastAttainment !== null) ? round($lastAttainment - $firstAttainment, 1) : null;

$scoreClass = static function (?float $score): string {
    if ($score === null) return 'score-empty';
    if ($score >= 80) return 'score-good';
    if ($score >= 70) return 'score-mid';
    if ($score >= 60) return 'score-low';
    return 'score-critical';
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
  <title><?=htmlspecialchars(t($t, 'analytics_overview', 'Analytics Overview'), ENT_QUOTES, 'UTF-8')?></title>
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
  <style nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
    :root { --analytics-blue:#1565c0; --analytics-green:#2e7d32; --analytics-red:#c62828; --analytics-orange:#ef6c00; --analytics-yellow:#f9a825; }
    .analytics-v2-page { max-width:1500px; margin:0 auto; padding:1rem; }
    .analytics-heading { display:flex; justify-content:space-between; gap:1rem; align-items:flex-start; margin-bottom:.75rem; }
    .analytics-heading h1 { margin:0; color:#10233f; font-size:1.65rem; }
    .analytics-heading p { margin:.3rem 0 0; color:#647084; }
    .analytics-actions { display:flex; gap:.5rem; flex-wrap:wrap; }
    .analytics-tabs { display:flex; gap:.25rem; border-bottom:1px solid #d9e1ec; margin-bottom:1rem; overflow-x:auto; }
    .analytics-tabs a { padding:.75rem .95rem; text-decoration:none; color:#2d3748; border-bottom:3px solid transparent; white-space:nowrap; font-weight:600; }
    .analytics-tabs a.is-active { color:var(--analytics-blue); border-bottom-color:var(--analytics-blue); }
    .analytics-filters { display:grid; grid-template-columns:repeat(5,minmax(150px,1fr)) auto; gap:.65rem; align-items:end; margin-bottom:1rem; }
    .analytics-filters label { display:flex; flex-direction:column; gap:.3rem; font-size:.82rem; font-weight:600; color:#4a5568; }
    .analytics-filters select,.analytics-filters input { width:100%; }
    .kpi-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.8rem; margin-bottom:1rem; }
    .kpi-card { background:#fff; border:1px solid #dfe6ef; border-radius:12px; box-shadow:0 2px 8px rgba(15,35,60,.06); padding:1rem; }
    .kpi-card small { display:block; color:#6a7484; font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; }
    .kpi-card strong { display:block; margin-top:.2rem; font-size:2rem; color:#14345c; }
    .kpi-card .green { color:var(--analytics-green); }
    .kpi-card .blue { color:var(--analytics-blue); }
    .kpi-card span { color:#647084; font-size:.82rem; }
    .analytics-grid { display:grid; grid-template-columns:repeat(12,minmax(0,1fr)); gap:1rem; }
    .analytics-card { background:#fff; border:1px solid #dfe6ef; border-radius:12px; box-shadow:0 2px 8px rgba(15,35,60,.06); padding:1rem; min-width:0; break-inside:avoid; }
    .analytics-card h2 { margin:0 0 .3rem; font-size:1.02rem; color:#0d47a1; }
    .analytics-card p.meta { margin:.1rem 0 .75rem; color:#667386; font-size:.83rem; }
    .trend-card { grid-column:1/-1; }
    .trend-layout { display:grid; grid-template-columns:minmax(0,1fr) 190px; gap:1rem; }
    #overviewTrend { min-height:360px; }
    .trend-side { display:flex; flex-direction:column; gap:.8rem; justify-content:center; }
    .trend-stat { border:2px solid #d7e4f6; border-radius:12px; padding:1rem .6rem; text-align:center; }
    .trend-stat strong { display:block; font-size:1.65rem; color:var(--analytics-blue); }
    .trend-stat.green { border-color:#c9e6ce; }
    .trend-stat.green strong { color:var(--analytics-green); }
    .directorate-card { grid-column:span 7; }
    .questionnaire-card { grid-column:span 5; }
    .analytics-table { width:100%; border-collapse:collapse; font-size:.88rem; }
    .analytics-table th,.analytics-table td { padding:.58rem .5rem; border-bottom:1px solid #e7edf4; text-align:left; }
    .analytics-table th { font-size:.76rem; background:#f8fafc; color:#546173; }
    .score-pill { display:inline-flex; min-width:58px; justify-content:center; padding:.22rem .45rem; border-radius:6px; font-weight:700; }
    .score-good { background:#e8f5e9; color:#1b5e20; }.score-mid{background:#fff8e1;color:#8d6e00}.score-low{background:#fff3e0;color:#e65100}.score-critical{background:#ffebee;color:#b71c1c}.score-empty{background:#f3f4f6;color:#6b7280}
    .dual-metric { display:flex; gap:.35rem; flex-wrap:wrap; align-items:center; }
    .attainment { color:#2e7d32; font-weight:700; }
    .methodology-note { margin-top:1rem; border-left:4px solid #1565c0; background:#eef6ff; padding:.8rem 1rem; color:#42536a; font-size:.82rem; }
    @media (max-width:1050px){.analytics-filters{grid-template-columns:repeat(2,minmax(160px,1fr))}.kpi-grid{grid-template-columns:repeat(2,1fr)}.directorate-card,.questionnaire-card{grid-column:1/-1}.trend-layout{grid-template-columns:1fr}.trend-side{display:grid;grid-template-columns:repeat(2,1fr)}}
    @page { size:A4 landscape; margin:9mm; }
    @media print {
      body{background:#fff!important;font-size:9pt} header,footer,.no-print,.analytics-tabs,.analytics-filters{display:none!important}.analytics-v2-page{max-width:none;padding:0}.analytics-heading{margin-bottom:4mm}.analytics-heading h1{font-size:16pt}.kpi-grid{grid-template-columns:repeat(4,1fr);gap:3mm}.kpi-card{box-shadow:none;border:1px solid #bdc7d3;padding:3mm}.analytics-grid{display:block}.analytics-card{box-shadow:none;border:1px solid #bdc7d3;margin-bottom:5mm;padding:4mm;page-break-inside:avoid}.trend-card{page-break-after:always}.trend-layout{grid-template-columns:minmax(0,1fr) 38mm}#overviewTrend{min-height:120mm}.methodology-note{page-break-inside:avoid}a{color:#000;text-decoration:none}
    }
  </style>
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__ . '/../templates/header.php'; ?>
<main class="analytics-v2-page">
  <div class="analytics-heading">
    <div>
      <h1><?=t($t, 'analytics_overview', 'Analytics Overview')?></h1>
      <p><?=htmlspecialchars($selectedQuestionnaireTitle !== '' ? $selectedQuestionnaireTitle : t($t, 'questionnaire', 'Questionnaire'), ENT_QUOTES, 'UTF-8')?> · <?=htmlspecialchars((string)$filters['year'], ENT_QUOTES, 'UTF-8')?></p>
    </div>
    <div class="analytics-actions no-print">
      <button type="button" class="md-button md-outline" data-print-dashboard><?=t($t, 'print_a4', 'Print / Save PDF')?></button>
      <a class="md-button md-outline" href="<?=htmlspecialchars(url_for('admin/analytics_data_viewer_export.php') . '?' . http_build_query($filterQuery), ENT_QUOTES, 'UTF-8')?>"><?=t($t, 'export_csv', 'Export CSV')?></a>
    </div>
  </div>

  <nav class="analytics-tabs no-print" aria-label="<?=htmlspecialchars(t($t, 'analytics_tabs', 'Analytics sections'), ENT_QUOTES, 'UTF-8')?>">
    <a class="is-active" href="<?=htmlspecialchars(url_for('admin/analytics_dashboard.php') . '?' . http_build_query($filterQuery), ENT_QUOTES, 'UTF-8')?>">1. <?=t($t, 'analytics_overview', 'Overview')?></a>
    <a href="<?=htmlspecialchars(url_for('admin/analytics_capacity.php') . '?' . http_build_query($filterQuery), ENT_QUOTES, 'UTF-8')?>">2. <?=t($t, 'capacity_areas_gaps_short', 'Capacity Areas & Gaps')?></a>
    <a href="<?=htmlspecialchars(url_for('admin/analytics.php?view=drilldown&questionnaire_id=' . (int)$filters['questionnaire_id']), ENT_QUOTES, 'UTF-8')?>">3. <?=t($t, 'questionnaire_analysis', 'Questionnaire Analysis')?></a>
    <a href="<?=htmlspecialchars(url_for('admin/course_mappings.php'), ENT_QUOTES, 'UTF-8')?>">4. <?=t($t, 'learning_recommendations', 'Learning Recommendations')?></a>
    <a href="<?=htmlspecialchars(url_for('admin/analytics_data_viewer.php') . '?' . http_build_query($filterQuery), ENT_QUOTES, 'UTF-8')?>">5. <?=t($t, 'analytics_report_explorer_title', 'Report Explorer')?></a>
  </nav>

  <form method="get" class="analytics-filters no-print">
    <label><?=t($t, 'year', 'Assessment Year')?><input type="number" name="year" min="2000" max="2100" value="<?=htmlspecialchars((string)$filters['year'], ENT_QUOTES, 'UTF-8')?>"></label>
    <label><?=t($t, 'questionnaire', 'Questionnaire')?><select name="questionnaire_id"><?php foreach ($questionnaires as $q): $qid=(int)($q['id']??0); ?><option value="<?=$qid?>" <?=$filters['questionnaire_id']===$qid?'selected':''?>><?=htmlspecialchars((string)($q['title']??''), ENT_QUOTES, 'UTF-8')?></option><?php endforeach; ?></select></label>
    <label><?=t($t, 'department', 'Directorate')?><select name="department"><option value=""><?=t($t, 'all_departments', 'All directorates')?></option><?php foreach ($departmentOptions as $value=>$label): ?><option value="<?=htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8')?>" <?=$filters['department']===(string)$value?'selected':''?>><?=htmlspecialchars((string)$label,ENT_QUOTES,'UTF-8')?></option><?php endforeach; ?></select></label>
    <label><?=t($t, 'cadre', 'Team')?><select name="team"><option value=""><?=t($t, 'all_teams', 'All teams')?></option><?php foreach ($teamOptions as $value=>$label): ?><option value="<?=htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8')?>" <?=$filters['team']===(string)$value?'selected':''?>><?=htmlspecialchars((string)$label,ENT_QUOTES,'UTF-8')?></option><?php endforeach; ?></select></label>
    <label><?=t($t, 'work_function', 'Work Role')?><select name="work_function"><option value=""><?=t($t, 'all_work_roles', 'All work roles')?></option><?php foreach ($workFunctionOptions as $value=>$label): ?><option value="<?=htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8')?>" <?=$filters['work_function']===(string)$value?'selected':''?>><?=htmlspecialchars((string)$label,ENT_QUOTES,'UTF-8')?></option><?php endforeach; ?></select></label>
    <button class="md-button md-primary" type="submit"><?=t($t, 'apply_filters', 'Apply filters')?></button>
  </form>

  <section class="kpi-grid">
    <div class="kpi-card"><small><?=t($t, 'average_score', 'Average Competency Score')?></small><strong class="blue"><?=$overallAverage !== null ? number_format($overallAverage,1).'%' : '—'?></strong><span><?=t($t, 'latest_completed_per_staff', 'Latest completed assessment per staff member')?></span></div>
    <div class="kpi-card"><small><?=t($t, 'epss_kpi_title', 'Staff Meeting ≥80% Target')?></small><strong class="green"><?=$overallAttainment['percent'] !== null ? number_format((float)$overallAttainment['percent'],1).'%' : '—'?></strong><span><?=sprintf('%d / %d staff', (int)$overallAttainment['hit'], (int)$overallAttainment['total'])?></span></div>
    <div class="kpi-card"><small><?=t($t, 'unique_participants', 'Staff Assessed')?></small><strong><?=number_format((int)$overallAttainment['total'])?></strong><span><?=t($t, 'active_filter_population', 'Active filter population')?></span></div>
    <div class="kpi-card"><small><?=t($t, 'proficiency_level', 'Competency Level')?></small><strong><?=htmlspecialchars(questionnaire_competency_level($overallAverage) ?: '—', ENT_QUOTES, 'UTF-8')?></strong><span><?=t($t, 'based_on_average_score', 'Based on the average score')?></span></div>
  </section>

  <section class="analytics-grid">
    <article class="analytics-card trend-card">
      <h2><?=t($t, 'annual_competency_trend', 'Annual Competency Performance Trend')?></h2>
      <p class="meta"><?=t($t, 'annual_competency_trend_hint', 'Annual assessments only. Average score and ≥80% target attainment are shown as separate measures.')?></p>
      <div class="trend-layout">
        <div id="overviewTrend" aria-label="<?=htmlspecialchars(t($t,'annual_competency_trend','Annual Competency Performance Trend'),ENT_QUOTES,'UTF-8')?>"></div>
        <aside class="trend-side">
          <div class="trend-stat"><span><?=t($t, 'average_score_change', 'Average Score Change')?></span><strong><?=$scoreChange !== null ? (($scoreChange>=0?'+':'').number_format($scoreChange,1).' pts') : '—'?></strong><small><?=t($t,'first_to_latest_available','First to latest available year')?></small></div>
          <div class="trend-stat green"><span><?=t($t, 'target_attainment_change', '≥80% Target Change')?></span><strong><?=$attainmentChange !== null ? (($attainmentChange>=0?'+':'').number_format($attainmentChange,1).' pts') : '—'?></strong><small><?=t($t,'first_to_latest_available','First to latest available year')?></small></div>
        </aside>
      </div>
    </article>

    <article class="analytics-card directorate-card">
      <h2><?=t($t, 'average_score_by_directorate', 'Average Score by Directorate')?></h2>
      <p class="meta"><?=t($t, 'directorate_score_hint', 'Primary performance measure is the actual average score; ≥80% attainment is shown separately.')?></p>
      <?php if ($directorateRows): ?>
      <table class="analytics-table">
        <thead><tr><th><?=t($t,'department','Directorate')?></th><th><?=t($t,'average_score','Average Score')?></th><th><?=t($t,'proficiency_level','Competency Level')?></th><th><?=t($t,'epss_kpi_label','≥80% Target')?></th><th><?=t($t,'staff','Staff')?></th></tr></thead>
        <tbody><?php foreach ($directorateRows as $row): $avg=$row['average_score']; ?><tr><td><?=htmlspecialchars((string)$row['name'],ENT_QUOTES,'UTF-8')?></td><td><span class="score-pill <?=$scoreClass($avg)?>"><?=$avg!==null?number_format((float)$avg,1).'%':'—'?></span></td><td><?=htmlspecialchars(questionnaire_competency_level($avg) ?: '—',ENT_QUOTES,'UTF-8')?></td><td><span class="attainment"><?=$row['attainment_percent']!==null?number_format((float)$row['attainment_percent'],1).'%':'—'?></span><br><small><?=sprintf('%d/%d', (int)$row['target_hit'], (int)$row['staff_assessed'])?></small></td><td><?=number_format((int)$row['staff_assessed'])?></td></tr><?php endforeach; ?></tbody>
      </table>
      <?php else: ?><p class="meta"><?=t($t,'no_data','No matching completed assessments.')?></p><?php endif; ?>
    </article>

    <article class="analytics-card questionnaire-card">
      <h2><?=t($t, 'questionnaire_performance', 'Questionnaire Performance')?></h2>
      <p class="meta"><?=t($t, 'questionnaire_score_hint', 'Questionnaires remain separate; scores are not blended into one cross-questionnaire average.')?></p>
      <?php if ($questionnairePerformance): ?>
      <table class="analytics-table">
        <thead><tr><th><?=t($t,'questionnaire','Questionnaire')?></th><th><?=t($t,'average_score','Avg Score')?></th><th><?=t($t,'epss_kpi_label','≥80%')?></th><th><?=t($t,'staff','Staff')?></th></tr></thead>
        <tbody><?php foreach ($questionnairePerformance as $row): $avg=$row['average_score']; ?><tr><td><?=htmlspecialchars((string)$row['title'],ENT_QUOTES,'UTF-8')?></td><td><span class="score-pill <?=$scoreClass($avg)?>"><?=$avg!==null?number_format((float)$avg,1).'%':'—'?></span></td><td class="attainment"><?=$row['attainment_percent']!==null?number_format((float)$row['attainment_percent'],1).'%':'—'?></td><td><?=number_format((int)$row['staff_assessed'])?></td></tr><?php endforeach; ?></tbody>
      </table>
      <?php else: ?><p class="meta"><?=t($t,'no_data','No matching completed assessments.')?></p><?php endif; ?>
    </article>
  </section>

  <aside class="methodology-note">
    <strong><?=t($t, 'methodology', 'Methodology')?>:</strong>
    <?=t($t, 'analytics_v2_methodology', 'Only submitted, approved, and approved-late responses are eligible. Repeated attempts do not multiply a staff member’s weight: the latest completed response per employee, questionnaire family, and assessment year is used. Average score and the percentage of staff meeting the 80% target are separate indicators.')?>
  </aside>
</main>
<?php include __DIR__ . '/../templates/footer.php'; ?>
<script src="<?=htmlspecialchars($apexUrl, ENT_QUOTES, 'UTF-8')?>" defer></script>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
(function(){
  const years = <?=json_encode($trendYears, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
  const scores = <?=json_encode($trendScores, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
  const attainment = <?=json_encode($trendAttainment, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
  const render = function(){
    if (typeof ApexCharts === 'undefined') { window.setTimeout(render, 60); return; }
    const target = document.querySelector('#overviewTrend');
    if (!target) return;
    const chart = new ApexCharts(target, {
      chart:{type:'line',height:360,toolbar:{show:true,tools:{download:true,selection:false,zoom:false,zoomin:false,zoomout:false,pan:false,reset:false}},animations:{enabled:false},fontFamily:'Segoe UI, system-ui, sans-serif'},
      series:[{name:'Average Competency Score',data:scores},{name:'Staff Meeting ≥80% Target',data:attainment}],
      xaxis:{categories:years,title:{text:'Assessment year'}},
      yaxis:{min:0,max:100,tickAmount:5,labels:{formatter:function(v){return Math.round(v)+'%';}},title:{text:'Percent'}},
      stroke:{curve:'smooth',width:[4,4]},markers:{size:6,strokeWidth:2},
      dataLabels:{enabled:true,formatter:function(v){return v===null||typeof v==='undefined'?'':Number(v).toFixed(1)+'%';}},
      tooltip:{shared:true,intersect:false,y:{formatter:function(v){return v===null||typeof v==='undefined'?'No assessment data':Number(v).toFixed(1)+'%';}}},
      legend:{position:'top',horizontalAlign:'left'},
      annotations:{yaxis:[{y:80,borderColor:'#2e7d32',strokeDashArray:5,label:{text:'80% benchmark',style:{background:'#2e7d32',color:'#fff'}}}]},
      grid:{borderColor:'#e3e8ef'}
    });
    chart.render();
    window.addEventListener('beforeprint', function(){ chart.updateOptions({chart:{height:430,toolbar:{show:false}},dataLabels:{enabled:true}},false,false); });
    window.addEventListener('afterprint', function(){ chart.updateOptions({chart:{height:360,toolbar:{show:true}}},false,false); });
  };
  document.querySelector('[data-print-dashboard]')?.addEventListener('click',function(){window.print();});
  if (document.readyState==='loading') document.addEventListener('DOMContentLoaded',render); else render();
})();
</script>
</body>
</html>
