<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$seals_filter_enabled = $app->config['events.filter.seals'] ?? false;

// O dataset de estados/cidades agora é publicado pelo componente <search> (ADR 0019);
// a variável antiga events.filter.statesAndCities não tem mais efeito (#118/#121
// removem o uso remanescente desta flag no script/template deste filtro).
$states_cities_filter_enabled = false;

$seals = [];
if ($seals_filter_enabled) {
    $query = new MapasCulturais\ApiQuery(MapasCulturais\Entities\Seal::class, [
        '@select' => 'id,name',
        '@order'  => 'name ASC',
    ]);
    $seals = $query->getFindResult();
}

$this->jsObject['config']['searchFilterEvent'] = [
    'statesAndCitiesFilterEnabled' => $states_cities_filter_enabled,
    'sealsFilterEnabled'           => $seals_filter_enabled,
    'seals'                        => $seals,
];
