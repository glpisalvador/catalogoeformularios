<?php

/**
 * Facilitador de Destinos de formulario do GLPI 11.
 *
 * Os destinos sao persistidos na tabela nativa glpi_forms_destinations atraves
 * da classe nativa \Glpi\Form\Destination\FormDestination. Cada campo do destino
 * (entidade, categoria, SLA, atores, etc.) tem sua config montada pelas classes
 * nativas *FieldConfig + enums *Strategy, garantindo que o JSON gravado seja
 * EXATAMENTE o que a versao instalada do GLPI entende.
 *
 * IMPORTANTE: os nomes das classes de campo/config/strategy podem variar entre
 * versoes 11.0.x. Por isso TODO acesso e protegido (class_exists/enum_exists/try):
 * se algo nao bater, o campo NAO e gravado e o usuario e direcionado ao editor
 * nativo, evitando corromper o destino.
 */
class PluginCatalogoeformulariosDestino extends CommonGLPI
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

    private const FD = '\\Glpi\\Form\\Destination\\FormDestination';

    /** Classes de tipo de destino candidatas. */
    private static function tiposClasses(): array
    {
        $ns = '\\Glpi\\Form\\Destination\\';
        return [
            'ticket'  => ['classe' => $ns . 'FormDestinationTicket',  'label' => 'Chamado (Ticket)'],
            'change'  => ['classe' => $ns . 'FormDestinationChange',  'label' => 'Mudanca (Change)'],
            'problem' => ['classe' => $ns . 'FormDestinationProblem', 'label' => 'Problema (Problem)'],
        ];
    }

    /** Tipos de destino realmente disponiveis nesta instalacao. */
    public static function tiposDisponiveis(): array
    {
        $saida = [];
        foreach (self::tiposClasses() as $slug => $def) {
            if (class_exists($def['classe'])) {
                $saida[] = ['slug' => $slug, 'classe' => $def['classe'], 'label' => $def['label']];
            }
        }
        return $saida;
    }

    /** Lista os destinos de um formulario. */
    public static function listar(int $formId): array
    {
        global $DB;

        if (!$DB->tableExists('glpi_forms_destinations_formdestinations')) {
            return [];
        }

        $saida = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_forms_destinations_formdestinations',
            'WHERE' => ['forms_forms_id' => $formId],
            'ORDER' => 'id ASC',
        ]) as $row) {
            $itemtype = (string) ($row['itemtype'] ?? '');
            $saida[] = [
                'id'        => (int) $row['id'],
                'nome'      => $row['name'] ?? '',
                'itemtype'  => $itemtype,
                'tipo_label' => self::labelDoTipo($itemtype),
                'campos_estado' => self::estadoCampos((int) $row['id']),
            ];
        }
        return $saida;
    }

    /**
     * Le a condicao de criacao de um destino, normalizada no mesmo formato dos
     * demais alvos: estrategia 'sempre'|'visivel_se'|'oculto_se'. Para destinos,
     * 'visivel_se' = criar se, 'oculto_se' = nao criar se.
     */
    public static function lerCondicao(int $destinoId): array
    {
        global $DB;
        $vazio = ['estrategia' => 'sempre', 'estrategia_val' => '', 'condicoes' => []];
        if (!$DB->tableExists('glpi_forms_destinations_formdestinations')) {
            return $vazio;
        }
        $row = $DB->request([
            'SELECT' => ['creation_strategy', 'conditions'],
            'FROM'   => 'glpi_forms_destinations_formdestinations',
            'WHERE'  => ['id' => $destinoId],
            'LIMIT'  => 1,
        ])->current();
        if ($row === null) {
            return $vazio;
        }

        $estr = (string) ($row['creation_strategy'] ?? '');
        $cond = $row['conditions'] ?? [];
        if (is_string($cond)) {
            $cond = $cond !== '' ? (json_decode($cond, true) ?: []) : [];
        }
        if (!is_array($cond)) {
            $cond = [];
        }
        if (isset($cond['conditions']) && is_array($cond['conditions'])) {
            $cond = $cond['conditions'];
        }

        $low  = strtolower($estr);
        $slug = 'sempre';
        if (strpos($low, 'not') !== false && strpos($low, 'creat') !== false) {
            $slug = 'oculto_se';
        } elseif (strpos($low, 'creat') !== false && strpos($low, 'always') === false) {
            $slug = 'visivel_se';
        }
        return ['estrategia' => $slug, 'estrategia_val' => $estr, 'condicoes' => $cond];
    }

    /**
     * Grava creation_strategy + conditions ja serializadas (nativas) num destino.
     * Tenta conditions como array e depois como JSON (robusto entre versoes).
     */
    public static function gravarCondicaoBruta(int $destinoId, string $estrSlug, array $condicoes): bool
    {
        $fdClass = self::FD;
        if (!class_exists($fdClass)) {
            return false;
        }
        $estrVal = self::resolverCreationStrategy($estrSlug);
        if ($estrVal === null) {
            return false;
        }
        try {
            $fd = new $fdClass();
            if (!$fd->getFromDB($destinoId)) {
                return false;
            }
            foreach ([$condicoes, json_encode($condicoes)] as $payload) {
                try {
                    if ($fd->update([
                        'id'                => $destinoId,
                        'creation_strategy' => $estrVal,
                        'conditions'        => $payload,
                    ])) {
                        return true;
                    }
                } catch (\Throwable $e) {
                    // tenta proximo formato
                }
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }

    /**
     * Aplica a condicao "por opcao" num destino ADICIONAL. O destino principal
     * (menor id do formulario) nao aceita condicao no GLPI 11 (issue #19292).
     * $condsNovas: specs ['pergunta_uuid','operador','valor','logica'].
     */
    public static function aplicarCondicaoOpcao(int $formId, int $destinoId, string $estrSlug, array $condsNovas): array
    {
        if (self::ehDestinoPrimario($formId, $destinoId)) {
            return ['ok' => false, 'msg' => 'O destino principal nao aceita condicao (limite do GLPI 11). Crie um destino adicional e condicione-o.'];
        }
        if (!class_exists('PluginCatalogoeformulariosCondicao')
            || !method_exists('PluginCatalogoeformulariosCondicao', 'serializarCondicoes')) {
            return ['ok' => false, 'msg' => 'Serializador de condicoes indisponivel.'];
        }

        $serial = PluginCatalogoeformulariosCondicao::serializarCondicoes($condsNovas);
        if ($serial === null) {
            return ['ok' => false, 'msg' => 'Nao foi possivel montar a condicao do destino nesta versao. Use o editor nativo.'];
        }

        $atual = self::lerCondicao($destinoId);
        if ($atual['estrategia'] !== 'sempre' && !empty($atual['condicoes'])
            && $atual['estrategia'] !== $estrSlug) {
            return ['ok' => false, 'msg' => 'Este destino ja tem condicao de outra origem com estrategia diferente; ajuste no editor nativo.'];
        }
        $merge = array_merge(is_array($atual['condicoes']) ? $atual['condicoes'] : [], $serial);

        return self::gravarCondicaoBruta($destinoId, $estrSlug, $merge)
            ? ['ok' => true, 'msg' => 'ok']
            : ['ok' => false, 'msg' => 'Falha ao salvar a condicao do destino.'];
    }

    /** True se o destino e o principal (menor id do formulario). */
    private static function ehDestinoPrimario(int $formId, int $destinoId): bool
    {
        global $DB;
        $min = 0;
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_forms_destinations_formdestinations',
            'WHERE'  => ['forms_forms_id' => $formId],
            'ORDER'  => 'id ASC',
            'LIMIT'  => 1,
        ]) as $r) {
            $min = (int) $r['id'];
        }
        return $min > 0 && $min === $destinoId;
    }

    /** Resolve o valor nativo da estrategia de criacao a partir do slug de visibilidade. */
    private static function resolverCreationStrategy(string $slug): ?string
    {
        $enum = '\\Glpi\\Form\\Condition\\CreationStrategy';
        if (!enum_exists($enum)) {
            return null;
        }
        $cands = $slug === 'oculto_se'
            ? ['NOT_CREATED_IF', 'NOTCREATED_IF']
            : ($slug === 'visivel_se' ? ['CREATED_IF'] : ['ALWAYS_CREATED', 'ALWAYS']);
        foreach ($cands as $c) {
            if (defined("$enum::$c")) {
                $case = constant("$enum::$c");
                return $case instanceof \BackedEnum ? (string) $case->value : strtolower($case->name);
            }
        }
        foreach ($enum::cases() as $case) {
            $n = strtoupper($case->name);
            foreach ($cands as $c) {
                if (strpos($n, $c) !== false) {
                    return $case instanceof \BackedEnum ? (string) $case->value : strtolower($case->name);
                }
            }
        }
        return null;
    }

    private static function labelDoTipo(string $itemtype): string
    {
        foreach (self::tiposClasses() as $def) {
            if (ltrim($def['classe'], '\\') === ltrim($itemtype, '\\')) {
                return $def['label'];
            }
        }
        return $itemtype;
    }

    /** Config atual (bruta) de um destino, para exibicao. */
    public static function getConfigDestino(int $destinoId): ?array
    {
        global $DB;
        $fdClass = self::FD;
        if (!class_exists($fdClass) || !$DB->tableExists('glpi_forms_destinations_formdestinations')) {
            return null;
        }
        $fd = new $fdClass();
        if (!$fd->getFromDB($destinoId)) {
            return null;
        }
        $cfg = $fd->fields['config'] ?? [];
        if (is_string($cfg)) {
            $cfg = json_decode($cfg, true) ?: [];
        }
        return [
            'id'       => $destinoId,
            'nome'     => $fd->fields['name'] ?? '',
            'itemtype' => $fd->fields['itemtype'] ?? '',
            'config'   => $cfg,
        ];
    }

    /**
     * Le a config salva do destino e devolve, por slug de campo, a estrategia
     * e o valor escolhido, para o front pre-selecionar ao reabrir a tela.
     */
    public static function estadoCampos(int $destinoId): array
    {
        $dados = self::getConfigDestino($destinoId);
        if ($dados === null) {
            return [];
        }
        $cfg = $dados['config'] ?? [];
        $out = [];
        foreach (self::registro() as $slug => $reg) {
            if (empty($reg['field']) || !class_exists($reg['field'])) {
                continue;
            }
            try {
                $chave = (new $reg['field']())->getKey();
            } catch (\Throwable $e) {
                continue;
            }
            if (!isset($cfg[$chave]) || !is_array($cfg[$chave])) {
                continue;
            }
            $out[$slug] = self::interpretarConfigCampo($cfg[$chave]);
        }
        // Observadores usam multiselect proprio (fora da camada de "field" acima),
        // entao expomos aqui os grupos configurados para que a UI marque o campo
        // como preenchido (azul) ja no render, sem precisar abrir o acordeao.
        $gruposObs = self::lerObservadorGruposDestino($destinoId);
        if (!empty($gruposObs)) {
            $out['observador'] = ['grupos' => array_values($gruposObs)];
        }
        return $out;
    }

    /** Traduz o JSON nativo de um campo de volta para estrategia/valor/pergunta. */
    private static function interpretarConfigCampo(array $serial): array
    {
        $out      = ['estrategia' => 'especifico', 'valor' => null, 'question' => 0];
        $strategy = '';
        foreach ($serial as $k => $v) {
            if (strtolower((string) $k) === 'strategy') {
                $strategy = is_scalar($v) ? strtolower((string) $v) : '';
            }
        }
        if ($strategy === 'from_form') {
            $out['estrategia'] = 'formulario';
        } elseif (strpos($strategy, 'template') !== false) {
            $out['estrategia'] = 'modelo';
        } elseif (strpos($strategy, 'answer') !== false) {
            $out['estrategia'] = 'resposta';
        } elseif (strpos($strategy, 'specific') !== false) {
            $out['estrategia'] = 'especifico';
        }
        foreach ($serial as $k => $v) {
            $lk = strtolower((string) $k);
            if ($lk === 'strategy') {
                continue;
            }
            if (strpos($lk, 'question') !== false) {
                if (is_numeric($v)) { $out['question'] = (int) $v; }
                continue;
            }
            if ($out['valor'] === null && !empty($v) && (is_numeric($v) || is_array($v))) {
                $out['valor'] = is_array($v) ? $v : (int) $v;
            }
        }
        return $out;
    }

    /** Cria um destino com a auto-config nativa (comportamento padrao do GLPI 11). */
    public static function criar(int $formId, string $tipoClasse, string $nome = ''): array
    {
        if ($formId <= 0) {
            return ['ok' => false, 'msg' => 'Formulario invalido.'];
        }
        $fdClass = self::FD;
        if (!class_exists($fdClass) || !class_exists($tipoClasse)) {
            return ['ok' => false, 'msg' => 'Tipo de destino indisponivel nesta versao do GLPI.'];
        }

        $nome = trim($nome);
        if ($nome === '') {
            $nome = self::labelDoTipo($tipoClasse);
        }

        try {
            $fd = new $fdClass();
            $id = $fd->add([
                'forms_forms_id' => $formId,
                'itemtype'       => $tipoClasse,
                'name'           => $nome,
            ]);
            if ($id) {
                // Destino novo tambem usa a entidade do formulario
                self::definirCampo((int) $id, 'entidade', 'formulario');
            }
            return $id
                ? ['ok' => true, 'msg' => 'Destino criado.', 'id' => (int) $id]
                : ['ok' => false, 'msg' => 'Falha ao criar o destino.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => 'Erro ao criar destino: ' . $e->getMessage()];
        }
    }

    /**
     * Faz todos os destinos do formulario usarem a entidade do formulario (estrategia nativa
     * "From form"): a entidade escolhida na aba Geral e a do chamado gerado.
     */
    public static function entidadeDoFormulario(int $formId): array
    {
        $falhas = [];
        foreach (self::listar($formId) as $dest) {
            if (($dest['campos_estado']['entidade']['estrategia'] ?? '') === 'formulario') {
                continue;
            }
            $r = self::definirCampo((int) $dest['id'], 'entidade', 'formulario');
            if (empty($r['ok'])) {
                $falhas[] = $dest['nome'] . ': ' . ($r['msg'] ?? '');
            }
        }
        return $falhas ? ['ok' => false, 'msg' => implode('; ', $falhas)] : ['ok' => true, 'msg' => ''];
    }

    public static function renomear(int $destinoId, string $nome): array
    {
        $nome = trim($nome);
        if ($destinoId <= 0 || $nome === '') {
            return ['ok' => false, 'msg' => 'Dados invalidos.'];
        }
        $fdClass = self::FD;
        if (!class_exists($fdClass)) {
            return ['ok' => false, 'msg' => 'Classe de destino nao encontrada.'];
        }
        $fd = new $fdClass();
        return $fd->update(['id' => $destinoId, 'name' => $nome])
            ? ['ok' => true, 'msg' => 'Destino renomeado.']
            : ['ok' => false, 'msg' => 'Falha ao renomear o destino.'];
    }

    public static function excluir(int $destinoId): array
    {
        if ($destinoId <= 0) {
            return ['ok' => false, 'msg' => 'Destino invalido.'];
        }
        $fdClass = self::FD;
        if (!class_exists($fdClass)) {
            return ['ok' => false, 'msg' => 'Classe de destino nao encontrada.'];
        }
        $fd = new $fdClass();
        return $fd->delete(['id' => $destinoId], true)
            ? ['ok' => true, 'msg' => 'Destino excluido.']
            : ['ok' => false, 'msg' => 'Falha ao excluir o destino.'];
    }

    /** URL do editor nativo de formularios (aba Destinos do formulario). */
    public static function getUrlEditorNativo(int $formId): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/front/form/form.php?id=' . $formId;
    }

    /** Localiza o destino de Ticket criado automaticamente pelo GLPI no formulario. */
    public static function getDestinoTicketPadrao(int $formId): int
    {
        global $DB;
        if (!$DB->tableExists('glpi_forms_destinations_formdestinations')) {
            return 0;
        }
        foreach ($DB->request([
            'SELECT' => ['id', 'itemtype'],
            'FROM'   => 'glpi_forms_destinations_formdestinations',
            'WHERE'  => ['forms_forms_id' => $formId],
            'ORDER'  => 'id ASC',
        ]) as $row) {
            if (stripos(ltrim((string) $row['itemtype'], '\\'), 'FormDestinationTicket') !== false) {
                return (int) $row['id'];
            }
        }
        return 0;
    }

    /** Aplica categoria ITIL e grupos observadores no destino de Ticket padrao do formulario. */
    public static function aplicarCategoriaEObservadores(int $formId, int $categoriaItilId, array $gruposIds): void
    {
        $destinoId = self::getDestinoTicketPadrao($formId);
        if ($destinoId <= 0) {
            return;
        }
        if ($categoriaItilId > 0) {
            self::definirCampo($destinoId, 'categoria', 'especifico', $categoriaItilId);
        }
        if (!empty($gruposIds)) {
            self::definirObservadorGrupos($destinoId, $gruposIds);
        }
    }

    /** Le a categoria ITIL e os grupos observadores atuais do destino de Ticket padrao. */
    public static function lerCategoriaEObservadores(int $formId): array
    {
        global $DB;

        $out = ['categoria_itil' => 0, 'observador_grupos' => []];

        $destinoId = self::getDestinoTicketPadrao($formId);
        if ($destinoId <= 0) {
            return $out;
        }

        // Le o JSON bruto da coluna `config` (sem passar pela camada de objetos do destino).
        $row = $DB->request([
            'SELECT' => ['config'],
            'FROM'   => 'glpi_forms_destinations_formdestinations',
            'WHERE'  => ['id' => $destinoId],
            'LIMIT'  => 1,
        ])->current();
        if ($row === null) {
            return $out;
        }

        $config = $row['config'] ?? '';
        if (is_string($config)) {
            $config = json_decode($config, true) ?: [];
        }
        if (!is_array($config)) {
            return $out;
        }

        // Busca recursiva em toda a config (robusto contra aninhamento e variacao de chave).
        $out['categoria_itil']    = self::buscarIntPorChave($config, '/itilcateg\w*_id/i');
        $out['observador_grupos'] = self::extrairGruposDeAtores($config);

        return $out;
    }

    /** Procura recursivamente o 1o valor inteiro > 0 cuja chave casa com o regex. */
    private static function buscarIntPorChave(array $node, string $regexChave): int
    {
        foreach ($node as $k => $v) {
            if (is_string($v) && isset($v[0]) && ($v[0] === '{' || $v[0] === '[')) {
                $dec = json_decode($v, true);
                if (is_array($dec)) {
                    $v = $dec;
                }
            }
            if (is_array($v)) {
                $achado = self::buscarIntPorChave($v, $regexChave);
                if ($achado > 0) {
                    return $achado;
                }
            } elseif (preg_match($regexChave, (string) $k) && (int) $v > 0) {
                return (int) $v;
            }
        }
        return 0;
    }

    /** Extrai recursivamente os IDs de grupos de uma estrutura de atores especificos. */
    private static function extrairGruposDeAtores(array $sub): array
    {
        $achados = [];
        $busca = function ($node) use (&$busca, &$achados) {
            if (is_string($node) && isset($node[0]) && ($node[0] === '{' || $node[0] === '[')) {
                $dec = json_decode($node, true);
                if (is_array($dec)) {
                    $node = $dec;
                }
            }
            if (!is_array($node)) {
                return;
            }
            if (isset($node['itemtype'], $node['items_id']) && $node['itemtype'] === 'Group') {
                $id = (int) $node['items_id'];
                if ($id > 0) {
                    $achados[] = $id;
                }
            }
            foreach ($node as $k => $v) {
                if (((string) $k === 'Group' || (string) $k === 'groups_ids') && is_array($v)) {
                    foreach ($v as $gid) {
                        $id = (int) (is_array($gid) ? ($gid['items_id'] ?? $gid['id'] ?? 0) : $gid);
                        if ($id > 0) {
                            $achados[] = $id;
                        }
                    }
                } else {
                    $busca($v);
                }
            }
        };
        $busca($sub);
        return array_values(array_unique($achados));
    }

    /** Edicao: categoria (null = nao mexe) e observadores (null = nao mexe; [] = limpa). */
    public static function editarDestinoCategoriaObservadores(int $formId, ?int $catItil, ?array $grupos): void
    {
        $destinoId = self::getDestinoTicketPadrao($formId);
        if ($destinoId <= 0) {
            return;
        }
        if ($catItil !== null) {
            self::definirCampo($destinoId, 'categoria', 'especifico', $catItil);
        }
        if ($grupos !== null) {
            if (empty($grupos)) {
                self::removerCampoConfig($destinoId, '\\Glpi\\Form\\Destination\\CommonITILField\\ObserverField');
            } else {
                self::definirObservadorGrupos($destinoId, $grupos);
            }
        }
    }

    /** Le os grupos observadores atualmente configurados em UM destino especifico. */
    public static function lerObservadorGruposDestino(int $destinoId): array
    {
        global $DB;
        if ($destinoId <= 0) {
            return [];
        }
        $row = $DB->request([
            'SELECT' => ['config'],
            'FROM'   => 'glpi_forms_destinations_formdestinations',
            'WHERE'  => ['id' => $destinoId],
            'LIMIT'  => 1,
        ])->current();
        if ($row === null) {
            return [];
        }
        $config = $row['config'] ?? '';
        if (is_string($config)) {
            $config = json_decode($config, true) ?: [];
        }
        if (!is_array($config)) {
            return [];
        }
        return self::extrairGruposDeAtores($config);
    }

    /** Define (grupos != vazio) ou limpa (vazio) os grupos observadores de UM destino. */
    public static function salvarObservadorGrupos(int $destinoId, array $gruposIds): array
    {
        if ($destinoId <= 0) {
            return ['ok' => false, 'msg' => 'Destino invalido.'];
        }
        $ids = array_values(array_filter(array_map('intval', $gruposIds)));
        if (empty($ids)) {
            self::removerCampoConfig($destinoId, '\\Glpi\\Form\\Destination\\CommonITILField\\ObserverField');
            return ['ok' => true, 'msg' => 'Observadores limpos (voltou ao padrao).'];
        }
        return self::definirObservadorGrupos($destinoId, $ids);
    }

    /** Remove a config de um campo do destino (volta ao comportamento padrao do GLPI). */
    private static function removerCampoConfig(int $destinoId, string $fieldClass): void
    {
        $fdClass = self::FD;
        if (!class_exists($fdClass) || !class_exists($fieldClass)) {
            return;
        }
        try {
            $fd = new $fdClass();
            if (!$fd->getFromDB($destinoId)) {
                return;
            }
            $chave = (new $fieldClass())->getKey();
            $cfg   = $fd->fields['config'] ?? [];
            if (is_string($cfg)) {
                $cfg = json_decode($cfg, true) ?: [];
            }
            if (array_key_exists($chave, $cfg)) {
                unset($cfg[$chave]);
                self::gravarConfig($fd, $destinoId, $cfg);
            }
        } catch (\Throwable $e) {
        }
    }

    /** Grava grupos como observadores especificos no destino (campo de ator do GLPI 11). */
    public static function definirObservadorGrupos(int $destinoId, array $gruposIds): array
    {
        global $DB;
        $chave = 'glpi-form-destination-commonitilfield-observerfield';

        try {
            $row = $DB->request([
                'SELECT' => ['config'],
                'FROM'   => 'glpi_forms_destinations_formdestinations',
                'WHERE'  => ['id' => $destinoId],
                'LIMIT'  => 1,
            ])->current();
            if ($row === null) {
                return ['ok' => false, 'msg' => 'Destino nao encontrado.'];
            }

            $cfg = $row['config'] ?? '';
            if (is_string($cfg)) {
                $cfg = json_decode($cfg, true) ?: [];
            }
            if (!is_array($cfg)) {
                $cfg = [];
            }

            $ids = array_values(array_filter(array_map('intval', $gruposIds)));

            // Formato identico ao gravado pelo editor nativo desta versao:
            //   strategies = ["specific_values"]
            //   specific_itilactors_ids = { "Group": [id, id, ...] }
            //   specific_question_ids = null
            $cfg[$chave] = [
                'strategies'              => ['specific_values'],
                'specific_itilactors_ids' => empty($ids) ? null : ['Group' => array_values($ids)],
                'specific_question_ids'   => null,
            ];

            // Escreve a config direto (o $fd->update() dispara explode() em campos de ator).
            $DB->update(
                'glpi_forms_destinations_formdestinations',
                ['config' => json_encode($cfg)],
                ['id' => $destinoId]
            );

            return ['ok' => true, 'msg' => 'Observadores definidos.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => 'Observadores nao aplicados: ' . $e->getMessage()];
        }
    }

    /** Monta o ObserverFieldConfig por reflexao, tratando estrategia em array (atores). */
    private static function construirConfigObservador(string $configClass, string $enumClass, $caso, array $valorIds): ?object
    {
        $ref  = new \ReflectionClass($configClass);
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            return $ref->newInstance();
        }

        $args = [];
        foreach ($ctor->getParameters() as $p) {
            $type     = $p->getType();
            $typeName = ($type instanceof \ReflectionNamedType) ? ltrim($type->getName(), '\\') : '';
            $pname    = $p->getName();

            if ($typeName === 'array' && preg_match('/strateg/i', $pname)) {
                $args[] = ($caso !== null) ? [$caso] : [];
                continue;
            }
            if ($enumClass && $typeName === ltrim($enumClass, '\\')) {
                if ($caso === null) {
                    return null;
                }
                $args[] = $caso;
                continue;
            }
            if (preg_match('/(itilactors|actors|ids)/i', $pname)) {
                $args[] = $valorIds;
                continue;
            }
            if ($p->isDefaultValueAvailable()) {
                $args[] = $p->getDefaultValue();
                continue;
            }
            if ($p->allowsNull()) {
                $args[] = null;
                continue;
            }
            return null;
        }

        return $ref->newInstanceArgs($args);
    }

    // -----------------------------------------------------------------
    // Registro de campos configuraveis (classes nativas, version-guarded)
    // -----------------------------------------------------------------

    /**
     * Mapa de campos do destino -> classes nativas.
     * Os FQCNs sao assumidos para o GLPI 11; tudo e protegido por class_exists.
     * 'fonte' indica de qual lista nativa o valor vem (ver PluginCatalogoeformulariosFonte).
     */
    private static function registro(): array
    {
        $b = '\\Glpi\\Form\\Destination\\CommonITILField\\';
        return [
            'entidade'    => ['label' => 'Cliente (entidade)',                 'fonte' => 'entidades',      'field' => $b . 'EntityField',          'config' => $b . 'EntityFieldConfig',          'enum' => $b . 'EntityFieldStrategy'],
            'categoria'   => ['label' => 'Categoria ITIL',                     'fonte' => 'itilcategorias', 'field' => $b . 'ITILCategoryField',    'config' => $b . 'ITILCategoryFieldConfig',    'enum' => $b . 'ITILCategoryFieldStrategy'],
            'tipo'        => ['label' => 'Tipo de requisicao',                 'fonte' => 'tiposrequisicao','field' => $b . 'RequestTypeField',     'config' => $b . 'RequestTypeFieldConfig',     'enum' => $b . 'RequestTypeFieldStrategy'],
            'status'      => ['label' => 'Status',                             'fonte' => 'status',         'field' => $b . 'StatusField',          'config' => $b . 'StatusFieldConfig',          'enum' => $b . 'StatusFieldStrategy'],
            'origem'      => ['label' => 'Origem da requisicao',               'fonte' => 'origens',        'field' => $b . 'RequestSourceField',   'config' => $b . 'RequestSourceFieldConfig',   'enum' => $b . 'RequestSourceFieldStrategy'],
            'urgencia'    => ['label' => 'Urgencia',                           'fonte' => 'urgencias',      'field' => $b . 'UrgencyField',         'config' => $b . 'UrgencyFieldConfig',         'enum' => $b . 'UrgencyFieldStrategy'],
            'localizacao' => ['label' => 'Localizacao',                        'fonte' => 'localizacoes',   'field' => $b . 'LocationField',        'config' => $b . 'LocationFieldConfig',        'enum' => $b . 'LocationFieldStrategy'],
            'requerente'  => ['label' => 'Requerentes',         'multi' => true, 'fonte' => 'atores',       'field' => $b . 'RequesterField',       'config' => $b . 'RequesterFieldConfig',       'enum' => $b . 'ITILActorFieldStrategy'],
            'observador'  => ['label' => 'Observadores',        'multi' => true, 'fonte' => 'atores',       'field' => $b . 'ObserverField',        'config' => $b . 'ObserverFieldConfig',        'enum' => $b . 'ITILActorFieldStrategy'],
            'atribuido'   => ['label' => 'Atribuicoes',         'multi' => true, 'fonte' => 'atores',       'field' => $b . 'AssigneeField',        'config' => $b . 'AssigneeFieldConfig',        'enum' => $b . 'ITILActorFieldStrategy'],
            'sla_tto'     => ['label' => 'Tempo para atendimento (SLA)',        'fonte' => 'slas',          'field' => $b . 'SLATTOField',          'config' => $b . 'SLATTOFieldConfig',          'enum' => $b . 'SLATTOFieldStrategy'],
            'sla_ttr'     => ['label' => 'Tempo para solucao (SLA)',            'fonte' => 'slas',          'field' => $b . 'SLATTRField',          'config' => $b . 'SLATTRFieldConfig',          'enum' => $b . 'SLATTRFieldStrategy'],
            'ola_tto'     => ['label' => 'Tempo interno para atendimento (OLA)','fonte' => 'olas',          'field' => $b . 'OLATTOField',          'config' => $b . 'OLATTOFieldConfig',          'enum' => $b . 'OLATTOFieldStrategy'],
            'ola_ttr'     => ['label' => 'Tempo interno para solucao (OLA)',    'fonte' => 'olas',          'field' => $b . 'OLATTRField',          'config' => $b . 'OLATTRFieldConfig',          'enum' => $b . 'OLATTRFieldStrategy'],
            'ativo'       => ['label' => 'Ativos vinculados',   'multi' => true, 'fonte' => 'ativos',       'field' => $b . 'AssociatedItemsField', 'config' => $b . 'AssociatedItemsFieldConfig', 'enum' => $b . 'AssociatedItemsFieldStrategy'],
            'template'    => ['label' => 'Modelo (template)',                  'fonte' => 'templates',      'field' => $b . 'TemplateField',        'config' => $b . 'TemplateFieldConfig',        'enum' => $b . 'TemplateFieldStrategy'],
        ];
    }

    /**
     * Lista de campos configuraveis para o front, com flag 'suporte' indicando
     * se a versao atual do GLPI permite configurar pelo plugin.
     */
    public static function camposSuportados(): array
    {
        $saida = [];
        foreach (self::registro() as $slug => $def) {
            $suporte = class_exists($def['field']) && class_exists($def['config']);
            $saida[] = [
                'slug'    => $slug,
                'label'   => $def['label'],
                'fonte'   => $def['fonte'],
                'multi'   => !empty($def['multi']),
                'suporte' => $suporte,
            ];
        }
        return $saida;
    }

    // -----------------------------------------------------------------
    // Gravacao da config de um campo (usando as classes nativas da versao)
    // -----------------------------------------------------------------

    /**
     * Define um campo do destino.
     *
     * @param string     $estrategia 'modelo' | 'especifico' | 'resposta'
     * @param mixed      $valor      id especifico (int) ou array (atores/ativos)
     * @param int        $questionId id da pergunta (estrategia 'resposta' especifica)
     */
    public static function definirCampo(int $destinoId, string $campoSlug, string $estrategia, $valor = null, int $questionId = 0): array
    {
        $reg = self::registro()[$campoSlug] ?? null;
        if ($reg === null) {
            return ['ok' => false, 'msg' => 'Campo nao suportado.'];
        }

        // Limpar: remove a chave do campo na config do destino, voltando ao padrao nativo.
        if ($estrategia === 'limpar') {
            if (!empty($reg['field']) && class_exists($reg['field'])) {
                self::removerCampoConfig($destinoId, $reg['field']);
                return ['ok' => true, 'msg' => 'Campo do destino voltou ao padrao.'];
            }
            return ['ok' => false, 'msg' => 'Campo nao suportado.'];
        }

        $fdClass = self::FD;
        if (!class_exists($fdClass)) {
            return ['ok' => false, 'msg' => 'Classe de destino nao encontrada nesta versao.'];
        }
        if (!class_exists($reg['field']) || !class_exists($reg['config'])) {
            return ['ok' => false, 'msg' => 'Este campo nao pode ser configurado por aqui nesta versao. Use o editor nativo.'];
        }

        try {
            $fd = new $fdClass();
            if (!$fd->getFromDB($destinoId)) {
                return ['ok' => false, 'msg' => 'Destino nao encontrado.'];
            }

            // Chave do campo dentro do JSON de config (definida pela classe nativa).
            $chave = (new $reg['field']())->getKey();

            // Resolve o "case" do enum de estrategia.
            $enumClass = $reg['enum'] ?? null;
            $caso      = null;
            if ($enumClass && enum_exists($enumClass)) {
                $caso = self::resolverEstrategia($enumClass, $estrategia, $questionId > 0);
                if ($caso === null) {
                    return ['ok' => false, 'msg' => 'Estrategia indisponivel para este campo nesta versao. Use o editor nativo.'];
                }
            }

            // Monta o objeto de config nativo via reflexao (JSON garantido pela versao).
            $obj = self::construirConfig($reg['config'], $enumClass, $caso, $valor, $questionId);
            if ($obj === null) {
                return ['ok' => false, 'msg' => 'Nao foi possivel montar a configuracao deste campo nesta versao. Use o editor nativo.'];
            }

            $serial = method_exists($obj, 'jsonSerialize') ? $obj->jsonSerialize() : (array) $obj;

            $cfg = $fd->fields['config'] ?? [];
            if (is_string($cfg)) {
                $cfg = json_decode($cfg, true) ?: [];
            }
            $cfg[$chave] = $serial;

            return self::gravarConfig($fd, $destinoId, $cfg)
                ? ['ok' => true, 'msg' => 'Campo do destino atualizado.']
                : ['ok' => false, 'msg' => 'Falha ao salvar a configuracao do destino.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => 'Configuracao nao aplicada (incompatibilidade de versao). Use o editor nativo. Detalhe: ' . $e->getMessage()];
        }
    }

    /**
     * Grava a config do destino. O update nativo reprocessa todos os campos esperando o formato
     * do formulario da tela (ex.: atores "Group-1") e quebra com o formato ja salvo ({"Group":[1]}).
     * Nesse caso grava a coluna direto: a config ja esta no formato serializado nativo.
     */
    private static function gravarConfig(object $fd, int $destinoId, array $cfg): bool
    {
        global $DB;
        try {
            if ($fd->update(['id' => $destinoId, 'config' => $cfg])) {
                return true;
            }
        } catch (\Throwable $e) {
            // segue para a gravacao direta
        }
        return (bool) $DB->update(
            'glpi_forms_destinations_formdestinations',
            ['config' => json_encode($cfg, JSON_UNESCAPED_UNICODE)],
            ['id' => $destinoId]
        );
    }

    /** Resolve o case do enum de estrategia por nome/heuristica. */
    private static function resolverEstrategia(string $enumClass, string $meaning, bool $temPergunta)
    {
        if (!enum_exists($enumClass)) {
            return null;
        }

        if ($meaning === 'modelo') {
            $cands = ['FROM_TEMPLATE', 'TEMPLATE'];
        } elseif ($meaning === 'formulario') {
            // Entidade: o chamado recebe a entidade do proprio formulario
            if (!defined("$enumClass::FROM_FORM")) {
                return null;
            }
            return constant("$enumClass::FROM_FORM");
        } elseif ($meaning === 'resposta') {
            $cands = $temPergunta
                ? ['SPECIFIC_ANSWER', 'FROM_SPECIFIC_ANSWER', 'ANSWER']
                : ['LAST_VALID_ANSWER', 'FROM_LAST_VALID_ANSWER', 'ANSWER'];
        } else { // especifico
            $cands = ['SPECIFIC_VALUE', 'SPECIFIC_VALUES', 'SPECIFIC'];
        }

        // Match exato por nome do case.
        foreach ($cands as $c) {
            if (defined("$enumClass::$c")) {
                return constant("$enumClass::$c");
            }
        }
        // Match aproximado (contains).
        foreach ($enumClass::cases() as $case) {
            $n = strtoupper($case->name);
            foreach ($cands as $c) {
                if (strpos($n, $c) !== false) {
                    return $case;
                }
            }
        }
        return null;
    }

    /**
     * Constroi o objeto *FieldConfig por reflexao, casando os parametros do
     * construtor com a estrategia (enum), a pergunta e o valor especifico.
     * Retorna null se nao conseguir preencher um parametro obrigatorio.
     */
    private static function construirConfig(string $configClass, ?string $enumClass, $caso, $valor, int $questionId): ?object
    {
        if (!class_exists($configClass)) {
            return null;
        }
        $ref  = new \ReflectionClass($configClass);
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            return $ref->newInstance();
        }

        $args = [];
        foreach ($ctor->getParameters() as $p) {
            $type     = $p->getType();
            $typeName = ($type instanceof \ReflectionNamedType) ? ltrim($type->getName(), '\\') : '';
            $pname    = $p->getName();

            // Parametro da estrategia (enum).
            if ($enumClass && $typeName === ltrim($enumClass, '\\')) {
                if ($caso === null) {
                    return null;
                }
                $args[] = $caso;
                continue;
            }
            // Parametro de pergunta (estrategia 'resposta' especifica).
            if ($questionId > 0 && preg_match('/question/i', $pname)) {
                $args[] = $questionId;
                continue;
            }
            // Parametro de valor especifico (id escalar ou array).
            if ($valor !== null && preg_match('/(specific|value|ids?)/i', $pname)) {
                $args[] = $valor;
                continue;
            }
            // Default ou null.
            if ($p->isDefaultValueAvailable()) {
                $args[] = $p->getDefaultValue();
                continue;
            }
            if ($p->allowsNull()) {
                $args[] = null;
                continue;
            }
            // Parametro obrigatorio que nao sabemos preencher.
            return null;
        }

        return $ref->newInstanceArgs($args);
    }

    // -----------------------------------------------------------------
    // Apoio ao painel rapido de controle do formulario
    // -----------------------------------------------------------------

    /** Definicao publica de um campo do destino (label / fonte / suporte). */
    public static function definicaoCampo(string $slug): ?array
    {
        $reg = self::registro()[$slug] ?? null;
        if ($reg === null) {
            return null;
        }
        return [
            'slug'    => $slug,
            'label'   => $reg['label'],
            'fonte'   => $reg['fonte'],
            'multi'   => !empty($reg['multi']),
            'suporte' => class_exists($reg['field']) && class_exists($reg['config']),
        ];
    }

    /** Chave usada pelo GLPI para guardar a config de um campo dentro do destino. */
    public static function chaveCampo(string $fieldClass): string
    {
        if (class_exists($fieldClass)) {
            try {
                return (new $fieldClass())->getKey();
            } catch (\Throwable $e) {
                // cai no calculo abaixo
            }
        }
        return strtolower(str_replace('\\', '-', trim($fieldClass, '\\')));
    }

    /** Campos de ator do chamado gerado pelo formulario. */
    public static function mapaAtores(): array
    {
        $b = '\\Glpi\\Form\\Destination\\CommonITILField\\';
        return [
            'requerente' => ['label' => 'Requerentes',  'icone' => 'ti ti-user',       'field' => $b . 'RequesterField'],
            'observador' => ['label' => 'Observadores', 'icone' => 'ti ti-eye',        'field' => $b . 'ObserverField'],
            'atribuido'  => ['label' => 'Atribuido a',  'icone' => 'ti ti-user-check', 'field' => $b . 'AssigneeField'],
        ];
    }

    /** Config bruta (array) de um destino, direto da coluna nativa. */
    public static function configBruta(int $destinoId): array
    {
        global $DB;

        if ($destinoId <= 0 || !$DB->tableExists('glpi_forms_destinations_formdestinations')) {
            return [];
        }
        $row = $DB->request([
            'SELECT' => ['config'],
            'FROM'   => 'glpi_forms_destinations_formdestinations',
            'WHERE'  => ['id' => $destinoId],
            'LIMIT'  => 1,
        ])->current();
        if ($row === null) {
            return [];
        }
        $cfg = $row['config'] ?? '';
        if (is_string($cfg)) {
            $cfg = $cfg !== '' ? (json_decode($cfg, true) ?: []) : [];
        }
        return is_array($cfg) ? $cfg : [];
    }

    /** Normaliza uma lista de ids que pode vir como escalares ou como objetos. */
    private static function normalizarIds($lista): array
    {
        if (!is_array($lista)) {
            return [];
        }
        $saida = [];
        foreach ($lista as $item) {
            $id = is_array($item)
                ? (int) ($item['items_id'] ?? ($item['id'] ?? 0))
                : (int) $item;
            if ($id > 0) {
                $saida[] = $id;
            }
        }
        return array_values(array_unique($saida));
    }

    /** Le usuarios e grupos configurados em cada campo de ator do destino. */
    public static function lerAtores(int $destinoId): array
    {
        $cfg   = self::configBruta($destinoId);
        $saida = [];

        foreach (self::mapaAtores() as $slug => $def) {
            $chave = self::chaveCampo($def['field']);
            $bloco = $cfg[$chave] ?? null;
            if (is_string($bloco)) {
                $bloco = json_decode($bloco, true);
            }

            $item = [
                'label'     => $def['label'],
                'suporte'   => class_exists($def['field']),
                'usuarios'  => [],
                'grupos'    => [],
                'perguntas' => [],
            ];

            if (is_array($bloco)) {
                $ids = $bloco['specific_itilactors_ids'] ?? [];
                if (is_string($ids)) {
                    $ids = json_decode($ids, true) ?: [];
                }
                if (is_array($ids)) {
                    $item['usuarios'] = self::normalizarIds($ids['User'] ?? []);
                    $item['grupos']   = self::normalizarIds($ids['Group'] ?? []);
                }
                $item['perguntas'] = self::normalizarIds($bloco['specific_question_ids'] ?? []);
            }

            $saida[$slug] = $item;
        }
        return $saida;
    }

    /**
     * Grava usuarios e grupos especificos num campo de ator do destino.
     * Listas vazias removem a config, devolvendo o campo ao padrao do GLPI.
     */
    public static function salvarAtores(int $destinoId, string $slug, array $usuarios, array $grupos): array
    {
        global $DB;

        $def = self::mapaAtores()[$slug] ?? null;
        if ($destinoId <= 0 || $def === null) {
            return ['ok' => false, 'msg' => 'Ator invalido.'];
        }

        $u = self::normalizarIds($usuarios);
        $g = self::normalizarIds($grupos);

        try {
            $chave = self::chaveCampo($def['field']);
            $cfg   = self::configBruta($destinoId);

            if (empty($u) && empty($g)) {
                unset($cfg[$chave]);
                $msg = $def['label'] . ': voltou ao padrao do GLPI.';
            } else {
                $ids = [];
                if (!empty($u)) {
                    $ids['User'] = array_values($u);
                }
                if (!empty($g)) {
                    $ids['Group'] = array_values($g);
                }
                // Mesmo formato gravado pelo editor nativo desta versao.
                $cfg[$chave] = [
                    'strategies'              => ['specific_values'],
                    'specific_itilactors_ids' => $ids,
                    'specific_question_ids'   => null,
                ];
                $msg = $def['label'] . ': atualizado.';
            }

            // Escrita direta: o update() da camada de objetos dispara explode() em atores.
            $DB->update(
                'glpi_forms_destinations_formdestinations',
                ['config' => json_encode($cfg)],
                ['id' => $destinoId]
            );

            return ['ok' => true, 'msg' => $msg];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => 'Nao foi possivel salvar os atores: ' . $e->getMessage()];
        }
    }
}