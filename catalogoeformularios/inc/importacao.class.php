<?php

use Glpi\Form\Form;
use Glpi\Form\Category;
use Glpi\Form\Section;
use Glpi\Form\Question;

/**
 * Importacao de um pacote .json do catalogo de servicos.
 *
 * Fase 1 - analisar(): le o arquivo e monta a previa, dizendo item por item
 *          se ja existe no destino e qual acao vem sugerida.
 * Fase 2 - executar(): aplica a acao escolhida pelo usuario em cada item, na
 *          ordem dependencias -> categorias -> formularios.
 */
class PluginCatalogoeformulariosImportacao extends CommonGLPI
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

    /** Teto de candidatos carregados nos seletores de "apontar para". */
    private const LIMITE_CANDIDATOS = 3000;

    // -----------------------------------------------------------------
    // Fase 1: analise
    // -----------------------------------------------------------------

    public static function analisar(string $conteudo): array
    {
        $pacote = json_decode($conteudo, true);

        if (!is_array($pacote)) {
            return ['ok' => false, 'msg' => 'Arquivo invalido: nao e um JSON valido.'];
        }
        if (!isset($pacote['manifest']) || !is_array($pacote['manifest'])) {
            return ['ok' => false, 'msg' => 'Arquivo invalido: manifesto ausente.'];
        }
        // Pacotes exportados pela versão antiga (catalogodeservicos) têm o mesmo formato
        if (!in_array((string) ($pacote['manifest']['plugin'] ?? ''), ['catalogoeformularios', 'catalogodeservicos'], true)) {
            return ['ok' => false, 'msg' => 'Este arquivo nao foi gerado pelo Catálogo e Formulários (ou pelo antigo Catálogo de Serviços).'];
        }

        $avisos  = [];
        $formato = (int) ($pacote['manifest']['formato'] ?? 0);

        if ($formato > PluginCatalogoeformulariosPacote::FORMATO) {
            return ['ok' => false, 'msg' => 'O arquivo foi gerado por uma versao mais nova do plugin. Atualize o plugin antes de importar.'];
        }
        if ($formato < PluginCatalogoeformulariosPacote::FORMATO) {
            $avisos[] = 'O arquivo usa um formato mais antigo (v' . $formato . '). A importacao vai prosseguir, mas alguns detalhes da previa podem faltar.';
        }

        $glpiOrigem = (string) ($pacote['manifest']['glpi_versao'] ?? '');
        $glpiLocal  = PluginCatalogoeformulariosPacote::versaoGlpi();
        if ($glpiOrigem !== '' && $glpiLocal !== '' && $glpiOrigem !== $glpiLocal) {
            $avisos[] = 'O pacote veio do GLPI ' . $glpiOrigem . ' e este e o ' . $glpiLocal
                . '. Formularios criados em versoes diferentes podem ter campos novos ou renomeados; revise os destinos apos importar.';
        }

        // ---- Categorias do catalogo -------------------------------------
        $categorias = [];
        foreach ((array) ($pacote['categorias'] ?? []) as $idOrigem => $cat) {
            $caminho     = (string) ($cat['caminho'] ?? $cat['nome'] ?? '');
            $existenteId = self::acharCategoriaPorCaminho($caminho);

            $categorias[] = [
                'id_origem'  => (int) $idOrigem,
                'nome'       => (string) ($cat['nome'] ?? ''),
                'caminho'    => $caminho,
                'pai'        => (int) ($cat['pai'] ?? 0),
                'ilustracao' => (string) ($cat['ilustracao'] ?? ''),
                'existe'     => $existenteId > 0,
                'existe_id'  => $existenteId,
                'existe_txt' => $existenteId > 0 ? self::rotuloExistente('__categoria__', $existenteId) : '',
                'acao'       => $existenteId > 0 ? 'reutilizar' : 'novo',
                'nome_novo'  => $existenteId > 0
                    ? self::sugerirNome('__categoria__', (string) ($cat['nome'] ?? ''))
                    : (string) ($cat['nome'] ?? ''),
            ];
        }
        usort($categorias, static fn($a, $b) => strcasecmp($a['caminho'], $b['caminho']));

        // ---- Formularios -------------------------------------------------
        $formularios = [];
        foreach ((array) ($pacote['formularios'] ?? []) as $idOrigem => $form) {
            $campos  = (array) ($form['campos'] ?? []);
            $nome    = (string) ($campos['name'] ?? '');
            $catOrig = (int) ($form['categoria'] ?? 0);

            $qtdPerguntas = 0;
            foreach ((array) ($form['secoes'] ?? []) as $s) {
                $qtdPerguntas += count((array) ($s['perguntas'] ?? []));
            }

            $existenteId = self::acharFormularioPorNome($nome);

            $usa = [];
            foreach ((array) ($form['usa'] ?? []) as $itemtype => $ids) {
                $nomes = [];
                foreach ((array) $ids as $id) {
                    $def = $pacote['dependencias'][$itemtype][(string) $id] ?? null;
                    if ($def !== null) {
                        $nomes[] = (string) (($def['caminho'] ?? '') !== '' ? $def['caminho'] : ($def['nome'] ?? ''));
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

            $formularios[] = [
                'id_origem'     => (int) $idOrigem,
                'nome'          => $nome,
                'categoria'     => $catOrig,
                'categoria_txt' => (string) ($pacote['categorias'][(string) $catOrig]['caminho'] ?? '(sem categoria)'),
                'ativo'         => (int) ($campos['is_active'] ?? 0),
                'rascunho'      => (int) ($campos['is_draft'] ?? 0),
                'qtd_secoes'    => count((array) ($form['secoes'] ?? [])),
                'qtd_perguntas' => $qtdPerguntas,
                'qtd_destinos'  => count((array) ($form['destinos'] ?? [])),
                'qtd_acesso'    => count((array) ($form['acesso'] ?? [])),
                'usa'           => $usa,
                'existe'        => $existenteId > 0,
                'existe_id'     => $existenteId,
                'existe_txt'    => $existenteId > 0 ? self::rotuloExistente('__formulario__', $existenteId) : '',
                'acao'          => 'novo',
                'nome_novo'     => $existenteId > 0 ? self::sugerirNome('__formulario__', $nome) : $nome,
            ];
        }
        usort($formularios, static fn($a, $b) => strcasecmp($a['nome'], $b['nome']));

        // ---- Dependencias nativas ----------------------------------------
        $dependencias = [];

        foreach ((array) ($pacote['dependencias'] ?? []) as $itemtype => $itens) {
            $itemtype = (string) $itemtype;
            if (PluginCatalogoeformulariosPacote::tabelaTipo($itemtype) === '') {
                continue;
            }

            $criavel = PluginCatalogoeformulariosPacote::ehCriavel($itemtype);
            $lista   = [];

            foreach ((array) $itens as $idOrigem => $def) {
                $def         = (array) $def;
                $existenteId = self::acharDependencia($itemtype, $def, (array) $itens);
                $nome        = (string) ($def['nome'] ?? '');
                $caminho     = (string) (($def['caminho'] ?? '') !== '' ? $def['caminho'] : $nome);

                if ($existenteId > 0) {
                    $acao = 'reutilizar';
                } elseif ($criavel) {
                    $acao = 'novo';
                } else {
                    $acao = 'apontar';
                }

                $lista[] = [
                    'id_origem'  => (int) $idOrigem,
                    'nome'       => $nome,
                    'caminho'    => $caminho,
                    'existe'     => $existenteId > 0,
                    'existe_id'  => $existenteId,
                    'existe_txt' => $existenteId > 0 ? self::rotuloExistente($itemtype, $existenteId) : '',
                    'acao'       => $acao,
                    'nome_novo'  => $existenteId > 0 ? self::sugerirNome($itemtype, $nome) : $nome,
                ];
            }

            if (empty($lista)) {
                continue;
            }

            $dependencias[] = [
                'itemtype' => $itemtype,
                'label'    => PluginCatalogoeformulariosPacote::labelTipo($itemtype),
                'icone'    => PluginCatalogoeformulariosPacote::iconeTipo($itemtype),
                'criavel'  => $criavel,
                'acoes'    => PluginCatalogoeformulariosPacote::acoesDoTipo($itemtype),
                'itens'    => $lista,
            ];
        }

        foreach ($dependencias as $bloco) {
            if ($bloco['criavel']) {
                continue;
            }
            $faltando = array_filter($bloco['itens'], static fn($i) => empty($i['existe']));
            if (!empty($faltando)) {
                $avisos[] = $bloco['label'] . ': ' . count($faltando)
                    . ' item(ns) nao existem aqui e nao podem ser criados a partir do pacote. '
                    . 'Aponte manualmente para um item deste GLPI ou as referencias ficarao vazias.';
            }
        }

        return [
            'ok'           => true,
            'msg'          => '',
            'avisos'       => $avisos,
            'manifest'     => (array) $pacote['manifest'],
            'categorias'   => $categorias,
            'formularios'  => $formularios,
            'dependencias' => $dependencias,
        ];
    }

    // -----------------------------------------------------------------
    // Candidatos para os seletores de "apontar para"
    // -----------------------------------------------------------------

    /**
     * Lista os itens do destino que podem ser escolhidos manualmente.
     * Aceita os pseudo-tipos __categoria__ e __formulario__.
     */
    public static function candidatos(string $itemtype): array
    {
        global $DB;

        if ($itemtype === '__categoria__') {
            $tabela = 'glpi_forms_categories';
        } elseif ($itemtype === '__formulario__') {
            $tabela = 'glpi_forms_forms';
        } else {
            $tabela = PluginCatalogoeformulariosPacote::tabelaTipo($itemtype);
        }

        if ($tabela === '' || !$DB->tableExists($tabela)) {
            return [];
        }

        $temCompleto = $DB->fieldExists($tabela, 'completename');
        $select      = ['id', 'name'];
        if ($temCompleto) {
            $select[] = 'completename';
        }
        if ($itemtype === 'User') {
            $select[] = 'realname';
            $select[] = 'firstname';
        }

        $where = [];
        if ($DB->fieldExists($tabela, 'is_deleted')) {
            $where['is_deleted'] = 0;
        }
        if ($itemtype === 'User' && $DB->fieldExists($tabela, 'is_active')) {
            $where['is_active'] = 1;
        }

        $saida = [];
        foreach ($DB->request([
            'SELECT' => $select,
            'FROM'   => $tabela,
            'WHERE'  => $where,
            'ORDER'  => ($temCompleto ? 'completename' : 'name') . ' ASC',
            'LIMIT'  => self::LIMITE_CANDIDATOS,
        ]) as $r) {
            $txt = (string) ($r['completename'] ?? $r['name'] ?? '');
            if ($itemtype === 'User') {
                $pessoa = trim(((string) ($r['realname'] ?? '')) . ' ' . ((string) ($r['firstname'] ?? '')));
                if ($pessoa !== '') {
                    $txt = $pessoa . ' (' . (string) $r['name'] . ')';
                }
            }
            if ($txt === '') {
                continue;
            }
            $saida[] = ['id' => (int) $r['id'], 'txt' => $txt];
        }

        // Categorias do catalogo nao tem completename: monta o caminho na mao.
        if ($itemtype === '__categoria__') {
            $saida = self::caminhosCategorias();
        }

        return $saida;
    }

    /** Categorias do catalogo com o caminho "Pai > Filho" (memorizado). */
    private static function caminhosCategorias(): array
    {
        global $DB;

        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $nome = [];
        $pai  = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'forms_categories_id'],
            'FROM'   => 'glpi_forms_categories',
            'LIMIT'  => self::LIMITE_CANDIDATOS,
        ]) as $r) {
            $id        = (int) $r['id'];
            $nome[$id] = (string) $r['name'];
            $pai[$id]  = (int) $r['forms_categories_id'];
        }

        $saida = [];
        foreach ($nome as $id => $n) {
            $partes = [];
            $atual  = $id;
            $guarda = 0;
            while ($atual > 0 && isset($nome[$atual]) && $guarda < 30) {
                $partes[] = $nome[$atual];
                $atual    = $pai[$atual] ?? 0;
                $guarda++;
            }
            $saida[] = ['id' => $id, 'txt' => implode(' > ', array_reverse($partes))];
        }

        usort($saida, static fn($a, $b) => strcasecmp($a['txt'], $b['txt']));

        $cache = $saida;

        return $saida;
    }

    /** Rotulo de um item existente no destino. */
    private static function rotuloExistente(string $itemtype, int $id): string
    {
        global $DB;

        if ($id <= 0) {
            return '';
        }

        if ($itemtype === '__categoria__') {
            foreach (self::caminhosCategorias() as $c) {
                if ($c['id'] === $id) {
                    return $c['txt'];
                }
            }
            return '';
        }

        $tabela = $itemtype === '__formulario__'
            ? 'glpi_forms_forms'
            : PluginCatalogoeformulariosPacote::tabelaTipo($itemtype);

        if ($tabela === '' || !$DB->tableExists($tabela)) {
            return '';
        }

        $select = ['name'];
        if ($DB->fieldExists($tabela, 'completename')) {
            $select[] = 'completename';
        }

        $row = $DB->request(['SELECT' => $select, 'FROM' => $tabela, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();
        if ($row === null) {
            return '';
        }

        return (string) ($row['completename'] ?? $row['name'] ?? '');
    }

    /** Nome livre para "criar novo" quando ja existe um homonimo. */
    private static function sugerirNome(string $itemtype, string $nome): string
    {
        $nome = trim($nome);
        if ($nome === '') {
            return '';
        }

        for ($i = 2; $i <= 50; $i++) {
            $tentativa = $nome . ' (' . $i . ')';
            if (!self::nomeEmUso($itemtype, $tentativa)) {
                return $tentativa;
            }
        }

        return $nome . ' (' . date('Ymd-His') . ')';
    }

    /** Existe algum item deste tipo com esse nome exato? */
    private static function nomeEmUso(string $itemtype, string $nome): bool
    {
        global $DB;

        $tabela = $itemtype === '__categoria__'
            ? 'glpi_forms_categories'
            : ($itemtype === '__formulario__' ? 'glpi_forms_forms' : PluginCatalogoeformulariosPacote::tabelaTipo($itemtype));

        if ($tabela === '' || !$DB->tableExists($tabela)) {
            return false;
        }

        $where = ['name' => $nome];
        if ($DB->fieldExists($tabela, 'is_deleted')) {
            $where['is_deleted'] = 0;
        }

        $row = $DB->request(['SELECT' => ['id'], 'FROM' => $tabela, 'WHERE' => $where, 'LIMIT' => 1])->current();

        return $row !== null;
    }

    // -----------------------------------------------------------------
    // Busca de itens existentes no destino
    // -----------------------------------------------------------------

    /** Procura uma categoria do catalogo pelo caminho "Pai > Filho". */
    public static function acharCategoriaPorCaminho(string $caminho): int
    {
        global $DB;

        $caminho = trim($caminho);
        if ($caminho === '') {
            return 0;
        }

        $partes = array_map('trim', explode('>', $caminho));
        $paiId  = 0;

        foreach ($partes as $nome) {
            if ($nome === '') {
                continue;
            }
            $row = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_forms_categories',
                'WHERE'  => ['name' => $nome, 'forms_categories_id' => $paiId],
                'LIMIT'  => 1,
            ])->current();

            if ($row === null) {
                return 0;
            }
            $paiId = (int) $row['id'];
        }

        return $paiId;
    }

    /** Procura um formulario nao excluido pelo nome exato. */
    public static function acharFormularioPorNome(string $nome): int
    {
        global $DB;

        $nome = trim($nome);
        if ($nome === '') {
            return 0;
        }
        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_forms_forms',
            'WHERE'  => ['name' => $nome, 'is_deleted' => 0],
            'LIMIT'  => 1,
        ])->current();

        return $row ? (int) $row['id'] : 0;
    }

    /** Procura uma dependencia nativa: arvore casa pelo caminho, resto pelo nome. */
    public static function acharDependencia(string $itemtype, array $def, array $doMesmoTipo = []): int
    {
        global $DB;

        $tabela = PluginCatalogoeformulariosPacote::tabelaTipo($itemtype);
        if ($tabela === '' || !$DB->tableExists($tabela)) {
            return 0;
        }

        $nome    = trim((string) ($def['nome'] ?? ''));
        $caminho = trim((string) ($def['caminho'] ?? ''));
        if ($nome === '' && $caminho === '') {
            return 0;
        }

        if (PluginCatalogoeformulariosPacote::ehArvore($itemtype)) {
            if ($caminho !== '' && $DB->fieldExists($tabela, 'completename')) {
                $row = $DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => $tabela,
                    'WHERE'  => ['completename' => $caminho],
                    'LIMIT'  => 1,
                ])->current();
                if ($row !== null) {
                    return (int) $row['id'];
                }
            }
            // Sem caminho identico: casa pelo nome apenas quando for unico.
            $achados = [];
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM'   => $tabela,
                'WHERE'  => ['name' => $nome],
                'LIMIT'  => 2,
            ]) as $r) {
                $achados[] = (int) $r['id'];
            }
            return count($achados) === 1 ? $achados[0] : 0;
        }

        $where = ['name' => $nome];
        if ($DB->fieldExists($tabela, 'is_deleted')) {
            $where['is_deleted'] = 0;
        }

        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => $tabela,
            'WHERE'  => $where,
            'LIMIT'  => 1,
        ])->current();

        return $row ? (int) $row['id'] : 0;
    }

    /** O ID informado existe mesmo na tabela do tipo? Barra IDs forjados no POST. */
    private static function validarAlvo(string $itemtype, int $id): int
    {
        global $DB;

        if ($id <= 0) {
            return 0;
        }

        $tabela = $itemtype === '__categoria__'
            ? 'glpi_forms_categories'
            : ($itemtype === '__formulario__' ? 'glpi_forms_forms' : PluginCatalogoeformulariosPacote::tabelaTipo($itemtype));

        if ($tabela === '' || !$DB->tableExists($tabela)) {
            return 0;
        }

        $row = $DB->request(['SELECT' => ['id'], 'FROM' => $tabela, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();

        return $row ? (int) $row['id'] : 0;
    }

    /** Le a acao escolhida para um item, sempre dentro da lista permitida. */
    private static function lerConf(array $conf, array $permitidas): array
    {
        $acao = (string) ($conf['acao'] ?? '');

        if (!in_array($acao, $permitidas, true)) {
            $acao = '';
        }

        return [
            'acao' => $acao,
            'alvo' => (int) ($conf['alvo'] ?? 0),
            'nome' => PluginCatalogoeformulariosPacote::limparNome((string) ($conf['nome'] ?? '')),
        ];
    }

    // -----------------------------------------------------------------
    // Fase 2: execucao
    // -----------------------------------------------------------------

    /**
     * Aplica a importacao.
     *
     * $selecao: entidade, ativar, deps[itemtype][idOrigem][acao|alvo|nome],
     *           cats[idOrigem][...], forms[idOrigem][...]
     */
    public static function executar(array $pacote, array $selecao): array
    {
        $entidade = (int) ($selecao['entidade'] ?? 0);
        $ativar   = !empty($selecao['ativar']);

        $rel = [
            'deps_criadas'      => 0,
            'deps_reusadas'     => 0,
            'deps_substituidas' => 0,
            'deps_apontadas'    => 0,
            'deps_ignoradas'    => 0,
            'deps_falhas'       => 0,
            'cat_criadas'       => 0,
            'cat_reusadas'      => 0,
            'cat_substituidas'  => 0,
            'cat_ignoradas'     => 0,
            'form_criados'      => 0,
            'form_substituidos' => 0,
            'form_ignorados'    => 0,
            'form_falhas'       => 0,
            'pendencias'        => [],
            'detalhes'          => [],
        ];

        $mapa = self::processarDependencias(
            $pacote,
            (array) ($selecao['deps'] ?? []),
            $entidade,
            $rel
        );

        $mapaCategorias = self::processarCategorias(
            (array) ($pacote['categorias'] ?? []),
            (array) ($selecao['cats'] ?? []),
            $entidade,
            $rel
        );

        self::processarFormularios(
            $pacote,
            (array) ($selecao['forms'] ?? []),
            $mapa,
            $mapaCategorias,
            $entidade,
            $ativar,
            $rel
        );

        return $rel;
    }

    // -----------------------------------------------------------------
    // 1) Dependencias
    // -----------------------------------------------------------------

    /** @return array<string, array<int,int>> de-para [itemtype => [origem => destino]] */
    private static function processarDependencias(array $pacote, array $acoes, int $entidade, array &$rel): array
    {
        $mapa = [];
        $deps = (array) ($pacote['dependencias'] ?? []);

        foreach (array_keys(PluginCatalogoeformulariosPacote::todosTipos()) as $itemtype) {
            if (!isset($deps[$itemtype])) {
                continue;
            }

            $itens     = (array) $deps[$itemtype];
            $criavel   = PluginCatalogoeformulariosPacote::ehCriavel($itemtype);
            $permitidas = PluginCatalogoeformulariosPacote::acoesDoTipo($itemtype);
            $label     = PluginCatalogoeformulariosPacote::labelTipo($itemtype);

            // Pais antes dos filhos.
            $ordem = array_keys($itens);
            usort($ordem, static function ($a, $b) use ($itens) {
                $pa = substr_count((string) ($itens[$a]['caminho'] ?? ''), '>');
                $pb = substr_count((string) ($itens[$b]['caminho'] ?? ''), '>');
                return $pa <=> $pb;
            });

            foreach ($ordem as $chave) {
                $idOrigem = (int) $chave;
                $def      = (array) $itens[(string) $chave];
                $rotulo   = (string) (($def['caminho'] ?? '') !== '' ? $def['caminho'] : ($def['nome'] ?? ''));

                $conf      = self::lerConf((array) ($acoes[$itemtype][(string) $idOrigem] ?? []), $permitidas);
                $existente = self::acharDependencia($itemtype, $def, $itens);
                $acao      = $conf['acao'];

                if ($acao === '') {
                    $acao = $existente > 0 ? 'reutilizar' : ($criavel ? 'novo' : 'ignorar');
                }

                switch ($acao) {
                    case 'reutilizar':
                        if ($existente > 0) {
                            $mapa[$itemtype][$idOrigem] = $existente;
                            $rel['deps_reusadas']++;
                        } else {
                            $rel['deps_ignoradas']++;
                            $rel['pendencias'][] = $label . ': "' . $rotulo . '" nao foi encontrado neste GLPI e nao foi criado.';
                        }
                        break;

                    case 'apontar':
                        $alvo = self::validarAlvo($itemtype, $conf['alvo']);
                        if ($alvo > 0) {
                            $mapa[$itemtype][$idOrigem] = $alvo;
                            $rel['deps_apontadas']++;
                            $rel['detalhes'][] = $label . ': "' . $rotulo . '" apontado para "' . self::rotuloExistente($itemtype, $alvo) . '".';
                        } else {
                            $rel['deps_ignoradas']++;
                            $rel['pendencias'][] = $label . ': "' . $rotulo . '" nao teve um item de destino valido selecionado.';
                        }
                        break;

                    case 'substituir':
                        $alvo = self::validarAlvo($itemtype, $conf['alvo'] > 0 ? $conf['alvo'] : $existente);
                        if ($alvo <= 0) {
                            $rel['deps_falhas']++;
                            $rel['pendencias'][] = $label . ': nao foi possivel substituir "' . $rotulo . '" (item de destino invalido).';
                            break;
                        }
                        if (self::atualizarDependencia($itemtype, $alvo, $def, $mapa, $conf['nome'])) {
                            $mapa[$itemtype][$idOrigem] = $alvo;
                            $rel['deps_substituidas']++;
                            $rel['detalhes'][] = $label . ' substituido: ' . $rotulo;
                        } else {
                            $rel['deps_falhas']++;
                            $rel['pendencias'][] = $label . ': falha ao substituir "' . $rotulo . '".';
                        }
                        break;

                    case 'novo':
                        if (!$criavel) {
                            $rel['deps_ignoradas']++;
                            $rel['pendencias'][] = $label . ': "' . $rotulo . '" nao pode ser criado a partir do pacote.';
                            break;
                        }
                        $novo = self::criarDependencia($itemtype, $def, $mapa, $entidade, $conf['nome']);
                        if ($novo > 0) {
                            $mapa[$itemtype][$idOrigem] = $novo;
                            $rel['deps_criadas']++;
                            $rel['detalhes'][] = $label . ' criado: ' . ($conf['nome'] !== '' ? $conf['nome'] : $rotulo);
                        } else {
                            $rel['deps_falhas']++;
                            $rel['pendencias'][] = 'Falha ao criar ' . $label . ': ' . $rotulo;
                        }
                        break;

                    default:
                        $rel['deps_ignoradas']++;
                        $rel['pendencias'][] = $label . ': "' . $rotulo . '" foi ignorado. As referencias a ele ficarao vazias.';
                        break;
                }
            }
        }

        return $mapa;
    }

    /** Cria um item nativo de dependencia pela classe nativa correspondente. */
    private static function criarDependencia(string $itemtype, array $def, array $mapa, int $entidade, string $nomeNovo = ''): int
    {
        global $DB;

        if (!class_exists($itemtype)) {
            return 0;
        }

        $tabela = PluginCatalogoeformulariosPacote::tabelaTipo($itemtype);
        $campos = PluginCatalogoeformulariosPacote::remapear((array) ($def['campos'] ?? []), $mapa);
        if (!is_array($campos)) {
            return 0;
        }

        $segmentos = (array) ($campos['_segmentos'] ?? []);
        unset($campos['_segmentos']);

        $campos = self::zerarReferenciasNaoResolvidas($campos, $def, $mapa);

        if ($nomeNovo !== '') {
            $campos['name'] = $nomeNovo;
        }

        // entities_id/is_recursive so quando a tabela realmente tem as colunas.
        if ($itemtype === 'Entity') {
            $campos['entities_id'] = $entidade;
        } else {
            if ($DB->fieldExists($tabela, 'entities_id')) {
                $campos['entities_id'] = $entidade;
            }
            if ($DB->fieldExists($tabela, 'is_recursive')) {
                $campos['is_recursive'] = 1;
            }
        }
        $campos['_disablenotif'] = true;

        // Pai da arvore (Entity usa a entidade de destino como pai).
        $campoPai = self::campoPaiDoTipo($itemtype);
        if ($campoPai !== null && $itemtype !== 'Entity') {
            $paiOrigem         = (int) ($def['pai'] ?? 0);
            $campos[$campoPai] = $paiOrigem > 0 ? (int) ($mapa[$itemtype][$paiOrigem] ?? 0) : 0;
        }

        try {
            $obj = new $itemtype();
            $id  = $obj->add($campos);
        } catch (\Throwable $e) {
            return 0;
        }

        if (!$id) {
            return 0;
        }
        $id = (int) $id;

        if ($itemtype === 'Calendar' && !empty($segmentos)) {
            self::gravarSegmentos($id, $segmentos, $entidade);
        }

        return $id;
    }

    /** Sobrescreve um item existente com a definicao vinda do pacote. */
    private static function atualizarDependencia(string $itemtype, int $alvo, array $def, array $mapa, string $nomeNovo = ''): bool
    {
        global $DB;

        if (!class_exists($itemtype)) {
            return false;
        }

        $campos = PluginCatalogoeformulariosPacote::remapear((array) ($def['campos'] ?? []), $mapa);
        if (!is_array($campos)) {
            return false;
        }

        $segmentos = (array) ($campos['_segmentos'] ?? []);
        unset($campos['_segmentos']);

        $campos = self::zerarReferenciasNaoResolvidas($campos, $def, $mapa);

        if ($nomeNovo !== '') {
            $campos['name'] = $nomeNovo;
        }

        // Nao mexe na entidade nem na hierarquia do item que ja vive no destino.
        unset($campos['entities_id'], $campos['is_recursive']);
        $campoPai = self::campoPaiDoTipo($itemtype);
        if ($campoPai !== null) {
            unset($campos[$campoPai]);
        }

        $campos['id']            = $alvo;
        $campos['_disablenotif'] = true;

        try {
            $obj = new $itemtype();
            $ok  = $obj->update($campos);
        } catch (\Throwable $e) {
            return false;
        }

        if ($itemtype === 'Calendar' && $DB->tableExists('glpi_calendarsegments')) {
            $DB->delete('glpi_calendarsegments', ['calendars_id' => $alvo]);
            if (!empty($segmentos)) {
                $ent = 0;
                $row = $DB->request(['SELECT' => ['entities_id'], 'FROM' => 'glpi_calendars', 'WHERE' => ['id' => $alvo], 'LIMIT' => 1])->current();
                if ($row !== null) {
                    $ent = (int) $row['entities_id'];
                }
                self::gravarSegmentos($alvo, $segmentos, $ent);
            }
        }

        return (bool) $ok;
    }

    /** Faixas de horario de um calendario. */
    private static function gravarSegmentos(int $calendarId, array $segmentos, int $entidade): void
    {
        if (!class_exists('CalendarSegment')) {
            return;
        }

        foreach ($segmentos as $seg) {
            try {
                $cs = new CalendarSegment();
                $cs->add([
                    'calendars_id'  => $calendarId,
                    'day'           => (int) ($seg['day'] ?? 0),
                    'begin'         => (string) ($seg['begin'] ?? '00:00:00'),
                    'end'           => (string) ($seg['end'] ?? '00:00:00'),
                    'entities_id'   => $entidade,
                    'is_recursive'  => 1,
                    '_disablenotif' => true,
                ]);
            } catch (\Throwable $e) {
                // Segmento invalido nao impede o restante.
            }
        }

        try {
            $cal = new Calendar();
            if (method_exists($cal, 'updateDurationCache')) {
                $cal->updateDurationCache($calendarId);
            }
        } catch (\Throwable $e) {
            // cache e opcional
        }
    }

    /** Zera referencias que nao puderam ser traduzidas, evitando apontar errado. */
    private static function zerarReferenciasNaoResolvidas(array $campos, array $def, array $mapa): array
    {
        $original = (array) ($def['campos'] ?? []);

        foreach ($campos as $chave => $valor) {
            if (!is_numeric($valor) || (int) $valor <= 0) {
                continue;
            }
            $tipo = PluginCatalogoeformulariosPacote::itemtypeDaChave((string) $chave);
            if ($tipo === null || $tipo === '_question' || $tipo === '_section') {
                continue;
            }
            $antigo = (int) ($original[$chave] ?? 0);
            if ($antigo > 0 && (int) $valor === $antigo && !isset($mapa[$tipo][$antigo])) {
                $campos[$chave] = 0;
            }
        }

        return $campos;
    }

    private static function campoPaiDoTipo(string $itemtype): ?string
    {
        switch ($itemtype) {
            case 'ITILCategory':
                return 'itilcategories_id';
            case 'Group':
                return 'groups_id';
            case 'Location':
                return 'locations_id';
            case 'Entity':
                return 'entities_id';
            default:
                return null;
        }
    }

    // -----------------------------------------------------------------
    // 2) Categorias do catalogo
    // -----------------------------------------------------------------

    /** @return array<int,int> de-para [id_origem => id_destino] */
    private static function processarCategorias(array $categorias, array $acoes, int $entidade, array &$rel): array
    {
        global $DB;

        $mapa       = [];
        $temIlu     = $DB->fieldExists('glpi_forms_categories', 'illustration');
        $permitidas = ['reutilizar', 'apontar', 'novo', 'substituir', 'ignorar'];

        // Pais antes dos filhos.
        $ordem = array_keys($categorias);
        usort($ordem, static function ($a, $b) use ($categorias) {
            $pa = substr_count((string) ($categorias[$a]['caminho'] ?? ''), '>');
            $pb = substr_count((string) ($categorias[$b]['caminho'] ?? ''), '>');
            return $pa <=> $pb;
        });

        foreach ($ordem as $chave) {
            $idOrigem = (int) $chave;
            $cat      = (array) $categorias[(string) $chave];
            $nome     = trim((string) ($cat['nome'] ?? ''));
            $rotulo   = (string) (($cat['caminho'] ?? '') !== '' ? $cat['caminho'] : $nome);
            if ($nome === '') {
                continue;
            }

            $paiOrigem  = (int) ($cat['pai'] ?? 0);
            $paiDestino = $paiOrigem > 0 ? (int) ($mapa[$paiOrigem] ?? 0) : 0;

            // Pai nao criado: procura pelo caminho para a categoria nao ficar orfa.
            if ($paiOrigem > 0 && $paiDestino === 0) {
                $paiDestino = self::acharCategoriaPorCaminho((string) ($categorias[(string) $paiOrigem]['caminho'] ?? ''));
            }

            $existenteId = self::acharCategoriaPorCaminho((string) ($cat['caminho'] ?? ''));
            $conf        = self::lerConf((array) ($acoes[(string) $idOrigem] ?? []), $permitidas);
            $acao        = $conf['acao'];

            if ($acao === '') {
                $acao = $existenteId > 0 ? 'reutilizar' : 'novo';
            }

            switch ($acao) {
                case 'reutilizar':
                    if ($existenteId > 0) {
                        $mapa[$idOrigem] = $existenteId;
                        $rel['cat_reusadas']++;
                    } else {
                        $rel['cat_ignoradas']++;
                        $rel['pendencias'][] = 'Categoria do catalogo "' . $rotulo . '" nao existe aqui e nao foi criada.';
                    }
                    break;

                case 'apontar':
                    $alvo = self::validarAlvo('__categoria__', $conf['alvo']);
                    if ($alvo > 0) {
                        $mapa[$idOrigem] = $alvo;
                        $rel['cat_reusadas']++;
                        $rel['detalhes'][] = 'Categoria do catalogo "' . $rotulo . '" apontada para "' . self::rotuloExistente('__categoria__', $alvo) . '".';
                    } else {
                        $rel['cat_ignoradas']++;
                        $rel['pendencias'][] = 'Categoria do catalogo "' . $rotulo . '": destino invalido.';
                    }
                    break;

                case 'substituir':
                    $alvo = self::validarAlvo('__categoria__', $conf['alvo'] > 0 ? $conf['alvo'] : $existenteId);
                    if ($alvo <= 0) {
                        $rel['pendencias'][] = 'Categoria do catalogo "' . $rotulo . '": destino invalido para substituicao.';
                        break;
                    }
                    $dados = [
                        'id'            => $alvo,
                        'name'          => $conf['nome'] !== '' ? $conf['nome'] : $nome,
                        'comment'       => (string) ($cat['descricao'] ?? ''),
                        '_disablenotif' => true,
                    ];
                    $ilu = trim((string) ($cat['ilustracao'] ?? ''));
                    if ($ilu !== '' && $temIlu) {
                        $dados['illustration'] = $ilu;
                    }
                    try {
                        $obj = new Category();
                        $ok  = $obj->update($dados);
                    } catch (\Throwable $e) {
                        $ok = false;
                    }
                    if ($ok) {
                        $mapa[$idOrigem] = $alvo;
                        $rel['cat_substituidas']++;
                        $rel['detalhes'][] = 'Categoria do catalogo substituida: ' . $rotulo;
                    } else {
                        $rel['pendencias'][] = 'Falha ao substituir a categoria do catalogo: ' . $rotulo;
                    }
                    break;

                case 'novo':
                    $dados = [
                        'name'                => $conf['nome'] !== '' ? $conf['nome'] : $nome,
                        'forms_categories_id' => $paiDestino,
                        'comment'             => (string) ($cat['descricao'] ?? ''),
                        '_disablenotif'       => true,
                    ];
                    $ilu = trim((string) ($cat['ilustracao'] ?? ''));
                    if ($ilu !== '' && $temIlu) {
                        $dados['illustration'] = $ilu;
                    }
                    if ($DB->fieldExists('glpi_forms_categories', 'entities_id')) {
                        $dados['entities_id'] = $entidade;
                        if ($DB->fieldExists('glpi_forms_categories', 'is_recursive')) {
                            $dados['is_recursive'] = 1;
                        }
                    }
                    try {
                        $obj  = new Category();
                        $novo = $obj->add($dados);
                    } catch (\Throwable $e) {
                        $novo = 0;
                    }
                    if ($novo) {
                        $mapa[$idOrigem] = (int) $novo;
                        $rel['cat_criadas']++;
                        $rel['detalhes'][] = 'Categoria do catalogo criada: ' . ($dados['name']);
                    } else {
                        $rel['pendencias'][] = 'Falha ao criar a categoria do catalogo: ' . $rotulo;
                    }
                    break;

                default:
                    $rel['cat_ignoradas']++;
                    break;
            }
        }

        return $mapa;
    }

    // -----------------------------------------------------------------
    // 3) Formularios
    // -----------------------------------------------------------------

    private static function processarFormularios(
        array $pacote,
        array $acoes,
        array $mapaDeps,
        array $mapaCategorias,
        int $entidade,
        bool $ativar,
        array &$rel
    ): void {
        $permitidas = ['novo', 'substituir', 'ignorar'];

        foreach ((array) ($pacote['formularios'] ?? []) as $chave => $form) {
            $form     = (array) $form;
            $idOrigem = (int) $chave;
            $nome     = (string) ($form['campos']['name'] ?? '');
            if ($nome === '') {
                continue;
            }

            $conf = self::lerConf((array) ($acoes[(string) $idOrigem] ?? []), $permitidas);
            $acao = $conf['acao'] !== '' ? $conf['acao'] : 'ignorar';

            if ($acao === 'ignorar') {
                $rel['form_ignorados']++;
                continue;
            }

            $substituirId = 0;
            if ($acao === 'substituir') {
                $substituirId = self::validarAlvo('__formulario__', $conf['alvo'] > 0 ? $conf['alvo'] : self::acharFormularioPorNome($nome));
                if ($substituirId <= 0) {
                    $rel['form_falhas']++;
                    $rel['pendencias'][] = 'Formulario "' . $nome . '": destino invalido para substituicao.';
                    continue;
                }
            }

            $novoId = self::importarFormulario(
                $form,
                $mapaDeps,
                $mapaCategorias,
                $entidade,
                $ativar,
                $conf['nome'],
                $substituirId,
                $rel
            );

            if ($novoId > 0) {
                if ($substituirId > 0) {
                    $rel['form_substituidos']++;
                } else {
                    $rel['form_criados']++;
                }
            } else {
                $rel['form_falhas']++;
            }
        }
    }

    /**
     * Recria um formulario completo. Com $substituirId > 0 o formulario
     * existente e reaproveitado: campos atualizados e conteudo refeito.
     */
    private static function importarFormulario(
        array $form,
        array $mapaDeps,
        array $mapaCategorias,
        int $entidade,
        bool $ativar,
        string $nomeNovo,
        int $substituirId,
        array &$rel
    ): int {
        global $DB;

        $campos = (array) ($form['campos'] ?? []);
        $nome   = $nomeNovo !== '' ? $nomeNovo : trim((string) ($campos['name'] ?? ''));
        if ($nome === '') {
            return 0;
        }

        $catOrigem  = (int) ($form['categoria'] ?? 0);
        $catDestino = $catOrigem > 0 ? (int) ($mapaCategorias[$catOrigem] ?? 0) : 0;

        $base = [
            'name'                => $nome,
            'description'         => (string) ($campos['description'] ?? ''),
            'header'              => (string) ($campos['header'] ?? ''),
            'is_active'           => $ativar ? 1 : 0,
            'is_draft'            => (int) ($campos['is_draft'] ?? 0),
            'forms_categories_id' => $catDestino,
            'render_layout'       => (string) ($campos['render_layout'] ?? 'step_by_step'),
            '_disablenotif'       => true,
        ];

        foreach (['illustration', 'is_pinned', 'submit_button_visibility_strategy'] as $extra) {
            if (isset($campos[$extra]) && $DB->fieldExists('glpi_forms_forms', $extra)) {
                $base[$extra] = $campos[$extra];
            }
        }

        if ($substituirId > 0) {
            $dados       = $base;
            $dados['id'] = $substituirId;
            try {
                $objForm = new Form();
                $objForm->update($dados);
            } catch (\Throwable $e) {
                $rel['pendencias'][] = 'Falha ao substituir o formulario "' . $nome . '": ' . $e->getMessage();
                return 0;
            }
            $novoId = $substituirId;
            self::limparFilhos($novoId, true);
        } else {
            $entrada                          = $base;
            $entrada['entities_id']           = $entidade;
            $entrada['is_recursive']          = (int) ($campos['is_recursive'] ?? 1);
            $entrada['_do_not_init_sections'] = true;

            try {
                $objForm = new Form();
                $novoId  = $objForm->add($entrada);
            } catch (\Throwable $e) {
                $rel['pendencias'][] = 'Falha ao criar o formulario "' . $nome . '": ' . $e->getMessage();
                return 0;
            }

            if (!$novoId) {
                $rel['pendencias'][] = 'Falha ao criar o formulario "' . $nome . '".';
                return 0;
            }
            $novoId = (int) $novoId;
            self::limparFilhos($novoId, false);
        }

        // Secoes e perguntas (primeira passada, sem condicoes).
        $mapaUuid     = [];
        $mapaSecoes   = [];
        $mapaPergunta = [];
        $condicoes    = [];

        foreach ((array) ($form['secoes'] ?? []) as $secao) {
            $cs = (array) ($secao['campos'] ?? []);

            try {
                $objSecao  = new Section();
                $novaSecao = $objSecao->add([
                    'forms_forms_id' => $novoId,
                    'name'           => (string) ($cs['name'] ?? ''),
                    'description'    => (string) ($cs['description'] ?? ''),
                    'rank'           => (int) ($cs['rank'] ?? 0),
                    '_disablenotif'  => true,
                ]);
            } catch (\Throwable $e) {
                $novaSecao = 0;
            }

            if (!$novaSecao) {
                $rel['pendencias'][] = 'Formulario "' . $nome . '": falha ao criar a secao "' . ($cs['name'] ?? '') . '".';
                continue;
            }
            $novaSecao = (int) $novaSecao;

            $mapaSecoes[(int) ($secao['id_origem'] ?? 0)] = $novaSecao;
            self::registrarUuid($mapaUuid, 'glpi_forms_sections', $novaSecao, (string) ($cs['uuid'] ?? ''));

            if (!empty($cs['conditions'])) {
                $condicoes[] = ['tipo' => 'secao', 'id' => $novaSecao, 'conditions' => $cs['conditions'], 'strategy' => $cs['visibility_strategy'] ?? null];
            }

            foreach ((array) ($secao['perguntas'] ?? []) as $pergunta) {
                $cq = (array) ($pergunta['campos'] ?? []);

                $entradaPergunta = [
                    'forms_sections_id' => $novaSecao,
                    'name'              => (string) ($cq['name'] ?? ''),
                    'type'              => (string) ($cq['type'] ?? ''),
                    'is_mandatory'      => (int) ($cq['is_mandatory'] ?? 0),
                    'description'       => (string) ($cq['description'] ?? ''),
                    'default_value'     => $cq['default_value'] ?? '',
                    'horizontal_rank'   => $cq['horizontal_rank'] ?? null,
                    'vertical_rank'     => (int) ($cq['vertical_rank'] ?? 0),
                    '_disablenotif'     => true,
                ];

                if (isset($cq['extra_data']) && $cq['extra_data'] !== null && $cq['extra_data'] !== '') {
                    $entradaPergunta['extra_data'] = PluginCatalogoeformulariosPacote::remapear($cq['extra_data'], $mapaDeps);
                }
                if ($entradaPergunta['default_value'] !== '') {
                    $entradaPergunta['default_value'] = PluginCatalogoeformulariosPacote::remapear($entradaPergunta['default_value'], $mapaDeps);
                }

                try {
                    $objPergunta  = new Question();
                    $novaPergunta = $objPergunta->add($entradaPergunta);
                } catch (\Throwable $e) {
                    $novaPergunta = 0;
                }

                if (!$novaPergunta) {
                    $rel['pendencias'][] = 'Formulario "' . $nome . '": falha ao criar a pergunta "' . ($cq['name'] ?? '') . '".';
                    continue;
                }
                $novaPergunta = (int) $novaPergunta;

                $mapaPergunta[(int) ($pergunta['id_origem'] ?? 0)] = $novaPergunta;
                self::registrarUuid($mapaUuid, 'glpi_forms_questions', $novaPergunta, (string) ($cq['uuid'] ?? ''));

                if (!empty($cq['conditions'])) {
                    $condicoes[] = ['tipo' => 'pergunta', 'id' => $novaPergunta, 'conditions' => $cq['conditions'], 'strategy' => $cq['visibility_strategy'] ?? null];
                }
            }
        }

        $mapaTotal              = $mapaDeps;
        $mapaTotal['_question'] = $mapaPergunta;
        $mapaTotal['_section']  = $mapaSecoes;

        // Segunda passada: condicoes, com todas as perguntas ja existindo.
        foreach ($condicoes as $c) {
            $valor = self::traduzirJson($c['conditions'], $mapaTotal, $mapaUuid);

            $dados = ['id' => (int) $c['id'], 'conditions' => $valor, '_disablenotif' => true];
            if ($c['strategy'] !== null && $c['strategy'] !== '') {
                $dados['visibility_strategy'] = $c['strategy'];
            }

            try {
                $obj = $c['tipo'] === 'secao' ? new Section() : new Question();
                $obj->update($dados);
            } catch (\Throwable $e) {
                $rel['pendencias'][] = 'Formulario "' . $nome . '": nao foi possivel aplicar as condicoes de visibilidade de um item.';
            }
        }

        // Destinos.
        if ($DB->tableExists('glpi_forms_destinations_formdestinations')) {
            foreach ((array) ($form['destinos'] ?? []) as $destino) {
                $linha                   = (array) ($destino['campos'] ?? []);
                $linha['forms_forms_id'] = $novoId;

                foreach (['config', 'conditions'] as $col) {
                    if (isset($linha[$col]) && $linha[$col] !== null && $linha[$col] !== '') {
                        $linha[$col] = self::traduzirJson($linha[$col], $mapaTotal, $mapaUuid);
                    }
                }

                // Insercao direta: a classe nativa reconstroi a config a partir de
                // objetos internos e perderia o que veio no pacote.
                try {
                    $DB->insert('glpi_forms_destinations_formdestinations', $linha);
                } catch (\Throwable $e) {
                    $rel['pendencias'][] = 'Formulario "' . $nome . '": falha ao importar um destino.';
                }
            }
            // O chamado gerado fica na entidade escolhida para o formulario importado
            PluginCatalogoeformulariosDestino::entidadeDoFormulario((int) $novoId);
        }

        // Controle de acesso.
        $tabelaAcesso = 'glpi_forms_accesscontrols_formaccesscontrols';
        if ($DB->tableExists($tabelaAcesso)) {
            foreach ((array) ($form['acesso'] ?? []) as $acesso) {
                $linha                   = (array) ($acesso['campos'] ?? []);
                $linha['forms_forms_id'] = $novoId;

                if (isset($linha['config']) && $linha['config'] !== null && $linha['config'] !== '') {
                    $linha['config'] = self::traduzirJson($linha['config'], $mapaTotal, $mapaUuid);
                }

                $existente = $DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => $tabelaAcesso,
                    'WHERE'  => ['forms_forms_id' => $novoId, 'strategy' => $linha['strategy'] ?? ''],
                    'LIMIT'  => 1,
                ])->current();

                try {
                    if ($existente !== null) {
                        $upd = $linha;
                        unset($upd['forms_forms_id'], $upd['strategy']);
                        $DB->update($tabelaAcesso, $upd, ['id' => (int) $existente['id']]);
                    } else {
                        $DB->insert($tabelaAcesso, $linha);
                    }
                } catch (\Throwable $e) {
                    $rel['pendencias'][] = 'Formulario "' . $nome . '": falha ao importar o controle de acesso.';
                }
            }
        }

        $rel['detalhes'][] = ($substituirId > 0 ? 'Formulario substituido: ' : 'Formulario importado: ') . $nome;

        return $novoId;
    }

    /** Remove secoes, destinos e (opcionalmente) acessos de um formulario. */
    private static function limparFilhos(int $formId, bool $incluirAcesso): void
    {
        global $DB;

        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_forms_sections',
            'WHERE'  => ['forms_forms_id' => $formId],
        ]) as $s) {
            try {
                $sec = new Section();
                $sec->delete(['id' => (int) $s['id']], true);
            } catch (\Throwable $e) {
                // segue
            }
        }

        if ($DB->tableExists('glpi_forms_destinations_formdestinations')) {
            $DB->delete('glpi_forms_destinations_formdestinations', ['forms_forms_id' => $formId]);
        }

        if ($incluirAcesso && $DB->tableExists('glpi_forms_accesscontrols_formaccesscontrols')) {
            $DB->delete('glpi_forms_accesscontrols_formaccesscontrols', ['forms_forms_id' => $formId]);
        }
    }

    /**
     * De-para de UUID (origem => destino). As condicoes de visibilidade do
     * GLPI 11 apontam para o UUID do item, entao a troca textual mantem tudo valido.
     */
    private static function registrarUuid(array &$mapaUuid, string $tabela, int $novoId, string $uuidOrigem): void
    {
        global $DB;

        $uuidOrigem = trim($uuidOrigem);
        if ($uuidOrigem === '') {
            return;
        }

        $row = $DB->request([
            'SELECT' => ['uuid'],
            'FROM'   => $tabela,
            'WHERE'  => ['id' => $novoId],
            'LIMIT'  => 1,
        ])->current();

        $novoUuid = (string) ($row['uuid'] ?? '');
        if ($novoUuid !== '' && $novoUuid !== $uuidOrigem) {
            $mapaUuid[$uuidOrigem] = $novoUuid;
        }
    }

    /** Traduz um JSON nativo: primeiro os UUIDs, depois os IDs numericos. */
    private static function traduzirJson($valor, array $mapa, array $mapaUuid)
    {
        if (is_string($valor) && !empty($mapaUuid)) {
            $valor = strtr($valor, $mapaUuid);
        } elseif (is_array($valor) && !empty($mapaUuid)) {
            $json = json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                $dec = json_decode(strtr($json, $mapaUuid), true);
                if (is_array($dec)) {
                    $valor = $dec;
                }
            }
        }

        return PluginCatalogoeformulariosPacote::remapear($valor, $mapa);
    }
}
