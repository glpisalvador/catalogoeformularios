<?php

/**
 * Painel rapido de controle de um formulario do catalogo.
 *
 * Reune, numa unica leitura, tudo o que define o formulario e o chamado que ele
 * vai gerar (destino de Ticket nativo do GLPI 11): categoria ITIL, SLAs, OLAs,
 * tipo, urgencia, status, origem, localizacao, modelo, entidade, atores
 * (requerente / observador / atribuido) e o controle de acesso (quem visualiza).
 *
 * Nao cria tabela propria: tudo e lido e gravado nas tabelas nativas
 * glpi_forms_* atraves das classes facilitadoras do plugin.
 */
class PluginCatalogoeformulariosPainel extends CommonGLPI
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

    /** Campos escalares do destino exibidos no painel, na ordem de exibicao. */
    private const CAMPOS = [
        'categoria', 'tipo', 'urgencia', 'status', 'origem',
        'sla_tto', 'sla_ttr', 'ola_tto', 'ola_ttr',
        'localizacao', 'template', 'entidade',
    ];

    /** Campos destacados no cabecalho do painel (visao rapida do chamado). */
    private const DESTAQUES = ['categoria', 'sla_tto', 'sla_ttr'];

    /** Cache por requisicao dos mapas valor => rotulo de cada fonte. */
    private static array $cacheFonte = [];

    // -----------------------------------------------------------------
    // Leitura
    // -----------------------------------------------------------------

    /** Resumo completo do formulario para o painel rapido. */
    public static function getResumo(int $formId): ?array
    {
        global $DB, $CFG_GLPI;

        if ($formId <= 0) {
            return null;
        }

        $f = $DB->request([
            'FROM'  => 'glpi_forms_forms',
            'WHERE' => ['id' => $formId, 'is_deleted' => 0],
            'LIMIT' => 1,
        ])->current();

        if ($f === null) {
            return null;
        }

        $destinos  = PluginCatalogoeformulariosDestino::listar($formId);
        $destinoId = PluginCatalogoeformulariosDestino::getDestinoTicketPadrao($formId);
        if ($destinoId <= 0 && !empty($destinos)) {
            $destinoId = (int) ($destinos[0]['id'] ?? 0);
        }

        $destinoNome  = '';
        $destinoTipo  = '';
        foreach ($destinos as $d) {
            if ((int) ($d['id'] ?? 0) === $destinoId) {
                $destinoNome = (string) ($d['nome'] ?? '');
                $destinoTipo = (string) ($d['tipo_label'] ?? '');
            }
        }

        return [
            'form'     => self::dadosFormulario($f),
            'destino'  => [
                'id'         => $destinoId,
                'nome'       => $destinoNome,
                'tipo_label' => $destinoTipo,
                'total'      => count($destinos),
            ],
            'destinos'  => $destinos,
            'campos'    => self::camposDoDestino($destinoId),
            'destaques' => self::DESTAQUES,
            'atores'    => self::atoresDoDestino($destinoId),
            'acesso'    => self::acessoDoFormulario($formId),
            'perguntas' => self::perguntasDoFormulario($formId),
            'url_nativo' => $CFG_GLPI['root_doc'] . '/front/form/form.php?id=' . $formId,
        ];
    }

    /** Perguntas do formulario (para a estrategia "resposta de uma pergunta"). */
    private static function perguntasDoFormulario(int $formId): array
    {
        global $DB;

        $secIds = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_forms_sections',
            'WHERE'  => ['forms_forms_id' => $formId],
            'ORDER'  => 'rank ASC',
        ]) as $s) {
            $secIds[] = (int) $s['id'];
        }
        if (empty($secIds)) {
            return [];
        }

        $saida = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => 'glpi_forms_questions',
            'WHERE'  => ['forms_sections_id' => $secIds],
            'ORDER'  => 'vertical_rank ASC',
        ]) as $q) {
            $saida[] = ['v' => (int) $q['id'], 't' => (string) $q['name']];
        }
        return $saida;
    }

    /** Dados basicos do formulario + contagens de estrutura. */
    private static function dadosFormulario(array $f): array
    {
        global $DB;

        $formId    = (int) $f['id'];
        $qtdSecoes = 0;
        $secIds    = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_forms_sections',
            'WHERE'  => ['forms_forms_id' => $formId],
        ]) as $s) {
            $secIds[] = (int) $s['id'];
            $qtdSecoes++;
        }

        $qtdPerguntas = 0;
        if (!empty($secIds)) {
            $c = $DB->request([
                'COUNT' => 'total',
                'FROM'  => 'glpi_forms_questions',
                'WHERE' => ['forms_sections_id' => $secIds],
            ])->current();
            $qtdPerguntas = (int) ($c['total'] ?? 0);
        }

        $catId  = (int) ($f['forms_categories_id'] ?? 0);
        $catNom = '';
        if ($catId > 0) {
            $c = $DB->request([
                'SELECT' => ['completename', 'name'],
                'FROM'   => 'glpi_forms_categories',
                'WHERE'  => ['id' => $catId],
                'LIMIT'  => 1,
            ])->current();
            $catNom = (string) ($c['completename'] ?? ($c['name'] ?? ''));
        }

        return [
            'id'            => $formId,
            'nome'          => (string) $f['name'],
            'ativo'         => (int) $f['is_active'] === 1,
            'rascunho'      => (int) ($f['is_draft'] ?? 0) === 1,
            'recursivo'     => (int) ($f['is_recursive'] ?? 0) === 1,
            'usos'          => (int) ($f['usage_count'] ?? 0),
            'fixado'        => (int) ($f['is_pinned'] ?? 0) === 1,
            'tem_fixado'    => array_key_exists('is_pinned', $f),
            'layout'        => (string) ($f['render_layout'] ?? 'step_by_step'),
            'tem_layout'    => array_key_exists('render_layout', $f),
            'categoria'     => $catId,
            'categoria_nome' => $catNom !== '' ? $catNom : '(Raiz do catalogo)',
            'entidade'      => (int) ($f['entities_id'] ?? 0),
            'entidade_nome' => Dropdown::getDropdownName('glpi_entities', (int) ($f['entities_id'] ?? 0)),
            'qtd_secoes'    => $qtdSecoes,
            'qtd_perguntas' => $qtdPerguntas,
        ];
    }

    /** Estado atual de cada campo escalar do destino, com o rotulo do valor. */
    private static function camposDoDestino(int $destinoId): array
    {
        $estado = $destinoId > 0
            ? PluginCatalogoeformulariosDestino::estadoCampos($destinoId)
            : [];

        $saida = [];
        foreach (self::CAMPOS as $slug) {
            $def = PluginCatalogoeformulariosDestino::definicaoCampo($slug);
            if ($def === null) {
                continue;
            }

            $atual = $estado[$slug] ?? [];
            $estr  = (string) ($atual['estrategia'] ?? 'modelo');
            $valor = $atual['valor'] ?? null;
            if (is_array($valor)) {
                $valor = null;
            }
            $valor    = (int) $valor;
            $pergunta = (int) ($atual['question'] ?? 0);

            $saida[] = [
                'slug'           => $slug,
                'label'          => $def['label'],
                'fonte'          => $def['fonte'],
                'suporte'        => $def['suporte'],
                'estrategia'     => $estr,
                'valor'          => $valor,
                'valor_label'    => $valor > 0 ? self::rotuloFonte($def['fonte'], $valor) : '',
                'question'       => $pergunta,
                'question_label' => $pergunta > 0 ? self::rotuloPergunta($pergunta) : '',
            ];
        }
        return $saida;
    }

    /** Atores configurados no destino, com nomes resolvidos. */
    private static function atoresDoDestino(int $destinoId): array
    {
        $mapa  = PluginCatalogoeformulariosDestino::mapaAtores();
        $atual = $destinoId > 0 ? PluginCatalogoeformulariosDestino::lerAtores($destinoId) : [];

        $saida = [];
        foreach ($mapa as $slug => $def) {
            $bloco = $atual[$slug] ?? ['usuarios' => [], 'grupos' => [], 'perguntas' => []];
            $saida[] = [
                'slug'          => $slug,
                'label'         => $def['label'],
                'icone'         => $def['icone'],
                'suporte'       => (bool) ($bloco['suporte'] ?? true),
                'usuarios'      => self::nomesUsuarios($bloco['usuarios'] ?? []),
                'grupos'        => self::nomesGrupos($bloco['grupos'] ?? []),
                'qtd_perguntas' => count($bloco['perguntas'] ?? []),
            ];
        }
        return $saida;
    }

    /** Controle de acesso do formulario com os nomes de perfis / grupos / usuarios. */
    private static function acessoDoFormulario(int $formId): array
    {
        $est   = PluginCatalogoeformulariosAcesso::getEstado($formId);
        $lista = $est['lista'] ?? [];

        return [
            'ativo'    => !empty($lista['ativo']),
            'todos'    => !empty($lista['todos']),
            'perfis'   => self::nomesPerfis($lista['perfis'] ?? []),
            'grupos'   => self::nomesGrupos($lista['grupos'] ?? []),
            'usuarios' => self::nomesUsuarios($lista['usuarios'] ?? []),
            'direto'   => [
                'ativo'                 => !empty($est['direto']['ativo']),
                'allow_unauthenticated' => !empty($est['direto']['allow_unauthenticated']),
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Gravacao
    // -----------------------------------------------------------------

    /** Define um campo escalar do destino de chamado do formulario. */
    public static function salvarCampo(
        int $formId,
        string $slug,
        string $estrategia,
        int $valor = 0,
        int $pergunta = 0,
        int $destinoId = 0
    ): array {
        if (!in_array($slug, self::CAMPOS, true)) {
            return ['ok' => false, 'msg' => 'Campo nao disponivel no painel.'];
        }

        $destinoId = self::resolverDestino($formId, $destinoId);
        if ($destinoId <= 0) {
            return ['ok' => false, 'msg' => 'Este formulario nao possui destino de chamado. Crie um destino primeiro.'];
        }

        // "Especifico" sem valor equivale a voltar o campo ao padrao nativo.
        if ($estrategia === 'especifico' && $valor <= 0) {
            $estrategia = 'limpar';
        }
        if ($estrategia === 'resposta' && $pergunta <= 0) {
            return ['ok' => false, 'msg' => 'Escolha a pergunta que define o valor deste campo.'];
        }

        $valorEnviado = ($estrategia === 'especifico') ? $valor : null;

        return PluginCatalogoeformulariosDestino::definirCampo(
            $destinoId,
            $slug,
            $estrategia,
            $valorEnviado,
            $pergunta
        );
    }

    /** Define usuarios e grupos de um ator (requerente / observador / atribuido). */
    public static function salvarAtores(
        int $formId,
        string $slug,
        array $usuarios,
        array $grupos,
        int $destinoId = 0
    ): array {
        $destinoId = self::resolverDestino($formId, $destinoId);
        if ($destinoId <= 0) {
            return ['ok' => false, 'msg' => 'Este formulario nao possui destino de chamado. Crie um destino primeiro.'];
        }
        return PluginCatalogoeformulariosDestino::salvarAtores($destinoId, $slug, $usuarios, $grupos);
    }

    /** Salva a lista de quem visualiza o formulario (perfis / grupos / usuarios). */
    public static function salvarAcesso(int $formId, array $d): array
    {
        if ($formId <= 0) {
            return ['ok' => false, 'msg' => 'Formulario invalido.'];
        }

        $todos    = !empty($d['todos']);
        $perfis   = array_values(array_filter(array_map('intval', $d['perfis'] ?? [])));
        $grupos   = array_values(array_filter(array_map('intval', $d['grupos'] ?? [])));
        $usuarios = array_values(array_filter(array_map('intval', $d['usuarios'] ?? [])));

        $temAlgo = $todos || !empty($perfis) || !empty($grupos) || !empty($usuarios);
        $ativo   = array_key_exists('ativo', $d) ? !empty($d['ativo']) : $temAlgo;

        if ($ativo && !$temAlgo) {
            return ['ok' => false, 'msg' => 'Selecione ao menos um perfil, grupo ou usuario (ou marque "todos").'];
        }

        return PluginCatalogoeformulariosAcesso::salvarAllowList($formId, [
            'todos'    => $todos,
            'perfis'   => $perfis,
            'grupos'   => $grupos,
            'usuarios' => $usuarios,
            'ativo'    => $ativo,
        ]);
    }

    /**
     * Aplica um mesmo valor de campo em varios formularios de uma vez
     * (edicao em lote a partir da matriz do catalogo).
     */
    public static function salvarCampoEmLote(array $formIds, string $slug, string $estrategia, int $valor = 0): array
    {
        $ids = array_values(array_filter(array_map('intval', $formIds)));
        if (empty($ids)) {
            return ['ok' => false, 'msg' => 'Nenhum formulario selecionado.'];
        }

        $ok = 0;
        $erro = 0;
        $ultimaMsg = '';
        foreach ($ids as $id) {
            $r = self::salvarCampo($id, $slug, $estrategia, $valor);
            if (!empty($r['ok'])) {
                $ok++;
            } else {
                $erro++;
                $ultimaMsg = (string) ($r['msg'] ?? '');
            }
        }

        $msg = $ok . ' formulario(s) atualizado(s)';
        if ($erro > 0) {
            $msg .= ', ' . $erro . ' com falha' . ($ultimaMsg !== '' ? ' (' . $ultimaMsg . ')' : '');
        }
        return ['ok' => $ok > 0, 'msg' => $msg . '.'];
    }

    /**
     * Matriz do catalogo: uma linha por formulario abaixo de uma categoria
     * (recursivo), com os campos principais do chamado gerado. Usada para ver e
     * comparar tudo numa unica tela.
     */
    public static function getMatriz(int $categoriaId, int $limite = 300): array
    {
        global $DB;

        $cats = self::categoriasRecursivas($categoriaId);
        $cats[] = $categoriaId;

        $linhas = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'is_active', 'is_draft', 'forms_categories_id', 'usage_count'],
            'FROM'   => 'glpi_forms_forms',
            'WHERE'  => ['forms_categories_id' => array_values(array_unique($cats)), 'is_deleted' => 0],
            'ORDER'  => 'name ASC',
            'LIMIT'  => $limite,
        ]) as $row) {
            $formId    = (int) $row['id'];
            $destinoId = PluginCatalogoeformulariosDestino::getDestinoTicketPadrao($formId);
            $estado    = $destinoId > 0 ? PluginCatalogoeformulariosDestino::estadoCampos($destinoId) : [];
            $atores    = $destinoId > 0 ? PluginCatalogoeformulariosDestino::lerAtores($destinoId) : [];
            $acesso    = PluginCatalogoeformulariosAcesso::resumoPerfis($formId);

            $catNome = '';
            if ((int) $row['forms_categories_id'] > 0) {
                $c = $DB->request([
                    'SELECT' => ['name'],
                    'FROM'   => 'glpi_forms_categories',
                    'WHERE'  => ['id' => (int) $row['forms_categories_id']],
                    'LIMIT'  => 1,
                ])->current();
                $catNome = (string) ($c['name'] ?? '');
            }

            $linhas[] = [
                'id'         => $formId,
                'nome'       => (string) $row['name'],
                'ativo'      => (int) $row['is_active'] === 1,
                'rascunho'   => (int) ($row['is_draft'] ?? 0) === 1,
                'usos'       => (int) ($row['usage_count'] ?? 0),
                'categoria'  => $catNome !== '' ? $catNome : '(Raiz)',
                'itil'       => self::rotuloCampoMatriz($estado, 'categoria', 'itilcategorias'),
                'sla_tto'    => self::rotuloCampoMatriz($estado, 'sla_tto', 'slas'),
                'sla_ttr'    => self::rotuloCampoMatriz($estado, 'sla_ttr', 'slas'),
                'requerente' => self::resumoAtorMatriz($atores, 'requerente'),
                'observador' => self::resumoAtorMatriz($atores, 'observador'),
                'atribuido'  => self::resumoAtorMatriz($atores, 'atribuido'),
                'perfis'     => $acesso,
                'sem_destino' => $destinoId <= 0,
            ];
        }

        return ['linhas' => $linhas, 'total' => count($linhas)];
    }

    // -----------------------------------------------------------------
    // Internos
    // -----------------------------------------------------------------

    /** Ids de todas as categorias abaixo de uma categoria (qualquer profundidade). */
    private static function categoriasRecursivas(int $categoriaId, int $nivel = 0): array
    {
        global $DB;

        if ($nivel > 20) {
            return [];
        }
        $saida = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_forms_categories',
            'WHERE'  => ['forms_categories_id' => $categoriaId],
        ]) as $r) {
            $id      = (int) $r['id'];
            $saida[] = $id;
            $saida   = array_merge($saida, self::categoriasRecursivas($id, $nivel + 1));
        }
        return $saida;
    }

    /** Rotulo curto de um campo para a matriz. */
    private static function rotuloCampoMatriz(array $estado, string $slug, string $fonte): string
    {
        $atual = $estado[$slug] ?? null;
        if (!is_array($atual)) {
            return '';
        }
        $estr = (string) ($atual['estrategia'] ?? '');
        if ($estr === 'resposta') {
            return 'Resposta do formulario';
        }
        $valor = $atual['valor'] ?? null;
        if (is_array($valor) || (int) $valor <= 0) {
            return '';
        }
        return self::rotuloFonte($fonte, (int) $valor);
    }

    /** Resumo textual de um ator para a matriz. */
    private static function resumoAtorMatriz(array $atores, string $slug): string
    {
        $bloco = $atores[$slug] ?? null;
        if (!is_array($bloco)) {
            return '';
        }
        $partes = [];
        foreach (self::nomesUsuarios($bloco['usuarios'] ?? []) as $u) {
            $partes[] = (string) $u['t'];
        }
        foreach (self::nomesGrupos($bloco['grupos'] ?? []) as $g) {
            $partes[] = (string) $g['t'];
        }
        return implode(', ', $partes);
    }

    /** Destino informado ou o destino de Ticket padrao do formulario. */
    private static function resolverDestino(int $formId, int $destinoId): int
    {
        if ($destinoId > 0) {
            return $destinoId;
        }
        return PluginCatalogoeformulariosDestino::getDestinoTicketPadrao($formId);
    }

    /** Mapa valor => rotulo de uma fonte nativa (cacheado por requisicao). */
    private static function fonteMapa(string $fonte): array
    {
        if (isset(self::$cacheFonte[$fonte])) {
            return self::$cacheFonte[$fonte];
        }

        $mapa  = [];
        $lista = PluginCatalogoeformulariosFonte::getFonte($fonte);
        if (is_array($lista)) {
            foreach ($lista as $item) {
                if (is_array($item) && isset($item['v'])) {
                    $mapa[(string) $item['v']] = (string) ($item['t'] ?? '');
                }
            }
        }
        self::$cacheFonte[$fonte] = $mapa;
        return $mapa;
    }

    private static function rotuloFonte(string $fonte, int $valor): string
    {
        $mapa = self::fonteMapa($fonte);
        return $mapa[(string) $valor] ?? ('#' . $valor);
    }

    private static function rotuloPergunta(int $perguntaId): string
    {
        global $DB;

        $r = $DB->request([
            'SELECT' => ['name'],
            'FROM'   => 'glpi_forms_questions',
            'WHERE'  => ['id' => $perguntaId],
            'LIMIT'  => 1,
        ])->current();

        return $r !== null ? (string) $r['name'] : ('Pergunta #' . $perguntaId);
    }

    /** Nomes de usuarios na ordem nome + sobrenome. */
    private static function nomesUsuarios(array $ids): array
    {
        global $DB;

        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }

        $saida = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'firstname', 'realname'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['id' => $ids],
            'ORDER'  => 'firstname ASC',
        ]) as $r) {
            $completo = trim(($r['firstname'] ?? '') . ' ' . ($r['realname'] ?? ''));
            $saida[]  = [
                'v' => (int) $r['id'],
                't' => $completo !== '' ? $completo : (string) $r['name'],
            ];
        }
        return $saida;
    }

    private static function nomesGrupos(array $ids): array
    {
        global $DB;

        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }

        $saida = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'completename', 'name'],
            'FROM'   => 'glpi_groups',
            'WHERE'  => ['id' => $ids],
            'ORDER'  => 'completename ASC',
        ]) as $r) {
            $saida[] = [
                'v' => (int) $r['id'],
                't' => (string) ($r['completename'] ?: $r['name']),
            ];
        }
        return $saida;
    }

    private static function nomesPerfis(array $ids): array
    {
        global $DB;

        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }

        $saida = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => 'glpi_profiles',
            'WHERE'  => ['id' => $ids],
            'ORDER'  => 'name ASC',
        ]) as $r) {
            $saida[] = ['v' => (int) $r['id'], 't' => (string) $r['name']];
        }
        return $saida;
    }
}
