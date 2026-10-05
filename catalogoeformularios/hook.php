<?php

/**
 * Instalacao do plugin: cria a tabela de configuracoes (chave-valor)
 * e insere as chaves padrao.
 *
 * Observacao: o plugin NAO cria tabelas de dados proprias. Formularios,
 * categorias, secoes, perguntas, blocos de texto, destinos, controles de acesso
 * e condicoes sao todos persistidos nas tabelas nativas do GLPI 11/12
 * (glpi_forms_*), atraves das classes nativas. A unica tabela do plugin guarda
 * as permissoes de quem pode visualizar/editar o catalogo e as configuracoes.
 */
function plugin_catalogoeformularios_install(): bool
{
    global $DB;

    $tabela = 'glpi_plugin_catalogoeformularios_configs';

    if (!$DB->tableExists($tabela)) {
        $sql = "CREATE TABLE `{$tabela}` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) NOT NULL,
            `value` TEXT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC";

        $DB->doQuery($sql);
    }

    // Upgrade idempotente: garante a coluna date_mod em instalacoes antigas.
    $colDateMod = $DB->request([
        'FROM'  => 'information_schema.columns',
        'WHERE' => [
            'table_schema' => $DB->dbdefault,
            'table_name'   => $tabela,
            'column_name'  => 'date_mod',
        ],
    ])->current();

    if ($colDateMod === null) {
        $DB->doQuery(
            "ALTER TABLE `{$tabela}` "
            . "ADD COLUMN `date_mod` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
        );
    }

    // Chaves padrao de permissao (arrays vazios em JSON para getArrayConfig nunca retornar null).
    $padroes = [
        'visualizar_perfis'   => json_encode([]),
        'visualizar_usuarios' => json_encode([]),
        'editar_perfis'       => json_encode([]),
        'editar_usuarios'     => json_encode([]),
    ];

    // Entidade onde formularios e categorias novos sao criados.
    // Vindo do antigo "catalogodeservicos", herda a entidade que ele usava (a de nome "Contrato");
    // num GLPI novo, a entidade raiz. Depois e ajustada pela configuracao.
    $antiga = 'glpi_plugin_catalogodeservicos_configs';
    $padroes['entidade_padrao'] = '0';
    if ($DB->tableExists($antiga)) {
        $ent = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_entities',
            'WHERE'  => ['name' => 'Contrato'],
            'ORDER'  => 'level ASC',
            'LIMIT'  => 1,
        ])->current();
        if ($ent) {
            $padroes['entidade_padrao'] = (string) (int) $ent['id'];
        }
        // Copia unica das permissoes do plugin antigo (a tabela dele nao e alterada)
        foreach ($DB->request(['FROM' => $antiga]) as $velha) {
            if (array_key_exists((string) $velha['name'], $padroes) && (string) $velha['name'] !== 'entidade_padrao') {
                $padroes[(string) $velha['name']] = (string) $velha['value'];
            }
        }
    }

    foreach ($padroes as $nome => $valor) {
        $existe = $DB->request([
            'COUNT' => 'total',
            'FROM'  => $tabela,
            'WHERE' => ['name' => $nome],
        ])->current();

        if ((int) ($existe['total'] ?? 0) === 0) {
            // insertOrDie() foi removido no GLPI 12; insert() existe no 11 e no 12
            $DB->insert($tabela, [
                'name'  => $nome,
                'value' => $valor,
            ]);
        }
    }

    return true;
}

/**
 * Desinstalacao do plugin.
 *
 * IMPORTANTE: por regra do projeto, a tabela NUNCA e removida na desinstalacao.
 * Os dados de configuracao sao preservados mesmo desinstalando o plugin.
 * Nenhum dado nativo do GLPI (formularios, categorias etc.) e tocado aqui.
 */
function plugin_catalogoeformularios_uninstall(): bool
{
    // Tabela mantida de proposito. Nada e dropado aqui.
    return true;
}