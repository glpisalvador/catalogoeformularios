<?php

/**
 * Leituras das fontes nativas do GLPI que alimentam os seletores do gerenciador
 * (destinos e condicoes). Somente leitura; nenhuma escrita.
 */
class PluginCatalogoeformulariosFonte extends CommonGLPI
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

    /** Dispatcher: retorna a fonte pedida pelo nome (usado pelo AJAX/JS). */
    public static function getFonte(string $tipo): array
    {
        switch ($tipo) {
            case 'entidades':       return self::getEntidades();
            case 'itilcategorias':  return self::getItilCategorias();
            case 'tiposrequisicao': return self::getTiposRequisicao();
            case 'status':          return self::getStatus();
            case 'origens':         return self::getOrigens();
            case 'urgencias':       return self::getUrgencias();
            case 'localizacoes':    return self::getLocalizacoes();
            case 'slas':            return self::getSlas();
            case 'olas':            return self::getOlas();
            case 'templates':       return self::getTemplates();
            case 'ativos':          return self::getAtivosTipos();
            case 'atores':
                return ['usuarios' => self::getUsuarios(), 'grupos' => self::getGrupos()];
            case 'perfis':
                return self::getPerfis();
            default:
                return [];
        }
    }

    /** Entidades ativas do usuario. */
    public static function getEntidades(): array
    {
        global $DB;

        $ativas = $_SESSION['glpiactiveentities'] ?? [];
        if (empty($ativas)) {
            return [];
        }
        $out = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'completename'],
            'FROM'   => 'glpi_entities',
            'WHERE'  => ['id' => $ativas],
            'ORDER'  => 'completename ASC',
        ]) as $r) {
            $out[] = ['v' => (int) $r['id'], 't' => $r['completename']];
        }
        return $out;
    }

    /** Categorias ITIL. */
    public static function getItilCategorias(): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'completename'],
            'FROM'   => 'glpi_itilcategories',
            'ORDER'  => 'completename ASC',
        ]) as $r) {
            $out[] = ['v' => (int) $r['id'], 't' => $r['completename']];
        }
        return $out;
    }

    /** Tipos de requisicao (Incidente / Requisicao). */
    public static function getTiposRequisicao(): array
    {
        return [
            ['v' => 1, 't' => 'Incidente'],
            ['v' => 2, 't' => 'Requisicao'],
        ];
    }

    /** Status de chamado (usa labels nativos quando disponiveis). */
    public static function getStatus(): array
    {
        $out = [];
        if (class_exists('Ticket') && method_exists('Ticket', 'getAllStatusArray')) {
            try {
                foreach (\Ticket::getAllStatusArray() as $id => $label) {
                    $out[] = ['v' => (int) $id, 't' => (string) $label];
                }
                if (!empty($out)) {
                    return $out;
                }
            } catch (\Throwable $e) {
                // fallback abaixo
            }
        }
        return [
            ['v' => 1, 't' => 'Novo'],
            ['v' => 2, 't' => 'Em atendimento'],
            ['v' => 4, 't' => 'Pendente'],
            ['v' => 5, 't' => 'Solucionado'],
            ['v' => 6, 't' => 'Fechado'],
        ];
    }

    /** Origens da requisicao (glpi_requesttypes ativos). */
    public static function getOrigens(): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => 'glpi_requesttypes',
            'WHERE'  => ['is_active' => 1],
            'ORDER'  => 'name ASC',
        ]) as $r) {
            $out[] = ['v' => (int) $r['id'], 't' => $r['name']];
        }
        return $out;
    }

    /** Urgencias (usa labels nativos quando disponiveis). */
    public static function getUrgencias(): array
    {
        $out = [];
        if (class_exists('CommonITILObject') && method_exists('CommonITILObject', 'getUrgencyName')) {
            for ($i = 5; $i >= 1; $i--) {
                try {
                    $out[] = ['v' => $i, 't' => (string) \CommonITILObject::getUrgencyName($i)];
                } catch (\Throwable $e) {
                    $out = [];
                    break;
                }
            }
            if (!empty($out)) {
                return $out;
            }
        }
        return [
            ['v' => 5, 't' => 'Muito alta'],
            ['v' => 4, 't' => 'Alta'],
            ['v' => 3, 't' => 'Media'],
            ['v' => 2, 't' => 'Baixa'],
            ['v' => 1, 't' => 'Muito baixa'],
        ];
    }

    /** Localizacoes. */
    public static function getLocalizacoes(): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'completename'],
            'FROM'   => 'glpi_locations',
            'ORDER'  => 'completename ASC',
        ]) as $r) {
            $out[] = ['v' => (int) $r['id'], 't' => $r['completename']];
        }
        return $out;
    }

    /** SLAs (com indicacao do tipo TTO/TTR). */
    public static function getSlas(): array
    {
        return self::lerNivelServico('glpi_slas');
    }

    /** OLAs (com indicacao do tipo TTO/TTR). */
    public static function getOlas(): array
    {
        return self::lerNivelServico('glpi_olas');
    }

    private static function lerNivelServico(string $tabela): array
    {
        global $DB;

        $tipoLabel = [1 => 'Atendimento', 0 => 'Solucao']; // SLM::TTO=1 / SLM::TTR=0 (convencao GLPI)
        $out = [];
        foreach ($DB->request([
            'FROM'  => $tabela,
            'ORDER' => 'name ASC',
        ]) as $r) {
            $nome = $r['name'] ?? '';
            $tipo = isset($r['type']) ? (int) $r['type'] : null;
            $tag  = ($tipo !== null && isset($tipoLabel[$tipo])) ? ' (' . $tipoLabel[$tipo] . ')' : '';
            $out[] = ['v' => (int) $r['id'], 't' => $nome . $tag, 'tipo' => $tipo];
        }
        return $out;
    }

    /** Templates de chamado. */
    public static function getTemplates(): array
    {
        global $DB;

        $out = [];
        if (!$DB->tableExists('glpi_tickettemplates')) {
            return $out;
        }
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => 'glpi_tickettemplates',
            'ORDER'  => 'name ASC',
        ]) as $r) {
            $out[] = ['v' => (int) $r['id'], 't' => $r['name']];
        }
        return $out;
    }

    /** Usuarios ativos (para atores requerente/observador/atribuido). */
    public static function getUsuarios(): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'realname', 'firstname'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['is_active' => 1, 'is_deleted' => 0],
            'ORDER'  => ['firstname ASC', 'realname ASC', 'name ASC'],
        ]) as $r) {
            // Ordem de exibicao: nome seguido do sobrenome.
            $nomeCompleto = trim(($r['firstname'] ?? '') . ' ' . ($r['realname'] ?? ''));
            $label = $nomeCompleto !== '' ? $nomeCompleto . ' (' . $r['name'] . ')' : $r['name'];
            $out[] = ['v' => (int) $r['id'], 't' => $label];
        }
        return $out;
    }

    /** Perfis do GLPI (glpi_profiles NAO possui is_deleted: nunca filtrar por ele). */
    public static function getPerfis(): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => 'glpi_profiles',
            'ORDER'  => 'name ASC',
        ]) as $r) {
            $out[] = ['v' => (int) $r['id'], 't' => $r['name']];
        }
        return $out;
    }

    /** Grupos (glpi_groups NAO possui is_deleted: nunca filtrar por ele). */
    public static function getGrupos(): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'completename'],
            'FROM'   => 'glpi_groups',
            'ORDER'  => 'completename ASC',
        ]) as $r) {
            $out[] = ['v' => (int) $r['id'], 't' => $r['completename']];
        }
        return $out;
    }

    /** Tipos de ativo comuns (para o campo "Ativos vinculados" do destino). */
    public static function getAtivosTipos(): array
    {
        $cands = [
            'Computer'         => 'Computador',
            'Monitor'          => 'Monitor',
            'NetworkEquipment' => 'Equipamento de rede',
            'Peripheral'       => 'Periferico',
            'Phone'            => 'Telefone',
            'Printer'          => 'Impressora',
            'Software'         => 'Software',
        ];
        $out = [];
        foreach ($cands as $itemtype => $label) {
            if (class_exists($itemtype)) {
                $out[] = ['v' => $itemtype, 't' => $label];
            }
        }
        return $out;
    }
}