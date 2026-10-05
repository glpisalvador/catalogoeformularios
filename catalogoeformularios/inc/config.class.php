<?php

/**
 * Classe de configuracao do plugin Catálogo e Formulários.
 * Armazena tudo na tabela chave-valor glpi_plugin_catalogoeformularios_configs.
 */
class PluginCatalogoeformulariosConfig extends CommonDBTM
{
    // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no GLPI 11,
    // e o PHP exige o mesmo tipo da classe pai. As permissões são sobrescritas abaixo.
    private const RIGHTNAME = 'config';

    public const TABELA = 'glpi_plugin_catalogoeformularios_configs';

    public static function getTypeName($nb = 0): string
    {
        return 'Catálogo e Formulários';
    }

    public static function canView(): bool
    {
        return Session::haveRight('config', READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canDelete(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canPurge(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    // ---------------------------------------------------------------------
    // Acesso as configuracoes (chave-valor)
    // ---------------------------------------------------------------------

    public static function getConfig(string $name, $default = null)
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => self::TABELA,
            'WHERE' => ['name' => $name],
            'LIMIT' => 1,
        ])->current();

        if ($row === null) {
            return $default;
        }

        return $row['value'];
    }

    public static function setConfig(string $name, $value): bool
    {
        global $DB;

        $existe = $DB->request([
            'COUNT' => 'total',
            'FROM'  => self::TABELA,
            'WHERE' => ['name' => $name],
        ])->current();

        if ((int) ($existe['total'] ?? 0) > 0) {
            return (bool) $DB->update(self::TABELA, ['value' => $value], ['name' => $name]);
        }

        // insertOrDie() foi removido no GLPI 12; insert() existe no 11 e no 12
        return (bool) $DB->insert(self::TABELA, ['name' => $name, 'value' => $value]);
    }

    public static function getArrayConfig(string $name): array
    {
        $valor = self::getConfig($name, null);
        if ($valor === null) {
            return [];
        }
        $decodificado = json_decode($valor, true);
        return is_array($decodificado) ? $decodificado : [];
    }

    public static function setArrayConfig(string $name, array $value): bool
    {
        return self::setConfig($name, json_encode(array_values($value)));
    }

    public static function getAllConfigs(): array
    {
        global $DB;

        $saida = [];
        foreach ($DB->request(['FROM' => self::TABELA]) as $row) {
            $saida[$row['name']] = $row['value'];
        }
        return $saida;
    }

    // ---------------------------------------------------------------------
    // Controle de acesso do plugin (perfis/usuarios configurados)
    // ---------------------------------------------------------------------

    /**
     * Pode editar o catalogo?
     * - direito nativo config UPDATE (atalho de admin), OU
     * - perfil ativo presente em editar_perfis, OU
     * - usuario presente em editar_usuarios.
     */
    public static function podeEditar(): bool
    {
        if (Session::haveRight('config', UPDATE)) {
            return true;
        }

        $perfil = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        $user   = (int) Session::getLoginUserID();

        $perfisEdit = array_map('intval', self::getArrayConfig('editar_perfis'));
        $usersEdit  = array_map('intval', self::getArrayConfig('editar_usuarios'));

        if ($perfil > 0 && in_array($perfil, $perfisEdit, true)) {
            return true;
        }
        if ($user > 0 && in_array($user, $usersEdit, true)) {
            return true;
        }
        return false;
    }

    /**
     * Pode visualizar o catalogo?
     * - direito nativo config READ (atalho de admin), OU
     * - quem pode editar tambem visualiza, OU
     * - perfil ativo presente em visualizar_perfis, OU
     * - usuario presente em visualizar_usuarios.
     */
    public static function podeVisualizar(): bool
    {
        if (Session::haveRight('config', READ)) {
            return true;
        }
        if (self::podeEditar()) {
            return true;
        }

        $perfil = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        $user   = (int) Session::getLoginUserID();

        $perfisVis = array_map('intval', self::getArrayConfig('visualizar_perfis'));
        $usersVis  = array_map('intval', self::getArrayConfig('visualizar_usuarios'));

        if ($perfil > 0 && in_array($perfil, $perfisVis, true)) {
            return true;
        }
        if ($user > 0 && in_array($user, $usersVis, true)) {
            return true;
        }
        return false;
    }

    // ---------------------------------------------------------------------
    // Listas de perfis e usuarios
    // ---------------------------------------------------------------------

    /**
     * @return array<int, string> id => nome
     */
    public static function getTodosPerfis(): array
    {
        global $DB;

        // glpi_profiles NAO possui is_deleted, por isso nao filtramos por ele.
        $perfis = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => 'glpi_profiles',
            'ORDER'  => 'name ASC',
        ]) as $row) {
            $perfis[(int) $row['id']] = $row['name'];
        }
        return $perfis;
    }

    /**
     * @return array<int, string> id => nome amigavel
     */
    public static function getUsuariosAtivos(): array
    {
        global $DB;

        $usuarios = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'realname', 'firstname'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['is_active' => 1, 'is_deleted' => 0],
            'ORDER'  => 'name ASC',
        ]) as $row) {
            $nomeCompleto = trim(($row['realname'] ?? '') . ' ' . ($row['firstname'] ?? ''));
            $label = $nomeCompleto !== ''
                ? $nomeCompleto . ' (' . $row['name'] . ')'
                : $row['name'];
            $usuarios[(int) $row['id']] = $label;
        }
        return $usuarios;
    }

    // ---------------------------------------------------------------------
    // Categorias ITIL
    // ---------------------------------------------------------------------

    public static function contarCategoriasItil(): int
    {
        global $DB;

        $row = $DB->request([
            'COUNT' => 'total',
            'FROM'  => 'glpi_itilcategories',
        ])->current();

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Copia as categorias ITIL para as categorias de formulario (glpi_forms_categories),
     * preservando a hierarquia pai/filho.
     *
     * Observacao: glpi_forms_categories NAO possui coluna entities_id (categorias de
     * formulario sao globais no GLPI 11), por isso a entidade de origem nao e replicada.
     * Nao duplica: se ja existir categoria com o mesmo nome sob o mesmo pai, reutiliza.
     *
     * @return array{total:int, copiadas:int, existentes:int, erros:int, msg_erro:string, erro_classe?:bool}
     */
    public static function copiarCategoriasItilParaFormularios(): array
    {
        global $DB;

        $resultado = ['total' => 0, 'copiadas' => 0, 'existentes' => 0, 'erros' => 0, 'msg_erro' => ''];

        if (!class_exists('\\Glpi\\Form\\Category')) {
            $resultado['erro_classe'] = true;
            return $resultado;
        }

        // Pais primeiro (level ASC) para que o pai ja esteja mapeado quando o filho for processado.
        $itilCategorias = $DB->request([
            'SELECT' => ['id', 'name', 'itilcategories_id', 'level'],
            'FROM'   => 'glpi_itilcategories',
            'ORDER'  => ['level ASC', 'id ASC'],
        ]);

        $mapa = []; // id_itil => id_forms_categoria

        foreach ($itilCategorias as $cat) {
            $idItil  = (int) $cat['id'];
            $nome    = (string) $cat['name'];
            $paiItil = (int) $cat['itilcategories_id'];

            // Resolve o pai ja copiado; se nao houver, vai para a raiz (0).
            $paiForm = ($paiItil > 0 && isset($mapa[$paiItil])) ? $mapa[$paiItil] : 0;

            // Ja existe (mesmo nome + mesmo pai)?
            $existente = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_forms_categories',
                'WHERE'  => [
                    'name'                => $nome,
                    'forms_categories_id' => $paiForm,
                ],
                'LIMIT'  => 1,
            ])->current();

            if ($existente !== null) {
                $mapa[$idItil] = (int) $existente['id'];
                $resultado['existentes']++;
                continue;
            }

            // Cria pela classe nativa (calcula completename, level e caches da arvore).
            try {
                $formCat = new \Glpi\Form\Category();
                $novoId  = $formCat->add([
                    'name'                => $nome,
                    'forms_categories_id' => $paiForm,
                    '_disablenotif'       => true,
                ]);
            } catch (\Throwable $e) {
                $resultado['erros']++;
                if ($resultado['msg_erro'] === '') {
                    $resultado['msg_erro'] = $e->getMessage();
                }
                continue;
            }

            if ($novoId) {
                $mapa[$idItil] = (int) $novoId;
                $resultado['copiadas']++;
            } else {
                $resultado['erros']++;
                if ($resultado['msg_erro'] === '') {
                    $resultado['msg_erro'] = 'add() retornou falso para a categoria "' . $nome . '".';
                }
            }
        }

        $resultado['total'] = $resultado['copiadas'] + $resultado['existentes'] + $resultado['erros'];

        return $resultado;
    }

    // ---------------------------------------------------------------------
    // Renderizacao da tela de configuracao
    // ---------------------------------------------------------------------

    public static function showConfigForm(): void
    {
        global $CFG_GLPI;

        $actionUrl = $CFG_GLPI['root_doc'] . '/plugins/catalogoeformularios/front/config.form.php';
        $ajaxUrl   = $CFG_GLPI['root_doc'] . '/plugins/catalogoeformularios/front/ajax.php';

        $visPerfis  = self::getArrayConfig('visualizar_perfis');
        $visUsers   = self::getArrayConfig('visualizar_usuarios');
        $editPerfis = self::getArrayConfig('editar_perfis');
        $editUsers  = self::getArrayConfig('editar_usuarios');

        $todosPerfis = self::getTodosPerfis();
        $usuarios    = self::getUsuariosAtivos();

        // Dados globais para o JavaScript (URL do AJAX + token CSRF).
        echo '<script>window.catalogoeformulariosConfig = '
            . json_encode(['ajaxUrl' => $ajaxUrl, 'csrf' => plugin_catalogoeformularios_token_csrf()])
            . ';</script>';

        echo '<div class="catalogoeformularios-config">';

        // ---- Bloco: Permissao para Visualizar ----
        echo '<form method="post" action="' . htmlspecialchars($actionUrl) . '">';
        echo plugin_catalogoeformularios_campo_csrf();
        echo '<input type="hidden" name="save_action" value="salvar_visualizar">';
        echo '<div class="catalogoeformularios-card">';
        echo '<div class="catalogoeformularios-card-header"><i class="ti ti-eye"></i> Permissao para Visualizar</div>';
        echo '<div class="catalogoeformularios-card-body">';
        echo '<p class="catalogoeformularios-help"><i class="ti ti-info-circle"></i> Selecione os perfis e/ou usuarios que poderao visualizar o catalogo.</p>';
        echo '<label class="catalogoeformularios-label">Perfis</label>';
        echo self::renderMultiselect('visualizar_perfis', $todosPerfis, $visPerfis, 'Selecione os perfis...');
        echo '<label class="catalogoeformularios-label">Usuarios</label>';
        echo self::renderMultiselect('visualizar_usuarios', $usuarios, $visUsers, 'Selecione os usuarios...');
        echo '<div class="catalogoeformularios-actions"><button type="submit" class="catalogoeformularios-btn-salvar"><i class="ti ti-device-floppy"></i> Salvar</button></div>';
        echo '</div></div>';
        echo '</form>';

        // ---- Bloco: Permissao para Editar ----
        echo '<form method="post" action="' . htmlspecialchars($actionUrl) . '">';
        echo plugin_catalogoeformularios_campo_csrf();
        echo '<input type="hidden" name="save_action" value="salvar_editar">';
        echo '<div class="catalogoeformularios-card">';
        echo '<div class="catalogoeformularios-card-header"><i class="ti ti-edit"></i> Permissao para Editar</div>';
        echo '<div class="catalogoeformularios-card-body">';
        echo '<p class="catalogoeformularios-help"><i class="ti ti-info-circle"></i> Selecione os perfis e/ou usuarios que poderao editar o catalogo.</p>';
        echo '<label class="catalogoeformularios-label">Perfis</label>';
        echo self::renderMultiselect('editar_perfis', $todosPerfis, $editPerfis, 'Selecione os perfis...');
        echo '<label class="catalogoeformularios-label">Usuarios</label>';
        echo self::renderMultiselect('editar_usuarios', $usuarios, $editUsers, 'Selecione os usuarios...');
        echo '<div class="catalogoeformularios-actions"><button type="submit" class="catalogoeformularios-btn-salvar"><i class="ti ti-device-floppy"></i> Salvar</button></div>';
        echo '</div></div>';
        echo '</form>';

        // ---- Bloco: Criacao de formularios e categorias ----
        echo '<form method="post" action="' . htmlspecialchars($actionUrl) . '">';
        echo plugin_catalogoeformularios_campo_csrf();
        echo '<input type="hidden" name="save_action" value="salvar_criacao">';
        echo '<div class="catalogoeformularios-card">';
        echo '<div class="catalogoeformularios-card-header"><i class="ti ti-building"></i> Criacao de formularios e categorias</div>';
        echo '<div class="catalogoeformularios-card-body">';
        echo '<p class="catalogoeformularios-help"><i class="ti ti-info-circle"></i> Entidade em que os formularios e as categorias novos do catalogo sao criados (tambem nas copias e importacoes). Marque "subentidades" no formulario para ele aparecer nas entidades filhas.</p>';
        echo '<label class="catalogoeformularios-label">Entidade</label>';
        echo '<div class="catalogoeformularios-campo-entidade">';
        Entity::dropdown([
            'name'                => 'entidade_padrao',
            'value'               => (int) self::getConfig('entidade_padrao', 0),
            'entity'              => $_SESSION['glpiactiveentities'] ?? [0],
            'display_emptychoice' => false,
        ]);
        echo '</div>';
        echo '<div class="catalogoeformularios-actions"><button type="submit" class="catalogoeformularios-btn-salvar"><i class="ti ti-device-floppy"></i> Salvar</button></div>';
        echo '</div></div>';
        echo '</form>';

        // ---- Bloco: Categorias ITIL ----
        echo '<div class="catalogoeformularios-card">';
        echo '<div class="catalogoeformularios-card-header"><i class="ti ti-list-check"></i> Categorias ITIL</div>';
        echo '<div class="catalogoeformularios-card-body">';
        echo '<p class="catalogoeformularios-help"><i class="ti ti-info-circle"></i> Conte as categorias ITIL existentes e copie-as para as categorias de formulario preservando a hierarquia.</p>';

        echo '<div class="catalogoeformularios-botao-linha">';
        echo '<button type="button" id="catalogoeformularios-btn-contar" class="catalogoeformularios-btn-acao"><i class="ti ti-list"></i> Categorias ITIL</button>';
        echo '<span id="catalogoeformularios-resultado-contar" class="catalogoeformularios-resultado"></span>';
        echo '</div>';

        echo '<div class="catalogoeformularios-botao-linha">';
        echo '<button type="button" id="catalogoeformularios-btn-copiar" class="catalogoeformularios-btn-acao"><i class="ti ti-copy"></i> Copiar Categorias ITIL para Categorias Formularios</button>';
        echo '<span id="catalogoeformularios-resultado-copiar" class="catalogoeformularios-resultado"></span>';
        echo '</div>';

        echo '</div></div>';

        echo '</div>'; // .catalogoeformularios-config
    }

    /**
     * Renderiza um multiselect customizado (selecionados primeiro, busca, marcar todos).
     *
     * @param array<int, string> $opcoes       id => label
     * @param array<int|string>  $selecionados ids selecionados
     */
    private static function renderMultiselect(string $name, array $opcoes, array $selecionados, string $placeholder): string
    {
        $uid          = preg_replace('/[^A-Za-z0-9]/', '', $name) . mt_rand(1000, 9999);
        $selecionados = array_map('intval', $selecionados);

        // Selecionados primeiro, cada grupo em ordem alfabetica.
        $sel  = [];
        $nsel = [];
        foreach ($opcoes as $id => $label) {
            if (in_array((int) $id, $selecionados, true)) {
                $sel[$id] = $label;
            } else {
                $nsel[$id] = $label;
            }
        }
        asort($sel, SORT_NATURAL | SORT_FLAG_CASE);
        asort($nsel, SORT_NATURAL | SORT_FLAG_CASE);
        $ordenadas = $sel + $nsel;

        $totalSel   = count($sel);
        $labelTexto = $totalSel > 0 ? ($totalSel . ' selecionado(s)') : $placeholder;

        $html  = '<div class="catalogoeformularios-multiselect" id="ms_' . $uid . '">';
        $html .= '<div class="catalogoeformularios-ms-header" onclick="catalogoeformulariosToggleMultiselect(\'' . $uid . '\')">';
        $html .= '<span class="catalogoeformularios-ms-label" id="ms_label_' . $uid . '">' . htmlspecialchars($labelTexto) . '</span>';
        $html .= '<i class="ti ti-chevron-down"></i>';
        $html .= '</div>';

        $html .= '<div class="catalogoeformularios-ms-dropdown" id="ms_dropdown_' . $uid . '" style="display:none;">';
        $html .= '<input type="text" class="form-control form-control-sm catalogoeformularios-ms-search" placeholder="Buscar..." '
              . 'onkeyup="catalogoeformulariosFilterMultiselect(\'' . $uid . '\', this.value)">';

        $html .= '<label class="catalogoeformularios-ms-selectall">';
        $html .= '<input type="checkbox" onchange="catalogoeformulariosToggleAllMultiselect(\'' . $uid . '\', this.checked)"> Marcar / desmarcar todos';
        $html .= '</label>';

        $html .= '<div class="catalogoeformularios-ms-options" id="ms_options_' . $uid . '">';
        foreach ($ordenadas as $id => $label) {
            $checked    = in_array((int) $id, $selecionados, true);
            $classeSel  = $checked ? ' selected' : '';
            $attrCheck  = $checked ? ' checked' : '';
            $dataLabel  = htmlspecialchars(mb_strtolower($label));
            $html .= '<label class="catalogoeformularios-ms-option' . $classeSel . '" data-label="' . $dataLabel . '">';
            $html .= '<input type="checkbox" name="' . htmlspecialchars($name) . '[]" value="' . (int) $id . '"' . $attrCheck
                  . ' onchange="catalogoeformulariosHandleMultiselectChange(\'' . $uid . '\', this)">';
            $html .= '<span>' . htmlspecialchars($label) . '</span>';
            $html .= '</label>';
        }
        $html .= '</div>';

        $html .= '<div class="catalogoeformularios-ms-count" id="ms_count_' . $uid . '">' . $totalSel . ' de ' . count($opcoes) . '</div>';
        $html .= '</div>'; // dropdown
        $html .= '</div>'; // multiselect

        return $html;
    }
}