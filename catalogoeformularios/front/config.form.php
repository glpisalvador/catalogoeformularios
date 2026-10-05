<?php


Session::checkLoginUser();

// Acesso a tela de configuracao exige direito de configuracao.
if (!Session::haveRight('config', UPDATE)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

// ---------------------------------------------------------------------
// Processamento do POST (sem Html::back / Html::redirect apos salvar).
// O formulario faz POST para si mesmo; a pagina recarrega naturalmente.
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_action'])) {

    switch ($_POST['save_action']) {

        case 'salvar_visualizar':
            $perfis   = array_map('intval', $_POST['visualizar_perfis'] ?? []);
            $usuarios = array_map('intval', $_POST['visualizar_usuarios'] ?? []);

            PluginCatalogoeformulariosConfig::setArrayConfig('visualizar_perfis', $perfis);
            PluginCatalogoeformulariosConfig::setArrayConfig('visualizar_usuarios', $usuarios);

            Session::addMessageAfterRedirect(
                'Permissoes de visualizacao salvas com sucesso.',
                true,
                INFO
            );
            break;

        case 'salvar_editar':
            $perfis   = array_map('intval', $_POST['editar_perfis'] ?? []);
            $usuarios = array_map('intval', $_POST['editar_usuarios'] ?? []);

            PluginCatalogoeformulariosConfig::setArrayConfig('editar_perfis', $perfis);
            PluginCatalogoeformulariosConfig::setArrayConfig('editar_usuarios', $usuarios);

            Session::addMessageAfterRedirect(
                'Permissoes de edicao salvas com sucesso.',
                true,
                INFO
            );
            break;

        case 'salvar_criacao':
            $entidade = max(0, (int) ($_POST['entidade_padrao'] ?? 0));
            if ($entidade > 0 && countElementsInTable('glpi_entities', ['id' => $entidade]) === 0) {
                $entidade = 0;
            }
            PluginCatalogoeformulariosConfig::setConfig('entidade_padrao', (string) $entidade);
            Session::addMessageAfterRedirect(
                'Entidade dos formularios novos salva: ' . Dropdown::getDropdownName('glpi_entities', $entidade) . '.',
                true,
                INFO
            );
            break;
    }
}

// ---------------------------------------------------------------------
// Renderizacao da pagina (breadcrumb nativo: Inicio / Configurar / ...).
// ---------------------------------------------------------------------
Html::header(
    'Catálogo e Formulários',
    $_SERVER['PHP_SELF'],
    'config',
    'PluginCatalogoeformulariosConfig'
);

PluginCatalogoeformulariosConfig::showConfigForm();

Html::footer();