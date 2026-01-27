#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Repair questionnaires that share the same ID by cloning their structure
 * to new IDs and removing the duplicate rows.
 *
 * Usage: php scripts/fix_linked_questionnaires.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only be executed from the command line." . PHP_EOL);
    exit(1);
}

define('APP_BOOTSTRAPPED', true);
require_once __DIR__ . '/../config.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$duplicateStmt = $pdo->query('SELECT id, COUNT(*) AS row_count FROM questionnaire GROUP BY id HAVING row_count > 1');
$duplicateGroups = $duplicateStmt ? $duplicateStmt->fetchAll() : [];

if (!$duplicateGroups) {
    echo 'No duplicate questionnaire IDs detected.' . PHP_EOL;
    exit(0);
}

echo 'Found ' . count($duplicateGroups) . ' questionnaire ID group(s) with duplicates.' . PHP_EOL;

$questionnaireStmt = $pdo->prepare('SELECT * FROM questionnaire WHERE id = ? ORDER BY created_at ASC, title ASC');
$sectionStmt = $pdo->prepare('SELECT * FROM questionnaire_section WHERE questionnaire_id = ? ORDER BY order_index, id');
$itemStmt = $pdo->prepare('SELECT * FROM questionnaire_item WHERE questionnaire_id = ? ORDER BY order_index, id');
$optionStmt = $pdo->prepare('SELECT * FROM questionnaire_item_option WHERE questionnaire_item_id = ? ORDER BY order_index, id');
$workFunctionStmt = $pdo->prepare('SELECT work_function FROM questionnaire_work_function WHERE questionnaire_id = ?');

$insertQuestionnaireStmt = $pdo->prepare(
    'INSERT INTO questionnaire (title, description, status, created_at) VALUES (?, ?, ?, ?)'
);
$insertSectionStmt = $pdo->prepare(
    'INSERT INTO questionnaire_section (questionnaire_id, title, description, order_index, is_active) VALUES (?, ?, ?, ?, ?)'
);
$insertItemStmt = $pdo->prepare(
    'INSERT INTO questionnaire_item (questionnaire_id, section_id, linkId, text, type, order_index, weight_percent, allow_multiple, is_required, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$insertOptionStmt = $pdo->prepare(
    'INSERT INTO questionnaire_item_option (questionnaire_item_id, value, is_correct, order_index) VALUES (?, ?, ?, ?)'
);
$insertWorkFunctionStmt = $pdo->prepare(
    'INSERT INTO questionnaire_work_function (questionnaire_id, work_function) VALUES (?, ?)'
);
$deleteQuestionnaireStmt = $pdo->prepare(
    'DELETE FROM questionnaire WHERE id = ? AND title <=> ? AND description <=> ? AND status <=> ? AND created_at <=> ? LIMIT 1'
);

foreach ($duplicateGroups as $group) {
    $duplicateId = (int)($group['id'] ?? 0);
    if ($duplicateId <= 0) {
        continue;
    }

    $questionnaireStmt->execute([$duplicateId]);
    $questionnaires = $questionnaireStmt->fetchAll();

    if (count($questionnaires) < 2) {
        continue;
    }

    $sectionStmt->execute([$duplicateId]);
    $sections = $sectionStmt->fetchAll();
    $itemStmt->execute([$duplicateId]);
    $items = $itemStmt->fetchAll();

    $workFunctionStmt->execute([$duplicateId]);
    $workFunctions = $workFunctionStmt->fetchAll();

    $itemsBySection = [];
    $rootItems = [];
    foreach ($items as $item) {
        if ($item['section_id'] === null) {
            $rootItems[] = $item;
        } else {
            $itemsBySection[(int)$item['section_id']][] = $item;
        }
    }

    $pdo->beginTransaction();
    try {
        foreach (array_slice($questionnaires, 1) as $duplicateRow) {
            $insertQuestionnaireStmt->execute([
                $duplicateRow['title'],
                $duplicateRow['description'],
                $duplicateRow['status'],
                $duplicateRow['created_at'],
            ]);
            $newQuestionnaireId = (int)$pdo->lastInsertId();

            $sectionIdMap = [];
            foreach ($sections as $section) {
                $insertSectionStmt->execute([
                    $newQuestionnaireId,
                    $section['title'],
                    $section['description'],
                    $section['order_index'],
                    $section['is_active'],
                ]);
                $sectionIdMap[(int)$section['id']] = (int)$pdo->lastInsertId();
            }

            $itemIdMap = [];
            foreach ($rootItems as $item) {
                $insertItemStmt->execute([
                    $newQuestionnaireId,
                    null,
                    $item['linkId'],
                    $item['text'],
                    $item['type'],
                    $item['order_index'],
                    $item['weight_percent'],
                    $item['allow_multiple'],
                    $item['is_required'],
                    $item['is_active'],
                ]);
                $itemIdMap[(int)$item['id']] = (int)$pdo->lastInsertId();
            }

            foreach ($itemsBySection as $sectionId => $sectionItems) {
                $newSectionId = $sectionIdMap[$sectionId] ?? null;
                if ($newSectionId === null) {
                    continue;
                }
                foreach ($sectionItems as $item) {
                    $insertItemStmt->execute([
                        $newQuestionnaireId,
                        $newSectionId,
                        $item['linkId'],
                        $item['text'],
                        $item['type'],
                        $item['order_index'],
                        $item['weight_percent'],
                        $item['allow_multiple'],
                        $item['is_required'],
                        $item['is_active'],
                    ]);
                    $itemIdMap[(int)$item['id']] = (int)$pdo->lastInsertId();
                }
            }

            foreach ($itemIdMap as $oldItemId => $newItemId) {
                $optionStmt->execute([$oldItemId]);
                foreach ($optionStmt->fetchAll() as $option) {
                    $insertOptionStmt->execute([
                        $newItemId,
                        $option['value'],
                        $option['is_correct'],
                        $option['order_index'],
                    ]);
                }
            }

            foreach ($workFunctions as $wf) {
                if (!isset($wf['work_function'])) {
                    continue;
                }
                $insertWorkFunctionStmt->execute([$newQuestionnaireId, $wf['work_function']]);
            }

            $deleteQuestionnaireStmt->execute([
                $duplicateId,
                $duplicateRow['title'],
                $duplicateRow['description'],
                $duplicateRow['status'],
                $duplicateRow['created_at'],
            ]);

            echo sprintf(
                'Cloned questionnaire "%s" into new ID %d to replace duplicate ID %d.' . PHP_EOL,
                (string)$duplicateRow['title'],
                $newQuestionnaireId,
                $duplicateId
            );
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, 'Failed to repair questionnaire ID ' . $duplicateId . ': ' . $e->getMessage() . PHP_EOL);
    }
}
