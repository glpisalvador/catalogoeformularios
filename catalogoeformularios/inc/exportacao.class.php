<?php

/**
 * Exportacao do catalogo de servicos para um arquivo .json portavel.
 * Somente leitura: nada e alterado no banco.
 */
class PluginCatalogoeformulariosExportacao extends CommonGLPI
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

    // -----------------------------------------------------------------
    // Arvore para a tela de selecao
    // -----------------------------------------------------------------

    /**
     * Listas separadas para a tela de selecao: categorias do catalogo (com
     * nivel para indentacao) e formularios (com o caminho da categoria).
     */
    public static function listasSelecao(): array
    {
        global $DB;

        $cats   = [];
        $filhos = [];

        $campos = ['id', 'name', 'forms_categories_id'];
        if ($DB->fieldExists('glpi_forms_categories', 'illustration')) {
            $campos[] = 'illustration';
        }

        foreach ($DB->request([
            'SELECT' => $campos,
            'FROM'   => 'glpi_forms_categories',
            'ORDER'  => 'name ASC',
        ]) as $row) {
            $id  = (int) $row['id'];
            $pai = (int) $row['forms_categories_id'];

            $cats[$id] = [
                'id'         => $id,
                'nome'       => (string) $row['name'],
                'pai'        => $pai,
                'ilustracao' => (string) ($row['illustration'] ?? ''),
                'qtd'        => 0,
            ];
            $filhos[$pai][] = $id;
        }

        // Caminho completo de cada categoria.
        $caminho = [];
        foreach ($cats as $id => $c) {
            $partes = [];
            $atual  = $id;
            $guarda = 0;
            while ($atual > 0 && isset($cats[$atual]) && $guarda < 30) {
                $partes[] = $cats[$atual]['nome'];
                $atual    = $cats[$atual]['pai'];
                $guarda++;
            }
            $caminho[$id] = implode(' > ', array_reverse($partes));
        }

        $formularios = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'is_active', 'is_draft', 'forms_categories_id'],
            'FROM'   => 'glpi_forms_forms',
            'WHERE'  => ['is_deleted' => 0],
            'ORDER'  => 'name ASC',
        ]) as $f) {
            $cat = (int) $f['forms_categories_id'];
            if (isset($cats[$cat])) {
                $cats[$cat]['qtd']++;
            }
            $formularios[] = [
                'id'        => (int) $f['id'],
                'nome'      => (string) $f['name'],
                'ativo'     => (int) $f['is_active'],
                'rascunho'  => (int) $f['is_draft'],
                'categoria' => (string) ($caminho[$cat] ?? '(sem categoria)'),
            ];
        }

        // Achatamento em profundidade para preservar a hierarquia visual.
        $categorias = [];
        $desce = function (int $pai, int $nivel) use (&$desce, &$categorias, $cats, $filhos, $caminho): void {
            foreach ($filhos[$pai] ?? [] as $id) {
                $c            = $cats[$id];
                $c['nivel']   = $nivel;
                $c['caminho'] = (string) ($caminho[$id] ?? $c['nome']);
                $categorias[] = $c;
                $desce($id, $nivel + 1);
            }
        };
        $desce(0, 0);

        return ['categorias' => $categorias, 'formularios' => $formularios];
    }

    // -----------------------------------------------------------------
    // Geracao do pacote
    // -----------------------------------------------------------------

    /**
     * Monta o pacote completo.
     *
     * Opcoes: categorias[], formularios[], tipos_deps[], incluir_forms_cat,
     * incluir_destinos, incluir_acesso, incluir_deps.
     */
    public static function gerar(array $opcoes): array
    {
        $catIds  = array_values(array_unique(array_map('intval', $opcoes['categorias'] ?? [])));
        $formIds = array_values(array_unique(array_map('intval', $opcoes['formularios'] ?? [])));

        $comDestinos = !empty($opcoes['incluir_destinos']);
        $comAcesso   = !empty($opcoes['incluir_acesso']);
        $comDeps     = !empty($opcoes['incluir_deps']);

        // Formularios das categorias marcadas (e das subcategorias delas).
        if (!empty($opcoes['incluir_forms_cat']) && !empty($catIds)) {
            $formIds = array_values(array_unique(array_merge($formIds, self::formulariosDasCategorias($catIds))));
        }

        $categorias  = self::coletarCategorias($catIds, $formIds);
        $formularios = [];
        $refs        = [];

        foreach ($formIds as $formId) {
            $form = self::montarFormulario($formId, $comDestinos, $comAcesso, $refs);
            if ($form !== null) {
                $formularios[(string) $formId] = $form;
            }
        }

        $tiposDeps    = (array) ($opcoes['tipos_deps'] ?? array_keys(PluginCatalogoeformulariosPacote::todosTipos()));
        $dependencias = $comDeps ? self::montarDependencias($refs, $tiposDeps) : [];

        return [
            'manifest' => [
                'formato'         => PluginCatalogoeformulariosPacote::FORMATO,
                'plugin'          => 'catalogoeformularios',
                'plugin_versao'   => defined('PLUGIN_CATALOGOEFORMULARIOS_VERSION') ? PLUGIN_CATALOGOEFORMULARIOS_VERSION : '',
                'glpi_versao'     => PluginCatalogoeformulariosPacote::versaoGlpi(),
                'gerado_em'       => date('Y-m-d H:i:s'),
                'gerado_por'      => (string) ($_SESSION['glpiname'] ?? ''),
                'entidade_origem' => self::entidadeOrigem(),
                'conteudo'        => [
                    'destinos'    => $comDestinos,
                    'acesso'      => $comAcesso,
                    'dependencias' => $comDeps,
                ],
                'totais'          => [
                    'categorias'   => count($categorias),
                    'formularios'  => count($formularios),
                    'dependencias' => array_map('count', $dependencias),
                ],
            ],
            'categorias'   => $categorias,
            'formularios'  => $formularios,
            'dependencias' => $dependencias,
        ];
    }

    /** IDs de formularios das categorias informadas e de todas as descendentes. */
    private static function formulariosDasCategorias(array $catIds): array
    {
        global $DB;

        $filhos = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'forms_categories_id'],
            'FROM'   => 'glpi_forms_categories',
        ]) as $r) {
            $filhos[(int) $r['forms_categories_id']][] = (int) $r['id'];
        }

        $todas = [];
        $fila  = $catIds;
        while (!empty($fila)) {
            $id = (int) array_shift($fila);
            if ($id <= 0 || isset($todas[$id])) {
                continue;
            }
            $todas[$id] = true;
            foreach ($filhos[$id] ?? [] as $f) {
                $fila[] = $f;
            }
        }

        if (empty($todas)) {
            return [];
        }

        $ids = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_forms_forms',
            'WHERE'  => ['is_deleted' => 0, 'forms_categories_id' => array_keys($todas)],
        ]) as $f) {
            $ids[] = (int) $f['id'];
        }

        return $ids;
    }

    /** Entidade de origem (informativa no manifesto). */
    private static function entidadeOrigem(): array
    {
        global $DB;

        $id  = (int) ($_SESSION['glpiactive_entity'] ?? 0);
        $row = $DB->request([
            'SELECT' => ['completename'],
            'FROM'   => 'glpi_entities',
            'WHERE'  => ['id' => $id],
            'LIMIT'  => 1,
        ])->current();

        return ['id' => $id, 'nome' => (string) ($row['completename'] ?? '')];
    }

    // -----------------------------------------------------------------
    // Categorias do catalogo
    // -----------------------------------------------------------------

    /** Categorias escolhidas, as dos formularios escolhidos e todos os pais. */
    private static function coletarCategorias(array $catIds, array $formIds): array
    {
        global $DB;

        $necessarias = $catIds;

        if (!empty($formIds)) {
            foreach ($DB->request([
                'SELECT' => ['forms_categories_id'],
                'FROM'   => 'glpi_forms_forms',
                'WHERE'  => ['id' => $formIds],
            ]) as $r) {
                $c = (int) $r['forms_categories_id'];
                if ($c > 0) {
                    $necessarias[] = $c;
                }
            }
        }

        $necessarias = array_values(array_unique(array_filter($necessarias, static fn($v) => (int) $v > 0)));
        if (empty($necessarias)) {
            return [];
        }

        $campos = ['id', 'name', 'forms_categories_id', 'comment'];
        if ($DB->fieldExists('glpi_forms_categories', 'illustration')) {
            $campos[] = 'illustration';
        }

        $coletadas = [];
        $fila      = $necessarias;
        $visitados = [];

        while (!empty($fila)) {
            $id = (int) array_shift($fila);
            if ($id <= 0 || isset($visitados[$id])) {
                continue;
            }
            $visitados[$id] = true;

            $row = $DB->request([
                'SELECT' => $campos,
                'FROM'   => 'glpi_forms_categories',
                'WHERE'  => ['id' => $id],
                'LIMIT'  => 1,
            ])->current();

            if ($row === null) {
                continue;
            }

            $pai = (int) $row['forms_categories_id'];

            $coletadas[(string) $id] = [
                'nome'       => (string) $row['name'],
                'descricao'  => (string) ($row['comment'] ?? ''),
                'ilustracao' => (string) ($row['illustration'] ?? ''),
                'pai'        => $pai,
                'caminho'    => '',
            ];

            if ($pai > 0) {
                $fila[] = $pai;
            }
        }

        foreach ($coletadas as $id => $dados) {
            $coletadas[$id]['caminho'] = self::caminhoCategoria((int) $id, $coletadas);
        }

        return $coletadas;
    }

    /** Monta "Pai > Filho > Neto" a partir do proprio pacote. */
    private static function caminhoCategoria(int $id, array $mapa): string
    {
        $partes = [];
        $atual  = $id;
        $guarda = 0;

        while ($atual > 0 && isset($mapa[(string) $atual]) && $guarda < 30) {
            $partes[] = $mapa[(string) $atual]['nome'];
            $atual    = (int) $mapa[(string) $atual]['pai'];
            $guarda++;
        }

        return implode(' > ', array_reverse($partes));
    }

    // -----------------------------------------------------------------
    // Formularios
    // -----------------------------------------------------------------

    /** Le um formulario inteiro e acumula as referencias encontradas. */
    private static function montarFormulario(int $formId, bool $comDestinos, bool $comAcesso, array &$refs): ?array
    {
        global $DB;

        $f = $DB->request([
            'FROM'  => 'glpi_forms_forms',
            'WHERE' => ['id' => $formId, 'is_deleted' => 0],
            'LIMIT' => 1,
        ])->current();

        if ($f === null) {
            return null;
        }

        $refsForm = [];

        $saida = [
            'categoria' => (int) $f['forms_categories_id'],
            'campos'    => PluginCatalogoeformulariosPacote::limparLinha((array) $f),
            'secoes'    => [],
            'destinos'  => [],
            'acesso'    => [],
        ];

        foreach ($DB->request([
            'FROM'  => 'glpi_forms_sections',
            'WHERE' => ['forms_forms_id' => $formId],
            'ORDER' => 'rank ASC',
        ]) as $s) {
            $secao = [
                'id_origem' => (int) $s['id'],
                'campos'    => PluginCatalogoeformulariosPacote::limparLinha((array) $s),
                'perguntas' => [],
            ];

            PluginCatalogoeformulariosPacote::coletarReferencias($s['conditions'] ?? null, $refsForm);

            foreach ($DB->request([
                'FROM'  => 'glpi_forms_questions',
                'WHERE' => ['forms_sections_id' => (int) $s['id']],
                'ORDER' => 'vertical_rank ASC',
            ]) as $q) {
                $secao['perguntas'][] = [
                    'id_origem' => (int) $q['id'],
                    'campos'    => PluginCatalogoeformulariosPacote::limparLinha((array) $q),
                ];

                PluginCatalogoeformulariosPacote::coletarReferencias($q['extra_data'] ?? null, $refsForm);
                PluginCatalogoeformulariosPacote::coletarReferencias($q['conditions'] ?? null, $refsForm);
                PluginCatalogoeformulariosPacote::coletarReferencias($q['default_value'] ?? null, $refsForm);
            }

            $saida['secoes'][] = $secao;
        }

        if ($comDestinos && $DB->tableExists('glpi_forms_destinations_formdestinations')) {
            foreach ($DB->request([
                'FROM'  => 'glpi_forms_destinations_formdestinations',
                'WHERE' => ['forms_forms_id' => $formId],
                'ORDER' => 'id ASC',
            ]) as $d) {
                $saida['destinos'][] = [
                    'id_origem' => (int) $d['id'],
                    'campos'    => PluginCatalogoeformulariosPacote::limparLinha((array) $d),
                ];
                PluginCatalogoeformulariosPacote::coletarReferencias($d['config'] ?? null, $refsForm);
                PluginCatalogoeformulariosPacote::coletarReferencias($d['conditions'] ?? null, $refsForm);
            }
        }

        if ($comAcesso && $DB->tableExists('glpi_forms_accesscontrols_formaccesscontrols')) {
            foreach ($DB->request([
                'FROM'  => 'glpi_forms_accesscontrols_formaccesscontrols',
                'WHERE' => ['forms_forms_id' => $formId],
                'ORDER' => 'id ASC',
            ]) as $a) {
                $saida['acesso'][] = [
                    'id_origem' => (int) $a['id'],
                    'campos'    => PluginCatalogoeformulariosPacote::limparLinha((array) $a),
                ];
                PluginCatalogoeformulariosPacote::coletarReferencias($a['config'] ?? null, $refsForm);
            }
        }

        // Guarda no proprio formulario o que ele referencia, para a previa.
        $usa = [];
        foreach ($refsForm as $itemtype => $ids) {
            if ($itemtype === '_question' || $itemtype === '_section') {
                continue;
            }
            $usa[$itemtype] = array_values($ids);
            foreach ($ids as $id) {
                if (!isset($refs[$itemtype])) {
                    $refs[$itemtype] = [];
                }
                if (!in_array((int) $id, $refs[$itemtype], true)) {
                    $refs[$itemtype][] = (int) $id;
                }
            }
        }
        foreach (['_question', '_section'] as $pseudo) {
            if (isset($refsForm[$pseudo])) {
                $refs[$pseudo] = array_merge($refs[$pseudo] ?? [], $refsForm[$pseudo]);
            }
        }
        $saida['usa'] = $usa;

        return $saida;
    }

    // -----------------------------------------------------------------
    // Dependencias nativas
    // -----------------------------------------------------------------

    /** Le a definicao de cada referencia e sobe pais e pre-requisitos. */
    private static function montarDependencias(array $refs, array $tiposPermitidos = []): array
    {
        $tipos = PluginCatalogoeformulariosPacote::todosTipos();
        if (!empty($tiposPermitidos)) {
            $tipos = array_intersect_key($tipos, array_flip($tiposPermitidos));
        }
        $saida = [];

        unset($refs['_question'], $refs['_section']);

        $fila = [];
        foreach ($refs as $itemtype => $ids) {
            if (!isset($tipos[$itemtype])) {
                continue;
            }
            foreach ($ids as $id) {
                $fila[] = [$itemtype, (int) $id];
            }
        }

        $vistos = [];

        while (!empty($fila)) {
            [$itemtype, $id] = array_shift($fila);
            $chave = $itemtype . '#' . $id;

            if ($id <= 0 || isset($vistos[$chave]) || !isset($tipos[$itemtype])) {
                continue;
            }
            $vistos[$chave] = true;

            $def = self::definicaoItem($itemtype, $id);
            if ($def === null) {
                continue;
            }

            $saida[$itemtype][(string) $id] = $def;

            if (!empty($def['pai'])) {
                $fila[] = [$itemtype, (int) $def['pai']];
            }
            foreach ($def['requer'] ?? [] as $req) {
                $fila[] = [$req[0], (int) $req[1]];
            }
        }

        foreach ($saida as $itemtype => $itens) {
            uasort($saida[$itemtype], static function ($a, $b) {
                return strcasecmp((string) ($a['caminho'] ?? $a['nome']), (string) ($b['caminho'] ?? $b['nome']));
            });
        }

        // Ordem de processamento estavel.
        $ordenado = [];
        foreach (array_keys($tipos) as $t) {
            if (isset($saida[$t])) {
                $ordenado[$t] = $saida[$t];
            }
        }

        return $ordenado;
    }

    /** Definicao portavel de um item nativo. */
    private static function definicaoItem(string $itemtype, int $id): ?array
    {
        global $DB;

        $tabela = PluginCatalogoeformulariosPacote::tabelaTipo($itemtype);
        if ($tabela === '' || !$DB->tableExists($tabela)) {
            return null;
        }

        $row = $DB->request(['FROM' => $tabela, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();
        if ($row === null) {
            return null;
        }

        $nome    = (string) ($row['name'] ?? '');
        $caminho = (string) ($row['completename'] ?? $nome);
        $requer  = [];
        $pai     = 0;
        $campos  = [];

        switch ($itemtype) {
            case 'ITILCategory':
                $pai    = (int) ($row['itilcategories_id'] ?? 0);
                $campos = self::filtrar($row, [
                    'name', 'comment', 'code', 'is_helpdeskvisible',
                    'is_incident', 'is_request', 'is_problem', 'is_change',
                    'groups_id', 'users_id',
                ]);
                if ((int) ($row['groups_id'] ?? 0) > 0) {
                    $requer[] = ['Group', (int) $row['groups_id']];
                }
                if ((int) ($row['users_id'] ?? 0) > 0) {
                    $requer[] = ['User', (int) $row['users_id']];
                }
                break;

            case 'Group':
                $pai    = (int) ($row['groups_id'] ?? 0);
                $campos = self::filtrar($row, [
                    'name', 'comment', 'is_requester', 'is_watcher', 'is_assign',
                    'is_task', 'is_notify', 'is_manager', 'is_usergroup',
                ]);
                break;

            case 'Location':
                $pai    = (int) ($row['locations_id'] ?? 0);
                $campos = self::filtrar($row, [
                    'name', 'comment', 'code', 'alias', 'address', 'postcode',
                    'town', 'state', 'country', 'building', 'room',
                ]);
                break;

            case 'RequestType':
                $campos = self::filtrar($row, [
                    'name', 'comment', 'is_active', 'is_helpdesk_default',
                    'is_mail_default', 'is_mailfollowup_default', 'is_ticketheader',
                    'is_followup_default',
                ]);
                break;

            case 'Calendar':
                $campos               = self::filtrar($row, ['name', 'comment', 'cache_duration']);
                $campos['_segmentos'] = [];
                if ($DB->tableExists('glpi_calendarsegments')) {
                    foreach ($DB->request([
                        'SELECT' => ['day', 'begin', 'end'],
                        'FROM'   => 'glpi_calendarsegments',
                        'WHERE'  => ['calendars_id' => $id],
                        'ORDER'  => ['day ASC', 'begin ASC'],
                    ]) as $seg) {
                        $campos['_segmentos'][] = [
                            'day'   => (int) $seg['day'],
                            'begin' => (string) $seg['begin'],
                            'end'   => (string) $seg['end'],
                        ];
                    }
                }
                break;

            case 'SLM':
                $campos = self::filtrar($row, [
                    'name', 'comment', 'calendars_id', 'use_ticket_calendar',
                    'definition_time', 'number_time',
                ]);
                if ((int) ($row['calendars_id'] ?? 0) > 0) {
                    $requer[] = ['Calendar', (int) $row['calendars_id']];
                }
                break;

            case 'SLA':
            case 'OLA':
                $campos = self::filtrar($row, [
                    'name', 'comment', 'type', 'number_time', 'definition_time',
                    'end_of_working_day', 'slms_id', 'calendars_id',
                ]);
                if ((int) ($row['slms_id'] ?? 0) > 0) {
                    $requer[] = ['SLM', (int) $row['slms_id']];
                }
                if ((int) ($row['calendars_id'] ?? 0) > 0) {
                    $requer[] = ['Calendar', (int) $row['calendars_id']];
                }
                break;

            case 'Supplier':
                $campos = self::filtrar($row, ['name', 'comment', 'is_active', 'phonenumber', 'email', 'website']);
                break;

            case 'Entity':
                $pai    = (int) ($row['entities_id'] ?? 0);
                $campos = self::filtrar($row, ['name', 'comment']);
                break;

            case 'Profile':
            case 'TicketTemplate':
                $campos = self::filtrar($row, ['name', 'comment']);
                break;

            case 'User':
                $nome    = (string) ($row['name'] ?? '');
                $caminho = trim(((string) ($row['realname'] ?? '')) . ' ' . ((string) ($row['firstname'] ?? '')));
                if ($caminho === '') {
                    $caminho = $nome;
                }
                $campos = self::filtrar($row, ['name', 'realname', 'firstname']);
                break;

            default:
                $campos = self::filtrar($row, ['name', 'comment']);
                break;
        }

        return [
            'nome'    => $nome,
            'caminho' => $caminho,
            'pai'     => $pai,
            'campos'  => $campos,
            'requer'  => $requer,
        ];
    }

    /** Mantem apenas as colunas desejadas que existem na linha. */
    private static function filtrar(array $row, array $colunas): array
    {
        $out = [];
        foreach ($colunas as $c) {
            if (array_key_exists($c, $row) && $row[$c] !== null) {
                $out[$c] = $row[$c];
            }
        }
        return $out;
    }

    // -----------------------------------------------------------------
    // Resumo para a tela de revisao
    // -----------------------------------------------------------------

    /**
     * Inventario legivel do pacote: tudo que vai no arquivo, item por item.
     */
    public static function resumo(array $pacote): array
    {
        $categorias = [];
        foreach ((array) ($pacote['categorias'] ?? []) as $idOrigem => $cat) {
            $categorias[] = [
                'nome'       => (string) ($cat['nome'] ?? ''),
                'caminho'    => (string) ($cat['caminho'] ?? $cat['nome'] ?? ''),
                'ilustracao' => (string) ($cat['ilustracao'] ?? ''),
            ];
        }
        usort($categorias, static fn($a, $b) => strcasecmp($a['caminho'], $b['caminho']));

        $formularios = [];
        foreach ((array) ($pacote['formularios'] ?? []) as $form) {
            $qtdPerguntas = 0;
            foreach ((array) ($form['secoes'] ?? []) as $s) {
                $qtdPerguntas += count((array) ($s['perguntas'] ?? []));
            }

            $usa = [];
            foreach ((array) ($form['usa'] ?? []) as $itemtype => $ids) {
                $nomes = [];
                foreach ((array) $ids as $id) {
                    $def = $pacote['dependencias'][$itemtype][(string) $id] ?? null;
                    if ($def !== null) {
                        $nomes[] = (string) ($def['caminho'] ?: $def['nome']);
                    }
                }
                if (!empty($nomes)) {
                    sort($nomes);
                    $usa[] = [
                        'label' => PluginCatalogoeformulariosPacote::labelTipo((string) $itemtype),
                        'icone' => PluginCatalogoeformulariosPacote::iconeTipo((string) $itemtype),
                        'itens' => $nomes,
                    ];
                }
            }

            $catOrig = (int) ($form['categoria'] ?? 0);

            $formularios[] = [
                'nome'          => (string) ($form['campos']['name'] ?? ''),
                'categoria_txt' => (string) ($pacote['categorias'][(string) $catOrig]['caminho'] ?? '(sem categoria)'),
                'ativo'         => (int) ($form['campos']['is_active'] ?? 0),
                'rascunho'      => (int) ($form['campos']['is_draft'] ?? 0),
                'qtd_secoes'    => count((array) ($form['secoes'] ?? [])),
                'qtd_perguntas' => $qtdPerguntas,
                'qtd_destinos'  => count((array) ($form['destinos'] ?? [])),
                'qtd_acesso'    => count((array) ($form['acesso'] ?? [])),
                'usa'           => $usa,
            ];
        }
        usort($formularios, static fn($a, $b) => strcasecmp($a['nome'], $b['nome']));

        $dependencias = [];
        foreach ((array) ($pacote['dependencias'] ?? []) as $itemtype => $itens) {
            $lista = [];
            foreach ((array) $itens as $idOrigem => $def) {
                $lista[] = [
                    'id'  => (int) $idOrigem,
                    'txt' => (string) (($def['caminho'] ?? '') !== '' ? $def['caminho'] : ($def['nome'] ?? '')),
                ];
            }
            if (empty($lista)) {
                continue;
            }
            usort($lista, static fn($a, $b) => strcasecmp($a['txt'], $b['txt']));
            $dependencias[] = [
                'itemtype' => (string) $itemtype,
                'label'    => PluginCatalogoeformulariosPacote::labelTipo((string) $itemtype),
                'icone'    => PluginCatalogoeformulariosPacote::iconeTipo((string) $itemtype),
                'itens'    => $lista,
            ];
        }

        return [
            'categorias'   => $categorias,
            'formularios'  => $formularios,
            'dependencias' => $dependencias,
        ];
    }

    /**
     * Remove do pacote as dependencias que o usuario desmarcou na revisao.
     * $manter: [itemtype => int[] ids de origem a preservar]
     */
    public static function filtrarDependencias(array $pacote, array $manter): array
    {
        $deps  = (array) ($pacote['dependencias'] ?? []);
        $saida = [];

        foreach ($deps as $itemtype => $itens) {
            $ids = array_map('intval', (array) ($manter[(string) $itemtype] ?? []));
            if (empty($ids)) {
                continue;
            }
            $bloco = [];
            foreach ((array) $itens as $idOrigem => $def) {
                if (in_array((int) $idOrigem, $ids, true)) {
                    $bloco[(string) $idOrigem] = $def;
                }
            }
            if (!empty($bloco)) {
                $saida[(string) $itemtype] = $bloco;
            }
        }

        $pacote['dependencias']                 = $saida;
        $pacote['manifest']['totais']['dependencias'] = array_map('count', $saida);

        return $pacote;
    }

    /** Serializa o pacote em JSON legivel. */
    public static function paraJson(array $pacote): string
    {
        $json = json_encode($pacote, PluginCatalogoeformulariosPacote::flagsJson());
        return $json === false ? '{}' : $json;
    }
}
