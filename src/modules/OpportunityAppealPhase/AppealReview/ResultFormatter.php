<?php

namespace OpportunityAppealPhase\AppealReview;

use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\i;

/**
 * R06 (#131) — CA-18: formatação dos resultados de correção por método de
 * avaliação da fase principal.
 *
 * Até a R06 as colunas de correção eram numéricas e incondicionais: no método
 * simplificado a "média" era média aritmética de códigos de status (sem
 * semântica), na habilitação documental o toNumericScore anulava tudo
 * (colunas mortas) e na documental a média de 1/−1 não significava nada.
 *
 * Este formatador centraliza a regra por método:
 * - técnico: mantém o pipeline numérico (nota/média original, corrigida e
 *   diferença) — comportamento preservado byte a byte;
 * - simplificada / documental / habilitação documental: as colunas de valor
 *   exibem o RÓTULO do resultado no vocabulário do próprio método (mapa de
 *   rótulos do valueToString: Selecionada/Suplente/... no simples; Válida/
 *   Inválida na documental; Habilitado/Inabilitado na habilitação) e a
 *   coluna "Diferença" NÃO é exibida (lista de inscritos, lista de
 *   avaliações e exportações).
 *
 * Os rótulos de cabeçalho também saem daqui (fonte única consumida pelos
 * init.php dos componentes opportunity-registrations-table e
 * opportunity-evaluations-table e pelo JobType de planilha de inscrições —
 * backend não tem texts.php).
 */
class ResultFormatter
{
    /**
     * Métodos cujas colunas de correção mantêm semântica numérica (nota).
     * Método desconhecido/nulo cai no caminho numérico por segurança
     * (comportamento anterior à R06).
     */
    public static function isNumericMethod(?string $method): bool
    {
        return $method === null || $method === '' || $method === 'technical';
    }

    /**
     * CA-18: a coluna "Diferença" só existe no método técnico.
     */
    public static function showDifference(?string $method): bool
    {
        return $method === 'technical';
    }

    /**
     * Valor de resultado como string de exibição, no vocabulário do próprio
     * método (valueToString do módulo do método — mesma fonte da coluna
     * "Resultado do avaliador").
     *
     * Métodos numéricos retornam null: o pipeline numérico continua no
     * consumidor (comportamento técnico preservado).
     */
    public static function formatValue(?string $method, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (self::isNumericMethod($method)) {
            return null;
        }

        $evaluation_method = self::evaluationMethod((string) $method);
        if (!$evaluation_method) {
            return null;
        }

        $formatted = $evaluation_method->valueToString($value);

        return $formatted !== '' ? (string) $formatted : null;
    }

    /**
     * Consolida valores crus de slots (result/originalValue) para o nível
     * inscrição, espelhando a aritmética de _getConsolidatedResult de cada
     * método sobre os slots ENVIADOS — sem alterar a consolidação oficial
     * (exibição somente).
     *
     * - simplificada: mínimo dos códigos de status;
     * - documental: 1 (válida) só se todos os slots forem 1, senão −1;
     * - habilitação: 'invalid' se algum slot for 'invalid', senão 'valid'.
     */
    public static function consolidate(string $method, array $values): mixed
    {
        return match ($method) {
            'simple' => self::consolidateSimple($values),
            'documentary' => self::consolidateDocumentary($values),
            'qualification' => self::consolidateQualification($values),
            default => null,
        };
    }

    /**
     * Rótulos de cabeçalho por método (fonte única das listas e exportações).
     *
     * $context:
     * - 'registration': lista de inscritos (nível inscrição);
     * - 'slot': lista de avaliações e planilhas por slot;
     * - 'export': planilha de inscrições (rótulos descritivos mais longos).
     *
     * Chave 'difference' null = coluna não registrada/não exportada
     * (CA-18: fora do técnico não há coluna de diferença).
     *
     * @return array{original: string, corrected: string, difference: ?string}
     */
    public static function headerLabels(?string $method, string $context = 'registration'): array
    {
        if (!self::isNumericMethod($method)) {
            return match ($context) {
                'export' => [
                    'original' => i::__('Resultado original (antes das correções de recurso)'),
                    'corrected' => i::__('Resultado corrigido (vigente)'),
                    'difference' => null,
                ],
                default => [
                    'original' => i::__('Resultado original'),
                    'corrected' => i::__('Resultado corrigido'),
                    'difference' => null,
                ],
            };
        }

        return match ($context) {
            'slot' => [
                'original' => i::__('Nota original'),
                'corrected' => i::__('Nota corrigida'),
                'difference' => i::__('Diferença'),
            ],
            'export' => [
                'original' => i::__('Média original (antes das correções de recurso)'),
                'corrected' => i::__('Média corrigida (vigente)'),
                'difference' => i::__('Diferença (média corrigida - média original)'),
            ],
            default => [
                'original' => i::__('Média original'),
                'corrected' => i::__('Média corrigida'),
                'difference' => i::__('Diferença'),
            ],
        };
    }

    /**
     * Método de avaliação que dá origem às notas/correções da oportunidade em
     * contexto: na fase de recurso as colunas refletem a fase PRINCIPAL
     * (onde vivem os slots corrigidos), não o EMC da própria fase de recurso.
     */
    public static function methodForOpportunity(?Opportunity $opportunity): ?string
    {
        if (!$opportunity) {
            return null;
        }

        if ($opportunity->status === Opportunity::STATUS_APPEAL_PHASE && $opportunity->parent) {
            $opportunity = $opportunity->parent;
        }

        return $opportunity->evaluationMethodConfiguration?->type?->id;
    }

    /**
     * Existe alguma designação de correção (registration_appeal_review) para
     * avaliações desta oportunidade (fase principal)? Usado pelas exportações
     * por slot para decidir se acrescentam as colunas original/corrigida.
     */
    public static function opportunityHasAppealReviews(Opportunity $opportunity): bool
    {
        if ($opportunity->status === Opportunity::STATUS_APPEAL_PHASE && $opportunity->parent) {
            $opportunity = $opportunity->parent;
        }

        $exists = App::i()->em->getConnection()->fetchOne("
            SELECT EXISTS (
                SELECT 1
                FROM registration_appeal_review r
                JOIN registration reg ON reg.id = r.registration_id
                WHERE reg.opportunity_id = :opportunity
            )
        ", ['opportunity' => $opportunity->id]);

        return (bool) $exists;
    }

    /**
     * @return \MapasCulturais\EvaluationMethod|null
     */
    private static function evaluationMethod(string $slug): ?\MapasCulturais\EvaluationMethod
    {
        $definition = App::i()->getRegisteredEvaluationMethodBySlug($slug);

        return $definition?->evaluationMethod;
    }

    /**
     * Espelho de EvaluationMethodSimple::_getConsolidatedResult (mínimo dos
     * códigos; slots sem resultado não influenciam).
     */
    private static function consolidateSimple(array $values): mixed
    {
        $result = 10;

        foreach ($values as $value) {
            if ($value !== null && $value !== '' && is_numeric($value) && (float) $value < (float) $result) {
                $result = (float) $value;
            }
        }

        return $result;
    }

    /**
     * Espelho de EvaluationMethodDocumentary::_getConsolidatedResult
     * (1 só se todos os slots válidos).
     */
    private static function consolidateDocumentary(array $values): mixed
    {
        $result = 1;

        foreach ($values as $value) {
            $result = ($result === 1 && $value == 1) ? 1 : -1;
        }

        return $result;
    }

    /**
     * Espelho de EvaluationMethodQualification::_getConsolidatedResult
     * ('invalid' se algum slot inabilitado).
     */
    private static function consolidateQualification(array $values): mixed
    {
        $result = 'valid';

        foreach ($values as $value) {
            if ($value == 'invalid') {
                $result = 'invalid';
            }
        }

        return $result;
    }
}
