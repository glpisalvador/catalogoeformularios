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

// Captura erros fatais e devolve JSON valido em vez de quebrar o parse no frontend.
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

if (!Session::haveRight('config', UPDATE)) {
    echo json_encode([
        'success'   => false,
        'message'   => 'Voce nao tem permissao para executar esta acao.',
        'new_token' => plugin_catalogoeformularios_token_csrf(),
    ]);
    exit;
}

$action = $_POST['action'] ?? ($_REQUEST['action'] ?? '');

try {
    switch ($action) {

        case 'contar_itil':
            $total = PluginCatalogoeformulariosConfig::contarCategoriasItil();
            echo json_encode([
                'success'   => true,
                'total'     => $total,
                'message'   => $total . ' categoria(s) ITIL encontrada(s).',
                'new_token' => plugin_catalogoeformularios_token_csrf(),
            ]);
            exit;

        case 'copiar_categorias':
            $r = PluginCatalogoeformulariosConfig::copiarCategoriasItilParaFormularios();

            if (!empty($r['erro_classe'])) {
                echo json_encode([
                    'success'   => false,
                    'message'   => 'A classe de categorias de formulario do GLPI 11 nao foi encontrada nesta instalacao.',
                    'new_token' => plugin_catalogoeformularios_token_csrf(),
                ]);
                exit;
            }

            $msg = 'Processo concluido. '
                 . $r['copiadas'] . ' categoria(s) copiada(s), '
                 . $r['existentes'] . ' ja existia(m)';
            if ($r['erros'] > 0) {
                $msg .= ', ' . $r['erros'] . ' com erro';
            }
            $msg .= ' (total processado: ' . $r['total'] . ').';

            if (!empty($r['msg_erro'])) {
                $msg .= ' Detalhe do erro: ' . $r['msg_erro'];
            }

            echo json_encode([
                'success'   => ($r['erros'] === 0),
                'dados'     => $r,
                'message'   => $msg,
                'new_token' => plugin_catalogoeformularios_token_csrf(),
            ]);
            exit;

        default:
            echo json_encode([
                'success'   => false,
                'message'   => 'Acao invalida.',
                'new_token' => plugin_catalogoeformularios_token_csrf(),
            ]);
            exit;
    }
} catch (\Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode([
        'success'   => false,
        'message'   => 'Erro: ' . $e->getMessage(),
        'new_token' => plugin_catalogoeformularios_token_csrf(),
    ]);
    exit;
}