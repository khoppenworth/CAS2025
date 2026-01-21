<?php
require_once __DIR__.'/utils.php';
require_once __DIR__.'/../lib/scoring.php';
if ($_SERVER['REQUEST_METHOD']==='GET') {
  $entries = [];
  $rs = $pdo->query("SELECT qr.*, pp.label AS period_label FROM questionnaire_response qr JOIN performance_period pp ON pp.id = qr.performance_period_id ORDER BY qr.id DESC");
  foreach ($rs as $r) {
    $items = $pdo->prepare("SELECT linkId, answer FROM questionnaire_response_item WHERE response_id=?");
    $items->execute([$r['id']]);
    $entries[] = ["resource"=>[
      "resourceType"=>"QuestionnaireResponse",
      "id"=>$r['id'],
      "questionnaire"=>$r['questionnaire_id'],
      "status"=>$r['status'],
      "authored"=>$r['created_at'],
      "performancePeriod"=>$r['period_label'],
      "item"=>array_map(function($it){ return ["linkId"=>$it['linkId'],"answer"=>json_decode($it['answer'], true)]; }, $items->fetchAll())
    ]];
  }
  echo json_encode(bundle($entries)); exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $data = json_decode(file_get_contents('php://input'), true);
  if (($data['resourceType'] ?? '')!=='QuestionnaireResponse') { http_response_code(400); echo json_encode(["error"=>"Invalid resourceType"]); exit; }
  $uid = (int)($data['user_id'] ?? 0);
  $qid = (int)($data['questionnaire'] ?? 0);
  if (!$uid || !$qid) { http_response_code(400); echo json_encode(["error"=>"user_id and questionnaire required"]); exit; }
  $periodId = (int)($data['performance_period_id'] ?? 0);
  if (!$periodId && !empty($data['performance_period'])) {
    $label = (string)$data['performance_period'];
    $lookup = $pdo->prepare('SELECT id FROM performance_period WHERE label = ?');
    $lookup->execute([$label]);
    $periodId = (int)($lookup->fetchColumn() ?: 0);
  }
  if (!$periodId) {
    $current = date('Y');
    $lookup = $pdo->prepare('SELECT id FROM performance_period WHERE label = ?');
    $lookup->execute([$current]);
    $periodId = (int)($lookup->fetchColumn() ?: 0);
  }
  if (!$periodId) {
    http_response_code(400); echo json_encode(["error"=>"performance_period_id required"]); exit;
  }

  $pdo->beginTransaction();
  try {
    $pdo->prepare("INSERT INTO questionnaire_response (user_id, questionnaire_id, performance_period_id, status, created_at) VALUES (?,?,?, 'submitted', NOW())")->execute([$uid,$qid,$periodId]);
    $rid = (int)$pdo->lastInsertId();

    // Calculate weighted score
    $items_meta = $pdo->prepare("SELECT id, linkId, type, allow_multiple, weight_percent FROM questionnaire_item WHERE questionnaire_id=?");
    $items_meta->execute([$qid]);
    $meta = [];
    $metaRows = $items_meta->fetchAll(PDO::FETCH_ASSOC);
    $nonScorableTypes = ['display', 'group', 'section'];
    $singleChoiceWeightMap = questionnaire_even_single_choice_weights($metaRows);
    $likertWeightMap = questionnaire_even_likert_weights($metaRows);
    $correctByItemId = [];
    if ($metaRows) {
      $itemIds = array_values(array_filter(array_map(static function ($row) {
        return isset($row['id']) ? (int)$row['id'] : 0;
      }, $metaRows)));
      $itemIds = array_values(array_unique(array_filter($itemIds)));
      if ($itemIds) {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $optStmt = $pdo->prepare("SELECT questionnaire_item_id, value FROM questionnaire_item_option WHERE questionnaire_item_id IN ($placeholders) AND is_correct=1 ORDER BY questionnaire_item_id, order_index, id");
        $optStmt->execute($itemIds);
        foreach ($optStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
          $itemId = (int)$row['questionnaire_item_id'];
          if (!isset($correctByItemId[$itemId])) {
            $correctByItemId[$itemId] = (string)($row['value'] ?? '');
          }
        }
      }
    }
    foreach ($metaRows as $row) {
      $type = (string)($row['type'] ?? '');
      $isScorable = !in_array($type, $nonScorableTypes, true);
      $row['computed_weight'] = questionnaire_resolve_effective_weight($row, $singleChoiceWeightMap, $likertWeightMap, $isScorable);
      $row['correct_value'] = $correctByItemId[(int)($row['id'] ?? 0)] ?? null;
      $key = isset($row['linkId']) ? (string)$row['linkId'] : '';
      $meta[$key] = $row;
    }
    $score_sum = 0.0; $max_points = 0.0;

    foreach (($data['item'] ?? []) as $it) {
      $lid = $it['linkId'] ?? '';
      $ansArr = $it['answer'] ?? [];
      $ans = json_encode($ansArr);
      $pdo->prepare("INSERT INTO questionnaire_response_item (response_id, linkId, answer) VALUES (?,?,?)")->execute([$rid, $lid, $ans]);

      $metaRow = $meta[$lid] ?? null;
      $type = is_array($metaRow) ? (string)($metaRow['type'] ?? '') : '';
      $isScorable = !in_array($type, $nonScorableTypes, true);
      $effectiveWeight = 0.0;
      if ($metaRow) {
        $effectiveWeight = isset($metaRow['computed_weight'])
          ? (float)$metaRow['computed_weight']
          : questionnaire_resolve_effective_weight($metaRow, $singleChoiceWeightMap, $likertWeightMap, $isScorable);
      } elseif ($isScorable) {
        $effectiveWeight = questionnaire_resolve_effective_weight([], $singleChoiceWeightMap, $likertWeightMap, $isScorable);
      }
      $achievedPoints = 0.0;

      if (!is_array($ansArr)) { $ansArr = []; }

      if ($type === 'boolean') {
        foreach ($ansArr as $a) {
          if (isset($a['valueBoolean'])) {
            if (filter_var($a['valueBoolean'], FILTER_VALIDATE_BOOLEAN)) {
              $achievedPoints = $effectiveWeight;
            }
            break;
          }
          if (isset($a['valueString'])) {
            $val = strtolower(trim((string)$a['valueString']));
            if (in_array($val, ['true','1','yes','on'], true)) {
              $achievedPoints = $effectiveWeight;
            }
            break;
          }
        }
      } elseif ($type === 'likert') {
        $scoreValue = null;
        foreach ($ansArr as $a) {
          if (isset($a['valueInteger']) && is_numeric($a['valueInteger'])) {
            $scoreValue = (float)$a['valueInteger'];
            break;
          }
          if (isset($a['valueDecimal']) && is_numeric($a['valueDecimal'])) {
            $scoreValue = (float)$a['valueDecimal'];
            break;
          }
          if (isset($a['valueString'])) {
            $str = trim((string)$a['valueString']);
            if (preg_match('/^([1-5])/', $str, $matches)) {
              $scoreValue = (float)$matches[1];
              break;
            }
            if (is_numeric($str)) {
              $scoreValue = (float)$str;
              break;
            }
          }
        }
        if ($scoreValue !== null) {
          $scoreValue = max(0.0, min(5.0, $scoreValue));
          $achievedPoints = $effectiveWeight * ($scoreValue / 5.0);
        }
      } elseif ($type === 'choice') {
        $allowMultiple = !empty($metaRow['allow_multiple']);
        if (!$allowMultiple) {
          $correctValue = isset($metaRow['correct_value']) ? (string)$metaRow['correct_value'] : '';
          if ($correctValue !== '') {
            foreach ($ansArr as $a) {
              if (isset($a['valueString']) && (string)$a['valueString'] === $correctValue) {
                $achievedPoints = $effectiveWeight;
                break;
              }
            }
          }
        } else {
          $hasSelection = false;
          foreach ($ansArr as $a) {
            if (isset($a['valueString']) && trim((string)$a['valueString']) !== '') {
              $hasSelection = true;
              break;
            }
            if (isset($a['valueCoding'])) {
              $coding = $a['valueCoding'];
              if (is_array($coding)) {
                $code = isset($coding['code']) ? trim((string)$coding['code']) : '';
                $display = isset($coding['display']) ? trim((string)$coding['display']) : '';
                if ($code !== '' || $display !== '') {
                  $hasSelection = true;
                  break;
                }
              }
            }
          }
          if ($hasSelection) {
            $achievedPoints = $effectiveWeight;
          }
        }
      } else {
        foreach ($ansArr as $a) {
          if (isset($a['valueBoolean'])) {
            if (filter_var($a['valueBoolean'], FILTER_VALIDATE_BOOLEAN)) {
              $achievedPoints = $effectiveWeight;
            }
            break;
          }
          if (isset($a['valueString']) && trim((string)$a['valueString']) !== '') {
            $achievedPoints = $effectiveWeight;
            break;
          }
          if (isset($a['valueInteger']) && $a['valueInteger'] !== '') {
            $achievedPoints = $effectiveWeight;
            break;
          }
          if (isset($a['valueDecimal']) && $a['valueDecimal'] !== '') {
            $achievedPoints = $effectiveWeight;
            break;
          }
        }
      }

      if ($isScorable) {
        $max_points += $effectiveWeight;
        $score_sum += max(0.0, min($effectiveWeight, $achievedPoints));
      }
    }
    $pctRaw = $max_points > 0 ? ($score_sum / $max_points) * 100 : 0.0;
    $pct = (int)round(max(0.0, min(100.0, $pctRaw)));
    $pdo->prepare("UPDATE questionnaire_response SET score=? WHERE id=?")->execute([$pct, $rid]);
    $pdo->commit();
    echo json_encode(["id"=>$rid, "status"=>"created"]);
  } catch (Exception $e) {
    $pdo->rollBack(); http_response_code(500); echo json_encode(["error"=>$e->getMessage()]);
  }
  exit;
}
http_response_code(405); echo json_encode(["error"=>"Method not allowed"]);
?>
