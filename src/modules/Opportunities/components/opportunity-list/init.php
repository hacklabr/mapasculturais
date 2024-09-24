<?php
$app->log->debug(print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), true));
$entity = $this->controller->requestedEntity;
$relatedOpportunities = $entity->getOpportunities();

$orderEntities = function ($a, $b) {
    return $a->registrationTo <=> $b->registrationTo;
};

usort($relatedOpportunities, $orderEntities);

$opportunities = [];

foreach($relatedOpportunities as $opportunity) {
    $opportunities[] = $opportunity->simplify("id,name,avatar,registrationFrom,registrationTo");
}

$this->jsObject['opportunityList']['opportunity'] = $opportunities;


