<?php

use Glpi\Form\Form;
use Glpi\Form\Category;
use Glpi\Form\Section;
use Glpi\Form\Question;

/**
 * Logica do gerenciador de catalogo de servicos.
 *
 * Tudo e persistido nas tabelas nativas do GLPI 11 atraves das classes nativas
 * (Form, Category, Section, Question). Esta classe e apenas o "facilitador":
 * nao cria tabelas proprias.
 */
class PluginCatalogoeformulariosCatalogo extends CommonGLPI
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

    private const NS = 'Glpi\\Form\\QuestionType\\';

    /**
     * Entidade onde os formularios e categorias novos do catalogo sao criados
     * (configuracao "entidade_padrao"; entidade raiz se a escolhida nao existir mais).
     */
    public static function getEntidadePadrao(): int
    {
        $id = (int) PluginCatalogoeformulariosConfig::getConfig('entidade_padrao', 0);
        if ($id > 0 && countElementsInTable('glpi_entities', ['id' => $id]) === 0) {
            return 0;
        }
        return max(0, $id);
    }

    // -----------------------------------------------------------------
    // Tipos de pergunta: a definicao completa fica em PluginCatalogoeformulariosEstrutura
    // (todos os tipos nativos, com a configuracao de cada um).
    // -----------------------------------------------------------------
    public static function getMapaTipos(): array
    {
        $saida = [];
        foreach (PluginCatalogoeformulariosEstrutura::mapa() as $slug => $def) {
            $saida[$slug] = ['fqcn' => $def['fqcn'], 'label' => $def['label'], 'opcoes' => !empty($def['opcoes'])];
        }
        return $saida;
    }

    /** Lista enxuta para o front montar o seletor de tipos (apenas tipos existentes nesta instalacao). */
    public static function getTiposPergunta(): array
    {
        return PluginCatalogoeformulariosEstrutura::tipos();
    }

    // -----------------------------------------------------------------
    // LEITURA - arvore e niveis
    // -----------------------------------------------------------------

    /**
     * A tabela nativa de categorias possui a coluna de ilustracao (icone)?
     * Checado uma vez por request para tolerar instalacoes 11.0.x mais antigas.
     */
    private static function temIlustracao(): bool
    {
        global $DB;

        static $tem = null;
        if ($tem === null) {
            $tem = (bool) $DB->fieldExists('glpi_forms_categories', 'illustration');
        }
        return $tem;
    }

    /** Campos lidos de glpi_forms_categories, incluindo a ilustracao quando existir. */
    private static function camposCategoria(array $base): array
    {
        if (self::temIlustracao()) {
            $base[] = 'illustration';
        }
        return $base;
    }

    /**
     * Biblioteca de ilustracoes nativas do GLPI 11 (os icones das categorias do
     * catalogo de servicos). Os ids vivem todos num sprite SVG unico e o front
     * referencia cada um por <use xlink:href="sprite.svg#id">.
     *
     * @return array<int, array{id: string, titulo: string}>
     */
    public static function getIlustracoes(): array
    {
        $classe = '\\Glpi\\UI\\IllustrationManager';
        if (!class_exists($classe)) {
            return [];
        }

        try {
            $mgr     = new $classe();
            $ids     = $mgr->getAllIconsIds();
            $titulos = method_exists($mgr, 'getAllIconsTitles') ? $mgr->getAllIconsTitles() : [];
        } catch (\Throwable $e) {
            return [];
        }

        $saida = [];
        foreach (array_values($ids) as $i => $iconId) {
            $saida[] = [
                'id'     => (string) $iconId,
                // getAllIconsTitles devolve os titulos na MESMA ordem dos ids.
                'titulo' => (string) ($titulos[$i] ?? $iconId),
            ];
        }
        return $saida;
    }

    /** URL publica do sprite SVG das ilustracoes nativas. */
    public static function getSpriteIlustracoes(): string
    {
        global $CFG_GLPI;

        return ($CFG_GLPI['root_doc'] ?? '') . '/lib/glpi-project/illustrations/glpi-illustrations-icons.svg';
    }

    /**
     * Dados de UMA categoria para o modal de edicao (nome, icone, pai, descricao).
     * Busca por ID, sem depender do nivel carregado no painel principal.
     */
    public static function getCategoriaDetalhe(int $id): ?array
    {
        global $DB;

        if ($id <= 0) {
            return null;
        }

        $row = $DB->request([
            'SELECT' => self::camposCategoria(['id', 'name', 'completename', 'comment', 'forms_categories_id']),
            'FROM'   => 'glpi_forms_categories',
            'WHERE'  => ['id' => $id],
            'LIMIT'  => 1,
        ])->current();

        if ($row === null) {
            return null;
        }

        return [
            'id'           => (int) $row['id'],
            'nome'         => $row['name'],
            'completename' => $row['completename'] ?? $row['name'],
            'descricao'    => $row['comment'] ?? '',
            'pai'          => (int) $row['forms_categories_id'],
            'ilustracao'   => (string) ($row['illustration'] ?? ''),
        ];
    }

    /**
     * Popularidade por categoria: soma de usage_count dos formularios DIRETOS,
     * considerando apenas ativos e nao excluidos.
     *
     * Espelha PopularitySort::getPopularity() do GLPI 11.0.4. O core soma os
     * filhos via getChildren(), que naquele momento traz somente os formularios
     * diretos do nivel (as subcategorias nao tem os proprios filhos carregados),
     * por isso a soma NAO e recursiva. Confirmado contra a tela do /ServiceCatalog.
     *
     * @return array<int, int> [ idCategoria => popularidade ]
     */
    private static function getPopularidadePorCategoria(): array
    {
        global $DB;

        static $mapa = null;
        if ($mapa !== null) {
            return $mapa;
        }

        $mapa = [];
        foreach ($DB->request([
            'SELECT' => [
                'forms_categories_id',
                new \Glpi\DBAL\QueryExpression('COALESCE(SUM(usage_count), 0) AS total'),
            ],
            'FROM'    => 'glpi_forms_forms',
            'WHERE'   => ['is_deleted' => 0, 'is_active' => 1],
            'GROUPBY' => ['forms_categories_id'],
        ]) as $row) {
            $mapa[(int) $row['forms_categories_id']] = (int) $row['total'];
        }

        return $mapa;
    }

    /** Popularidade de uma categoria (0 quando nao tem formulario direto ativo). */
    private static function popularidadeCategoria(int $categoriaId): int
    {
        $mapa = self::getPopularidadePorCategoria();
        return (int) ($mapa[$categoriaId] ?? 0);
    }

    /**
     * Ordena categorias como o catalogo nativo: popularidade decrescente e,
     * em caso de empate, nome crescente.
     *
     * Categorias nunca sao "fixadas" no GLPI (Category::isServiceCatalogItemPinned
     * retorna false sempre), por isso aqui nao ha tratamento de pinned.
     */
    private static function ordenarCategorias(array $cats): array
    {
        if (count($cats) < 2) {
            return $cats;
        }

        usort($cats, static function (array $a, array $b): int {
            $pa = (int) ($a['popularidade'] ?? 0);
            $pb = (int) ($b['popularidade'] ?? 0);
            if ($pa !== $pb) {
                return $pb <=> $pa;
            }
            return strcasecmp((string) ($a['nome'] ?? ''), (string) ($b['nome'] ?? ''));
        });

        return $cats;
    }

    /**
     * Ordena formularios como o catalogo nativo: fixados primeiro, depois
     * usage_count decrescente e, no empate, nome crescente.
     */
    private static function ordenarFormularios(array $forms): array
    {
        if (count($forms) < 2) {
            return $forms;
        }

        usort($forms, static function (array $a, array $b): int {
            $fa = !empty($a['fixado']);
            $fb = !empty($b['fixado']);
            if ($fa !== $fb) {
                return $fb <=> $fa;
            }
            $ua = (int) ($a['usos'] ?? 0);
            $ub = (int) ($b['usos'] ?? 0);
            if ($ua !== $ub) {
                return $ub <=> $ua;
            }
            return strcasecmp((string) ($a['nome'] ?? ''), (string) ($b['nome'] ?? ''));
        });

        return $forms;
    }

    /** Separa os formularios fixados dos demais (fixados vem antes das categorias). */
    private static function separarFixados(array $forms): array
    {
        $fixados = [];
        $normais = [];
        foreach ($forms as $f) {
            if (!empty($f['fixado'])) {
                $fixados[] = $f;
            } else {
                $normais[] = $f;
            }
        }
        return [self::ordenarFormularios($fixados), self::ordenarFormularios($normais)];
    }

    /**
     * Arvore completa de categorias (raiz -> folhas) com os formularios de cada nivel.
     * Usado pelo painel lateral de hierarquia.
     */
    public static function getArvoreCategorias(): array
    {
        global $DB;

        $cats     = [];
        $filhosDe = []; // paiId => [ids]

        foreach ($DB->request([
            'SELECT' => self::camposCategoria(['id', 'name', 'forms_categories_id', 'comment']),
            'FROM'   => 'glpi_forms_categories',
            'ORDER'  => 'name ASC',
        ]) as $row) {
            $id  = (int) $row['id'];
            $pai = (int) $row['forms_categories_id'];

            $cats[$id] = [
                'id'           => $id,
                'nome'         => $row['name'],
                'pai'          => $pai,
                'descricao'    => $row['comment'] ?? '',
                'ilustracao'   => (string) ($row['illustration'] ?? ''),
                'popularidade' => self::popularidadeCategoria($id),
                'formularios'  => [],
                'fixados'      => [],
                'filhos'       => [],
            ];
            $filhosDe[$pai][] = $id;
        }

        $formsRaiz = [];
        foreach ($DB->request([
            'SELECT' => [
                'id', 'name', 'is_active', 'is_draft', 'forms_categories_id',
                'usage_count', 'is_pinned',
            ],
            'FROM'   => 'glpi_forms_forms',
            'WHERE'  => ['is_deleted' => 0],
            'ORDER'  => 'name ASC',
        ]) as $f) {
            $cat  = (int) $f['forms_categories_id'];
            $info = [
                'id'       => (int) $f['id'],
                'nome'     => $f['name'],
                'ativo'    => (int) $f['is_active'] === 1,
                'rascunho' => (int) $f['is_draft'] === 1,
                'usos'     => (int) ($f['usage_count'] ?? 0),
                'fixado'   => (int) ($f['is_pinned'] ?? 0) === 1,
            ];
            if ($cat > 0 && isset($cats[$cat])) {
                $cats[$cat]['formularios'][] = $info;
            } else {
                $formsRaiz[] = $info;
            }
        }

        $montar = function (int $paiId) use (&$montar, $cats, $filhosDe): array {
            $out = [];
            foreach ($filhosDe[$paiId] ?? [] as $cid) {
                $node = $cats[$cid];
                // Fixados saem da lista normal: no catalogo nativo eles vem antes
                // mesmo das subcategorias.
                [$fix, $normais]     = self::separarFixados($node['formularios']);
                $node['fixados']     = $fix;
                $node['formularios'] = $normais;
                $node['filhos']      = $montar($cid);
                $out[]               = $node;
            }
            return self::ordenarCategorias($out);
        };

        [$fixRaiz, $normaisRaiz] = self::separarFixados($formsRaiz);

        return [
            'categorias'            => $montar(0),
            'formularios_raiz'      => $normaisRaiz,
            'formularios_raiz_fixos' => $fixRaiz,
        ];
    }

    /**
     * Conteudo de um nivel do catalogo. categoriaId = 0 e a raiz.
     * Retorna subcategorias e formularios daquele nivel.
     */
    public static function getConteudo(int $categoriaId): array
    {
        global $DB;

        $categorias = [];
        $idxPorCat  = []; // categoriaId => indice em $categorias (para anexar os formularios)
        foreach ($DB->request([
            'SELECT' => self::camposCategoria(['id', 'name', 'completename', 'comment']),
            'FROM'   => 'glpi_forms_categories',
            'WHERE'  => ['forms_categories_id' => $categoriaId],
            'ORDER'  => 'name ASC',
        ]) as $row) {
            $id = (int) $row['id'];
            $categorias[] = [
                'id'            => $id,
                'nome'          => $row['name'],
                'completename'  => $row['completename'],
                'descricao'     => $row['comment'] ?? '',
                'ilustracao'    => (string) ($row['illustration'] ?? ''),
                'popularidade'  => self::popularidadeCategoria($id),
                'qtd_subcat'    => self::contar('glpi_forms_categories', ['forms_categories_id' => $id]),
                'qtd_form'      => self::contar('glpi_forms_forms', ['forms_categories_id' => $id, 'is_deleted' => 0]),
                'formularios'   => [],
                // Subarvore completa (subcategorias aninhadas + seus formularios),
                // para o acordeao mostrar formularios mesmo de categoria dentro de categoria.
                'subcategorias' => self::getSubArvore($id),
            ];
            $idxPorCat[$id] = count($categorias) - 1;
        }

        // Carrega de uma vez os formularios diretos de cada subcategoria deste nivel,
        // para exibir as relacoes "categoria -> formularios" sem precisar entrar nelas.
        if (!empty($idxPorCat)) {
            foreach ($DB->request([
                'SELECT' => [
                    'id', 'name', 'is_active', 'is_draft', 'entities_id', 'forms_categories_id',
                    'usage_count', 'is_pinned', ...(self::formTemIlustracao() ? ['illustration'] : []),
                ],
                'FROM'   => 'glpi_forms_forms',
                'WHERE'  => ['forms_categories_id' => array_keys($idxPorCat), 'is_deleted' => 0],
                'ORDER'  => 'name ASC',
            ]) as $row) {
                $cat = (int) $row['forms_categories_id'];
                if (!isset($idxPorCat[$cat])) {
                    continue;
                }
                $categorias[$idxPorCat[$cat]]['formularios'][] = [
                    'id'       => (int) $row['id'],
                    'nome'     => $row['name'],
                    'ativo'    => (int) $row['is_active'] === 1,
                    'rascunho' => (int) $row['is_draft'] === 1,
                    'usos'     => (int) ($row['usage_count'] ?? 0),
                    'fixado'   => (int) ($row['is_pinned'] ?? 0) === 1,
                    'ilustracao' => (string) ($row['illustration'] ?? ''),
                    'entidade' => Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id']),
                    'perfis'   => PluginCatalogoeformulariosAcesso::resumoPerfis((int) $row['id']),
                ];
            }
        }

        $formularios = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'is_active', 'is_draft', 'entities_id', 'usage_count', 'is_pinned', ...(self::formTemIlustracao() ? ['illustration'] : [])],
            'FROM'   => 'glpi_forms_forms',
            'WHERE'  => ['forms_categories_id' => $categoriaId, 'is_deleted' => 0],
            'ORDER'  => 'name ASC',
        ]) as $row) {
            $fid = (int) $row['id'];
            $formularios[] = [
                'id'           => $fid,
                'nome'         => $row['name'],
                'ativo'        => (int) $row['is_active'] === 1,
                'rascunho'     => (int) $row['is_draft'] === 1,
                'usos'         => (int) ($row['usage_count'] ?? 0),
                'fixado'       => (int) ($row['is_pinned'] ?? 0) === 1,
                'ilustracao'   => (string) ($row['illustration'] ?? ''),
                'entidade'     => Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id']),
                'qtd_destinos' => self::contar('glpi_forms_destinations_formdestinations', ['forms_forms_id' => $fid]),
                'qtd_acesso'   => self::contar('glpi_forms_accesscontrols_formaccesscontrols', ['forms_forms_id' => $fid, 'is_active' => 1]),
                'perfis'       => PluginCatalogoeformulariosAcesso::resumoPerfis($fid),
            ];
        }

        // Mesma ordenacao da arvore lateral e do catalogo nativo.
        foreach ($categorias as $i => $c) {
            $categorias[$i]['formularios'] = self::ordenarFormularios($c['formularios']);
        }
        $categorias  = self::ordenarCategorias($categorias);
        $formularios = self::ordenarFormularios($formularios);

        $trilha = self::getTrilha($categoriaId);

        return ['categorias' => $categorias, 'formularios' => $formularios, 'trilha' => $trilha];
    }

    /**
     * Subarvore recursiva de uma categoria: todas as subcategorias (em qualquer
     * profundidade), cada uma com seus formularios diretos. Usado para exibir os
     * formularios aninhados dentro do acordeao da categoria.
     */
    private static function getSubArvore(int $categoriaId): array
    {
        global $DB;

        $saida = [];
        foreach ($DB->request([
            'SELECT' => self::camposCategoria(['id', 'name', 'comment']),
            'FROM'   => 'glpi_forms_categories',
            'WHERE'  => ['forms_categories_id' => $categoriaId],
            'ORDER'  => 'name ASC',
        ]) as $row) {
            $id = (int) $row['id'];

            $forms = [];
            foreach ($DB->request([
                'SELECT' => ['id', 'name', 'is_active', 'is_draft', 'entities_id', 'usage_count', 'is_pinned', ...(self::formTemIlustracao() ? ['illustration'] : [])],
                'FROM'   => 'glpi_forms_forms',
                'WHERE'  => ['forms_categories_id' => $id, 'is_deleted' => 0],
                'ORDER'  => 'name ASC',
            ]) as $f) {
                $forms[] = [
                    'id'       => (int) $f['id'],
                    'nome'     => $f['name'],
                    'ativo'    => (int) $f['is_active'] === 1,
                    'rascunho' => (int) $f['is_draft'] === 1,
                    'usos'     => (int) ($f['usage_count'] ?? 0),
                    'fixado'   => (int) ($f['is_pinned'] ?? 0) === 1,
                    'ilustracao' => (string) ($f['illustration'] ?? ''),
                    'entidade' => Dropdown::getDropdownName('glpi_entities', (int) $f['entities_id']),
                    'perfis'   => PluginCatalogoeformulariosAcesso::resumoPerfis((int) $f['id']),
                ];
            }

            $saida[] = [
                'id'            => $id,
                'nome'          => $row['name'],
                'descricao'     => $row['comment'] ?? '',
                'ilustracao'    => (string) ($row['illustration'] ?? ''),
                'popularidade'  => self::popularidadeCategoria($id),
                'formularios'   => self::ordenarFormularios($forms),
                'subcategorias' => self::getSubArvore($id),
            ];
        }
        return self::ordenarCategorias($saida);
    }

    /** Trilha de categorias da raiz ate a categoria atual. */
    private static function getTrilha(int $categoriaId): array
    {
        global $DB;

        $trilha = [];
        $atual  = $categoriaId;
        $guarda = 0;

        while ($atual > 0 && $guarda < 50) {
            $row = $DB->request([
                'SELECT' => ['id', 'name', 'forms_categories_id'],
                'FROM'   => 'glpi_forms_categories',
                'WHERE'  => ['id' => $atual],
                'LIMIT'  => 1,
            ])->current();

            if ($row === null) {
                break;
            }
            array_unshift($trilha, ['id' => (int) $row['id'], 'nome' => $row['name']]);
            $atual = (int) $row['forms_categories_id'];
            $guarda++;
        }

        return $trilha;
    }

    /** Detalhe completo de um formulario: dados, secoes, perguntas e resumo de destinos/acessos. */
    public static function getDetalheFormulario(int $formId): ?array
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

        $secoes = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'uuid', 'name', 'description', 'rank'],
            'FROM'   => 'glpi_forms_sections',
            'WHERE'  => ['forms_forms_id' => $formId],
            'ORDER'  => 'rank ASC',
        ]) as $sec) {
            $secId = (int) $sec['id'];

            $perguntas = [];
            // FROM sem SELECT: pega todas as colunas (robusto entre versoes 11.0.x).
            foreach ($DB->request([
                'FROM'  => 'glpi_forms_questions',
                'WHERE' => ['forms_sections_id' => $secId],
                'ORDER' => 'vertical_rank ASC',
            ]) as $q) {
                $extra = (string) ($q['extra_data'] ?? '');
                $tipo  = self::detectarTipo((string) $q['type'], $extra);

                $opcoesValores = [];
                if ($tipo['opcoes']) {
                    $extraArr = $extra !== '' ? (json_decode($extra, true) ?: []) : [];
                    if (isset($extraArr['options']) && is_array($extraArr['options'])) {
                        $opcoesValores = array_values($extraArr['options']);
                    }
                }

                $estrategia  = (string) ($q['visibility_strategy'] ?? '');
                $condicoes   = $q['conditions'] ?? '';
                $condArr     = (is_string($condicoes) && $condicoes !== '')
                    ? (json_decode($condicoes, true) ?: [])
                    : (is_array($condicoes) ? $condicoes : []);

                $perguntas[] = [
                    'id'             => (int) $q['id'],
                    'uuid'           => $q['uuid'] ?? '',
                    'nome'           => $q['name'],
                    'tipo_slug'      => $tipo['slug'],
                    'tipo_label'     => $tipo['label'],
                    'obrigatoria'    => (int) ($q['is_mandatory'] ?? 0) === 1,
                    'descricao'      => $q['description'] ?? '',
                    'opcoes'         => $tipo['opcoes'],
                    'opcoes_valores' => $opcoesValores,
                    'visibilidade'   => [
                        'estrategia' => $estrategia,
                        'condicoes'  => $condArr,
                        'tem_regra'  => $estrategia !== '' && !empty($condArr),
                    ],
                ];
            }

            $secoes[] = [
                'id'        => $secId,
                'uuid'      => $sec['uuid'] ?? '',
                'nome'      => $sec['name'],
                'descricao' => $sec['description'] ?? '',
                'perguntas' => $perguntas,
            ];
        }

        $destItil = PluginCatalogoeformulariosDestino::lerCategoriaEObservadores($formId);

        return [
            'id'                => (int) $f['id'],
            'nome'              => $f['name'],
            'descricao'         => $f['description'] ?? '',
            'header'            => $f['header'] ?? '',
            'ilustracao'        => (string) ($f['illustration'] ?? ''),
            'ativo'             => (int) $f['is_active'] === 1,
            'rascunho'          => (int) ($f['is_draft'] ?? 0) === 1,
            'categoria'         => (int) $f['forms_categories_id'],
            'categoria_itil'    => $destItil['categoria_itil'],
            'observador_grupos' => $destItil['observador_grupos'],
            'entidade'          => (int) $f['entities_id'],
            'recursivo'         => (int) $f['is_recursive'] === 1,
            'secoes'            => $secoes,
            'qtd_destinos'      => self::contar('glpi_forms_destinations_formdestinations', ['forms_forms_id' => $formId]),
            'qtd_acesso'        => self::contar('glpi_forms_accesscontrols_formaccesscontrols', ['forms_forms_id' => $formId, 'is_active' => 1]),
        ];
    }

    /**
     * Alvos disponiveis para condicionais por opcao num formulario:
     * perguntas (uuid + nome + tipo), secoes (uuid + nome) e destinos (id + nome).
     * Usado para popular os seletores de alvo no modal de opcoes.
     */
    public static function getAlvosCondicionais(int $formId): array
    {
        $det = self::getDetalheFormulario($formId);
        if ($det === null) {
            return ['perguntas' => [], 'secoes' => [], 'destinos' => []];
        }

        $perguntas = [];
        $secoes    = [];
        foreach ($det['secoes'] as $sec) {
            $secoes[] = [
                'id'   => (int) $sec['id'],
                'uuid' => (string) ($sec['uuid'] ?? ''),
                'nome' => (string) $sec['nome'],
            ];
            foreach ($sec['perguntas'] as $q) {
                $perguntas[] = [
                    'id'             => (int) $q['id'],
                    'uuid'           => (string) ($q['uuid'] ?? ''),
                    'nome'           => (string) $q['nome'],
                    'tipo_slug'      => (string) $q['tipo_slug'],
                    'opcoes'         => (bool) $q['opcoes'],
                    'opcoes_valores' => (isset($q['opcoes_valores']) && is_array($q['opcoes_valores'])) ? array_values($q['opcoes_valores']) : [],
                    'secao_id'       => (int) $sec['id'],
                ];
            }
        }

        $destinos = [];
        foreach (PluginCatalogoeformulariosDestino::listar($formId) as $d) {
            $destinos[] = [
                'id'         => (int) ($d['id'] ?? 0),
                'nome'       => (string) ($d['nome'] ?? ''),
                'itemtype'   => (string) ($d['itemtype'] ?? ''),
                'tipo_label' => (string) ($d['tipo_label'] ?? ''),
            ];
        }

        return [
            'perguntas' => $perguntas,
            'secoes'    => $secoes,
            'destinos'  => $destinos,
        ];
    }

    /** Descobre o slug/label a partir do fqcn + extra_data gravados. */
    private static function detectarTipo(string $fqcn, string $extra): array
    {
        $extraArr = $extra !== '' ? (json_decode($extra, true) ?: []) : [];
        $slug     = PluginCatalogoeformulariosEstrutura::detectar($fqcn, $extraArr);
        $def      = PluginCatalogoeformulariosEstrutura::mapa()[$slug] ?? null;
        if ($def === null) {
            return ['slug' => '', 'label' => 'Tipo de outro plugin', 'opcoes' => false];
        }
        return ['slug' => $slug, 'label' => $def['label'], 'opcoes' => !empty($def['opcoes'])];
    }

    /** Entidades disponiveis ao usuario para criar formularios. */
    public static function getEntidades(): array
    {
        global $DB;

        $ativas = $_SESSION['glpiactiveentities'] ?? [];
        if (empty($ativas)) {
            return [];
        }

        $saida = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'completename'],
            'FROM'   => 'glpi_entities',
            'WHERE'  => ['id' => $ativas],
            'ORDER'  => 'completename ASC',
        ]) as $row) {
            $saida[] = ['id' => (int) $row['id'], 'nome' => $row['completename']];
        }
        return $saida;
    }

    /** Lista plana de categorias (para selects de categoria pai/destino). */
    public static function getTodasCategorias(): array
    {
        global $DB;

        $saida = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'completename'],
            'FROM'   => 'glpi_forms_categories',
            'ORDER'  => 'completename ASC',
        ]) as $row) {
            $saida[] = ['id' => (int) $row['id'], 'nome' => $row['completename']];
        }
        return $saida;
    }

    /**
     * Lista entidades e grupos do GLPI para o recurso "transformar em categorias".
     * Mostra o completename no seletor (identificacao), mas a categoria sera criada
     * com o name curto. glpi_groups e glpi_entities sao escopados pelas entidades ativas.
     */
    public static function getEntidadesEGruposParaCategorias(): array
    {
        global $DB;

        $ativas = $_SESSION['glpiactiveentities'] ?? [];

        $entidades = [];
        if (!empty($ativas)) {
            foreach ($DB->request([
                'SELECT' => ['id', 'name', 'completename'],
                'FROM'   => 'glpi_entities',
                'WHERE'  => ['id' => $ativas],
                'ORDER'  => 'completename ASC',
            ]) as $row) {
                $entidades[] = [
                    'id'   => (int) $row['id'],
                    'nome' => trim((string) $row['completename']) !== '' ? $row['completename'] : $row['name'],
                ];
            }
        }

        $grupos = [];
        $critGrupos = [
            'SELECT' => ['id', 'name', 'completename'],
            'FROM'   => 'glpi_groups',
            'ORDER'  => 'completename ASC',
        ];
        if (!empty($ativas)) {
            $critGrupos['WHERE'] = ['entities_id' => $ativas];
        }
        foreach ($DB->request($critGrupos) as $row) {
            $grupos[] = [
                'id'   => (int) $row['id'],
                'nome' => trim((string) ($row['completename'] ?? '')) !== '' ? $row['completename'] : $row['name'],
            ];
        }

        return ['entidades' => $entidades, 'grupos' => $grupos];
    }

    /**
     * Cria uma categoria do catalogo para cada entidade/grupo selecionado, usando o
     * name curto de cada um. Os nomes sao buscados no servidor pelos IDs (nao confia
     * no que vem do front). As categorias ficam sob $paiId (nivel atual) e podem ser
     * renomeadas depois normalmente.
     */
    public static function criarCategoriasEmLote(array $entidadeIds, array $grupoIds, int $paiId = 0): array
    {
        global $DB;

        $limpar      = fn(array $a): array => array_values(array_unique(array_filter(array_map('intval', $a), fn($v) => $v > 0)));
        $entidadeIds = $limpar($entidadeIds);
        $grupoIds    = $limpar($grupoIds);

        if (empty($entidadeIds) && empty($grupoIds)) {
            return ['ok' => false, 'msg' => 'Selecione ao menos uma entidade ou grupo.'];
        }

        // Cada item: ['nome' => string, 'entidade' => int]. Categorias vindas de uma
        // ENTIDADE ficam restritas aquela entidade (visiveis apenas para ela). Categorias
        // vindas de GRUPO nao recebem restricao de entidade (grupo nao e entidade).
        $itens = [];

        if (!empty($entidadeIds)) {
            foreach ($DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => 'glpi_entities',
                'WHERE'  => ['id' => $entidadeIds],
            ]) as $row) {
                $n = trim((string) $row['name']);
                if ($n !== '') {
                    $itens[] = ['nome' => $n, 'entidade' => (int) $row['id']];
                }
            }
        }

        if (!empty($grupoIds)) {
            foreach ($DB->request([
                'SELECT' => ['name'],
                'FROM'   => 'glpi_groups',
                'WHERE'  => ['id' => $grupoIds],
            ]) as $row) {
                $n = trim((string) $row['name']);
                if ($n !== '') {
                    $itens[] = ['nome' => $n, 'entidade' => 0];
                }
            }
        }

        if (empty($itens)) {
            return ['ok' => false, 'msg' => 'Nenhum nome valido encontrado.'];
        }

        $criadas = 0;
        foreach ($itens as $item) {
            $dados = [
                'name'                => $item['nome'],
                'forms_categories_id' => $paiId,
                '_disablenotif'       => true,
            ];
            self::aplicarEntidadeNaCategoria($dados, $item['entidade']);

            $cat    = new Category();
            $novoId = $cat->add($dados);
            if ($novoId) {
                $criadas++;
            }
        }

        return $criadas > 0
            ? ['ok' => true, 'msg' => $criadas . ' categoria(s) criada(s).']
            : ['ok' => false, 'msg' => 'Nenhuma categoria foi criada.'];
    }

    // -----------------------------------------------------------------
    // CATEGORIAS (classe nativa \Glpi\Form\Category - CommonTreeDropdown)
    // -----------------------------------------------------------------

    public static function criarCategoria(
        string $nome,
        int $paiId,
        string $descricao = '',
        int $entidade = 0,
        string $ilustracao = ''
    ): array {
        $nome = trim($nome);
        if ($nome === '') {
            return ['ok' => false, 'msg' => 'Informe o nome da categoria.'];
        }

        $dados = [
            'name'                => $nome,
            'forms_categories_id' => $paiId,
            'comment'             => $descricao,
            '_disablenotif'       => true,
        ];

        // Icone nativo do catalogo (campo illustration). Vazio = padrao do GLPI.
        $ilustracao = trim($ilustracao);
        if ($ilustracao !== '' && self::temIlustracao()) {
            $dados['illustration'] = $ilustracao;
        }
        // Categorias novas nascem na entidade padrao da configuracao.
        self::aplicarEntidadeNaCategoria($dados, self::getEntidadePadrao());

        $cat = new Category();
        $id  = $cat->add($dados);

        return $id
            ? ['ok' => true, 'msg' => 'Categoria criada.', 'id' => (int) $id]
            : ['ok' => false, 'msg' => 'Falha ao criar a categoria.'];
    }

    /**
     * Restringe a categoria a uma entidade especifica (visivel apenas para ela),
     * gravando os campos nativos entities_id + is_recursive=0 — desde que a tabela
     * de categorias da versao instalada possua esses campos (entity assignable).
     */
    private static function aplicarEntidadeNaCategoria(array &$dados, int $entidade): void
    {
        global $DB;
        if ($entidade <= 0) {
            return;
        }
        if ($DB->fieldExists('glpi_forms_categories', 'entities_id')) {
            $dados['entities_id'] = $entidade;
            if ($DB->fieldExists('glpi_forms_categories', 'is_recursive')) {
                $dados['is_recursive'] = 0;
            }
        }
    }

    /**
     * Edita a categoria. $descricao e $ilustracao sao opcionais: quando vierem
     * null, os campos correspondentes NAO sao tocados (editar somente o nome nao
     * apaga a descricao nem o icone). Ilustracao como string vazia limpa o icone,
     * voltando ao padrao do GLPI.
     */
    public static function editarCategoria(
        int $id,
        string $nome,
        ?string $descricao = null,
        ?int $paiId = null,
        ?string $ilustracao = null
    ): array {
        $nome = trim($nome);
        if ($id <= 0 || $nome === '') {
            return ['ok' => false, 'msg' => 'Dados invalidos.'];
        }

        $dados = ['id' => $id, 'name' => $nome, '_disablenotif' => true];
        if ($descricao !== null) {
            $dados['comment'] = $descricao;
        }
        if ($ilustracao !== null && self::temIlustracao()) {
            $dados['illustration'] = trim($ilustracao);
        }
        if ($paiId !== null) {
            $dados['forms_categories_id'] = $paiId;
        }

        $cat = new Category();
        return $cat->update($dados)
            ? ['ok' => true, 'msg' => 'Categoria atualizada.']
            : ['ok' => false, 'msg' => 'Falha ao atualizar a categoria.'];
    }

    /**
     * Exclui a categoria EM CASCATA: remove recursivamente todas as subcategorias
     * e todos os formularios contidos (em qualquer profundidade) antes de remover
     * a propria categoria. O aviso ao usuario e dado no front.
     */
    public static function excluirCategoria(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'msg' => 'Categoria invalida.'];
        }

        self::excluirCategoriaRec($id);

        return ['ok' => true, 'msg' => 'Categoria e todo o conteudo dentro dela foram excluidos.'];
    }

    /** Recursao da exclusao: primeiro os filhos (formularios + subcategorias), depois a categoria. */
    private static function excluirCategoriaRec(int $id): void
    {
        global $DB;

        // 1) Subcategorias (recursao em profundidade).
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_forms_categories',
            'WHERE'  => ['forms_categories_id' => $id],
        ]) as $sub) {
            self::excluirCategoriaRec((int) $sub['id']);
        }

        // 2) Formularios diretos desta categoria.
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_forms_forms',
            'WHERE'  => ['forms_categories_id' => $id, 'is_deleted' => 0],
        ]) as $f) {
            self::excluirFormulario((int) $f['id']);
        }

        // 3) A propria categoria (agora vazia).
        $cat = new Category();
        $cat->delete(['id' => $id], true);
    }

    /** Move uma categoria para outro pai (drag-and-drop). update() nativo recalcula completename/level. */
    public static function moverCategoria(int $id, int $novoPaiId): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'msg' => 'Categoria invalida.'];
        }
        if ($id === $novoPaiId) {
            return ['ok' => false, 'msg' => 'Uma categoria nao pode ser pai dela mesma.'];
        }
        $cat = new Category();
        return $cat->update(['id' => $id, 'forms_categories_id' => $novoPaiId, '_disablenotif' => true])
            ? ['ok' => true, 'msg' => 'Categoria movida.']
            : ['ok' => false, 'msg' => 'Falha ao mover a categoria.'];
    }

    // -----------------------------------------------------------------
    // FORMULARIOS (classe nativa \Glpi\Form\Form)
    // -----------------------------------------------------------------

    public static function criarFormulario(array $d): array
    {
        $nome = trim($d['nome'] ?? '');
        if ($nome === '') {
            return ['ok' => false, 'msg' => 'Informe o nome do formulario.'];
        }

        // Formularios novos nascem na entidade padrao da configuracao.
        $entidade = self::getEntidadePadrao();

        // Sem desligar os _init_*, o GLPI cria sozinho a 1a secao, o destino de
        // Ticket padrao e as politicas de acesso padrao (comportamento nativo desejado).
        $form = new Form();
        $novo = [
            'name'                => $nome,
            'description'         => $d['descricao'] ?? '',
            'header'              => $d['header'] ?? '',
            'entities_id'         => $entidade,
            'is_recursive'        => isset($d['recursivo']) ? (int) $d['recursivo'] : 1,
            'is_active'           => isset($d['ativo']) ? (int) $d['ativo'] : 0,
            'is_draft'            => 0,
            'forms_categories_id' => (int) ($d['categoria'] ?? 0),
            'render_layout'       => 'step_by_step',
        ];
        $ilustracao = self::limparIlustracao($d['ilustracao'] ?? '');
        if ($ilustracao !== '' && self::formTemIlustracao()) {
            $novo['illustration'] = $ilustracao;
        }
        $id = $form->add($novo);

        if (!$id) {
            return ['ok' => false, 'msg' => 'Falha ao criar o formulario.'];
        }

        // Categoria ITIL e grupos observadores no destino de Ticket padrao do formulario.
        $catItil   = (int) ($d['categoria_itil'] ?? 0);
        $gruposObs = array_values(array_filter(array_map('intval', $d['observador_grupos'] ?? [])));
        if ($catItil > 0 || !empty($gruposObs)) {
            PluginCatalogoeformulariosDestino::aplicarCategoriaEObservadores((int) $id, $catItil, $gruposObs);
        }

        // Controle de acesso definido na criacao: se vier qualquer perfil/usuario/grupo,
        // aplica AllowList restrita (desmarca "todos"). Caso contrario mantem o padrao nativo.
        $perfis   = array_map('intval', $d['ac_perfis']   ?? []);
        $usuarios = array_map('intval', $d['ac_usuarios'] ?? []);
        $grupos   = array_map('intval', $d['ac_grupos']   ?? []);
        if (!empty($perfis) || !empty($usuarios) || !empty($grupos)) {
            PluginCatalogoeformulariosAcesso::salvarAllowList((int) $id, [
                'todos'    => false,
                'perfis'   => $perfis,
                'usuarios' => $usuarios,
                'grupos'   => $grupos,
                'ativo'    => true,
            ]);
        }

        return ['ok' => true, 'msg' => 'Formulario criado.', 'id' => (int) $id];
    }

    public static function editarFormulario(int $id, array $d): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'msg' => 'Formulario invalido.'];
        }

        $dados = ['id' => $id];
        if (isset($d['nome'])) {
            $nome = trim($d['nome']);
            if ($nome === '') {
                return ['ok' => false, 'msg' => 'Informe o nome do formulario.'];
            }
            $dados['name'] = $nome;
        }
        if (array_key_exists('descricao', $d)) {
            $dados['description'] = $d['descricao'];
        }
        if (array_key_exists('header', $d)) {
            $dados['header'] = $d['header'];
        }
        if (isset($d['categoria'])) {
            $dados['forms_categories_id'] = (int) $d['categoria'];
        }
        if (isset($d['recursivo'])) {
            $dados['is_recursive'] = (int) $d['recursivo'];
        }
        if (array_key_exists('ilustracao', $d) && self::formTemIlustracao()) {
            $dados['illustration'] = self::limparIlustracao($d['ilustracao']);
        }

        $form = new Form();
        $ok   = $form->update($dados);

        if ($ok) {
            $catItil = array_key_exists('categoria_itil', $d) ? (int) $d['categoria_itil'] : null;
            $grupos  = array_key_exists('observador_grupos', $d)
                ? array_values(array_map('intval', (array) $d['observador_grupos']))
                : null;
            if ($catItil !== null || $grupos !== null) {
                PluginCatalogoeformulariosDestino::editarDestinoCategoriaObservadores($id, $catItil, $grupos);
            }
        }

        return $ok
            ? ['ok' => true, 'msg' => 'Formulario atualizado.']
            : ['ok' => false, 'msg' => 'Falha ao atualizar o formulario.'];
    }

    /** O formulario nativo tem a coluna de icone (ilustracao)? */
    private static function formTemIlustracao(): bool
    {
        global $DB;
        static $tem = null;
        return $tem ??= (bool) $DB->fieldExists('glpi_forms_forms', 'illustration');
    }

    /** Id de ilustracao seguro: ids nativos (letras, numeros, - e _) ou enviados pelo usuario ("custom:arquivo.png"). */
    private static function limparIlustracao($v): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_.:\-]/', '', (string) $v);
    }

    /**
     * Interliga (vincula) um formulario a uma categoria de catalogo.
     * E exatamente a vinculacao nativa: grava forms_categories_id no formulario.
     */
    public static function vincularCategoria(int $formId, int $categoriaId): array
    {
        if ($formId <= 0) {
            return ['ok' => false, 'msg' => 'Formulario invalido.'];
        }
        $form = new Form();
        return $form->update(['id' => $formId, 'forms_categories_id' => $categoriaId])
            ? ['ok' => true, 'msg' => $categoriaId > 0 ? 'Formulario vinculado a categoria.' : 'Formulario movido para a raiz.']
            : ['ok' => false, 'msg' => 'Falha ao vincular o formulario.'];
    }

    /**
     * Ativa/desativa o formulario e devolve o estado REAL gravado, para o front
     * atualizar o icone na hora sem depender de recarregar a pagina.
     */
    public static function alternarAtivoFormulario(int $id, bool $ativo): array
    {
        global $DB;

        if ($id <= 0) {
            return ['ok' => false, 'msg' => 'Formulario invalido.', 'ativo' => false, 'rascunho' => false];
        }

        $dados = ['id' => $id, 'is_active' => $ativo ? 1 : 0];
        // Rascunho nao aparece no catalogo: ativar tambem tira o formulario de rascunho.
        if ($ativo) {
            $dados['is_draft'] = 0;
        }

        $form = new Form();
        $form->update($dados);

        $estado = self::lerEstadoFormulario($id);

        // Se a camada de objetos nao persistiu, grava direto na tabela nativa.
        if ($estado['ativo'] !== $ativo) {
            $direto = ['is_active' => $ativo ? 1 : 0];
            if ($ativo) {
                $direto['is_draft'] = 0;
            }
            $DB->update('glpi_forms_forms', $direto, ['id' => $id]);
            $estado = self::lerEstadoFormulario($id);
        }

        if ($estado['ativo'] !== $ativo) {
            return [
                'ok'       => false,
                'msg'      => 'Falha ao alterar o status.',
                'ativo'    => $estado['ativo'],
                'rascunho' => $estado['rascunho'],
            ];
        }

        return [
            'ok'       => true,
            'msg'      => $ativo ? 'Formulario ativado.' : 'Formulario desativado.',
            'ativo'    => $estado['ativo'],
            'rascunho' => $estado['rascunho'],
        ];
    }

    /** Le is_active / is_draft atuais de um formulario. */
    public static function lerEstadoFormulario(int $id): array
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['is_active', 'is_draft'],
            'FROM'   => 'glpi_forms_forms',
            'WHERE'  => ['id' => $id],
            'LIMIT'  => 1,
        ])->current();

        return [
            'ativo'    => $row !== null && (int) $row['is_active'] === 1,
            'rascunho' => $row !== null && (int) ($row['is_draft'] ?? 0) === 1,
        ];
    }

    public static function excluirFormulario(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'msg' => 'Formulario invalido.'];
        }
        $form = new Form();
        // Force purge para remover de vez do catalogo.
        if ($form->delete(['id' => $id], true)) {
            return ['ok' => true, 'msg' => 'Formulario excluido.'];
        }
        // Fallback: lixeira (caso haja respostas vinculadas).
        return $form->delete(['id' => $id])
            ? ['ok' => true, 'msg' => 'Formulario enviado para a lixeira (possui respostas vinculadas).']
            : ['ok' => false, 'msg' => 'Falha ao excluir o formulario.'];
    }

    /**
     * Cria um formulario para cada categoria ITIL selecionada. Cada formulario:
     *  - recebe o name curto da categoria ITIL (buscado no servidor pelo id);
     *  - fica sob a categoria de catalogo atual ($catalogoCategoriaId);
     *  - tem o destino Ticket nativo configurado para abrir o chamado NAQUELA
     *    categoria ITIL (estrategia "especifico").
     * Os nomes podem ser editados depois normalmente.
     */
    public static function criarFormulariosDeCategoriasItil(array $itilIds, int $catalogoCategoriaId = 0): array
    {
        global $DB;

        $itilIds = array_values(array_unique(array_filter(array_map('intval', $itilIds), fn($v) => $v > 0)));
        if (empty($itilIds)) {
            return ['ok' => false, 'msg' => 'Selecione ao menos uma categoria ITIL.'];
        }

        // Nomes das categorias ITIL pelos IDs (nao confia no que vem do front).
        $nomes = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => 'glpi_itilcategories',
            'WHERE'  => ['id' => $itilIds],
        ]) as $row) {
            $n = trim((string) $row['name']);
            if ($n !== '') {
                $nomes[(int) $row['id']] = $n;
            }
        }

        if (empty($nomes)) {
            return ['ok' => false, 'msg' => 'Nenhuma categoria ITIL valida encontrada.'];
        }

        $criados  = 0;
        $semCateg = 0;
        foreach ($nomes as $itilId => $nome) {
            $res = self::criarFormulario([
                'nome'      => $nome,
                'categoria' => $catalogoCategoriaId,
                'recursivo' => 1,
                'ativo'     => 1,
            ]);
            if (empty($res['ok']) || empty($res['id'])) {
                continue;
            }
            $formId = (int) $res['id'];

            // Garante que o formulario fique DENTRO da categoria do catalogo escolhida.
            // (Alguns fluxos do GLPI nao aplicam forms_categories_id no add inicial,
            //  deixando o formulario na raiz; o update abaixo corrige isso.)
            if ($catalogoCategoriaId > 0) {
                $formObj = new Form();
                $formObj->update([
                    'id'                  => $formId,
                    'forms_categories_id' => $catalogoCategoriaId,
                    '_disablenotif'       => true,
                ]);
            }

            // Destino Ticket auto-criado pelo GLPI -> define a categoria ITIL.
            $destinoId = self::primeiroDestinoTicket($formId);
            if ($destinoId > 0) {
                $r = PluginCatalogoeformulariosDestino::definirCampo($destinoId, 'categoria', 'especifico', $itilId);
                if (empty($r['ok'])) {
                    $semCateg++;
                }
            } else {
                $semCateg++;
            }
            $criados++;
        }

        if ($criados === 0) {
            return ['ok' => false, 'msg' => 'Nenhum formulario foi criado.'];
        }

        $msg = $criados . ' formulario(s) criado(s).';
        if ($semCateg > 0) {
            $msg .= ' ' . $semCateg . ' sem a categoria ITIL aplicada automaticamente (ajuste pelo editor de destinos).';
        }
        return ['ok' => true, 'msg' => $msg];
    }

    /** Retorna o id do primeiro destino do tipo Ticket de um formulario (0 se nao houver). */
    private static function primeiroDestinoTicket(int $formId): int
    {
        foreach (PluginCatalogoeformulariosDestino::listar($formId) as $d) {
            if (stripos((string) ($d['itemtype'] ?? ''), 'FormDestinationTicket') !== false) {
                return (int) $d['id'];
            }
        }
        return 0;
    }

    // -----------------------------------------------------------------
    // SECOES (classe nativa \Glpi\Form\Section)
    // -----------------------------------------------------------------

    public static function criarSecao(int $formId, string $nome, string $descricao = ''): array
    {
        $nome = trim($nome);
        if ($formId <= 0 || $nome === '') {
            return ['ok' => false, 'msg' => 'Dados invalidos.'];
        }

        $rank = self::contar('glpi_forms_sections', ['forms_forms_id' => $formId]);

        $sec = new Section();
        $id  = $sec->add([
            'forms_forms_id' => $formId,
            'name'           => $nome,
            'description'    => $descricao,
            'rank'           => $rank,
        ]);

        return $id
            ? ['ok' => true, 'msg' => 'Secao criada.', 'id' => (int) $id]
            : ['ok' => false, 'msg' => 'Falha ao criar a secao.'];
    }

    public static function editarSecao(int $id, string $nome, string $descricao = ''): array
    {
        $nome = trim($nome);
        if ($id <= 0 || $nome === '') {
            return ['ok' => false, 'msg' => 'Dados invalidos.'];
        }
        $sec = new Section();
        return $sec->update(['id' => $id, 'name' => $nome, 'description' => $descricao])
            ? ['ok' => true, 'msg' => 'Secao atualizada.']
            : ['ok' => false, 'msg' => 'Falha ao atualizar a secao.'];
    }

    public static function excluirSecao(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'msg' => 'Secao invalida.'];
        }
        $sec = new Section();
        return $sec->delete(['id' => $id], true)
            ? ['ok' => true, 'msg' => 'Secao excluida.']
            : ['ok' => false, 'msg' => 'Falha ao excluir a secao.'];
    }

    /** Reordena as secoes de um formulario conforme a lista de ids (drag-and-drop). */
    public static function reordenarSecoes(int $formId, array $idsOrdenados): array
    {
        if ($formId <= 0) {
            return ['ok' => false, 'msg' => 'Formulario invalido.'];
        }
        $sec  = new Section();
        $rank = 0;
        foreach ($idsOrdenados as $sid) {
            $sid = (int) $sid;
            if ($sid <= 0) {
                continue;
            }
            $sec->update(['id' => $sid, 'rank' => $rank]);
            $rank++;
        }
        return ['ok' => true, 'msg' => 'Ordem das secoes atualizada.'];
    }

    // -----------------------------------------------------------------
    // PERGUNTAS (classe nativa \Glpi\Form\Question)
    // -----------------------------------------------------------------

    /** Cria uma pergunta (mesma logica do editor do acordeao: tipos nativos, chaves das opcoes preservadas). */
    public static function criarPergunta(int $secaoId, array $d): array
    {
        return PluginCatalogoeformulariosEstrutura::salvarPergunta(['secao' => $secaoId, 'id' => 0] + $d);
    }

    /** Atualiza uma pergunta preservando as chaves das opcoes, a configuracao do tipo e o valor padrao. */
    public static function editarPergunta(int $id, array $d): array
    {
        return PluginCatalogoeformulariosEstrutura::salvarPergunta(['id' => $id] + $d);
    }

    public static function excluirPergunta(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'msg' => 'Pergunta invalida.'];
        }
        $q = new Question();
        return $q->delete(['id' => $id], true)
            ? ['ok' => true, 'msg' => 'Pergunta excluida.']
            : ['ok' => false, 'msg' => 'Falha ao excluir a pergunta.'];
    }

    /** Reordena as perguntas de uma secao conforme a lista de ids (drag-and-drop). */
    public static function reordenarPerguntas(int $secaoId, array $idsOrdenados): array
    {
        if ($secaoId <= 0) {
            return ['ok' => false, 'msg' => 'Secao invalida.'];
        }
        $q    = new Question();
        $rank = 0;
        foreach ($idsOrdenados as $qid) {
            $qid = (int) $qid;
            if ($qid <= 0) {
                continue;
            }
            $q->update(['id' => $qid, 'vertical_rank' => $rank]);
            $rank++;
        }
        return ['ok' => true, 'msg' => 'Ordem das perguntas atualizada.'];
    }

    /** Move uma pergunta para outra secao e reordena a secao de destino. */
    public static function moverPergunta(int $perguntaId, int $novaSecaoId, array $idsDestino = []): array
    {
        if ($perguntaId <= 0 || $novaSecaoId <= 0) {
            return ['ok' => false, 'msg' => 'Dados invalidos.'];
        }
        $q = new Question();
        if (!$q->update(['id' => $perguntaId, 'forms_sections_id' => $novaSecaoId])) {
            return ['ok' => false, 'msg' => 'Falha ao mover a pergunta.'];
        }
        if (!empty($idsDestino)) {
            self::reordenarPerguntas($novaSecaoId, $idsDestino);
        }
        return ['ok' => true, 'msg' => 'Pergunta movida.'];
    }

    /** Conta linhas de uma tabela com WHERE simples (seguro se a tabela nao existir). */
    private static function contar(string $tabela, array $where): int
    {
        global $DB;
        if (!$DB->tableExists($tabela)) {
            return 0;
        }
        $row = $DB->request(['COUNT' => 'total', 'FROM' => $tabela, 'WHERE' => $where])->current();
        return (int) ($row['total'] ?? 0);
    }

    // -----------------------------------------------------------------
    // DUPLICACAO
    // -----------------------------------------------------------------

    /**
     * Duplica um formulario completo: dados, secoes, perguntas, destinos e acesso.
     * O novo formulario fica na MESMA categoria, com sufixo " (copia)" no nome.
     */
    public static function duplicarFormulario(int $formId, ?int $categoriaDestino = null): array
    {
        global $DB;

        $f = $DB->request([
            'FROM'  => 'glpi_forms_forms',
            'WHERE' => ['id' => $formId, 'is_deleted' => 0],
            'LIMIT' => 1,
        ])->current();
        if ($f === null) {
            return ['ok' => false, 'msg' => 'Formulario nao encontrado.'];
        }

        $catDest = $categoriaDestino !== null ? (int) $categoriaDestino : (int) $f['forms_categories_id'];

        // 1) Cria o novo formulario (sem deixar o GLPI criar secao/destino padrao,
        //    pois vamos copiar tudo manualmente do original).
        $form  = new Form();
        $novoId = $form->add([
            'name'                => trim((string) $f['name']) . ' (copia)',
            'description'         => $f['description'] ?? '',
            'header'              => $f['header'] ?? '',
            'entities_id'         => self::getEntidadePadrao(),
            'is_recursive'        => (int) ($f['is_recursive'] ?? 1),
            'is_active'           => 0,        // copia nasce inativa
            'is_draft'            => (int) ($f['is_draft'] ?? 0),
            'forms_categories_id' => $catDest,
            'render_layout'       => $f['render_layout'] ?? 'step_by_step',
            '_do_not_init_sections' => true,
        ]);
        if (!$novoId) {
            return ['ok' => false, 'msg' => 'Falha ao duplicar o formulario.'];
        }
        $novoId = (int) $novoId;

        // Remove secoes/destinos que o GLPI possa ter criado automaticamente,
        // para nao duplicar (a copia deve refletir exatamente o original).
        self::limparFilhosFormulario($novoId);

        // 2) Copia secoes + perguntas.
        self::copiarSecoesEPerguntas($formId, $novoId);

        // 3) Copia destinos.
        self::copiarDestinos($formId, $novoId);

        // 4) Copia controle de acesso (AllowList + DirectAccess).
        self::copiarAcesso($formId, $novoId);

        return ['ok' => true, 'msg' => 'Formulario duplicado.', 'id' => $novoId];
    }

    /** Remove todas as secoes (e suas perguntas) e destinos de um formulario. */
    private static function limparFilhosFormulario(int $formId): void
    {
        global $DB;

        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_forms_sections', 'WHERE' => ['forms_forms_id' => $formId]]) as $s) {
            $sec = new Section();
            $sec->delete(['id' => (int) $s['id']], true);
        }
        if ($DB->tableExists('glpi_forms_destinations_formdestinations')) {
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_forms_destinations_formdestinations', 'WHERE' => ['forms_forms_id' => $formId]]) as $d) {
                $DB->delete('glpi_forms_destinations_formdestinations', ['id' => (int) $d['id']]);
            }
        }
    }

    /** Copia todas as secoes e perguntas de um formulario para outro. */
    private static function copiarSecoesEPerguntas(int $origemFormId, int $destinoFormId): void
    {
        global $DB;

        foreach ($DB->request([
            'FROM'  => 'glpi_forms_sections',
            'WHERE' => ['forms_forms_id' => $origemFormId],
            'ORDER' => 'rank ASC',
        ]) as $s) {
            $sec    = new Section();
            $novaSec = $sec->add([
                'forms_forms_id' => $destinoFormId,
                'name'           => $s['name'] ?? '',
                'description'    => $s['description'] ?? '',
                'rank'           => (int) ($s['rank'] ?? 0),
            ]);
            if (!$novaSec) {
                continue;
            }

            foreach ($DB->request([
                'FROM'  => 'glpi_forms_questions',
                'WHERE' => ['forms_sections_id' => (int) $s['id']],
                'ORDER' => 'vertical_rank ASC',
            ]) as $q) {
                $input = [
                    'forms_sections_id' => (int) $novaSec,
                    'name'              => $q['name'] ?? '',
                    'type'              => $q['type'] ?? '',
                    'is_mandatory'      => (int) ($q['is_mandatory'] ?? 0),
                    'description'       => $q['description'] ?? '',
                    'default_value'     => $q['default_value'] ?? '',
                    'horizontal_rank'   => $q['horizontal_rank'] ?? null,
                    'vertical_rank'     => (int) ($q['vertical_rank'] ?? 0),
                ];
                if (isset($q['extra_data']) && $q['extra_data'] !== null && $q['extra_data'] !== '') {
                    $input['extra_data'] = $q['extra_data'];
                }
                if (isset($q['visibility_strategy'])) {
                    $input['visibility_strategy'] = $q['visibility_strategy'];
                }
                if (isset($q['conditions']) && $q['conditions'] !== null && $q['conditions'] !== '') {
                    $input['conditions'] = $q['conditions'];
                }
                $qObj = new Question();
                $qObj->add($input);
            }
        }
    }

    /** Copia os destinos (linha bruta) de um formulario para outro. */
    private static function copiarDestinos(int $origemFormId, int $destinoFormId): void
    {
        global $DB;

        if (!$DB->tableExists('glpi_forms_destinations_formdestinations')) {
            return;
        }
        foreach ($DB->request([
            'FROM'  => 'glpi_forms_destinations_formdestinations',
            'WHERE' => ['forms_forms_id' => $origemFormId],
            'ORDER' => 'id ASC',
        ]) as $d) {
            $dados = $d;
            unset($dados['id']);
            $dados['forms_forms_id'] = $destinoFormId;
            $DB->insert('glpi_forms_destinations_formdestinations', $dados);
        }
    }

    /** Copia as politicas de acesso de um formulario para outro (UPDATE se ja existir a strategy, senao INSERT). */
    private static function copiarAcesso(int $origemFormId, int $destinoFormId): void
    {
        global $DB;

        $tabela = 'glpi_forms_accesscontrols_formaccesscontrols';
        if (!$DB->tableExists($tabela)) {
            return;
        }

        foreach ($DB->request([
            'FROM'  => $tabela,
            'WHERE' => ['forms_forms_id' => $origemFormId],
        ]) as $a) {
            $dados = $a;
            unset($dados['id']);
            $dados['forms_forms_id'] = $destinoFormId;

            // O GLPI ja cria uma politica padrao por strategy ao criar o formulario.
            // Para nao violar o indice unico (forms_forms_id + strategy), atualiza a
            // existente; se nao houver, insere uma nova.
            $existente = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => $tabela,
                'WHERE'  => [
                    'forms_forms_id' => $destinoFormId,
                    'strategy'       => $dados['strategy'] ?? '',
                ],
                'LIMIT'  => 1,
            ])->current();

            if ($existente !== null) {
                $upd = $dados;
                unset($upd['forms_forms_id'], $upd['strategy']); // chaves do indice nao mudam
                $DB->update($tabela, $upd, ['id' => (int) $existente['id']]);
            } else {
                $DB->insert($tabela, $dados);
            }
        }
    }

    /**
     * Duplica uma categoria recursivamente: cria a copia sob o mesmo pai (com
     * sufixo " (copia)"), duplica os formularios diretos e desce nas subcategorias.
     * Apenas a categoria de topo recebe o sufixo; subcategorias mantem o nome.
     */
    public static function duplicarCategoria(int $categoriaId): array
    {
        global $DB;

        $cat = $DB->request([
            'FROM'  => 'glpi_forms_categories',
            'WHERE' => ['id' => $categoriaId],
            'LIMIT' => 1,
        ])->current();
        if ($cat === null) {
            return ['ok' => false, 'msg' => 'Categoria nao encontrada.'];
        }

        $novoId = self::duplicarCategoriaRec($categoriaId, (int) $cat['forms_categories_id'], true);
        return $novoId
            ? ['ok' => true, 'msg' => 'Categoria duplicada com toda a estrutura.', 'id' => $novoId]
            : ['ok' => false, 'msg' => 'Falha ao duplicar a categoria.'];
    }

    /** Recursao da duplicacao de categoria. Retorna o id da nova categoria criada. */
    private static function duplicarCategoriaRec(int $origemCatId, int $novoPaiId, bool $topo): int
    {
        global $DB;

        $cat = $DB->request([
            'FROM'  => 'glpi_forms_categories',
            'WHERE' => ['id' => $origemCatId],
            'LIMIT' => 1,
        ])->current();
        if ($cat === null) {
            return 0;
        }

        $nome = trim((string) $cat['name']) . ($topo ? ' (copia)' : '');
        $dados = [
            'name'                => $nome,
            'forms_categories_id' => $novoPaiId,
            'comment'             => $cat['comment'] ?? '',
            '_disablenotif'       => true,
        ];
        self::aplicarEntidadeNaCategoria($dados, self::getEntidadePadrao());

        $catObj = new Category();
        $novaCat = $catObj->add($dados);
        if (!$novaCat) {
            return 0;
        }
        $novaCat = (int) $novaCat;

        // Formularios diretos desta categoria.
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_forms_forms',
            'WHERE'  => ['forms_categories_id' => $origemCatId, 'is_deleted' => 0],
        ]) as $f) {
            self::duplicarFormularioParaCategoria((int) $f['id'], $novaCat);
        }

        // Subcategorias (recursao).
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_forms_categories',
            'WHERE'  => ['forms_categories_id' => $origemCatId],
        ]) as $sub) {
            self::duplicarCategoriaRec((int) $sub['id'], $novaCat, false);
        }

        return $novaCat;
    }

    /** Duplica um formulario forcando a categoria de destino e mantendo o nome original (sem sufixo). */
    private static function duplicarFormularioParaCategoria(int $formId, int $categoriaDestino): void
    {
        global $DB;

        $f = $DB->request([
            'FROM'  => 'glpi_forms_forms',
            'WHERE' => ['id' => $formId, 'is_deleted' => 0],
            'LIMIT' => 1,
        ])->current();
        if ($f === null) {
            return;
        }

        $form  = new Form();
        $novoId = $form->add([
            'name'                  => (string) $f['name'], // dentro de categoria copiada mantem o nome
            'description'           => $f['description'] ?? '',
            'header'                => $f['header'] ?? '',
            'entities_id'           => self::getEntidadePadrao(),
            'is_recursive'          => (int) ($f['is_recursive'] ?? 1),
            'is_active'             => (int) ($f['is_active'] ?? 0),
            'is_draft'              => (int) ($f['is_draft'] ?? 0),
            'forms_categories_id'   => $categoriaDestino,
            'render_layout'         => $f['render_layout'] ?? 'step_by_step',
            '_do_not_init_sections' => true,
        ]);
        if (!$novoId) {
            return;
        }
        $novoId = (int) $novoId;
        self::limparFilhosFormulario($novoId);
        self::copiarSecoesEPerguntas($formId, $novoId);
        self::copiarDestinos($formId, $novoId);
        self::copiarAcesso($formId, $novoId);
    }

    // -----------------------------------------------------------------
    // PERFIS QUE VISUALIZAM (formulario / categoria)
    //
    // No GLPI 11 a visibilidade por perfil existe apenas no nivel do
    // FORMULARIO (politica AllowList em glpi_forms_accesscontrols_formaccesscontrols).
    // A categoria nao tem acesso proprio: os perfis dela sao a UNIAO dos
    // perfis dos formularios diretos contidos nela.
    //
    // Estados especiais que NAO listam perfis individuais:
    //  - aberto = true  : algum form e visivel a todos (user_ids=["all"]
    //                     ou acesso direto/publico ativo) => "todos os perfis".
    //  - quando nenhum form da categoria tem politica ativa de restricao por
    //    perfil e nenhum esta aberto => lista de perfis vazia + aberto=false.
    // -----------------------------------------------------------------

    /**
     * Perfis que conseguem visualizar UM formulario.
     *
     * Retorna:
     *  [
     *    'aberto'  => bool,                 // visivel a todos (sem restricao de perfil)
     *    'perfis'  => [ ['id'=>int,'nome'=>string], ... ],
     *    'total'   => int,                  // qtd de perfis listados
     *  ]
     */
    public static function getPerfisDoFormulario(int $formId): array
    {
        global $DB;

        $vazio = ['aberto' => false, 'perfis' => [], 'total' => 0];
        if ($formId <= 0 || !$DB->tableExists('glpi_forms_accesscontrols_formaccesscontrols')) {
            return $vazio;
        }

        $allowlist = ltrim('Glpi\\Form\\AccessControl\\ControlType\\AllowList', '\\');

        $aberto    = false;
        $perfilIds = [];

        foreach ($DB->request([
            'SELECT' => ['strategy', 'config', 'is_active'],
            'FROM'   => 'glpi_forms_accesscontrols_formaccesscontrols',
            'WHERE'  => ['forms_forms_id' => $formId, 'is_active' => 1],
        ]) as $row) {
            $strategy = ltrim((string) ($row['strategy'] ?? ''), '\\');
            $cfg      = $row['config'] ?? [];
            if (is_string($cfg)) {
                $cfg = json_decode($cfg, true) ?: [];
            }

            // Apenas a politica AllowList define visibilidade por PERFIL.
            // DirectAccess (acesso por link/token) e uma via separada e NAO significa
            // "todos os perfis veem no catalogo", por isso e ignorado aqui.
            if ($strategy === $allowlist) {
                $uids = $cfg['user_ids'] ?? [];
                // "todos" so quando user_ids contem o marcador 'all' (string).
                if (in_array('all', $uids, true)) {
                    $aberto = true; // visivel a todos os usuarios autenticados
                }
                foreach (($cfg['profile_ids'] ?? []) as $pid) {
                    $pid = (int) $pid;
                    if ($pid > 0) {
                        $perfilIds[$pid] = true;
                    }
                }
            }
        }

        if ($aberto) {
            // Quando aberto, perfis individuais sao irrelevantes para a UI.
            return ['aberto' => true, 'perfis' => [], 'total' => 0];
        }

        return [
            'aberto' => false,
            'perfis' => self::nomearPerfis(array_keys($perfilIds)),
            'total'  => count($perfilIds),
        ];
    }

    /**
     * Perfis que conseguem visualizar UMA categoria (uniao dos perfis dos
     * formularios diretos, ativos e nao excluidos, contidos nela).
     *
     * Retorna o mesmo formato de getPerfisDoFormulario(), acrescido de
     * 'qtd_forms' (formularios considerados no calculo).
     */
    public static function getPerfisDaCategoria(int $categoriaId): array
    {
        global $DB;

        $formIds = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_forms_forms',
            'WHERE'  => ['forms_categories_id' => $categoriaId, 'is_deleted' => 0],
        ]) as $row) {
            $formIds[] = (int) $row['id'];
        }

        if (empty($formIds)) {
            return ['aberto' => false, 'perfis' => [], 'total' => 0, 'qtd_forms' => 0];
        }

        $aberto    = false;
        $perfilIds = [];
        foreach ($formIds as $fid) {
            $r = self::getPerfisDoFormulario($fid);
            if ($r['aberto']) {
                $aberto = true;
            }
            foreach ($r['perfis'] as $p) {
                $perfilIds[(int) $p['id']] = true;
            }
        }

        if ($aberto) {
            return ['aberto' => true, 'perfis' => [], 'total' => 0, 'qtd_forms' => count($formIds)];
        }

        return [
            'aberto'    => false,
            'perfis'    => self::nomearPerfis(array_keys($perfilIds)),
            'total'     => count($perfilIds),
            'qtd_forms' => count($formIds),
        ];
    }

    /**
     * Resolve nomes de perfis a partir dos IDs, em ordem alfabetica.
     * glpi_profiles NAO possui is_deleted, por isso nao filtramos por ele.
     *
     * @param int[] $ids
     * @return array<int, array{id:int,nome:string}>
     */
    private static function nomearPerfis(array $ids): array
    {
        global $DB;

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (empty($ids)) {
            return [];
        }

        $perfis = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => 'glpi_profiles',
            'WHERE'  => ['id' => $ids],
            'ORDER'  => 'name ASC',
        ]) as $row) {
            $perfis[] = ['id' => (int) $row['id'], 'nome' => (string) $row['name']];
        }
        return $perfis;
    }
}
