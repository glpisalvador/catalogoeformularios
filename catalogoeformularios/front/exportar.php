<?php


Session::checkLoginUser();

// Exportar e leitura: basta poder visualizar o catalogo.
if (!PluginCatalogoeformulariosConfig::podeVisualizar()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

global $CFG_GLPI;

$base = $CFG_GLPI['root_doc'] . '/plugins/catalogoeformularios';
$v    = '3';

PluginCatalogoeformulariosPacote::limparTempAntigos();

$etapa  = 'selecao';
$resumo = null;
$man    = [];
$token  = '';

// -----------------------------------------------------------------------
// Download do pacote ja revisado.
// -----------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['acao'] ?? '') === 'baixar') {
    $token    = (string) ($_POST['token'] ?? '');
    $conteudo = PluginCatalogoeformulariosPacote::lerTemp($token);

    if ($conteudo === null) {
        Session::addMessageAfterRedirect('A previa expirou. Refaca a selecao.', false, ERROR);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Location: ' . $base . '/front/exportar.php');
        exit;
    }

    $pacote = json_decode($conteudo, true);

    if (is_array($pacote)) {
        $manter = [];
        foreach ((array) ($_POST['deps'] ?? []) as $itemtype => $ids) {
            $manter[(string) $itemtype] = array_map('intval', (array) $ids);
        }
        $pacote   = PluginCatalogoeformulariosExportacao::filtrarDependencias($pacote, $manter);
        $conteudo = PluginCatalogoeformulariosExportacao::paraJson($pacote);
    }

    PluginCatalogoeformulariosPacote::limparTemp($token);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . PluginCatalogoeformulariosPacote::nomeArquivo() . '"');
    header('Content-Length: ' . strlen($conteudo));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo $conteudo;
    exit;
}

// -----------------------------------------------------------------------
// Monta o pacote e mostra o inventario para revisao.
// -----------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['acao'] ?? '') === 'revisar') {
    $categorias  = array_map('intval', (array) ($_POST['categorias'] ?? []));
    $formularios = array_map('intval', (array) ($_POST['formularios'] ?? []));

    if (empty($categorias) && empty($formularios)) {
        Session::addMessageAfterRedirect('Selecione ao menos uma categoria ou um formulario para exportar.', false, ERROR);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Location: ' . $base . '/front/exportar.php');
        exit;
    }

    $pacote = PluginCatalogoeformulariosExportacao::gerar([
        'categorias'        => $categorias,
        'formularios'       => $formularios,
        'incluir_forms_cat' => !empty($_POST['incluir_forms_cat']),
        'incluir_destinos'  => !empty($_POST['incluir_destinos']),
        'incluir_acesso'    => !empty($_POST['incluir_acesso']),
        'incluir_deps'      => !empty($_POST['incluir_deps']),
        'tipos_deps'        => array_values(array_intersect(
            array_map('strval', (array) ($_POST['tipos'] ?? [])),
            array_keys(PluginCatalogoeformulariosPacote::todosTipos())
        )),
    ]);

    $json  = PluginCatalogoeformulariosExportacao::paraJson($pacote);
    $token = (string) PluginCatalogoeformulariosPacote::guardarTemp($json);

    if ($token === '') {
        Session::addMessageAfterRedirect('Nao foi possivel preparar a previa do arquivo.', false, ERROR);
    } else {
        $resumo = PluginCatalogoeformulariosExportacao::resumo($pacote);
        $man    = (array) $pacote['manifest'];
        $etapa  = 'revisao';
    }
}

Html::header(
    'Exportar catalogo',
    $_SERVER['PHP_SELF'],
    'tools',
    'PluginCatalogoeformulariosMenu',
    'exportar'
);

echo '<link rel="stylesheet" type="text/css" href="' . $base . '/public/css/pacote.css?v=' . $v . '">';

echo '<div class="cdspac-wrap">';

echo '<div class="cdspac-topo">';
echo '  <div>';
echo '    <span class="cdspac-titulo"><i class="ti ti-download"></i> Exportar catalogo de servicos</span>';
echo '    <p class="cdspac-sub"><i class="ti ti-info-circle"></i> Gera um arquivo <strong>.json</strong> com as categorias do catalogo, os formularios e a definicao de tudo que eles usam: categorias ITIL, grupos (requerente, observador e atribuido), SLAs, OLAs, calendarios, localizacoes, perfis e entidades.</p>';
echo '  </div>';
echo '  <a href="' . $base . '/front/importar.php" class="cdspac-btn cdspac-btn-neutro"><i class="ti ti-upload"></i> Ir para a importacao</a>';
echo '</div>';

$etapas = ['selecao' => 'Escolher o conteudo', 'revisao' => 'Conferir e baixar'];
echo '<div class="cdspac-passos">';
foreach ($etapas as $chave => $rotulo) {
    $classe = $chave === $etapa ? ' cdspac-passo-ativo' : '';
    echo '<span class="cdspac-passo' . $classe . '">' . $rotulo . '</span>';
}
echo '</div>';

// =======================================================================
// ETAPA 1 - selecao
// =======================================================================
if ($etapa === 'selecao') {
    $listas = PluginCatalogoeformulariosExportacao::listasSelecao();

    if (empty($listas['categorias']) && empty($listas['formularios'])) {
        echo '<div class="cdspac-alerta cdspac-alerta-info"><i class="ti ti-mood-empty"></i> Nenhuma categoria ou formulario encontrado para exportar.</div>';
        echo '</div>';
        Html::footer();
        return;
    }

    echo '<form method="post" action="' . $base . '/front/exportar.php" id="cdspac-form-export">';
    echo plugin_catalogoeformularios_campo_csrf();
    echo '<input type="hidden" name="acao" value="revisar">';

    // ---- Area: o que compoe o arquivo ----------------------------------
    echo '<div class="card cdspac-card">';
    echo '  <div class="card-header cdspac-card-header"><h5><i class="ti ti-settings"></i> O que incluir no arquivo</h5></div>';
    echo '  <div class="cdspac-card-body">';
    echo '    <div class="cdspac-opcoes">';

    $opcoes = [
        'incluir_forms_cat' => ['Formularios das categorias marcadas', 'Ao marcar uma categoria, leva junto os formularios dela e das subcategorias.'],
        'incluir_destinos'  => ['Destinos dos formularios', 'Categoria ITIL, grupos requerente/observador/atribuido, SLAs, OLAs, modelo e demais campos do chamado gerado.'],
        'incluir_acesso'    => ['Controle de acesso', 'Perfis, usuarios, grupos e entidades autorizados a ver cada formulario.'],
        'incluir_deps'      => ['Definicao das dependencias', 'Leva a descricao completa dos itens marcados abaixo para que possam ser recriados no destino.'],
    ];

    foreach ($opcoes as $nome => $texto) {
        echo '      <label class="cdspac-opcao">';
        echo '        <input type="checkbox" name="' . $nome . '" value="1" checked>';
        echo '        <span><strong>' . $texto[0] . '</strong><small>' . $texto[1] . '</small></span>';
        echo '      </label>';
    }

    echo '    </div>';
    echo '  </div>';
    echo '</div>';

    // ---- Area: categorias do catalogo ----------------------------------
    echo '<div class="card cdspac-card">';
    echo '  <div class="card-header cdspac-card-header">';
    echo '    <h5><i class="ti ti-list"></i> Categorias do catalogo <span class="cdspac-badge">' . count((array) $listas['categorias']) . '</span></h5>';
    echo '    <div class="cdspac-header-acoes">';
    echo '      <input type="text" class="form-control form-control-sm cdspac-busca" placeholder="Filtrar..." onkeyup="cdspacFiltrar(this, \'cdsexp-sel-cats\')">';
    echo '      <label class="cdspac-mini"><input type="checkbox" onchange="cdspacMarcarTodos(\'cdsexp-sel-cats\', this.checked)"> Marcar todas</label>';
    echo '    </div>';
    echo '  </div>';
    echo '  <div class="cdspac-card-body" id="cdsexp-sel-cats">';

    if (empty($listas['categorias'])) {
        echo '<div class="cdspac-linha"><span class="cdspac-item cdspac-item-virtual">Nenhuma categoria cadastrada.</span></div>';
    }

    foreach ((array) $listas['categorias'] as $cat) {
        $nivel = (int) ($cat['nivel'] ?? 0);
        echo '<div class="cdspac-linha" data-search="' . htmlspecialchars(strtolower((string) $cat['caminho'])) . '" style="padding-left:' . (10 + $nivel * 18) . 'px;">';
        echo '  <label class="cdspac-item">';
        echo '    <input type="checkbox" name="categorias[]" value="' . (int) $cat['id'] . '">';
        echo '    <i class="ti ti-folder"></i> <span>' . htmlspecialchars((string) $cat['nome']) . '</span>';
        echo '  </label>';
        echo '  <span class="cdspac-badge">' . (int) $cat['qtd'] . ' formulario(s)</span>';
        if (!empty($cat['ilustracao'])) {
            echo '  <span class="cdspac-badge"><i class="ti ti-photo"></i> icone</span>';
        }
        echo '</div>';
    }

    echo '  </div>';
    echo '</div>';

    // ---- Area: formularios ----------------------------------------------
    echo '<div class="card cdspac-card">';
    echo '  <div class="card-header cdspac-card-header">';
    echo '    <h5><i class="ti ti-file-text"></i> Formularios <span class="cdspac-badge">' . count((array) $listas['formularios']) . '</span></h5>';
    echo '    <div class="cdspac-header-acoes">';
    echo '      <input type="text" class="form-control form-control-sm cdspac-busca" placeholder="Filtrar..." onkeyup="cdspacFiltrar(this, \'cdsexp-sel-forms\')">';
    echo '      <label class="cdspac-mini"><input type="checkbox" onchange="cdspacMarcarTodos(\'cdsexp-sel-forms\', this.checked)"> Marcar todos</label>';
    echo '    </div>';
    echo '  </div>';
    echo '  <div class="cdspac-card-body" id="cdsexp-sel-forms">';

    if (empty($listas['formularios'])) {
        echo '<div class="cdspac-linha"><span class="cdspac-item cdspac-item-virtual">Nenhum formulario cadastrado.</span></div>';
    }

    foreach ((array) $listas['formularios'] as $f) {
        echo '<div class="cdspac-linha" data-search="' . htmlspecialchars(strtolower($f['nome'] . ' ' . $f['categoria'])) . '">';
        echo '  <label class="cdspac-item">';
        echo '    <input type="checkbox" name="formularios[]" value="' . (int) $f['id'] . '">';
        echo '    <i class="ti ti-file-text"></i> <span>' . htmlspecialchars((string) $f['nome']) . '</span>';
        echo '  </label>';
        echo '  <span class="cdspac-caminho">' . htmlspecialchars((string) $f['categoria']) . '</span>';
        if (empty($f['ativo'])) {
            echo '  <span class="cdspac-pill cdspac-pill-off">inativo</span>';
        } else {
            echo '  <span class="cdspac-pill cdspac-pill-on">ativo</span>';
        }
        if (!empty($f['rascunho'])) {
            echo '  <span class="cdspac-pill cdspac-pill-warn">rascunho</span>';
        }
        echo '</div>';
    }

    echo '  </div>';
    echo '</div>';

    // ---- Area: tipos de item do GLPI ------------------------------------
    $tipos = PluginCatalogoeformulariosPacote::todosTipos();

    echo '<div class="card cdspac-card">';
    echo '  <div class="card-header cdspac-card-header">';
    echo '    <h5><i class="ti ti-puzzle"></i> Itens do GLPI que os formularios usam <span class="cdspac-badge">' . count($tipos) . ' tipos</span></h5>';
    echo '    <div class="cdspac-header-acoes">';
    echo '      <label class="cdspac-mini"><input type="checkbox" checked onchange="cdspacMarcarTodos(\'cdsexp-sel-tipos\', this.checked)"> Marcar todos</label>';
    echo '    </div>';
    echo '  </div>';
    echo '  <div class="cdspac-card-body" id="cdsexp-sel-tipos">';
    echo '    <p class="cdspac-nota"><i class="ti ti-info-circle"></i> Marque os tipos cuja <strong>definicao</strong> deve viajar no arquivo. So entram os itens realmente referenciados pelos formularios escolhidos acima; a proxima tela lista um por um.</p>';

    foreach ($tipos as $itemtype => $info) {
        echo '<div class="cdspac-linha" data-search="' . htmlspecialchars(strtolower((string) $info['label'])) . '">';
        echo '  <label class="cdspac-item">';
        echo '    <input type="checkbox" name="tipos[]" value="' . htmlspecialchars((string) $itemtype) . '" checked>';
        echo '    <i class="' . htmlspecialchars((string) $info['icone']) . '"></i> <span>' . htmlspecialchars((string) $info['label']) . '</span>';
        echo '  </label>';
        if (empty($info['criavel'])) {
            echo '  <span class="cdspac-pill cdspac-pill-warn">nao e recriado na importacao</span>';
        } else {
            echo '  <span class="cdspac-pill cdspac-pill-new">pode ser recriado no destino</span>';
        }
        echo '</div>';
    }

    echo '  </div>';
    echo '</div>';

    echo '<div class="cdspac-rodape">';
    echo '  <p class="cdspac-nota"><i class="ti ti-info-circle"></i> Os pais das categorias marcadas entram automaticamente para que a hierarquia seja reconstruida no destino. A proxima tela mostra o inventario completo, item por item, antes do download.</p>';
    echo '  <button type="submit" class="cdspac-btn cdspac-btn-salvar"><i class="ti ti-search"></i> Conferir o que sera exportado</button>';
    echo '</div>';

    echo '</form>';
}

// =======================================================================
// ETAPA 2 - revisao do inventario
// =======================================================================
if ($etapa === 'revisao' && is_array($resumo)) {
    $totalDeps = 0;
    foreach ((array) ($resumo['dependencias'] ?? []) as $bloco) {
        $totalDeps += count((array) $bloco['itens']);
    }

    echo '<div class="cdspac-resumo">';
    $cartoes = [
        ['Categorias do catalogo', count((array) $resumo['categorias']), 'ti ti-list'],
        ['Formularios',            count((array) $resumo['formularios']), 'ti ti-file-text'],
        ['Itens do GLPI',          $totalDeps, 'ti ti-puzzle'],
    ];
    foreach ($cartoes as $c) {
        echo '<div class="cdspac-cartao"><i class="' . $c[2] . '"></i><span class="cdspac-cartao-num">' . $c[1] . '</span><span class="cdspac-cartao-txt">' . $c[0] . '</span></div>';
    }
    echo '</div>';

    echo '<div class="card cdspac-card">';
    echo '  <div class="card-header cdspac-card-header"><h5><i class="ti ti-notes"></i> Identificacao do arquivo</h5></div>';
    echo '  <div class="cdspac-card-body">';
    echo '    <div class="cdspac-meta">';
    echo '      <span><strong>Gerado em:</strong> ' . htmlspecialchars((string) ($man['gerado_em'] ?? '-')) . '</span>';
    echo '      <span><strong>Por:</strong> ' . htmlspecialchars((string) ($man['gerado_por'] ?? '-')) . '</span>';
    echo '      <span><strong>GLPI:</strong> ' . htmlspecialchars((string) ($man['glpi_versao'] ?? '-')) . '</span>';
    echo '      <span><strong>Entidade de origem:</strong> ' . htmlspecialchars((string) ($man['entidade_origem']['nome'] ?? '-')) . '</span>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';

    echo '<form method="post" action="' . $base . '/front/exportar.php">';
    echo plugin_catalogoeformularios_campo_csrf();
    echo '<input type="hidden" name="acao" value="baixar">';
    echo '<input type="hidden" name="token" value="' . htmlspecialchars($token) . '">';

    // ---- Area: categorias do catalogo ----------------------------------
    if (!empty($resumo['categorias'])) {
        echo '<div class="card cdspac-card">';
        echo '  <div class="card-header cdspac-card-header">';
        echo '    <h5><i class="ti ti-list"></i> Categorias do catalogo <span class="cdspac-badge">' . count((array) $resumo['categorias']) . '</span></h5>';
        echo '    <div class="cdspac-header-acoes">';
        echo '      <input type="text" class="form-control form-control-sm cdspac-busca" placeholder="Filtrar..." onkeyup="cdspacFiltrar(this, \'cdsexp-cats\')">';
        echo '    </div>';
        echo '  </div>';
        echo '  <div class="cdspac-card-body" id="cdsexp-cats">';
        foreach ((array) $resumo['categorias'] as $c) {
            echo '<div class="cdspac-linha" data-search="' . htmlspecialchars(strtolower((string) $c['caminho'])) . '">';
            echo '  <span class="cdspac-item cdspac-item-virtual"><i class="ti ti-folder"></i> ' . htmlspecialchars((string) $c['caminho']) . '</span>';
            if (!empty($c['ilustracao'])) {
                echo '  <span class="cdspac-badge"><i class="ti ti-photo"></i> icone</span>';
            }
            echo '</div>';
        }
        echo '  </div>';
        echo '</div>';
    }

    // ---- Area: formularios ---------------------------------------------
    if (!empty($resumo['formularios'])) {
        echo '<div class="card cdspac-card">';
        echo '  <div class="card-header cdspac-card-header">';
        echo '    <h5><i class="ti ti-file-text"></i> Formularios <span class="cdspac-badge">' . count((array) $resumo['formularios']) . '</span></h5>';
        echo '    <div class="cdspac-header-acoes">';
        echo '      <input type="text" class="form-control form-control-sm cdspac-busca" placeholder="Filtrar..." onkeyup="cdspacFiltrar(this, \'cdsexp-forms\')">';
        echo '    </div>';
        echo '  </div>';
        echo '  <div class="cdspac-card-body" id="cdsexp-forms">';

        foreach ((array) $resumo['formularios'] as $f) {
            echo '<div class="cdspac-linha cdspac-linha-bloco" data-search="' . htmlspecialchars(strtolower($f['nome'] . ' ' . $f['categoria_txt'])) . '">';
            echo '  <div class="cdspac-linha-topo">';
            echo '    <span class="cdspac-item cdspac-item-virtual"><i class="ti ti-file-text"></i> <strong>' . htmlspecialchars((string) $f['nome']) . '</strong></span>';
            echo '    <span class="cdspac-caminho">' . htmlspecialchars((string) $f['categoria_txt']) . '</span>';
            echo '    <span class="cdspac-badge">' . (int) $f['qtd_secoes'] . ' secao(oes)</span>';
            echo '    <span class="cdspac-badge">' . (int) $f['qtd_perguntas'] . ' pergunta(s)</span>';
            if ((int) $f['qtd_destinos'] > 0) {
                echo '    <span class="cdspac-badge">' . (int) $f['qtd_destinos'] . ' destino(s)</span>';
            }
            if ((int) $f['qtd_acesso'] > 0) {
                echo '    <span class="cdspac-badge">' . (int) $f['qtd_acesso'] . ' regra(s) de acesso</span>';
            }
            echo '    <span class="cdspac-pill ' . (!empty($f['ativo']) ? 'cdspac-pill-on">ativo' : 'cdspac-pill-off">inativo') . '</span>';
            echo '  </div>';

            if (!empty($f['usa'])) {
                echo '  <div class="cdspac-usa">';
                foreach ((array) $f['usa'] as $g) {
                    echo '    <div class="cdspac-usa-grupo">';
                    echo '      <span class="cdspac-usa-label"><i class="' . htmlspecialchars((string) $g['icone']) . '"></i> ' . htmlspecialchars((string) $g['label']) . '</span>';
                    foreach ((array) $g['itens'] as $n) {
                        echo '      <span class="cdspac-tag">' . htmlspecialchars((string) $n) . '</span>';
                    }
                    echo '    </div>';
                }
                echo '  </div>';
            }

            echo '</div>';
        }

        echo '  </div>';
        echo '</div>';
    }

    // ---- Uma area por tipo de item do GLPI ------------------------------
    if (!empty($resumo['dependencias'])) {
        foreach ((array) $resumo['dependencias'] as $bloco) {
            $itemtype = (string) $bloco['itemtype'];
            $idArea   = 'cdsexp-dep-' . preg_replace('/[^A-Za-z0-9]/', '', $itemtype);

            echo '<div class="card cdspac-card">';
            echo '  <div class="card-header cdspac-card-header">';
            echo '    <h5><i class="' . htmlspecialchars((string) $bloco['icone']) . '"></i> ' . htmlspecialchars((string) $bloco['label']) . ' <span class="cdspac-badge">' . count((array) $bloco['itens']) . '</span></h5>';
            echo '    <div class="cdspac-header-acoes">';
            echo '      <input type="text" class="form-control form-control-sm cdspac-busca" placeholder="Filtrar..." onkeyup="cdspacFiltrar(this, \'' . $idArea . '\')">';
            echo '      <label class="cdspac-mini"><input type="checkbox" checked onchange="cdspacMarcarTodos(\'' . $idArea . '\', this.checked)"> Incluir todos</label>';
            echo '    </div>';
            echo '  </div>';
            echo '  <div class="cdspac-card-body" id="' . $idArea . '">';

            foreach ((array) $bloco['itens'] as $item) {
                echo '<div class="cdspac-linha" data-search="' . htmlspecialchars(strtolower((string) $item['txt'])) . '">';
                echo '  <label class="cdspac-item">';
                echo '    <input type="checkbox" name="deps[' . htmlspecialchars($itemtype) . '][]" value="' . (int) $item['id'] . '" checked>';
                echo '    <span>' . htmlspecialchars((string) $item['txt']) . '</span>';
                echo '  </label>';
                echo '</div>';
            }

            echo '  </div>';
            echo '</div>';
        }
    } else {
        echo '<div class="cdspac-alerta cdspac-alerta-warning"><i class="ti ti-alert-triangle"></i> O arquivo nao leva a definicao das dependencias. Ao importar em um GLPI que nao tenha esses itens, as referencias ficarao vazias.</div>';
    }

    echo '<div class="cdspac-rodape">';
    echo '  <p class="cdspac-nota"><i class="ti ti-info-circle"></i> Desmarcar um item acima tira apenas a <strong>definicao</strong> dele do arquivo: o formulario continua apontando para o item, mas o destino precisara ja ter algo equivalente. Ao desmarcar um item pai de uma arvore, os filhos entram na raiz no destino.</p>';
    echo '  <a href="' . $base . '/front/exportar.php" class="cdspac-btn cdspac-btn-neutro"><i class="ti ti-arrow-left"></i> Refazer a selecao</a>';
    echo '  <button type="submit" class="cdspac-btn cdspac-btn-salvar"><i class="ti ti-download"></i> Baixar o arquivo .json</button>';
    echo '</div>';

    echo '</form>';
}

echo '</div>';

echo '<script src="' . $base . '/public/js/pacote.js?v=' . $v . '"></script>';

Html::footer();
