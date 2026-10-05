<?php

define('PLUGIN_CATALOGOEFORMULARIOS_VERSION', '3.0.5');
define('PLUGIN_CATALOGOEFORMULARIOS_MIN_GLPI', '11.0.0');
define('PLUGIN_CATALOGOEFORMULARIOS_MAX_GLPI', '12.99.99');

/**
 * Inicializacao do plugin: registra hooks e classes.
 */
function plugin_init_catalogoeformularios(): void
{
    global $PLUGIN_HOOKS, $CFG_GLPI;

    // Plugin compativel com CSRF do GLPI 11.
    $PLUGIN_HOOKS['csrf_compliant']['catalogoeformularios'] = true;

    // Pagina de configuracao acessivel pelo icone de engrenagem no marketplace.
    $PLUGIN_HOOKS['config_page']['catalogoeformularios'] = 'front/config.form.php';

    // So continua se o plugin estiver realmente ativo.
    $plugin = new Plugin();
    if (!$plugin->isActivated('catalogoeformularios')) {
        return;
    }

    // Registro das classes com tabela / menu.
    // As classes facilitadoras (Catalogo, Destino, Acesso, Condicao, Fonte, Painel) sao
    // estaticas e carregam pelo autoloader nativo (PluginCatalogoeformulariosX -> inc/x.class.php),
    // por isso nao precisam de registerClass.
    Plugin::registerClass('PluginCatalogoeformulariosConfig');
    Plugin::registerClass('PluginCatalogoeformulariosMenu');

    // Facilitadores de exportacao/importacao (Pacote, Exportacao, Importacao) sao
    // estaticos e carregam pelo autoloader nativo, como os demais.

    // Menu na secao Ferramentas (entra automaticamente no breadcrumb nativo).
    $PLUGIN_HOOKS['menu_toadd']['catalogoeformularios'] = [
        'tools' => 'PluginCatalogoeformulariosMenu',
    ];

    // Sem usuario logado nao ha o que carregar.
    if (!isset($_SESSION['glpiID'])) {
        return;
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '';

    // Assets exclusivos da pagina de configuracao do plugin (config.php / config.form.php).
    if (strpos($uri, 'catalogoeformularios/front/config') !== false) {
        $PLUGIN_HOOKS['add_css']['catalogoeformularios'][]        = 'public/css/estilo.css';
        $PLUGIN_HOOKS['add_javascript']['catalogoeformularios'][] = 'public/js/script.js';
        return;
    }

    // Assets exclusivos das paginas de exportacao/importacao do pacote.
    if (strpos($uri, 'catalogoeformularios/front/exportar') !== false
        || strpos($uri, 'catalogoeformularios/front/importar') !== false) {
        // O CSS e o JS dessas paginas sao carregados dentro delas mesmas (com
        // cache-buster proprio), entao aqui nao ha nada a acrescentar.
        return;
    }

    // Assets exclusivos da pagina do gerenciador (catalogo.php).
    // A lib de drag-and-drop (SortableJS) e carregada via tag <script> dentro da
    // propria pagina (front/catalogo.php) para evitar problemas de path com CDN.
}

/**
 * Metadados do plugin.
 *
 * @return array<string, mixed>
 */
function plugin_version_catalogoeformularios(): array
{
    return [
        'name'         => 'Catálogo e Formulários',
        'version'      => PLUGIN_CATALOGOEFORMULARIOS_VERSION,
        'author'       => 'GLPI Salvador',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_CATALOGOEFORMULARIOS_MIN_GLPI,
                'max' => PLUGIN_CATALOGOEFORMULARIOS_MAX_GLPI,
            ],
        ],
    ];
}

/**
 * Verifica pre-requisitos antes de permitir a instalacao/ativacao.
 */
function plugin_catalogoeformularios_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_CATALOGOEFORMULARIOS_MIN_GLPI, 'lt')) {
        echo sprintf(
            'Este plugin requer o GLPI %s ou superior.',
            PLUGIN_CATALOGOEFORMULARIOS_MIN_GLPI
        );
        return false;
    }

    return true;
}

/**
 * Verifica se a configuracao do plugin esta pronta.
 */
function plugin_catalogoeformularios_check_config($verbose = false): bool
{
    return true;
}

// ------------------------------------------------------------
// CSRF: o GLPI 11 exige token; no GLPI 12 a protecao e feita pelos
// cabecalhos Sec-Fetch-Site/Origin e o token foi descontinuado
// ------------------------------------------------------------

function plugin_catalogoeformularios_usa_token_csrf(): bool
{
    return version_compare(GLPI_VERSION, '12.0.0-dev', '<');
}

function plugin_catalogoeformularios_token_csrf(): string
{
    return plugin_catalogoeformularios_usa_token_csrf() ? Session::getNewCSRFToken() : '';
}

function plugin_catalogoeformularios_campo_csrf(): string
{
    return plugin_catalogoeformularios_usa_token_csrf()
        ? Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()])
        : '';
}