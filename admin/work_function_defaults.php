<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/work_functions.php';

auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

$flashKey = 'work_function_defaults_flash';
$catalogFlashKey = 'work_function_catalog_flash';

$msg = $_SESSION[$flashKey] ?? '';
if ($msg !== '') {
    unset($_SESSION[$flashKey]);
}
$catalogMsg = $_SESSION[$catalogFlashKey] ?? '';
if ($catalogMsg !== '') {
    unset($_SESSION[$catalogFlashKey]);
}

$errors = [];
$catalogErrors = [];

$questionnaires = [];
$questionnaireMap = [];
try {
    $stmt = $pdo->query("SELECT id, title, description FROM questionnaire WHERE status='published' ORDER BY title ASC");
    if ($stmt) {
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $qid = isset($row['id']) ? (int)$row['id'] : 0;
            if ($qid <= 0) {
                continue;
            }
            $questionnaires[] = $row;
            $questionnaireMap[$qid] = $row;
        }
    }
} catch (PDOException $e) {
    error_log('work_function_defaults questionnaire fetch failed: ' . $e->getMessage());
}

$workFunctionOptions = available_work_functions($pdo);
$workFunctionKeys = array_keys($workFunctionOptions);
$assignmentsByWorkFunction = work_function_assignments($pdo);
foreach ($workFunctionKeys as $wf) {
    if (!isset($assignmentsByWorkFunction[$wf])) {
        $assignmentsByWorkFunction[$wf] = [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = isset($_POST['mode']) ? (string)$_POST['mode'] : 'assignments';
    if (in_array($mode, ['catalog_add', 'catalog_update', 'catalog_archive'], true)) {
        csrf_check();
        try {
            if ($mode === 'catalog_add') {
                $label = (string)($_POST['label'] ?? '');
                $slugInput = isset($_POST['slug']) ? (string)$_POST['slug'] : null;
                create_work_function($pdo, $label, $slugInput);
                $_SESSION[$catalogFlashKey] = t($t, 'work_function_catalog_created', 'Work function added.');
                header('Location: ' . url_for('admin/work_function_defaults.php'));
                exit;
            }
            if ($mode === 'catalog_update') {
                $slug = (string)($_POST['slug'] ?? '');
                $label = (string)($_POST['label'] ?? '');
                update_work_function_label($pdo, $slug, $label);
                $_SESSION[$catalogFlashKey] = t($t, 'work_function_catalog_updated', 'Work function updated.');
                header('Location: ' . url_for('admin/work_function_defaults.php'));
                exit;
            }
            if ($mode === 'catalog_archive') {
                $slug = (string)($_POST['slug'] ?? '');
                archive_work_function($pdo, $slug);
                $_SESSION[$catalogFlashKey] = t($t, 'work_function_catalog_archived', 'Work function archived.');
                header('Location: ' . url_for('admin/work_function_defaults.php'));
                exit;
            }
        } catch (InvalidArgumentException $e) {
            $catalogErrors[] = $e->getMessage();
        } catch (Throwable $e) {
            error_log('work_function_catalog action failed: ' . $e->getMessage());
            $catalogErrors[] = t($t, 'work_function_catalog_error', 'Unable to update work functions. Please try again.');
        }
    } else {
        csrf_check();
        $input = $_POST['assignments'] ?? [];
        $payloadJson = $_POST['assignments_payload'] ?? '';
        if (is_string($payloadJson) && $payloadJson !== '') {
            $decoded = json_decode($payloadJson, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $input = $decoded;
            } else {
                $errors[] = t(
                    $t,
                    'work_function_defaults_invalid_payload',
                    'The work function selections could not be processed. Please try again.'
                );
                $input = [];
            }
        }
        if (!is_array($input)) {
            $input = [];
        }
        $normalized = normalize_work_function_assignments(
            $input,
            $workFunctionKeys,
            array_keys($questionnaireMap)
        );
        $assignmentsByWorkFunction = $normalized;
        if ($errors === []) {
            try {
                save_work_function_assignments($pdo, $normalized);
                $_SESSION[$flashKey] = t($t, 'work_function_defaults_saved', 'Default questionnaire assignments updated.');
                header('Location: ' . url_for('admin/work_function_defaults.php'));
                exit;
            } catch (Throwable $e) {
                error_log('work_function_defaults save failed: ' . $e->getMessage());
                $errors[] = t($t, 'work_function_defaults_save_failed', 'Unable to save work function defaults. Please try again.');
            }
        }
    }
}

$workFunctionCatalog = work_function_catalog($pdo);
$hasActiveCatalog = false;
foreach ($workFunctionCatalog as $record) {
    if (($record['archived_at'] ?? null) === null) {
        $hasActiveCatalog = true;
        break;
    }
}

$staffCounts = [];
try {
    $stmt = $pdo->query("SELECT work_function, COUNT(*) AS c FROM users WHERE work_function IS NOT NULL AND work_function <> '' GROUP BY work_function");
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $wf = canonical_work_function_key((string)($row['work_function'] ?? ''));
            if ($wf !== '') {
                $staffCounts[$wf] = (int)($row['c'] ?? 0);
            }
        }
    }
} catch (PDOException $e) {
    error_log('work_function_defaults staff count failed: ' . $e->getMessage());
}

$assignmentCounts = [];
foreach ($assignmentsByWorkFunction as $wf => $ids) {
    $assignmentCounts[$wf] = is_array($ids) ? count($ids) : 0;
}

$baseUrl = htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8');
$pageTitle = htmlspecialchars(t($t, 'work_function_defaults_title', 'Work Function Defaults'), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=$baseUrl?>">
<head>
  <meta charset="utf-8">
  <title><?=$pageTitle?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-url" content="<?=$baseUrl?>">
  <link rel="manifest" href="<?=asset_url('manifest.php')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php $drawerKey = 'admin.work_function_defaults'; ?>
<?php include __DIR__ . '/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=$pageTitle?></h2>
    <p class="md-hint md-work-function-hint"><?=htmlspecialchars(t($t, 'work_function_defaults_hint', 'Choose the questionnaires that should be provided automatically to staff members based on their work function or cadre.'), ENT_QUOTES, 'UTF-8')?></p>
    <?php if ($catalogMsg !== ''): ?>
      <div class="md-alert success"><?=htmlspecialchars($catalogMsg, ENT_QUOTES, 'UTF-8')?></div>
    <?php endif; ?>
    <?php if ($catalogErrors): ?>
      <div class="md-alert error">
        <?php foreach ($catalogErrors as $error): ?>
          <p><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8')?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="md-work-function-manage">
      <h3 class="md-subhead"><?=htmlspecialchars(t($t, 'work_function_catalog_title', 'Manage Work Functions'), ENT_QUOTES, 'UTF-8')?></h3>
      <p class="md-hint"><?=htmlspecialchars(t($t, 'work_function_catalog_hint', 'Add, rename, or archive work functions to control questionnaire availability.'), ENT_QUOTES, 'UTF-8')?></p>
      <form method="post" class="md-work-function-add" action="<?=htmlspecialchars(url_for('admin/work_function_defaults.php'), ENT_QUOTES, 'UTF-8')?>">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="mode" value="catalog_add">
        <div class="md-work-function-add-fields">
          <label class="md-field md-field-inline">
            <span><?=htmlspecialchars(t($t, 'work_function_label_name', 'Work function name'), ENT_QUOTES, 'UTF-8')?></span>
            <input name="label" required autocomplete="off" placeholder="<?=htmlspecialchars(t($t, 'work_function_label_placeholder', 'e.g. Community Health'), ENT_QUOTES, 'UTF-8')?>">
          </label>
          <label class="md-field md-field-inline">
            <span><?=htmlspecialchars(t($t, 'work_function_key_optional', 'Optional unique key'), ENT_QUOTES, 'UTF-8')?></span>
            <input name="slug" autocomplete="off" placeholder="<?=htmlspecialchars(t($t, 'work_function_key_placeholder', 'leave blank to generate automatically'), ENT_QUOTES, 'UTF-8')?>">
          </label>
          <button type="submit" class="md-button md-primary md-compact md-elev-1"><?=htmlspecialchars(t($t, 'work_function_add', 'Add work function'), ENT_QUOTES, 'UTF-8')?></button>
        </div>
      </form>
      <div class="md-work-function-catalog">
        <?php if (!$hasActiveCatalog): ?>
          <p class="md-hint"><?=htmlspecialchars(t($t, 'work_function_catalog_empty', 'No work functions are currently available.'), ENT_QUOTES, 'UTF-8')?></p>
        <?php endif; ?>
        <?php foreach ($workFunctionCatalog as $slug => $record): ?>
          <?php
          if (($record['archived_at'] ?? null) !== null) {
              continue;
          }
          $questionnaireCount = $assignmentCounts[$slug] ?? 0;
          $staffCount = $staffCounts[$slug] ?? 0;
          $statsTemplate = t($t, 'work_function_catalog_stats', '{questionnaires} questionnaires · {staff} staff');
          $statsText = strtr($statsTemplate, [
              '{questionnaires}' => number_format($questionnaireCount),
              '{staff}' => number_format($staffCount),
          ]);
          $confirmText = t($t, 'work_function_archive_confirm', 'Archive this work function? Existing assignments will be removed.');
          $confirmJson = json_encode($confirmText, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
          if ($confirmJson === false) {
              $confirmJson = json_encode((string)$confirmText);
          }
          ?>
          <form method="post" class="md-work-function-row" action="<?=htmlspecialchars(url_for('admin/work_function_defaults.php'), ENT_QUOTES, 'UTF-8')?>">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="slug" value="<?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?>">
            <div class="md-work-function-row-main">
              <label class="md-field md-field-inline">
                <span><?=htmlspecialchars(t($t, 'work_function_label_name', 'Work function name'), ENT_QUOTES, 'UTF-8')?></span>
                <input name="label" value="<?=htmlspecialchars($record['label'] ?? '', ENT_QUOTES, 'UTF-8')?>" required autocomplete="off">
              </label>
              <div class="md-work-function-row-meta">
                <span class="md-tag"><?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?></span>
                <span class="md-tag md-muted"><?=htmlspecialchars($statsText, ENT_QUOTES, 'UTF-8')?></span>
              </div>
            </div>
            <div class="md-work-function-row-actions">
              <button type="submit" name="mode" value="catalog_update" class="md-button md-primary md-compact"><?=htmlspecialchars(t($t, 'save', 'Save Changes'), ENT_QUOTES, 'UTF-8')?></button>
              <button type="submit" name="mode" value="catalog_archive" class="md-button md-outline md-compact" onclick="return confirm(<?=htmlspecialchars((string)$confirmJson, ENT_QUOTES, 'UTF-8')?>);"><?=htmlspecialchars(t($t, 'work_function_archive', 'Archive'), ENT_QUOTES, 'UTF-8')?></button>
            </div>
          </form>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if ($msg !== ''): ?>
      <div class="md-alert success"><?=htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="md-alert error">
        <?php foreach ($errors as $error): ?>
          <p><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8')?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <form method="post" action="<?=htmlspecialchars(url_for('admin/work_function_defaults.php'), ENT_QUOTES, 'UTF-8')?>" data-work-function-form>
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="assignments_payload" value="" data-work-function-payload>
      <div class="md-work-function-grid">
        <?php if ($workFunctionKeys === []): ?>
          <p class="md-hint"><?=htmlspecialchars(t($t, 'work_function_defaults_none', 'No work functions are available yet. Staff members can continue to receive questionnaires assigned directly to them.'), ENT_QUOTES, 'UTF-8')?></p>
        <?php endif; ?>
        <?php foreach ($workFunctionKeys as $wf): ?>
          <?php $label = $workFunctionOptions[$wf] ?? work_function_label($pdo, $wf); ?>
          <div class="md-work-function-card" data-work-function-block data-work-function="<?=htmlspecialchars($wf, ENT_QUOTES, 'UTF-8')?>">
            <div class="md-work-function-heading">
              <h3><?=htmlspecialchars($label, ENT_QUOTES, 'UTF-8')?></h3>
              <p><?=htmlspecialchars(t($t, 'work_function_defaults_card_hint', 'Staff in this work function receive the selected questionnaires by default.'), ENT_QUOTES, 'UTF-8')?></p>
            </div>
            <?php if ($questionnaires): ?>
              <div class="md-work-function-toolbar">
                <label class="md-work-function-search">
                  <span class="md-visually-hidden"><?=htmlspecialchars(t($t, 'filter_questionnaires', 'Filter questionnaires'), ENT_QUOTES, 'UTF-8')?></span>
                  <input
                    type="search"
                    placeholder="<?=htmlspecialchars(t($t, 'filter_questionnaires_placeholder', 'Type to narrow the list…'), ENT_QUOTES, 'UTF-8')?>"
                    data-questionnaire-search
                    aria-label="<?=htmlspecialchars(t($t, 'filter_questionnaires', 'Filter questionnaires'), ENT_QUOTES, 'UTF-8')?>"
                    autocomplete="off"
                  >
                </label>
                <div class="md-work-function-actions">
                  <button type="button" class="md-button md-outline" data-select-all><?=htmlspecialchars(t($t, 'select_all', 'Select All'), ENT_QUOTES, 'UTF-8')?></button>
                  <button type="button" class="md-button md-outline" data-clear-all><?=htmlspecialchars(t($t, 'clear_all', 'Clear All'), ENT_QUOTES, 'UTF-8')?></button>
                </div>
              </div>
            <?php endif; ?>
            <fieldset class="md-work-function-options" aria-label="<?=htmlspecialchars(t($t, 'questionnaires', 'Questionnaires'), ENT_QUOTES, 'UTF-8')?>">
              <?php if ($questionnaires): ?>
                <?php foreach ($questionnaires as $questionnaire): ?>
                  <?php
                  $qid = (int)$questionnaire['id'];
                  $title = trim((string)($questionnaire['title'] ?? ''));
                  $description = trim((string)($questionnaire['description'] ?? ''));
                  $displayTitle = $title !== '' ? $title : t($t, 'untitled_questionnaire', 'Untitled questionnaire');
                  $searchText = trim($displayTitle . ' ' . $description);
                  $checked = in_array($qid, $assignmentsByWorkFunction[$wf] ?? [], true);
                  ?>
                  <div
                    class="md-work-function-option"
                    data-questionnaire-option
                    data-search-text="<?=htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8')?>"
                  >
                    <label class="md-checkbox md-checkbox-stacked">
                      <input
                        type="checkbox"
                        name="assignments[<?=htmlspecialchars($wf, ENT_QUOTES, 'UTF-8')?>][]"
                        value="<?=$qid?>"
                        data-work-function-option
                        <?=$checked ? 'checked' : ''?>
                      >
                      <span class="md-questionnaire-copy">
                        <span class="md-questionnaire-title"><?=htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8')?></span>
                        <?php if ($description !== ''): ?>
                          <span class="md-questionnaire-desc"><?=htmlspecialchars($description, ENT_QUOTES, 'UTF-8')?></span>
                        <?php endif; ?>
                      </span>
                    </label>
                  </div>
                <?php endforeach; ?>
                <p class="md-work-function-filter-empty" data-filter-empty hidden><?=htmlspecialchars(t($t, 'filter_no_results', 'No questionnaires match your search.'), ENT_QUOTES, 'UTF-8')?></p>
              <?php else: ?>
                <p class="md-hint"><?=htmlspecialchars(t($t, 'no_questionnaires_configured', 'No questionnaires are configured yet.'), ENT_QUOTES, 'UTF-8')?></p>
              <?php endif; ?>
            </fieldset>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="md-form-actions">
        <button class="md-button md-primary md-elev-2" type="submit"><?=htmlspecialchars(t($t, 'save', 'Save Changes'), ENT_QUOTES, 'UTF-8')?></button>
      </div>
    </form>
  </div>
</section>
<?php include __DIR__ . '/../templates/footer.php'; ?>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
(function () {
  const form = document.querySelector('[data-work-function-form]');
  const payloadInput = form ? form.querySelector('[data-work-function-payload]') : null;
  const blocks = document.querySelectorAll('[data-work-function-block]');

  blocks.forEach((block) => {
    const selectAllBtn = block.querySelector('[data-select-all]');
    const clearBtn = block.querySelector('[data-clear-all]');
    const searchInput = block.querySelector('[data-questionnaire-search]');
    const optionRows = block.querySelectorAll('[data-questionnaire-option]');
    const emptyMessage = block.querySelector('[data-filter-empty]');

    const setAll = (checked) => {
      const checkboxes = block.querySelectorAll('input[type="checkbox"][data-work-function-option]');
      checkboxes.forEach((checkbox) => {
        const optionRow = checkbox.closest('[data-questionnaire-option]');
        if (!checkbox.disabled && optionRow && !optionRow.hidden) {
          checkbox.checked = checked;
          checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
    };

    if (selectAllBtn) {
      selectAllBtn.addEventListener('click', () => setAll(true));
    }
    if (clearBtn) {
      clearBtn.addEventListener('click', () => setAll(false));
    }

    const applyFilter = () => {
      if (!searchInput) {
        return;
      }
      const term = searchInput.value.trim().toLowerCase();
      let visibleCount = 0;
      optionRows.forEach((row) => {
        const haystack = (row.dataset.searchText || '').toLowerCase();
        const matches = term === '' || haystack.includes(term);
        row.hidden = !matches;
        if (matches) {
          visibleCount += 1;
        }
      });
      if (emptyMessage) {
        emptyMessage.hidden = visibleCount !== 0;
      }
    };

    if (searchInput) {
      searchInput.addEventListener('input', applyFilter);
      searchInput.addEventListener('change', applyFilter);
      applyFilter();
    }
  });

  if (form && payloadInput) {
    form.addEventListener('submit', () => {
      const payload = {};
      blocks.forEach((block) => {
        const workFunction = block.getAttribute('data-work-function');
        if (!workFunction) {
          return;
        }
        const selections = [];
        const checkboxes = block.querySelectorAll('input[type="checkbox"][data-work-function-option]:checked');
        checkboxes.forEach((checkbox) => {
          if (checkbox.value !== '') {
            selections.push(checkbox.value);
          }
        });
        payload[workFunction] = selections;
      });
      try {
        payloadInput.value = JSON.stringify(payload);
      } catch (err) {
        payloadInput.value = '';
      }
    });
  }
})();
</script>
</body>
</html>
