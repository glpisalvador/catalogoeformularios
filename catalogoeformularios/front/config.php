<?php


global $CFG_GLPI;

// Esta pagina apenas redireciona para o formulario de configuracao.
Html::redirect($CFG_GLPI['root_doc'] . '/plugins/catalogoeformularios/front/config.form.php');