<?php

/**
 * Nucleo do pacote de exportacao/importacao do catalogo de servicos.
 * Facilitador estatico, sem tabela propria.
 */
class PluginCatalogoeformulariosPacote extends CommonGLPI
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

    /** Versao do formato do arquivo. */
    public const FORMATO = 2;

    private const PREFIXO_TMP = 'catalogoeformularios_pacote_';

    /** Acoes aceitas na resolucao de conflitos da importacao. */
    public const ACOES = ['reutilizar', 'apontar', 'novo', 'substituir', 'ignorar'];

    // -----------------------------------------------------------------
    // Tipos de dependencia
    // -----------------------------------------------------------------

    /**
     * Catalogo de tipos na ordem de processamento. 'criavel' indica que o
     * pacote sabe recriar o item no destino quando o usuario autorizar.
     */
    public static function todosTipos(): array
    {
        return [
            'Entity'         => ['label' => 'Entidades',              'tabela' => 'glpi_entities',        'arvore' => true,  'criavel' => true,  'icone' => 'ti ti-building'],
            'Profile'        => ['label' => 'Perfis',                 'tabela' => 'glpi_profiles',        'arvore' => false, 'criavel' => true,  'icone' => 'ti ti-shield'],
            'Calendar'       => ['label' => 'Calendarios',            'tabela' => 'glpi_calendars',       'arvore' => false, 'criavel' => true,  'icone' => 'ti ti-calendar'],
            'SLM'            => ['label' => 'Acordos de nivel (SLM)', 'tabela' => 'glpi_slms',            'arvore' => false, 'criavel' => true,  'icone' => 'ti ti-file-certificate'],
            'SLA'            => ['label' => 'SLAs',                   'tabela' => 'glpi_slas',            'arvore' => false, 'criavel' => true,  'icone' => 'ti ti-clock'],
            'OLA'            => ['label' => 'OLAs',                   'tabela' => 'glpi_olas',            'arvore' => false, 'criavel' => true,  'icone' => 'ti ti-clock'],
            'RequestType'    => ['label' => 'Origens da requisicao',  'tabela' => 'glpi_requesttypes',    'arvore' => false, 'criavel' => true,  'icone' => 'ti ti-flag'],
            'Location'       => ['label' => 'Localizacoes',           'tabela' => 'glpi_locations',       'arvore' => true,  'criavel' => true,  'icone' => 'ti ti-building'],
            'Supplier'       => ['label' => 'Fornecedores',           'tabela' => 'glpi_suppliers',       'arvore' => false, 'criavel' => true,  'icone' => 'ti ti-briefcase'],
            // Grupo antes de Categoria ITIL: a categoria pode apontar para um grupo tecnico.
            'Group'          => ['label' => 'Grupos',                 'tabela' => 'glpi_groups',          'arvore' => true,  'criavel' => true,  'icone' => 'ti ti-users'],
            'ITILCategory'   => ['label' => 'Categorias ITIL',        'tabela' => 'glpi_itilcategories',  'arvore' => true,  'criavel' => true,  'icone' => 'ti ti-git-merge'],
            'User'           => ['label' => 'Usuarios',               'tabela' => 'glpi_users',           'arvore' => false, 'criavel' => false, 'icone' => 'ti ti-user'],
            'TicketTemplate' => ['label' => 'Modelos de chamado',     'tabela' => 'glpi_tickettemplates', 'arvore' => false, 'criavel' => false, 'icone' => 'ti ti-file-text'],
        ];
    }

    /** Tipos que o pacote sabe recriar no destino. */
    public static function tiposCriaveis(): array
    {
        return array_filter(self::todosTipos(), static fn($t) => !empty($t['criavel']));
    }

    /** Tipos que so podem ser reaproveitados ou apontados manualmente. */
    public static function tiposSomenteMapear(): array
    {
        return array_filter(self::todosTipos(), static fn($t) => empty($t['criavel']));
    }

    public static function labelTipo(string $itemtype): string
    {
        $todos = self::todosTipos();
        return (string) ($todos[$itemtype]['label'] ?? $itemtype);
    }

    public static function iconeTipo(string $itemtype): string
    {
        $todos = self::todosTipos();
        return (string) ($todos[$itemtype]['icone'] ?? 'ti ti-puzzle');
    }

    public static function tabelaTipo(string $itemtype): string
    {
        $todos = self::todosTipos();
        return (string) ($todos[$itemtype]['tabela'] ?? '');
    }

    public static function ehCriavel(string $itemtype): bool
    {
        $todos = self::todosTipos();
        return !empty($todos[$itemtype]['criavel']);
    }

    public static function ehArvore(string $itemtype): bool
    {
        $todos = self::todosTipos();
        return !empty($todos[$itemtype]['arvore']);
    }

    /** Acoes oferecidas na tela de importacao para um tipo de dependencia. */
    public static function acoesDoTipo(string $itemtype): array
    {
        $acoes = ['reutilizar', 'apontar'];
        if (self::ehCriavel($itemtype)) {
            $acoes[] = 'novo';
            $acoes[] = 'substituir';
        }
        $acoes[] = 'ignorar';
        return $acoes;
    }

    /** Rotulo curto de cada acao. */
    public static function rotuloAcao(string $acao): string
    {
        $mapa = [
            'reutilizar' => 'Usar o que ja existe',
            'apontar'    => 'Apontar para outro item',
            'novo'       => 'Criar novo',
            'substituir' => 'Substituir o existente',
            'ignorar'    => 'Nao importar',
        ];
        return $mapa[$acao] ?? $acao;
    }

    // -----------------------------------------------------------------
    // Reconhecimento de referencias dentro dos JSON nativos
    // -----------------------------------------------------------------

    /**
     * Descobre a que itemtype uma chave de configuracao nativa se refere.
     * A deteccao e por trecho do nome da chave para sobreviver a mudancas
     * de nomenclatura entre versoes do GLPI.
     */
    public static function itemtypeDaChave(string $chave): ?string
    {
        $k = strtolower($chave);

        if (in_array($k, ['strategy', 'itemtype', 'type', 'id', 'uuid', 'name', 'value', 'values'], true)) {
            return null;
        }

        // O GLPI 11 grava os atores de destino como itemtype => [ids], entao a
        // propria chave pode ser o nome do tipo ("Group", "User", "Supplier").
        $exato = self::normalizarItemtype($chave);
        if ($exato !== null && $exato !== '_question' && $exato !== '_section') {
            return $exato;
        }

        $pareceRef = (strpos($k, 'id') !== false);

        // A ordem importa: o mais especifico vem primeiro.
        if (strpos($k, 'itilcategor') !== false) {
            return 'ITILCategory';
        }
        if (strpos($k, 'requesttype') !== false || strpos($k, 'request_type') !== false) {
            return 'RequestType';
        }
        if (strpos($k, 'tickettemplate') !== false || strpos($k, 'itiltemplate') !== false || strpos($k, 'template') !== false) {
            return $pareceRef ? 'TicketTemplate' : null;
        }
        if (strpos($k, 'calendar') !== false) {
            return $pareceRef ? 'Calendar' : null;
        }
        if (strpos($k, 'slm') !== false) {
            return $pareceRef ? 'SLM' : null;
        }
        if (strpos($k, 'ola') !== false) {
            return $pareceRef ? 'OLA' : null;
        }
        if (strpos($k, 'sla') !== false) {
            return $pareceRef ? 'SLA' : null;
        }
        if (strpos($k, 'location') !== false) {
            return $pareceRef ? 'Location' : null;
        }
        if (strpos($k, 'group') !== false) {
            return $pareceRef ? 'Group' : null;
        }
        if (strpos($k, 'profile') !== false) {
            return $pareceRef ? 'Profile' : null;
        }
        if (strpos($k, 'user') !== false) {
            return $pareceRef ? 'User' : null;
        }
        if (strpos($k, 'entit') !== false) {
            return $pareceRef ? 'Entity' : null;
        }
        if (strpos($k, 'question') !== false) {
            return $pareceRef ? '_question' : null;
        }
        if (strpos($k, 'section') !== false) {
            return $pareceRef ? '_section' : null;
        }

        return null;
    }

    /**
     * Percorre uma estrutura e coleta os IDs referenciados por itemtype.
     * Usado na exportacao.
     */
    public static function coletarReferencias($no, array &$achados, ?string $itemtypeContexto = null): void
    {
        if (is_string($no)) {
            $t = trim($no);
            if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
                $dec = json_decode($t, true);
                if (is_array($dec)) {
                    self::coletarReferencias($dec, $achados, $itemtypeContexto);
                }
            }
            return;
        }

        if (!is_array($no)) {
            return;
        }

        if (isset($no['itemtype'], $no['items_id']) && is_string($no['itemtype'])) {
            self::registrarRef($achados, $no['itemtype'], (int) $no['items_id']);
        }

        foreach ($no as $chave => $valor) {
            $tipo = is_string($chave) ? self::itemtypeDaChave($chave) : $itemtypeContexto;

            if (is_array($valor)) {
                if ($tipo !== null && self::ehListaDeEscalares($valor)) {
                    foreach ($valor as $v) {
                        if (is_numeric($v)) {
                            self::registrarRef($achados, $tipo, (int) $v);
                        }
                    }
                    continue;
                }
                self::coletarReferencias($valor, $achados, $tipo ?? $itemtypeContexto);
                continue;
            }

            if ($tipo !== null && is_numeric($valor)) {
                self::registrarRef($achados, $tipo, (int) $valor);
                continue;
            }

            if (is_string($valor)) {
                self::coletarReferencias($valor, $achados, $tipo ?? $itemtypeContexto);
            }
        }
    }

    /**
     * Reescreve as referencias de uma estrutura usando o de-para de IDs.
     * Usado na importacao, depois das dependencias resolvidas.
     */
    public static function remapear($no, array $mapa, ?string $itemtypeContexto = null)
    {
        if (is_string($no)) {
            $t = trim($no);
            if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
                $dec = json_decode($t, true);
                if (is_array($dec)) {
                    $novo = self::remapear($dec, $mapa, $itemtypeContexto);
                    $enc  = json_encode($novo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    return $enc === false ? $no : $enc;
                }
            }
            return $no;
        }

        if (!is_array($no)) {
            return $no;
        }

        if (isset($no['itemtype'], $no['items_id']) && is_string($no['itemtype'])) {
            $novoId = self::novoId($mapa, $no['itemtype'], (int) $no['items_id']);
            if ($novoId !== null) {
                $no['items_id'] = $novoId;
            }
        }

        foreach ($no as $chave => $valor) {
            if ($chave === 'items_id' && isset($no['itemtype'])) {
                continue;
            }

            $tipo = is_string($chave) ? self::itemtypeDaChave($chave) : $itemtypeContexto;

            if (is_array($valor)) {
                if ($tipo !== null && self::ehListaDeEscalares($valor)) {
                    $lista = [];
                    foreach ($valor as $k2 => $v) {
                        if (is_numeric($v)) {
                            $novoId     = self::novoId($mapa, $tipo, (int) $v);
                            $lista[$k2] = $novoId !== null ? $novoId : $v;
                        } else {
                            $lista[$k2] = $v;
                        }
                    }
                    $no[$chave] = $lista;
                    continue;
                }
                $no[$chave] = self::remapear($valor, $mapa, $tipo ?? $itemtypeContexto);
                continue;
            }

            if ($tipo !== null && is_numeric($valor)) {
                $novoId = self::novoId($mapa, $tipo, (int) $valor);
                if ($novoId !== null) {
                    $no[$chave] = is_string($valor) ? (string) $novoId : $novoId;
                }
                continue;
            }

            if (is_string($valor)) {
                $no[$chave] = self::remapear($valor, $mapa, $tipo ?? $itemtypeContexto);
            }
        }

        return $no;
    }

    /** Lista sequencial de escalares (ex.: groups_ids: [3,4])? */
    private static function ehListaDeEscalares(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        $i = 0;
        foreach ($arr as $chave => $valor) {
            if (is_array($valor) || $chave !== $i) {
                return false;
            }
            $i++;
        }
        return true;
    }

    private static function registrarRef(array &$achados, string $itemtype, int $id): void
    {
        if ($id <= 0 || $itemtype === '') {
            return;
        }
        $itemtype = self::normalizarItemtype($itemtype);
        if ($itemtype === null) {
            return;
        }
        if (!isset($achados[$itemtype])) {
            $achados[$itemtype] = [];
        }
        if (!in_array($id, $achados[$itemtype], true)) {
            $achados[$itemtype][] = $id;
        }
    }

    /** Reduz o itemtype ao conjunto conhecido; null quando nao e tratado. */
    public static function normalizarItemtype(string $itemtype): ?string
    {
        $itemtype = trim($itemtype, '\\ ');
        if ($itemtype === '') {
            return null;
        }
        if ($itemtype === '_question' || $itemtype === '_section') {
            return $itemtype;
        }
        foreach (array_keys(self::todosTipos()) as $c) {
            if (strcasecmp($c, $itemtype) === 0) {
                return $c;
            }
        }
        $equiv = [
            'itilcategory'   => 'ITILCategory',
            'itilcategories' => 'ITILCategory',
            'group'          => 'Group',
            'user'           => 'User',
            'entity'         => 'Entity',
            'profile'        => 'Profile',
            'location'       => 'Location',
            'requesttype'    => 'RequestType',
            'supplier'       => 'Supplier',
        ];
        return $equiv[strtolower($itemtype)] ?? null;
    }

    private static function novoId(array $mapa, string $itemtype, int $antigo): ?int
    {
        if ($antigo <= 0) {
            return null;
        }
        $tipo = self::normalizarItemtype($itemtype);
        if ($tipo === null) {
            return null;
        }
        $novo = $mapa[$tipo][$antigo] ?? null;
        return ($novo !== null && (int) $novo > 0) ? (int) $novo : null;
    }

    // -----------------------------------------------------------------
    // Arquivo temporario do assistente
    // -----------------------------------------------------------------

    private static function dirTmp(): string
    {
        if (defined('GLPI_TMP_DIR') && is_dir(GLPI_TMP_DIR) && is_writable(GLPI_TMP_DIR)) {
            return GLPI_TMP_DIR;
        }
        return sys_get_temp_dir();
    }

    /** Guarda o pacote fora da web root e devolve um token aleatorio. */
    public static function guardarTemp(string $conteudo): ?string
    {
        $token   = bin2hex(random_bytes(16));
        $caminho = self::dirTmp() . '/' . self::PREFIXO_TMP . $token . '.json';

        if (file_put_contents($caminho, $conteudo) === false) {
            return null;
        }
        @chmod($caminho, 0600);
        return $token;
    }

    public static function lerTemp(string $token): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $caminho = self::dirTmp() . '/' . self::PREFIXO_TMP . $token . '.json';
        if (!is_readable($caminho)) {
            return null;
        }
        $conteudo = file_get_contents($caminho);
        return $conteudo === false ? null : $conteudo;
    }

    public static function limparTemp(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return;
        }
        $caminho = self::dirTmp() . '/' . self::PREFIXO_TMP . $token . '.json';
        if (file_exists($caminho)) {
            @unlink($caminho);
        }
    }

    public static function limparTempAntigos(): void
    {
        $padrao = self::dirTmp() . '/' . self::PREFIXO_TMP . '*.json';
        $limite = time() - (6 * HOUR_TIMESTAMP);
        foreach ((array) glob($padrao) as $arquivo) {
            if (is_file($arquivo) && filemtime($arquivo) < $limite) {
                @unlink($arquivo);
            }
        }
    }

    // -----------------------------------------------------------------
    // Utilitarios
    // -----------------------------------------------------------------

    /** Colunas locais que nunca viajam no pacote. */
    public static function colunasVolateis(): array
    {
        return [
            'id',
            'forms_forms_id',
            'forms_sections_id',
            'forms_categories_id',
            'entities_id',
            'date_creation',
            'date_mod',
            'usage_count',
        ];
    }

    public static function limparLinha(array $linha, array $extras = []): array
    {
        $remover = array_merge(self::colunasVolateis(), $extras);
        foreach ($remover as $col) {
            unset($linha[$col]);
        }
        return $linha;
    }

    /** Normaliza um nome vindo do formulario do usuario. */
    public static function limparNome(string $nome): string
    {
        $nome = trim(strip_tags($nome));
        return function_exists('mb_substr') ? mb_substr($nome, 0, 250) : substr($nome, 0, 250);
    }

    public static function versaoGlpi(): string
    {
        return defined('GLPI_VERSION') ? (string) GLPI_VERSION : '';
    }

    public static function nomeArquivo(): string
    {
        return 'catalogo-servicos-' . date('Ymd-His') . '.json';
    }

    public static function flagsJson(): int
    {
        return JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
    }
}
