<?php
require_once __DIR__ . '/../config.php';
auth_required(['admin', 'supervisor']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$user = current_user();
$role = $user['role'] ?? ($_SESSION['user']['role'] ?? null);
$department = trim((string)($user['department'] ?? ''));

$responseId = (int)($_GET['id'] ?? 0);
$notFound = false;
$response = null;
$questionnaireItems = [];
$answerMap = [];

if ($responseId > 0) {
    $baseSql = 'SELECT qr.*, u.username, u.full_name, u.email, q.title AS questionnaire_title, pp.label AS period_label '
        . 'FROM questionnaire_response qr '
        . 'JOIN users u ON u.id = qr.user_id '
        . 'JOIN questionnaire q ON q.id = qr.questionnaire_id '
        . 'LEFT JOIN performance_period pp ON pp.id = qr.performance_period_id ';

    if ($role === 'supervisor') {
        if ($department === '') {
            $stmt = null;
            $response = false;
        } else {
            $stmt = $pdo->prepare($baseSql . 'WHERE qr.id = ? AND u.department = ?');
            $stmt->execute([$responseId, $department]);
        }
    } else {
        $stmt = $pdo->prepare($baseSql . 'WHERE qr.id = ?');
        $stmt->execute([$responseId]);
    }
    if ($stmt instanceof PDOStatement) {
        $response = $stmt->fetch();
    }

    if ($response) {
        $itemStmt = $pdo->prepare(
            'SELECT id, linkId, text, type, order_index '
            . 'FROM questionnaire_item '
            . 'WHERE questionnaire_id = ? '
            . 'ORDER BY order_index ASC, id ASC'
        );
        $itemStmt->execute([(int)$response['questionnaire_id']]);
        $questionnaireItems = $itemStmt->fetchAll();

        $answerStmt = $pdo->prepare('SELECT linkId, answer FROM questionnaire_response_item WHERE response_id = ?');
        $answerStmt->execute([$responseId]);
        foreach ($answerStmt->fetchAll() as $row) {
            $linkId = (string)$row['linkId'];
            $decoded = json_decode((string)$row['answer'], true);
            if (!is_array($decoded)) {
                $decoded = [];
            }
            $answerMap[$linkId] = $decoded;
        }
    } else {
        $notFound = true;
        http_response_code(404);
    }
} else {
    $notFound = true;
    http_response_code(404);
}

$statusLabels = [
    'draft' => t($t, 'status_draft', 'Draft'),
    'submitted' => t($t, 'status_submitted', 'Submitted'),
    'approved' => t($t, 'status_approved', 'Approved'),
    'rejected' => t($t, 'status_rejected', 'Rejected'),
];

$formatAnswerEntry = static function (array $entry) use ($t): string {
    if (array_key_exists('valueString', $entry)) {
        return (string)$entry['valueString'];
    }
    if (array_key_exists('valueInteger', $entry)) {
        return (string)$entry['valueInteger'];
    }
    if (array_key_exists('valueDecimal', $entry)) {
        $value = (string)$entry['valueDecimal'];
        return rtrim(rtrim($value, '0'), '.') ?: $value;
    }
    if (array_key_exists('valueBoolean', $entry)) {
        return !empty($entry['valueBoolean']) ? t($t, 'yes', 'Yes') : t($t, 'no', 'No');
    }
    if (array_key_exists('valueDate', $entry)) {
        return (string)$entry['valueDate'];
    }
    if (array_key_exists('valueDateTime', $entry)) {
        return (string)$entry['valueDateTime'];
    }
    if (array_key_exists('valueTime', $entry)) {
        return (string)$entry['valueTime'];
    }
    if (array_key_exists('valueCoding', $entry) && is_array($entry['valueCoding'])) {
        $coding = $entry['valueCoding'];
        $display = trim((string)($coding['display'] ?? ''));
        $code = trim((string)($coding['code'] ?? ''));
        return $display !== '' ? $display : $code;
    }
    if (array_key_exists('valueQuantity', $entry) && is_array($entry['valueQuantity'])) {
        $quantity = $entry['valueQuantity'];
        $value = isset($quantity['value']) ? (string)$quantity['value'] : '';
        $unit = isset($quantity['unit']) ? trim((string)$quantity['unit']) : '';
        return trim($value . ' ' . $unit);
    }
    if (array_key_exists('valueAttachment', $entry) && is_array($entry['valueAttachment'])) {
        $attachment = $entry['valueAttachment'];
        $title = trim((string)($attachment['title'] ?? ''));
        $url = trim((string)($attachment['url'] ?? ''));
        if ($title && $url) {
            return $title . ' (' . $url . ')';
        }
        return $title ?: $url;
    }

    return trim(json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
};

$formatAnswerSet = static function (array $entries) use ($formatAnswerEntry): array {
    $values = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $value = $formatAnswerEntry($entry);
        if ($value !== '') {
            $values[] = $value;
        }
    }
    return $values;
};

$renderAnswerHtml = static function (array $values): string {
    if (!$values) {
        return '—';
    }
    $parts = [];
    foreach ($values as $value) {
        $parts[] = nl2br(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
    }
    return implode('<br>', $parts);
};
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars(t($t, 'submission_detail', 'Submission Detail'), ENT_QUOTES, 'UTF-8')?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
  <link rel="manifest" href="<?=asset_url('manifest.php')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
  <style nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
    .md-response-meta {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 0.75rem;
      margin: 1rem 0;
    }
    .md-response-meta-item {
      padding: 0.75rem;
      border-radius: 6px;
      background: var(--app-surface-alt, #f5f7fa);
    }
    .md-answer-list {
      list-style: none;
      padding: 0;
      margin: 0;
    }
    .md-answer-list li {
      border-bottom: 1px solid rgba(0, 0, 0, 0.08);
      padding: 0.75rem 0;
    }
    .md-answer-question {
      font-weight: 600;
      margin-bottom: 0.35rem;
    }
    .md-answer-value {
      color: var(--app-text-secondary, #444);
      word-break: break-word;
    }
  </style>
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__ . '/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title">
      <?=htmlspecialchars(t($t, 'submission_detail', 'Submission Detail'), ENT_QUOTES, 'UTF-8')?>
    </h2>
    <?php if ($notFound): ?>
      <p class="md-upgrade-meta"><?=t($t, 'submission_not_found', 'The requested submission could not be found.')?></p>
      <p><a class="md-button" href="<?=htmlspecialchars(url_for('admin/supervisor_review.php'), ENT_QUOTES, 'UTF-8')?>">&larr; <?=t($t, 'back_to_pending', 'Back to pending submissions')?></a></p>
    <?php else: ?>
      <div class="md-response-meta">
        <div class="md-response-meta-item">
          <strong><?=t($t, 'user', 'User')?>:</strong><br>
          <?=htmlspecialchars($response['username'] ?? '', ENT_QUOTES, 'UTF-8')?>
          <?php if (!empty($response['full_name'])): ?>
            <br><span class="md-muted"><?=htmlspecialchars($response['full_name'], ENT_QUOTES, 'UTF-8')?></span>
          <?php endif; ?>
        </div>
        <div class="md-response-meta-item">
          <strong><?=t($t, 'questionnaire', 'Questionnaire')?>:</strong><br>
          <?=htmlspecialchars($response['questionnaire_title'] ?? '', ENT_QUOTES, 'UTF-8')?>
          <?php if (!empty($response['period_label'])): ?>
            <br><span class="md-muted"><?=htmlspecialchars($response['period_label'], ENT_QUOTES, 'UTF-8')?></span>
          <?php endif; ?>
        </div>
        <div class="md-response-meta-item">
          <strong><?=t($t, 'status', 'Status')?>:</strong><br>
          <?php
            $statusKey = $response['status'] ?? '';
            $statusLabel = $statusLabels[$statusKey] ?? ucfirst((string)$statusKey);
            echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8');
          ?>
          <?php if (!empty($response['created_at'])): ?>
            <br><span class="md-muted"><?=htmlspecialchars($response['created_at'], ENT_QUOTES, 'UTF-8')?></span>
          <?php endif; ?>
        </div>
        <div class="md-response-meta-item">
          <strong><?=t($t, 'score', 'Score (%)')?>:</strong><br>
          <?= isset($response['score']) && $response['score'] !== null ? (int)$response['score'] : '—' ?>
          <?php if (!empty($response['review_comment'])): ?>
            <br><span class="md-muted"><?=htmlspecialchars($response['review_comment'], ENT_QUOTES, 'UTF-8')?></span>
          <?php endif; ?>
        </div>
      </div>
      <p><a class="md-button" href="<?=htmlspecialchars(url_for('admin/supervisor_review.php'), ENT_QUOTES, 'UTF-8')?>">&larr; <?=t($t, 'back_to_pending', 'Back to pending submissions')?></a></p>
      <?php if ($questionnaireItems): ?>
        <ul class="md-answer-list">
          <?php foreach ($questionnaireItems as $item): ?>
            <?php
              $linkId = (string)$item['linkId'];
              $questionText = trim((string)($item['text'] ?? ''));
              if ($questionText === '') {
                  $questionText = $linkId !== '' ? $linkId : t($t, 'question', 'Question');
              }
              $answers = $answerMap[$linkId] ?? [];
              $formatted = $formatAnswerSet(is_array($answers) ? $answers : []);
              $answerHtml = $renderAnswerHtml($formatted);
            ?>
            <li>
              <div class="md-answer-question"><?=htmlspecialchars($questionText, ENT_QUOTES, 'UTF-8')?></div>
              <div class="md-answer-value"><?=$answerHtml?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="md-upgrade-meta"><?=t($t, 'no_questions_found', 'No questionnaire items were found for this submission.')?></p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
