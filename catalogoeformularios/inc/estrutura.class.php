<?php

use Glpi\Form\Form;
use Glpi\Form\Section;
use Glpi\Form\Question;

/**
 * Estrutura completa de um formulario para o editor do acordeao:
 * secoes, perguntas (todos os tipos nativos, com a configuracao de cada um),
 * blocos de texto, valor padrao, validacao, visibilidade condicional
 * (pergunta, secao, bloco de texto e botao Enviar) e condicao de criacao
 * dos destinos.
 *
 * Tudo e lido e gravado nas tabelas nativas glpi_forms_* pelas classes
 * nativas do GLPI 11/12. Os operadores das condicoes vem do proprio tipo de
 * pergunta (getSupportedValueOperators), entao so aparecem combinacoes que o
 * motor do GLPI realmente avalia.
 */
class PluginCatalogoeformulariosEstrutura extends CommonGLPI
{
    // $rightname nao e redeclarada: e tipada (string) no GLPI 12 e sem tipo no GLPI 11.
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

    private const NS      = 'Glpi\\Form\\QuestionType\\';
    private const COMMENT = '\\Glpi\\Form\\Comment';
    private const FD      = '\\Glpi\\Form\\Destination\\FormDestination';
    private const VALOP   = '\\Glpi\\Form\\Condition\\ValueOperator';

    /** Operadores que nao comparam com um valor. */
    private const SEM_VALOR = ['empty', 'not_empty', 'visible', 'not_visible'];

    /** Para tipos cujo valor de comparacao nao tem campo simples, so estes operadores. */
    private const SO_PRESENCA = ['empty', 'not_empty', 'visible', 'not_visible'];

    // =================================================================
    // Tipos de pergunta
    // =================================================================

    /**
     * Todos os tipos nativos (slug interno => definicao).
     * - opcoes: tipo de escolha (lista de opcoes com chave)
     * - dt: [data, hora] habilitados (DateTime)
     * - multi_campo: chave booleana de "multiplos" no extra_data
     * - itemtype: 'item' | 'itemlista' (precisa escolher o tipo de item)
     * - padrao: como o valor padrao e informado na tela
     * - valor: como o valor de uma condicao e informado na tela
     */
    public static function mapa(): array
    {
        $ns = self::NS;
        return [
            'texto'          => ['fqcn' => $ns . 'QuestionTypeShortText',   'label' => 'Texto curto',              'grupo' => 'Resposta curta',  'padrao' => 'texto',     'valor' => 'texto'],
            'email'          => ['fqcn' => $ns . 'QuestionTypeEmail',       'label' => 'E-mail',                   'grupo' => 'Resposta curta',  'padrao' => 'texto',     'valor' => 'texto'],
            'numero'         => ['fqcn' => $ns . 'QuestionTypeNumber',      'label' => 'Numero',                   'grupo' => 'Resposta curta',  'padrao' => 'numero',    'valor' => 'numero'],
            'textolongo'     => ['fqcn' => $ns . 'QuestionTypeLongText',    'label' => 'Texto longo (rico)',       'grupo' => 'Resposta longa',  'padrao' => 'texto',     'valor' => 'texto'],
            'data'           => ['fqcn' => $ns . 'QuestionTypeDateTime',    'label' => 'Data',                     'grupo' => 'Data e hora',     'padrao' => 'data',      'valor' => 'data',     'dt' => [1, 0]],
            'hora'           => ['fqcn' => $ns . 'QuestionTypeDateTime',    'label' => 'Hora',                     'grupo' => 'Data e hora',     'padrao' => 'hora',      'valor' => 'hora',     'dt' => [0, 1]],
            'datahora'       => ['fqcn' => $ns . 'QuestionTypeDateTime',    'label' => 'Data e hora',              'grupo' => 'Data e hora',     'padrao' => 'datahora',  'valor' => 'datahora', 'dt' => [1, 1]],
            'lista'          => ['fqcn' => $ns . 'QuestionTypeDropdown',    'label' => 'Lista suspensa',           'grupo' => 'Escolha',         'padrao' => 'opcoes',    'valor' => 'opcao',    'opcoes' => true],
            'multipla'       => ['fqcn' => $ns . 'QuestionTypeDropdown',    'label' => 'Lista (varias escolhas)',  'grupo' => 'Escolha',         'padrao' => 'opcoes',    'valor' => 'opcao',    'opcoes' => true, 'multi_lista' => true],
            'unica'          => ['fqcn' => $ns . 'QuestionTypeRadio',       'label' => 'Botoes de opcao',          'grupo' => 'Escolha',         'padrao' => 'opcoes',    'valor' => 'opcao',    'opcoes' => true],
            'caixas'         => ['fqcn' => $ns . 'QuestionTypeCheckbox',    'label' => 'Caixas de selecao',        'grupo' => 'Escolha',         'padrao' => 'opcoes',    'valor' => 'opcao',    'opcoes' => true],
            'urgencia'       => ['fqcn' => $ns . 'QuestionTypeUrgency',     'label' => 'Urgencia',                 'grupo' => 'Chamado',         'padrao' => 'urgencia',  'valor' => 'urgencia'],
            'tiporequisicao' => ['fqcn' => $ns . 'QuestionTypeRequestType', 'label' => 'Tipo (incidente/requisicao)', 'grupo' => 'Chamado',     'padrao' => 'tipo',      'valor' => 'tipo'],
            'requerente'     => ['fqcn' => $ns . 'QuestionTypeRequester',   'label' => 'Requerente',               'grupo' => 'Atores',          'padrao' => 'atores',    'valor' => 'nenhum',   'multi_campo' => 'is_multiple_actors'],
            'observador'     => ['fqcn' => $ns . 'QuestionTypeObserver',    'label' => 'Observador',               'grupo' => 'Atores',          'padrao' => 'atores',    'valor' => 'nenhum',   'multi_campo' => 'is_multiple_actors'],
            'atribuido'      => ['fqcn' => $ns . 'QuestionTypeAssignee',    'label' => 'Atribuido a',              'grupo' => 'Atores',          'padrao' => 'atores',    'valor' => 'nenhum',   'multi_campo' => 'is_multiple_actors'],
            'itemlista'      => ['fqcn' => $ns . 'QuestionTypeItemDropdown', 'label' => 'Lista do GLPI (categoria, local...)', 'grupo' => 'Itens', 'padrao' => 'nenhum', 'valor' => 'nenhum',   'itemtype' => 'itemlista', 'multi_campo' => 'is_multiple_items'],
            'item'           => ['fqcn' => $ns . 'QuestionTypeItem',        'label' => 'Item do GLPI (ativo, chamado...)',     'grupo' => 'Itens', 'padrao' => 'nenhum', 'valor' => 'nenhum',   'itemtype' => 'item',      'multi_campo' => 'is_multiple_items'],
            'dispositivo'    => ['fqcn' => $ns . 'QuestionTypeUserDevice',  'label' => 'Dispositivo do usuario',   'grupo' => 'Itens',           'padrao' => 'nenhum',    'valor' => 'nenhum',   'multi_campo' => 'is_multiple_devices'],
            'arquivo'        => ['fqcn' => $ns . 'QuestionTypeFile',        'label' => 'Arquivo',                  'grupo' => 'Arquivo',         'padrao' => 'nenhum',    'valor' => 'nenhum'],
        ];
    }

    /** Tipos disponiveis nesta instalacao, para o seletor da tela. */
    public static function tipos(): array
    {
        $saida = [];
        foreach (self::mapa() as $slug => $def) {
            if (!class_exists($def['fqcn'])) {
                continue;
            }
            $saida[] = [
                'slug'     => $slug,
                'label'    => $def['label'],
                'grupo'    => $def['grupo'],
                'opcoes'   => !empty($def['opcoes']),
                'dt'       => isset($def['dt']),
                'multiplo' => !empty($def['multi_campo']),
                'itemtype' => $def['itemtype'] ?? '',
                'padrao'   => $def['padrao'],
                'valor'    => $def['valor'],
            ];
        }
        return $saida;
    }

    /** Tipos de item que podem ser escolhidos em "Item do GLPI" e "Lista do GLPI". */
    public static function tiposDeItem(): array
    {
        $saida = ['item' => [], 'itemlista' => []];
        foreach (['item' => 'QuestionTypeItem', 'itemlista' => 'QuestionTypeItemDropdown'] as $slug => $cls) {
            $fqcn = self::NS . $cls;
            if (!class_exists($fqcn)) {
                continue;
            }
            try {
                $grupos = (new $fqcn())->getAllowedItemtypes();
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($grupos as $grupo => $lista) {
                if (!is_array($lista)) {
                    $lista = [$lista];
                }
                array_walk_recursive($lista, function ($classe, $chave) use (&$saida, $slug, $grupo) {
                    $classe = is_string($classe) && class_exists($classe) ? $classe : (is_string($chave) && class_exists($chave) ? $chave : '');
                    if ($classe === '') {
                        return;
                    }
                    $saida[$slug][$classe] = [
                        'v' => $classe,
                        't' => (string) $classe::getTypeName(1),
                        'g' => is_string($grupo) ? $grupo : '',
                    ];
                });
            }
            $saida[$slug] = array_values($saida[$slug]);
        }
        return $saida;
    }

    /** Slug interno a partir da classe gravada e do extra_data. */
    public static function detectar(string $fqcn, array $extra): string
    {
        $fqcn = ltrim($fqcn, '\\');
        if ($fqcn === self::NS . 'QuestionTypeDateTime') {
            $d = (int) ($extra['is_date_enabled'] ?? 1);
            $t = (int) ($extra['is_time_enabled'] ?? 0);
            return ($d && $t) ? 'datahora' : (($t && !$d) ? 'hora' : 'data');
        }
        if ($fqcn === self::NS . 'QuestionTypeDropdown') {
            return !empty($extra['is_multiple_dropdown']) ? 'multipla' : 'lista';
        }
        foreach (self::mapa() as $slug => $def) {
            if ($def['fqcn'] === $fqcn) {
                return $slug;
            }
        }
        return '';
    }

    // =================================================================
    // Leitura da estrutura completa
    // =================================================================

    public static function temComentarios(): bool
    {
        global $DB;
        return class_exists(self::COMMENT) && $DB->tableExists('glpi_forms_comments');
    }

    /** Estrutura completa do formulario para o editor do acordeao. */
    public static function ler(int $formId): ?array
    {
        global $DB;

        $f = $DB->request(['FROM' => 'glpi_forms_forms', 'WHERE' => ['id' => $formId, 'is_deleted' => 0], 'LIMIT' => 1])->current();
        if ($f === null) {
            return null;
        }

        $secoes  = [];
        $origens = [];
        foreach ($DB->request(['FROM' => 'glpi_forms_sections', 'WHERE' => ['forms_forms_id' => $formId], 'ORDER' => 'rank ASC']) as $s) {
            $blocos = [];
            foreach ($DB->request(['FROM' => 'glpi_forms_questions', 'WHERE' => ['forms_sections_id' => (int) $s['id']]]) as $q) {
                $p = self::perguntaParaTela($q);
                $p['secao_id'] = (int) $s['id'];
                $blocos[] = $p;
                $origens[] = [
                    'uuid'       => $p['uuid'],
                    'id'         => $p['id'],
                    'nome'       => $p['nome'],
                    'tipo'       => $p['tipo'],
                    'secao'      => (string) $s['name'],
                    'opcoes'     => $p['opcoes'],
                    'valor'      => $p['valor_tipo'],
                    'operadores' => self::operadoresDaPergunta($q, $p['tipo']),
                ];
            }
            if (self::temComentarios()) {
                foreach ($DB->request(['FROM' => 'glpi_forms_comments', 'WHERE' => ['forms_sections_id' => (int) $s['id']]]) as $c) {
                    $blocos[] = [
                        'bloco'        => 'comentario',
                        'id'           => (int) $c['id'],
                        'uuid'         => (string) $c['uuid'],
                        'nome'         => (string) $c['name'],
                        'descricao'    => (string) ($c['description'] ?? ''),
                        'ordem'        => (int) $c['vertical_rank'],
                        'secao_id'     => (int) $s['id'],
                        'visibilidade' => self::lerRegra((string) ($c['visibility_strategy'] ?? ''), $c['conditions'] ?? ''),
                    ];
                }
            }
            usort($blocos, fn($a, $b) => [$a['ordem'], $a['bloco'], $a['id']] <=> [$b['ordem'], $b['bloco'], $b['id']]);
            $secoes[] = [
                'id'           => (int) $s['id'],
                'uuid'         => (string) $s['uuid'],
                'nome'         => (string) $s['name'],
                'descricao'    => (string) ($s['description'] ?? ''),
                'visibilidade' => self::lerRegra((string) ($s['visibility_strategy'] ?? ''), $s['conditions'] ?? ''),
                'blocos'       => $blocos,
            ];
        }

        $rotulos = [];
        foreach (PluginCatalogoeformulariosDestino::listar($formId) as $l) {
            $rotulos[(int) ($l['id'] ?? 0)] = (string) ($l['tipo_label'] ?? '');
        }
        $destinos = [];
        foreach ($DB->request(['FROM' => 'glpi_forms_destinations_formdestinations', 'WHERE' => ['forms_forms_id' => $formId], 'ORDER' => 'id ASC']) as $d) {
            $destinos[] = [
                'id'      => (int) $d['id'],
                'nome'    => (string) $d['name'],
                'tipo'    => (string) $d['itemtype'],
                'tipo_label' => $rotulos[(int) $d['id']] ?? '',
                'criacao' => self::lerRegra((string) ($d['creation_strategy'] ?? ''), $d['conditions'] ?? '', true),
            ];
        }

        return [
            'form'      => self::geral($f),
            'secoes'    => $secoes,
            'enviar'    => array_key_exists('submit_button_visibility_strategy', $f)
                ? self::lerRegra((string) ($f['submit_button_visibility_strategy'] ?? ''), $f['submit_button_conditions'] ?? '')
                : null,
            'destinos'  => $destinos,
            'origens'   => $origens,
            'comentarios' => self::temComentarios(),
        ];
    }

    /** Campos gerais do formulario (aba Geral). */
    private static function geral(array $f): array
    {
        return [
            'id'          => (int) $f['id'],
            'nome'        => (string) $f['name'],
            'ilustracao'  => (string) ($f['illustration'] ?? ''),
            'tem_ilustracao' => array_key_exists('illustration', $f),
            'categoria'   => (int) ($f['forms_categories_id'] ?? 0),
            'descricao'   => (string) ($f['description'] ?? ''),
            'header'      => (string) ($f['header'] ?? ''),
            'ativo'       => (int) $f['is_active'] === 1,
            'rascunho'    => (int) ($f['is_draft'] ?? 0) === 1,
            'recursivo'   => (int) ($f['is_recursive'] ?? 0) === 1,
            'fixado'      => (int) ($f['is_pinned'] ?? 0) === 1,
            'tem_fixado'  => array_key_exists('is_pinned', $f),
            'layout'      => (string) ($f['render_layout'] ?? 'step_by_step'),
            'tem_layout'  => array_key_exists('render_layout', $f),
            'entidade'    => (int) ($f['entities_id'] ?? 0),
            'entidade_nome' => Dropdown::getDropdownName('glpi_entities', (int) ($f['entities_id'] ?? 0)),
        ];
    }

    /** Uma pergunta no formato do editor. */
    private static function perguntaParaTela(array $q): array
    {
        $extra = self::json($q['extra_data'] ?? '');
        $tipo  = self::detectar((string) $q['type'], $extra);
        $def   = self::mapa()[$tipo] ?? null;

        $opcoes = [];
        if ($def && !empty($def['opcoes']) && isset($extra['options']) && is_array($extra['options'])) {
            foreach ($extra['options'] as $k => $t) {
                $opcoes[] = ['k' => (string) $k, 't' => (string) $t];
            }
        }

        $config = [
            'itemtype'   => (string) ($extra['itemtype'] ?? ''),
            'multiplo'   => $def && !empty($def['multi_campo']) ? !empty($extra[$def['multi_campo']]) : false,
            'data_atual' => !empty($extra['is_default_value_current_time']),
        ];

        return [
            'bloco'       => 'pergunta',
            'id'          => (int) $q['id'],
            'uuid'        => (string) $q['uuid'],
            'nome'        => (string) $q['name'],
            'tipo'        => $tipo,
            'tipo_desconhecido' => $tipo === '' ? (string) $q['type'] : '',
            'tipo_label'  => $def['label'] ?? 'Tipo de outro plugin',
            'valor_tipo'  => $def['valor'] ?? 'nenhum',
            'obrigatoria' => (int) ($q['is_mandatory'] ?? 0) === 1,
            'descricao'   => (string) ($q['description'] ?? ''),
            'opcoes'      => $opcoes,
            'config'      => $config,
            'padrao'      => self::padraoParaTela($def['padrao'] ?? 'nenhum', (string) ($q['default_value'] ?? '')),
            'ordem'       => (int) ($q['vertical_rank'] ?? 0),
            'visibilidade' => self::lerRegra((string) ($q['visibility_strategy'] ?? ''), $q['conditions'] ?? ''),
            'validacao'   => array_key_exists('validation_strategy', $q)
                ? self::lerValidacao((string) ($q['validation_strategy'] ?? ''), $q['validation_conditions'] ?? '')
                : null,
        ];
    }

    /** Valor padrao gravado -> formato da tela. */
    private static function padraoParaTela(string $tipo, string $gravado)
    {
        if ($gravado === '') {
            return $tipo === 'opcoes' || $tipo === 'atores' ? [] : '';
        }
        if ($tipo === 'opcoes') {
            return array_values(array_filter(explode(',', $gravado), fn($v) => $v !== ''));
        }
        if ($tipo === 'atores') {
            $a = self::json($gravado);
            return [
                'usuarios' => array_values(array_map('intval', (array) ($a['users_ids'] ?? []))),
                'grupos'   => array_values(array_map('intval', (array) ($a['groups_ids'] ?? []))),
            ];
        }
        return $gravado;
    }

    /**
     * Operadores aceitos pelo tipo da pergunta (vindos do proprio GLPI).
     * Para tipos sem campo simples de valor (atores, itens, arquivo) ficam so
     * os operadores de presenca, que nao precisam de valor.
     */
    private static function operadoresDaPergunta(array $q, string $tipo): array
    {
        $slugs = [];
        try {
            $obj = new Question();
            if ($obj->getFromDB((int) $q['id']) && method_exists($obj, 'getQuestionType')) {
                $tipoObj = $obj->getQuestionType();
                if ($tipoObj && method_exists($tipoObj, 'getSupportedValueOperators')) {
                    $cfg = method_exists($obj, 'getExtraDataConfig') ? $obj->getExtraDataConfig() : null;
                    foreach ($tipoObj->getSupportedValueOperators($cfg) as $op) {
                        $slugs[$op->value] = method_exists($op, 'getLabel') ? $op->getLabel() : $op->value;
                    }
                }
            }
        } catch (\Throwable $e) {
            $slugs = [];
        }

        // GLPI sem o metodo (11.0.x antigo): conjunto seguro
        if (!$slugs) {
            $slugs = ['equals' => 'E igual a', 'not_equals' => 'E diferente de', 'empty' => 'Esta vazio', 'not_empty' => 'Esta preenchido'];
        }

        $valor = self::mapa()[$tipo]['valor'] ?? 'nenhum';
        $saida = [];
        foreach ($slugs as $slug => $label) {
            if ($valor === 'nenhum' && !in_array($slug, self::SO_PRESENCA, true)) {
                continue;
            }
            // Comparacao por tipo de item exige um seletor proprio: fica no editor nativo
            if (in_array($slug, ['is_itemtype', 'is_not_itemtype', 'at_least_one_item_of_itemtype', 'all_items_of_itemtype'], true)) {
                continue;
            }
            $saida[] = ['slug' => (string) $slug, 'label' => (string) $label, 'sem_valor' => in_array($slug, self::SEM_VALOR, true)];
        }
        return $saida;
    }

    // =================================================================
    // Regras (visibilidade, criacao, validacao)
    // =================================================================

    /** visibility_strategy / creation_strategy + conditions -> formato da tela. */
    private static function lerRegra(string $estrategia, $condicoes, bool $criacao = false): array
    {
        $slug = 'sempre';
        if (in_array($estrategia, ['visible_if', 'created_if'], true)) {
            $slug = 'se';
        } elseif (in_array($estrategia, ['hidden_if', 'created_unless'], true)) {
            $slug = 'exceto';
        }
        $lista = [];
        foreach (self::json($condicoes) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $uuid = (string) ($c['item_uuid'] ?? '');
            if ($uuid === '' && isset($c['item']) && is_string($c['item']) && str_contains($c['item'], '-')) {
                $uuid = substr($c['item'], strpos($c['item'], '-') + 1);
            }
            $lista[] = [
                'origem'   => $uuid,
                'origem_tipo' => (string) ($c['item_type'] ?? 'question'),
                'operador' => (string) ($c['value_operator'] ?? 'equals'),
                'valor'    => $c['value'] ?? '',
                'logica'   => (string) ($c['logic_operator'] ?? 'and'),
            ];
        }
        return ['estrategia' => $lista ? $slug : 'sempre', 'condicoes' => $lista, 'criacao' => $criacao];
    }

    private static function lerValidacao(string $estrategia, $condicoes): array
    {
        $slug = $estrategia === 'valid_if' ? 'valido_se' : ($estrategia === 'invalid_if' ? 'invalido_se' : 'sem');
        $lista = [];
        foreach (self::json($condicoes) as $c) {
            if (is_array($c)) {
                $lista[] = [
                    'operador' => (string) ($c['value_operator'] ?? ''),
                    'valor'    => $c['value'] ?? '',
                    'logica'   => (string) ($c['logic_operator'] ?? 'and'),
                ];
            }
        }
        return ['estrategia' => $lista ? $slug : 'sem', 'condicoes' => $lista];
    }

    /**
     * Grava a regra de visibilidade (ou de criacao, no destino).
     * $alvo: pergunta | secao | comentario | enviar | destino
     * $condicoes: lista de [origem (uuid da pergunta), operador, valor, logica]
     */
    public static function salvarRegra(string $alvo, int $id, string $estrategia, array $condicoes): array
    {
        global $DB;

        $mapa = [
            'pergunta'   => ['glpi_forms_questions', 'visibility_strategy', 'conditions', ['always_visible', 'visible_if', 'hidden_if']],
            'secao'      => ['glpi_forms_sections', 'visibility_strategy', 'conditions', ['always_visible', 'visible_if', 'hidden_if']],
            'comentario' => ['glpi_forms_comments', 'visibility_strategy', 'conditions', ['always_visible', 'visible_if', 'hidden_if']],
            'enviar'     => ['glpi_forms_forms', 'submit_button_visibility_strategy', 'submit_button_conditions', ['always_visible', 'visible_if', 'hidden_if']],
            'destino'    => ['glpi_forms_destinations_formdestinations', 'creation_strategy', 'conditions', ['always_created', 'created_if', 'created_unless']],
        ];
        if (!isset($mapa[$alvo]) || $id <= 0) {
            return ['ok' => false, 'msg' => 'Item invalido.'];
        }
        [$tabela, $colEstr, $colCond, $valores] = $mapa[$alvo];
        if (!$DB->tableExists($tabela) || !$DB->fieldExists($tabela, $colEstr)) {
            return ['ok' => false, 'msg' => 'Esta versao do GLPI nao tem condicoes para este item.'];
        }
        $formId = self::formDoAlvo($alvo, $id);
        if ($formId <= 0) {
            return ['ok' => false, 'msg' => 'Item nao encontrado.'];
        }

        $lista = [];
        if ($estrategia !== 'sempre') {
            $origens = self::origensValidas($formId);
            foreach ($condicoes as $i => $c) {
                $uuid = (string) ($c['origem'] ?? '');
                if (!isset($origens[$uuid])) {
                    continue;
                }
                // Uma pergunta nao pode depender dela mesma
                if ($alvo === 'pergunta' && (int) $origens[$uuid] === $id) {
                    continue;
                }
                $op = self::operador((string) ($c['operador'] ?? 'equals'));
                $lista[] = [
                    'item'           => 'question-' . $uuid,
                    'item_uuid'      => $uuid,
                    'item_type'      => 'question',
                    'value_operator' => $op,
                    'value'          => in_array($op, self::SEM_VALOR, true) ? null : self::valorCondicao($c['valor'] ?? ''),
                    'logic_operator' => strtolower((string) ($c['logica'] ?? 'and')) === 'or' ? 'or' : 'and',
                ];
            }
            if (!$lista) {
                return ['ok' => false, 'msg' => 'Informe ao menos uma condicao completa (pergunta e operador).'];
            }
        }

        $valorEstr = $estrategia === 'se' ? $valores[1] : ($estrategia === 'exceto' ? $valores[2] : $valores[0]);
        if (!$lista) {
            $valorEstr = $valores[0];
        }

        $classes = ['pergunta' => Question::class, 'secao' => Section::class, 'comentario' => self::COMMENT, 'enviar' => Form::class, 'destino' => self::FD];
        $ok = self::gravarColunas($classes[$alvo], $tabela, $id, [$colEstr => $valorEstr, $colCond => json_encode($lista)]);
        return $ok
            ? ['ok' => true, 'msg' => $lista ? 'Condicao salva.' : ($alvo === 'destino' ? 'Destino criado sempre.' : 'Sempre visivel.')]
            : ['ok' => false, 'msg' => 'Falha ao salvar a condicao.'];
    }

    /** Validacao da resposta (sobre a propria pergunta). */
    public static function salvarValidacao(int $perguntaId, string $estrategia, array $condicoes): array
    {
        global $DB;

        if (!$DB->fieldExists('glpi_forms_questions', 'validation_strategy')) {
            return ['ok' => false, 'msg' => 'Esta versao do GLPI nao tem validacao de respostas.'];
        }
        $q = $DB->request(['SELECT' => ['uuid'], 'FROM' => 'glpi_forms_questions', 'WHERE' => ['id' => $perguntaId], 'LIMIT' => 1])->current();
        if (!$q) {
            return ['ok' => false, 'msg' => 'Pergunta nao encontrada.'];
        }
        $lista = [];
        if ($estrategia !== 'sem') {
            foreach ($condicoes as $c) {
                $op = self::operador((string) ($c['operador'] ?? ''));
                if ($op === '' || in_array($op, ['visible', 'not_visible'], true)) {
                    continue;
                }
                $valor = self::valorCondicao($c['valor'] ?? '');
                if (in_array($op, ['match_regex', 'not_match_regex'], true) && @preg_match((string) $valor, '') === false) {
                    return ['ok' => false, 'msg' => 'Expressao regular invalida: ' . $valor . ' (use o formato /padrao/).'];
                }
                $lista[] = [
                    'item'           => 'question-' . $q['uuid'],
                    'item_uuid'      => (string) $q['uuid'],
                    'item_type'      => 'question',
                    'value_operator' => $op,
                    'value'          => in_array($op, self::SEM_VALOR, true) ? null : $valor,
                    'logic_operator' => strtolower((string) ($c['logica'] ?? 'and')) === 'or' ? 'or' : 'and',
                ];
            }
        }
        $valorEstr = !$lista ? 'no_validation' : ($estrategia === 'invalido_se' ? 'invalid_if' : 'valid_if');
        return self::gravarColunas(Question::class, 'glpi_forms_questions', $perguntaId, ['validation_strategy' => $valorEstr, 'validation_conditions' => json_encode($lista)])
            ?['ok' => true, 'msg' => $lista ? 'Validacao salva.' : 'Sem validacao.']
            : ['ok' => false, 'msg' => 'Falha ao salvar a validacao.'];
    }

    /** Normaliza o operador (aceita os nomes antigos do plugin). */
    private static function operador(string $op): string
    {
        $op = strtolower(trim($op));
        $antigos = ['is_empty' => 'empty', 'is_not_empty' => 'not_empty', 'equal' => 'equals', 'not_equal' => 'not_equals', 'different' => 'not_equals'];
        $op = $antigos[$op] ?? $op;
        $enum = self::VALOP;
        if (enum_exists($enum) && $enum::tryFrom($op) === null) {
            return '';
        }
        return $op;
    }

    private static function valorCondicao($v)
    {
        if (is_array($v)) {
            return array_values(array_map('strval', $v));
        }
        return is_scalar($v) ? (string) $v : '';
    }

    /** uuid => id das perguntas do formulario (origens validas de condicao). */
    private static function origensValidas(int $formId): array
    {
        global $DB;
        $saida = [];
        $secs  = array_column(iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_forms_sections', 'WHERE' => ['forms_forms_id' => $formId]]), false), 'id');
        if ($secs) {
            foreach ($DB->request(['SELECT' => ['id', 'uuid'], 'FROM' => 'glpi_forms_questions', 'WHERE' => ['forms_sections_id' => $secs]]) as $q) {
                $saida[(string) $q['uuid']] = (int) $q['id'];
            }
        }
        return $saida;
    }

    /** Formulario a que um alvo pertence (0 se nao existir). */
    public static function formDoAlvo(string $alvo, int $id): int
    {
        global $DB;
        switch ($alvo) {
            case 'enviar':
                return countElementsInTable('glpi_forms_forms', ['id' => $id]) ? $id : 0;
            case 'destino':
                $r = $DB->request(['SELECT' => ['forms_forms_id'], 'FROM' => 'glpi_forms_destinations_formdestinations', 'WHERE' => ['id' => $id]])->current();
                return (int) ($r['forms_forms_id'] ?? 0);
            case 'secao':
                $r = $DB->request(['SELECT' => ['forms_forms_id'], 'FROM' => 'glpi_forms_sections', 'WHERE' => ['id' => $id]])->current();
                return (int) ($r['forms_forms_id'] ?? 0);
            case 'pergunta':
            case 'comentario':
                $tabela = $alvo === 'pergunta' ? 'glpi_forms_questions' : 'glpi_forms_comments';
                if (!$DB->tableExists($tabela)) {
                    return 0;
                }
                $r = $DB->request([
                    'SELECT' => ['glpi_forms_sections.forms_forms_id'],
                    'FROM'   => $tabela,
                    'INNER JOIN' => ['glpi_forms_sections' => ['FKEY' => [$tabela => 'forms_sections_id', 'glpi_forms_sections' => 'id']]],
                    'WHERE'  => [$tabela . '.id' => $id],
                ])->current();
                return (int) ($r['forms_forms_id'] ?? 0);
        }
        return 0;
    }

    // =================================================================
    // Perguntas
    // =================================================================

    /**
     * Cria ou atualiza uma pergunta com todas as configuracoes.
     * $d: id (0 = nova), secao, nome, tipo, obrigatoria, descricao,
     *     opcoes (lista de {k,t} ou de textos), config {itemtype, multiplo, data_atual}, padrao
     */
    public static function salvarPergunta(array $d): array
    {
        global $DB;

        $id   = (int) ($d['id'] ?? 0);
        $nome = trim((string) ($d['nome'] ?? ''));
        $slug = (string) ($d['tipo'] ?? '');
        $mapa = self::mapa();
        if ($nome === '') {
            return ['ok' => false, 'msg' => 'Informe o nome da pergunta.'];
        }
        if (!isset($mapa[$slug]) || !class_exists($mapa[$slug]['fqcn'])) {
            return ['ok' => false, 'msg' => 'Tipo de pergunta nao disponivel nesta versao do GLPI.'];
        }
        $def = $mapa[$slug];

        $atual = null;
        if ($id > 0) {
            $atual = $DB->request(['FROM' => 'glpi_forms_questions', 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();
            if (!$atual) {
                return ['ok' => false, 'msg' => 'Pergunta nao encontrada.'];
            }
        }
        $extraAtual = $atual ? self::json($atual['extra_data'] ?? '') : [];
        $mesmoTipo  = $atual && ltrim((string) $atual['type'], '\\') === $def['fqcn'];

        // ---- extra_data (quem nao envia a configuracao mantem a atual)
        $cfg = is_array($d['config'] ?? null) ? $d['config'] : [
            'itemtype'   => (string) ($extraAtual['itemtype'] ?? ''),
            'multiplo'   => !empty($def['multi_campo']) && !empty($extraAtual[$def['multi_campo']]),
            'data_atual' => !empty($extraAtual['is_default_value_current_time']),
        ];
        $extra = null;
        if (isset($def['dt'])) {
            $extra = [
                'is_default_value_current_time' => !empty($cfg['data_atual']),
                'is_date_enabled'               => (bool) $def['dt'][0],
                'is_time_enabled'               => (bool) $def['dt'][1],
            ];
        } elseif (!empty($def['opcoes'])) {
            $opcoes = self::opcoesComChaves($d['opcoes'] ?? [], $mesmoTipo || self::ehEscolha((string) ($atual['type'] ?? '')) ? (array) ($extraAtual['options'] ?? []) : []);
            if (!$opcoes) {
                return ['ok' => false, 'msg' => 'Informe ao menos uma opcao.'];
            }
            $extra = ['options' => $opcoes];
            if ($def['fqcn'] === self::NS . 'QuestionTypeDropdown') {
                $extra['is_multiple_dropdown'] = !empty($def['multi_lista']);
            }
        } elseif (!empty($def['itemtype'])) {
            $itemtype = (string) ($cfg['itemtype'] ?? ($extraAtual['itemtype'] ?? ''));
            $validos  = array_column(self::tiposDeItem()[$def['itemtype']] ?? [], 'v');
            if ($itemtype === '' || !in_array($itemtype, $validos, true)) {
                return ['ok' => false, 'msg' => 'Escolha o tipo de item da pergunta.'];
            }
            $base = $mesmoTipo && ($extraAtual['itemtype'] ?? '') === $itemtype ? $extraAtual : [];
            $extra = array_merge([
                'itemtype'             => $itemtype,
                'root_items_id'        => 0,
                'subtree_depth'        => 0,
                'selectable_tree_root' => false,
                'is_multiple_items'    => false,
            ], $base, ['itemtype' => $itemtype, 'is_multiple_items' => !empty($cfg['multiplo'])]);
            if ($def['itemtype'] === 'itemlista') {
                if (!isset($extra['categories_filter']) || !is_array($extra['categories_filter'])) {
                    $extra['categories_filter'] = $itemtype === 'ITILCategory' ? ['request', 'incident', 'change', 'problem'] : [];
                }
            } else {
                unset($extra['categories_filter']);
            }
        } elseif (!empty($def['multi_campo'])) {
            $extra = ($mesmoTipo ? $extraAtual : []);
            $extra[$def['multi_campo']] = !empty($cfg['multiplo']);
        }

        // ---- valor padrao: vai no formato de entrada do tipo; o proprio GLPI (Question::prepareInput)
        // converte para o formato gravado. Sem o campo, mantem o atual.
        $entrada = $d['padrao'] ?? '';
        if (!array_key_exists('padrao', $d) && $mesmoTipo) {
            $entrada = self::padraoParaTela($def['padrao'], (string) ($atual['default_value'] ?? ''));
        }
        $enviarPadrao = true;
        switch ($def['padrao']) {
            case 'opcoes':
                $chaves = array_values(array_intersect(array_map('strval', (array) $entrada), array_map('strval', array_keys($extra['options'] ?? []))));
                if (!in_array($slug, ['multipla', 'caixas'], true)) {
                    $chaves = array_slice($chaves, 0, 1);
                }
                $padrao = implode(',', $chaves);
                break;
            case 'atores':
                $usuarios = array_values(array_filter(array_map('intval', (array) ($entrada['usuarios'] ?? []))));
                $grupos   = array_values(array_filter(array_map('intval', (array) ($entrada['grupos'] ?? []))));
                $padrao   = ($usuarios || $grupos) ? ['users_ids' => $usuarios, 'groups_ids' => $grupos] : null;
                break;
            case 'nenhum':
                // Itens, dispositivo e arquivo: o padrao (se houver) e definido no editor nativo e nao e tocado aqui
                $enviarPadrao = !$mesmoTipo;
                $padrao = null;
                break;
            default:
                $padrao = is_scalar($entrada) ? trim((string) $entrada) : '';
                if ($def['padrao'] === 'numero' && $padrao !== '' && !is_numeric($padrao)) {
                    return ['ok' => false, 'msg' => 'O valor padrao precisa ser um numero.'];
                }
                if ($slug === 'email' && $padrao !== '' && !filter_var($padrao, FILTER_VALIDATE_EMAIL)) {
                    return ['ok' => false, 'msg' => 'O valor padrao nao e um e-mail valido.'];
                }
        }

        $input = [
            'name'          => $nome,
            'type'          => $def['fqcn'],
            'is_mandatory'  => !empty($d['obrigatoria']) ? 1 : 0,
            'description'   => (string) ($d['descricao'] ?? ''),
            'extra_data'    => $extra === null ? '' : json_encode($extra),
        ];
        if ($enviarPadrao) {
            $input['default_value'] = $padrao;
        }

        $q = new Question();
        if ($id > 0) {
            $input['id'] = $id;
            if (!$q->update($input)) {
                return ['ok' => false, 'msg' => 'Falha ao atualizar a pergunta.'];
            }
            // Tipo trocado: as condicoes de outras perguntas que comparavam com o valor antigo deixam de fazer sentido
            return ['ok' => true, 'msg' => 'Pergunta atualizada.', 'id' => $id, 'tipo_mudou' => !$mesmoTipo];
        }

        $secao = (int) ($d['secao'] ?? 0);
        if ($secao <= 0 || countElementsInTable('glpi_forms_sections', ['id' => $secao]) === 0) {
            return ['ok' => false, 'msg' => 'Secao invalida.'];
        }
        $input['forms_sections_id'] = $secao;
        $input['vertical_rank']     = self::proximaOrdem($secao);
        $input['horizontal_rank']   = null;
        $novo = $q->add($input);
        return $novo
            ? ['ok' => true, 'msg' => 'Pergunta criada.', 'id' => (int) $novo]
            : ['ok' => false, 'msg' => 'Falha ao criar a pergunta.'];
    }

    private static function ehEscolha(string $fqcn): bool
    {
        $fqcn = ltrim($fqcn, '\\');
        foreach (['QuestionTypeDropdown', 'QuestionTypeRadio', 'QuestionTypeCheckbox'] as $c) {
            if ($fqcn === self::NS . $c) {
                return true;
            }
        }
        return false;
    }

    /**
     * Monta options { chave => texto } preservando as chaves que ja existem
     * (as condicoes e o valor padrao apontam para a chave, nao para a posicao).
     * Aceita {k,t} ou so textos (estes casam pelo texto com as opcoes atuais).
     */
    private static function opcoesComChaves(array $entrada, array $atuais): array
    {
        $porTexto = [];
        foreach ($atuais as $k => $t) {
            $porTexto[mb_strtolower(trim((string) $t))] ??= (string) $k;
        }
        $proxima = 1;
        foreach (array_keys($atuais) as $k) {
            if (is_numeric($k)) {
                $proxima = max($proxima, (int) $k + 1);
            }
        }

        $saida = [];
        foreach ($entrada as $o) {
            $texto = trim((string) (is_array($o) ? ($o['t'] ?? '') : $o));
            if ($texto === '') {
                continue;
            }
            $chave = is_array($o) ? trim((string) ($o['k'] ?? '')) : '';
            if ($chave === '' || isset($saida[$chave]) || ($chave !== '' && !array_key_exists($chave, $atuais))) {
                $chave = $porTexto[mb_strtolower($texto)] ?? '';
            }
            if ($chave === '' || isset($saida[$chave])) {
                $chave = (string) $proxima++;
            }
            $saida[$chave] = $texto;
        }
        return $saida;
    }

    /** Proxima posicao vertical numa secao (perguntas e blocos de texto dividem a mesma ordem). */
    private static function proximaOrdem(int $secaoId): int
    {
        global $DB;
        $max = -1;
        foreach (['glpi_forms_questions', 'glpi_forms_comments'] as $t) {
            if (!$DB->tableExists($t)) {
                continue;
            }
            $r = $DB->request(['SELECT' => [new \Glpi\DBAL\QueryExpression('MAX(vertical_rank) AS m')], 'FROM' => $t, 'WHERE' => ['forms_sections_id' => $secaoId]])->current();
            if ($r && $r['m'] !== null) {
                $max = max($max, (int) $r['m']);
            }
        }
        return $max + 1;
    }

    /** Duplica uma pergunta logo abaixo da original (sem as condicoes que dependem dela). */
    public static function duplicarPergunta(int $id): array
    {
        global $DB;
        $q = $DB->request(['FROM' => 'glpi_forms_questions', 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();
        if (!$q) {
            return ['ok' => false, 'msg' => 'Pergunta nao encontrada.'];
        }
        $input = [];
        foreach (['forms_sections_id', 'type', 'is_mandatory', 'description', 'default_value', 'extra_data', 'visibility_strategy', 'conditions', 'validation_strategy', 'validation_conditions'] as $c) {
            if (array_key_exists($c, $q)) {
                $input[$c] = $q[$c];
            }
        }
        $input['name']            = $q['name'] . ' (copia)';
        $input['vertical_rank']   = self::proximaOrdem((int) $q['forms_sections_id']);
        $input['horizontal_rank'] = null;
        $novo = (new Question())->add($input);
        return $novo ? ['ok' => true, 'msg' => 'Pergunta duplicada.', 'id' => (int) $novo] : ['ok' => false, 'msg' => 'Falha ao duplicar.'];
    }

    // =================================================================
    // Blocos de texto (comentarios nativos)
    // =================================================================

    public static function salvarComentario(array $d): array
    {
        if (!self::temComentarios()) {
            return ['ok' => false, 'msg' => 'Esta versao do GLPI nao tem blocos de texto nos formularios.'];
        }
        $id   = (int) ($d['id'] ?? 0);
        $nome = trim((string) ($d['nome'] ?? ''));
        $desc = (string) ($d['descricao'] ?? '');
        if ($nome === '' && trim(strip_tags($desc)) === '') {
            return ['ok' => false, 'msg' => 'Informe o titulo ou o texto do bloco.'];
        }
        $cls = self::COMMENT;
        $c   = new $cls();
        if ($id > 0) {
            return $c->update(['id' => $id, 'name' => $nome, 'description' => $desc])
                ? ['ok' => true, 'msg' => 'Bloco de texto atualizado.', 'id' => $id]
                : ['ok' => false, 'msg' => 'Falha ao atualizar o bloco de texto.'];
        }
        $secao = (int) ($d['secao'] ?? 0);
        if ($secao <= 0 || countElementsInTable('glpi_forms_sections', ['id' => $secao]) === 0) {
            return ['ok' => false, 'msg' => 'Secao invalida.'];
        }
        $novo = $c->add([
            'forms_sections_id' => $secao,
            'name'              => $nome,
            'description'       => $desc,
            'vertical_rank'     => self::proximaOrdem($secao),
            'horizontal_rank'   => null,
        ]);
        return $novo ? ['ok' => true, 'msg' => 'Bloco de texto criado.', 'id' => (int) $novo] : ['ok' => false, 'msg' => 'Falha ao criar o bloco de texto.'];
    }

    public static function excluirComentario(int $id): array
    {
        if (!self::temComentarios() || $id <= 0) {
            return ['ok' => false, 'msg' => 'Bloco invalido.'];
        }
        $cls = self::COMMENT;
        return (new $cls())->delete(['id' => $id], true)
            ? ['ok' => true, 'msg' => 'Bloco de texto excluido.']
            : ['ok' => false, 'msg' => 'Falha ao excluir o bloco de texto.'];
    }

    /**
     * Grava a ordem dos blocos de uma secao. $blocos: lista "pergunta:ID" / "comentario:ID".
     * Blocos vindos de outra secao passam para esta (arrastar entre secoes).
     */
    public static function ordenarBlocos(int $secaoId, array $blocos): array
    {
        if ($secaoId <= 0 || countElementsInTable('glpi_forms_sections', ['id' => $secaoId]) === 0) {
            return ['ok' => false, 'msg' => 'Secao invalida.'];
        }
        $q   = new Question();
        $cls = ltrim(self::COMMENT, '\\');
        $c   = self::temComentarios() ? new $cls() : null;
        $ordem = 0;
        foreach ($blocos as $b) {
            [$tipo, $id] = array_pad(explode(':', (string) $b, 2), 2, 0);
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $dados = ['id' => $id, 'vertical_rank' => $ordem, 'forms_sections_id' => $secaoId];
            if ($tipo === 'pergunta') {
                $q->update($dados);
            } elseif ($tipo === 'comentario' && $c) {
                $c->update($dados);
            } else {
                continue;
            }
            $ordem++;
        }
        return ['ok' => true, 'msg' => 'Ordem atualizada.'];
    }

    // =================================================================
    // Geral do formulario
    // =================================================================

    /** Salva os campos gerais (aba Geral do acordeao). */
    public static function salvarGeral(int $formId, array $d): array
    {
        global $DB;

        $f = $DB->request(['FROM' => 'glpi_forms_forms', 'WHERE' => ['id' => $formId], 'LIMIT' => 1])->current();
        if (!$f) {
            return ['ok' => false, 'msg' => 'Formulario nao encontrado.'];
        }
        $nome = trim((string) ($d['nome'] ?? $f['name']));
        if ($nome === '') {
            return ['ok' => false, 'msg' => 'Informe o nome do formulario.'];
        }
        $dados = [
            'id'                  => $formId,
            'name'                => $nome,
            'description'         => (string) ($d['descricao'] ?? $f['description']),
            'header'              => (string) ($d['header'] ?? $f['header']),
            'forms_categories_id' => max(0, (int) ($d['categoria'] ?? $f['forms_categories_id'])),
            'is_recursive'        => !empty($d['recursivo']) ? 1 : 0,
        ];
        if (array_key_exists('illustration', $f) && array_key_exists('ilustracao', $d)) {
            // Ids nativos (letras, numeros, - e _) ou enviados pelo usuario ("custom:arquivo.png")
            $dados['illustration'] = preg_replace('/[^A-Za-z0-9_.:\-]/', '', (string) $d['ilustracao']);
        }
        // Entidade: so uma das entidades ativas de quem edita (a atual continua valendo)
        if (array_key_exists('entidade', $d) && (int) $d['entidade'] !== (int) $f['entities_id']) {
            $ent = (int) $d['entidade'];
            if (!in_array($ent, array_map('intval', $_SESSION['glpiactiveentities'] ?? []), true)
                || countElementsInTable('glpi_entities', ['id' => $ent]) === 0) {
                return ['ok' => false, 'msg' => 'Entidade invalida ou fora das suas entidades ativas.'];
            }
            $dados['entities_id'] = $ent;
        }
        if (array_key_exists('is_pinned', $f) && array_key_exists('fixado', $d)) {
            $dados['is_pinned'] = !empty($d['fixado']) ? 1 : 0;
        }
        if (array_key_exists('render_layout', $f) && in_array((string) ($d['layout'] ?? ''), ['step_by_step', 'single_page'], true)) {
            $dados['render_layout'] = (string) $d['layout'];
        }
        if (!(new Form())->update($dados)) {
            return ['ok' => false, 'msg' => 'Falha ao salvar o formulario.'];
        }
        if (array_key_exists('ativo', $d) && (bool) $d['ativo'] !== ((int) $f['is_active'] === 1)) {
            $r = PluginCatalogoeformulariosCatalogo::alternarAtivoFormulario($formId, !empty($d['ativo']));
            if (empty($r['ok'])) {
                return ['ok' => false, 'msg' => 'Dados salvos, mas o status nao mudou: ' . $r['msg']];
            }
        }
        return ['ok' => true, 'msg' => 'Formulario salvo.'];
    }

    // =================================================================
    // Util
    // =================================================================

    /**
     * Grava colunas de regra pelo objeto nativo (historico e ganchos do GLPI) e confere
     * o que ficou no banco; se o objeto recusar, grava direto na mesma tabela nativa.
     */
    private static function gravarColunas(string $classe, string $tabela, int $id, array $colunas): bool
    {
        global $DB;
        try {
            $cls = ltrim($classe, '\\');
            if (class_exists($cls)) {
                (new $cls())->update(['id' => $id] + $colunas);
            }
        } catch (\Throwable $e) {
            // confere abaixo
        }
        $r = $DB->request(['SELECT' => array_keys($colunas), 'FROM' => $tabela, 'WHERE' => ['id' => $id]])->current();
        $igual = $r !== null;
        foreach ($colunas as $c => $v) {
            if ($r === null || (string) $r[$c] !== (string) $v) {
                $igual = false;
            }
        }
        return $igual || (bool) $DB->update($tabela, $colunas, ['id' => $id]);
    }

    private static function json($v): array
    {
        if (is_array($v)) {
            return $v;
        }
        if (!is_string($v) || $v === '') {
            return [];
        }
        $d = json_decode($v, true);
        return is_array($d) ? $d : [];
    }
}
