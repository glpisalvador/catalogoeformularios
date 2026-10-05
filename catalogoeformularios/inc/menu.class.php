<?php

/**
 * Item de menu do plugin na secao Ferramentas.
 * Aponta para a pagina do gerenciador de catalogo (front/catalogo.php).
 */
class PluginCatalogoeformulariosMenu extends CommonGLPI
{
    // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no GLPI 11,
    // e o PHP exige o mesmo tipo da classe pai. As permissões são sobrescritas abaixo.
    private const RIGHTNAME = 'config';

    public static function canCreate(): bool
    {
        return Session::haveRight(self::RIGHTNAME, CREATE);
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

    public static function getTypeName($nb = 0): string
    {
        return 'Catálogo e Formulários';
    }

    /**
     * Acesso a pagina: direito nativo config OU perfil/usuario liberado na config do plugin.
     */
    public static function canView(): bool
    {
        return PluginCatalogoeformulariosConfig::podeVisualizar();
    }

    public static function getMenuName(): string
    {
        return self::getTypeName();
    }

    /**
     * Conteudo do menu: titulo, icone e pagina principal.
     *
     * @return array<string, mixed>
     */
    public static function getMenuContent(): array
    {
        global $CFG_GLPI;

        $menu = [];

        $menu['title'] = self::getTypeName();
        $menu['page']  = '/plugins/catalogoeformularios/front/catalogo.php';
        $menu['icon']  = 'ti ti-clipboard-list';

        // Link de acesso direto que aparece no menu lateral de Ferramentas.
        $menu['links']['search'] = '/plugins/catalogoeformularios/front/catalogo.php';

        // Subpaginas: alimentam o breadcrumb nativo (5o parametro do Html::header)
        // e aparecem como atalhos no menu lateral de Ferramentas.
        $menu['options']['exportar'] = [
            'title' => 'Exportar catalogo',
            'icon'  => 'ti ti-download',
            'page'  => '/plugins/catalogoeformularios/front/exportar.php',
            'links' => [
                'search' => '/plugins/catalogoeformularios/front/exportar.php',
            ],
        ];

        $menu['options']['importar'] = [
            'title' => 'Importar catalogo',
            'icon'  => 'ti ti-upload',
            'page'  => '/plugins/catalogoeformularios/front/importar.php',
            'links' => [
                'search' => '/plugins/catalogoeformularios/front/importar.php',
            ],
        ];

        return $menu;
    }

    /**
     * Icone do item de menu.
     */
    public static function getIcon(): string
    {
        return 'ti ti-clipboard-list';
    }
}