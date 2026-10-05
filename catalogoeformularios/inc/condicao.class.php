<?php

/**
 * Facilitador da visibilidade condicional de perguntas (GLPI 11).
 *
 * Cada pergunta nativa (glpi_forms_questions) tem:
 *   - visibility_strategy : '' (sempre visivel) | 'visible_if' | 'hidden_if'
 *   - conditions          : JSON com a lista de condicoes
 *
 * O motor de condicoes (\Glpi\Form\Condition\*) varia entre versoes 11.0.x, por
 * isso as regras sao montadas pelas proprias classes nativas via reflexao
 * (formato garantido pela versao) e protegidas por try/catch. Se nao for possivel,
 * o usuario e direcionado ao editor nativo, sem corromper a pergunta.
 */
class PluginCatalogoeformulariosCondicao extends CommonGLPI
{
    // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no GLPI 11,
    // e o PHP exige o mesmo tipo da classe pai. As permissões são sobrescritas abaixo.
    private const RIGHTNAME = 'config';

    public static function canCreate(): bool
    {
        return Session::haveRight(self::RIGHTNAME, CREATE);
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::RIGHTNAME, READ);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight(self::RIGHTNAME, UPDATE);
    }

    public static function canDelete(): bool
    {
        return Session::haveRight(self::RIGHTNAME, DELETE);
    }

    public static function canPurge(): bool
    {
        return Session::haveRight(self::RIGHTNAME, PURGE);
    }

    private const Q     = '\\Glpi\\Form\\Question';
    private const SEC   = '\\Glpi\\Form\\Section';
    private const VIS   = '\\Glpi\\Form\\Condition\\VisibilityStrategy';
    private const VALOP = '\\Glpi\\Form\\Condition\\ValueOperator';
    private const LOGIC = '\\Glpi\\Form\\Condition\\LogicOperator';
    private const COND  = '\\Glpi\\Form\\Condition\\ConditionData';

    /** O motor de condicoes esta disponivel nesta versao? */
    public static function suportado(): bool
    {
        return enum_exists(self::VIS) && class_exists(self::COND);
    }

    /** Estrategias de visibilidade disponiveis para o front. */
    public static function estrategiasVisibilidade(): array
    {
        $out = [['slug' => 'sempre', 'label' => 'Sempre visivel']];
        if (enum_exists(self::VIS)) {
            if (self::caso(self::VIS, ['VISIBLE_IF']) !== null) {
                $out[] = ['slug' => 'visivel_se', 'label' => 'Visivel se...'];
            }
            if (self::caso(self::VIS, ['HIDDEN_IF']) !== null) {
                $out[] = ['slug' => 'oculto_se', 'label' => 'Oculto se...'];
            }
        }
        return $out;
    }

    /** Operadores de comparacao disponiveis. */
    public static function operadores(): array
    {
        $map = [
            'equals'        => 'igual a',
            'not_equals'    => 'diferente de',
            'contains'      => 'contem',
            'not_contains'  => 'nao contem',
            'greater_than'  => 'maior que',
            'less_than'     => 'menor que',
            'empty'         => 'vazio',
            'not_empty'     => 'preenchido',
        ];

        $out   = [];
        $valop = self::VALOP;
        if (enum_exists($valop)) {
            foreach ($valop::cases() as $c) {
                $slug  = strtolower($c->name);
                $out[] = ['slug' => $slug, 'label' => $map[$slug] ?? $c->name];
            }
        } else {
            foreach ($map as $s => $l) {
                $out[] = ['slug' => $s, 'label' => $l];
            }
        }
        return $out;
    }

    /** Le a regra de visibilidade atual de uma pergunta. */
    public static function getRegra(int $perguntaId): array
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['visibility_strategy', 'conditions'],
            'FROM'   => 'glpi_forms_questions',
            'WHERE'  => ['id' => $perguntaId],
            'LIMIT'  => 1,
        ])->current();

        if ($row === null) {
            return ['estrategia' => 'sempre', 'condicoes' => []];
        }

        $estr = (string) ($row['visibility_strategy'] ?? '');
        $cond = $row['conditions'] ?? [];
        if (is_string($cond)) {
            $cond = json_decode($cond, true) ?: [];
        }

        $slug = 'sempre';
        if (stripos($estr, 'visible') !== false && stripos($estr, 'always') === false) {
            $slug = 'visivel_se';
        } elseif (stripos($estr, 'hidden') !== false) {
            $slug = 'oculto_se';
        }

        return ['estrategia' => $slug, 'condicoes' => is_array($cond) ? $cond : []];
    }

    /** Define a pergunta como sempre visivel (limpa condicoes). Operacao segura. */
    public static function definirSempreVisivel(int $perguntaId): array
    {
        if ($perguntaId <= 0) {
            return ['ok' => false, 'msg' => 'Pergunta invalida.'];
        }
        return self::gravar($perguntaId, 'sempre', []);
    }

    /**
     * Define uma regra de visibilidade.
     *
     * @param string $estrategiaSlug 'sempre' | 'visivel_se' | 'oculto_se'
     * @param array  $condicoes      lista de [pergunta_uuid, operador, valor, logica]
     */
    public static function definir(int $perguntaId, string $estrategiaSlug, array $condicoes): array
    {
        if ($perguntaId <= 0) {
            return ['ok' => false, 'msg' => 'Pergunta invalida.'];
        }
        if ($estrategiaSlug === 'sempre') {
            return self::gravar($perguntaId, 'sempre', []);
        }
        if (empty($condicoes)) {
            return ['ok' => false, 'msg' => 'Informe ao menos uma condicao.'];
        }
        return self::gravar($perguntaId, $estrategiaSlug, $condicoes);
    }

    /** Serializa uma lista de condicoes (specs) em condicoes nativas, ou null se falhar. Usado por outros facilitadores (ex.: destinos). */
    public static function serializarCondicoes(array $condicoes): ?array
    {
        return self::construirLista($condicoes);
    }

    /**
     * Operadores oferecidos na interface por tipo de origem.
     * - escolha (lista/multipla/unica/caixas): so igual/diferente (limite do motor GLPI 11).
     * - demais (texto/numero/data/etc.): igual, diferente, contem, nao contem, vazio, preenchido.
     * Os slugs sao gravados direto em value_operator (confirmado: equals, not_equals, contains...).
     */
    public static function operadoresPorTipo(string $tipoSlug): array
    {
        $escolha = ['lista', 'multipla', 'unica', 'caixas'];
        if (in_array($tipoSlug, $escolha, true)) {
            return [
                ['slug' => 'equals',     'label' => 'e igual a'],
                ['slug' => 'not_equals', 'label' => 'e diferente de'],
            ];
        }
        return [
            ['slug' => 'equals',       'label' => 'e igual a'],
            ['slug' => 'not_equals',   'label' => 'e diferente de'],
            ['slug' => 'contains',     'label' => 'contem'],
            ['slug' => 'not_contains', 'label' => 'nao contem'],
            ['slug' => 'empty',        'label' => 'esta vazio'],
            ['slug' => 'not_empty',    'label' => 'esta preenchido'],
        ];
    }

    /** Todos os operadores conhecidos (usado pela interface quando o tipo da origem ainda nao e conhecido). */
    public static function operadoresTodos(): array
    {
        return [
            ['slug' => 'equals',       'label' => 'e igual a'],
            ['slug' => 'not_equals',   'label' => 'e diferente de'],
            ['slug' => 'contains',     'label' => 'contem'],
            ['slug' => 'not_contains', 'label' => 'nao contem'],
            ['slug' => 'empty',        'label' => 'esta vazio'],
            ['slug' => 'not_empty',    'label' => 'esta preenchido'],
        ];
    }

    // -----------------------------------------------------------------
    // Condicionais por opcao (facilitador do modal de opcoes)
    // -----------------------------------------------------------------

    /**
     * Aplica as condicionais "por opcao" de uma pergunta de escolha (origem).
     * $regras: lista de:
     *   ['opcao'=>'2','acao'=>'mostrar'|'ocultar',
     *    'alvo_tipo'=>'pergunta'|'secao'|'destino','alvo_id'=>64,'alvo_uuid'=>'...']
     * A condicao mora no ALVO: cada opcao vira "origem == opcao" (OR), e a acao
     * define a estrategia (mostrar=>visivel_se, ocultar=>oculto_se). Condicoes de
     * OUTRAS origens no mesmo alvo sao preservadas.
     */
    public static function aplicarCondicionaisPorOpcao(int $formId, string $uuidOrigem, array $regras): array
    {
        if ($uuidOrigem === '') {
            return ['ok' => false, 'msg' => 'Pergunta de origem sem identificador.'];
        }

        // 1) Limpa condicoes antigas DESTA origem em todos os alvos (preserva outras).
        foreach (self::alvosDoFormulario($formId) as $alvo) {
            self::removerCondicoesDaOrigem($alvo['tipo'], (int) $alvo['id'], $uuidOrigem);
        }

        // 2) Agrupa as regras novas por alvo.
        $porAlvo = [];
        foreach ($regras as $r) {
            $opcao = (string) ($r['opcao'] ?? '');
            $tipo  = (string) ($r['alvo_tipo'] ?? '');
            $id    = (int) ($r['alvo_id'] ?? 0);
            if ($opcao === '' || $tipo === '' || $id <= 0) {
                continue;
            }
            $chave = $tipo . ':' . $id;
            if (!isset($porAlvo[$chave])) {
                $porAlvo[$chave] = [
                    'tipo'   => $tipo,
                    'id'     => $id,
                    'uuid'   => (string) ($r['alvo_uuid'] ?? ''),
                    'acao'   => (string) ($r['acao'] ?? 'mostrar'),
                    'opcoes' => [],
                ];
            }
            $porAlvo[$chave]['opcoes'][] = $opcao;
        }

        // 3) Aplica cada alvo.
        $erros = [];
        $aplicados = 0;
        foreach ($porAlvo as $alvo) {
            $estr  = $alvo['acao'] === 'ocultar' ? 'oculto_se' : 'visivel_se';
            $conds = [];
            foreach (array_values(array_unique($alvo['opcoes'])) as $op) {
                $conds[] = [
                    'pergunta_uuid' => $uuidOrigem,
                    'operador'      => 'equals',
                    'valor'         => (string) $op,
                    'logica'        => 'or',
                ];
            }
            $res = self::aplicarNoAlvo($formId, $alvo['tipo'], (int) $alvo['id'], $estr, $conds);
            if (!empty($res['ok'])) {
                $aplicados++;
            } else {
                $erros[] = $res['msg'] ?? 'Falha em um alvo.';
            }
        }

        if (!empty($erros)) {
            return ['ok' => $aplicados > 0, 'msg' => implode(' | ', $erros), 'aplicados' => $aplicados];
        }
        return ['ok' => true, 'msg' => 'Condicionais salvas.', 'aplicados' => $aplicados];
    }

    /**
     * Le as condicionais atuais de uma origem no formato "por opcao" (para o modal).
     * Parsing defensivo: nao depende do nome exato das chaves do JSON nativo.
     */
    public static function lerCondicionaisPorOpcao(int $formId, string $uuidOrigem): array
    {
        $regras = [];
        if ($uuidOrigem === '') {
            return $regras;
        }
        foreach (self::alvosDoFormulario($formId) as $alvo) {
            $reg = self::lerRegraDoAlvo($alvo['tipo'], (int) $alvo['id']);
            if ($reg['estrategia'] === 'sempre' || empty($reg['condicoes'])) {
                continue;
            }
            $acao = $reg['estrategia'] === 'oculto_se' ? 'ocultar' : 'mostrar';
            foreach ($reg['condicoes'] as $cond) {
                if (!is_array($cond)) {
                    continue;
                }
                $info = self::interpretarCondicao($cond, $uuidOrigem);
                if ($info === null) {
                    continue; // condicao de outra origem
                }
                $regras[] = [
                    'opcao'     => $info['valor'],
                    'acao'      => $acao,
                    'alvo_tipo' => $alvo['tipo'],
                    'alvo_id'   => (int) $alvo['id'],
                    'alvo_uuid' => (string) ($alvo['uuid'] ?? ''),
                ];
            }
        }
        return $regras;
    }

    /** Lista todos os alvos possiveis do formulario (secoes, perguntas, destinos). */
    private static function alvosDoFormulario(int $formId): array
    {
        global $DB;
        $alvos  = [];
        $secIds = [];

        foreach ($DB->request([
            'SELECT' => ['id', 'uuid'],
            'FROM'   => 'glpi_forms_sections',
            'WHERE'  => ['forms_forms_id' => $formId],
        ]) as $s) {
            $secIds[] = (int) $s['id'];
            $alvos[]  = ['tipo' => 'secao', 'id' => (int) $s['id'], 'uuid' => (string) ($s['uuid'] ?? '')];
        }

        if (!empty($secIds)) {
            foreach ($DB->request([
                'SELECT' => ['id', 'uuid'],
                'FROM'   => 'glpi_forms_questions',
                'WHERE'  => ['forms_sections_id' => $secIds],
            ]) as $q) {
                $alvos[] = ['tipo' => 'pergunta', 'id' => (int) $q['id'], 'uuid' => (string) ($q['uuid'] ?? '')];
            }
        }

        if (class_exists('PluginCatalogoeformulariosDestino')
            && method_exists('PluginCatalogoeformulariosDestino', 'listar')) {
            foreach (PluginCatalogoeformulariosDestino::listar($formId) as $d) {
                $alvos[] = ['tipo' => 'destino', 'id' => (int) ($d['id'] ?? 0), 'uuid' => ''];
            }
        }
        return $alvos;
    }

    /** Le a regra de visibilidade de um alvo (pergunta/secao/destino) normalizada. */
    private static function lerRegraDoAlvo(string $tipo, int $id): array
    {
        global $DB;

        if ($tipo === 'destino') {
            if (class_exists('PluginCatalogoeformulariosDestino')
                && method_exists('PluginCatalogoeformulariosDestino', 'lerCondicao')) {
                return PluginCatalogoeformulariosDestino::lerCondicao($id);
            }
            return ['estrategia' => 'sempre', 'estrategia_val' => '', 'condicoes' => []];
        }

        $tabela = $tipo === 'secao' ? 'glpi_forms_sections' : 'glpi_forms_questions';
        $row = $DB->request([
            'SELECT' => ['visibility_strategy', 'conditions'],
            'FROM'   => $tabela,
            'WHERE'  => ['id' => $id],
            'LIMIT'  => 1,
        ])->current();

        if ($row === null) {
            return ['estrategia' => 'sempre', 'estrategia_val' => '', 'condicoes' => []];
        }
        return self::normalizarRegra((string) ($row['visibility_strategy'] ?? ''), $row['conditions'] ?? []);
    }

    /** Normaliza visibility_strategy + conditions numa estrutura estavel. */
    private static function normalizarRegra(string $estr, $cond): array
    {
        if (is_string($cond)) {
            $cond = $cond !== '' ? (json_decode($cond, true) ?: []) : [];
        }
        if (!is_array($cond)) {
            $cond = [];
        }
        if (isset($cond['conditions']) && is_array($cond['conditions'])) {
            $cond = $cond['conditions']; // tolera wrapper {conditions:[...]}
        }

        $slug = 'sempre';
        if (stripos($estr, 'visible') !== false && stripos($estr, 'always') === false) {
            $slug = 'visivel_se';
        } elseif (stripos($estr, 'hidden') !== false) {
            $slug = 'oculto_se';
        }
        return ['estrategia' => $slug, 'estrategia_val' => $estr, 'condicoes' => $cond];
    }

    /**
     * Interpreta uma condicao armazenada. Retorna ['valor'=>...,'operador'=>...]
     * se referencia a origem informada, ou null. Parsing tolerante (nao depende
     * dos nomes exatos das chaves, que variam entre versoes 11.0.x).
     */
    private static function interpretarCondicao(array $cond, string $uuidOrigem): ?array
    {
        // Formato desta versao: item ("question-<uuid>"), item_uuid, item_type,
        // value_operator (string), value (chave da opcao), logic_operator.
        $uuidCond = '';
        if (isset($cond['item_uuid']) && is_scalar($cond['item_uuid'])) {
            $uuidCond = (string) $cond['item_uuid'];
        } elseif (isset($cond['item']) && is_scalar($cond['item'])) {
            $it  = (string) $cond['item'];
            $pos = strpos($it, '-');
            $uuidCond = $pos !== false ? substr($it, $pos + 1) : $it;
        }

        if ($uuidCond !== '') {
            if ($uuidCond !== $uuidOrigem) {
                return null; // condicao de outra origem
            }
            return [
                'valor'    => (isset($cond['value']) && is_scalar($cond['value'])) ? (string) $cond['value'] : '',
                'operador' => (isset($cond['value_operator']) && is_scalar($cond['value_operator'])) ? (string) $cond['value_operator'] : 'equals',
            ];
        }

        // Fallback tolerante (versoes com nomes de chave diferentes): so casa se
        // algum valor for exatamente o uuid da origem. Le a chave 'value' EXATA
        // (nunca 'value_operator'), evitando pegar o operador como se fosse a opcao.
        $refere = false;
        $flat   = [];
        array_walk_recursive($cond, static function ($v, $k) use (&$flat, &$refere, $uuidOrigem) {
            $flat[(string) $k][] = $v;
            if (is_scalar($v) && (string) $v === $uuidOrigem) {
                $refere = true;
            }
        });
        if (!$refere) {
            return null;
        }
        $valor = '';
        if (isset($flat['value'])) {
            foreach ($flat['value'] as $v) {
                if (is_scalar($v) && (string) $v !== $uuidOrigem && (string) $v !== '') {
                    $valor = (string) $v;
                    break;
                }
            }
        }
        return ['valor' => $valor, 'operador' => 'equals'];
    }

    /** Remove do alvo apenas as condicoes DESTA origem, preservando as demais. */
    private static function removerCondicoesDaOrigem(string $tipo, int $id, string $uuidOrigem): void
    {
        $reg = self::lerRegraDoAlvo($tipo, $id);
        if ($reg['estrategia'] === 'sempre' || empty($reg['condicoes'])) {
            return;
        }
        $mantidas = [];
        foreach ($reg['condicoes'] as $cond) {
            if (is_array($cond) && self::interpretarCondicao($cond, $uuidOrigem) !== null) {
                continue; // e desta origem: remove
            }
            $mantidas[] = $cond;
        }
        if (count($mantidas) === count($reg['condicoes'])) {
            return; // nada desta origem aqui
        }
        if ($tipo === 'destino') {
            if (class_exists('PluginCatalogoeformulariosDestino')
                && method_exists('PluginCatalogoeformulariosDestino', 'gravarCondicaoBruta')) {
                PluginCatalogoeformulariosDestino::gravarCondicaoBruta($id, empty($mantidas) ? 'sempre' : $reg['estrategia'], $mantidas);
            }
            return;
        }
        if (empty($mantidas)) {
            self::gravarColunaItem($tipo, $id, '', []);
            return;
        }
        self::gravarColunaItem($tipo, $id, (string) ($reg['estrategia_val'] ?? ''), $mantidas);
    }

    /** Aplica as condicoes novas no alvo, mesclando com as remanescentes (outras origens). */
    private static function aplicarNoAlvo(int $formId, string $tipo, int $id, string $estrSlug, array $condsNovas): array
    {
        if ($tipo === 'destino') {
            if (class_exists('PluginCatalogoeformulariosDestino')
                && method_exists('PluginCatalogoeformulariosDestino', 'aplicarCondicaoOpcao')) {
                return PluginCatalogoeformulariosDestino::aplicarCondicaoOpcao($formId, $id, $estrSlug, $condsNovas);
            }
            return ['ok' => false, 'msg' => 'Destinos condicionais indisponiveis (arquivo 3 pendente).'];
        }

        $atual = self::lerRegraDoAlvo($tipo, $id);
        if ($atual['estrategia'] !== 'sempre' && !empty($atual['condicoes'])
            && $atual['estrategia'] !== $estrSlug) {
            return ['ok' => false, 'msg' => 'Alvo ja tem condicao de outra origem com estrategia diferente; ajuste no editor nativo.'];
        }

        $novas = self::construirLista($condsNovas);
        if ($novas === null) {
            return ['ok' => false, 'msg' => 'Nao foi possivel montar a condicao nesta versao. Use o editor nativo.'];
        }
        $estrVal = self::estrategiaValor($estrSlug);
        if ($estrVal === null) {
            return ['ok' => false, 'msg' => 'Estrategia indisponivel nesta versao. Use o editor nativo.'];
        }

        $merge = array_merge(is_array($atual['condicoes']) ? $atual['condicoes'] : [], $novas);
        return self::gravarColunaItem($tipo, $id, $estrVal, $merge)
            ? ['ok' => true, 'msg' => 'ok']
            : ['ok' => false, 'msg' => 'Falha ao salvar no alvo.'];
    }

    /** Valor nativo (backed enum) da estrategia a partir do slug. */
    private static function estrategiaValor(string $slug): ?string
    {
        if (!enum_exists(self::VIS)) {
            return null;
        }
        $cands = $slug === 'visivel_se'
            ? ['VISIBLE_IF']
            : ($slug === 'oculto_se' ? ['HIDDEN_IF'] : ['ALWAYS_VISIBLE', 'ALWAYS']);
        $case = self::caso(self::VIS, $cands);
        return $case !== null ? self::valorEnum($case) : null;
    }

    // -----------------------------------------------------------------
    // Internos
    // -----------------------------------------------------------------

    private static function gravar(int $perguntaId, string $estrategiaSlug, array $condicoes): array
    {
        // Sem o motor de condicoes: so conseguimos garantir "sempre visivel".
        if (!enum_exists(self::VIS)) {
            if ($estrategiaSlug === 'sempre') {
                return self::gravarColunaItem('pergunta', $perguntaId, '', [])
                    ? ['ok' => true, 'msg' => 'Definido como sempre visivel.']
                    : ['ok' => false, 'msg' => 'Falha. Use o editor nativo.'];
            }
            return ['ok' => false, 'msg' => 'Motor de condicoes indisponivel nesta versao. Use o editor nativo.'];
        }

        $cands = $estrategiaSlug === 'visivel_se'
            ? ['VISIBLE_IF']
            : ($estrategiaSlug === 'oculto_se' ? ['HIDDEN_IF'] : ['ALWAYS_VISIBLE', 'ALWAYS']);

        $stratCase = self::caso(self::VIS, $cands);
        if ($stratCase === null) {
            return ['ok' => false, 'msg' => 'Estrategia indisponivel nesta versao. Use o editor nativo.'];
        }
        $stratVal = self::valorEnum($stratCase);

        if ($estrategiaSlug === 'sempre') {
            return self::gravarColunaItem('pergunta', $perguntaId, $stratVal, [])
                ? ['ok' => true, 'msg' => 'Definido como sempre visivel.']
                : ['ok' => false, 'msg' => 'Falha ao salvar.'];
        }

        try {
            $serial = self::construirLista($condicoes);
            if ($serial === null) {
                return ['ok' => false, 'msg' => 'Nao foi possivel montar a condicao nesta versao. Use o editor nativo.'];
            }
            if (empty($serial)) {
                return ['ok' => false, 'msg' => 'Condicoes invalidas.'];
            }
            return self::gravarColunaItem('pergunta', $perguntaId, $stratVal, $serial)
                ? ['ok' => true, 'msg' => 'Regra de visibilidade salva.']
                : ['ok' => false, 'msg' => 'Falha ao salvar a regra. Use o editor nativo.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => 'Regra nao aplicada (incompatibilidade de versao). Use o editor nativo. Detalhe: ' . $e->getMessage()];
        }
    }

    /** Constroi a lista de condicoes nativas (serializadas) via reflexao, ou null se falhar. */
    private static function construirLista(array $condicoes): ?array
    {
        $serial = [];
        foreach ($condicoes as $c) {
            $uuid = (string) ($c['pergunta_uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $obj = self::construirCondicao(
                $uuid,
                (string) ($c['operador'] ?? 'equals'),
                $c['valor'] ?? '',
                (string) ($c['logica'] ?? 'or')
            );
            if ($obj === null) {
                return null;
            }
            $serial[] = $obj; // ja e o array no formato nativo desta versao
        }
        return $serial;
    }

    /** Grava visibility_strategy + conditions no alvo (pergunta ou secao). */
    private static function gravarColunaItem(string $tipoAlvo, int $id, string $estrategiaVal, array $condicoes): bool
    {
        $cls = $tipoAlvo === 'secao' ? self::SEC : self::Q;
        if (!class_exists($cls)) {
            return false;
        }
        foreach ([$condicoes, json_encode($condicoes)] as $payload) {
            try {
                $obj = new $cls();
                if ($obj->update([
                    'id'                  => $id,
                    'visibility_strategy' => $estrategiaVal,
                    'conditions'          => $payload,
                ])) {
                    return true;
                }
            } catch (\Throwable $e) {
                // tenta proximo formato
            }
        }
        return false;
    }

    /**
     * Monta uma condicao no formato nativo desta versao (confirmado no banco):
     *   item, item_uuid, item_type, value_operator (string), value, logic_operator.
     * Retorna um array serializavel (nao objeto), que gravarColunaItem grava direto.
     */
    private static function construirCondicao(string $uuid, string $operadorSlug, $valor, string $logicaSlug): ?array
    {
        if ($uuid === '') {
            return null;
        }

        // Operador: para tipos de escolha a versao so aceita equals / not_equals.
        $op = strtolower(trim($operadorSlug));
        if ($op === '' || $op === 'equal') {
            $op = 'equals';
        }
        if ($op === 'different' || $op === 'not_equal' || $op === 'notequals') {
            $op = 'not_equals';
        }
        // Nomes usados pelas versoes antigas do plugin; o GLPI grava "empty" / "not_empty"
        if ($op === 'is_empty') {
            $op = 'empty';
        }
        if ($op === 'is_not_empty') {
            $op = 'not_empty';
        }
        $semValor = in_array($op, ['empty', 'not_empty', 'visible', 'not_visible'], true);

        // Logica: string 'and' | 'or' (nunca null, senao o motor descarta).
        $logica = strtolower(trim($logicaSlug));
        if ($logica !== 'and' && $logica !== 'or') {
            $logica = 'or';
        }

        return [
            'item'           => 'question-' . $uuid,
            'item_uuid'      => $uuid,
            'item_type'      => 'question',
            'value_operator' => $op,
            'value'          => $semValor ? null : (is_scalar($valor) ? (string) $valor : $valor),
            'logic_operator' => $logica,
        ];
    }

    /** Resolve um case de enum por nome exato ou aproximado. */
    private static function caso(string $enum, array $cands)
    {
        if (!enum_exists($enum)) {
            return null;
        }
        foreach ($cands as $c) {
            if (defined("$enum::$c")) {
                return constant("$enum::$c");
            }
        }
        foreach ($enum::cases() as $case) {
            $n = strtoupper($case->name);
            foreach ($cands as $c) {
                if (strpos($n, $c) !== false) {
                    return $case;
                }
            }
        }
        return null;
    }

    /** Valor string de um enum (backed -> value; senao nome minusculo). */
    private static function valorEnum($case): string
    {
        if ($case instanceof \BackedEnum) {
            return (string) $case->value;
        }
        return strtolower($case->name);
    }
}