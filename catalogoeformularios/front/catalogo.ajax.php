<?php

// Limpa qualquer buffer antes do include para garantir resposta JSON limpa.
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();


// Descarta qualquer output gerado pelo include (warnings/notices de outros plugins).
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(function () {
    $erro = error_get_last();
    if ($erro && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        echo json_encode([
            'success' => false,
            'message' => 'Erro fatal: ' . $erro['message'],
        ]);
    }
});

Session::checkLoginUser();

/** Resposta JSON padronizada (sempre com new_token para renovar o CSRF). */
function catalogoeformularios_responder(array $extra): void
{
    echo json_encode(array_merge(['new_token' => plugin_catalogoeformularios_token_csrf()], $extra));
    exit;
}

// Acesso de leitura: direito nativo OU perfil/usuario liberado na config do plugin.
if (!PluginCatalogoeformulariosConfig::podeVisualizar()) {
    catalogoeformularios_responder(['success' => false, 'message' => 'Sem permissao.']);
}

$action     = $_POST['action'] ?? ($_REQUEST['action'] ?? '');
$podeEditar = PluginCatalogoeformulariosConfig::podeEditar();

// Acoes que alteram dados (exigem permissao de edicao).
$acoesEscrita = [
    'criar_categoria', 'editar_categoria', 'excluir_categoria', 'mover_categoria', 'transformar_em_categorias',
    'duplicar_categoria', 'duplicar_formulario',
    'transformar_itil_em_formularios',
    'criar_formulario', 'editar_formulario', 'alternar_ativo_formulario', 'excluir_formulario', 'vincular_categoria',
    'criar_secao', 'editar_secao', 'excluir_secao', 'reordenar_secoes',
    'criar_pergunta', 'editar_pergunta', 'excluir_pergunta', 'reordenar_perguntas', 'mover_pergunta',
    'destino_criar', 'destino_renomear', 'destino_excluir', 'destino_definir_campo', 'destino_observador_grupos',
    'acesso_salvar_lista', 'acesso_salvar_direto',
    'condicao_definir', 'condicao_limpar',
    'salvar_condicoes_opcao',
    'painel_campo', 'painel_atores', 'painel_acesso', 'painel_campo_lote',
    'geral_salvar', 'pergunta_salvar', 'pergunta_duplicar', 'comentario_salvar', 'comentario_excluir',
    'blocos_ordenar', 'regra_salvar', 'validacao_salvar',
];

if (in_array($action, $acoesEscrita, true) && !$podeEditar) {
    catalogoeformularios_responder(['success' => false, 'message' => 'Voce nao tem permissao para editar.']);
}

// Helpers de leitura de parametros.
$pInt  = fn(string $k, int $def = 0): int => (int) ($_POST[$k] ?? $def);
$pStr  = fn(string $k, string $def = ''): string => (string) ($_POST[$k] ?? $def);
$pArr  = fn(string $k): array => (isset($_POST[$k]) && is_array($_POST[$k])) ? $_POST[$k] : [];
$pJson = function (string $k) {
    if (!isset($_POST[$k]) || $_POST[$k] === '') {
        return null;
    }
    $d = json_decode((string) $_POST[$k], true);
    return is_array($d) ? $d : null;
};

try {
    switch ($action) {

        // =================== LEITURA ===================
        case 'arvore':
            catalogoeformularios_responder(['success' => true, 'dados' => PluginCatalogoeformulariosCatalogo::getArvoreCategorias()]);
            break;

        case 'conteudo':
            catalogoeformularios_responder(['success' => true, 'dados' => PluginCatalogoeformulariosCatalogo::getConteudo($pInt('categoria'))]);
            break;

        case 'formulario':
            $det = PluginCatalogoeformulariosCatalogo::getDetalheFormulario($pInt('form'));
            if ($det === null) {
                catalogoeformularios_responder(['success' => false, 'message' => 'Formulario nao encontrado.']);
            }
            catalogoeformularios_responder(['success' => true, 'dados' => $det]);
            break;

        case 'perfis_formulario':
            catalogoeformularios_responder(['success' => true, 'dados' => PluginCatalogoeformulariosCatalogo::getPerfisDoFormulario($pInt('form'))]);
            break;

        case 'perfis_categoria':
            catalogoeformularios_responder(['success' => true, 'dados' => PluginCatalogoeformulariosCatalogo::getPerfisDaCategoria($pInt('categoria'))]);
            break;

        case 'destinos_listar':
            catalogoeformularios_responder(['success' => true, 'dados' => PluginCatalogoeformulariosDestino::listar($pInt('form'))]);
            break;

        case 'destino_config':
            $cfg = PluginCatalogoeformulariosDestino::getConfigDestino($pInt('id'));
            catalogoeformularios_responder(['success' => $cfg !== null, 'dados' => $cfg]);
            break;

        case 'acesso_estado':
            catalogoeformularios_responder(['success' => true, 'dados' => PluginCatalogoeformulariosAcesso::getEstado($pInt('form'))]);
            break;

        case 'condicao_get':
            catalogoeformularios_responder(['success' => true, 'dados' => PluginCatalogoeformulariosCondicao::getRegra($pInt('pergunta'))]);
            break;

        case 'fonte':
            catalogoeformularios_responder(['success' => true, 'dados' => PluginCatalogoeformulariosFonte::getFonte($pStr('tipo'))]);
            break;

        case 'opcoes_transformar_categorias':
            catalogoeformularios_responder(['success' => true, 'dados' => PluginCatalogoeformulariosCatalogo::getEntidadesEGruposParaCategorias()]);
            break;

        case 'categorias_catalogo':
            catalogoeformularios_responder(['success' => true, 'dados' => PluginCatalogoeformulariosCatalogo::getTodasCategorias()]);
            break;

        // Dados de UMA categoria (nome, icone, pai) para o modal de edicao.
        // Busca por ID, entao funciona a partir da arvore lateral, do acordeon de
        // primeiro nivel e das subcategorias aninhadas.
        case 'categoria_detalhe':
            $det = PluginCatalogoeformulariosCatalogo::getCategoriaDetalhe($pInt('id'));
            if ($det === null) {
                catalogoeformularios_responder(['success' => false, 'message' => 'Categoria nao encontrada.']);
            }
            catalogoeformularios_responder(['success' => true, 'dados' => $det]);
            break;

        // Biblioteca de ilustracoes nativas do GLPI 11 (icones de categoria).
        case 'ilustracoes':
            catalogoeformularios_responder([
                'success' => true,
                'dados'   => PluginCatalogoeformulariosCatalogo::getIlustracoes(),
                'sprite'  => PluginCatalogoeformulariosCatalogo::getSpriteIlustracoes(),
            ]);
            break;

        // =================== EDITOR DO ACORDEAO ===================
        case 'estrutura':
            $est = PluginCatalogoeformulariosEstrutura::ler($pInt('form'));
            if ($est === null) {
                catalogoeformularios_responder(['success' => false, 'message' => 'Formulario nao encontrado.']);
            }
            catalogoeformularios_responder(['success' => true, 'dados' => $est]);
            break;

        case 'geral_salvar':
            $d = $pJson('dados_json') ?? [];
            $r = PluginCatalogoeformulariosEstrutura::salvarGeral($pInt('form'), $d);
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'pergunta_salvar':
            $d = $pJson('dados_json') ?? [];
            $r = PluginCatalogoeformulariosEstrutura::salvarPergunta($d);
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg'], 'id' => $r['id'] ?? 0, 'tipo_mudou' => !empty($r['tipo_mudou'])]);
            break;

        case 'pergunta_duplicar':
            $r = PluginCatalogoeformulariosEstrutura::duplicarPergunta($pInt('id'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg'], 'id' => $r['id'] ?? 0]);
            break;

        case 'comentario_salvar':
            $r = PluginCatalogoeformulariosEstrutura::salvarComentario([
                'id'        => $pInt('id'),
                'secao'     => $pInt('secao'),
                'nome'      => $pStr('nome'),
                'descricao' => $pStr('descricao'),
            ]);
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg'], 'id' => $r['id'] ?? 0]);
            break;

        case 'comentario_excluir':
            $r = PluginCatalogoeformulariosEstrutura::excluirComentario($pInt('id'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'blocos_ordenar':
            $r = PluginCatalogoeformulariosEstrutura::ordenarBlocos($pInt('secao'), $pArr('blocos'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'regra_salvar':
            $r = PluginCatalogoeformulariosEstrutura::salvarRegra($pStr('alvo'), $pInt('id'), $pStr('estrategia', 'sempre'), $pJson('condicoes_json') ?? []);
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'validacao_salvar':
            $r = PluginCatalogoeformulariosEstrutura::salvarValidacao($pInt('pergunta'), $pStr('estrategia', 'sem'), $pJson('condicoes_json') ?? []);
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        // =================== CATEGORIAS ===================
        case 'transformar_em_categorias':
            $r = PluginCatalogoeformulariosCatalogo::criarCategoriasEmLote($pArr('entidades'), $pArr('grupos'), $pInt('pai'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'criar_categoria':
            $r = PluginCatalogoeformulariosCatalogo::criarCategoria(
                $pStr('nome'),
                $pInt('pai'),
                $pStr('descricao'),
                $pInt('entidade'),
                $pStr('ilustracao')
            );
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg'], 'id' => $r['id'] ?? 0]);
            break;

        case 'editar_categoria':
            // descricao e ilustracao so sao gravadas quando vierem no POST. Assim
            // editar apenas o nome nao apaga o que o formulario nao enviou.
            $r = PluginCatalogoeformulariosCatalogo::editarCategoria(
                $pInt('id'),
                $pStr('nome'),
                isset($_POST['descricao']) ? $pStr('descricao') : null,
                null,
                isset($_POST['ilustracao']) ? $pStr('ilustracao') : null
            );
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'excluir_categoria':
            $r = PluginCatalogoeformulariosCatalogo::excluirCategoria($pInt('id'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'mover_categoria':
            $r = PluginCatalogoeformulariosCatalogo::moverCategoria($pInt('id'), $pInt('pai'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'duplicar_categoria':
            $r = PluginCatalogoeformulariosCatalogo::duplicarCategoria($pInt('id'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg'], 'id' => $r['id'] ?? 0]);
            break;

        // =================== FORMULARIOS ===================
        case 'transformar_itil_em_formularios':
            $r = PluginCatalogoeformulariosCatalogo::criarFormulariosDeCategoriasItil($pArr('itil'), $pInt('categoria'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'criar_formulario':
            $r = PluginCatalogoeformulariosCatalogo::criarFormulario([
                'nome'      => $pStr('nome'),
                'descricao' => $pStr('descricao'),
                'header'    => $pStr('header'),
                'categoria' => $pInt('categoria'),
                'recursivo' => $pInt('recursivo', 1),
                'ativo'     => $pInt('ativo'),
                'ilustracao'        => $pStr('ilustracao'),
                'categoria_itil'    => $pInt('categoria_itil'),
                'observador_grupos' => $pArr('observador_grupos'),
            ]);
            // Controle de acesso so e aplicado se o bloco foi aberto no front (campos enviados).
            if ($r['ok'] && (isset($_POST['ac_todos']) || isset($_POST['ac_perfis']) || isset($_POST['ac_usuarios']) || isset($_POST['ac_grupos']))) {
                $todos = !empty($_POST['ac_todos']);
                $perfis = $pArr('ac_perfis'); $usuarios = $pArr('ac_usuarios'); $grupos = $pArr('ac_grupos');
                if ($todos || !empty($perfis) || !empty($usuarios) || !empty($grupos)) {
                    PluginCatalogoeformulariosAcesso::salvarAllowList((int) ($r['id'] ?? 0), [
                        'todos'    => $todos,
                        'perfis'   => $perfis,
                        'usuarios' => $usuarios,
                        'grupos'   => $grupos,
                        'ativo'    => true,
                    ]);
                }
            }
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg'], 'id' => $r['id'] ?? 0]);
            break;

        case 'editar_formulario':
            $dadosEditar = [
                'nome'      => $pStr('nome'),
                'descricao' => $pStr('descricao'),
                'header'    => $pStr('header'),
                'categoria' => $pInt('categoria'),
                'recursivo' => $pInt('recursivo', 1),
            ];
            if (isset($_POST['ilustracao'])) {
                $dadosEditar['ilustracao'] = $pStr('ilustracao');
            }
            if (isset($_POST['categoria_itil'])) {
                $dadosEditar['categoria_itil'] = $pInt('categoria_itil');
            }
            if (isset($_POST['observador_grupos_set'])) {
                $dadosEditar['observador_grupos'] = $pArr('observador_grupos');
            }
            $r = PluginCatalogoeformulariosCatalogo::editarFormulario($pInt('id'), $dadosEditar);
            // Controle de acesso so e salvo se o bloco foi aberto no front (campos enviados).
            if ($r['ok'] && (isset($_POST['ac_todos']) || isset($_POST['ac_perfis']) || isset($_POST['ac_usuarios']) || isset($_POST['ac_grupos']))) {
                PluginCatalogoeformulariosAcesso::salvarAllowList($pInt('id'), [
                    'todos'    => !empty($_POST['ac_todos']),
                    'perfis'   => $pArr('ac_perfis'),
                    'usuarios' => $pArr('ac_usuarios'),
                    'grupos'   => $pArr('ac_grupos'),
                    'ativo'    => true,
                ]);
            }
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'alternar_ativo_formulario':
            $r = PluginCatalogoeformulariosCatalogo::alternarAtivoFormulario($pInt('id'), $pInt('ativo') === 1);
            catalogoeformularios_responder([
                'success'  => $r['ok'],
                'message'  => $r['msg'],
                'ativo'    => !empty($r['ativo']),
                'rascunho' => !empty($r['rascunho']),
            ]);
            break;

        case 'excluir_formulario':
            $r = PluginCatalogoeformulariosCatalogo::excluirFormulario($pInt('id'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'duplicar_formulario':
            $r = PluginCatalogoeformulariosCatalogo::duplicarFormulario($pInt('id'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg'], 'id' => $r['id'] ?? 0]);
            break;

        case 'vincular_categoria':
            $r = PluginCatalogoeformulariosCatalogo::vincularCategoria($pInt('form'), $pInt('categoria'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        // =================== SECOES ===================
        case 'criar_secao':
            $r = PluginCatalogoeformulariosCatalogo::criarSecao($pInt('form'), $pStr('nome'), $pStr('descricao'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg'], 'id' => $r['id'] ?? 0]);
            break;

        case 'editar_secao':
            $r = PluginCatalogoeformulariosCatalogo::editarSecao($pInt('id'), $pStr('nome'), $pStr('descricao'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'excluir_secao':
            $r = PluginCatalogoeformulariosCatalogo::excluirSecao($pInt('id'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'reordenar_secoes':
            $r = PluginCatalogoeformulariosCatalogo::reordenarSecoes($pInt('form'), $pArr('ids'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        // =================== PERGUNTAS ===================
        case 'criar_pergunta':
            $r = PluginCatalogoeformulariosCatalogo::criarPergunta($pInt('secao'), [
                'nome'        => $pStr('nome'),
                'tipo'        => $pStr('tipo'),
                'obrigatoria' => $pInt('obrigatoria'),
                'descricao'   => $pStr('descricao'),
                'opcoes'      => $pArr('opcoes'),
            ]);
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg'], 'id' => $r['id'] ?? 0]);
            break;

        case 'editar_pergunta':
            $r = PluginCatalogoeformulariosCatalogo::editarPergunta($pInt('id'), [
                'nome'        => $pStr('nome'),
                'tipo'        => $pStr('tipo'),
                'obrigatoria' => $pInt('obrigatoria'),
                'descricao'   => $pStr('descricao'),
                'opcoes'      => $pArr('opcoes'),
            ]);
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'excluir_pergunta':
            $r = PluginCatalogoeformulariosCatalogo::excluirPergunta($pInt('id'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'reordenar_perguntas':
            $r = PluginCatalogoeformulariosCatalogo::reordenarPerguntas($pInt('secao'), $pArr('ids'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'mover_pergunta':
            $r = PluginCatalogoeformulariosCatalogo::moverPergunta($pInt('id'), $pInt('secao'), $pArr('ids'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        // =================== DESTINOS ===================
        case 'destino_criar':
            $r = PluginCatalogoeformulariosDestino::criar($pInt('form'), $pStr('classe'), $pStr('nome'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg'], 'id' => $r['id'] ?? 0]);
            break;

        case 'destino_renomear':
            $r = PluginCatalogoeformulariosDestino::renomear($pInt('id'), $pStr('nome'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'destino_excluir':
            $r = PluginCatalogoeformulariosDestino::excluir($pInt('id'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'destino_definir_campo':
            $valorJson = $pJson('valor_json');
            $valor     = $valorJson !== null
                ? $valorJson
                : (($_POST['valor'] ?? '') !== '' ? (int) $_POST['valor'] : null);
            $r = PluginCatalogoeformulariosDestino::definirCampo(
                $pInt('id'),
                $pStr('campo'),
                $pStr('estrategia', 'especifico'),
                $valor,
                $pInt('question')
            );
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'destino_observador_estado':
            catalogoeformularios_responder(['success' => true, 'dados' => [
                'grupos' => PluginCatalogoeformulariosDestino::lerObservadorGruposDestino($pInt('id')),
            ]]);
            break;

        case 'destino_observador_grupos':
            $r = PluginCatalogoeformulariosDestino::salvarObservadorGrupos($pInt('id'), $pArr('grupos'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        // =================== CONTROLE DE ACESSO ===================
        case 'acesso_salvar_lista':
            $r = PluginCatalogoeformulariosAcesso::salvarAllowList($pInt('form'), [
                'todos'    => !empty($_POST['todos']),
                'perfis'   => $pArr('perfis'),
                'usuarios' => $pArr('usuarios'),
                'grupos'   => $pArr('grupos'),
                'ativo'    => !empty($_POST['ativo']),
            ]);
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'acesso_salvar_direto':
            $r = PluginCatalogoeformulariosAcesso::salvarDirectAccess($pInt('form'), [
                'ativo'                 => !empty($_POST['ativo']),
                'allow_unauthenticated' => !empty($_POST['allow_unauthenticated']),
            ]);
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        // =================== CONDICOES ===================
        case 'condicao_definir':
            $cond = $pJson('condicoes_json') ?? [];
            $r = PluginCatalogoeformulariosCondicao::definir($pInt('pergunta'), $pStr('estrategia', 'sempre'), $cond);
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'condicao_limpar':
            $r = PluginCatalogoeformulariosCondicao::definirSempreVisivel($pInt('pergunta'));
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        // =================== CONDICIONAIS POR OPCAO ===================
        case 'alvos_condicionais':
            catalogoeformularios_responder([
                'success' => true,
                'dados'   => PluginCatalogoeformulariosCatalogo::getAlvosCondicionais($pInt('form')),
            ]);
            break;

        case 'ler_condicoes_opcao':
            catalogoeformularios_responder([
                'success' => true,
                'dados'   => PluginCatalogoeformulariosCondicao::lerCondicionaisPorOpcao($pInt('form'), $pStr('origem_uuid')),
            ]);
            break;

        case 'salvar_condicoes_opcao':
            $r = PluginCatalogoeformulariosCondicao::aplicarCondicionaisPorOpcao(
                $pInt('form'),
                $pStr('origem_uuid'),
                $pJson('regras_json') ?? []
            );
            catalogoeformularios_responder([
                'success'   => $r['ok'],
                'message'   => $r['msg'],
                'aplicados' => $r['aplicados'] ?? 0,
            ]);
            break;

        // =================== PAINEL RAPIDO DO FORMULARIO ===================
        case 'painel_formulario':
            $res = PluginCatalogoeformulariosPainel::getResumo($pInt('form'));
            if ($res === null) {
                catalogoeformularios_responder(['success' => false, 'message' => 'Formulario nao encontrado.']);
            }
            catalogoeformularios_responder(['success' => true, 'dados' => $res]);
            break;

        case 'painel_matriz':
            catalogoeformularios_responder([
                'success' => true,
                'dados'   => PluginCatalogoeformulariosPainel::getMatriz($pInt('categoria')),
            ]);
            break;

        case 'painel_campo':
            $r = PluginCatalogoeformulariosPainel::salvarCampo(
                $pInt('form'),
                $pStr('campo'),
                $pStr('estrategia', 'especifico'),
                $pInt('valor'),
                $pInt('question'),
                $pInt('destino')
            );
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'painel_campo_lote':
            $r = PluginCatalogoeformulariosPainel::salvarCampoEmLote(
                $pArr('forms'),
                $pStr('campo'),
                $pStr('estrategia', 'especifico'),
                $pInt('valor')
            );
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'painel_atores':
            $r = PluginCatalogoeformulariosPainel::salvarAtores(
                $pInt('form'),
                $pStr('ator'),
                $pArr('usuarios'),
                $pArr('grupos'),
                $pInt('destino')
            );
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        case 'painel_acesso':
            $r = PluginCatalogoeformulariosPainel::salvarAcesso($pInt('form'), [
                'ativo'    => !empty($_POST['ativo']),
                'todos'    => !empty($_POST['todos']),
                'perfis'   => $pArr('perfis'),
                'grupos'   => $pArr('grupos'),
                'usuarios' => $pArr('usuarios'),
            ]);
            catalogoeformularios_responder(['success' => $r['ok'], 'message' => $r['msg']]);
            break;

        default:
            catalogoeformularios_responder(['success' => false, 'message' => 'Acao invalida.']);
    }
} catch (\Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    catalogoeformularios_responder(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
}