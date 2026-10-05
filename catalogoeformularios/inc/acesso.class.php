<?php

/**
 * Facilitador de Controle de Acesso de formularios do GLPI 11.
 *
 * Persiste na tabela nativa glpi_forms_accesscontrols via a classe nativa
 * \Glpi\Form\AccessControl\FormAccessControl. Duas politicas suportadas:
 *  - AllowList   : config { user_ids, group_ids, profile_ids }  (user_ids=["all"] => todos)
 *  - DirectAccess: config { token, allow_unauthenticated }
 */
class PluginCatalogoeformulariosAcesso extends CommonGLPI
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

    private const FAC       = '\\Glpi\\Form\\AccessControl\\FormAccessControl';
    private const ALLOWLIST = '\\Glpi\\Form\\AccessControl\\ControlType\\AllowList';
    private const DIRECT    = '\\Glpi\\Form\\AccessControl\\ControlType\\DirectAccess';

    /** Estado estruturado das politicas de acesso de um formulario (para a UI). */
    public static function getEstado(int $formId): array
    {
        global $DB;

        $estado = [
            'lista'  => ['ativo' => false, 'todos' => false, 'usuarios' => [], 'grupos' => [], 'perfis' => []],
            'direto' => ['ativo' => false, 'token' => '', 'allow_unauthenticated' => false],
        ];

        if (!$DB->tableExists('glpi_forms_accesscontrols_formaccesscontrols')) {
            return $estado;
        }

        foreach ($DB->request([
            'SELECT' => ['strategy', 'config', 'is_active'],
            'FROM'   => 'glpi_forms_accesscontrols_formaccesscontrols',
            'WHERE'  => ['forms_forms_id' => $formId],
        ]) as $row) {
            $strategy = ltrim((string) ($row['strategy'] ?? ''), '\\');
            $cfg      = $row['config'] ?? [];
            if (is_string($cfg)) {
                $cfg = json_decode($cfg, true) ?: [];
            }
            $ativo = (int) ($row['is_active'] ?? 0) === 1;

            if ($strategy === ltrim(self::ALLOWLIST, '\\')) {
                $uids  = $cfg['user_ids'] ?? [];
                $todos = in_array('all', $uids, true);
                $estado['lista'] = [
                    'ativo'    => $ativo,
                    'todos'    => $todos,
                    'usuarios' => $todos ? [] : array_map('intval', array_filter($uids, 'is_numeric')),
                    'grupos'   => array_map('intval', $cfg['group_ids'] ?? []),
                    'perfis'   => array_map('intval', $cfg['profile_ids'] ?? []),
                ];
            } elseif ($strategy === ltrim(self::DIRECT, '\\')) {
                $estado['direto'] = [
                    'ativo'                 => $ativo,
                    'token'                 => (string) ($cfg['token'] ?? ''),
                    'allow_unauthenticated' => (bool) ($cfg['allow_unauthenticated'] ?? false),
                ];
            }
        }

        return $estado;
    }

    /** Salva a politica AllowList (perfis / usuarios / grupos / "todos"). */
    public static function salvarAllowList(int $formId, array $d): array
    {
        if ($formId <= 0) {
            return ['ok' => false, 'msg' => 'Formulario invalido.'];
        }

        $todos    = !empty($d['todos']);
        $usuarios = array_map('intval', $d['usuarios'] ?? []);
        $grupos   = array_map('intval', $d['grupos'] ?? []);
        $perfis   = array_map('intval', $d['perfis'] ?? []);
        $ativo    = !empty($d['ativo']);

        $config = [
            'user_ids'    => $todos ? ['all'] : array_values($usuarios),
            'group_ids'   => array_values($grupos),
            'profile_ids' => array_values($perfis),
        ];

        return self::gravarPolitica($formId, self::ALLOWLIST, $config, $ativo)
            ? ['ok' => true, 'msg' => 'Controle de acesso (lista de permissao) salvo.']
            : ['ok' => false, 'msg' => 'Falha ao salvar o controle de acesso.'];
    }

    /** Salva a politica DirectAccess (link/token + acesso anonimo). */
    public static function salvarDirectAccess(int $formId, array $d): array
    {
        if ($formId <= 0) {
            return ['ok' => false, 'msg' => 'Formulario invalido.'];
        }

        $ativo  = !empty($d['ativo']);
        $unauth = !empty($d['allow_unauthenticated']);

        // Preserva o token existente; gera um novo se ainda nao houver.
        $atual = self::getEstado($formId);
        $token = $atual['direto']['token'] ?? '';
        if ($token === '') {
            $token = bin2hex(random_bytes(20));
        }

        $config = [
            'token'                 => $token,
            'allow_unauthenticated' => $unauth,
        ];

        return self::gravarPolitica($formId, self::DIRECT, $config, $ativo)
            ? ['ok' => true, 'msg' => 'Acesso direto (link) salvo.']
            : ['ok' => false, 'msg' => 'Falha ao salvar o acesso direto.'];
    }

    // -----------------------------------------------------------------
    // Internos
    // -----------------------------------------------------------------

    /** Procura a politica de uma estrategia num formulario. */
    private static function acharPolitica(int $formId, string $strategyDb): ?int
    {
        global $DB;
        foreach ($DB->request([
            'SELECT' => ['id', 'strategy'],
            'FROM'   => 'glpi_forms_accesscontrols_formaccesscontrols',
            'WHERE'  => ['forms_forms_id' => $formId],
        ]) as $r) {
            if (ltrim((string) $r['strategy'], '\\') === $strategyDb) {
                return (int) $r['id'];
            }
        }
        return null;
    }

    /**
     * Cria/atualiza a politica. Tenta gravar a config como array e, se a versao
     * exigir, repete como string JSON. Tudo via a classe nativa FormAccessControl.
     */
    private static function gravarPolitica(int $formId, string $strategy, array $config, bool $ativo): bool
    {
        $cls = self::FAC;
        if (!class_exists($cls)) {
            return false;
        }
        $strategyDb = ltrim($strategy, '\\');
        $id         = self::acharPolitica($formId, $strategyDb);

        foreach ([$config, json_encode($config)] as $payload) {
            try {
                $ac = new $cls();
                if ($id !== null) {
                    $ok = $ac->update([
                        'id'        => $id,
                        'strategy'  => $strategyDb,
                        'config'    => $payload,
                        'is_active' => $ativo ? 1 : 0,
                    ]);
                } else {
                    $novo = $ac->add([
                        'forms_forms_id' => $formId,
                        'strategy'       => $strategyDb,
                        'config'         => $payload,
                        'is_active'      => $ativo ? 1 : 0,
                    ]);
                    $ok = (bool) $novo;
                    if ($ok) {
                        $id = (int) $novo;
                    }
                }
                if ($ok) {
                    return true;
                }
            } catch (\Throwable $e) {
                // tenta o proximo formato de payload
            }
        }
        return false;
    }

    /**
     * Resumo dos perfis com acesso a um formulario, para exibir na barra de
     * titulo da lista. Retorna ['todos' => bool, 'nomes' => string[]].
     *  - todos = true -> AllowList liberada para todos (user_ids = ['all']).
     *  - nomes        -> nomes dos perfis (glpi_profiles) quando ha restricao por perfil.
     */
    public static function resumoPerfis(int $formId): array
    {
        global $DB;

        $estado = self::getEstado($formId);
        $lista  = $estado['lista'];

        if (!empty($lista['todos'])) {
            return ['todos' => true, 'nomes' => []];
        }

        $ids = array_values(array_filter(array_map('intval', $lista['perfis'] ?? [])));
        if (empty($ids)) {
            return ['todos' => false, 'nomes' => []];
        }

        $nomes = [];
        foreach ($DB->request([
            'SELECT' => ['name'],
            'FROM'   => 'glpi_profiles',
            'WHERE'  => ['id' => $ids],
            'ORDER'  => 'name ASC',
        ]) as $r) {
            $nomes[] = (string) $r['name'];
        }

        return ['todos' => false, 'nomes' => $nomes];
    }
}