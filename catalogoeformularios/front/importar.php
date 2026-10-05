<?php


Session::checkLoginUser();

// Importar escreve no banco: exige permissao de edicao do catalogo.
if (!PluginCatalogoeformulariosConfig::podeEditar()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

global $CFG_GLPI;

$base = $CFG_GLPI['root_doc'] . '/plugins/catalogoeformularios';
$v    = '3';

PluginCatalogoeformulariosPacote::limparTempAntigos();

$etapa   = 'inicio';
$analise = null;
$token   = '';

/** Uma linha de resolucao de conflito (acao + destino + nome novo). */
$linhaAcao = static function (string $campoBase, string $tipo, array $item, array $acoes): void {
    $acaoAtual = (string) ($item['acao'] ?? 'reutilizar');
    $existeId  = (int) ($item['existe_id'] ?? 0);
    $nomeNovo  = (string) ($item['nome_novo'] ?? $item['nome'] ?? '');

    echo '<div class="cdspac-acao" data-tipo="' . htmlspecialchars($tipo) . '">';

    echo '  <select name="' . $campoBase . '[acao]" class="form-select form-select-sm cdspac-sel-acao" onchange="cdspacTrocarAcao(this)">';
    foreach ($acoes as $a) {
        $sel = $a === $acaoAtual ? ' selected' : '';
        echo '<option value="' . htmlspecialchars($a) . '"' . $sel . '>' . htmlspecialchars(PluginCatalogoeformulariosPacote::rotuloAcao($a)) . '</option>';
    }
    echo '  </select>';

    echo '  <select name="' . $campoBase . '[alvo]" class="form-select form-select-sm cdspac-sel-alvo" data-selecionado="' . $existeId . '"></select>';

    echo '  <input type="text" name="' . $campoBase . '[nome]" class="form-control form-control-sm cdspac-inp-nome" value="' . htmlspecialchars($nomeNovo) . '" placeholder="Nome do novo item" maxlength="250">';

    echo '</div>';
};

// -----------------------------------------------------------------------
// ETAPA 3 - executa e redireciona (POST -> GET) para o F5 nao reenviar.
// -----------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['acao'] ?? '') === 'importar') {
    $token    = (string) ($_POST['token'] ?? '');
    $conteudo = PluginCatalogoeformulariosPacote::lerTemp($token);

    if ($conteudo === null) {
        Session::addMessageAfterRedirect('O arquivo enviado expirou. Envie o pacote novamente.', false, ERROR);
    } else {
        $pacote = json_decode($conteudo, true);

        if (!is_array($pacote)) {
            Session::addMessageAfterRedirect('Nao foi possivel ler o pacote enviado.', false, ERROR);
        } else {
            $deps = [];
            foreach ((array) ($_POST['deps'] ?? []) as $itemtype => $itens) {
                foreach ((array) $itens as $idOrigem => $conf) {
                    $deps[(string) $itemtype][(string) (int) $idOrigem] = (array) $conf;
                }
            }

            $cats = [];
            foreach ((array) ($_POST['cats'] ?? []) as $idOrigem => $conf) {
                $cats[(string) (int) $idOrigem] = (array) $conf;
            }

            $forms = [];
            foreach ((array) ($_POST['forms'] ?? []) as $idOrigem => $conf) {
                $forms[(string) (int) $idOrigem] = (array) $conf;
            }

            $relatorio = PluginCatalogoeformulariosImportacao::executar($pacote, [
                'entidade' => (int) ($_POST['entidade'] ?? 0),
                'ativar'   => !empty($_POST['ativar']),
                'deps'     => $deps,
                'cats'     => $cats,
                'forms'    => $forms,
            ]);

            $_SESSION['plugin_catalogoeformularios_relatorio'] = $relatorio;
            PluginCatalogoeformulariosPacote::limparTemp($token);

            Session::addMessageAfterRedirect('Importacao concluida.', true, INFO);
        }
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Location: ' . $base . '/front/importar.php?etapa=fim');
    exit;
}

// -----------------------------------------------------------------------
// ETAPA 2 - recebe o arquivo e monta a previa.
// -----------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['acao'] ?? '') === 'analisar') {
    $arquivo = $_FILES['pacote'] ?? null;

    if (!is_array($arquivo) || (int) ($arquivo['error'] ?? 1) !== UPLOAD_ERR_OK) {
        Session::addMessageAfterRedirect('Selecione um arquivo .json valido.', false, ERROR);
    } else {
        $conteudo = (string) file_get_contents($arquivo['tmp_name']);
        $analise  = PluginCatalogoeformulariosImportacao::analisar($conteudo);

        if (empty($analise['ok'])) {
            Session::addMessageAfterRedirect((string) ($analise['msg'] ?? 'Arquivo invalido.'), false, ERROR);
            $analise = null;
        } else {
            $token = (string) PluginCatalogoeformulariosPacote::guardarTemp($conteudo);
            if ($token === '') {
                Session::addMessageAfterRedirect('Nao foi possivel armazenar o arquivo temporariamente.', false, ERROR);
                $analise = null;
            } else {
                $etapa = 'previa';
            }
        }
    }
}

if (($_GET['etapa'] ?? '') === 'fim') {
    $etapa = 'fim';
}

Html::header(
    'Importar catalogo',
    $_SERVER['PHP_SELF'],
    'tools',
    'PluginCatalogoeformulariosMenu',
    'importar'
);

echo '<link rel="stylesheet" type="text/css" href="' . $base . '/public/css/pacote.css?v=' . $v . '">';

echo '<div class="cdspac-wrap">';

echo '<div class="cdspac-topo">';
echo '  <div>';
echo '    <span class="cdspac-titulo"><i class="ti ti-upload"></i> Importar catalogo de servicos</span>';
echo '    <p class="cdspac-sub"><i class="ti ti-info-circle"></i> Envie um arquivo gerado pela exportacao. Nada e gravado antes de voce revisar item por item e confirmar.</p>';
echo '  </div>';
echo '  <a href="' . $base . '/front/exportar.php" class="cdspac-btn cdspac-btn-neutro"><i class="ti ti-download"></i> Ir para a exportacao</a>';
echo '</div>';

$etapas = ['inicio' => 'Enviar arquivo', 'previa' => 'Revisar e resolver', 'fim' => 'Resultado'];
echo '<div class="cdspac-passos">';
foreach ($etapas as $chave => $rotulo) {
    $classe = $chave === $etapa ? ' cdspac-passo-ativo' : '';
    echo '<span class="cdspac-passo' . $classe . '">' . $rotulo . '</span>';
}
echo '</div>';

// =======================================================================
// ETAPA 1 - envio
// =======================================================================
if ($etapa === 'inicio') {
    echo '<form method="post" action="' . $base . '/front/importar.php" enctype="multipart/form-data">';
    echo plugin_catalogoeformularios_campo_csrf();
    echo '<input type="hidden" name="acao" value="analisar">';

    echo '<div class="card cdspac-card">';
    echo '  <div class="card-header cdspac-card-header"><h5><i class="ti ti-file"></i> Arquivo do pacote</h5></div>';
    echo '  <div class="cdspac-card-body">';
    echo '    <div class="cdspac-campo">';
    echo '      <label for="pacote">Arquivo .json exportado pelo catalogo</label>';
    echo '      <input type="file" id="pacote" name="pacote" accept=".json,application/json" class="form-control form-control-sm" required>';
    echo '    </div>';
    echo '    <p class="cdspac-nota"><i class="ti ti-info-circle"></i> A proxima tela lista tudo que existe no arquivo, compara com este GLPI pelos nomes e deixa voce decidir, para cada item, se reaproveita, aponta para outro, cria novo ou substitui.</p>';
    echo '  </div>';
    echo '</div>';

    echo '<div class="cdspac-rodape">';
    echo '  <button type="submit" class="cdspac-btn cdspac-btn-salvar"><i class="ti ti-search"></i> Analisar o arquivo</button>';
    echo '</div>';
    echo '</form>';
}

// =======================================================================
// ETAPA 2 - previa e resolucao de conflitos
// =======================================================================
if ($etapa === 'previa' && is_array($analise)) {
    $man = (array) ($analise['manifest'] ?? []);

    // Candidatos dos seletores "apontar para", carregados uma vez por tipo.
    $alvos = [
        '__categoria__'  => PluginCatalogoeformulariosImportacao::candidatos('__categoria__'),
        '__formulario__' => PluginCatalogoeformulariosImportacao::candidatos('__formulario__'),
    ];
    foreach ((array) $analise['dependencias'] as $bloco) {
        $it = (string) $bloco['itemtype'];
        if (!isset($alvos[$it])) {
            $alvos[$it] = PluginCatalogoeformulariosImportacao::candidatos($it);
        }
    }

    $totalDeps = 0;
    $conflitos = 0;
    foreach ((array) $analise['dependencias'] as $bloco) {
        $totalDeps += count((array) $bloco['itens']);
        foreach ((array) $bloco['itens'] as $i) {
            if (!empty($i['existe'])) {
                $conflitos++;
            }
        }
    }
    foreach ((array) $analise['categorias'] as $c) {
        if (!empty($c['existe'])) {
            $conflitos++;
        }
    }
    foreach ((array) $analise['formularios'] as $f) {
        if (!empty($f['existe'])) {
            $conflitos++;
        }
    }

    echo '<div class="cdspac-resumo">';
    $cartoes = [
        ['Categorias no arquivo', count((array) $analise['categorias']), 'ti ti-folder'],
        ['Formularios no arquivo', count((array) $analise['formularios']), 'ti ti-file-text'],
        ['Itens de dependencia', $totalDeps, 'ti ti-puzzle'],
        ['Ja existem aqui', $conflitos, 'ti ti-alert-triangle'],
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
    echo '      <span><strong>GLPI de origem:</strong> ' . htmlspecialchars((string) ($man['glpi_versao'] ?? '-')) . '</span>';
    echo '      <span><strong>Entidade de origem:</strong> ' . htmlspecialchars((string) ($man['entidade_origem']['nome'] ?? '-')) . '</span>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';

    foreach ((array) ($analise['avisos'] ?? []) as $aviso) {
        echo '<div class="cdspac-alerta cdspac-alerta-warning"><i class="ti ti-alert-triangle"></i> ' . htmlspecialchars($aviso) . '</div>';
    }

    echo '<div class="cdspac-alerta cdspac-alerta-info"><i class="ti ti-info-circle"></i> <strong>Como funciona cada acao.</strong> <em>Usar o que ja existe</em> reaproveita o item encontrado aqui pelo nome. <em>Apontar para outro item</em> troca a referencia por um item deste GLPI que voce escolhe. <em>Criar novo</em> cria um item separado com o nome que voce definir. <em>Substituir o existente</em> sobrescreve o item daqui com o que veio no arquivo. <em>Nao importar</em> deixa a referencia vazia.</div>';

    echo '<form method="post" action="' . $base . '/front/importar.php" id="cdspac-form-import">';
    echo plugin_catalogoeformularios_campo_csrf();
    echo '<input type="hidden" name="acao" value="importar">';
    echo '<input type="hidden" name="token" value="' . htmlspecialchars($token) . '">';

    // ---- Destino -------------------------------------------------------
    $entidadePadrao = PluginCatalogoeformulariosCatalogo::getEntidadePadrao();
    if ($entidadePadrao <= 0) {
        $entidadePadrao = (int) ($_SESSION['glpiactive_entity'] ?? 0);
    }

    echo '<div class="card cdspac-card">';
    echo '  <div class="card-header cdspac-card-header"><h5><i class="ti ti-building"></i> Destino da importacao</h5></div>';
    echo '  <div class="cdspac-card-body">';
    echo '    <div class="cdspac-linha-campos">';
    echo '      <div class="cdspac-grupo"><label>Entidade onde tudo que for criado sera gravado</label><span>';
    Entity::dropdown([
        'name'    => 'entidade',
        'value'   => $entidadePadrao,
        'entity'  => $_SESSION['glpiactiveentities'] ?? [],
        'display' => true,
        'width'   => '320px',
    ]);
    echo '      </span></div>';
    echo '    </div>';

    echo '    <div class="cdspac-opcoes">';
    echo '      <label class="cdspac-opcao"><input type="checkbox" name="ativar" value="1"><span><strong>Ativar os formularios importados</strong><small>Sem marcar, os formularios entram inativos para revisao antes de aparecer no catalogo.</small></span></label>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';

    // ---- Uma area por tipo de item do GLPI ------------------------------
    foreach ((array) $analise['dependencias'] as $bloco) {
        $itemtype = (string) $bloco['itemtype'];
        $acoes    = (array) $bloco['acoes'];
        $idArea   = 'cdspac-dep-' . preg_replace('/[^A-Za-z0-9]/', '', $itemtype);

        echo '<div class="card cdspac-card">';
        echo '  <div class="card-header cdspac-card-header">';
        echo '    <h5><i class="' . htmlspecialchars((string) $bloco['icone']) . '"></i> ' . htmlspecialchars((string) $bloco['label']) . ' <span class="cdspac-badge">' . count((array) $bloco['itens']) . '</span>';
        if (empty($bloco['criavel'])) {
            echo ' <span class="cdspac-pill cdspac-pill-warn">nao pode ser criado pelo pacote</span>';
        }
        echo '    </h5>';
        echo '    <div class="cdspac-header-acoes">';
        echo '      <input type="text" class="form-control form-control-sm cdspac-busca" placeholder="Filtrar..." onkeyup="cdspacFiltrar(this, \'' . $idArea . '\')">';
        echo '      <span class="cdspac-lote"><label>Aplicar a todos:</label>';
        echo '        <select class="form-select form-select-sm" onchange="cdspacAplicarLote(this)">';
        echo '          <option value="">-</option>';
        foreach ($acoes as $a) {
            echo '<option value="' . htmlspecialchars($a) . '">' . htmlspecialchars(PluginCatalogoeformulariosPacote::rotuloAcao($a)) . '</option>';
        }
        echo '        </select>';
        echo '      </span>';
        echo '    </div>';
        echo '  </div>';
        echo '  <div class="cdspac-card-body" id="' . $idArea . '">';

        foreach ((array) $bloco['itens'] as $item) {
            $rotulo = (string) (($item['caminho'] ?? '') !== '' ? $item['caminho'] : $item['nome']);
            echo '<div class="cdspac-linha cdspac-linha-item" data-search="' . htmlspecialchars(strtolower($bloco['label'] . ' ' . $rotulo)) . '">';
            echo '  <div class="cdspac-linha-topo">';
            echo '    <span class="cdspac-item cdspac-item-virtual"><i class="ti ti-arrow-right"></i> ' . htmlspecialchars($rotulo) . '</span>';
            if (!empty($item['existe'])) {
                echo '    <span class="cdspac-pill cdspac-pill-on">ja existe: ' . htmlspecialchars((string) $item['existe_txt']) . '</span>';
            } else {
                echo '    <span class="cdspac-pill cdspac-pill-new">nao existe aqui</span>';
            }
            echo '  </div>';

            $campo = 'deps[' . htmlspecialchars($itemtype) . '][' . (int) $item['id_origem'] . ']';
            $linhaAcao($campo, $itemtype, $item, $acoes);

            echo '</div>';
        }

        echo '  </div>';
        echo '</div>';
    }

    // ---- Categorias do catalogo ----------------------------------------
    if (!empty($analise['categorias'])) {
        $acoesCat = ['reutilizar', 'apontar', 'novo', 'substituir', 'ignorar'];

        echo '<div class="card cdspac-card">';
        echo '  <div class="card-header cdspac-card-header">';
        echo '    <h5><i class="ti ti-list"></i> Categorias do catalogo (' . count((array) $analise['categorias']) . ')</h5>';
        echo '    <div class="cdspac-header-acoes">';
        echo '      <input type="text" class="form-control form-control-sm cdspac-busca" placeholder="Filtrar..." onkeyup="cdspacFiltrar(this, \'cdspac-cats\')">';
        echo '      <span class="cdspac-lote"><label>Aplicar a todos:</label>';
        echo '        <select class="form-select form-select-sm" onchange="cdspacAplicarLote(this)">';
        echo '          <option value="">-</option>';
        foreach ($acoesCat as $a) {
            echo '<option value="' . htmlspecialchars($a) . '">' . htmlspecialchars(PluginCatalogoeformulariosPacote::rotuloAcao($a)) . '</option>';
        }
        echo '        </select>';
        echo '      </span>';
        echo '    </div>';
        echo '  </div>';
        echo '  <div class="cdspac-card-body" id="cdspac-cats">';

        foreach ((array) $analise['categorias'] as $cat) {
            $rotulo = (string) ($cat['caminho'] !== '' ? $cat['caminho'] : $cat['nome']);
            echo '<div class="cdspac-linha cdspac-linha-item" data-search="' . htmlspecialchars(strtolower($rotulo)) . '">';
            echo '  <div class="cdspac-linha-topo">';
            echo '    <span class="cdspac-item cdspac-item-virtual"><i class="ti ti-folder"></i> ' . htmlspecialchars($rotulo) . '</span>';
            if (!empty($cat['existe'])) {
                echo '    <span class="cdspac-pill cdspac-pill-on">ja existe aqui</span>';
            } else {
                echo '    <span class="cdspac-pill cdspac-pill-new">nao existe aqui</span>';
            }
            if (!empty($cat['ilustracao'])) {
                echo '    <span class="cdspac-badge"><i class="ti ti-photo"></i> icone</span>';
            }
            echo '  </div>';

            $linhaAcao('cats[' . (int) $cat['id_origem'] . ']', '__categoria__', $cat, $acoesCat);

            echo '</div>';
        }

        echo '  </div>';
        echo '</div>';
    }

    // ---- Formularios ---------------------------------------------------
    if (!empty($analise['formularios'])) {
        $acoesForm = ['novo', 'substituir', 'ignorar'];

        echo '<div class="card cdspac-card">';
        echo '  <div class="card-header cdspac-card-header">';
        echo '    <h5><i class="ti ti-file-text"></i> Formularios (' . count((array) $analise['formularios']) . ')</h5>';
        echo '    <div class="cdspac-header-acoes">';
        echo '      <input type="text" class="form-control form-control-sm cdspac-busca" placeholder="Filtrar..." onkeyup="cdspacFiltrar(this, \'cdspac-forms\')">';
        echo '      <span class="cdspac-lote"><label>Aplicar a todos:</label>';
        echo '        <select class="form-select form-select-sm" onchange="cdspacAplicarLote(this)">';
        echo '          <option value="">-</option>';
        foreach ($acoesForm as $a) {
            echo '<option value="' . htmlspecialchars($a) . '">' . htmlspecialchars(PluginCatalogoeformulariosPacote::rotuloAcao($a)) . '</option>';
        }
        echo '        </select>';
        echo '      </span>';
        echo '    </div>';
        echo '  </div>';
        echo '  <div class="cdspac-card-body" id="cdspac-forms">';

        foreach ((array) $analise['formularios'] as $f) {
            echo '<div class="cdspac-linha cdspac-linha-item" data-search="' . htmlspecialchars(strtolower($f['nome'] . ' ' . $f['categoria_txt'])) . '">';
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
            if (!empty($f['existe'])) {
                echo '    <span class="cdspac-pill cdspac-pill-warn">ja existe um com este nome</span>';
            }
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

            $linhaAcao('forms[' . (int) $f['id_origem'] . ']', '__formulario__', $f, $acoesForm);

            echo '</div>';
        }

        echo '  </div>';
        echo '</div>';
    }

    echo '<div class="cdspac-rodape">';
    echo '  <p class="cdspac-nota"><i class="ti ti-alert-triangle"></i> <strong>Substituir</strong> sobrescreve um item que ja vive neste GLPI e nao tem desfazer. Em grupos, categorias ITIL e SLAs, isso afeta tambem chamados e regras que nada tem a ver com este catalogo. Em formularios, apaga as secoes, perguntas e destinos atuais antes de recriar.</p>';
    echo '  <button type="submit" class="cdspac-btn cdspac-btn-salvar"><i class="ti ti-player-play"></i> Executar a importacao</button>';
    echo '</div>';

    echo '</form>';

    echo '<script>window.cdspacAlvos = ' . json_encode($alvos, JSON_UNESCAPED_UNICODE) . ';</script>';
}

// =======================================================================
// ETAPA 3 - resultado
// =======================================================================
if ($etapa === 'fim') {
    $rel = (array) ($_SESSION['plugin_catalogoeformularios_relatorio'] ?? []);
    unset($_SESSION['plugin_catalogoeformularios_relatorio']);

    if (empty($rel)) {
        echo '<div class="cdspac-alerta cdspac-alerta-info"><i class="ti ti-info-circle"></i> Nenhum resultado para mostrar. Comece uma nova importacao.</div>';
    } else {
        echo '<div class="cdspac-resumo">';
        $cartoes = [
            ['Formularios criados',      (int) ($rel['form_criados'] ?? 0),      'ti ti-file-text'],
            ['Formularios substituidos', (int) ($rel['form_substituidos'] ?? 0), 'ti ti-refresh'],
            ['Categorias criadas',       (int) ($rel['cat_criadas'] ?? 0),       'ti ti-folder'],
            ['Dependencias criadas',     (int) ($rel['deps_criadas'] ?? 0),      'ti ti-puzzle'],
            ['Itens substituidos',       (int) ($rel['deps_substituidas'] ?? 0) + (int) ($rel['cat_substituidas'] ?? 0), 'ti ti-exchange'],
            ['Itens reaproveitados',     (int) ($rel['deps_reusadas'] ?? 0) + (int) ($rel['cat_reusadas'] ?? 0) + (int) ($rel['deps_apontadas'] ?? 0), 'ti ti-circle-check'],
            ['Ignorados',                (int) ($rel['form_ignorados'] ?? 0) + (int) ($rel['deps_ignoradas'] ?? 0) + (int) ($rel['cat_ignoradas'] ?? 0), 'ti ti-eye-off'],
            ['Falhas',                   (int) ($rel['form_falhas'] ?? 0) + (int) ($rel['deps_falhas'] ?? 0), 'ti ti-alert-triangle'],
        ];
        foreach ($cartoes as $c) {
            echo '<div class="cdspac-cartao"><i class="' . $c[2] . '"></i><span class="cdspac-cartao-num">' . $c[1] . '</span><span class="cdspac-cartao-txt">' . $c[0] . '</span></div>';
        }
        echo '</div>';

        if (!empty($rel['pendencias'])) {
            echo '<div class="card cdspac-card">';
            echo '  <div class="card-header cdspac-card-header"><h5><i class="ti ti-alert-triangle"></i> Pendencias a revisar</h5></div>';
            echo '  <div class="cdspac-card-body">';
            foreach ((array) $rel['pendencias'] as $p) {
                echo '<div class="cdspac-linha cdspac-linha-alerta">' . htmlspecialchars((string) $p) . '</div>';
            }
            echo '  </div>';
            echo '</div>';
        }

        if (!empty($rel['detalhes'])) {
            echo '<div class="card cdspac-card">';
            echo '  <div class="card-header cdspac-card-header"><h5><i class="ti ti-list-check"></i> O que foi feito</h5></div>';
            echo '  <div class="cdspac-card-body cdspac-log">';
            foreach ((array) $rel['detalhes'] as $d) {
                echo '<div class="cdspac-linha">' . htmlspecialchars((string) $d) . '</div>';
            }
            echo '  </div>';
            echo '</div>';
        }
    }

    echo '<div class="cdspac-rodape">';
    echo '  <a href="' . $base . '/front/catalogo.php" class="cdspac-btn cdspac-btn-salvar"><i class="ti ti-clipboard-list"></i> Abrir o gerenciador do catalogo</a>';
    echo '  <a href="' . $base . '/front/importar.php" class="cdspac-btn cdspac-btn-neutro"><i class="ti ti-upload"></i> Nova importacao</a>';
    echo '</div>';
}

echo '</div>';

// Filtro de entidades: mostra apenas entidades pai e entidades sem filho.
// $DB precisa ser global aqui porque no GLPI 11 os arquivos de front sao
// carregados dentro de uma funcao (LegacyFileLoadController).
global $DB;

$idsEsconder = [];
foreach ($DB->request([
    'SELECT' => ['id'],
    'FROM'   => 'glpi_entities',
    'WHERE'  => ['entities_id' => ['>', 0]],
]) as $e) {
    $idsEsconder[] = (int) $e['id'];
}

echo '<script>window.cdspacEntidadesEsconder = ' . json_encode(array_values($idsEsconder)) . ';</script>';
echo '<script src="' . $base . '/public/js/pacote.js?v=' . $v . '"></script>';

Html::footer();
