<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/scoring.php';

$items = [
    ['id' => 1, 'linkId' => 'single_a', 'type' => 'choice', 'allow_multiple' => 0],
    ['id' => 2, 'linkId' => 'single_b', 'type' => 'choice', 'allow_multiple' => 0, 'weight_percent' => 25],
    ['id' => 3, 'linkId' => 'bool_a', 'type' => 'boolean', 'weight_percent' => 15],
    ['id' => 4, 'linkId' => 'text_a', 'type' => 'text'],
];

$singleChoiceWeights = questionnaire_even_single_choice_weights($items);
$likertWeights = questionnaire_even_likert_weights($items);
if (count($singleChoiceWeights) !== 2) {
    fwrite(STDERR, "Expected two single-choice weights.\n");
    exit(1);
}

$weightA = questionnaire_resolve_effective_weight($items[0], $singleChoiceWeights, $likertWeights, true);
$weightB = questionnaire_resolve_effective_weight($items[1], $singleChoiceWeights, $likertWeights, true);
$weightBoolean = questionnaire_resolve_effective_weight($items[2], $singleChoiceWeights, $likertWeights, true);
$weightText = questionnaire_resolve_effective_weight($items[3], $singleChoiceWeights, $likertWeights, true);

if (abs($weightA - 50.0) > 0.001) {
    fwrite(STDERR, "Single-choice auto weight calculation failed.\n");
    exit(1);
}

if (abs($weightB - 25.0) > 0.001) {
    fwrite(STDERR, "Explicit single-choice weight should override auto distribution.\n");
    exit(1);
}

if (abs($weightBoolean - 15.0) > 0.001) {
    fwrite(STDERR, "Boolean weight was lost when single-choice auto weights were present.\n");
    exit(1);
}

if ($weightText !== 0.0) {
    fwrite(STDERR, "Unweighted non-single-choice item should not receive implicit weight.\n");
    exit(1);
}

$nonScorable = ['id' => 5, 'linkId' => 'section_1', 'type' => 'section', 'weight_percent' => 10];
if (questionnaire_resolve_effective_weight($nonScorable, $singleChoiceWeights, $likertWeights, false) !== 0.0) {
    fwrite(STDERR, "Non-scorable items must yield zero weight.\n");
    exit(1);
}

echo "Questionnaire scoring tests passed.\n";
