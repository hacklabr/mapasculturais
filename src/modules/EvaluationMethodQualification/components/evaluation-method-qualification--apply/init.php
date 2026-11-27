<?php

use MapasCulturais\Entities\Registration;
use MapasCulturais\i;

$opportunity = $this->controller->requestedEntity;
$em = $opportunity->getEvaluationMethod();

$status_list = [];
$statuses_names = $opportunity->statusLabels ?: $opportunity->evaluationMethodConfiguration->defaultStatuses;

foreach ($statuses_names as $status => $status_name) {
    if ((int) $status !== Registration::STATUS_DRAFT) {
        $status_list[] = ["status" => (int) $status, "label" => $status_name];
    }
}

$this->jsObject['config']['evaluation-method-qualification--apply'] = [
    'statusList' => $status_list,
    'consolidated_results' => $em->findConsolidatedResult($opportunity),
    'defaultStatusByResult' => [
        'valid' => Registration::STATUS_APPROVED,
        'invalid' => Registration::STATUS_NOTAPPROVED,
    ],
];
