<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/scoring.php';
require_once __DIR__ . '/lib/course_recommendations.php';
if (!function_exists('canonical')) {
    require_once __DIR__ . '/lib/work_functions.php';
}
auth_required(['staff','supervisor','admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$err = '';
$flashNotice = '';
$cfg = get_site_config($pdo);
$reviewEnabled = (int)($cfg['review_enabled'] ?? 1) === 1;


$supportsItemConditions = false;
$supportsSectionIncludeInScoring = false;
try {
    $itemColumnsStmt = $pdo->query('SHOW COLUMNS FROM questionnaire_item');
    $itemColumns = [];
    if ($itemColumnsStmt) {
        foreach ($itemColumnsStmt->fetchAll() as $columnRow) {
            $name = isset($columnRow['Field']) ? (string)$columnRow['Field'] : '';
            if ($name !== '') {
                $itemColumns[$name] = true;
            }
        }
    }
    $supportsItemConditions = isset($itemColumns['condition_source_linkid'])
        && isset($itemColumns['condition_operator'])
        && isset($itemColumns['condition_value']);
} catch (PDOException $itemColumnError) {
    error_log('submit_assessment questionnaire_item columns fetch failed: ' . $itemColumnError->getMessage());
}

try {
    $sectionColumnsStmt = $pdo->query('SHOW COLUMNS FROM questionnaire_section');
    $sectionColumns = [];
    if ($sectionColumnsStmt) {
        foreach ($sectionColumnsStmt->fetchAll() as $columnRow) {
            $name = isset($columnRow['Field']) ? (string)$columnRow['Field'] : '';
            if ($name !== '') {
                $sectionColumns[$name] = true;
            }
        }
    }
    $supportsSectionIncludeInScoring = isset($sectionColumns['include_in_scoring']);
} catch (PDOException $sectionColumnError) {
    error_log('submit_assessment questionnaire_section columns fetch failed: ' . $sectionColumnError->getMessage());
}

$isOtherSpecifyPrompt = static function (string $text): bool {
    $normalized = strtolower(trim(str_replace(["’", '"', "'", ','], '', $text)));
    if ($normalized === '') {
        return false;
    }
    return str_contains($normalized, 'if other') && str_contains($normalized, 'specify');
};

$hasOtherOption = static function (array $values): bool {
    foreach ($values as $value) {
        if (is_string($value) && strtolower(trim($value)) === 'other') {
            return true;
        }
    }
    return false;
};

$encodeAnswerPayload = static function (array $payload): string {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        return '[]';
    }
    return $json;
};

$isOtherSelected = static function ($rawValue): bool {
    $selectedValues = is_array($rawValue) ? $rawValue : [$rawValue];
    foreach ($selectedValues as $value) {
        if (is_string($value) && strtolower(trim($value)) === 'other') {
            return true;
        }
    }
    return false;
};


$normalizeConditionLinkId = static function (string $value): string {
    $normalized = trim($value);
    if ($normalized === '') {
        return '';
    }
    if (str_starts_with(strtolower($normalized), 'item_')) {
        $normalized = substr($normalized, 5);
    }
    if (str_ends_with($normalized, '[]')) {
        $normalized = substr($normalized, 0, -2);
    }
    return strtolower(trim($normalized));
};


$collectPostedValues = static function (array $postData) use ($normalizeConditionLinkId): array {
    $valuesByLinkId = [];
    foreach ($postData as $key => $value) {
        if (!is_string($key) || !str_starts_with($key, 'item_')) {
            continue;
        }
        $linkId = $normalizeConditionLinkId(substr($key, 5));
        if ($linkId === '') {
            continue;
        }
        $rawValues = is_array($value) ? $value : [$value];
        $normalized = [];
        foreach ($rawValues as $entry) {
            if (is_scalar($entry) || $entry === null) {
                $text = trim((string)$entry);
                if ($text !== '') {
                    $normalized[] = $text;
                }
            }
        }
        if ($normalized) {
            $valuesByLinkId[$linkId] = $normalized;
        }
    }
    return $valuesByLinkId;
};

$matchesCondition = static function (array $item, array $valuesByLinkId) use ($normalizeConditionLinkId): bool {
    $source = $normalizeConditionLinkId((string)($item['condition_source_linkid'] ?? ''));
    if ($source === '') {
        return true;
    }

    $operator = strtolower(trim((string)($item['condition_operator'] ?? 'equals')));
    if ($operator === '') {
        $operator = 'equals';
    }

    $expected = trim((string)($item['condition_value'] ?? ''));
    $expectedLower = function_exists('mb_strtolower') ? mb_strtolower($expected, 'UTF-8') : strtolower($expected);
    $candidateValues = [];
    foreach (($valuesByLinkId[$source] ?? []) as $value) {
        $candidateValues[] = trim((string)$value);
    }

    if ($operator === 'contains') {
        if ($expectedLower === '') {
            return false;
        }
        foreach ($candidateValues as $candidate) {
            $candidateLower = function_exists('mb_strtolower') ? mb_strtolower($candidate, 'UTF-8') : strtolower($candidate);
            if ($candidateLower !== '' && str_contains($candidateLower, $expectedLower)) {
                return true;
            }
        }
        return false;
    }

    $normalizedCandidates = [];
    foreach ($candidateValues as $candidate) {
        $normalizedCandidates[] = function_exists('mb_strtolower')
            ? mb_strtolower((string)$candidate, 'UTF-8')
            : strtolower((string)$candidate);
    }
    $equals = in_array($expectedLower, $normalizedCandidates, true);
    if ($operator === 'not_equals') {
        return !$equals;
    }
    return $equals;
};

$user = current_user();
try {
    if (($user['role'] ?? '') !== 'admin') {
        $assigned = [];
        $isStaff = (($user['role'] ?? '') === 'staff');

        if ($isStaff) {
            $rawDepartment = trim((string)($user['department'] ?? ''));
            $department = function_exists('resolve_department_slug')
                ? resolve_department_slug($pdo, $rawDepartment)
                : $rawDepartment;
            if ($department !== '') {
                $departmentStmt = $pdo->prepare(
                    "SELECT q.id AS id, q.title AS title FROM questionnaire_department qd " .
                    "JOIN questionnaire q ON q.id = qd.questionnaire_id " .
                    "WHERE qd.department_slug = :department AND q.status='published' ORDER BY q.title"
                );
                $departmentStmt->execute([':department' => $department]);
                foreach ($departmentStmt->fetchAll() as $row) {
                    $assigned[(int)$row['id']] = $row;
                }
            }

            // Legacy fallback for environments that have not migrated defaults yet.
            if ($assigned === []) {
                $definitions = work_function_definitions($pdo);
                $workFunction = canonical_work_function_key(trim((string)($user['work_function'] ?? '')), $definitions);
                if ($workFunction !== '') {
                    $workFunctionAssignments = work_function_assignments($pdo);
                    $assignedQuestionnaireIds = array_map(
                        'intval',
                        $workFunctionAssignments[$workFunction] ?? []
                    );

                    if ($assignedQuestionnaireIds) {
                        $placeholders = implode(',', array_fill(0, count($assignedQuestionnaireIds), '?'));
                        $stmt = $pdo->prepare(
                            "SELECT q.id AS id, q.title AS title FROM questionnaire q " .
                            "WHERE q.id IN ($placeholders) AND q.status='published' ORDER BY q.title"
                        );
                        $stmt->execute($assignedQuestionnaireIds);
                        foreach ($stmt->fetchAll() as $row) {
                            $assigned[(int)$row['id']] = $row;
                        }
                    }
                }
            }
        }

        $directAssignmentStmt = $pdo->prepare(
            "SELECT q.id AS id, q.title AS title FROM questionnaire_assignment qa " .
            "JOIN questionnaire q ON q.id = qa.questionnaire_id " .
            "WHERE qa.staff_id = :staff_id AND q.status='published' ORDER BY q.title"
        );
        $directAssignmentStmt->execute([':staff_id' => (int)($user['id'] ?? 0)]);
        foreach ($directAssignmentStmt->fetchAll() as $row) {
            $assigned[(int)$row['id']] = $row;
        }

        $q = array_values($assigned);
    } else {
        $q = $pdo->query("SELECT id, title FROM questionnaire WHERE status='published' ORDER BY title")->fetchAll();
    }
} catch (PDOException $e) {
    error_log('submit_assessment questionnaire lookup failed: ' . $e->getMessage());
    $fallback = $pdo->query("SELECT id, title FROM questionnaire WHERE status='published' ORDER BY title");
    $q = $fallback ? $fallback->fetchAll() : [];
}
$periods = $pdo->query("SELECT id, label, period_start, period_end FROM performance_period ORDER BY period_start DESC")->fetchAll();
$qid = (int)($_GET['qid'] ?? ($q[0]['id'] ?? 0));
$availableQuestionnaireIds = array_map(static fn($row) => (int)$row['id'], $q);
if ($qid && !in_array($qid, $availableQuestionnaireIds, true)) {
    $qid = $availableQuestionnaireIds[0] ?? 0;
}
$findBestPeriodId = static function (array $periodRows, ?string $targetDate): int {
    if (!$periodRows) {
        return 0;
    }
    $fallbackId = (int)($periodRows[0]['id'] ?? 0);
    if (!$targetDate) {
        return $fallbackId;
    }
    $targetTs = strtotime($targetDate);
    if ($targetTs === false) {
        return $fallbackId;
    }
    foreach ($periodRows as $periodRow) {
        $start = isset($periodRow['period_start']) ? strtotime((string)$periodRow['period_start']) : false;
        $end = isset($periodRow['period_end']) ? strtotime((string)$periodRow['period_end']) : false;
        if ($start === false || $end === false) {
            continue;
        }
        if ($targetTs >= $start && $targetTs <= $end) {
            return (int)$periodRow['id'];
        }
    }
    return $fallbackId;
};
$periodTargetDate = trim((string)($user['next_assessment_date'] ?? ''));
if ($periodTargetDate === '') {
    $periodTargetDate = date('Y-m-d');
}
$periodId = (int)($_GET['performance_period_id'] ?? $findBestPeriodId($periods, $periodTargetDate));

$draftSaved = $_GET['saved'] ?? '';
if ($draftSaved === 'draft') {
    $flashNotice = t($t, 'draft_saved', 'Draft saved. You can return to this questionnaire from the same performance period to continue editing.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $qid = (int)($_POST['qid'] ?? 0);
    $periodId = (int)($_POST['performance_period_id'] ?? 0);
    $action = $_POST['action'] ?? 'submit_final';
    $isDraftSave = ($action === 'save_draft');
    $autoApprove = (!$reviewEnabled) && !$isDraftSave;
    if (($user['role'] ?? '') !== 'admin' && !in_array($qid, $availableQuestionnaireIds, true)) {
        $err = t($t, 'invalid_questionnaire_selection', 'The selected questionnaire is not available.');
    } elseif (!$periodId) {
        $err = t($t,'select_period','Please select a performance period.');
    } else {
        $responseColumns = [];
        try {
            $responseColumnStmt = $pdo->query('SHOW COLUMNS FROM questionnaire_response');
            $responseColumnRows = $responseColumnStmt ? $responseColumnStmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($responseColumnRows as $responseColumnRow) {
                $field = strtolower((string)($responseColumnRow['Field'] ?? ''));
                if ($field !== '') {
                    $responseColumns[$field] = true;
                }
            }
        } catch (Throwable $responseColumnError) {
            error_log('submit_assessment questionnaire_response columns fetch failed: ' . $responseColumnError->getMessage());
            $responseColumns = [];
        }

        $existingStmt = $pdo->prepare('SELECT * FROM questionnaire_response WHERE user_id=? AND questionnaire_id=? AND performance_period_id=?');
        $existingStmt->execute([$user['id'], $qid, $periodId]);
        $existingResponse = $existingStmt->fetch();
        if ($existingResponse && !$isDraftSave && ($existingResponse['status'] ?? '') !== 'draft') {
            $err = t($t,'duplicate_submission','A submission already exists for the selected performance period.');
        } else {
        $pdo->beginTransaction();
        try {
            $responseId = $existingResponse ? (int)$existingResponse['id'] : 0;
            $statusValue = $isDraftSave ? 'draft' : ($autoApprove ? 'approved' : 'submitted');
            $scoreValue = $isDraftSave ? null : 0;
            $autoApprovedAt = $autoApprove ? date('Y-m-d H:i:s') : null;
            if ($existingResponse) {
                $updateClauses = ['status=?', 'score=?', 'created_at=NOW()'];
                $updateParams = [$statusValue, $scoreValue];
                if (isset($responseColumns['reviewed_by'])) {
                    $updateClauses[] = 'reviewed_by=NULL';
                }
                if (isset($responseColumns['reviewed_at'])) {
                    $updateClauses[] = 'reviewed_at=?';
                    $updateParams[] = $autoApprovedAt;
                }
                if (isset($responseColumns['review_comment'])) {
                    $updateClauses[] = 'review_comment=NULL';
                }
                $updateParams[] = $responseId;
                $updateSql = 'UPDATE questionnaire_response SET ' . implode(', ', $updateClauses) . ' WHERE id=?';
                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->execute($updateParams);
                $pdo->prepare('DELETE FROM questionnaire_response_item WHERE response_id=?')->execute([$responseId]);
            } else {
                $insertColumns = ['user_id', 'questionnaire_id', 'performance_period_id', 'status', 'created_at'];
                $insertValues = ['?', '?', '?', '?', 'NOW()'];
                $insertParams = [$user['id'], $qid, $periodId, $statusValue];
                if (isset($responseColumns['reviewed_at'])) {
                    $insertColumns[] = 'reviewed_at';
                    $insertValues[] = '?';
                    $insertParams[] = $autoApprovedAt;
                }
                $insertSql = 'INSERT INTO questionnaire_response (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $insertValues) . ')';
                $insertStmt = $pdo->prepare($insertSql);
                $insertStmt->execute($insertParams);
                $responseId = (int)$pdo->lastInsertId();
            }

            // Fetch items with section scoring metadata
            $conditionSelectSql = $supportsItemConditions
                ? 'qi.condition_source_linkid, qi.condition_operator, qi.condition_value, '
                : 'NULL AS condition_source_linkid, NULL AS condition_operator, NULL AS condition_value, ';
            $includeInScoringSelectSql = $supportsSectionIncludeInScoring
                ? 'COALESCE(qs.include_in_scoring,1) AS include_in_scoring '
                : '1 AS include_in_scoring ';
            $itemsStmt = $pdo->prepare(
                'SELECT qi.id, qi.linkId, qi.text, qi.type, qi.allow_multiple, qi.weight_percent, qi.is_required, qi.requires_correct, '
                . $conditionSelectSql
                . 'qi.section_id, ' . $includeInScoringSelectSql
                . 'FROM questionnaire_item qi '
                . 'LEFT JOIN questionnaire_section qs ON qs.id = qi.section_id '
                . 'WHERE qi.questionnaire_id=? AND qi.is_active=1 ORDER BY qi.order_index ASC'
            );
            $itemsStmt->execute([$qid]);
            $items = $itemsStmt->fetchAll();

            $nonScorableTypes = ['display', 'group', 'section'];
            $singleChoiceWeightMap = questionnaire_even_single_choice_weights($items);
            $likertWeightMap = questionnaire_even_likert_weights($items);
            foreach ($items as &$itemRow) {
                $type = (string)($itemRow['type'] ?? '');
                $isScorable = !in_array($type, $nonScorableTypes, true);
                $itemRow['computed_weight'] = questionnaire_resolve_effective_weight($itemRow, $singleChoiceWeightMap, $likertWeightMap, $isScorable);
            }
            unset($itemRow);

            $optionMap = [];
            if ($items) {
                $itemIds = array_column($items, 'id');
                $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
                $optStmt = $pdo->prepare("SELECT questionnaire_item_id, value, is_correct FROM questionnaire_item_option WHERE questionnaire_item_id IN ($placeholders) ORDER BY questionnaire_item_id, order_index, id");
                $optStmt->execute($itemIds);
                foreach ($optStmt->fetchAll() as $opt) {
                    $itemId = (int)$opt['questionnaire_item_id'];
                    $value = (string)($opt['value'] ?? '');
                    if ($value === '') {
                        continue;
                    }
                    $optionMap[$itemId]['values'][] = $value;
                    if (!empty($opt['is_correct']) && empty($optionMap[$itemId]['correct'])) {
                        $optionMap[$itemId]['correct'] = $value;
                    }
                }
            }

            $previousItem = null;
            foreach ($items as $index => $itemRow) {
                $promptText = (string)($itemRow['text'] ?? '');
                if (!$isOtherSpecifyPrompt($promptText) || !is_array($previousItem)) {
                    $previousItem = $itemRow;
                    continue;
                }
                $previousItemId = (int)($previousItem['id'] ?? 0);
                $previousValues = $optionMap[$previousItemId]['values'] ?? [];
                if (($previousItem['type'] ?? '') === 'choice' && $hasOtherOption($previousValues)) {
                    $items[$index]['other_followup_parent_linkid'] = (string)($previousItem['linkId'] ?? '');
                }
                $previousItem = $itemRow;
            }

            $correctCount = 0;
            $totalCount = 0;

            $answersByLinkId = [];
            $submittedValuesByLinkId = $collectPostedValues($_POST);
            $missingRequired = [];
            foreach ($items as $it) {
                $isVisible = $matchesCondition($it, $submittedValuesByLinkId);
                if (!$isVisible) {
                    continue;
                }
                $name = 'item_' . $it['linkId'];
                $type = (string)($it['type'] ?? '');
                $isScorable = !in_array($type, $nonScorableTypes, true);
                $effectiveWeight = isset($it['computed_weight'])
                    ? (float)$it['computed_weight']
                    : questionnaire_resolve_effective_weight($it, $singleChoiceWeightMap, $likertWeightMap, $isScorable);
                $achievedPoints = 0.0;
                $a = json_encode([]);
                $isRequired = !empty($it['is_required']);
                $questionTitle = trim((string)($it['text'] ?? ''));
                if ($questionTitle === '') {
                    $questionTitle = (string)($it['linkId'] ?? '');
                }
                $hasResponse = false;
                $showForSelectedOther = true;
                $parentLinkId = (string)($it['other_followup_parent_linkid'] ?? '');
                if ($parentLinkId !== '') {
                    $parentField = 'item_' . $parentLinkId;
                    $showForSelectedOther = $isOtherSelected($_POST[$parentField] ?? null);
                    if (!$showForSelectedOther) {
                        $isRequired = false;
                    }
                }

                if (!$showForSelectedOther) {
                    $a = json_encode([]);
                } elseif ($type === 'boolean') {
                    $hasResponse = array_key_exists($name, $_POST);
                    $ans = $_POST[$name] ?? '';
                    $val = ($ans === '1' || $ans === 'true' || $ans === 'on') ? 'true' : 'false';
                    if ($val === 'true') {
                        $achievedPoints = $effectiveWeight;
                    }
                    $a = json_encode([['valueBoolean' => $val === 'true']]);
                } elseif ($type === 'likert') {
                    $raw = $_POST[$name] ?? '';
                    if (is_array($raw)) {
                        $raw = reset($raw);
                    }
                    $selected = is_string($raw) ? trim($raw) : '';
                    $validOptions = array_map('trim', $optionMap[(int)$it['id']]['values'] ?? []);
                    if ($selected !== '' && $validOptions && !in_array($selected, $validOptions, true)) {
                        $selected = '';
                    }
                    $scoreValue = null;
                    if ($selected !== '') {
                        if (preg_match('/^([1-5])/', $selected, $matches)) {
                            $scoreValue = (int)$matches[1];
                        } elseif (is_numeric($selected)) {
                            $candidate = (int)$selected;
                            if ($candidate >= 1 && $candidate <= 5) {
                                $scoreValue = $candidate;
                            }
                        }
                    }
                    if ($selected !== '' && $scoreValue === null && $validOptions) {
                        $optionIndex = array_search($selected, $validOptions, true);
                        if ($optionIndex !== false) {
                            $scoreValue = $optionIndex + 1;
                            $scaleMax = max(1, count($validOptions));
                            $achievedPoints = $effectiveWeight * ($scoreValue / $scaleMax);
                        }
                    } elseif ($scoreValue !== null) {
                        $achievedPoints = $effectiveWeight * ($scoreValue / 5.0);
                    }
                    if ($selected !== '') {
                        $hasResponse = true;
                        $answerEntry = [];
                        if ($scoreValue !== null) {
                            $answerEntry['valueInteger'] = $scoreValue;
                        }
                        $answerEntry['valueString'] = $selected;
                        $a = json_encode([$answerEntry]);
                    }
                } elseif ($type === 'choice') {
                    $allowMultiple = !empty($it['allow_multiple']);
                    $requiresCorrect = !$allowMultiple && !empty($it['requires_correct']);
                    $raw = $_POST[$name] ?? ($allowMultiple ? [] : '');
                    $selected = $allowMultiple ? (array)$raw : [$raw];
                    $values = array_values(array_filter(array_map(static function ($val) {
                        if (is_string($val)) {
                            return trim($val);
                        }
                        return '';
                    }, $selected), static fn($val) => $val !== ''));
                    $validOptions = array_map('trim', $optionMap[(int)$it['id']]['values'] ?? []);
                    if ($validOptions) {
                        $values = array_values(array_filter($values, static function ($val) use ($validOptions) {
                            return in_array($val, $validOptions, true);
                        }));
                    }
                    if ($values) {
                        $hasResponse = true;
                    }
                    if ($values && $allowMultiple) {
                        $achievedPoints = $effectiveWeight;
                    }
                    if (!$allowMultiple && $values) {
                        $correctValue = $optionMap[(int)$it['id']]['correct'] ?? null;
                        $selectedValue = (string)($values[0] ?? '');
                        if ($requiresCorrect && $correctValue !== null && $selectedValue !== '' && $selectedValue === $correctValue) {
                            $achievedPoints = $effectiveWeight;
                        }
                        if (!$requiresCorrect && $selectedValue !== '') {
                            $achievedPoints = $effectiveWeight;
                        }
                    }
                    $a = $encodeAnswerPayload(array_map(static fn($val) => ['valueString' => (string)$val], $values));
                } else {
                    $ans = $_POST[$name] ?? '';
                    $txt = trim((string)$ans);
                    if ($txt !== '') {
                        $hasResponse = true;
                    }
                    if ($txt !== '') {
                        $achievedPoints = $effectiveWeight;
                    }
                    $a = $encodeAnswerPayload([['valueString' => $txt]]);
                }

                $answersByLinkId[(string)$it['linkId']] = json_decode((string)$a, true) ?: [];

                if ($isRequired && !$isDraftSave && !$hasResponse) {
                    $missingRequired[] = $questionTitle;
                }

                $ins = $pdo->prepare('INSERT INTO questionnaire_response_item (response_id, linkId, answer) VALUES (?,?,?)');
                $ins->execute([$responseId, $it['linkId'], $a]);

                if (!$isDraftSave && $isScorable && questionnaire_item_uses_correct_answer($it) && questionnaire_section_included_in_scoring($it)) {
                    $correctValue = (string)($optionMap[(int)$it['id']]['correct'] ?? '');
                    if ($correctValue !== '') {
                        $totalCount++;
                        $answerSet = json_decode((string)$a, true);
                        if (is_array($answerSet) && questionnaire_answer_is_correct($answerSet, $correctValue)) {
                            $correctCount++;
                        }
                    }
                }
            }
            if (!$isDraftSave && $missingRequired) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $err = t($t, 'required_questions_missing', 'Please complete all required questions before submitting.');
                if (count($missingRequired) <= 5) {
                    $err .= ' ' . t($t, 'missing_questions_list', 'Missing:') . ' ' . implode(', ', array_map(static function ($label) use ($t) {
                        return $label !== '' ? $label : t($t, 'question', 'Question');
                    }, $missingRequired));
                }
            } else {
                if ($isDraftSave) {
                    $pdo->prepare('UPDATE questionnaire_response SET score=NULL WHERE id=?')->execute([$responseId]);
                    clear_response_training_recommendations($pdo, $responseId);
                } else {
                    $pctRaw = $totalCount > 0 ? ($correctCount / $totalCount) * 100 : 0.0;
                    $pct = (int)round(max(0.0, min(100.0, $pctRaw)));
                    $pdo->prepare('UPDATE questionnaire_response SET score=? WHERE id=?')->execute([$pct, $responseId]);
                    try {
                        map_response_to_training_courses($pdo, $responseId, (string)($user['work_function'] ?? ''), $pct, $qid);
                    } catch (Throwable $courseMapError) {
                        error_log('submit_assessment recommendation mapping failed: ' . $courseMapError->getMessage());
                    }
                }
                $pdo->commit();
                if ($isDraftSave) {
                    $query = http_build_query([
                        'qid' => $qid,
                        'performance_period_id' => $periodId,
                        'saved' => 'draft',
                    ]);
                    header('Location: ' . url_for('submit_assessment.php?' . $query));
                } else {
                    header('Location: ' . url_for('my_performance.php?msg=submitted'));
                }
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('submit_assessment failed: ' . $e->getMessage());
            $err = t($t, 'submission_failed', 'We could not save your responses. Please try again.');
        }
        }
    }
}

// Load selected questionnaire with sections and items
$questionnaireDetails = null;
$sections = [];
$items = [];
$sectionAnchors = [];
$additionalAnchor = null;
$availablePeriods = $periods;
$taken = [];
$finalizedPeriods = [];
$draftMap = [];
$currentAnswers = [];
$currentResponse = null;
$buildAnchorId = static function (string $prefix, string $value): string {
    $normalized = preg_replace('/[^a-zA-Z0-9_-]+/', '-', strtolower($value));
    $normalized = trim((string)$normalized, '-');
    if ($normalized === '') {
        $normalized = 'item';
    }
    return $prefix . '-' . $normalized;
};
if ($qid) {
    $detailStmt = $pdo->prepare('SELECT id, title, description FROM questionnaire WHERE id=?');
    $detailStmt->execute([$qid]);
    $questionnaireDetails = $detailStmt->fetch() ?: null;
    $s = $pdo->prepare("SELECT * FROM questionnaire_section WHERE questionnaire_id=? AND is_active=1 ORDER BY order_index ASC");
    $s->execute([$qid]); $sections = $s->fetchAll();
    $i = $pdo->prepare("SELECT * FROM questionnaire_item WHERE questionnaire_id=? AND is_active=1 ORDER BY order_index ASC");
    $i->execute([$qid]);
    $items = $i->fetchAll();
    $sectionIds = [];
    foreach ($sections as $sectionRow) {
        $sectionId = (int)($sectionRow['id'] ?? 0);
        if ($sectionId > 0) {
            $sectionIds[$sectionId] = true;
        }
    }
    $missingSectionIds = [];
    foreach ($items as $itemRow) {
        if ($itemRow['section_id'] === null) {
            continue;
        }
        $sectionId = (int)$itemRow['section_id'];
        if ($sectionId > 0 && !isset($sectionIds[$sectionId])) {
            $missingSectionIds[$sectionId] = true;
        }
    }
    if ($missingSectionIds) {
        $placeholders = implode(',', array_fill(0, count($missingSectionIds), '?'));
        $missingStmt = $pdo->prepare(
            "SELECT * FROM questionnaire_section WHERE questionnaire_id=? AND id IN ($placeholders) " .
            "ORDER BY order_index ASC, id ASC"
        );
        $missingStmt->execute(array_merge([$qid], array_keys($missingSectionIds)));
        $sections = array_merge($sections, $missingStmt->fetchAll());
        usort($sections, static function (array $a, array $b): int {
            $orderA = (int)($a['order_index'] ?? 0);
            $orderB = (int)($b['order_index'] ?? 0);
            if ($orderA === $orderB) {
                return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
            }
            return $orderA <=> $orderB;
        });
    }
    $itemOptions = [];
    if ($items) {
        $itemIds = array_column($items, 'id');
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $optStmt = $pdo->prepare("SELECT questionnaire_item_id, value, order_index, is_correct FROM questionnaire_item_option WHERE questionnaire_item_id IN ($placeholders) ORDER BY questionnaire_item_id, order_index, id");
        $optStmt->execute($itemIds);
        foreach ($optStmt->fetchAll() as $row) {
            $itemOptions[(int)$row['questionnaire_item_id']][] = $row;
        }
        foreach ($items as &$itemRow) {
            $itemId = (int)$itemRow['id'];
            $itemRow['options'] = $itemOptions[$itemId] ?? [];
            $itemRow['allow_multiple'] = (bool)$itemRow['allow_multiple'];
        }
        unset($itemRow);
    }
    $previousItem = null;
    foreach ($items as $index => $itemRow) {
        $promptText = (string)($itemRow['text'] ?? '');
        if (!$isOtherSpecifyPrompt($promptText) || !is_array($previousItem)) {
            $previousItem = $itemRow;
            continue;
        }
        $previousOptions = $previousItem['options'] ?? [];
        $previousValues = array_values(array_filter(array_map(static function ($opt) {
            return is_array($opt) ? (string)($opt['value'] ?? '') : '';
        }, $previousOptions), static fn($value) => $value !== ''));
        if (($previousItem['type'] ?? '') === 'choice' && $hasOtherOption($previousValues)) {
            $items[$index]['other_followup_parent_linkid'] = (string)($previousItem['linkId'] ?? '');
        }
        $previousItem = $itemRow;
    }
    foreach ($sections as &$sectionRow) {
        $sectionIdSource = (string)($sectionRow['id'] ?? ($sectionRow['title'] ?? uniqid('section')));
        $sectionRow['anchor'] = $buildAnchorId('section', $sectionIdSource);
        $sectionAnchors[(int)($sectionRow['id'] ?? count($sectionAnchors))] = $sectionRow['anchor'];
    }
    unset($sectionRow);
    $hasRootItems = false;
    foreach ($items as &$itemRow) {
        if ($itemRow['section_id'] === null) {
            $hasRootItems = true;
        }
        $linkId = (string)($itemRow['linkId'] ?? '');
        $anchorSource = $linkId !== '' ? $linkId : (string)($itemRow['id'] ?? uniqid('item'));
        $itemRow['question_anchor'] = $buildAnchorId('question', $anchorSource);
    }
    unset($itemRow);
    if ($hasRootItems) {
        $additionalAnchor = $buildAnchorId('section', 'additional');
    }
    $takenStmt = $pdo->prepare('SELECT performance_period_id, status, created_at, id FROM questionnaire_response WHERE user_id=? AND questionnaire_id=? ORDER BY created_at DESC');
    $takenStmt->execute([$user['id'], $qid]);
    $takenRows = $takenStmt->fetchAll();
    $finalStatuses = ['submitted','approved','rejected'];
    foreach ($takenRows as $row) {
        $pid = (int)$row['performance_period_id'];
        $status = $row['status'] ?? 'submitted';
        if (in_array($status, $finalStatuses, true)) {
            $finalizedPeriods[$pid] = true;
        }
        if ($status === 'draft') {
            $draftMap[$pid] = $row;
        }
    }
    $availablePeriods = array_values(array_filter($periods, static function ($p) use ($finalizedPeriods, $draftMap) {
        $pid = (int)$p['id'];
        return !isset($finalizedPeriods[$pid]) || isset($draftMap[$pid]);
    }));
    if ($periodId && isset($finalizedPeriods[$periodId]) && !isset($draftMap[$periodId])) {
        $periodId = $availablePeriods[0]['id'] ?? 0;
    }
    if (!$periodId && $availablePeriods) {
        $periodId = $availablePeriods[0]['id'];
    }
    if ($periodId && isset($draftMap[$periodId])) {
        $currentResponse = $draftMap[$periodId];
        $answerStmt = $pdo->prepare('SELECT linkId, answer FROM questionnaire_response_item WHERE response_id=?');
        $answerStmt->execute([(int)$currentResponse['id']]);
        foreach ($answerStmt->fetchAll() as $answerRow) {
            $decoded = json_decode($answerRow['answer'] ?? '[]', true);
            $currentAnswers[$answerRow['linkId']] = is_array($decoded) ? $decoded : [];
        }
        if ($flashNotice === '' && !empty($currentResponse['created_at'])) {
            $savedAt = date('F j, Y g:i a', strtotime($currentResponse['created_at']));
            $template = t($t, 'editing_draft_from', 'You are editing a saved draft from %s.');
            $flashNotice = sprintf($template, $savedAt);
        }
    }
}

$renderQuestionField = static function (array $it, array $t, array $answers) use ($buildAnchorId, $normalizeConditionLinkId): string {
    $options = $it['options'] ?? [];
    $allowMultiple = !empty($it['allow_multiple']);
    $linkId = (string)($it['linkId'] ?? '');
    $normalizedLinkId = $normalizeConditionLinkId($linkId);
    $anchorId = $it['question_anchor'] ?? $buildAnchorId('question', $linkId !== '' ? $linkId : (string)($it['id'] ?? uniqid('item')));
    $answerEntries = $answers[$linkId] ?? [];
    if ($answerEntries === [] && $normalizedLinkId !== '') {
        foreach ($answers as $answerKey => $entries) {
            if (!is_string($answerKey)) {
                continue;
            }
            if ($normalizeConditionLinkId($answerKey) === $normalizedLinkId) {
                $answerEntries = is_array($entries) ? $entries : [];
                break;
            }
        }
    }
    $checkedValues = [];
    if (is_array($answerEntries)) {
        foreach ($answerEntries as $entry) {
            if (is_array($entry)) {
                foreach (['valueString','valueCoding','valueInteger','valueBoolean'] as $key) {
                    if (isset($entry[$key])) {
                        $checkedValues[] = $entry[$key];
                    }
                }
            }
        }
    }
    $firstValue = $checkedValues[0] ?? '';
    $required = !empty($it['is_required']);
    $fieldClass = 'md-field' . ($required ? ' md-field--required' : '');
    $requiredAttr = $required ? ' required' : '';
    $conditionSource = trim((string)($it['condition_source_linkid'] ?? ''));
    $conditionOperator = trim((string)($it['condition_operator'] ?? 'equals'));
    $conditionValue = trim((string)($it['condition_value'] ?? ''));
    $ariaRequired = $required ? ' aria-required="true"' : '';
    $followupParentLinkId = (string)($it['other_followup_parent_linkid'] ?? '');
    $followupVisible = true;
    if ($followupParentLinkId !== '' && $conditionSource === '') {
        $parentAnswerEntries = $answers[$followupParentLinkId] ?? [];
        $followupVisible = false;
        if (is_array($parentAnswerEntries)) {
            foreach ($parentAnswerEntries as $entry) {
                if (!is_array($entry) || !isset($entry['valueString'])) {
                    continue;
                }
                if (str_contains(strtolower(trim((string)$entry['valueString'])), 'other')) {
                    $followupVisible = true;
                    break;
                }
            }
        }
    }
    ob_start();
    ?>
    <label
      class="<?=htmlspecialchars($fieldClass, ENT_QUOTES, 'UTF-8')?>"
      id="<?=htmlspecialchars($anchorId, ENT_QUOTES, 'UTF-8')?>"
      data-question-anchor
      <?php if ($followupParentLinkId !== ''): ?>data-other-followup data-other-parent-linkid="<?=htmlspecialchars($followupParentLinkId, ENT_QUOTES, 'UTF-8')?>"<?php endif; ?>
      <?php if ($conditionSource !== ''): ?>data-condition-source="<?=htmlspecialchars($conditionSource, ENT_QUOTES, 'UTF-8')?>" data-condition-operator="<?=htmlspecialchars($conditionOperator ?: 'equals', ENT_QUOTES, 'UTF-8')?>" data-condition-value="<?=htmlspecialchars($conditionValue, ENT_QUOTES, 'UTF-8')?>"<?php endif; ?>
      tabindex="-1"
      data-required="<?= $required ? '1' : '0' ?>"
      <?php if ($followupParentLinkId !== '' && !$followupVisible): ?>hidden<?php endif; ?>
    >
      <span><?=htmlspecialchars($it['text'] ?? '', ENT_QUOTES, 'UTF-8')?></span>
      <?php if (($it['type'] ?? '') === 'boolean'): ?>
        <?php $isChecked = false;
        if ($answerEntries) {
            $entry = $answerEntries[0] ?? [];
            if (is_array($entry)) {
                if (array_key_exists('valueBoolean', $entry)) {
                    $isChecked = filter_var($entry['valueBoolean'], FILTER_VALIDATE_BOOLEAN);
                } elseif (array_key_exists('valueString', $entry)) {
                    $isChecked = filter_var($entry['valueString'], FILTER_VALIDATE_BOOLEAN);
                }
            }
        }
        ?>
        <input type="checkbox" name="item_<?=htmlspecialchars($it['linkId'] ?? '', ENT_QUOTES, 'UTF-8')?>" value="true" <?=$isChecked ? 'checked' : ''?><?=$requiredAttr?>>
      <?php elseif (($it['type'] ?? '') === 'textarea'): ?>
        <textarea name="item_<?=htmlspecialchars($it['linkId'] ?? '', ENT_QUOTES, 'UTF-8')?>" rows="3"<?=$requiredAttr?>><?php
            $textValue = '';
            if ($answerEntries) {
                $entry = $answerEntries[0] ?? [];
                if (is_array($entry) && isset($entry['valueString'])) {
                    $textValue = (string)$entry['valueString'];
                }
            }
            echo htmlspecialchars($textValue, ENT_QUOTES, 'UTF-8');
        ?></textarea>
      <?php elseif (($it['type'] ?? '') === 'likert' && !empty($options)): ?>
        <div class="likert-scale" role="radiogroup" aria-label="<?=htmlspecialchars($it['text'] ?? '', ENT_QUOTES, 'UTF-8')?>"<?=$ariaRequired?>>
          <?php foreach ($options as $idx => $opt):
            $value = $opt['value'] ?? (string)($idx + 1);
            $label = $opt['value'] ?? ('Option ' . ($idx + 1));
            $inputId = ($it['linkId'] ?? 'likert') . '_' . ($idx + 1);
            $selected = is_string($firstValue) ? $firstValue : ((string)$firstValue);
          ?>
          <label class="likert-scale__option" for="<?=htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8')?>">
            <input type="radio" id="<?=htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8')?>" name="item_<?=htmlspecialchars($it['linkId'] ?? '', ENT_QUOTES, 'UTF-8')?>" value="<?=htmlspecialchars($value, ENT_QUOTES, 'UTF-8')?>" <?=($selected !== '' && (string)$value === $selected) ? 'checked' : ''?><?=($required && $idx === 0) ? ' required' : ''?>>
            <span><?=htmlspecialchars($label, ENT_QUOTES, 'UTF-8')?></span>
          </label>
          <?php endforeach; ?>
        </div>
      <?php elseif (($it['type'] ?? '') === 'choice' && !empty($options)): ?>
        <?php if ($allowMultiple): ?>
        <select name="item_<?=htmlspecialchars($it['linkId'] ?? '', ENT_QUOTES, 'UTF-8')?>[]" multiple size="<?=max(3, min(6, count($options)))?>"<?=$requiredAttr?>>
          <?php foreach ($options as $opt): ?>
            <?php $optValue = (string)($opt['value'] ?? '');
            $isSelected = in_array($optValue, array_map('strval', $checkedValues), true);
            ?>
            <option value="<?=htmlspecialchars($optValue, ENT_QUOTES, 'UTF-8')?>" <?=$isSelected ? 'selected' : ''?>><?=htmlspecialchars($opt['value'] ?? '', ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
          <small class="md-hint"><?=htmlspecialchars(t($t,'multiple_choice_hint','Select all that apply'), ENT_QUOTES, 'UTF-8')?></small>
      <?php else: ?>
        <div class="choice-options" role="radiogroup" aria-label="<?=htmlspecialchars($it['text'] ?? '', ENT_QUOTES, 'UTF-8')?>"<?=$ariaRequired?>>
          <?php foreach ($options as $idx => $opt):
            $optValue = (string)($opt['value'] ?? '');
            $label = $opt['value'] ?? ('Option ' . ($idx + 1));
            $inputId = ($it['linkId'] ?? 'choice') . '_' . ($idx + 1);
            $selected = is_string($firstValue) ? $firstValue : ((string)$firstValue);
            $isSelected = $optValue !== '' && $selected !== '' && (string)$optValue === $selected;
          ?>
          <label class="choice-options__option" for="<?=htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8')?>">
            <input type="radio" id="<?=htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8')?>" name="item_<?=htmlspecialchars($it['linkId'] ?? '', ENT_QUOTES, 'UTF-8')?>" value="<?=htmlspecialchars($optValue, ENT_QUOTES, 'UTF-8')?>" <?=$isSelected ? 'checked' : ''?><?=($required && $idx === 0) ? ' required' : ''?>>
            <span><?=htmlspecialchars($label, ENT_QUOTES, 'UTF-8')?></span>
          </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php else: ?>
        <?php
        $textValue = '';
        if ($answerEntries) {
            $entry = $answerEntries[0] ?? [];
            if (is_array($entry)) {
                if (isset($entry['valueString'])) {
                    $textValue = (string)$entry['valueString'];
                } elseif (isset($entry['valueInteger'])) {
                    $textValue = (string)$entry['valueInteger'];
                }
            }
        }
        ?>
        <input name="item_<?=htmlspecialchars($it['linkId'] ?? '', ENT_QUOTES, 'UTF-8')?>" value="<?=htmlspecialchars($textValue, ENT_QUOTES, 'UTF-8')?>"<?=$requiredAttr?>>
      <?php endif; ?>
      <?php
        $weightHint = null;
        if (isset($it['computed_weight']) && is_numeric($it['computed_weight'])) {
            $weightHint = (float)$it['computed_weight'];
        } elseif (isset($it['weight_percent']) && $it['weight_percent'] !== null) {
            $weightHint = (float)$it['weight_percent'];
        }
        if ($weightHint !== null && $weightHint > 0) {
            $weightDisplay = rtrim(rtrim(number_format($weightHint, 2, '.', ''), '0'), '.');
            echo '<small class="md-hint">Weight: ' . htmlspecialchars($weightDisplay, ENT_QUOTES, 'UTF-8') . '%</small>';
        }
      ?>
    </label>
    <?php
    return ob_get_clean();
};
?>
<!doctype html><html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>"><head>
<meta charset="utf-8"><title><?=htmlspecialchars(t($t,'submit_assessment','Submit Assessment'), ENT_QUOTES, 'UTF-8')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<link rel="manifest" href="<?=asset_url('manifest.php')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head><body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>" data-assessment-protected="true">
<?php include __DIR__.'/templates/header.php'; ?>
<section class="md-section">
<div class="md-card md-elev-2 md-assessment-shell">
  <h2 class="md-card-title"><?=t($t,'submit_assessment','Submit Assessment')?></h2>
  <?php if ($flashNotice): ?><div class="md-alert success"><?=htmlspecialchars($flashNotice, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
  <?php if (!empty($err)): ?><div class="md-alert error"><?=htmlspecialchars($err, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
  <form method="get" class="md-inline-form" action="<?=htmlspecialchars(url_for('submit_assessment.php'), ENT_QUOTES, 'UTF-8')?>" data-questionnaire-form>
    <label class="md-field">
      <span><?=t($t,'select_questionnaire','Select questionnaire')?></span>
      <select name="qid" data-questionnaire-select>
        <?php foreach ($q as $row): ?>
          <option value="<?=$row['id']?>" <?=($row['id']==$qid?'selected':'')?>><?=htmlspecialchars($row['title'])?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="md-field">
      <span><?=t($t,'performance_period','Performance Period')?></span>
      <select name="performance_period_id" data-performance-period-select>
        <?php foreach ($periods as $period): ?>
          <?php
            $disabled = isset($finalizedPeriods[$period['id']]) && !isset($draftMap[$period['id']]);
            $labelSuffix = '';
            if ($disabled) {
                $labelSuffix = ' · ' . t($t,'already_submitted','Submitted');
            } elseif (isset($draftMap[$period['id']])) {
                $labelSuffix = ' · ' . t($t,'status_draft','Draft');
            }
          ?>
          <option value="<?=$period['id']?>" <?=($period['id']===$periodId?'selected':'')?> <?=$disabled?'disabled':''?>><?=htmlspecialchars($period['label'])?><?=$labelSuffix?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <noscript>
      <button type="submit" class="md-button md-secondary"><?=t($t,'submit','Submit')?></button>
    </noscript>
  </form>
  <?php if ($qid && empty($availablePeriods)): ?>
    <p><?=t($t,'all_periods_used','You have already submitted for every period available for this questionnaire.')?></p>
  <?php elseif ($qid): ?>
  <div class="md-questionnaire-layout" data-questionnaire-layout data-nav-open="false">
    <button
      type="button"
      class="md-nav-trigger"
      data-nav-toggle
      aria-controls="questionnaire-nav"
      aria-expanded="false"
    >
      <span class="md-nav-trigger__icon" aria-hidden="true">☰</span>
      <span class="md-nav-trigger__label"><?=t($t, 'questionnaire_tabs', 'Questionnaire navigation')?></span>
    </button>
    <aside
      id="questionnaire-nav"
      class="md-questionnaire-nav"
      data-questionnaire-nav
      role="navigation"
      aria-label="<?=htmlspecialchars(t($t, 'questionnaire_tabs', 'Questionnaire navigation'), ENT_QUOTES, 'UTF-8')?>"
      aria-hidden="true"
    >
      <div class="md-questionnaire-nav__header">
        <div class="md-questionnaire-nav__title">
          <span class="md-questionnaire-nav__glyph" aria-hidden="true">🧭</span>
          <span><?=t($t, 'questionnaire_tabs', 'Questionnaire navigation')?></span>
        </div>
        <button
          type="button"
          class="md-questionnaire-nav__close"
          data-nav-close
          aria-label="<?=htmlspecialchars(t($t, 'close_menu', 'Close menu'), ENT_QUOTES, 'UTF-8')?>"
        >
          &times;
        </button>
      </div>
      <div class="md-questionnaire-nav__body" data-nav-body>
        <?php foreach ($sections as $sec):
          $sectionAnchor = $sec['anchor'] ?? $buildAnchorId('section', (string)($sec['id'] ?? $sec['title'] ?? uniqid('section')));
          $sectionHasItems = false;
        ?>
          <div class="md-questionnaire-nav__group">
            <a href="#<?=htmlspecialchars($sectionAnchor, ENT_QUOTES, 'UTF-8')?>" class="md-questionnaire-nav__section" data-nav-link>
              <span class="md-questionnaire-nav__icon" aria-hidden="true">📂</span>
              <span class="md-questionnaire-nav__label"><?=htmlspecialchars($sec['title'] ?? t($t, 'questionnaire', 'Questionnaire'), ENT_QUOTES, 'UTF-8')?></span>
            </a>
            <ul class="md-questionnaire-nav__list" role="list">
              <?php foreach ($items as $it): if ((int)$it['section_id'] !== (int)$sec['id']) continue; $sectionHasItems = true; ?>
                <?php $questionAnchor = $it['question_anchor'] ?? $buildAnchorId('question', (string)($it['linkId'] ?? $it['id'] ?? uniqid('item'))); ?>
                <li>
                  <a href="#<?=htmlspecialchars($questionAnchor, ENT_QUOTES, 'UTF-8')?>" class="md-questionnaire-nav__link" data-nav-link>
                    <span class="md-questionnaire-nav__dot" aria-hidden="true">•</span>
                    <span class="md-questionnaire-nav__label"><?=htmlspecialchars($it['text'] ?? t($t, 'questionnaire', 'Questionnaire'), ENT_QUOTES, 'UTF-8')?></span>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
            <?php if (!$sectionHasItems): ?>
              <p class="md-questionnaire-nav__empty md-muted"><?=t($t, 'no_questionnaire', 'No questionnaire found.')?></p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if ($additionalAnchor): ?>
          <div class="md-questionnaire-nav__group">
            <a href="#<?=htmlspecialchars($additionalAnchor, ENT_QUOTES, 'UTF-8')?>" class="md-questionnaire-nav__section" data-nav-link>
              <span class="md-questionnaire-nav__icon" aria-hidden="true">📝</span>
              <span class="md-questionnaire-nav__label"><?=htmlspecialchars(t($t, 'additional_items', 'Additional questions'), ENT_QUOTES, 'UTF-8')?></span>
            </a>
            <ul class="md-questionnaire-nav__list" role="list">
              <?php foreach ($items as $it): if ($it['section_id'] !== null) continue; ?>
                <?php $questionAnchor = $it['question_anchor'] ?? $buildAnchorId('question', (string)($it['linkId'] ?? $it['id'] ?? uniqid('item'))); ?>
                <li>
                  <a href="#<?=htmlspecialchars($questionAnchor, ENT_QUOTES, 'UTF-8')?>" class="md-questionnaire-nav__link" data-nav-link>
                    <span class="md-questionnaire-nav__dot" aria-hidden="true">•</span>
                    <span class="md-questionnaire-nav__label"><?=htmlspecialchars($it['text'] ?? t($t, 'questionnaire', 'Questionnaire'), ENT_QUOTES, 'UTF-8')?></span>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>
    </aside>
    <div class="md-questionnaire-content">
      <form
        method="post"
        action="<?=htmlspecialchars(url_for('submit_assessment.php'), ENT_QUOTES, 'UTF-8')?>"
        id="assessment-form"
        class="md-assessment-form"
        data-offline-draft="true"
        data-offline-saved-label="<?=htmlspecialchars(t($t, 'offline_draft_saved', 'Offline copy saved at %s.'), ENT_QUOTES, 'UTF-8')?>"
        data-offline-restored-label="<?=htmlspecialchars(t($t, 'offline_draft_restored', 'Restored offline responses from %s.'), ENT_QUOTES, 'UTF-8')?>"
        data-offline-queued-label="<?=htmlspecialchars(t($t, 'offline_draft_queued', 'You are offline. We saved your responses locally. Reconnect and submit to sync.'), ENT_QUOTES, 'UTF-8')?>"
        data-offline-reminder-label="<?=htmlspecialchars(t($t, 'offline_draft_reminder', 'Back online. Submit to upload your saved responses.'), ENT_QUOTES, 'UTF-8')?>"
        data-offline-error-label="<?=htmlspecialchars(t($t, 'offline_draft_error', 'We could not save an offline copy of your responses.'), ENT_QUOTES, 'UTF-8')?>"
      >
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="qid" value="<?=$qid?>">
        <input type="hidden" name="performance_period_id" value="<?=$periodId?>">
        <?php if ($questionnaireDetails): ?>
          <div class="md-questionnaire-header">
            <h3
              class="md-section-title"
              id="<?=htmlspecialchars($questionnaireDetails['anchor'] ?? $buildAnchorId('section', (string)($questionnaireDetails['id'] ?? $questionnaireDetails['title'] ?? 'questionnaire')), ENT_QUOTES, 'UTF-8')?>"
              data-section-anchor
              tabindex="-1"
            >
              <?=htmlspecialchars($questionnaireDetails['title'])?>
            </h3>
            <?php if (!empty($questionnaireDetails['description'])): ?>
              <p class="md-muted"><?=htmlspecialchars($questionnaireDetails['description'])?></p>
            <?php endif; ?>
            <div class="md-divider"></div>
          </div>
        <?php endif; ?>
        <?php foreach ($sections as $sec):
          $sectionAnchor = $sec['anchor'] ?? $buildAnchorId('section', (string)($sec['id'] ?? $sec['title'] ?? uniqid('section')));
        ?>
          <h3 class="md-section-title" id="<?=htmlspecialchars($sectionAnchor, ENT_QUOTES, 'UTF-8')?>" data-section-anchor tabindex="-1"><?=htmlspecialchars($sec['title'])?></h3>
          <p class="md-muted"><?=htmlspecialchars($sec['description'])?></p>
          <div class="md-divider"></div>
          <?php foreach ($items as $it): if ((int)$it['section_id'] !== (int)$sec['id']) continue; ?>
            <?=$renderQuestionField($it, $t, $currentAnswers ?? [])?>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <?php $renderedRoot = false; ?>
        <?php foreach ($items as $it): if ($it['section_id'] !== null) continue; ?>
          <?php if (!$renderedRoot): ?>
            <h3 class="md-section-title" id="<?=htmlspecialchars($additionalAnchor, ENT_QUOTES, 'UTF-8')?>" data-section-anchor tabindex="-1"><?=htmlspecialchars(t($t,'additional_items','Additional questions'), ENT_QUOTES, 'UTF-8')?></h3>
            <div class="md-divider"></div>
            <?php $renderedRoot = true; ?>
          <?php endif; ?>
          <?=$renderQuestionField($it, $t, $currentAnswers ?? [])?>
        <?php endforeach; ?>
        <div class="md-form-actions md-form-actions--stack">
          <button class="md-button md-outline md-floating-save-draft" name="action" value="save_draft" type="submit" formnovalidate><?=t($t,'save_draft','Save Draft')?></button>
          <button class="md-button md-primary md-elev-2" name="action" value="submit_final" type="submit"><?=t($t,'submit','Submit')?></button>
        </div>
      </form>
    </div>
    <div class="md-questionnaire-nav__backdrop" data-nav-backdrop hidden></div>
  </div>
  <?php else: ?>
    <p><?=t($t,'no_questionnaire','No questionnaire found.')?></p>
  <?php endif; ?>
  <script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
  (function() {
    const form = document.querySelector('[data-questionnaire-form]');
    if (!form) {
      return;
    }
    const questionnaireSelect = form.querySelector('[data-questionnaire-select]');
    const periodSelect = form.querySelector('[data-performance-period-select]');
    const assessmentForm = document.getElementById('assessment-form');
    const layout = document.querySelector('[data-questionnaire-layout]');
    const nav = document.querySelector('[data-questionnaire-nav]');
    const navLinks = nav ? Array.from(nav.querySelectorAll('[data-nav-link]')) : [];
    const navToggleButtons = Array.from(document.querySelectorAll('[data-nav-toggle]'));
    const navCloseButtons = Array.from(document.querySelectorAll('[data-nav-close]'));
    const navBackdrop = document.querySelector('[data-nav-backdrop]');
    const desktopMedia = window.matchMedia('(min-width: 1025px)');
    const connectivity = (window.AppConnectivity && typeof window.AppConnectivity.subscribe === 'function')
      ? window.AppConnectivity
      : null;
    const setNavOpen = (open) => {
      if (!layout || !nav) {
        return;
      }
      const next = open === true;
      layout.dataset.navOpen = next ? 'true' : 'false';
      nav.setAttribute('aria-hidden', next ? 'false' : 'true');
      navToggleButtons.forEach((btn) => btn.setAttribute('aria-expanded', next ? 'true' : 'false'));
      if (navBackdrop) {
        navBackdrop.hidden = desktopMedia.matches ? true : !next;
      }
    };
    const syncNavToViewport = () => {
      if (!layout || !nav) {
        return;
      }
      if (desktopMedia.matches) {
        layout.dataset.navOpen = 'true';
        nav.setAttribute('aria-hidden', 'false');
        if (navBackdrop) {
          navBackdrop.hidden = true;
        }
        navToggleButtons.forEach((btn) => btn.setAttribute('aria-expanded', 'true'));
      } else {
        setNavOpen(false);
      }
    };

    desktopMedia.addEventListener('change', syncNavToViewport);
    syncNavToViewport();

    navToggleButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        if (!layout) {
          return;
        }
        const isOpen = layout.dataset.navOpen === 'true';
        setNavOpen(!isOpen);
      });
    });

    navCloseButtons.forEach((btn) => btn.addEventListener('click', () => setNavOpen(false)));

    if (navBackdrop) {
      navBackdrop.addEventListener('click', () => setNavOpen(false));
    }

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !desktopMedia.matches) {
        setNavOpen(false);
      }
    });

    const scrollToAnchor = (selector) => {
      if (!selector || typeof selector !== 'string' || selector.charAt(0) !== '#') {
        return;
      }
      const target = document.querySelector(selector);
      if (!target) {
        return;
      }
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      if (typeof target.focus === 'function') {
        target.focus({ preventScroll: true });
      }
    };

    const normalizeConditionLinkId = (value) => {
      const raw = String(value || '').trim();
      if (!raw) {
        return '';
      }
      let normalized = raw;
      if (normalized.toLowerCase().startsWith('item_')) {
        normalized = normalized.slice(5);
      }
      if (normalized.endsWith('[]')) {
        normalized = normalized.slice(0, -2);
      }
      return normalized.trim().toLowerCase();
    };

    const controlsForLinkId = (linkId) => {
      const source = normalizeConditionLinkId(linkId);
      if (!source) {
        return [];
      }
      const controls = [];
      for (const element of Array.from(form.elements || [])) {
        if (!(element instanceof HTMLElement)) {
          continue;
        }
        const name = normalizeConditionLinkId(element.getAttribute('name') || '');
        if (name === source) {
          controls.push(element);
        }
      }
      return controls;
    };

    const setFieldVisible = (field, show) => {
      const next = show === true;
      field.hidden = !next;
      field.style.display = next ? '' : 'none';
      field.setAttribute('aria-hidden', next ? 'false' : 'true');
    };


    const questionFields = () => Array.from(document.querySelectorAll('#assessment-form [data-question-anchor]'));

    const clearMissingQuestionHighlights = () => {
      questionFields().forEach((field) => {
        field.classList.remove('md-field--missing');
      });
    };

    const applyMissingQuestionHighlights = () => {
      const missingFields = [];
      questionFields().forEach((field) => {
        const controls = Array.from(field.querySelectorAll('input, textarea, select'));
        if (controls.length === 0) {
          return;
        }
        const isMissing = controls.some((control) => {
          if (!(control instanceof HTMLElement) || control.disabled || !control.required) {
            return false;
          }
          if (control instanceof HTMLInputElement) {
            if ((control.type === 'radio' || control.type === 'checkbox')) {
              const groupName = control.name;
              if (!groupName) {
                return control.type === 'checkbox' ? !control.checked : control.value.trim() === '';
              }
              const group = Array.from(assessmentForm.querySelectorAll('input[type="radio"], input[type="checkbox"]'))
                .filter((candidate) => candidate instanceof HTMLInputElement && candidate.name === groupName);
              return !group.some((candidate) => candidate.checked);
            }
            return control.value.trim() === '';
          }
          if (control instanceof HTMLTextAreaElement) {
            return control.value.trim() === '';
          }
          if (control instanceof HTMLSelectElement) {
            if (control.multiple) {
              return control.selectedOptions.length === 0;
            }
            return control.value.trim() === '';
          }
          return false;
        });

        field.classList.toggle('md-field--missing', isMissing);
        if (isMissing) {
          missingFields.push(field);
        }
      });
      return missingFields;
    };

    navLinks.forEach((link) => {
      link.addEventListener('click', (event) => {
        const targetSelector = link.getAttribute('href') || link.getAttribute('data-target');
        if (!targetSelector) {
          return;
        }
        event.preventDefault();
        scrollToAnchor(targetSelector);
        if (!desktopMedia.matches) {
          setNavOpen(false);
        }
      });
    });

    const collectCurrentValuesByLinkId = () => {
      const valuesByLinkId = {};
      for (const element of Array.from(form.elements || [])) {
        if (!(element instanceof HTMLElement)) {
          continue;
        }
        const normalizedName = normalizeConditionLinkId(element.getAttribute('name') || '');
        if (!normalizedName) {
          continue;
        }

        if (element instanceof HTMLInputElement) {
          if ((element.type === 'checkbox' || element.type === 'radio') && !element.checked) {
            continue;
          }
          const value = String(element.value || '').trim();
          if (!value) {
            continue;
          }
          if (!Array.isArray(valuesByLinkId[normalizedName])) {
            valuesByLinkId[normalizedName] = [];
          }
          valuesByLinkId[normalizedName].push(value);
          continue;
        }

        if (element instanceof HTMLTextAreaElement) {
          const value = String(element.value || '').trim();
          if (!value) {
            continue;
          }
          if (!Array.isArray(valuesByLinkId[normalizedName])) {
            valuesByLinkId[normalizedName] = [];
          }
          valuesByLinkId[normalizedName].push(value);
          continue;
        }

        if (element instanceof HTMLSelectElement) {
          const selected = Array.from(element.selectedOptions || [])
            .map((option) => String(option.value || '').trim())
            .filter((value) => value !== '');
          if (selected.length === 0) {
            continue;
          }
          if (!Array.isArray(valuesByLinkId[normalizedName])) {
            valuesByLinkId[normalizedName] = [];
          }
          valuesByLinkId[normalizedName].push(...selected);
        }
      }
      return valuesByLinkId;
    };

    const ensureOriginalRequiredState = (control) => {
      if (!(control instanceof HTMLElement)) {
        return;
      }
      if (!Object.prototype.hasOwnProperty.call(control.dataset, 'originalRequired')) {
        control.dataset.originalRequired = control.required ? 'true' : 'false';
      }
    };

    const clearControlValue = (control) => {
      if (control instanceof HTMLInputElement) {
        if (control.type === 'checkbox' || control.type === 'radio') {
          control.checked = false;
        } else {
          control.value = '';
        }
        return;
      }
      if (control instanceof HTMLTextAreaElement) {
        control.value = '';
        return;
      }
      if (control instanceof HTMLSelectElement) {
        Array.from(control.options).forEach((option) => {
          option.selected = false;
        });
      }
    };

    const setFieldControlState = (field, visible) => {
      const controls = Array.from(field.querySelectorAll('input, textarea, select'));
      controls.forEach((control) => {
        if (!(control instanceof HTMLElement)) {
          return;
        }
        ensureOriginalRequiredState(control);
        if (visible) {
          control.disabled = false;
          control.required = control.dataset.originalRequired === 'true';
          return;
        }
        control.required = false;
        control.disabled = true;
        clearControlValue(control);
      });
    };

    const evaluateConditionMatch = (operator, expected, candidateValues) => {
      const expectedLower = String(expected || '').trim().toLowerCase();
      const normalizedCandidates = candidateValues.map((value) => String(value || '').trim().toLowerCase());
      const equals = normalizedCandidates.includes(expectedLower);
      const contains = expectedLower !== '' && normalizedCandidates.some((value) => value.includes(expectedLower));
      if (operator === 'contains') {
        return contains;
      }
      if (operator === 'not_equals') {
        return !equals;
      }
      return equals;
    };

    const runVisibilityEngine = () => {
      const fields = questionFields();
      const maxPasses = 25;

      for (let pass = 0; pass < maxPasses; pass += 1) {
        const valuesByLinkId = collectCurrentValuesByLinkId();
        let changed = false;

        fields.forEach((field) => {
          const followupParentLinkId = normalizeConditionLinkId(field.getAttribute('data-other-parent-linkid') || '');
          const source = normalizeConditionLinkId(field.getAttribute('data-condition-source') || '');
          const operator = String(field.getAttribute('data-condition-operator') || 'equals').trim().toLowerCase();
          const expected = String(field.getAttribute('data-condition-value') || '').trim();

          let visible = true;

          const hasExplicitCondition = source !== '';
          if (followupParentLinkId && !hasExplicitCondition) {
            const parentValues = valuesByLinkId[followupParentLinkId] || [];
            visible = parentValues.some((value) => String(value || '').trim().toLowerCase().includes('other'));
          }

          if (visible && hasExplicitCondition) {
            const sourceValues = valuesByLinkId[source] || [];
            visible = evaluateConditionMatch(operator, expected, sourceValues);
          }

          const currentlyVisible = !field.hidden;
          if (currentlyVisible !== visible) {
            changed = true;
          }
          setFieldVisible(field, visible);
          setFieldControlState(field, visible);
        });

        if (!changed) {
          break;
        }
      }
    };

    const handleQuestionValueChange = (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) {
        return;
      }
      if ((target.getAttribute('name') || '').startsWith('item_')) {
        runVisibilityEngine();
      }
    };

    document.addEventListener('change', handleQuestionValueChange);
    document.addEventListener('input', handleQuestionValueChange);

    runVisibilityEngine();
    const isAppOnline = () => {
      if (connectivity) {
        try {
          return connectivity.isOnline();
        } catch (err) {
          return navigator.onLine !== false;
        }
      }
      return navigator.onLine !== false;
    };
    const observeConnectivity = (handler) => {
      if (typeof handler !== 'function') {
        return function () {};
      }
      if (connectivity) {
        return connectivity.subscribe(handler);
      }
      const onlineHandler = () => handler({ online: true, forcedOffline: false });
      const offlineHandler = () => handler({ online: false, forcedOffline: false });
      window.addEventListener('online', onlineHandler);
      window.addEventListener('offline', offlineHandler);
      try {
        handler({ online: navigator.onLine !== false, forcedOffline: false });
      } catch (err) {
        // Ignore handler failures during initial sync.
      }
      return function () {
        window.removeEventListener('online', onlineHandler);
        window.removeEventListener('offline', offlineHandler);
      };
    };

    const sendWarmCacheMessage = (urls) => {
      if (!('serviceWorker' in navigator) || !Array.isArray(urls) || urls.length === 0) {
        return;
      }
      navigator.serviceWorker.ready
        .then((registration) => {
          if (registration && registration.active) {
            registration.active.postMessage({ type: 'WARM_ROUTE_CACHE', urls });
          }
        })
        .catch(() => undefined);
    };

    const buildQuestionnaireUrls = () => {
      if (!questionnaireSelect) {
        return [];
      }
      const action = form.getAttribute('action') || window.location.href;
      let baseUrl;
      try {
        baseUrl = new URL(action, window.location.origin);
      } catch (err) {
        return [];
      }
      const urls = new Set();
      const periodValues = periodSelect
        ? Array.from(periodSelect.options)
            .filter((option) => !option.disabled && option.value !== '')
            .map((option) => option.value)
        : [];
      Array.from(questionnaireSelect.options)
        .filter((option) => option.value && option.value !== '')
        .forEach((option) => {
          const qid = option.value;
          const base = new URL(baseUrl.toString());
          base.searchParams.set('qid', qid);
          base.searchParams.delete('performance_period_id');
          urls.add(base.toString());
          periodValues.forEach((pid) => {
            const periodUrl = new URL(baseUrl.toString());
            periodUrl.searchParams.set('qid', qid);
            periodUrl.searchParams.set('performance_period_id', pid);
            urls.add(periodUrl.toString());
          });
        });
      return Array.from(urls);
    };

    const warmQuestionnaireCaches = () => {
      if (!isAppOnline()) {
        return;
      }
      const urls = buildQuestionnaireUrls();
      if (urls.length === 0) {
        return;
      }
      sendWarmCacheMessage(urls);
    };

    const updateLocation = () => {
      const currentUrl = new URL(window.location.href);
      const action = form.getAttribute('action');
      if (action) {
        const actionUrl = new URL(action, window.location.origin);
        currentUrl.pathname = actionUrl.pathname;
      }

      const qid = questionnaireSelect ? questionnaireSelect.value : '';
      if (qid) {
        currentUrl.searchParams.set('qid', qid);
      } else {
        currentUrl.searchParams.delete('qid');
      }

      if (periodSelect && periodSelect.options.length) {
        const selectedOption = periodSelect.options[periodSelect.selectedIndex] || null;
        if (selectedOption && !selectedOption.disabled && selectedOption.value !== '') {
          currentUrl.searchParams.set('performance_period_id', selectedOption.value);
        } else {
          currentUrl.searchParams.delete('performance_period_id');
        }
      } else {
        currentUrl.searchParams.delete('performance_period_id');
      }

      window.location.assign(currentUrl.toString());
    };

    warmQuestionnaireCaches();

    if (questionnaireSelect) {
      questionnaireSelect.addEventListener('change', () => {
        if (periodSelect) {
          periodSelect.selectedIndex = -1;
        }
        warmQuestionnaireCaches();
        updateLocation();
      });
    }

    if (periodSelect) {
      periodSelect.addEventListener('change', () => {
        warmQuestionnaireCaches();
        updateLocation();
      });
    }

    observeConnectivity(function (state) {
      if (state && state.online) {
        warmQuestionnaireCaches();
      }
    });

    const storageSupported = (() => {
      try {
        const testKey = '__hrassess_offline__';
        window.localStorage.setItem(testKey, '1');
        window.localStorage.removeItem(testKey);
        return true;
      } catch (err) {
        return false;
      }
    })();

    if (assessmentForm && storageSupported) {
      const qidField = assessmentForm.querySelector('input[name="qid"]');
      const periodField = assessmentForm.querySelector('input[name="performance_period_id"]');
      const offlineLabels = {
        saved: assessmentForm.getAttribute('data-offline-saved-label') || 'Offline copy saved at %s.',
        restored: assessmentForm.getAttribute('data-offline-restored-label') || 'Restored offline responses from %s.',
        queued: assessmentForm.getAttribute('data-offline-queued-label') || 'You are offline. We saved your responses locally. Reconnect and submit to sync.',
        reminder: assessmentForm.getAttribute('data-offline-reminder-label') || 'Back online. Submit to upload your saved responses.',
        error: assessmentForm.getAttribute('data-offline-error-label') || 'We could not save an offline copy of your responses.',
      };
      const storagePrefix = 'hrassess:assessment';
      let pendingSubmit = false;
      let lastSubmitAction = null;

      const getStorageKey = () => {
        const qid = qidField && qidField.value ? qidField.value : 'unknown';
        const period = periodField && periodField.value ? periodField.value : 'default';
        return `${storagePrefix}:${qid}:${period}`;
      };

      const offlineStatus = document.createElement('div');
      offlineStatus.className = 'md-offline-draft';
      offlineStatus.setAttribute('role', 'status');
      offlineStatus.setAttribute('aria-live', 'polite');
      offlineStatus.hidden = true;
      const actionRow = assessmentForm.querySelector('.md-form-actions');
      if (actionRow && actionRow.parentNode) {
        actionRow.parentNode.insertBefore(offlineStatus, actionRow);
      } else {
        assessmentForm.appendChild(offlineStatus);
      }

      const submitControls = Array.from(assessmentForm.querySelectorAll('button[type="submit"], input[type="submit"]'));

      const captureSubmitAction = (control) => {
        if (!control) {
          return;
        }
        const remember = () => {
          if (control.name === 'action') {
            lastSubmitAction = control.value || null;
          } else {
            lastSubmitAction = null;
          }
        };
        control.addEventListener('click', remember);
        control.addEventListener('keydown', (event) => {
          if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
            remember();
          }
        });
      };

      submitControls.forEach(captureSubmitAction);

      const resolveSubmitAction = (event) => {
        if (event && event.submitter && event.submitter.name === 'action') {
          return event.submitter.value || null;
        }
        if (lastSubmitAction !== null) {
          return lastSubmitAction;
        }
        const active = document.activeElement;
        if (active && active.form === assessmentForm && active.name === 'action') {
          return active.value || null;
        }
        const defaultSubmit = submitControls.find((control) => control.name === 'action');
        return defaultSubmit ? (defaultSubmit.value || null) : null;
      };

      const formatTimestamp = (value) => {
        if (!value) {
          return '';
        }
        const date = value instanceof Date ? value : new Date(value);
        if (Number.isNaN(date.getTime())) {
          return '';
        }
        try {
          return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
        } catch (err) {
          return date.toLocaleString();
        }
      };

      const setStatusMessage = (state, timestamp) => {
        let template = '';
        switch (state) {
          case 'saved':
            template = offlineLabels.saved;
            break;
          case 'restored':
            template = offlineLabels.restored;
            break;
          case 'queued':
            template = offlineLabels.queued;
            break;
          case 'reminder':
            template = offlineLabels.reminder;
            break;
          case 'error':
            template = offlineLabels.error;
            break;
          default:
            template = '';
        }
        if (!template) {
          offlineStatus.dataset.state = '';
          offlineStatus.textContent = '';
          offlineStatus.hidden = true;
          return;
        }
        let message = template;
        if (message.includes('%s') && timestamp) {
          message = message.replace('%s', formatTimestamp(timestamp));
        }
        offlineStatus.dataset.state = state;
        offlineStatus.textContent = message;
        offlineStatus.hidden = false;
      };

      const readStoredDraft = () => {
        try {
          const raw = window.localStorage.getItem(getStorageKey());
          if (!raw) {
            return null;
          }
          const parsed = JSON.parse(raw);
          if (!parsed || typeof parsed !== 'object') {
            return null;
          }
          return parsed;
        } catch (err) {
          return null;
        }
      };

      const clearDraft = () => {
        try {
          window.localStorage.removeItem(getStorageKey());
        } catch (err) {
          // Ignore storage failures
        }
      };

      const applyValues = (data) => {
        if (!data || typeof data !== 'object') {
          return;
        }
        Object.keys(data).forEach((name) => {
          if (name === 'csrf') {
            return;
          }
          const fields = Array.from(assessmentForm.elements).filter((field) => field.name === name);
          if (!fields.length) {
            return;
          }
          const rawValue = data[name];
          const values = Array.isArray(rawValue) ? rawValue.map((value) => String(value)) : [String(rawValue)];
          fields.forEach((field) => {
            if (field.type === 'checkbox' || field.type === 'radio') {
              field.checked = values.includes(field.value);
            } else if (field.tagName === 'SELECT' && field.multiple) {
              Array.from(field.options).forEach((option) => {
                option.selected = values.includes(option.value);
              });
            } else if (field.tagName === 'SELECT') {
              const value = values.length ? values[values.length - 1] : '';
              field.value = value;
            } else {
              const value = values.length ? values[values.length - 1] : '';
              field.value = value;
            }
          });
        });
      };

      const persistDraft = () => {
        try {
          const formData = new FormData(assessmentForm);
          const payload = {};
          formData.forEach((value, key) => {
            if (key === 'csrf') {
              return;
            }
            if (Object.prototype.hasOwnProperty.call(payload, key)) {
              const existing = payload[key];
              if (Array.isArray(existing)) {
                existing.push(value);
              } else {
                payload[key] = [existing, value];
              }
            } else {
              payload[key] = value;
            }
          });
          const record = {
            data: payload,
            savedAt: Date.now(),
            pendingSubmit,
          };
          window.localStorage.setItem(getStorageKey(), JSON.stringify(record));
          setStatusMessage(pendingSubmit ? 'queued' : 'saved', record.savedAt);
        } catch (err) {
          setStatusMessage('error');
        }
      };

      const scheduleSave = (() => {
        let timer = null;
        return () => {
          if (timer) {
            clearTimeout(timer);
          }
          timer = setTimeout(() => {
            timer = null;
            persistDraft();
          }, 400);
        };
      })();

      assessmentForm.addEventListener('input', (event) => {
        scheduleSave();
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
          return;
        }
        const field = target.closest('[data-question-anchor]');
        if (field) {
          field.classList.remove('md-field--missing');
        }
      });
      assessmentForm.addEventListener('change', (event) => {
        scheduleSave();
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
          return;
        }
        const field = target.closest('[data-question-anchor]');
        if (field) {
          field.classList.remove('md-field--missing');
        }
      });

      const params = new URLSearchParams(window.location.search);
      if (params.get('saved') === 'draft') {
        clearDraft();
      }

      const storedDraft = readStoredDraft();
      if (storedDraft && storedDraft.data) {
        applyValues(storedDraft.data);
        pendingSubmit = Boolean(storedDraft.pendingSubmit);
        if (pendingSubmit) {
          setStatusMessage(isAppOnline() ? 'reminder' : 'queued', storedDraft.savedAt);
        } else {
          setStatusMessage('restored', storedDraft.savedAt);
        }
      }

      assessmentForm.addEventListener('submit', (event) => {
        const submitAction = resolveSubmitAction(event);
        lastSubmitAction = null;
        const isFinalSubmit = submitAction === 'submit_final';
        const finalSubmitConfirmationMessage = 'Please review your responses carefully before submitting. Once submitted, you will not be able to make any changes.';

        if (isFinalSubmit && !window.confirm(finalSubmitConfirmationMessage)) {
          event.preventDefault();
          return;
        }

        clearMissingQuestionHighlights();
        if (isFinalSubmit) {
          applyMissingQuestionHighlights();
        }

        if (!isAppOnline()) {
          event.preventDefault();
          pendingSubmit = isFinalSubmit;
          persistDraft();
        } else {
          pendingSubmit = isFinalSubmit;
          persistDraft();
        }
      });

      observeConnectivity(function (state) {
        if (!state || !state.online) {
          return;
        }
        const draft = readStoredDraft();
        if (draft && draft.pendingSubmit) {
          pendingSubmit = true;
          setStatusMessage('reminder', draft.savedAt);
        }
      });
    }
  })();
  </script>
</div>
</section>
<?php include __DIR__.'/templates/footer.php'; ?>
</body></html>
