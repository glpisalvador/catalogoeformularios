<?php


Session::checkLoginUser();

// Acesso: direito nativo config OU perfil/usuario liberado na config do plugin.
if (!PluginCatalogoeformulariosConfig::podeVisualizar()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

global $CFG_GLPI, $DB;

$podeEditar = PluginCatalogoeformulariosConfig::podeEditar();
$root       = $CFG_GLPI['root_doc'];
$base       = $root . '/plugins/catalogoeformularios';
$v          = PLUGIN_CATALOGOEFORMULARIOS_VERSION . '-' . max(array_map('filemtime', glob(__DIR__ . '/../public/{js,css}/*', GLOB_BRACE) ?: [__FILE__])); // cache-buster

$bootstrap = [
    'ajaxUrl'          => $base . '/front/catalogo.ajax.php',
    'csrf'             => plugin_catalogoeformularios_token_csrf(),
    'podeEditar'       => $podeEditar,
    'tipos'            => PluginCatalogoeformulariosCatalogo::getTiposPergunta(),
    'entidades'        => PluginCatalogoeformulariosCatalogo::getEntidades(),
    'categorias'       => PluginCatalogoeformulariosCatalogo::getTodasCategorias(),
    'tiposDestino'     => PluginCatalogoeformulariosDestino::tiposDisponiveis(),
    'camposDestino'    => PluginCatalogoeformulariosDestino::camposSuportados(),
    'condSuportado'    => PluginCatalogoeformulariosCondicao::suportado(),
    'condEstrategias'  => PluginCatalogoeformulariosCondicao::estrategiasVisibilidade(),
    'condOperadores'   => PluginCatalogoeformulariosCondicao::operadores(),
    'editorNativoBase' => $root . '/front/form/form.php?id=',
    'rootDoc'          => $root,
    // Sprite SVG das ilustracoes nativas do GLPI 11 (icones das categorias).
    'spriteIlustracoes' => PluginCatalogoeformulariosCatalogo::getSpriteIlustracoes(),
    // Editor completo do acordeao (tipos nativos de pergunta, tipos de item, validacao).
    'estruturaTipos'    => PluginCatalogoeformulariosEstrutura::tipos(),
    'estruturaItens'    => PluginCatalogoeformulariosEstrutura::tiposDeItem(),
    'temValidacao'      => $DB->fieldExists('glpi_forms_questions', 'validation_strategy'),
];

// A biblioteca do editor de texto rico precisa estar na pagina antes do cabecalho
Html::requireJs('tinymce');

Html::header(
    'Catálogo e Formulários',
    $_SERVER['PHP_SELF'],
    'tools',
    'PluginCatalogoeformulariosMenu'
);

// CSS do plugin carregado direto na pagina (nao depende do hook).
echo '<link rel="stylesheet" type="text/css" href="' . $base . '/public/css/catalogo.css?v=' . $v . '">';

// Lib de drag-and-drop.
echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.3/Sortable.min.js"></script>';

// Editor-base escondido: a configuracao nativa do GLPI (skin, colar imagem...) e copiada
// para os editores de texto rico do acordeao e dos modais.
echo '<div class="catalogoeformularios-editor-base" hidden>';
Html::textarea([
    'name'            => 'catalogoeformularios_editor_base',
    'editor_id'       => 'catalogoeformularios-editor-base',
    'enable_richtext' => true,
    'enable_images'   => true,
    'rows'            => 2,
]);
echo '</div>';

// Dados iniciais para o front.
echo '<script>window.catalogoeformulariosCat = '
    . json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
    . ';</script>';

// Estrutura principal: gerenciador (em cima) + previa do catalogo nativo (embaixo).
echo '<div class="catalogoeformularios-split">';
echo '<div class="catalogoeformularios-app">';

echo '  <aside class="catalogoeformularios-arvore">';
echo '    <div class="catalogoeformularios-arvore-topo">';
echo '      <span class="catalogoeformularios-arvore-titulo"><i class="ti ti-sitemap"></i> Hierarquia do Catalogo</span>';
echo '      <div class="catalogoeformularios-arvore-topo-acoes">';
echo '        <a href="' . $root . '/ServiceCatalog" target="_blank" rel="noopener" class="catalogoeformularios-btn-icone" title="Abrir catalogo nativo do GLPI"><i class="ti ti-external-link"></i></a>';
echo '        <a href="' . $root . '/front/form/category.php" target="_blank" rel="noopener" class="catalogoeformularios-btn-icone" title="Categorias nativas do GLPI"><i class="ti ti-folders"></i></a>';
echo '        <a href="' . $root . '/front/form/form.php" target="_blank" rel="noopener" class="catalogoeformularios-btn-icone" title="Formularios nativos do GLPI"><i class="ti ti-forms"></i></a>';
echo '        <a href="' . $base . '/front/exportar.php" class="catalogoeformularios-btn-icone" title="Exportar o catalogo para um arquivo"><i class="ti ti-download"></i></a>';
echo '        <a href="' . $base . '/front/importar.php" class="catalogoeformularios-btn-icone" title="Importar um catalogo de um arquivo"><i class="ti ti-upload"></i></a>';
echo '        <button type="button" class="catalogoeformularios-btn-icone" id="cat-arvore-expandir" title="Expandir todos"><i class="ti ti-fold-down"></i></button>';
echo '      </div>';
echo '    </div>';
echo '    <div class="catalogoeformularios-arvore-busca">';
echo '      <input type="text" id="cat-arvore-busca" class="catalogoeformularios-input" placeholder="Buscar Categorias e Formularios">';
echo '    </div>';
echo '    <div class="catalogoeformularios-arvore-corpo" id="cat-arvore">';
echo '      <div class="catalogoeformularios-cat-carregando"><i class="ti ti-loader"></i> Carregando...</div>';
echo '    </div>';
echo '  </aside>';

echo '  <main class="catalogoeformularios-main">';
echo '    <div class="catalogoeformularios-cat-topo">';
// Navegador de itens: anda entre os formularios do catalogo atual (como na fila de tickets).
echo '      <div class="catalogoeformularios-nav" id="cat-navegador" style="display:none;">';
echo '        <button type="button" class="catalogoeformularios-nav-seta" data-nav-passo="-1" title="Formulario anterior"><i class="ti ti-chevron-left"></i></button>';
echo '        <span class="catalogoeformularios-nav-info" id="cat-nav-info">0 / 0</span>';
echo '        <button type="button" class="catalogoeformularios-nav-seta" data-nav-passo="1" title="Proximo formulario"><i class="ti ti-chevron-right"></i></button>';
echo '        <span class="catalogoeformularios-nav-nome" id="cat-nav-nome"></span>';
echo '      </div>';
echo '      <div class="catalogoeformularios-cat-trilha" id="cat-trilha"></div>';
echo '      <button type="button" class="catalogoeformularios-btn-icone catalogoeformularios-cat-expandir" id="cat-grid-expandir" title="Expandir todas" style="display:none;"><i class="ti ti-fold-down"></i></button>';
echo '      <div class="catalogoeformularios-cat-acoes" id="cat-acoes"></div>';
echo '    </div>';
echo '    <div class="catalogoeformularios-cat-conteudo" id="cat-conteudo">';
echo '      <div class="catalogoeformularios-cat-carregando"><i class="ti ti-loader"></i> Carregando...</div>';
echo '    </div>';
echo '  </main>';

echo '</div>'; // fim .catalogoeformularios-app

// Previa em tempo real do catalogo de servicos NATIVO do GLPI (somente essa area recarrega).
echo '  <section class="catalogoeformularios-preview">';
echo '    <div class="catalogoeformularios-preview-topo">';
echo '      <span class="catalogoeformularios-preview-titulo"><i class="ti ti-eye"></i> Previa do catalogo de servicos (nativo do GLPI)</span>';
echo '      <span class="catalogoeformularios-preview-status" id="cat-preview-status"></span>';
echo '      <button type="button" class="catalogoeformularios-btn-icone" id="cat-preview-recarregar" title="Recarregar previa"><i class="ti ti-refresh"></i></button>';
echo '      <a href="' . $root . '/ServiceCatalog" target="_blank" rel="noopener" class="catalogoeformularios-btn-icone" title="Abrir em nova aba"><i class="ti ti-external-link"></i></a>';
echo '      <button type="button" class="catalogoeformularios-btn-icone" id="cat-preview-minimizar" title="Minimizar previa"><i class="ti ti-arrows-minimize"></i></button>';
echo '    </div>';
echo '    <iframe id="cat-preview-frame" class="catalogoeformularios-preview-frame" src="' . $root . '/ServiceCatalog" title="Previa do catalogo nativo" style="opacity:0;transition:opacity .12s linear;"></iframe>';
echo '  </section>';

// Restaura o estado minimizado antes do primeiro paint (evita piscar a divisao de tela).
echo '<script>(function(){try{if(window.localStorage.getItem("catalogoeformularios_preview_minimizado")==="1"){'
    . 'var s=document.querySelector(".catalogoeformularios-split");'
    . 'if(s){s.classList.add("catalogoeformularios-preview-minimizado");}}}catch(e){}})();</script>';

echo '</div>'; // fim .catalogoeformularios-split

// JS do plugin carregado no FIM do body, com o DOM ja pronto (corrige o "Carregando" travado).
echo '<script src="' . $base . '/public/js/catalogo.js?v=' . $v . '"></script>';
echo '<script src="' . $base . '/public/js/estrutura.js?v=' . $v . '"></script>';

Html::footer();