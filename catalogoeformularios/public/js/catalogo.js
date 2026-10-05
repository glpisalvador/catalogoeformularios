/* ===== Catalogo de Servicos - gerenciador (Ferramentas) ===== */

(function () {
    'use strict';

    var CFG = window.catalogoeformulariosCat;
    if (!CFG) { return; }

    var root = document.querySelector('.catalogoeformularios-app');
    if (!root) { return; }

    var elArvore   = document.getElementById('cat-arvore');
    var elTrilha   = document.getElementById('cat-trilha');
    var elAcoes    = document.getElementById('cat-acoes');
    var elConteudo = document.getElementById('cat-conteudo');
    var podeEditar = !!CFG.podeEditar;

    var TIPOS = {};
    (CFG.tipos || []).forEach(function (t) { TIPOS[t.slug] = t; });

    var estado = {
        modo: 'nivel',
        categoria: 0,
        formId: 0,
        trilha: [],
        conteudo: null,
        detalhe: null,
        arvore: null,
        arvoreAbertos: {},   // { idCategoria: true } -> acordeons expandidos na arvore lateral
        gridAbertos: {},     // { idCategoria: true } -> acordeons expandidos na area principal
        painelForm: 0,       // formulario com o painel rapido aberto na area principal
        painelDados: null,   // ultimo resumo carregado do painel rapido
        _arvoreFoco: null,   // item a trazer para a viewport no proximo render da arvore
        _ilustracoes: null   // cache da biblioteca de ilustracoes nativas do GLPI
    };

    var fonteCache = {};

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // Normaliza para busca: minusculas e sem acento, para "solucao" achar "Solução".
    function normTexto(s) {
        s = String(s == null ? '' : s).toLowerCase();
        try { return s.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
        catch (e) { return s; }
    }

    function toast(msg, ok) {
        var t = document.createElement('div');
        t.textContent = msg;
        t.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:1100;padding:10px 16px;'
            + 'border-radius:6px;font-size:13px;font-weight:600;color:#fff;max-width:420px;'
            + 'box-shadow:0 4px 12px rgba(0,0,0,.2);' + (ok ? 'background:#198754;' : 'background:#dc3545;');
        document.body.appendChild(t);
        setTimeout(function () {
            t.style.transition = 'opacity .3s';
            t.style.opacity = '0';
            setTimeout(function () { t.remove(); }, 300);
        }, 3200);
    }

    // ----- Previa (iframe) do catalogo NATIVO do GLPI -----
    // Recarrega SOMENTE o iframe da previa apos acoes que ALTERAM dados.
    // Espelha a lista $acoesEscrita do catalogo_ajax.php (se criar novas acoes de
    // escrita la, adicione aqui tambem).
    var ACOES_ESCRITA = {
        criar_categoria: 1, editar_categoria: 1, excluir_categoria: 1, mover_categoria: 1, transformar_em_categorias: 1,
        duplicar_categoria: 1, duplicar_formulario: 1, transformar_itil_em_formularios: 1,
        criar_formulario: 1, editar_formulario: 1, alternar_ativo_formulario: 1, excluir_formulario: 1, vincular_categoria: 1,
        criar_secao: 1, editar_secao: 1, excluir_secao: 1, reordenar_secoes: 1,
        criar_pergunta: 1, editar_pergunta: 1, excluir_pergunta: 1, reordenar_perguntas: 1, mover_pergunta: 1,
        destino_criar: 1, destino_renomear: 1, destino_excluir: 1, destino_definir_campo: 1, destino_observador_grupos: 1,
        acesso_salvar_lista: 1, acesso_salvar_direto: 1,
        condicao_definir: 1, condicao_limpar: 1, salvar_condicoes_opcao: 1,
        painel_campo: 1, painel_atores: 1, painel_acesso: 1, painel_campo_lote: 1,
        geral_salvar: 1, pergunta_salvar: 1, pergunta_duplicar: 1, comentario_salvar: 1, comentario_excluir: 1,
        blocos_ordenar: 1, regra_salvar: 1, validacao_salvar: 1
    };

    /**
     * Configuracao do editor de texto rico: copia a do editor-base escondido da pagina
     * (configuracao nativa do GLPI: skin, idioma, colar texto) e tira o envio de arquivos,
     * porque aqui o conteudo e salvo por AJAX direto nas tabelas nativas.
     */
    function configRich(id, valor) {
        var base = (window.tinymce_editor_configs || {})['catalogoeformularios-editor-base'];
        var cfg = base ? Object.assign({}, base) : {
            license_key: 'gpl', branding: false, menubar: false, statusbar: false, skin: false, content_css: false
        };
        cfg.selector = '#' + id;
        delete cfg.target;
        delete cfg.init_instance_callback;
        cfg.plugins = (Array.isArray(cfg.plugins) ? cfg.plugins : String(cfg.plugins || 'lists link table autoresize').split(/[\s,]+/))
            .filter(function (p) { return p && p !== 'glpi_upload_doc' && p !== 'image'; });
        ['lists', 'link', 'autoresize'].forEach(function (p) { if (cfg.plugins.indexOf(p) === -1) { cfg.plugins.push(p); } });
        cfg.toolbar = 'bold italic underline | forecolor | bullist numlist | link table | removeformat';
        cfg.menubar = false;
        cfg.statusbar = false;
        cfg.min_height = 120;
        cfg.paste_data_images = false;
        cfg.convert_urls = false;
        delete cfg.images_upload_handler;
        delete cfg.images_upload_url;
        cfg.setup = function (ed) {
            ed.on('init', function () { ed.setContent(valor || ''); });
            ed.on('Change', function () { ed.save(); });
        };
        return cfg;
    }

var _previewTimer  = null;
    var _previewWatch  = null;
    var _previewFrameEl = null;

    var PREVIEW_ALVOS = [
        '[data-service-catalog]', '.service-catalog', '#service-catalog',
        '.page-body', 'main#page', 'main.legacy', 'main', '#page', '[role="main"]'
    ];

    var PREVIEW_CSS = ''
        + '#navbar-menu,aside.navbar,.navbar-vertical,#sidebar-menu,'
        + 'body>header,.page>header,.page-wrapper>header,header.navbar,'
        + '#impersonate-banner,footer,.footer,#footer,'
        + '.debug-toolbar,#debug-toolbar,#glpi_debug_toolbar{display:none!important;}'
        + 'html,body{overflow-x:hidden!important;background:#fff!important;}'
        + '.page,.page-wrapper,.page-body{margin:0!important;padding-top:0!important;min-height:0!important;}'
        + '.page-body>.container-fluid,.page-body>.container-xl{padding-top:8px!important;}';

    function previewFrame() {
        if (!_previewFrameEl) { _previewFrameEl = document.getElementById('cat-preview-frame'); }
        return _previewFrameEl;
    }
    function previewDoc(frame) {
        try { return frame.contentDocument || null; } catch (e) { return null; }
    }
    function previewOcultar() {
        var f = previewFrame();
        if (f) { f.style.opacity = '0'; }
    }
    function previewRevelar() {
        var f = previewFrame();
        if (f) { f.style.opacity = '1'; }
    }

    // CSS injetado o mais cedo possivel: basta o <head> existir.
    function injetarCssPreview(doc) {
        if (!doc || doc.getElementById('cat-preview-limpeza')) { return; }
        var alvoHead = doc.head || doc.documentElement;
        if (!alvoHead) { return; }
        var st = doc.createElement('style');
        st.id = 'cat-preview-limpeza';
        st.textContent = PREVIEW_CSS;
        alvoHead.appendChild(st);
    }

    // Varredura: sobe do conteudo do catalogo ate o body escondendo os
    // irmaos de cada nivel (menu lateral, barra do topo, rodape, flutuantes).
    function limparCromoPreview(frame) {
        var doc = previewDoc(frame);
        if (!doc || !doc.body) { return; }
        injetarCssPreview(doc);

        var alvo = null;
        for (var i = 0; i < PREVIEW_ALVOS.length && !alvo; i++) {
            var cand = doc.querySelector(PREVIEW_ALVOS[i]);
            if (cand && cand.offsetHeight > 0) { alvo = cand; }
        }
        if (!alvo) { return; }

        var el = alvo;
        while (el && el.parentElement && el !== doc.body) {
            var irmaos = el.parentElement.children;
            for (var j = 0; j < irmaos.length; j++) {
                var ir = irmaos[j];
                if (ir === el) { continue; }
                if (/^(SCRIPT|STYLE|LINK|TEMPLATE|NOSCRIPT)$/.test(ir.tagName)) { continue; }
                ir.style.setProperty('display', 'none', 'important');
            }
            el.style.setProperty('width', '100%', 'important');
            el.style.setProperty('max-width', '100%', 'important');
            el = el.parentElement;
        }
    }

    // Vigia o iframe em alta frequencia para injetar o CSS antes da primeira
    // pintura. Ignora o documento antigo (marcado antes do reload).
    function previewVigiar() {
        clearInterval(_previewWatch);
        var t0 = Date.now();
        _previewWatch = setInterval(function () {
            var frame = previewFrame();
            if (!frame) { clearInterval(_previewWatch); return; }

            var doc = previewDoc(frame);
            var velho = doc && doc.documentElement
                && doc.documentElement.getAttribute('data-cat-velho') === '1';

            if (doc && !velho) {
                injetarCssPreview(doc);

                if (doc.body && (doc.readyState === 'interactive' || doc.readyState === 'complete')) {
                    limparCromoPreview(frame);
                    previewRevelar();
                }
                if (doc.readyState === 'complete') {
                    clearInterval(_previewWatch);
                    // Reaplica: flutuantes de outros plugins entram depois do load.
                    [150, 600, 1500].forEach(function (ms) {
                        setTimeout(function () { limparCromoPreview(frame); }, ms);
                    });
                    return;
                }
            }

            // Seguranca: nunca deixa o iframe invisivel para sempre.
            if (Date.now() - t0 > 5000) {
                clearInterval(_previewWatch);
                limparCromoPreview(frame);
                previewRevelar();
            }
        }, 16);
    }

    function recarregarPreview() {
        var frame = previewFrame();
        if (!frame) { return; }

        var st = document.getElementById('cat-preview-status');
        if (st) { st.textContent = 'atualizando...'; }

        // Marca o documento atual como velho para o vigia nao confundir
        // com o novo (o antigo continua 'complete' por alguns ms).
        var doc = previewDoc(frame);
        if (doc && doc.documentElement) { doc.documentElement.setAttribute('data-cat-velho', '1'); }

        previewOcultar();
        previewVigiar();

        try {
            frame.contentWindow.location.reload();
        } catch (e) {
            frame.src = frame.src;
        }
    }

    function agendarPreview() {
        clearTimeout(_previewTimer);
        _previewTimer = setTimeout(recarregarPreview, 700);
    }


    // Fila: uma requisicao por vez (o token CSRF de uso unico rotaciona a cada resposta).
    var _ajaxFila = Promise.resolve();
    function ajax(action, params) {
        function exec() {
            var fd = new FormData();
            fd.append('action', action);
            // o token so vem preenchido no GLPI 11; no GLPI 12 o CSRF e validado por cabecalho
            if (CFG.csrf) { fd.append('_glpi_csrf_token', CFG.csrf); }
            Object.keys(params || {}).forEach(function (k) {
                var v = params[k];
                if (Array.isArray(v)) {
                    v.forEach(function (x) { fd.append(k + '[]', x); });
                } else if (v !== undefined && v !== null) {
                    fd.append(k, v);
                }
            });
            return fetch(CFG.ajaxUrl, { method: 'POST', body: fd })
                .then(function (r) { return r.text(); })
                .then(function (txt) {
                    var data;
                    try { data = JSON.parse(txt); }
                    catch (e) {
                        var m = txt.match(/\{[\s\S]*\}\s*$/);
                        if (m) { data = JSON.parse(m[0]); } else { throw e; }
                    }
                    if (data && data.new_token) { CFG.csrf = data.new_token; }
                    if (data && data.success && ACOES_ESCRITA[action]) { agendarPreview(); }
                    return data;
                });
        }
        var p = _ajaxFila.then(exec, exec);
        _ajaxFila = p.then(function () {}, function () {});
        return p;
    }

    function carregarFonte(tipo) {
        // So usa cache se for um array com itens; nunca cacheia lista vazia/falha
        // (array vazio em JS e truthy e travaria a fonte vazia ate dar refresh).
        if (Array.isArray(fonteCache[tipo]) && fonteCache[tipo].length) {
            return Promise.resolve(fonteCache[tipo]);
        }
        if (fonteCache[tipo] && !Array.isArray(fonteCache[tipo])) {
            // fontes que retornam objeto (ex.: 'atores' = {usuarios, grupos})
            return Promise.resolve(fonteCache[tipo]);
        }
        return ajax('fonte', { tipo: tipo }).then(function (r) {
            var dados = (r && r.success) ? r.dados : [];
            // so guarda em cache se veio conteudo util
            if ((Array.isArray(dados) && dados.length) || (dados && !Array.isArray(dados))) {
                fonteCache[tipo] = dados;
            }
            return dados;
        });
    }

    // opts.silencioso = true -> atualiza os dados SEM limpar a tela, preservando a
    // rolagem e todos os acordeons abertos (arvore lateral e area principal).
    function recarregarAtual(opts) {
        if (estado.modo === 'builder') { carregarBuilder(estado.formId); }
        else { carregarNivel(estado.categoria, opts); }
        recarregarArvore();
    }

    // Recarrega a lista plana de categorias usada nos selects dos modais.
    // Substitui o location.reload() que antes zerava todo o estado da tela.
    function atualizarCategoriasCFG() {
        return ajax('categorias_catalogo', {}).then(function (r) {
            if (r && r.success && r.dados) { CFG.categorias = r.dados; }
        }).catch(function () {});
    }

    // -----------------------------------------------------------------
    // Painel lateral: arvore
    // -----------------------------------------------------------------
    function recarregarArvore() {
        return ajax('arvore', {}).then(function (r) {
            if (!r || !r.success) {
                elArvore.innerHTML = '<div class="catalogoeformularios-vazio"><i class="ti ti-alert-triangle"></i>'
                    + esc((r && r.message) || 'Falha ao carregar.') + '</div>';
                return;
            }
            estado.arvore = r.dados;
            renderArvore();
        }).catch(function () {
            elArvore.innerHTML = '<div class="catalogoeformularios-vazio"><i class="ti ti-alert-triangle"></i>Falha ao carregar a hierarquia.</div>';
        });
    }

    function renderArvore() {
        if (!estado.arvore) { return; }
        var scroll = elArvore.scrollTop; // preserva a rolagem entre re-renders

        var html = '';
        html += noFormHtmlLista(estado.arvore.formularios_raiz_fixos || []);
        (estado.arvore.categorias || []).forEach(function (c) { html += noCategoriaHtml(c); });
        html += noFormHtmlLista(estado.arvore.formularios_raiz || []);
        if (html === '') {
            html = '<div class="catalogoeformularios-vazio" style="padding:20px;"><i class="ti ti-folder"></i>Vazio</div>';
        }
        elArvore.innerHTML = html;
        aplicarFiltroArvore(document.getElementById('cat-arvore-busca').value || '');
        initDragDropArvore();

        elArvore.scrollTop = scroll;

        // Traz o item recem-movido para a area visivel (sem pular a rolagem se ja estiver nela).
        if (estado._arvoreFoco) {
            var f = estado._arvoreFoco;
            estado._arvoreFoco = null;
            var sel = (f.tipo === 'form')
                ? '[data-nav-form="' + f.id + '"]'
                : '.catalogoeformularios-no[data-cat="' + f.id + '"]';
            var alvo = elArvore.querySelector(sel);
            if (alvo && alvo.scrollIntoView) { alvo.scrollIntoView({ block: 'nearest' }); }
        }
    }

    // Conta, recursivamente, quantas subcategorias e quantos formularios existem
    // dentro de uma categoria (somando todos os niveis abaixo dela).
    function contarNaCategoria(c) {
        var subs  = (c.filhos || []).length;
        var forms = (c.fixados || []).length + (c.formularios || []).length;
        (c.filhos || []).forEach(function (f) {
            var n = contarNaCategoria(f);
            subs  += n.subs;
            forms += n.forms;
        });
        return { subs: subs, forms: forms };
    }

    function noCategoriaHtml(c) {
        var ativo = (estado.modo === 'nivel' && estado.categoria === c.id) ? ' ativo' : '';
        var temFilhos = (c.filhos && c.filhos.length)
                     || (c.fixados && c.fixados.length)
                     || (c.formularios && c.formularios.length);
        var cont = contarNaCategoria(c);
        var abertoNo = !!temFilhos && !!estado.arvoreAbertos[c.id];
        var h = '<div class="catalogoeformularios-no" data-cat="' + c.id + '">';
        h += '<div class="catalogoeformularios-no-cab' + ativo + '" data-nav-cat="' + c.id + '" data-rotulo="' + esc(String(c.nome).toLowerCase()) + '">';
        h += '<span class="catalogoeformularios-no-toggle">'
           + (temFilhos ? '<i class="ti ti-chevron-' + (abertoNo ? 'down' : 'right') + '"></i>' : '')
           + '</span>';
        h += '<span class="catalogoeformularios-no-rotulo">' + iconeCatHtml(c.ilustracao, 16) + esc(c.nome) + '</span>';
        if (cont.subs > 0) {
            h += '<span class="catalogoeformularios-no-contagem catalogoeformularios-no-contagem-sub" title="'
               + cont.subs + ' subcategoria(s) dentro desta categoria">'
               + '<i class="ti ti-folders"></i>' + cont.subs + '</span>';
        }
        if (cont.forms > 0) {
            h += '<span class="catalogoeformularios-no-contagem" title="'
               + cont.forms + ' formulario(s) dentro desta categoria">'
               + '<i class="ti ti-file-text"></i>' + cont.forms + '</span>';
        }
        h += badgePopHtml(c.popularidade, false);
        if (podeEditar) {
            h += '<span class="catalogoeformularios-no-acoes">'
               + '<button class="catalogoeformularios-btn-icone" data-acao="novo-form-cat" data-id="' + c.id + '" title="Adicionar formulario nesta categoria"><i class="ti ti-plus"></i></button>'
               + '<button class="catalogoeformularios-btn-icone" data-acao="editar-cat" data-id="' + c.id + '" title="Editar categoria (nome e icone)"><i class="ti ti-edit"></i></button>'
               + '<button class="catalogoeformularios-btn-icone" data-acao="duplicar-cat" data-id="' + c.id + '" title="Duplicar categoria (com toda a estrutura)"><i class="ti ti-copy"></i></button>'
               + '<button class="catalogoeformularios-btn-icone" data-acao="excluir-cat" data-id="' + c.id + '" title="Excluir categoria"><i class="ti ti-trash"></i></button>'
               + '</span>';
        }
        h += '</div>';
        h += '<div class="catalogoeformularios-no-filhos" style="display:' + (abertoNo ? 'block' : 'none') + ';">';
        // Ordem do catalogo nativo: formularios fixados, depois subcategorias,
        // depois os formularios normais.
        h += noFormHtmlLista(c.fixados || []);
        (c.filhos || []).forEach(function (f) { h += noCategoriaHtml(f); });
        h += noFormHtmlLista(c.formularios || []);
        h += '</div></div>';
        return h;
    }

    function noFormHtmlLista(forms) {
        var h = '';
        forms.forEach(function (f) {
            var ativo = (estado.modo === 'builder' && estado.formId === f.id) ? ' ativo' : '';
            h += '<div class="catalogoeformularios-no-form' + ativo + '" data-nav-form="' + f.id + '" data-rotulo="' + esc(String(f.nome).toLowerCase()) + '">';
            h += '<span class="ponto ' + (f.ativo ? 'on' : 'off') + '"></span>';
            h += '<i class="ti ti-file-text"></i><span class="catalogoeformularios-no-form-nome">' + esc(f.nome) + '</span>';
            if (f.fixado) { h += '<span class="catalogoeformularios-fixado" title="Fixado no catalogo"><i class="ti ti-pin"></i></span>'; }
            h += badgePopHtml(f.usos, true);
            if (podeEditar) {
                h += '<span class="catalogoeformularios-no-acoes">'
                   + '<button class="catalogoeformularios-btn-icone" data-acao="duplicar-form" data-id="' + f.id + '" title="Duplicar formulario"><i class="ti ti-copy"></i></button>'
                   + '<button class="catalogoeformularios-btn-icone" data-acao="excluir-form" data-id="' + f.id + '" title="Excluir formulario"><i class="ti ti-trash"></i></button>'
                   + '</span>';
            }
            h += '</div>';
        });
        return h;
    }

    // -----------------------------------------------------------------
    // Ilustracoes nativas do GLPI 11 (icone das categorias do catalogo)
    // -----------------------------------------------------------------

    // Todas as ilustracoes vivem num unico sprite SVG do GLPI e sao referenciadas
    // por id via <use xlink:href="sprite.svg#id">.
    function spriteIlustracoes() {
        return CFG.spriteIlustracoes
            || ((CFG.rootDoc || '') + '/lib/glpi-project/illustrations/glpi-illustrations-icons.svg');
    }

    // Marcacao do icone de uma categoria. Sem ilustracao definida usa a pasta padrao.
    function iconeCatHtml(ilustracao, tamanho) {
        var t = tamanho || 16;
        if (!ilustracao) { return '<i class="ti ti-folder"></i>'; }
        // Ilustracao enviada pelo usuario (prefixo custom:) nao esta no sprite.
        if (String(ilustracao).indexOf('custom:') === 0) {
            return '<i class="ti ti-photo" title="Ilustracao personalizada"></i>';
        }
        return '<svg class="catalogoeformularios-ilus" width="' + t + '" height="' + t + '" aria-hidden="true">'
             + '<use xlink:href="' + esc(spriteIlustracoes()) + '#' + esc(ilustracao) + '"></use></svg>';
    }

    // Badge de popularidade: e o criterio de ordenacao do catalogo nativo
    // (soma de usage_count dos formularios diretos, para categorias).
    function badgePopHtml(valor, ehForm) {
        var n = parseInt(valor, 10) || 0;
        if (n <= 0) { return ''; }
        var titulo = ehForm
            ? n + ' envio(s) deste formulario'
            : 'Popularidade ' + n + ' (soma dos envios dos formularios diretos)';
        return '<span class="catalogoeformularios-pop" title="' + esc(titulo) + '">'
             + '<i class="ti ti-star"></i>' + n + '</span>';
    }

    // Busca a biblioteca de ilustracoes uma unica vez por sessao de pagina.
    function carregarIlustracoes() {
        if (Array.isArray(estado._ilustracoes)) { return Promise.resolve(estado._ilustracoes); }
        return ajax('ilustracoes', {}).then(function (r) {
            if (r && r.sprite) { CFG.spriteIlustracoes = r.sprite; }
            estado._ilustracoes = (r && r.success && Array.isArray(r.dados)) ? r.dados : [];
            return estado._ilustracoes;
        }).catch(function () {
            estado._ilustracoes = [];
            return estado._ilustracoes;
        });
    }

    // -----------------------------------------------------------------
    // Estado de expansao da arvore lateral (sobrevive aos re-renders)
    // -----------------------------------------------------------------

    // Marca/desmarca uma categoria como expandida no estado persistente da arvore.
    function marcarArvoreAberto(id, aberto) {
        id = parseInt(id, 10) || 0;
        if (!id) { return; }
        if (aberto) { estado.arvoreAbertos[id] = true; }
        else { delete estado.arvoreAbertos[id]; }
    }

    // Abre no estado a categoria do elemento e toda a cadeia de ancestrais dela.
    // Usado no drop para o destino continuar visivel depois do re-render.
    function abrirRamoArvoreDom(el) {
        var no = (el && el.closest) ? el.closest('.catalogoeformularios-no') : null;
        while (no) {
            marcarArvoreAberto(no.getAttribute('data-cat'), true);
            no = no.parentElement ? no.parentElement.closest('.catalogoeformularios-no') : null;
        }
    }

    // Retorna a cadeia de ids da raiz ate a categoria informada (inclusive).
    // Ex.: [avo, pai, filho]. Vazio se a categoria nao existir mais na arvore.
    function caminhoNaArvore(id) {
        id = parseInt(id, 10) || 0;
        if (!id || !estado.arvore) { return []; }
        var achado = [];
        (function varrer(lista, trilha) {
            (lista || []).forEach(function (c) {
                if (achado.length) { return; }
                var atual = trilha.concat([c.id]);
                if (c.id === id) { achado = atual; return; }
                varrer(c.filhos || [], atual);
            });
        })(estado.arvore.categorias || [], []);
        return achado;
    }

    // Reaplica no DOM o estado aberto/fechado de cada categoria da arvore.
    function aplicarEstadoAberturaArvore() {
        elArvore.querySelectorAll('.catalogoeformularios-no').forEach(function (no) {
            var filhos = no.querySelector(':scope > .catalogoeformularios-no-filhos');
            if (!filhos) { return; }
            var aberto = filhos.children.length > 0
                && !!estado.arvoreAbertos[parseInt(no.getAttribute('data-cat'), 10)];
            filhos.style.display = aberto ? 'block' : 'none';
            var ic = no.querySelector(':scope > .catalogoeformularios-no-cab .catalogoeformularios-no-toggle i');
            if (ic) { ic.className = aberto ? 'ti ti-chevron-down' : 'ti ti-chevron-right'; }
        });
    }

    function aplicarFiltroArvore(termo) {
        termo = (termo || '').toLowerCase();
        elArvore.querySelectorAll('[data-rotulo]').forEach(function (el) {
            var ok = el.getAttribute('data-rotulo').indexOf(termo) !== -1;
            el.style.display = ok ? '' : (termo ? 'none' : '');
        });
        if (termo) {
            elArvore.querySelectorAll('.catalogoeformularios-no-filhos').forEach(function (d) { d.style.display = 'block'; });
        } else {
            // Sem busca ativa, volta ao estado real de cada acordeon.
            aplicarEstadoAberturaArvore();
        }
    }

    // Expande/recolhe TODAS as categorias da arvore lateral de uma vez.
    // Decide pela acao com base no estado atual do DOM (robusto a re-render).
    function alternarArvoreTudo() {
        var listas = elArvore.querySelectorAll('.catalogoeformularios-no-filhos');
        var algumFechado = false;
        listas.forEach(function (f) { if (f.style.display === 'none') { algumFechado = true; } });
        var expandir = algumFechado;

        // Sincroniza o estado persistente antes de mexer no DOM.
        if (expandir) {
            elArvore.querySelectorAll('.catalogoeformularios-no').forEach(function (no) {
                marcarArvoreAberto(no.getAttribute('data-cat'), true);
            });
        } else {
            estado.arvoreAbertos = {};
        }

        listas.forEach(function (f) { f.style.display = expandir ? 'block' : 'none'; });
        elArvore.querySelectorAll('.catalogoeformularios-no-toggle i').forEach(function (ic) {
            ic.className = expandir ? 'ti ti-chevron-down' : 'ti ti-chevron-right';
        });
        var b = document.getElementById('cat-arvore-expandir');
        if (b) {
            var bi = b.querySelector('i');
            if (bi) { bi.className = expandir ? 'ti ti-fold-up' : 'ti ti-fold-down'; }
            b.title = expandir ? 'Recolher todos' : 'Expandir todos';
        }
    }

    // Drag-and-drop na arvore (Hierarquia): UM unico Sortable por lista que aceita
    // formularios E categorias (o SortableJS nao permite 2 instancias no mesmo elemento).
    function initDragDropArvore() {
        if (!podeEditar || !window.Sortable) { return; }
        var listas = [elArvore];
        elArvore.querySelectorAll('.catalogoeformularios-no-filhos').forEach(function (el) { listas.push(el); });

        // Resolve a categoria de destino a partir do container onde o item foi solto.
        function destinoDe(to) {
            if (to === elArvore) { return 0; }
            var no = to.closest('.catalogoeformularios-no');
            return no ? (parseInt(no.getAttribute('data-cat'), 10) || 0) : 0;
        }

        listas.forEach(function (lista) {
            Sortable.create(lista, {
                group: 'catalogoeformularios-arvore',
                // Tanto categorias (.catalogoeformularios-no) quanto formularios (.catalogoeformularios-no-form).
                draggable: '.catalogoeformularios-no, .catalogoeformularios-no-form',
                // Nao iniciar arrasto ao clicar no expand/contagem/botoes (mas o clique ainda passa).
                filter: '.catalogoeformularios-no-toggle, .catalogoeformularios-no-contagem, .catalogoeformularios-no-acoes, .catalogoeformularios-no-acoes *',
                preventOnFilter: false,
                animation: 150,
                fallbackOnBody: true,
                swapThreshold: 0.65,
                invertSwap: true,
                emptyInsertThreshold: 15,
                ghostClass: 'catalogoeformularios-sortable-ghost',
                chosenClass: 'catalogoeformularios-sortable-chosen',
                // Reordenar irmaos nao e persistivel: a ordem do catalogo do GLPI 11.0.4
                // e por popularidade (usage_count) e nao ha ordem manual no core.
                // Avisa e devolve a lista para a ordem real.
                onUpdate: function (evt) {
                    abrirRamoArvoreDom(evt.to);
                    toast('A ordem segue a popularidade do catalogo do GLPI e nao pode ser definida manualmente.', false);
                    recarregarArvore();
                },
                // Mover entre listas (trocar de categoria pai) continua funcionando.
                // Apenas a POSICAO dentro da lista nao e persistida: quem define e a
                // popularidade, calculada pelo backend.
                onAdd: function (evt) {
                    var destCat = destinoDe(evt.to);
                    var item = evt.item;

                    // Mantem expandido o ramo onde o item foi solto (e os ancestrais dele),
                    // para a arvore voltar exatamente no ponto onde o usuario estava.
                    abrirRamoArvoreDom(evt.to);

                    // Formulario?
                    var formId = parseInt(item.getAttribute('data-nav-form'), 10);
                    if (item.classList.contains('catalogoeformularios-no-form') || formId) {
                        if (!formId) { recarregarArvore(); return; }
                        estado._arvoreFoco = { tipo: 'form', id: formId };
                        ajax('vincular_categoria', { form: formId, categoria: destCat }).then(function (r) {
                            toast((r && r.message) || (r && r.success ? 'Formulario movido.' : 'Falha ao mover.'), !!(r && r.success));
                            recarregarAtual({ silencioso: true });
                        });
                        return;
                    }

                    // Categoria?
                    if (item.classList.contains('catalogoeformularios-no')) {
                        var catId = parseInt(item.getAttribute('data-cat'), 10);
                        if (!catId || catId === destCat) { recarregarArvore(); return; }
                        // A categoria arrastada mantem o proprio estado: se estava aberta, continua aberta.
                        estado._arvoreFoco = { tipo: 'cat', id: catId };
                        ajax('mover_categoria', { id: catId, pai: destCat }).then(function (r) {
                            toast((r && r.message) || (r && r.success ? 'Categoria movida.' : 'Falha ao mover.'), !!(r && r.success));
                            recarregarAtual({ silencioso: true });   // ja chama recarregarArvore() internamente
                        });
                    }
                }
            });
        });
    }

    // -----------------------------------------------------------------
    // Navegacao por nivel
    // -----------------------------------------------------------------
    function carregarNivel(catId, opts) {
        opts = opts || {};
        // Em modo silencioso o conteudo atual permanece na tela enquanto o novo chega.
        if (!opts.silencioso) { elConteudo.innerHTML = carregando(); }
        ajax('conteudo', { categoria: catId }).then(function (data) {
            if (!data || !data.success) {
                elConteudo.innerHTML = vazio('ti ti-alert-triangle', (data && data.message) || 'Falha ao carregar.');
                return;
            }
            estado.modo = 'nivel';
            estado.categoria = catId;
            estado.trilha = data.dados.trilha || [];
            estado.conteudo = data.dados;
            renderTrilha(estado.trilha, null);
            renderAcoesNivel();
            renderGrid(data.dados, opts);
            // Em modo silencioso quem redesenha a arvore e o recarregarArvore(),
            // ja com dados frescos. Evita o render duplicado que causava o piscar.
            if (!opts.silencioso) { renderArvore(); }
        }).catch(function () {
            elConteudo.innerHTML = vazio('ti ti-alert-triangle', 'Falha ao carregar o conteudo.');
        });
    }

    function carregando() {
        return '<div class="catalogoeformularios-cat-carregando"><i class="ti ti-loader"></i> Carregando...</div>';
    }
    function vazio(icone, txt) {
        return '<div class="catalogoeformularios-vazio"><i class="' + icone + '"></i>' + esc(txt) + '</div>';
    }

    function renderTrilha(trilha, formNome) {
        var html = '<button class="catalogoeformularios-trilha-item" data-nav-cat="0">Categorias e Formulários</button>';
        (trilha || []).forEach(function (c) {
            html += '<span class="catalogoeformularios-trilha-sep">/</span>';
            var atual = (!formNome && c.id === estado.categoria);
            html += '<button class="catalogoeformularios-trilha-item' + (atual ? ' atual' : '') + '" data-nav-cat="' + c.id + '">' + esc(c.nome) + '</button>';
        });
        if (formNome) {
            html += '<span class="catalogoeformularios-trilha-sep">/</span>';
            html += '<span class="catalogoeformularios-trilha-item atual">' + esc(formNome) + '</span>';
        }
        elTrilha.innerHTML = html;
    }

    function renderAcoesNivel() {
        var matriz = '<button class="catalogoeformularios-btn" data-acao="matriz" title="Ver e editar todos os formularios deste nivel numa tabela"><i class="ti ti-table"></i> Matriz</button>';
        if (!podeEditar) { elAcoes.innerHTML = matriz; return; }
        elAcoes.innerHTML =
            matriz
          + '<button class="catalogoeformularios-btn catalogoeformularios-btn-primario" data-acao="nova-cat"><i class="ti ti-folder-plus"></i> Nova categoria</button>'
          + '<button class="catalogoeformularios-btn catalogoeformularios-btn-primario" data-acao="novo-form"><i class="ti ti-plus"></i> Novo formulario</button>'
          + '<button class="catalogoeformularios-btn" data-acao="transformar"><i class="ti ti-transform"></i> Transformar...</button>';
    }

    function cardAcoesHtml(botoes) {
        if (!podeEditar) { return ''; }
        var h = '<div class="catalogoeformularios-card-acoes">';
        botoes.forEach(function (b) {
            h += '<button class="catalogoeformularios-btn-icone" data-acao="' + b.acao + '" data-id="' + b.id + '" title="' + esc(b.titulo) + '"><i class="' + b.icone + '"></i></button>';
        });
        return h + '</div>';
    }

    function renderGrid(d, opts) {
        opts = opts || {};
        var scrollAnterior = root.scrollTop; // preserva a rolagem da area principal
        // Botao "expandir todas" vive na barra de titulo; esconde e so reexibe se houver categorias.
        var _btnExp = document.getElementById('cat-grid-expandir');
        if (_btnExp) { _btnExp.style.display = 'none'; }
        if (d.categorias.length === 0 && d.formularios.length === 0) {
            elConteudo.innerHTML = vazio('ti ti-folder', 'Nada por aqui ainda.' + (podeEditar ? ' Use os botoes acima para criar.' : ''));
            return;
        }
        var html = '';

        // Categorias deste nivel: cada uma como um painel ja listando os formularios dentro.
        if (d.categorias.length) {
            if (_btnExp) {
                _btnExp.style.display = '';
                var _biExp = _btnExp.querySelector('i');
                if (_biExp) { _biExp.className = 'ti ti-fold-down'; }
                _btnExp.title = 'Expandir todas';
            }
            html += '<div class="catalogoeformularios-grupos">';
            d.categorias.forEach(function (c) { html += grupoCategoriaHtml(c); });
            html += '</div>';
        }

        // Formularios ligados diretamente a este nivel (fora das subcategorias).
        if (d.formularios.length) {
            html += '<div class="catalogoeformularios-secao-titulo">Formularios neste nivel</div>';
            html += '<div class="catalogoeformularios-grupo catalogoeformularios-grupo-solto" data-cat="0"><div class="catalogoeformularios-grupo-corpo">';
            d.formularios.forEach(function (f) { html += formLinhaHtml(f); });
            html += '</div></div>';
        }

        elConteudo.innerHTML = html;
        initDragDropGrid();
        aplicarEstadoGridSalvo();
        // O painel rapido sobrevive aos re-renders: reabre no mesmo formulario.
        if (estado.painelForm) { pnAbrir(estado.painelForm, { rolar: false }); }
        renderNavegador();
        if (opts.silencioso) { root.scrollTop = scrollAnterior; }
    }

    var _gridArrastouMoveu = false;

    function initDragDropGrid() {
        if (!podeEditar || !window.Sortable) { return; }
        elConteudo.querySelectorAll('.catalogoeformularios-grupo-corpo').forEach(function (corpo) {
            Sortable.create(corpo, {
                group: 'catalogoeformularios-cat-forms',
                draggable: '.catalogoeformularios-form-linha',
                filter: '.catalogoeformularios-form-linha-acoes, .catalogoeformularios-btn-icone',
                preventOnFilter: false,
                animation: 150,
                ghostClass: 'catalogoeformularios-sortable-ghost',
                chosenClass: 'catalogoeformularios-sortable-chosen',
                onStart: function () { _gridArrastouMoveu = false; pnFecharDom(); expandirGruposParaArrastar(); },
                onEnd: function () { if (!_gridArrastouMoveu) { colapsarGrupos(); } },
                // Reordenar no mesmo acordeon nao e persistivel (ver comentario da arvore).
                onUpdate: function (evt) {
                    _gridArrastouMoveu = true;
                    toast('A ordem segue a popularidade do catalogo do GLPI e nao pode ser definida manualmente.', false);
                    recarregarAtual({ silencioso: true });
                },
                onAdd: function (evt) {
                    _gridArrastouMoveu = true;
                    var grupoDest = evt.to.closest('.catalogoeformularios-grupo');
                    var destCat = grupoDest ? (parseInt(grupoDest.getAttribute('data-cat'), 10) || 0) : 0;
                    var formId = parseInt(evt.item.getAttribute('data-abrir-form'), 10);
                    if (!formId) { recarregarAtual({ silencioso: true }); return; }
                    ajax('vincular_categoria', { form: formId, categoria: destCat }).then(function (r) {
                        toast((r && r.message) || (r && r.success ? 'Formulario movido.' : 'Falha ao mover.'), !!(r && r.success));
                        recarregarAtual({ silencioso: true });
                    });
                }
            });
        });
    }

    // Mantem o icone do botao de ramo coerente com o estado do proprio no.
    function sincronizarBotaoRamo(el, expandir) {
        var btn = el.querySelector(
            ':scope > .catalogoeformularios-grupo-cab [data-expandir-ramo],'
            + ':scope > .catalogoeformularios-subcat-cab [data-expandir-ramo]'
        );
        if (!btn) { return; }
        var i = btn.querySelector('i');
        if (i) { i.className = expandir ? 'ti ti-fold-up' : 'ti ti-fold-down'; }
        btn.title = expandir
            ? 'Recolher esta categoria e tudo dentro dela'
            : 'Expandir esta categoria e tudo dentro dela';
    }

    // Aplica o estado (aberto/fechado) num acordeon de categoria de 1o nivel.
    // salvar = false -> aplica no DOM sem gravar no estado (usado no arrasto).
    function aplicarEstadoGrupo(g, expandir, salvar) {
        if (!g) { return; }
        var corpo = g.querySelector('.catalogoeformularios-grupo-corpo');
        if (!corpo) { return; }
        corpo.style.display = expandir ? '' : 'none';
        g.classList.toggle('catalogoeformularios-grupo-fechado', !expandir);
        var ic = g.querySelector('.catalogoeformularios-grupo-chevron i');
        if (ic) { ic.className = expandir ? 'ti ti-chevron-down' : 'ti ti-chevron-right'; }
        sincronizarBotaoRamo(g, expandir);
        if (salvar !== false) { marcarGridAberto(g.getAttribute('data-cat'), expandir); }
    }

    // Aplica o estado (aberto/fechado) numa subcategoria aninhada.
    function aplicarEstadoSubcat(s, expandir, salvar) {
        if (!s) { return; }
        var corpo = s.querySelector(':scope > .catalogoeformularios-subcat-corpo');
        if (!corpo) { return; }
        corpo.style.display = expandir ? '' : 'none';
        s.classList.toggle('catalogoeformularios-subcat-fechado', !expandir);
        var ic = s.querySelector(':scope > .catalogoeformularios-subcat-cab .catalogoeformularios-subcat-chevron i');
        if (ic) { ic.className = expandir ? 'ti ti-chevron-down' : 'ti ti-chevron-right'; }
        sincronizarBotaoRamo(s, expandir);
        if (salvar !== false) { marcarGridAberto(s.getAttribute('data-subcat'), expandir); }
    }

    // Guarda quais acordeons da area principal estao abertos, para sobreviverem
    // aos re-renders (excluir, duplicar, criar, mover formulario...).
    function marcarGridAberto(id, aberto) {
        id = parseInt(id, 10) || 0;
        if (!id) { return; }
        if (aberto) { estado.gridAbertos[id] = true; }
        else { delete estado.gridAbertos[id]; }
    }

    // Reabre, apos um re-render, exatamente os acordeons que estavam abertos.
    function aplicarEstadoGridSalvo() {
        var abertos = 0, total = 0;
        todosOsGrupos().forEach(function (g) {
            total++;
            if (estado.gridAbertos[parseInt(g.getAttribute('data-cat'), 10)]) {
                aplicarEstadoGrupo(g, true, false);
                abertos++;
            }
        });
        todasAsSubcats().forEach(function (s) {
            total++;
            if (estado.gridAbertos[parseInt(s.getAttribute('data-subcat'), 10)]) {
                aplicarEstadoSubcat(s, true, false);
                abertos++;
            }
        });
        // Mantem o icone do botao "expandir todas" coerente com o que ficou na tela.
        var b = document.getElementById('cat-grid-expandir');
        if (b && total > 0) {
            var tudoAberto = (abertos === total);
            var bi = b.querySelector('i');
            if (bi) { bi.className = tudoAberto ? 'ti ti-fold-up' : 'ti ti-fold-down'; }
            b.title = tudoAberto ? 'Recolher tudo' : 'Expandir tudo';
        }
    }

    // Expande/recolhe UM ramo especifico: a categoria clicada + todas as subcategorias
    // dentro dela, em qualquer profundidade. Nao afeta as outras categorias da tela.
    function alternarRamo(raiz) {
        if (!raiz) { return; }
        var ehGrupo = raiz.classList.contains('catalogoeformularios-grupo');
        var subs    = raiz.querySelectorAll('.catalogoeformularios-subcat');

        var proprioCorpo = ehGrupo
            ? raiz.querySelector('.catalogoeformularios-grupo-corpo')
            : raiz.querySelector(':scope > .catalogoeformularios-subcat-corpo');

        var algumFechado = !!(proprioCorpo && proprioCorpo.style.display === 'none');
        subs.forEach(function (s) {
            var c = s.querySelector(':scope > .catalogoeformularios-subcat-corpo');
            if (c && c.style.display === 'none') { algumFechado = true; }
        });

        var expandir = algumFechado;
        if (ehGrupo) { aplicarEstadoGrupo(raiz, expandir); }
        else { aplicarEstadoSubcat(raiz, expandir); }
        subs.forEach(function (s) { aplicarEstadoSubcat(s, expandir); });
    }

    function todosOsGrupos() {
        return elConteudo.querySelectorAll('.catalogoeformularios-grupo:not(.catalogoeformularios-grupo-solto)');
    }
    function todasAsSubcats() {
        return elConteudo.querySelectorAll('.catalogoeformularios-subcat');
    }

    // Durante o arrasto, abre tudo (menos o painel "neste nivel") para virarem alvos.
    function expandirGruposParaArrastar() {
        todosOsGrupos().forEach(function (g) { aplicarEstadoGrupo(g, true, false); });
        todasAsSubcats().forEach(function (s) { aplicarEstadoSubcat(s, true, false); });
    }

    // Recolhe novamente tudo quando o arrasto nao moveu nada.
    function colapsarGrupos() {
        // Volta ao estado que o usuario tinha antes de comecar a arrastar.
        todosOsGrupos().forEach(function (g) { aplicarEstadoGrupo(g, false, false); });
        todasAsSubcats().forEach(function (s) { aplicarEstadoSubcat(s, false, false); });
        aplicarEstadoGridSalvo();
    }

    // Expande/recolhe TODOS os acordeons: categorias de 1o nivel E subcategorias aninhadas,
    // em qualquer profundidade, sem sair da tela atual.
    function alternarGruposTudo() {
        var grupos  = todosOsGrupos();
        var subcats = todasAsSubcats();

        var algumFechado = false;
        grupos.forEach(function (g) {
            var c = g.querySelector('.catalogoeformularios-grupo-corpo');
            if (c && c.style.display === 'none') { algumFechado = true; }
        });
        subcats.forEach(function (s) {
            var c = s.querySelector(':scope > .catalogoeformularios-subcat-corpo');
            if (c && c.style.display === 'none') { algumFechado = true; }
        });

        var expandir = algumFechado;
        grupos.forEach(function (g) { aplicarEstadoGrupo(g, expandir); });
        subcats.forEach(function (s) { aplicarEstadoSubcat(s, expandir); });

        var b = document.getElementById('cat-grid-expandir');
        if (b) {
            var bi = b.querySelector('i');
            if (bi) { bi.className = expandir ? 'ti ti-fold-up' : 'ti ti-fold-down'; }
            b.title = expandir ? 'Recolher tudo' : 'Expandir tudo';
        }
    }

    function grupoCategoriaHtml(c) {
        var forms = c.formularios || [];
        var h = '<div class="catalogoeformularios-grupo catalogoeformularios-grupo-fechado" data-cat="' + c.id + '">';
        h += '<div class="catalogoeformularios-grupo-cab" data-acordeon="1">';
        h += '<span class="catalogoeformularios-grupo-chevron"><i class="ti ti-chevron-right"></i></span>';
        h += '<span class="catalogoeformularios-grupo-icone">' + iconeCatHtml(c.ilustracao, 18) + '</span>';
        h += '<span class="catalogoeformularios-grupo-nome">' + esc(c.nome) + '</span>';
        h += '<span class="catalogoeformularios-grupo-contagem"><i class="ti ti-file-text"></i> ' + c.qtd_form
           + ' <span style="color:#cfd4da;">|</span> <i class="ti ti-folder"></i> ' + c.qtd_subcat + '</span>';
        h += badgePopHtml(c.popularidade, false);
        if (podeEditar) {
            h += '<span class="catalogoeformularios-grupo-acoes">'
               + '<button class="catalogoeformularios-btn-icone" data-acao="duplicar-cat" data-id="' + c.id + '" title="Duplicar categoria (com toda a estrutura)"><i class="ti ti-copy"></i></button>'
               + '<button class="catalogoeformularios-btn-icone" data-acao="editar-cat" data-id="' + c.id + '" title="Editar categoria"><i class="ti ti-edit"></i></button>'
               + '<button class="catalogoeformularios-btn-icone" data-acao="excluir-cat" data-id="' + c.id + '" title="Excluir categoria"><i class="ti ti-trash"></i></button>'
               + '</span>';
        }
        h += '<button class="catalogoeformularios-btn-icone" data-expandir-ramo="1" title="Expandir esta categoria e tudo dentro dela"><i class="ti ti-fold-down"></i></button>';
        h += '<button class="catalogoeformularios-btn-icone catalogoeformularios-grupo-entrar" data-abrir-cat="' + c.id + '" title="Abrir esta categoria"><i class="ti ti-arrow-right"></i></button>';
        h += '</div>';
        h += '<div class="catalogoeformularios-grupo-corpo" style="display:none;">';
        if (forms.length) {
            forms.forEach(function (f) { h += formLinhaHtml(f); });
        } else {
            h += '<div class="catalogoeformularios-grupo-vazio"><i class="ti ti-mood-empty"></i> Nenhum formulario direto nesta categoria.</div>';
        }
        // Subcategorias aninhadas (em qualquer profundidade), com seus formularios.
        (c.subcategorias || []).forEach(function (sub) { h += subCategoriaHtml(sub, 1); });
        h += '</div></div>';
        return h;
    }

    /** Renderiza uma subcategoria aninhada (recursiva) como acordeon, com recuo por nivel. */
    function subCategoriaHtml(sub, nivel) {
        var forms = sub.formularios || [];
        var subs  = sub.subcategorias || [];
        var h = '<div class="catalogoeformularios-subcat catalogoeformularios-subcat-fechado" data-subcat="' + sub.id + '" style="margin-left:' + (nivel * 14) + 'px;">';
        h += '<div class="catalogoeformularios-subcat-cab" data-acordeon-sub="1" title="Expandir / recolher">'
           + '<span class="catalogoeformularios-subcat-chevron"><i class="ti ti-chevron-right"></i></span>'
           + '<i class="ti ti-corner-down-right catalogoeformularios-subcat-seta"></i>'
           + iconeCatHtml(sub.ilustracao, 16) + ' <span class="catalogoeformularios-subcat-nome">' + esc(sub.nome) + '</span>'
           + '<span class="catalogoeformularios-subcat-contagem">' + forms.length + ' formulario(s)'
           + (subs.length ? ' <span style="color:#cfd4da;">|</span> ' + subs.length + ' subcat.' : '')
           + '</span>'
           + badgePopHtml(sub.popularidade, false)
           + (podeEditar ? '<button class="catalogoeformularios-btn-icone catalogoeformularios-subcat-editar" data-acao="editar-cat" data-id="' + sub.id + '" title="Editar categoria (nome e icone)"><i class="ti ti-edit"></i></button>' : '')
           + (subs.length ? '<button class="catalogoeformularios-btn-icone catalogoeformularios-subcat-ramo" data-expandir-ramo="1" title="Expandir esta categoria e tudo dentro dela"><i class="ti ti-fold-down"></i></button>' : '')
           + '<button class="catalogoeformularios-btn-icone catalogoeformularios-subcat-entrar" data-abrir-cat="' + sub.id + '" title="Abrir esta categoria"><i class="ti ti-arrow-right"></i></button>'
           + '</div>';
        h += '<div class="catalogoeformularios-subcat-corpo" style="display:none;">';
        if (forms.length) {
            forms.forEach(function (f) { h += formLinhaHtml(f); });
        } else if (!subs.length) {
            h += '<div class="catalogoeformularios-grupo-vazio" style="margin-left:6px;"><i class="ti ti-mood-empty"></i> Sem formularios.</div>';
        }
        subs.forEach(function (s) { h += subCategoriaHtml(s, nivel + 1); });
        h += '</div></div>';
        return h;
    }

    // Monta o indicador de perfis com acesso na barra do formulario.
    // Ate 3 perfis: mostra os nomes. Mais de 3 (ou "todos"): icone com tooltip.
    function perfisFormHtml(f) {
        var p = f.perfis || {};
        var nomes = p.nomes || [];
        if (p.todos) {
            return '<span class="catalogoeformularios-form-perfis-icone" title="Todos os perfis"><i class="ti ti-users"></i> Todos</span>';
        }
        if (!nomes.length) { return ''; }
        if (nomes.length <= 3) {
            var chips = nomes.map(function (n) {
                return '<span class="catalogoeformularios-perfil-chip" title="' + esc(n) + '">' + esc(n) + '</span>';
            }).join('');
            return '<span class="catalogoeformularios-form-perfis">' + chips + '</span>';
        }
        return '<span class="catalogoeformularios-form-perfis-icone" title="' + esc(nomes.join(', ')) + '"><i class="ti ti-users"></i> ' + nomes.length + ' perfis</span>';
    }

    function formLinhaHtml(f) {
        var badge = f.rascunho
            ? '<span class="catalogoeformularios-badge catalogoeformularios-badge-rascunho">Rascunho</span>'
            : (f.ativo ? '<span class="catalogoeformularios-badge catalogoeformularios-badge-ativo">Ativo</span>'
                       : '<span class="catalogoeformularios-badge catalogoeformularios-badge-inativo">Inativo</span>');
        var aberto = (estado.painelForm === f.id);
        var h = '<div class="catalogoeformularios-form-linha' + (aberto ? ' painel-aberto' : '') + '" data-abrir-form="' + f.id + '" data-ativo="' + (f.ativo ? 1 : 0) + '" title="Abrir o painel de controle deste formulario">';
        h += '<span class="catalogoeformularios-form-linha-chevron"><i class="ti ti-chevron-' + (aberto ? 'down' : 'right') + '"></i></span>';
        h += '<span class="ponto ' + (f.ativo ? 'on' : 'off') + '"></span>';
        h += f.ilustracao
            ? '<span class="catalogoeformularios-form-linha-ic">' + iconeCatHtml(f.ilustracao, 18) + '</span>'
            : '<i class="ti ti-file-text catalogoeformularios-form-linha-ic"></i>';
        h += '<span class="catalogoeformularios-form-linha-nome">' + esc(f.nome) + '</span>';
        h += perfisFormHtml(f);
        h += '<span class="catalogoeformularios-form-linha-acoes">';
        h += '<button class="catalogoeformularios-btn-icone" data-acao="painel-form" data-id="' + f.id + '" title="Painel de controle (chamado, SLA, atores e acesso)"><i class="ti ti-list-details"></i></button>';
        if (podeEditar) {
            h += '<button class="catalogoeformularios-btn-icone" data-acao="toggle-form" data-id="' + f.id + '" title="' + (f.ativo ? 'Desativar' : 'Ativar') + '"><i class="' + (f.ativo ? 'ti ti-eye-off' : 'ti ti-eye') + '"></i></button>';
        }
        h += '<button class="catalogoeformularios-btn-icone" data-acao="abrir-form-builder" data-id="' + f.id + '" title="Estrutura: secoes, perguntas e condicoes"><i class="ti ti-layout-list"></i></button>';
        if (podeEditar) {
            h += '<button class="catalogoeformularios-btn-icone catalogoeformularios-form-menu-btn" data-acao="menu-form" data-id="' + f.id + '" title="Mais acoes"><i class="ti ti-dots-vertical"></i></button>';
        }
        h += '</span>';
        h += badge;
        h += '</div>';
        return h;
    }

    // -----------------------------------------------------------------
    // Builder do formulario
    // -----------------------------------------------------------------
    function carregarBuilder(formId) {
        elConteudo.innerHTML = carregando();
        ajax('formulario', { form: formId }).then(function (data) {
            if (!data || !data.success) {
                toast((data && data.message) || 'Falha ao carregar o formulario.', false);
                carregarNivel(estado.categoria);
                return;
            }
            estado.modo = 'builder';
            estado.formId = formId;
            estado.detalhe = data.dados;
            renderTrilha(estado.trilha, data.dados.nome);
            renderAcoesBuilder(data.dados);
            renderBuilder(data.dados);
            initDragDrop();
            renderArvore();
            renderNavegador();
            if (estado._refreshModalBuilder) { estado._refreshModalBuilder(); }
        });
    }

    function renderAcoesBuilder(det) {
        var html = '<button class="catalogoeformularios-btn" data-acao="voltar"><i class="ti ti-arrow-left"></i> Voltar</button>';
        if (podeEditar) {
            html += '<button class="catalogoeformularios-btn" data-acao="config-form-builder"><i class="ti ti-settings"></i> Destinos e acesso</button>';
            html += '<button class="catalogoeformularios-btn" data-acao="toggle-form-builder"><i class="' + (det.ativo ? 'ti ti-eye-off' : 'ti ti-eye') + '"></i> ' + (det.ativo ? 'Desativar' : 'Ativar') + '</button>';
            html += '<button class="catalogoeformularios-btn" data-acao="editar-form-builder"><i class="ti ti-edit"></i> Editar</button>';
            html += '<button class="catalogoeformularios-btn catalogoeformularios-btn-primario" data-acao="nova-secao"><i class="ti ti-plus"></i> Nova secao</button>';
        }
        elAcoes.innerHTML = html;
    }

    function renderBuilder(det) {
        // No modo builder nao ha acordeons de categoria; esconde o botao de expandir todas.
        var _btnExpB = document.getElementById('cat-grid-expandir');
        if (_btnExpB) { _btnExpB.style.display = 'none'; }
        var badge = det.ativo
            ? '<span class="catalogoeformularios-badge catalogoeformularios-badge-ativo">Ativo</span>'
            : '<span class="catalogoeformularios-badge catalogoeformularios-badge-inativo">Inativo</span>';

        var html = '<div class="catalogoeformularios-form-info"><div class="catalogoeformularios-card-nome">' + esc(det.nome) + ' ' + badge + '</div>'
            + (det.descricao ? '<div class="desc">' + det.descricao + '</div>' : '')
            + '<div class="resumo"><span><i class="ti ti-target"></i> ' + det.qtd_destinos + ' destino(s)</span>'
            + '<span><i class="ti ti-shield"></i> ' + det.qtd_acesso + ' controle(s) de acesso</span></div></div>';

        html += '<div id="cat-secoes">';
        if (!det.secoes.length) {
            html += vazio('ti ti-layout-list', 'Sem secoes.');
        }
        det.secoes.forEach(function (s) {
            html += '<div class="catalogoeformularios-secao" data-secao="' + s.id + '">';
            html += '<div class="catalogoeformularios-secao-head"><div class="catalogoeformularios-secao-nome">'
                + (podeEditar ? '<i class="ti ti-grip-vertical catalogoeformularios-secao-arrasta"></i>' : '')
                + '<i class="ti ti-layout-list"></i> ' + esc(s.nome) + '</div>';
            if (podeEditar) {
                html += '<div class="catalogoeformularios-acoes-linha">'
                    + '<button class="catalogoeformularios-btn-icone" data-acao="nova-pergunta" data-id="' + s.id + '" title="Adicionar pergunta"><i class="ti ti-plus"></i></button>'
                    + '<button class="catalogoeformularios-btn-icone" data-acao="editar-secao" data-id="' + s.id + '" title="Editar secao"><i class="ti ti-edit"></i></button>'
                    + '<button class="catalogoeformularios-btn-icone" data-acao="excluir-secao" data-id="' + s.id + '" title="Excluir secao"><i class="ti ti-trash"></i></button>'
                    + '</div>';
            }
            html += '</div><div class="catalogoeformularios-secao-body" data-secao="' + s.id + '">';
            if (!s.perguntas.length) {
                html += '<div style="font-size:12px;color:#999;padding:6px 4px;">Sem perguntas nesta secao.</div>';
            }
            s.perguntas.forEach(function (p) {
                var cond = (p.visibilidade && p.visibilidade.tem_regra)
                    ? '<span class="catalogoeformularios-pergunta-cond" title="Possui regra de visibilidade"><i class="ti ti-eye"></i> condicional</span>' : '';
                html += '<div class="catalogoeformularios-pergunta" data-pergunta="' + p.id + '">'
                    + (podeEditar ? '<i class="ti ti-grip-vertical catalogoeformularios-pergunta-arrasta"></i>' : '')
                    + '<span class="catalogoeformularios-pergunta-nome">' + esc(p.nome)
                    + (p.obrigatoria ? '<span class="catalogoeformularios-pergunta-obrig">*</span>' : '') + '</span>'
                    + cond
                    + '<span class="catalogoeformularios-pergunta-tipo">' + esc(p.tipo_label) + '</span>';
                if (podeEditar) {
                    html += '<div class="catalogoeformularios-acoes-linha">'
                        + '<button class="catalogoeformularios-btn-icone" data-acao="condicao-pergunta" data-id="' + p.id + '" title="Visibilidade condicional"><i class="ti ti-eye-check"></i></button>'
                        + '<button class="catalogoeformularios-btn-icone" data-acao="editar-pergunta" data-id="' + p.id + '" data-secao="' + s.id + '" title="Editar"><i class="ti ti-edit"></i></button>'
                        + '<button class="catalogoeformularios-btn-icone" data-acao="excluir-pergunta" data-id="' + p.id + '" title="Excluir"><i class="ti ti-trash"></i></button>'
                        + '</div>';
                }
                html += '</div>';
            });
            html += '</div></div>';
        });
        html += '</div>';
        elConteudo.innerHTML = html;
    }

    function renderModalSecoes(container, det) {
        var html = '';
        if (podeEditar) {
            html += '<div style="margin-bottom:10px;"><button type="button" class="catalogoeformularios-btn catalogoeformularios-btn-primario" data-acao="nova-secao"><i class="ti ti-plus"></i> Nova secao</button></div>';
        }
        if (!det.secoes || !det.secoes.length) {
            html += '<div style="font-size:12px;color:#999;padding:6px 4px;">Sem secoes.</div>';
        }
        (det.secoes || []).forEach(function (s) {
            html += '<div class="catalogoeformularios-secao" data-secao="' + s.id + '">';
            html += '<div class="catalogoeformularios-secao-head"><div class="catalogoeformularios-secao-nome">'
                + '<i class="ti ti-layout-list"></i> ' + esc(s.nome) + '</div>';
            if (podeEditar) {
                html += '<div class="catalogoeformularios-acoes-linha">'
                    + '<button class="catalogoeformularios-btn-icone" data-acao="nova-pergunta" data-id="' + s.id + '" title="Adicionar pergunta"><i class="ti ti-plus"></i></button>'
                    + '<button class="catalogoeformularios-btn-icone" data-acao="editar-secao" data-id="' + s.id + '" title="Editar secao"><i class="ti ti-edit"></i></button>'
                    + '<button class="catalogoeformularios-btn-icone" data-acao="excluir-secao" data-id="' + s.id + '" title="Excluir secao"><i class="ti ti-trash"></i></button>'
                    + '</div>';
            }
            html += '</div><div class="catalogoeformularios-secao-body">';
            if (!s.perguntas || !s.perguntas.length) {
                html += '<div style="font-size:12px;color:#999;padding:6px 4px;">Sem perguntas nesta secao.</div>';
            }
            (s.perguntas || []).forEach(function (p) {
                var cond = (p.visibilidade && p.visibilidade.tem_regra)
                    ? '<span class="catalogoeformularios-pergunta-cond" title="Possui regra de visibilidade"><i class="ti ti-eye"></i> condicional</span>' : '';
                html += '<div class="catalogoeformularios-pergunta" data-pergunta="' + p.id + '">'
                    + '<span class="catalogoeformularios-pergunta-nome">' + esc(p.nome)
                    + (p.obrigatoria ? '<span class="catalogoeformularios-pergunta-obrig">*</span>' : '') + '</span>'
                    + cond
                    + '<span class="catalogoeformularios-pergunta-tipo">' + esc(p.tipo_label) + '</span>';
                if (podeEditar) {
                    html += '<div class="catalogoeformularios-acoes-linha">'
                        + '<button class="catalogoeformularios-btn-icone" data-acao="condicao-pergunta" data-id="' + p.id + '" title="Visibilidade condicional"><i class="ti ti-eye-check"></i></button>'
                        + '<button class="catalogoeformularios-btn-icone" data-acao="editar-pergunta" data-id="' + p.id + '" data-secao="' + s.id + '" title="Editar"><i class="ti ti-edit"></i></button>'
                        + '<button class="catalogoeformularios-btn-icone" data-acao="excluir-pergunta" data-id="' + p.id + '" title="Excluir"><i class="ti ti-trash"></i></button>'
                        + '</div>';
                }
                html += '</div>';
            });
            html += '</div></div>';
        });
        container.innerHTML = html;
    }

    function initDragDrop() {
        if (!podeEditar || !window.Sortable) { return; }
        var cont = document.getElementById('cat-secoes');
        if (!cont) { return; }

        Sortable.create(cont, {
            handle: '.catalogoeformularios-secao-arrasta',
            animation: 150,
            ghostClass: 'catalogoeformularios-sortable-ghost',
            chosenClass: 'catalogoeformularios-sortable-chosen',
            onEnd: function () {
                var ids = Array.prototype.map.call(cont.querySelectorAll('.catalogoeformularios-secao'), function (s) {
                    return s.getAttribute('data-secao');
                });
                ajax('reordenar_secoes', { form: estado.formId, ids: ids }).then(function (r) {
                    if (!r || !r.success) { toast((r && r.message) || 'Falha ao reordenar.', false); }
                });
            }
        });

        cont.querySelectorAll('.catalogoeformularios-secao-body').forEach(function (body) {
            Sortable.create(body, {
                group: 'catalogoeformularios-perguntas',
                handle: '.catalogoeformularios-pergunta-arrasta',
                animation: 150,
                ghostClass: 'catalogoeformularios-sortable-ghost',
                chosenClass: 'catalogoeformularios-sortable-chosen',
                onEnd: function (evt) {
                    var destino = evt.to.getAttribute('data-secao');
                    var origem  = evt.from.getAttribute('data-secao');
                    var idsDest = Array.prototype.map.call(evt.to.querySelectorAll('.catalogoeformularios-pergunta'), function (p) {
                        return p.getAttribute('data-pergunta');
                    });
                    var perg = evt.item.getAttribute('data-pergunta');
                    if (origem === destino) {
                        ajax('reordenar_perguntas', { secao: destino, ids: idsDest });
                    } else {
                        ajax('mover_pergunta', { id: perg, secao: destino, ids: idsDest }).then(function () {
                            var idsOrig = Array.prototype.map.call(evt.from.querySelectorAll('.catalogoeformularios-pergunta'), function (p) {
                                return p.getAttribute('data-pergunta');
                            });
                            ajax('reordenar_perguntas', { secao: origem, ids: idsOrig });
                        });
                    }
                }
            });
        });
    }

    // -----------------------------------------------------------------
    // Modal proprio + TinyMCE
    // -----------------------------------------------------------------
    function abrirModal(opts) {
        var richIds = [];
        var overlay = document.createElement('div');
        overlay.className = 'catalogoeformularios-modal-overlay';
        var modal = document.createElement('div');
        modal.className = 'catalogoeformularios-modal' + (opts.grande ? ' grande' : '');
        modal.innerHTML =
            '<div class="catalogoeformularios-modal-head"><div class="catalogoeformularios-modal-titulo">' + opts.titulo + '</div>'
          + '<button class="catalogoeformularios-modal-fechar" type="button">&times;</button></div>'
          + '<div class="catalogoeformularios-modal-body"></div>'
          + '<div class="catalogoeformularios-modal-foot"></div>';
        overlay.appendChild(modal);
        document.body.appendChild(overlay);

        var body = modal.querySelector('.catalogoeformularios-modal-body');
        var foot = modal.querySelector('.catalogoeformularios-modal-foot');

        function fechar() {
            richIds.forEach(function (id) {
                if (window.tinymce) { var ed = window.tinymce.get(id); if (ed) { ed.remove(); } }
            });
            overlay.remove();
        }
        modal.querySelector('.catalogoeformularios-modal-fechar').addEventListener('click', fechar);
        overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) { fechar(); } });

        function initRich(id, valor) {
            richIds.push(id);
            if (window.tinymce) {
                window.tinymce.init(configRich(id, valor));
            } else {
                var ta = document.getElementById(id); if (ta && valor) { ta.value = valor; }
            }
        }
        function lerRich(id) {
            if (window.tinymce) { var ed = window.tinymce.get(id); if (ed) { ed.save(); return ed.getContent(); } }
            var ta = document.getElementById(id); return ta ? ta.value : '';
        }

        return { overlay: overlay, modal: modal, body: body, foot: foot, fechar: fechar, initRich: initRich, lerRich: lerRich };
    }

    function botaoFoot(label, classe, onClick) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'catalogoeformularios-btn ' + (classe || '');
        b.innerHTML = label;
        b.addEventListener('click', onClick);
        return b;
    }

    function campoTexto(id, label, valor, obrig) {
        return '<div class="catalogoeformularios-campo"><label>' + esc(label) + (obrig ? ' <span class="obrig">*</span>' : '') + '</label>'
            + '<input type="text" id="' + id + '" class="catalogoeformularios-input" value="' + esc(valor || '') + '"></div>';
    }
    function campoRich(id, label) {
        return '<div class="catalogoeformularios-campo"><label>' + esc(label) + '</label>'
            + '<textarea id="' + id + '" class="catalogoeformularios-input" rows="4"></textarea></div>';
    }
    function campoSelect(id, label, opcoes, valorSel) {
        var html = '<div class="catalogoeformularios-campo"><label>' + esc(label) + '</label><select id="' + id + '" class="catalogoeformularios-select">';
        opcoes.forEach(function (o) {
            var sel = (String(o.v) === String(valorSel)) ? ' selected' : '';
            html += '<option value="' + esc(o.v) + '"' + sel + '>' + esc(o.t) + '</option>';
        });
        return html + '</select></div>';
    }
    function campoCheck(id, label, marcado) {
        return '<div class="catalogoeformularios-campo"><label class="catalogoeformularios-check">'
            + '<input type="checkbox" id="' + id + '"' + (marcado ? ' checked' : '') + '> ' + esc(label) + '</label></div>';
    }
    function blocoErro() { return '<div class="catalogoeformularios-erro" data-erro></div>'; }
    function mostrarErro(ref, msg) { var e = ref.body.querySelector('[data-erro]'); if (e) { e.textContent = msg || ''; } }

    // Select com busca (single). hidden#id guarda o valor.
    function campoSelectBusca(id, label, opcoes, valorSel) {
        var sel = null;
        opcoes.forEach(function (o) { if (String(o.v) === String(valorSel)) { sel = o; } });
        if (!sel && opcoes.length) { sel = opcoes[0]; }
        var valIni = sel ? sel.v : '';
        var txtIni = sel ? sel.t : 'Selecione...';
        var ops = '';
        opcoes.forEach(function (o) {
            var marc = (String(o.v) === String(valIni)) ? ' selecionada' : '';
            ops += '<div class="catalogoeformularios-selbusca-opcao' + marc + '" data-valor="' + esc(o.v) + '" data-label="' + esc(String(o.t).toLowerCase()) + '">' + esc(o.t) + '</div>';
        });
        return '<div class="catalogoeformularios-campo">' + (label ? '<label>' + esc(label) + '</label>' : '')
            + '<div class="catalogoeformularios-selbusca"><input type="hidden" id="' + id + '" value="' + esc(valIni) + '">'
            + '<div class="catalogoeformularios-selbusca-header"><span class="catalogoeformularios-selbusca-texto">' + esc(txtIni) + '</span><i class="ti ti-chevron-down"></i></div>'
            + '<div class="catalogoeformularios-selbusca-dropdown" style="display:none;"><input type="text" class="catalogoeformularios-selbusca-search catalogoeformularios-input" placeholder="Pesquisar...">'
            + '<div class="catalogoeformularios-selbusca-opcoes">' + ops + '</div></div></div></div>';
    }
    function ativarSelectBusca(container) {
        var hidden = container.querySelector('input[type="hidden"]');
        var header = container.querySelector('.catalogoeformularios-selbusca-header');
        var texto = container.querySelector('.catalogoeformularios-selbusca-texto');
        var dropdown = container.querySelector('.catalogoeformularios-selbusca-dropdown');
        var busca = container.querySelector('.catalogoeformularios-selbusca-search');
        var lista = container.querySelector('.catalogoeformularios-selbusca-opcoes');
        function filtrar(t) {
            t = normTexto(t);
            var visiveis = 0;
            lista.querySelectorAll('.catalogoeformularios-selbusca-opcao').forEach(function (op) {
                var rot = op.getAttribute('data-busca');
                if (rot === null) {
                    rot = normTexto(op.getAttribute('data-label') || op.textContent);
                    op.setAttribute('data-busca', rot);
                }
                var mostra = (t === '' || rot.indexOf(t) !== -1);
                op.style.display = mostra ? 'block' : 'none';
                if (mostra) { visiveis++; }
            });
            var aviso = container.querySelector('.catalogoeformularios-selbusca-nada');
            if (!aviso) {
                aviso = document.createElement('div');
                aviso.className = 'catalogoeformularios-selbusca-nada';
                aviso.textContent = 'Nenhum resultado.';
                lista.parentNode.appendChild(aviso);
            }
            aviso.style.display = visiveis ? 'none' : 'block';
        }
        function abrir() {
            document.querySelectorAll('.catalogoeformularios-selbusca-dropdown').forEach(function (d) { if (d !== dropdown) { d.style.display = 'none'; } });
            dropdown.style.display = 'block'; busca.value = ''; filtrar(''); busca.focus();
        }
        function fechar() { dropdown.style.display = 'none'; }
        header.addEventListener('click', function (e) { e.stopPropagation(); (dropdown.style.display === 'none') ? abrir() : fechar(); });
        busca.addEventListener('click', function (e) { e.stopPropagation(); });
        busca.addEventListener('input', function () { filtrar(busca.value); });
        lista.addEventListener('click', function (e) {
            var op = e.target.closest('.catalogoeformularios-selbusca-opcao');
            if (!op) { return; }
            hidden.value = op.getAttribute('data-valor');
            texto.textContent = op.textContent;
            lista.querySelectorAll('.catalogoeformularios-selbusca-opcao').forEach(function (o) { o.classList.remove('selecionada'); });
            op.classList.add('selecionada'); busca.value = ''; filtrar(''); fechar();
        });
    }

    // Multi-busca (checklist com busca). Retorna ids via coletarMultiBusca.
    function multiBusca(idBase, lista, selecionados) {
        selecionados = (selecionados || []).map(String);
        var html = '<div class="catalogoeformularios-mb" id="' + idBase + '">'
            + '<input type="text" class="catalogoeformularios-input catalogoeformularios-mb-busca" placeholder="Buscar...">'
            + '<div class="catalogoeformularios-mb-lista" style="max-height:160px;overflow:auto;border:1px solid #f0f0f0;border-radius:4px;margin-top:6px;">';
        lista.forEach(function (o) {
            var chk = (selecionados.indexOf(String(o.v)) !== -1) ? ' checked' : '';
            html += '<label class="catalogoeformularios-mb-item" data-label="' + esc(String(o.t).toLowerCase()) + '" style="display:flex;gap:8px;align-items:center;padding:4px 8px;font-size:12px;border-bottom:1px solid #f5f5f5;cursor:pointer;">'
                + '<input type="checkbox" value="' + esc(o.v) + '"' + chk + '> <span>' + esc(o.t) + '</span></label>';
        });
        return html + '</div></div>';
    }
    function preencherMultiBusca(container, itens, selecionados) {
        if (!container) { return; }
        var lista = container.querySelector('.catalogoeformularios-mb-lista');
        if (!lista) { return; }
        var sel = (selecionados || []).map(String);
        var html = '';
        (itens || []).forEach(function (o) {
            var chk = (sel.indexOf(String(o.v)) !== -1) ? ' checked' : '';
            html += '<label class="catalogoeformularios-mb-item" data-label="' + esc(String(o.t).toLowerCase()) + '" style="display:flex;gap:8px;align-items:center;padding:4px 8px;font-size:12px;border-bottom:1px solid #f5f5f5;cursor:pointer;">'
                + '<input type="checkbox" value="' + esc(o.v) + '"' + chk + '> <span>' + esc(o.t) + '</span></label>';
        });
        lista.innerHTML = html || '<div style="padding:8px;color:#999;font-size:12px;">Nenhum item.</div>';
    }

    function ativarMultiBusca(container) {
        var busca = container.querySelector('.catalogoeformularios-mb-busca');
        var lista = container.querySelector('.catalogoeformularios-mb-lista');
        if (!lista) { return; }
        // Reordena: selecionados primeiro, alfabetico dentro de cada grupo.
        function reordenar() {
            var itens = Array.prototype.slice.call(lista.querySelectorAll('.catalogoeformularios-mb-item'));
            itens.sort(function (a, b) {
                var ca = a.querySelector('input').checked ? 0 : 1;
                var cb = b.querySelector('input').checked ? 0 : 1;
                if (ca !== cb) { return ca - cb; }
                return (a.getAttribute('data-label') || '').localeCompare(b.getAttribute('data-label') || '');
            });
            itens.forEach(function (it) { lista.appendChild(it); });
        }
        if (busca) {
            busca.addEventListener('input', function () {
                var t = normTexto(busca.value);
                lista.querySelectorAll('.catalogoeformularios-mb-item').forEach(function (it) {
                    var rot = it.getAttribute('data-busca');
                    if (rot === null) {
                        rot = normTexto(it.getAttribute('data-label') || it.textContent);
                        it.setAttribute('data-busca', rot);
                    }
                    it.style.display = (t === '' || rot.indexOf(t) !== -1) ? 'flex' : 'none';
                });
            });
        }
        // Ao marcar/desmarcar: reordena e limpa a busca (mostra todos de novo).
        lista.addEventListener('change', function (e) {
            if (e.target && e.target.type === 'checkbox') {
                reordenar();
                if (busca && busca.value) {
                    busca.value = '';
                    lista.querySelectorAll('.catalogoeformularios-mb-item').forEach(function (it) { it.style.display = 'flex'; });
                }
            }
        });
        reordenar();
    }
    function coletarMultiBusca(container) {
        var ids = [];
        container.querySelectorAll('.catalogoeformularios-mb-item input:checked').forEach(function (c) { ids.push(c.value); });
        return ids;
    }

    function confirmar(mensagem, onSim, opcoes) {
        opcoes = opcoes || {};
        var rotulo = opcoes.rotulo || '<i class="ti ti-trash"></i> Excluir';
        var classe = opcoes.classe || 'catalogoeformularios-btn-perigo';
        var m = abrirModal({ titulo: '<i class="ti ti-alert-triangle"></i> Confirmar' });
        m.body.innerHTML = '<p style="font-size:13px;color:#495057;margin:0;">' + esc(mensagem) + '</p>';
        m.foot.appendChild(botaoFoot('Cancelar', '', m.fechar));
        m.foot.appendChild(botaoFoot(rotulo, classe, function () { m.fechar(); onSim(); }));
    }

    // -----------------------------------------------------------------
    // Modais: Categoria / Formulario / Secao / Pergunta
    // -----------------------------------------------------------------
    // Campo de escolha do icone: preview + busca + grade de ilustracoes.
    // O hidden #id guarda o id escolhido ('' = padrao do GLPI).
    function campoIlustracao(id, label, valorAtual) {
        return '<div class="catalogoeformularios-campo catalogoeformularios-ilus-campo" data-ilus-campo="' + id + '">'
            + '<label>' + esc(label) + '</label>'
            + '<input type="hidden" id="' + id + '" value="' + esc(valorAtual || '') + '">'
            + '<div class="catalogoeformularios-ilus-topo">'
            +   '<span class="catalogoeformularios-ilus-preview" data-ilus-preview>' + iconeCatHtml(valorAtual, 26) + '</span>'
            +   '<span class="catalogoeformularios-ilus-atual" data-ilus-atual>' + esc(valorAtual || 'Padrao do GLPI') + '</span>'
            +   '<button type="button" class="catalogoeformularios-btn" data-ilus-limpar title="Voltar ao icone padrao"><i class="ti ti-x"></i> Padrao</button>'
            + '</div>'
            + '<input type="text" class="catalogoeformularios-input catalogoeformularios-ilus-busca" data-ilus-busca placeholder="Buscar icone (ex.: computer, network, user)">'
            + '<div class="catalogoeformularios-ilus-grade" data-ilus-grade>' + carregando() + '</div>'
            + '<div class="catalogoeformularios-ilus-contagem" data-ilus-contagem></div>'
            + '</div>';
    }

    // Liga o comportamento do campo de icone (grade, busca em tempo real, selecao).
    function ativarSeletorIlustracao(escopo, id) {
        var campo = escopo.querySelector('[data-ilus-campo="' + id + '"]');
        if (!campo) { return; }

        var hidden   = campo.querySelector('#' + id);
        var grade    = campo.querySelector('[data-ilus-grade]');
        var busca    = campo.querySelector('[data-ilus-busca]');
        var preview  = campo.querySelector('[data-ilus-preview]');
        var atual    = campo.querySelector('[data-ilus-atual]');
        var contagem = campo.querySelector('[data-ilus-contagem]');
        var lista    = [];

        function refletir() {
            var v = hidden.value || '';
            preview.innerHTML = iconeCatHtml(v, 26);
            atual.textContent = v || 'Padrao do GLPI';
            grade.querySelectorAll('[data-ilus-id]').forEach(function (b) {
                b.classList.toggle('escolhido', b.getAttribute('data-ilus-id') === v);
            });
        }

        function pintar(filtro) {
            filtro = (filtro || '').toLowerCase();
            var visiveis = lista.filter(function (i) {
                return !filtro
                    || String(i.id).toLowerCase().indexOf(filtro) !== -1
                    || String(i.titulo || '').toLowerCase().indexOf(filtro) !== -1;
            });
            // A biblioteca do GLPI tem centenas de icones: renderiza um lote por vez.
            var corte = visiveis.slice(0, 240);
            var h = '';
            corte.forEach(function (i) {
                h += '<button type="button" class="catalogoeformularios-ilus-item" data-ilus-id="' + esc(i.id) + '"'
                   + ' title="' + esc(i.titulo || i.id) + '">' + iconeCatHtml(i.id, 24) + '</button>';
            });
            grade.innerHTML = h
                || '<div class="catalogoeformularios-ilus-vazio"><i class="ti ti-mood-empty"></i> Nenhum icone encontrado.</div>';
            contagem.textContent = visiveis.length
                ? (corte.length < visiveis.length
                    ? corte.length + ' de ' + visiveis.length + ' icones — refine a busca para ver os demais'
                    : visiveis.length + ' icone(s)')
                : '';
            refletir();
        }

        grade.addEventListener('click', function (e) {
            var b = e.target.closest('[data-ilus-id]');
            if (!b) { return; }
            e.preventDefault();
            hidden.value = b.getAttribute('data-ilus-id');
            refletir();
        });
        campo.querySelector('[data-ilus-limpar]').addEventListener('click', function (e) {
            e.preventDefault();
            hidden.value = '';
            refletir();
        });

        var tBusca = null;
        busca.addEventListener('input', function () {
            var v = this.value;
            clearTimeout(tBusca);
            tBusca = setTimeout(function () { pintar(v); }, 200);
        });

        carregarIlustracoes().then(function (l) {
            lista = l || [];
            if (!lista.length) {
                grade.innerHTML = '<div class="catalogoeformularios-ilus-vazio"><i class="ti ti-alert-triangle"></i> '
                    + 'Biblioteca de ilustracoes do GLPI nao encontrada nesta instalacao.</div>';
                contagem.textContent = '';
                return;
            }
            pintar('');
        });
    }

    // Abre o modal de edicao buscando os dados no servidor pelo ID. Funciona de
    // qualquer lugar: arvore lateral, acordeon de 1o nivel e subcategorias aninhadas.
    function abrirEdicaoCategoria(id) {
        id = parseInt(id, 10) || 0;
        if (!id) { return; }
        ajax('categoria_detalhe', { id: id }).then(function (r) {
            if (r && r.success && r.dados) { modalCategoria(r.dados); }
            else { toast((r && r.message) || 'Categoria nao encontrada.', false); }
        });
    }

    function modalCategoria(cat) {
        var editar = !!cat;
        var m = abrirModal({ titulo: '<i class="ti ti-folder"></i> ' + (editar ? 'Editar categoria' : 'Nova categoria') });
        var html = campoTexto('cat_nome', 'Nome', editar ? cat.nome : '', true);
        if (!editar) {
            var optPai = [{ v: 0, t: '(Raiz — nenhuma categoria pai)' }].concat(
                (CFG.categorias || []).map(function (c) { return { v: c.id, t: c.nome }; })
            );
            html += campoSelectBusca('cat_pai', 'Categoria pai', optPai, estado.categoria || 0);
        }
        html += campoIlustracao('cat_ilustracao', 'Icone (ilustracao nativa do GLPI)', editar ? (cat.ilustracao || '') : '');
        html += blocoErro();
        m.body.innerHTML = html;
        m.body.querySelectorAll('.catalogoeformularios-selbusca').forEach(ativarSelectBusca);
        ativarSeletorIlustracao(m.body, 'cat_ilustracao');
        m.foot.appendChild(botaoFoot('Cancelar', '', m.fechar));
        m.foot.appendChild(botaoFoot('<i class="ti ti-device-floppy"></i> Salvar', 'catalogoeformularios-btn-primario', function () {
            var nome = document.getElementById('cat_nome').value.trim();
            if (!nome) { mostrarErro(m, 'Informe o nome.'); return; }
            var elIlus = document.getElementById('cat_ilustracao');
            var params = { nome: nome, ilustracao: elIlus ? elIlus.value : '' };
            var acao;
            if (editar) {
                params.id = cat.id; acao = 'editar_categoria';
            } else {
                var paiEl = document.getElementById('cat_pai');
                params.pai = paiEl ? (parseInt(paiEl.value, 10) || 0) : (estado.categoria || 0);
                acao = 'criar_categoria';
            }
            ajax(acao, params).then(function (r) {
                if (r && r.success) {
                    m.fechar();
                    if (editar) {
                        toast(r.message, true);
                        recarregarAtual({ silencioso: true });
                    } else {
                        toast(r.message, true);
                        // Antes era location.reload(), que zerava toda a tela.
                        atualizarCategoriasCFG().then(function () {
                            recarregarAtual({ silencioso: true });
                        });
                    }
                } else { mostrarErro(m, (r && r.message) || 'Falha.'); }
            });
        }));
    }

    function modalEscolherTransformar() {
        var m = abrirModal({ titulo: '<i class="ti ti-transform"></i> Transformar' });
        m.body.innerHTML =
            '<p style="font-size:13px;color:#495057;margin:0 0 12px;">Escolha o que deseja transformar:</p>'
          + '<div style="display:flex;flex-direction:column;gap:8px;">'
          + '<button type="button" class="catalogoeformularios-btn" data-esc="cat" style="justify-content:flex-start;"><i class="ti ti-transform"></i> Entidades / Grupos em Categorias do Catalogo</button>'
          + '<button type="button" class="catalogoeformularios-btn" data-esc="itil" style="justify-content:flex-start;"><i class="ti ti-clipboard-list"></i> Categorias ITIL em formularios</button>'
          + '</div>';
        m.body.querySelector('[data-esc="cat"]').addEventListener('click', function () { m.fechar(); modalTransformarCategorias(); });
        m.body.querySelector('[data-esc="itil"]').addEventListener('click', function () { m.fechar(); modalTransformarItil(); });
        m.foot.appendChild(botaoFoot('Cancelar', '', m.fechar));
    }

    function modalTransformarCategorias() {
        var m = abrirModal({ titulo: '<i class="ti ti-transform"></i> Transformar Entidades / Grupos em Categorias' });
        m.body.innerHTML = carregando();
        ajax('opcoes_transformar_categorias', {}).then(function (r) {
            if (!r || !r.success) {
                m.body.innerHTML = '<div class="catalogoeformularios-erro">' + esc((r && r.message) || 'Falha ao carregar entidades e grupos.') + '</div>';
                return;
            }
            var ents = (r.dados.entidades || []).map(function (e) { return { v: e.id, t: e.nome }; });
            var grps = (r.dados.grupos || []).map(function (g) { return { v: g.id, t: g.nome }; });
            var html = '<p style="font-size:12px;color:#6c757d;margin:0 0 10px;"><i class="ti ti-info-circle"></i> As categorias serao criadas no nivel atual com o mesmo nome das entidades/grupos selecionados. Voce pode renomea-las depois.</p>';
            html += '<div class="catalogoeformularios-campo"><label><i class="ti ti-building"></i> Entidades</label>' + multiBusca('tc_entidades', ents, []) + '</div>';
            html += '<div class="catalogoeformularios-campo"><label><i class="ti ti-users-group"></i> Grupos</label>' + multiBusca('tc_grupos', grps, []) + '</div>';
            html += blocoErro();
            m.body.innerHTML = html;
            m.body.querySelectorAll('.catalogoeformularios-mb').forEach(ativarMultiBusca);
        });
        m.foot.appendChild(botaoFoot('Cancelar', '', m.fechar));
        m.foot.appendChild(botaoFoot('<i class="ti ti-device-floppy"></i> Confirmar', 'catalogoeformularios-btn-primario', function () {
            var cEnt = m.body.querySelector('#tc_entidades');
            var cGrp = m.body.querySelector('#tc_grupos');
            var ents = cEnt ? coletarMultiBusca(cEnt) : [];
            var grps = cGrp ? coletarMultiBusca(cGrp) : [];
            if (!ents.length && !grps.length) { mostrarErro(m, 'Selecione ao menos uma entidade ou grupo.'); return; }
            ajax('transformar_em_categorias', { entidades: ents, grupos: grps, pai: estado.categoria }).then(function (r) {
                if (r && r.success) {
                    m.fechar();
                    toast((r && r.message) || 'Concluido.', true);
                    atualizarCategoriasCFG().then(function () {
                        recarregarAtual({ silencioso: true });
                    });
                }
                else { mostrarErro(m, (r && r.message) || 'Falha.'); }
            });
        }));
    }

    function modalTransformarItil() {
        var m = abrirModal({ titulo: '<i class="ti ti-clipboard-list"></i> Transformar categorias ITIL em formularios' });
        m.body.innerHTML = carregando();
        Promise.all([
            ajax('fonte', { tipo: 'itilcategorias' }),
            ajax('categorias_catalogo', {})
        ]).then(function (res) {
            var rItil = res[0], rCat = res[1];
            if (!rItil || !rItil.success) {
                m.body.innerHTML = '<div class="catalogoeformularios-erro">' + esc((rItil && rItil.message) || 'Falha ao carregar categorias ITIL.') + '</div>';
                return;
            }
            var cats = (rItil.dados || []).map(function (c) { return { v: c.v, t: c.t }; });
            if (!cats.length) {
                m.body.innerHTML = '<div class="catalogoeformularios-erro">Nenhuma categoria ITIL encontrada.</div>';
                return;
            }
            var listaCat = (rCat && rCat.success && rCat.dados) ? rCat.dados : (CFG.categorias || []);
            var catalogoOpts = [{ v: 0, t: '(Sem vinculo / Raiz)' }].concat(
                listaCat.map(function (c) { return { v: c.id, t: c.nome }; })
            );

            var html = '<p style="font-size:12px;color:#6c757d;margin:0 0 10px;"><i class="ti ti-info-circle"></i> Para cada categoria ITIL selecionada sera criado um formulario com o mesmo nome, ja configurado para abrir o chamado naquela categoria. Voce pode renomear depois.</p>';
            html += '<div class="catalogoeformularios-campo"><label><i class="ti ti-list-check"></i> Categorias ITIL</label>' + multiBusca('ti_itil', cats, []) + '</div>';
            html += campoSelectBusca('ti_categoria_catalogo', 'Vincular a categoria do catalogo (opcional)', catalogoOpts, estado.categoria);
            html += blocoErro();
            m.body.innerHTML = html;
            m.body.querySelectorAll('.catalogoeformularios-mb').forEach(ativarMultiBusca);
            m.body.querySelectorAll('.catalogoeformularios-selbusca').forEach(ativarSelectBusca);
        });
        m.foot.appendChild(botaoFoot('Cancelar', '', m.fechar));
        m.foot.appendChild(botaoFoot('<i class="ti ti-device-floppy"></i> Confirmar', 'catalogoeformularios-btn-primario', function () {
            var cont = m.body.querySelector('#ti_itil');
            var ids = cont ? coletarMultiBusca(cont) : [];
            if (!ids.length) { mostrarErro(m, 'Selecione ao menos uma categoria ITIL.'); return; }
            var catEl = document.getElementById('ti_categoria_catalogo');
            var catalogoId = catEl ? (parseInt(catEl.value, 10) || 0) : 0;
            ajax('transformar_itil_em_formularios', { itil: ids, categoria: catalogoId }).then(function (r) {
                if (r && r.success) {
                    m.fechar();
                    toast((r && r.message) || 'Concluido.', true);
                    atualizarCategoriasCFG().then(function () {
                        recarregarAtual({ silencioso: true });
                    });
                }
                else { mostrarErro(m, (r && r.message) || 'Falha.'); }
            });
        }));
    }

    function modalFormulario(det, catInicial, opts) {
        opts = opts || {};
        var editar = !!det;
        var idDesc = 'form_desc_' + Date.now();
        var idHead = 'form_head_' + Date.now();
        var m = abrirModal({ titulo: '<i class="ti ti-file-text"></i> ' + (editar ? 'Editar formulario' : 'Novo formulario'), grande: true });
        var optCat = [{ v: 0, t: '(Raiz)' }].concat((CFG.categorias || []).map(function (c) { return { v: c.id, t: c.nome }; }));
        var catSel = editar ? det.categoria : ((catInicial !== undefined && catInicial !== null) ? catInicial : estado.categoria);

        var html = campoTexto('form_nome', 'Nome', editar ? det.nome : '', true);
        html += campoSelectBusca('form_categoria', 'Categoria do catalogo', optCat, catSel);
        html += campoIlustracao('form_ilustracao', 'Icone do formulario (ilustracao nativa do GLPI)', editar ? (det.ilustracao || '') : '');
        html += campoCheck('form_recursivo', 'Visivel nas subentidades (recursivo)', editar ? det.recursivo : true);
        if (!editar) { html += campoCheck('form_ativo', 'Ativar imediatamente', true); }

        html += blocoExpansivel('exp_catitil', 'ti ti-category', 'Categoria ITIL do chamado gerado',
            '<div id="form_cat_itil_wrap"><span style="font-size:12px;color:#999;">Carregando...</span></div>');
        html += blocoExpansivel('exp_obsgrupos', 'ti ti-eye', 'Grupos observadores do chamado gerado',
            multiBusca('form_obs_grupos', [], []));

        // Blocos expansiveis (recolhidos por padrao; expandem ao clicar no cabecalho).
        html += blocoExpansivel('exp_desc', 'ti ti-notes', 'Descricao (exibida no catalogo)', campoRich(idDesc, ''));
        html += blocoExpansivel('exp_head', 'ti ti-file-text', 'Cabecalho (exibido no topo do formulario)', campoRich(idHead, ''));
        html += blocoExpansivel('exp_ac', 'ti ti-shield', 'Controle de Acesso',
              '<p class="catalogoeformularios-ac-ajuda"><i class="ti ti-info-circle"></i> Somente os perfis, usuarios ou grupos selecionados poderao visualizar o formulario. Deixe tudo vazio para manter o padrao do GLPI (visivel a todos).</p>'
            + '<label class="catalogoeformularios-ac-todos-rot"><input type="checkbox" id="form_ac_todos"> Liberar para TODOS os usuarios autenticados</label>'
            + '<div id="form_ac_listas">'
            + '<label class="catalogoeformularios-ac-rotulo">Perfis</label>'   + multiBusca('form_ac_perfis', [], [])
            + '<label class="catalogoeformularios-ac-rotulo">Usuarios</label>' + multiBusca('form_ac_usuarios', [], [])
            + '<label class="catalogoeformularios-ac-rotulo">Grupos</label>'   + multiBusca('form_ac_grupos', [], [])
            + '</div>');

        html += blocoExpansivel('exp_secoes', 'ti ti-layout-list', 'Secoes e perguntas',
              '<div id="form_secoes_modal">'
            + (editar
                ? '<span style="font-size:12px;color:#999;">Expanda para carregar...</span>'
                : '<p class="catalogoeformularios-ac-ajuda"><i class="ti ti-info-circle"></i> Salve o formulario primeiro. Depois reabra em Editar para gerenciar as secoes e perguntas.</p>')
            + '</div>');

        html += blocoExpansivel('exp_campos', 'ti ti-clipboard-list', 'Campos do chamado gerado',
              '<div id="form_campos_destino">'
            + (editar
                ? '<span style="font-size:12px;color:#999;">Expanda para carregar...</span>'
                : '<p class="catalogoeformularios-ac-ajuda"><i class="ti ti-info-circle"></i> Salve o formulario primeiro. Depois reabra em Editar para configurar os campos do chamado gerado.</p>')
            + '</div>');

        html += blocoErro();
        m.body.innerHTML = html;
        m.body.querySelectorAll('.catalogoeformularios-selbusca').forEach(ativarSelectBusca);
        ativarSeletorIlustracao(m.body, 'form_ilustracao');

        var catItilSel = editar ? (det.categoria_itil || 0) : 0;
        var obsSel     = editar ? (det.observador_grupos || []) : [];
        carregarFonte('itilcategorias').then(function (lista) {
            var wrap = m.body.querySelector('#form_cat_itil_wrap');
            if (!wrap) { return; }
            var ops = [{ v: 0, t: '(Nenhuma)' }].concat((lista || []).map(function (c) { return { v: c.v, t: c.t }; }));
            wrap.innerHTML = campoSelectBusca('form_categoria_itil', '', ops, catItilSel);
            wrap.querySelectorAll('.catalogoeformularios-selbusca').forEach(ativarSelectBusca);
        });
        carregarFonte('atores').then(function (atores) {
            var cg = m.body.querySelector('#form_obs_grupos');
            if (!cg) { return; }
            preencherMultiBusca(cg, (atores && atores.grupos) || [], obsSel);
            ativarMultiBusca(cg);
            cg.setAttribute('data-carregado', '1');
        });

        // Estado de inicializacao tardia de cada bloco (so quando expandido pela 1a vez).
        var richIniciado = {};
        var acIniciado = false;
        var camposIniciado = false;
        var secoesIniciado = false;

        function inicializarAcesso() {
            if (acIniciado) { return; }
            acIniciado = true;
            var acTodos  = m.body.querySelector('#form_ac_todos');
            var acListas = m.body.querySelector('#form_ac_listas');
            acTodos.addEventListener('change', function () { acListas.style.display = acTodos.checked ? 'none' : 'block'; });

            var fontes = [carregarFonte('perfis'), carregarFonte('atores')];
            if (editar) { fontes.push(ajax('acesso_estado', { form: det.id })); }
            Promise.all(fontes).then(function (res) {
                var perfis = res[0] || [];
                var atores = res[1] || { usuarios: [], grupos: [] };
                var estLista = (editar && res[2] && res[2].success && res[2].dados) ? (res[2].dados.lista || {}) : {};
                var cPerfis = m.body.querySelector('#form_ac_perfis');
                var cUsers  = m.body.querySelector('#form_ac_usuarios');
                var cGrupos = m.body.querySelector('#form_ac_grupos');
                preencherMultiBusca(cPerfis, perfis, estLista.perfis || []);
                preencherMultiBusca(cUsers, atores.usuarios || [], estLista.usuarios || []);
                preencherMultiBusca(cGrupos, atores.grupos || [], estLista.grupos || []);
                ativarMultiBusca(cPerfis);
                ativarMultiBusca(cUsers);
                ativarMultiBusca(cGrupos);
                if (editar && estLista.todos) { acTodos.checked = true; acListas.style.display = 'none'; }
            });
        }

        // Liga o expandir/recolher dos tres blocos; inicializa o conteudo na 1a expansao.
        m.body.querySelectorAll('.catalogoeformularios-exp-cab').forEach(function (cab) {
            cab.addEventListener('click', function () {
                var bloco = cab.closest('.catalogoeformularios-exp');
                var corpo = bloco.querySelector('.catalogoeformularios-exp-corpo');
                var abrindo = corpo.style.display === 'none';
                corpo.style.display = abrindo ? 'block' : 'none';
                bloco.classList.toggle('aberto', abrindo);
                if (!abrindo) { return; }
                var alvo = bloco.getAttribute('data-exp');
                if (alvo === 'exp_desc' && !richIniciado[idDesc]) { richIniciado[idDesc] = true; m.initRich(idDesc, editar ? det.descricao : ''); }
                if (alvo === 'exp_head' && !richIniciado[idHead]) { richIniciado[idHead] = true; m.initRich(idHead, editar ? det.header : ''); }
                if (alvo === 'exp_ac') { inicializarAcesso(); }
                if (alvo === 'exp_campos' && !camposIniciado) {
                    camposIniciado = true;
                    if (editar) {
                        var corpoCampos = m.body.querySelector('#form_campos_destino');
                        corpoCampos.innerHTML = '<span style="font-size:12px;color:#999;">Carregando...</span>';
                        var urlNativoCampos = (CFG.editorNativoBase || '') + det.id;
                        ajax('destinos_listar', { form: det.id }).then(function (r) {
                            var lista = (r && r.success) ? (r.dados || []) : [];
                            if (!lista.length) {
                                corpoCampos.innerHTML = '<p class="catalogoeformularios-ac-ajuda"><i class="ti ti-info-circle"></i> Este formulario ainda nao possui um destino. Use "Destinos e acesso" para criar um destino primeiro.</p>';
                                return;
                            }
                            corpoCampos.innerHTML = '';
                            renderCamposDestino(corpoCampos, lista[0].id, urlNativoCampos);
                        });
                    }
                }
                if (alvo === 'exp_secoes' && !secoesIniciado) {
                    secoesIniciado = true;
                    if (editar) {
                        estado.formId = det.id;
                        estado.detalhe = det;
                        var contSec = m.body.querySelector('#form_secoes_modal');
                        estado._refreshModalBuilder = function () {
                            var c = document.getElementById('form_secoes_modal');
                            if (!c) { estado._refreshModalBuilder = null; return; }
                            renderModalSecoes(c, estado.detalhe || det);
                        };
                        renderModalSecoes(contSec, det);
                        contSec.addEventListener('click', function (e) {
                            var b = e.target.closest('[data-acao]');
                            if (!b) { return; }
                            e.stopPropagation();
                            var acaoSec = b.getAttribute('data-acao');
                            // Acoes que abrem um editor de texto rico (TinyMCE) nao renderizam
                            // bem em modal empilhado, entao fechamos este modal antes de abrir.
                            if (acaoSec === 'nova-secao' || acaoSec === 'editar-secao') {
                                estado.formId = det.id;
                                estado.detalhe = det;
                                m.fechar();
                            } else if (acaoSec === 'nova-pergunta' || acaoSec === 'editar-pergunta') {
                                estado.formId = det.id;
                                estado.detalhe = det;
                            }
                            if (acaoSec === 'nova-secao') { modalSecao(null); }
                            else { tratarAcaoItem(b); }
                        });
                    }
                }
            });
        });

        // Abre automaticamente um bloco especifico (ex.: vindo do menu "Editar secoes"/"Adicionar secoes").
        if (opts.expandir) {
            var blocoAlvo = m.body.querySelector('.catalogoeformularios-exp[data-exp="' + opts.expandir + '"]');
            if (blocoAlvo) {
                var cabAlvo = blocoAlvo.querySelector('.catalogoeformularios-exp-cab');
                var corpoAlvo = blocoAlvo.querySelector('.catalogoeformularios-exp-corpo');
                if (cabAlvo && corpoAlvo && corpoAlvo.style.display === 'none') { cabAlvo.click(); }
                requestAnimationFrame(function () {
                    try { blocoAlvo.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (err) { blocoAlvo.scrollIntoView(); }
                });
            }
        }

        m.foot.appendChild(botaoFoot('Cancelar', '', m.fechar));
        m.foot.appendChild(botaoFoot('<i class="ti ti-device-floppy"></i> Salvar', 'catalogoeformularios-btn-primario', function () {
            var nome = document.getElementById('form_nome').value.trim();
            if (!nome) { mostrarErro(m, 'Informe o nome.'); return; }
            var params = {
                nome: nome,
                categoria: document.getElementById('form_categoria').value,
                ilustracao: (document.getElementById('form_ilustracao') || {}).value || '',
                recursivo: document.getElementById('form_recursivo').checked ? 1 : 0,
                // So le o rich se o bloco foi aberto/inicializado (senao mantem vazio na criacao
                // ou o valor atual na edicao).
                descricao: richIniciado[idDesc] ? m.lerRich(idDesc) : (editar ? det.descricao : ''),
                header:    richIniciado[idHead] ? m.lerRich(idHead) : (editar ? det.header : '')
            };
            // Controle de acesso: so envia se o bloco foi aberto (caso contrario nao mexe).
            if (acIniciado) {
                var acTodosChk = document.getElementById('form_ac_todos');
                params.ac_todos    = acTodosChk && acTodosChk.checked ? 1 : 0;
                params.ac_perfis   = coletarMultiBusca(document.getElementById('form_ac_perfis'));
                params.ac_usuarios = coletarMultiBusca(document.getElementById('form_ac_usuarios'));
                params.ac_grupos   = coletarMultiBusca(document.getElementById('form_ac_grupos'));
            }
            var catItilEl = document.getElementById('form_categoria_itil');
            if (catItilEl) { params.categoria_itil = parseInt(catItilEl.value, 10) || 0; }
            var cgObs = document.getElementById('form_obs_grupos');
            if (cgObs && cgObs.getAttribute('data-carregado') === '1') {
                params.observador_grupos = coletarMultiBusca(cgObs);
                params.observador_grupos_set = 1;
            }
            var acao;
            if (editar) { params.id = det.id; acao = 'editar_formulario'; }
            else { params.ativo = document.getElementById('form_ativo').checked ? 1 : 0; acao = 'criar_formulario'; }
            ajax(acao, params).then(function (r) {
                if (r && r.success) {
                    m.fechar();
                    if (editar) {
                        toast(r.message, true);
                        recarregarAtual({ silencioso: true });
                    } else {
                        toast(r.message, true);
                        atualizarCategoriasCFG().then(function () {
                            recarregarAtual({ silencioso: true });
                        });
                    }
                } else { mostrarErro(m, (r && r.message) || 'Falha.'); }
            });
        }));
    }

    /** Bloco expansivel (cabecalho clicavel + corpo recolhido). */
    function blocoExpansivel(chave, icone, titulo, conteudoHtml) {
        return '<div class="catalogoeformularios-exp" data-exp="' + chave + '">'
             + '<div class="catalogoeformularios-exp-cab"><i class="' + icone + '"></i> <span>' + esc(titulo) + '</span>'
             + '<i class="ti ti-chevron-down catalogoeformularios-exp-seta"></i></div>'
             + '<div class="catalogoeformularios-exp-corpo" style="display:none;">' + conteudoHtml + '</div>'
             + '</div>';
    }

    function modalSecao(sec) {
        var editar = !!sec;
        var idDesc = 'sec_rich_' + Date.now();
        var m = abrirModal({ titulo: '<i class="ti ti-layout-list"></i> ' + (editar ? 'Editar secao' : 'Nova secao') });
        // Descricao em bloco fechado; o editor rico so e criado ao abrir
        m.body.innerHTML = campoTexto('sec_nome', 'Nome', editar ? sec.nome : '', true)
            + blocoExpansivel('exp_sec_desc', 'ti ti-notes', 'Descricao da secao', campoRich(idDesc, '')) + blocoErro();
        var richSecIniciado = false;
        var taSec = document.getElementById(idDesc);
        if (taSec && editar) { taSec.value = sec.descricao || ''; }
        m.body.querySelector('.catalogoeformularios-exp-cab').addEventListener('click', function () {
            var bloco = this.closest('.catalogoeformularios-exp');
            var corpo = bloco.querySelector('.catalogoeformularios-exp-corpo');
            var abrindo = corpo.style.display === 'none';
            corpo.style.display = abrindo ? 'block' : 'none';
            bloco.classList.toggle('aberto', abrindo);
            if (abrindo && !richSecIniciado) { richSecIniciado = true; m.initRich(idDesc, editar ? sec.descricao : ''); }
        });
        m.foot.appendChild(botaoFoot('Cancelar', '', m.fechar));
        m.foot.appendChild(botaoFoot('<i class="ti ti-device-floppy"></i> Salvar', 'catalogoeformularios-btn-primario', function () {
            var nome = document.getElementById('sec_nome').value.trim();
            if (!nome) { mostrarErro(m, 'Informe o nome.'); return; }
            var params = { nome: nome, descricao: m.lerRich(idDesc) };
            var acao;
            if (editar) { params.id = sec.id; acao = 'editar_secao'; } else { params.form = estado.formId; acao = 'criar_secao'; }
            ajax(acao, params).then(function (r) {
                if (r && r.success) { m.fechar(); toast(r.message, true); carregarBuilder(estado.formId); } else { mostrarErro(m, (r && r.message) || 'Falha.'); }
            });
        }));
    }

    // Editor "por alvo": liga/desliga a visibilidade condicional de UMA pergunta,
    // escolhendo origem (outra pergunta) + operador + valor. Convive com o modelo
    // "por opcao" (ambos gravam no mesmo armazenamento nativo).
    function montarVisibilidadeCondicional(container, perg) {
        if (!container) { return null; }
        var formId = estado.formId;
        var origens = [];      // perguntas que podem ser origem
        var regras = [];       // [{origem_uuid, operador, valor, logica}]
        var estrategia = 'sempre';
        var carregado = false;

        container.innerHTML = '<div class="catalogoeformularios-campo"><label><i class="ti ti-git-branch"></i> Visibilidade condicional</label>'
            + '<div class="catalogoeformularios-vc-vazio"><i class="ti ti-loader"></i> Carregando...</div></div>';

        function opsOrigem() {
            return origens.filter(function (p) {
                return String(p.uuid) !== String(perg.uuid) && p.id !== perg.id && p.uuid;
            });
        }
        function operadoresDe(tipoSlug) {
            var escolha = { lista: 1, multipla: 1, unica: 1, caixas: 1 };
            if (escolha[tipoSlug]) {
                return [{ slug: 'equals', label: 'e igual a' }, { slug: 'not_equals', label: 'e diferente de' }];
            }
            return [
                { slug: 'equals', label: 'e igual a' }, { slug: 'not_equals', label: 'e diferente de' },
                { slug: 'contains', label: 'contem' }, { slug: 'not_contains', label: 'nao contem' },
                { slug: 'empty', label: 'esta vazio' }, { slug: 'not_empty', label: 'esta preenchido' }
            ];
        }
        function origemPorUuid(uuid) {
            var f = origens.filter(function (p) { return String(p.uuid) === String(uuid); });
            return f.length ? f[0] : null;
        }

        function render() {
            var h = '<div class="catalogoeformularios-campo"><label><i class="ti ti-git-branch"></i> Visibilidade condicional</label>';
            h += '<select class="catalogoeformularios-select" data-vc-estrategia style="max-width:220px;">'
               + '<option value="sempre">Sempre visivel</option>'
               + '<option value="visivel_se">Visivel se...</option>'
               + '<option value="oculto_se">Oculto se...</option></select>';
            h += '<div data-vc-corpo style="margin-top:8px;"></div>';
            h += '</div>';
            container.innerHTML = h;
            container.querySelector('[data-vc-estrategia]').value = estrategia;
            container.querySelector('[data-vc-estrategia]').addEventListener('change', function () {
                estrategia = this.value;
                if (estrategia !== 'sempre' && !regras.length) { regras.push({ origem_uuid: '', operador: 'equals', valor: '', logica: 'or' }); }
                renderCorpo();
            });
            renderCorpo();
        }

        function renderCorpo() {
            var corpo = container.querySelector('[data-vc-corpo]');
            if (!corpo) { return; }
            if (estrategia === 'sempre') { corpo.innerHTML = ''; return; }
            corpo.innerHTML = '<div data-vc-regras></div>'
                + '<button type="button" class="catalogoeformularios-btn" data-vc-add style="margin-top:6px;"><i class="ti ti-plus"></i> Adicionar condicao</button>';
            var lista = corpo.querySelector('[data-vc-regras]');
            regras.forEach(function (rg) { lista.appendChild(linhaRegra(rg)); });
            corpo.querySelector('[data-vc-add]').addEventListener('click', function () {
                var rg = { origem_uuid: '', operador: 'equals', valor: '', logica: 'or' };
                regras.push(rg);
                lista.appendChild(linhaRegra(rg));
            });
        }

        function linhaRegra(rg) {
            var div = document.createElement('div');
            div.className = 'catalogoeformularios-vc-regra';
            var ops = opsOrigem();
            var selOrig = '<select class="catalogoeformularios-select" data-vc-origem><option value="">Pergunta...</option>'
                + ops.map(function (p) { return '<option value="' + esc(p.uuid) + '" data-tipo="' + esc(p.tipo_slug) + '">' + esc(p.nome) + '</option>'; }).join('') + '</select>';
            div.innerHTML = (regras.indexOf(rg) > 0
                    ? '<select class="catalogoeformularios-select catalogoeformularios-vc-logica" data-vc-logica><option value="or">OU</option><option value="and">E</option></select>'
                    : '<span class="catalogoeformularios-vc-se">Se</span>')
                + selOrig
                + '<select class="catalogoeformularios-select" data-vc-oper></select>'
                + '<span data-vc-valor-wrap></span>'
                + '<button type="button" class="catalogoeformularios-btn-icone" data-vc-rem><i class="ti ti-x"></i></button>';

            var selOrigem = div.querySelector('[data-vc-origem]');
            var selOper   = div.querySelector('[data-vc-oper]');
            var valorWrap = div.querySelector('[data-vc-valor-wrap]');
            var selLog    = div.querySelector('[data-vc-logica]');
            if (selLog) { selLog.value = rg.logica || 'or'; selLog.addEventListener('change', function () { rg.logica = selLog.value; }); }
            selOrigem.value = rg.origem_uuid || '';

            function popularOper() {
                var org = origemPorUuid(selOrigem.value);
                var tipo = org ? org.tipo_slug : '';
                var lista = operadoresDe(tipo);
                selOper.innerHTML = lista.map(function (o) { return '<option value="' + o.slug + '">' + esc(o.label) + '</option>'; }).join('');
                if (lista.filter(function (o) { return o.slug === rg.operador; }).length) { selOper.value = rg.operador; }
                else { rg.operador = lista[0] ? lista[0].slug : 'equals'; selOper.value = rg.operador; }
            }
            function popularValor() {
                var semValor = (rg.operador === 'empty' || rg.operador === 'not_empty');
                var org = origemPorUuid(selOrigem.value);
                if (semValor) {
                    valorWrap.innerHTML = '<input type="text" class="catalogoeformularios-input" disabled placeholder="(sem valor)" style="max-width:150px;">';
                    return;
                }
                if (org && org.opcoes && org.opcoes_valores && org.opcoes_valores.length) {
                    // origem de escolha: valor = chave da opcao (1..N)
                    var opts = org.opcoes_valores.map(function (txt, i) {
                        return '<option value="' + (i + 1) + '">' + esc(txt) + '</option>';
                    }).join('');
                    valorWrap.innerHTML = '<select class="catalogoeformularios-select" data-vc-valor style="max-width:180px;"><option value="">Opcao...</option>' + opts + '</select>';
                } else {
                    valorWrap.innerHTML = '<input type="text" class="catalogoeformularios-input" data-vc-valor placeholder="valor" style="max-width:180px;">';
                }
                var campo = valorWrap.querySelector('[data-vc-valor]');
                if (campo) {
                    campo.value = rg.valor || '';
                    campo.addEventListener('input', function () { rg.valor = campo.value; });
                    campo.addEventListener('change', function () { rg.valor = campo.value; });
                }
            }

            popularOper();
            popularValor();
            selOrigem.addEventListener('change', function () {
                rg.origem_uuid = selOrigem.value; rg.valor = '';
                popularOper(); rg.operador = selOper.value; popularValor();
            });
            selOper.addEventListener('change', function () { rg.operador = selOper.value; popularValor(); });
            div.querySelector('[data-vc-rem]').addEventListener('click', function () {
                var i = regras.indexOf(rg); if (i >= 0) { regras.splice(i, 1); }
                div.remove();
                if (!regras.length) { estrategia = 'sempre'; render(); }
            });
            return div;
        }

        // Carrega origens + a regra atual desta pergunta.
        Promise.all([
            ajax('alvos_condicionais', { form: formId }),
            ajax('condicao_get', { pergunta: perg.id })
        ]).then(function (res) {
            origens = (res[0] && res[0].success && res[0].dados) ? (res[0].dados.perguntas || []) : [];
            var reg = (res[1] && res[1].success && res[1].dados) ? res[1].dados : { estrategia: 'sempre', condicoes: [] };
            estrategia = reg.estrategia || 'sempre';
            (reg.condicoes || []).forEach(function (c) {
                var uuid = c.item_uuid || (c.item ? String(c.item).replace(/^question-/, '') : '');
                regras.push({
                    origem_uuid: uuid,
                    operador: c.value_operator || 'equals',
                    valor: (c.value !== undefined && c.value !== null) ? String(c.value) : '',
                    logica: c.logic_operator || 'or'
                });
            });
            carregado = true;
            render();
        });

        return {
            salvar: function (done) {
                done = done || function () {};
                if (!carregado) { done(); return; }
                if (estrategia === 'sempre') {
                    ajax('condicao_definir', { pergunta: perg.id, estrategia: 'sempre', condicoes_json: '[]' }).then(function () { done(); });
                    return;
                }
                var validas = regras.filter(function (r) { return r.origem_uuid && r.operador; })
                    .map(function (r) {
                        return { pergunta_uuid: r.origem_uuid, operador: r.operador, valor: r.valor || '', logica: r.logica || 'or' };
                    });
                if (!validas.length) {
                    ajax('condicao_definir', { pergunta: perg.id, estrategia: 'sempre', condicoes_json: '[]' }).then(function () { done(); });
                    return;
                }
                ajax('condicao_definir', {
                    pergunta: perg.id, estrategia: estrategia, condicoes_json: JSON.stringify(validas)
                }).then(function (r) {
                    if (r && !r.success && r.message) { toast('Visibilidade: ' + r.message, false); }
                    done();
                });
            }
        };
    }

    function modalPergunta(secaoId, perg) {
        var editar = !!perg;
        var idDesc = 'perg_rich_' + Date.now();
        var m = abrirModal({ titulo: '<i class="ti ti-help-circle"></i> ' + (editar ? 'Editar pergunta' : 'Nova pergunta') });
        var optTipos = (CFG.tipos || []).map(function (t) { return { v: t.slug, t: t.label }; });
        var tipoSel = editar ? perg.tipo_slug : (optTipos[0] ? optTipos[0].v : 'texto');

        // Condicionais por opcao: so no modo edicao (a condicao referencia o uuid,
        // que so existe apos a pergunta ser salva) e com permissao de edicao.
        var formIdAtual   = estado.formId;
        var podeCond      = editar && perg && perg.uuid && (typeof podeEditar === 'undefined' ? true : podeEditar);
        var alvosCond     = null;
        var alvosCarregados = false;

        var html = campoTexto('perg_nome', 'Nome da pergunta', editar ? perg.nome : '', true)
            + campoSelect('perg_tipo', 'Tipo', optTipos, tipoSel)
            + campoCheck('perg_obrig', 'Obrigatoria', editar ? perg.obrigatoria : false)
            + '<div class="catalogoeformularios-campo" id="perg_opcoes_wrap" style="display:none;"><label>Opcoes</label>'
            + '<div class="catalogoeformularios-opcoes" id="perg_opcoes"></div>'
            + '<button type="button" class="catalogoeformularios-btn" id="perg_add_opcao" style="margin-top:6px;"><i class="ti ti-plus"></i> Adicionar opcao</button></div>'
            + '<div class="catalogoeformularios-campo"><label>Pre-visualizacao</label><div id="perg_preview" class="catalogoeformularios-preview"></div></div>'
            + '<div id="perg_visib_cond"></div>'
            + blocoErro();
        m.body.innerHTML = html;
        var wrap = document.getElementById('perg_opcoes_wrap');
        var lista = document.getElementById('perg_opcoes');

        function ehEscolha() {
            var tipo = document.getElementById('perg_tipo').value;
            return !!(TIPOS[tipo] && TIPOS[tipo].opcoes);
        }

        // Alvos disponiveis (perguntas/secoes/destinos) por tipo, excluindo a propria pergunta.
        function listaAlvos(tipo) {
            if (!alvosCond) { return []; }
            if (tipo === 'secao') {
                return (alvosCond.secoes || []).map(function (s) { return { id: s.id, uuid: s.uuid, nome: s.nome }; });
            }
            if (tipo === 'destino') {
                return (alvosCond.destinos || []).map(function (d) {
                    return { id: d.id, uuid: '', nome: d.nome + (d.tipo_label ? ' (' + d.tipo_label + ')' : '') };
                });
            }
            return (alvosCond.perguntas || []).filter(function (p) {
                return String(p.uuid) !== String(perg.uuid) && p.id !== perg.id;
            }).map(function (p) { return { id: p.id, uuid: p.uuid, nome: p.nome }; });
        }

        function linhaRegra(row, rg) {
            var div = document.createElement('div');
            div.className = 'catalogoeformularios-cond-regra';
            div.innerHTML =
                '<select class="catalogoeformularios-select" data-r-acao><option value="mostrar">Mostrar</option><option value="ocultar">Ocultar</option></select>'
              + '<select class="catalogoeformularios-select" data-r-tipo><option value="pergunta">Pergunta</option><option value="secao">Secao</option><option value="destino">Destino</option></select>'
              + '<select class="catalogoeformularios-select" data-r-alvo></select>'
              + '<button type="button" class="catalogoeformularios-btn-icone" data-r-rem><i class="ti ti-x"></i></button>';
            var selAcao = div.querySelector('[data-r-acao]');
            var selTipo = div.querySelector('[data-r-tipo]');
            var selAlvo = div.querySelector('[data-r-alvo]');
            selAcao.value = rg.acao || 'mostrar';
            selTipo.value = rg.alvo_tipo || 'pergunta';
            function popularAlvo() {
                var lst = listaAlvos(selTipo.value);
                selAlvo.innerHTML = '<option value="">Selecione...</option>' + lst.map(function (a) {
                    return '<option value="' + a.id + '" data-uuid="' + esc(a.uuid || '') + '">' + esc(a.nome) + '</option>';
                }).join('');
                selAlvo.value = rg.alvo_id ? String(rg.alvo_id) : '';
            }
            popularAlvo();
            selAcao.addEventListener('change', function () { rg.acao = selAcao.value; });
            selTipo.addEventListener('change', function () { rg.alvo_tipo = selTipo.value; rg.alvo_id = 0; rg.alvo_uuid = ''; popularAlvo(); });
            selAlvo.addEventListener('change', function () {
                rg.alvo_id = parseInt(selAlvo.value, 10) || 0;
                var opt = selAlvo.options[selAlvo.selectedIndex];
                rg.alvo_uuid = opt ? (opt.getAttribute('data-uuid') || '') : '';
            });
            div.querySelector('[data-r-rem]').addEventListener('click', function () {
                var i = row._condRules.indexOf(rg);
                if (i >= 0) { row._condRules.splice(i, 1); }
                div.remove();
            });
            return div;
        }

        function renderRegras(row) {
            var painel = row._condPanel;
            if (!painel) { return; }
            if (!alvosCarregados) { painel.innerHTML = '<div class="catalogoeformularios-cond-vazio"><i class="ti ti-loader"></i> Carregando alvos...</div>'; return; }
            painel.innerHTML = '<div class="catalogoeformularios-cond-titulo">Quando esta opcao for escolhida:</div>'
                + '<div class="catalogoeformularios-cond-regras"></div>'
                + '<button type="button" class="catalogoeformularios-btn" data-add-regra style="margin-top:6px;"><i class="ti ti-plus"></i> Adicionar acao</button>';
            var cont = painel.querySelector('.catalogoeformularios-cond-regras');
            row._condRules.forEach(function (rg) { cont.appendChild(linhaRegra(row, rg)); });
            painel.querySelector('[data-add-regra]').addEventListener('click', function () {
                var rg = { acao: 'mostrar', alvo_tipo: 'pergunta', alvo_id: 0, alvo_uuid: '' };
                row._condRules.push(rg);
                cont.appendChild(linhaRegra(row, rg));
            });
        }

        function addOpcao(valor, regras) {
            var row = document.createElement('div');
            row.className = 'catalogoeformularios-opcao-bloco';
            row._condRules = Array.isArray(regras) ? regras : [];
            var linha = document.createElement('div');
            linha.className = 'catalogoeformularios-opcao';
            linha.innerHTML = '<input type="text" class="catalogoeformularios-input catalogoeformularios-opcao-input" value="' + esc(valor || '') + '">'
                + (podeCond ? '<button type="button" class="catalogoeformularios-btn-icone" data-cond-toggle title="Condicionais desta opcao"><i class="ti ti-git-branch"></i></button>' : '')
                + '<button type="button" class="catalogoeformularios-btn-icone" data-rem-opcao><i class="ti ti-x"></i></button>';
            row.appendChild(linha);
            if (podeCond) {
                var painel = document.createElement('div');
                painel.className = 'catalogoeformularios-cond-panel';
                painel.style.display = 'none';
                row.appendChild(painel);
                row._condPanel = painel;
                linha.querySelector('[data-cond-toggle]').addEventListener('click', function () {
                    var vis = painel.style.display === 'none';
                    painel.style.display = vis ? 'block' : 'none';
                    if (vis) { renderRegras(row); }
                });
            }
            linha.querySelector('[data-rem-opcao]').addEventListener('click', function () { row.remove(); renderPreview(); });
            lista.appendChild(row);
            renderPreview();
        }
        function coletarOpcoes() {
            var vals = [];
            lista.querySelectorAll('.catalogoeformularios-opcao-input').forEach(function (i) { if (i.value.trim() !== '') { vals.push(i.value.trim()); } });
            return vals;
        }
        // Regras condicionais no formato do backend: chave da opcao = posicao (1..N)
        // entre as opcoes nao-vazias, na mesma ordem em que sao salvas.
        function coletarRegrasCondicionais() {
            var out = [];
            var k = 0;
            lista.querySelectorAll('.catalogoeformularios-opcao-bloco').forEach(function (row) {
                var inp = row.querySelector('.catalogoeformularios-opcao-input');
                if (!inp || inp.value.trim() === '') { return; }
                k++;
                (row._condRules || []).forEach(function (rg) {
                    if (!rg.alvo_id) { return; }
                    out.push({ opcao: String(k), acao: rg.acao || 'mostrar', alvo_tipo: rg.alvo_tipo, alvo_id: rg.alvo_id, alvo_uuid: rg.alvo_uuid || '' });
                });
            });
            return out;
        }
        function renderPreview() {
            var prev = document.getElementById('perg_preview');
            if (!prev) { return; }
            var tipo = document.getElementById('perg_tipo').value;
            var def = TIPOS[tipo] || {};
            var ops = (def.opcoes) ? coletarOpcoes() : [];
            if (def.opcoes && !ops.length) { ops = ['Opcao 1', 'Opcao 2']; }
            var h = '';
            switch (tipo) {
                case 'texto':          h = '<input type="text" class="catalogoeformularios-input" placeholder="Resposta curta" disabled>'; break;
                case 'textolongo':     h = '<textarea class="catalogoeformularios-input" rows="3" placeholder="Texto com formatacao (negrito, listas, imagens...)" disabled></textarea>'; break;
                case 'numero':         h = '<input type="number" class="catalogoeformularios-input" placeholder="0" disabled>'; break;
                case 'email':          h = '<input type="email" class="catalogoeformularios-input" placeholder="nome@exemplo.com" disabled>'; break;
                case 'data':           h = '<input type="date" class="catalogoeformularios-input" disabled>'; break;
                case 'hora':           h = '<input type="time" class="catalogoeformularios-input" disabled>'; break;
                case 'datahora':       h = '<input type="datetime-local" class="catalogoeformularios-input" disabled>'; break;
                case 'lista':          h = '<select class="catalogoeformularios-select" disabled><option>Selecione...</option>' + ops.map(function (o) { return '<option>' + esc(o) + '</option>'; }).join('') + '</select>'; break;
                case 'multipla':       h = '<select class="catalogoeformularios-select" multiple disabled style="min-height:90px;">' + ops.map(function (o) { return '<option>' + esc(o) + '</option>'; }).join('') + '</select>'; break;
                case 'unica':          h = ops.map(function (o, i) { return '<label style="display:block;font-size:13px;margin:3px 0;"><input type="radio" name="perg_prev_radio" disabled' + (i === 0 ? ' checked' : '') + '> ' + esc(o) + '</label>'; }).join(''); break;
                case 'caixas':         h = ops.map(function (o) { return '<label style="display:block;font-size:13px;margin:3px 0;"><input type="checkbox" disabled> ' + esc(o) + '</label>'; }).join(''); break;
                case 'arquivo':        h = '<input type="file" disabled>'; break;
                case 'urgencia':       h = '<select class="catalogoeformularios-select" disabled><option>Muito baixa</option><option>Baixa</option><option selected>Media</option><option>Alta</option><option>Muito alta</option></select>'; break;
                case 'tiporequisicao': h = '<select class="catalogoeformularios-select" disabled><option>Incidente</option><option>Requisicao</option></select>'; break;
                case 'item':           h = '<select class="catalogoeformularios-select" disabled><option>Selecione um item do GLPI...</option></select>'; break;
                default:               h = '<input type="text" class="catalogoeformularios-input" placeholder="Resposta" disabled>';
            }
            prev.innerHTML = h;
        }
        function atualizarOpcoesUI() {
            var tipo = document.getElementById('perg_tipo').value;
            wrap.style.display = (TIPOS[tipo] && TIPOS[tipo].opcoes) ? 'block' : 'none';
            renderPreview();
        }

        // Carrega alvos + condicionais ja existentes e distribui por opcao (posicao).
        function carregarCondicionais() {
            if (!podeCond) { return; }
            Promise.all([
                ajax('alvos_condicionais', { form: formIdAtual }),
                ajax('ler_condicoes_opcao', { form: formIdAtual, origem_uuid: perg.uuid })
            ]).then(function (res) {
                alvosCond = (res[0] && res[0].success) ? res[0].dados : { perguntas: [], secoes: [], destinos: [] };
                alvosCarregados = true;
                var regras = (res[1] && res[1].success && Array.isArray(res[1].dados)) ? res[1].dados : [];
                var rows = lista.querySelectorAll('.catalogoeformularios-opcao-bloco');
                regras.forEach(function (rg) {
                    var k = parseInt(rg.opcao, 10);
                    if (k >= 1 && rows[k - 1]) {
                        rows[k - 1]._condRules.push({ acao: rg.acao, alvo_tipo: rg.alvo_tipo, alvo_id: rg.alvo_id, alvo_uuid: rg.alvo_uuid });
                    }
                });
            });
        }

        document.getElementById('perg_add_opcao').addEventListener('click', function () { addOpcao('', []); });
        document.getElementById('perg_tipo').addEventListener('change', atualizarOpcoesUI);
        lista.addEventListener('input', renderPreview);
        if (editar && perg.opcoes_valores && perg.opcoes_valores.length) { perg.opcoes_valores.forEach(function (v) { addOpcao(v, []); }); } else { addOpcao('', []); }
        atualizarOpcoesUI();
        if (podeCond && ehEscolha()) { carregarCondicionais(); }
        var visibCond = (editar && perg && perg.uuid) ? montarVisibilidadeCondicional(document.getElementById('perg_visib_cond'), perg) : null;

        m.foot.appendChild(botaoFoot('Cancelar', '', m.fechar));
        m.foot.appendChild(botaoFoot('<i class="ti ti-device-floppy"></i> Salvar', 'catalogoeformularios-btn-primario', function () {
            var nome = document.getElementById('perg_nome').value.trim();
            var tipo = document.getElementById('perg_tipo').value;
            if (!nome) { mostrarErro(m, 'Informe o nome.'); return; }
            var params = {
                nome: nome, tipo: tipo, obrigatoria: document.getElementById('perg_obrig').checked ? 1 : 0,
                descricao: editar ? (perg.descricao || '') : '', opcoes: (TIPOS[tipo] && TIPOS[tipo].opcoes) ? coletarOpcoes() : []
            };
            if (TIPOS[tipo] && TIPOS[tipo].opcoes && params.opcoes.length === 0) { mostrarErro(m, 'Informe ao menos uma opcao.'); return; }
            var acao;
            if (editar) { params.id = perg.id; acao = 'editar_pergunta'; } else { params.secao = secaoId; acao = 'criar_pergunta'; }
            ajax(acao, params).then(function (r) {
                if (!(r && r.success)) { mostrarErro(m, (r && r.message) || 'Falha.'); return; }
                // Apos salvar a pergunta, persiste as condicionais por opcao (so no modo edicao).
                if (podeCond) {
                    var regras = (TIPOS[tipo] && TIPOS[tipo].opcoes) ? coletarRegrasCondicionais() : [];
                    ajax('salvar_condicoes_opcao', {
                        form: formIdAtual, origem_uuid: perg.uuid, regras_json: JSON.stringify(regras)
                    }).then(function (rc) {
                        if (visibCond) { visibCond.salvar(function () {}); }
                        m.fechar();
                        toast(r.message, true);
                        if (rc && !rc.success && rc.message) { toast('Condicionais: ' + rc.message, false); }
                        carregarBuilder(estado.formId);
                    });
                } else {
                    if (visibCond) {
                        visibCond.salvar(function () { m.fechar(); toast(r.message, true); carregarBuilder(estado.formId); });
                    } else {
                        m.fechar(); toast(r.message, true); carregarBuilder(estado.formId);
                    }
                }
            });
        }));
    }

    // -----------------------------------------------------------------
    // Modal: Configuracao do formulario (Destinos + Controle de acesso)
    // -----------------------------------------------------------------
    function modalConfigFormulario(formId, formNome, opts) {
        opts = opts || {};
        var m = abrirModal({ titulo: '<i class="ti ti-settings"></i> Configuracao: ' + esc(formNome || ''), grande: true });
        var urlNativo = (CFG.editorNativoBase || '') + formId;
        m.body.innerHTML =
            '<div class="catalogoeformularios-abas">'
          + '<button class="catalogoeformularios-aba ativa" data-aba="destinos"><i class="ti ti-target"></i> Destinos</button>'
          + '<button class="catalogoeformularios-aba" data-aba="acesso"><i class="ti ti-shield"></i> Controle de acesso</button>'
          + '</div>'
          + '<div class="catalogoeformularios-aba-conteudo ativa" data-painel="destinos">' + carregando() + '</div>'
          + '<div class="catalogoeformularios-aba-conteudo" data-painel="acesso">' + carregando() + '</div>';

        m.body.querySelectorAll('.catalogoeformularios-aba').forEach(function (ab) {
            ab.addEventListener('click', function () {
                var alvo = ab.getAttribute('data-aba');
                m.body.querySelectorAll('.catalogoeformularios-aba').forEach(function (x) { x.classList.toggle('ativa', x === ab); });
                m.body.querySelectorAll('.catalogoeformularios-aba-conteudo').forEach(function (p) {
                    p.classList.toggle('ativa', p.getAttribute('data-painel') === alvo);
                });
            });
        });

        m.foot.appendChild(botaoFoot('<i class="ti ti-external-link"></i> Editor nativo', '', function () { window.open(urlNativo, '_blank'); }));
        m.foot.appendChild(botaoFoot('Fechar', '', m.fechar));

        renderAbaDestinos(formId, m.body.querySelector('[data-painel="destinos"]'), urlNativo, opts.autoCampos);
        renderAbaAcesso(formId, m.body.querySelector('[data-painel="acesso"]'));

        // Abre direto numa aba especifica (ex.: "Editar destinos" / "Controle de acesso" do menu).
        if (opts.abaInicial) {
            var abaAlvo = m.body.querySelector('.catalogoeformularios-aba[data-aba="' + opts.abaInicial + '"]');
            if (abaAlvo && !abaAlvo.classList.contains('ativa')) { abaAlvo.click(); }
        }
    }

    // ----- Aba Destinos -----
    function renderAbaDestinos(formId, painel, urlNativo, autoCampos) {
        Promise.all([
            ajax('destinos_listar', { form: formId }),
            ajax('formulario', { form: formId })
        ]).then(function (res) {
            var destinos = (res[0] && res[0].success) ? res[0].dados : [];
            var det = (res[1] && res[1].success) ? res[1].dados : null;
            var perguntas = [];
            if (det) { (det.secoes || []).forEach(function (s) { (s.perguntas || []).forEach(function (p) { perguntas.push({ v: p.id, t: p.nome }); }); }); }
            estado._campoEstado = {};
            destinos.forEach(function (d) { estado._campoEstado[d.id] = d.campos_estado || {}; });

            var html = '';
            if (podeEditar) {
                var optTipos = (CFG.tiposDestino || []).map(function (t) { return { v: t.classe, t: t.label }; });
                html += '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">';
                html += '<select id="dest_novo_tipo" class="catalogoeformularios-select" style="max-width:220px;">';
                optTipos.forEach(function (o) { html += '<option value="' + esc(o.v) + '">' + esc(o.t) + '</option>'; });
                html += '</select>';
                html += '<input type="text" id="dest_novo_nome" class="catalogoeformularios-input" placeholder="Nome do destino" style="max-width:220px;">';
                html += '<button class="catalogoeformularios-btn catalogoeformularios-btn-primario" id="dest_criar"><i class="ti ti-plus"></i> Novo destino</button>';
                html += '</div>';
            }
            if (!destinos.length) {
                html += vazio('ti ti-target', 'Nenhum destino. Crie um destino para gerar chamados a partir deste formulario.');
            }
            destinos.forEach(function (d) { html += destinoHtml(d, perguntas); });
            painel.innerHTML = html;

            var btnCriar = painel.querySelector('#dest_criar');
            if (btnCriar) {
                btnCriar.addEventListener('click', function () {
                    ajax('destino_criar', {
                        form: formId,
                        classe: painel.querySelector('#dest_novo_tipo').value,
                        nome: painel.querySelector('#dest_novo_nome').value
                    }).then(function (r) {
                        toast(r.message, !!(r && r.success));
                        if (r && r.success) { renderAbaDestinos(formId, painel, urlNativo); }
                    });
                });
            }
            painel.querySelectorAll('.catalogoeformularios-selbusca').forEach(ativarSelectBusca);
            ligarAcoesDestino(painel, formId, urlNativo);
            if (autoCampos) {
                var primeiroConfig = painel.querySelector('[data-dacao="config"]');
                if (primeiroConfig) { primeiroConfig.click(); }
            }
        });
    }

    function destinoHtml(d, perguntas) {
        var h = '<div class="catalogoeformularios-destino" data-destino="' + d.id + '">';
        h += '<div class="catalogoeformularios-destino-cab"><span class="catalogoeformularios-destino-nome"><i class="ti ti-target"></i> ' + esc(d.nome)
            + ' <span class="catalogoeformularios-destino-tipo">' + esc(d.tipo_label) + '</span></span>';
        if (podeEditar) {
            h += '<div class="catalogoeformularios-acoes-linha">'
                + '<button class="catalogoeformularios-btn-icone" data-dacao="renomear" data-id="' + d.id + '" title="Renomear"><i class="ti ti-edit"></i></button>'
                + '<button class="catalogoeformularios-btn-icone" data-dacao="excluir" data-id="' + d.id + '" title="Excluir"><i class="ti ti-trash"></i></button>'
                + '<button class="catalogoeformularios-btn-icone" data-dacao="config" data-id="' + d.id + '" title="Configurar campos"><i class="ti ti-adjustments"></i></button>'
                + '</div>';
        }
        h += '</div>';
        h += '<div class="catalogoeformularios-destino-corpo" data-corpo="' + d.id + '" style="display:none;"></div>';
        return h;
    }

    function ligarAcoesDestino(painel, formId, urlNativo) {
        painel.querySelectorAll('[data-dacao]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = parseInt(btn.getAttribute('data-id'), 10);
                var acao = btn.getAttribute('data-dacao');
                if (acao === 'renomear') {
                    var mm = abrirModal({ titulo: '<i class="ti ti-edit"></i> Renomear destino' });
                    mm.body.innerHTML = campoTexto('dest_nome', 'Nome', '', true) + blocoErro();
                    mm.foot.appendChild(botaoFoot('Cancelar', '', mm.fechar));
                    mm.foot.appendChild(botaoFoot('Salvar', 'catalogoeformularios-btn-primario', function () {
                        var nome = document.getElementById('dest_nome').value.trim();
                        if (!nome) { mostrarErro(mm, 'Informe o nome.'); return; }
                        ajax('destino_renomear', { id: id, nome: nome }).then(function (r) {
                            toast(r.message, !!(r && r.success));
                            if (r && r.success) { mm.fechar(); renderAbaDestinos(formId, painel, urlNativo); }
                        });
                    }));
                } else if (acao === 'excluir') {
                    confirmar('Excluir este destino?', function () {
                        ajax('destino_excluir', { id: id }).then(function (r) {
                            toast(r.message, !!(r && r.success));
                            if (r && r.success) { renderAbaDestinos(formId, painel, urlNativo); }
                        });
                    });
                } else if (acao === 'config') {
                    var corpo = painel.querySelector('[data-corpo="' + id + '"]');
                    if (corpo.style.display === 'none') {
                        corpo.style.display = 'block';
                        renderCamposDestino(corpo, id, urlNativo);
                    } else { corpo.style.display = 'none'; }
                }
            });
        });
    }

    function renderCamposDestino(corpo, destinoId, urlNativo) {
        var perguntas = [];
        if (estado.detalhe && estado.detalhe.id) {
            (estado.detalhe.secoes || []).forEach(function (s) { (s.perguntas || []).forEach(function (p) { perguntas.push({ v: p.id, t: p.nome }); }); });
        }
        var campos = CFG.camposDestino || [];
        var estadoCampos = (estado._campoEstado || {})[destinoId] || {};
        var html = '';
        campos.forEach(function (c, idx) {
            // Observadores: multiselect de GRUPOS (grava direto no destino via GLPI nativo).
            if (c.slug === 'observador' && c.suporte) {
                var uidObs = 'dcobs_' + destinoId;
                var obsCfg = estadoCampos['observador'] || {};
                var obsTem = !!(obsCfg.grupos && obsCfg.grupos.length);
                html += '<div class="catalogoeformularios-dcampo catalogoeformularios-dacc' + (obsTem ? ' catalogoeformularios-dacc-preenchido' : '') + '" data-observador="1" data-uid="' + uidObs + '">'
                    + '<div class="catalogoeformularios-dacc-cab"><span class="catalogoeformularios-dcampo-label">' + esc(c.label) + ' (grupos)</span><i class="ti ti-chevron-down catalogoeformularios-dacc-seta"></i></div>'
                    + '<div class="catalogoeformularios-dacc-corpo" style="display:none;flex-direction:column;align-items:stretch;">'
                    + '<div class="catalogoeformularios-dcampo-valor dcobs-valor" style="width:100%;"><span style="font-size:12px;color:#999;">Carregando...</span></div>'
                    + '<div style="display:flex;gap:6px;margin-top:8px;">'
                    + '<button class="catalogoeformularios-btn catalogoeformularios-btn-primario dcobs-salvar"><i class="ti ti-device-floppy"></i> Salvar observadores</button>'
                    + '<button class="catalogoeformularios-btn dcobs-limpar"><i class="ti ti-eraser"></i> Limpar</button>'
                    + '</div></div></div>';
                return;
            }
            if (c.multi || !c.suporte) {
                html += '<div class="catalogoeformularios-dcampo catalogoeformularios-dacc sem-suporte">'
                    + '<div class="catalogoeformularios-dacc-cab"><span class="catalogoeformularios-dcampo-label">' + esc(c.label) + '</span><i class="ti ti-chevron-down catalogoeformularios-dacc-seta"></i></div>'
                    + '<div class="catalogoeformularios-dacc-corpo" style="display:none;">'
                    + '<div class="catalogoeformularios-dcampo-valor">Configure no <a href="' + esc(urlNativo) + '" target="_blank">editor nativo</a>.</div>'
                    + '</div></div>';
                return;
            }
            var uid = 'dc_' + destinoId + '_' + idx;
            var est = estadoCampos[c.slug] || {};
            var estr = est.estrategia || 'especifico';
            var valSalvo = (est.valor !== undefined && est.valor !== null) ? est.valor : '';
            var qSalva = est.question || 0;
            var temConfig = false;
            if (est.estrategia === 'especifico') { temConfig = !!est.valor; }
            else if (est.estrategia && est.estrategia !== 'modelo') { temConfig = true; }
            var classeDacc = 'catalogoeformularios-dcampo catalogoeformularios-dacc' + (temConfig ? ' catalogoeformularios-dacc-preenchido' : '');
            html += '<div class="' + classeDacc + '" data-campo="' + esc(c.slug) + '" data-fonte="' + esc(c.fonte) + '" data-uid="' + uid + '" data-valor-salvo="' + esc(String(valSalvo)) + '" data-question-salva="' + esc(String(qSalva)) + '">'
                + '<div class="catalogoeformularios-dacc-cab"><span class="catalogoeformularios-dcampo-label">' + esc(c.label) + '</span><i class="ti ti-chevron-down catalogoeformularios-dacc-seta"></i></div>'
                + '<div class="catalogoeformularios-dacc-corpo" style="display:none;">'
                + '<div class="catalogoeformularios-dcampo-estrat"><select class="catalogoeformularios-select dc-estrat">'
                + '<option value="modelo"' + (estr === 'modelo' ? ' selected' : '') + '>Do modelo</option><option value="especifico"' + (estr === 'especifico' ? ' selected' : '') + '>Especifico</option><option value="resposta"' + (estr === 'resposta' ? ' selected' : '') + '>Resposta de pergunta</option>'
                + '</select></div>'
                + '<div class="catalogoeformularios-dcampo-valor dc-valor"></div>'
                + '<button class="catalogoeformularios-btn-icone dc-salvar" title="Salvar"><i class="ti ti-device-floppy"></i></button>'
                + '<button class="catalogoeformularios-btn-icone dc-limpar" title="Limpar (voltar ao padrao)"><i class="ti ti-eraser"></i></button>'
                + '</div></div>';
        });
        corpo.innerHTML = html || '<div style="font-size:12px;color:#999;">Sem campos configuraveis.</div>';

        // Expandir / recolher cada area ao clicar no cabecalho
        corpo.querySelectorAll('.catalogoeformularios-dacc').forEach(function (acc) {
            var cab  = acc.querySelector('.catalogoeformularios-dacc-cab');
            var body = acc.querySelector('.catalogoeformularios-dacc-corpo');
            cab.addEventListener('click', function () {
                var aberto = body.style.display !== 'none';
                body.style.display = aberto ? 'none' : 'flex';
                acc.classList.toggle('aberto', !aberto);
                if (!aberto && acc._montar && !acc._montado) { acc._montado = true; acc._montar(); }
            });
        });

        // Observadores (grupos): monta multiselect ao expandir e salva/limpa via AJAX.
        var obsAcc = corpo.querySelector('[data-observador]');
        if (obsAcc) {
            obsAcc._montar = function () {
                var box = obsAcc.querySelector('.dcobs-valor');
                var uidObs = obsAcc.getAttribute('data-uid');
                Promise.all([
                    carregarFonte('atores'),
                    ajax('destino_observador_estado', { id: destinoId })
                ]).then(function (res) {
                    var grupos = (res[0] && res[0].grupos) ? res[0].grupos : [];
                    var sel = (res[1] && res[1].success && res[1].dados) ? (res[1].dados.grupos || []) : [];
                    box.innerHTML = grupos.length ? multiBusca(uidObs + '_ms', grupos, sel)
                        : '<span style="font-size:12px;color:#999;">Nenhum grupo disponivel.</span>';
                    box.querySelectorAll('.catalogoeformularios-mb').forEach(ativarMultiBusca);
                    obsAcc.classList.toggle('catalogoeformularios-dacc-preenchido', sel.length > 0);
                });
            };
            obsAcc.querySelector('.dcobs-salvar').addEventListener('click', function () {
                var cont = obsAcc.querySelector('.catalogoeformularios-mb');
                var ids = cont ? coletarMultiBusca(cont) : [];
                ajax('destino_observador_grupos', { id: destinoId, grupos: ids }).then(function (r) {
                    toast(r.message, !!(r && r.success));
                    if (r && r.success) { obsAcc.classList.toggle('catalogoeformularios-dacc-preenchido', ids.length > 0); }
                });
            });
            obsAcc.querySelector('.dcobs-limpar').addEventListener('click', function () {
                ajax('destino_observador_grupos', { id: destinoId, grupos: [] }).then(function (r) {
                    toast(r.message, !!(r && r.success));
                    if (r && r.success) {
                        var cont = obsAcc.querySelector('.catalogoeformularios-mb');
                        if (cont) { cont.querySelectorAll('input:checked').forEach(function (ch) { ch.checked = false; }); }
                        obsAcc.classList.remove('catalogoeformularios-dacc-preenchido');
                    }
                });
            });
        }

        corpo.querySelectorAll('.catalogoeformularios-dcampo[data-campo]').forEach(function (row) {
            var fonte = row.getAttribute('data-fonte');
            var uid = row.getAttribute('data-uid');
            var estrat = row.querySelector('.dc-estrat');
            var valor = row.querySelector('.dc-valor');

            var valSalvoRow = row.getAttribute('data-valor-salvo') || '';
            var qSalvaRow = row.getAttribute('data-question-salva') || '';
            function montarValor() {
                var e = estrat.value;
                if (e === 'modelo') { valor.innerHTML = '<span style="font-size:12px;color:#999;">Herdado do modelo</span>'; return; }
                if (e === 'resposta') {
                    valor.innerHTML = campoSelectBusca(uid + '_q', '', perguntas.length ? perguntas : [{ v: 0, t: '(sem perguntas)' }], qSalvaRow);
                    valor.querySelectorAll('.catalogoeformularios-selbusca').forEach(ativarSelectBusca);
                    return;
                }
                valor.innerHTML = '<span style="font-size:12px;color:#999;">Carregando...</span>';
                carregarFonte(fonte).then(function (lista) {
                    valor.innerHTML = campoSelectBusca(uid + '_v', '', lista.length ? lista : [{ v: 0, t: '(vazio)' }], valSalvoRow);
                    valor.querySelectorAll('.catalogoeformularios-selbusca').forEach(ativarSelectBusca);
                });
            }
            estrat.addEventListener('change', montarValor);
            // monta o conteudo somente na primeira vez que a area for expandida
            row._montar = montarValor;

            row.querySelector('.dc-salvar').addEventListener('click', function () {
                var e = estrat.value;
                var params = { id: destinoId, campo: row.getAttribute('data-campo'), estrategia: e };
                if (e === 'especifico') {
                    var h = document.getElementById(uid + '_v');
                    params.valor = h ? h.value : '';
                } else if (e === 'resposta') {
                    var hq = document.getElementById(uid + '_q');
                    params.question = hq ? hq.value : 0;
                }
                ajax('destino_definir_campo', params).then(function (r) {
                    toast(r.message, !!(r && r.success));
                    if (r && r.success) {
                        var marcar = (e === 'especifico') ? !!(params.valor && params.valor !== '0') : (e !== 'modelo');
                        row.classList.toggle('catalogoeformularios-dacc-preenchido', marcar);
                    }
                });
            });

            var btnLimpar = row.querySelector('.dc-limpar');
            if (btnLimpar) {
                btnLimpar.addEventListener('click', function () {
                    ajax('destino_definir_campo', { id: destinoId, campo: row.getAttribute('data-campo'), estrategia: 'limpar' }).then(function (r) {
                        toast(r.message, !!(r && r.success));
                        if (r && r.success) {
                            estrat.value = 'modelo';
                            montarValor();
                            row.classList.remove('catalogoeformularios-dacc-preenchido');
                            row.setAttribute('data-valor-salvo', '');
                            row.setAttribute('data-question-salva', '0');
                        }
                    });
                });
            }
        });
    }

    // ----- Aba Controle de acesso -----
    function renderAbaAcesso(formId, painel) {
        Promise.all([
            ajax('acesso_estado', { form: formId }),
            carregarFonte('perfis'),
            carregarFonte('atores')
        ]).then(function (res) {
            var est = (res[0] && res[0].success) ? res[0].dados : { lista: {}, direto: {} };
            var perfis = res[1] || [];
            var atores = res[2] || { usuarios: [], grupos: [] };
            var lista = est.lista || {};
            var direto = est.direto || {};

            var html = '';
            // AllowList
            var temUsuarios = (lista.usuarios || []).length > 0;
            var temGrupos   = (lista.grupos   || []).length > 0;
            html += '<div class="catalogoeformularios-acesso-bloco">';
            html += '<div class="catalogoeformularios-acesso-cab"><span class="catalogoeformularios-acesso-titulo"><i class="ti ti-list-check"></i> Lista de permissao</span>'
                + switchHtml('ac_lista_ativo', lista.ativo) + '</div>';
            html += campoCheck('ac_todos', 'Liberar para TODOS os usuarios autenticados', !!lista.todos);
            html += '<div id="ac_listas" style="' + (lista.todos ? 'display:none;' : '') + '">';

            // Perfis: multiselect sempre visivel.
            html += '<label class="catalogoeformularios-campo" style="font-size:12px;font-weight:600;color:#495057;display:block;"><i class="ti ti-shield"></i> Perfis com acesso</label>'
                + multiBusca('ac_perfis', perfis, lista.perfis || []);

            // Usuarios: revelado por switch.
            html += '<div class="catalogoeformularios-acesso-cab" style="margin-top:14px;"><span class="catalogoeformularios-acesso-titulo" style="font-size:12px;"><i class="ti ti-user"></i> Usuarios especificos</span>'
                + switchHtml('ac_switch_usuarios', temUsuarios) + '</div>';
            html += '<div id="ac_wrap_usuarios" style="' + (temUsuarios ? '' : 'display:none;') + '">'
                + multiBusca('ac_usuarios', atores.usuarios || [], lista.usuarios || []) + '</div>';

            // Grupos: revelado por switch.
            html += '<div class="catalogoeformularios-acesso-cab" style="margin-top:14px;"><span class="catalogoeformularios-acesso-titulo" style="font-size:12px;"><i class="ti ti-users"></i> Grupos com acesso</span>'
                + switchHtml('ac_switch_grupos', temGrupos) + '</div>';
            html += '<div id="ac_wrap_grupos" style="' + (temGrupos ? '' : 'display:none;') + '">'
                + multiBusca('ac_grupos', atores.grupos || [], lista.grupos || []) + '</div>';

            html += '<p class="catalogoeformularios-ac-ajuda" style="margin-top:12px;"><i class="ti ti-info-circle"></i> Ao selecionar um perfil ou grupo, todos os usuarios contidos nele passam a ter acesso. Quem nao estiver selecionado (e sem "Liberar para TODOS") nao vera o formulario.</p>';
            html += '</div>';
            html += '<div style="margin-top:10px;"><button class="catalogoeformularios-btn catalogoeformularios-btn-primario" id="ac_salvar_lista"><i class="ti ti-device-floppy"></i> Salvar lista</button></div>';
            html += '</div>';

            // DirectAccess
            html += '<div class="catalogoeformularios-acesso-bloco">';
            html += '<div class="catalogoeformularios-acesso-cab"><span class="catalogoeformularios-acesso-titulo"><i class="ti ti-link"></i> Acesso direto (link)</span>'
                + switchHtml('ac_direto_ativo', direto.ativo) + '</div>';
            html += campoCheck('ac_unauth', 'Permitir acesso anonimo (sem login)', !!direto.allow_unauthenticated);
            if (direto.token) {
                html += '<div style="font-size:12px;color:#6c757d;">Token atual: <code>' + esc(direto.token) + '</code></div>';
            }
            html += '<div style="margin-top:10px;"><button class="catalogoeformularios-btn catalogoeformularios-btn-primario" id="ac_salvar_direto"><i class="ti ti-device-floppy"></i> Salvar acesso direto</button></div>';
            html += '</div>';

            painel.innerHTML = html;
            painel.querySelectorAll('.catalogoeformularios-mb').forEach(ativarMultiBusca);

            var chkTodos = painel.querySelector('#ac_todos');
            chkTodos.addEventListener('change', function () { painel.querySelector('#ac_listas').style.display = chkTodos.checked ? 'none' : 'block'; });

            // Switches que mostram/escondem os multiselects de usuarios e grupos.
            var swUsuarios = painel.querySelector('#ac_switch_usuarios');
            var swGrupos   = painel.querySelector('#ac_switch_grupos');
            swUsuarios.addEventListener('change', function () { painel.querySelector('#ac_wrap_usuarios').style.display = swUsuarios.checked ? 'block' : 'none'; });
            swGrupos.addEventListener('change', function () { painel.querySelector('#ac_wrap_grupos').style.display = swGrupos.checked ? 'block' : 'none'; });

            painel.querySelector('#ac_salvar_lista').addEventListener('click', function () {
                ajax('acesso_salvar_lista', {
                    form: formId,
                    ativo: painel.querySelector('#ac_lista_ativo').checked ? 1 : 0,
                    todos: chkTodos.checked ? 1 : 0,
                    perfis: coletarMultiBusca(painel.querySelector('#ac_perfis')),
                    usuarios: swUsuarios.checked ? coletarMultiBusca(painel.querySelector('#ac_usuarios')) : [],
                    grupos: swGrupos.checked ? coletarMultiBusca(painel.querySelector('#ac_grupos')) : []
                }).then(function (r) { toast(r.message, !!(r && r.success)); });
            });
            painel.querySelector('#ac_salvar_direto').addEventListener('click', function () {
                ajax('acesso_salvar_direto', {
                    form: formId,
                    ativo: painel.querySelector('#ac_direto_ativo').checked ? 1 : 0,
                    allow_unauthenticated: painel.querySelector('#ac_unauth').checked ? 1 : 0
                }).then(function (r) { toast(r.message, !!(r && r.success)); });
            });
        });
    }

    function switchHtml(id, on) {
        return '<label class="catalogoeformularios-switch"><input type="checkbox" id="' + id + '"' + (on ? ' checked' : '') + '><span class="trilho"></span></label>';
    }

    // -----------------------------------------------------------------
    // Modal: Visibilidade condicional de uma pergunta
    // -----------------------------------------------------------------
    function modalCondicao(perguntaId) {
        var det = estado.detalhe;
        if (!det) { return; }
        var alvos = [];
        var atualNome = '';
        (det.secoes || []).forEach(function (s) {
            (s.perguntas || []).forEach(function (p) {
                if (p.id === perguntaId) { atualNome = p.nome; }
                else if (p.uuid) { alvos.push({ v: p.uuid, t: p.nome }); }
            });
        });

        var m = abrirModal({ titulo: '<i class="ti ti-eye-check"></i> Visibilidade: ' + esc(atualNome), grande: true });

        ajax('condicao_get', { pergunta: perguntaId }).then(function (r) {
            var regra = (r && r.success) ? r.dados : { estrategia: 'sempre', condicoes: [] };
            var estrategias = CFG.condEstrategias || [{ slug: 'sempre', label: 'Sempre visivel' }];
            var operadores = CFG.condOperadores || [{ slug: 'equals', label: 'igual a' }];

            var html = '';
            if (!CFG.condSuportado) {
                html += '<div class="catalogoeformularios-aviso"><i class="ti ti-alert-triangle"></i> O motor de condicoes desta versao do GLPI nao foi reconhecido. Voce pode definir "Sempre visivel" aqui; regras detalhadas devem ser feitas no editor nativo.</div>';
            }
            html += campoSelect('cond_estrategia', 'Estrategia', estrategias.map(function (e) { return { v: e.slug, t: e.label }; }), regra.estrategia);
            html += '<div id="cond_regras" style="' + (regra.estrategia === 'sempre' ? 'display:none;' : '') + '">';
            html += '<div id="cond_linhas"></div>';
            html += '<button type="button" class="catalogoeformularios-btn" id="cond_add"><i class="ti ti-plus"></i> Adicionar condicao</button>';
            html += '</div>' + blocoErro();
            m.body.innerHTML = html;

            var linhas = m.body.querySelector('#cond_linhas');
            function addLinha(c) {
                c = c || {};
                var div = document.createElement('div');
                div.className = 'catalogoeformularios-cond-linha';
                var idq = 'cl_q_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6);
                div.innerHTML =
                    '<select class="catalogoeformularios-select logica"><option value="and">E</option><option value="or">OU</option></select>'
                  + campoSelectBusca(idq, '', alvos.length ? alvos : [{ v: '', t: '(sem perguntas)' }], c.pergunta_uuid || '')
                  + '<select class="catalogoeformularios-select cl-op">' + operadores.map(function (o) { return '<option value="' + esc(o.slug) + '">' + esc(o.label) + '</option>'; }).join('') + '</select>'
                  + '<input type="text" class="catalogoeformularios-input cl-val" placeholder="valor" value="' + esc(c.valor || '') + '">'
                  + '<button type="button" class="catalogoeformularios-btn-icone rem" title="Remover"><i class="ti ti-x"></i></button>';
                linhas.appendChild(div);
                div.querySelectorAll('.catalogoeformularios-selbusca').forEach(ativarSelectBusca);
                div.querySelector('.rem').addEventListener('click', function () { div.remove(); });
                div.setAttribute('data-idq', idq);
                if (c.logica === 'or') { div.querySelector('.logica').value = 'or'; }
                if (c.operador) { div.querySelector('.cl-op').value = c.operador; }
            }

            (regra.condicoes || []).forEach(function (c) {
                addLinha({
                    pergunta_uuid: c.pergunta_uuid || c.item || c.item_uuid || '',
                    operador: c.operador || c.value_operator || 'equals',
                    valor: c.valor !== undefined ? c.valor : (c.value !== undefined ? c.value : ''),
                    logica: c.logica || c.logic_operator || 'and'
                });
            });
            if (!(regra.condicoes || []).length) { addLinha(); }

            m.body.querySelector('#cond_add').addEventListener('click', function () { addLinha(); });
            var selEstr = m.body.querySelector('#cond_estrategia');
            selEstr.addEventListener('change', function () {
                m.body.querySelector('#cond_regras').style.display = (selEstr.value === 'sempre') ? 'none' : 'block';
            });

            m.foot.appendChild(botaoFoot('Cancelar', '', m.fechar));
            m.foot.appendChild(botaoFoot('<i class="ti ti-device-floppy"></i> Salvar', 'catalogoeformularios-btn-primario', function () {
                var estr = selEstr.value;
                if (estr === 'sempre') {
                    ajax('condicao_limpar', { pergunta: perguntaId }).then(function (rr) {
                        toast(rr.message, !!(rr && rr.success));
                        if (rr && rr.success) { m.fechar(); carregarBuilder(estado.formId); }
                    });
                    return;
                }
                var cond = [];
                linhas.querySelectorAll('.catalogoeformularios-cond-linha').forEach(function (div) {
                    var idq = div.getAttribute('data-idq');
                    var uuid = (document.getElementById(idq) || {}).value || '';
                    if (!uuid) { return; }
                    cond.push({
                        pergunta_uuid: uuid,
                        operador: div.querySelector('.cl-op').value,
                        valor: div.querySelector('.cl-val').value,
                        logica: div.querySelector('.logica').value
                    });
                });
                if (!cond.length) { mostrarErro(m, 'Informe ao menos uma condicao valida.'); return; }
                ajax('condicao_definir', { pergunta: perguntaId, estrategia: estr, condicoes_json: JSON.stringify(cond) }).then(function (rr) {
                    toast(rr.message, !!(rr && rr.success));
                    if (rr && rr.success) { m.fechar(); carregarBuilder(estado.formId); }
                });
            }));
        });
    }

    // -----------------------------------------------------------------
    // Lookups
    // -----------------------------------------------------------------
    function acharCategoria(id) { return (estado.conteudo && estado.conteudo.categorias || []).filter(function (c) { return c.id === id; })[0]; }
    function acharSecao(id) { return (estado.detalhe && estado.detalhe.secoes || []).filter(function (s) { return s.id === id; })[0]; }
    function acharPergunta(id) {
        var achada = null;
        (estado.detalhe && estado.detalhe.secoes || []).forEach(function (s) { s.perguntas.forEach(function (p) { if (p.id === id) { achada = p; } }); });
        return achada;
    }

    // -----------------------------------------------------------------
    // Painel rapido de controle do formulario (abre na propria lista)
    // -----------------------------------------------------------------
    var PN_ESTRATEGIAS = [
        { v: 'modelo',     t: 'Padrao do GLPI / modelo' },
        { v: 'especifico', t: 'Valor fixo' },
        { v: 'resposta',   t: 'Resposta de uma pergunta' }
    ];

    // Campos oferecidos na edicao em lote da matriz (slug -> rotulo + fonte).
    var PN_LOTE = [
        { v: 'categoria', t: 'Categoria ITIL',              fonte: 'itilcategorias' },
        { v: 'sla_tto',   t: 'Tempo para atendimento (SLA)', fonte: 'slas' },
        { v: 'sla_ttr',   t: 'Tempo para solucao (SLA)',     fonte: 'slas' },
        { v: 'ola_tto',   t: 'Atendimento interno (OLA)',    fonte: 'olas' },
        { v: 'ola_ttr',   t: 'Solucao interna (OLA)',        fonte: 'olas' },
        { v: 'tipo',      t: 'Tipo de requisicao',           fonte: 'tiposrequisicao' },
        { v: 'urgencia',  t: 'Urgencia',                     fonte: 'urgencias' },
        { v: 'origem',    t: 'Origem da requisicao',         fonte: 'origens' }
    ];

    /** Remove o painel do DOM sem apagar o estado (usado no arraste). */
    function pnFecharDom() {
        elConteudo.querySelectorAll('.catalogoeformularios-pn').forEach(function (p) { p.remove(); });
        elConteudo.querySelectorAll('.catalogoeformularios-form-linha.painel-aberto').forEach(function (l) {
            l.classList.remove('painel-aberto');
            var ic = l.querySelector('.catalogoeformularios-form-linha-chevron i');
            if (ic) { ic.className = 'ti ti-chevron-right'; }
        });
    }

    function pnFechar() {
        pnFecharDom();
        estado.painelForm = 0;
        estado.painelDados = null;
        renderNavegador();
    }

    function pnAlternar(formId) {
        if (estado.painelForm === formId) { pnFechar(); return; }
        pnAbrir(formId, { rolar: false });
    }

    /** Abre o acordeao do formulario direto numa aba (Geral, Estrutura, Chamado gerado, Quem visualiza). */
    function pnAbrirAba(formId, aba) {
        estado.pnAba = aba;
        var box = elConteudo.querySelector('.catalogoeformularios-pn[data-pn-form="' + formId + '"]');
        if (box && estado.painelDados && (estado.painelDados.form || {}).id === formId) {
            pnMostrarAba(box, estado.painelDados, aba);
            try { box.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) { box.scrollIntoView(); }
            return;
        }
        pnAbrir(formId, { rolar: true });
    }

    /** Expande os acordeons necessarios para a linha do formulario ficar visivel. */
    function pnGarantirVisivel(formId) {
        var linha = elConteudo.querySelector('.catalogoeformularios-form-linha[data-abrir-form="' + formId + '"]');
        if (!linha) { return null; }
        var el = linha.parentElement;
        while (el && el !== elConteudo) {
            if (el.classList && el.classList.contains('catalogoeformularios-grupo')) { aplicarEstadoGrupo(el, true); }
            if (el.classList && el.classList.contains('catalogoeformularios-subcat')) { aplicarEstadoSubcat(el, true); }
            el = el.parentElement;
        }
        return linha;
    }

    function pnAbrir(formId, opts) {
        opts = opts || {};
        formId = parseInt(formId, 10) || 0;
        if (!formId) { return Promise.resolve(); }

        pnFecharDom();
        var linha = pnGarantirVisivel(formId);
        if (!linha) { estado.painelForm = 0; renderNavegador(); return Promise.resolve(); }

        estado.painelForm = formId;
        linha.classList.add('painel-aberto');
        var ic = linha.querySelector('.catalogoeformularios-form-linha-chevron i');
        if (ic) { ic.className = 'ti ti-chevron-down'; }

        var box = document.createElement('div');
        box.className = 'catalogoeformularios-pn';
        box.setAttribute('data-pn-form', String(formId));
        box.innerHTML = '<div class="catalogoeformularios-pn-carregando"><i class="ti ti-loader"></i> Carregando painel de controle...</div>';
        linha.parentNode.insertBefore(box, linha.nextSibling);

        renderNavegador();
        if (opts.rolar) {
            requestAnimationFrame(function () {
                try { linha.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) { linha.scrollIntoView(); }
            });
        }

        return pnCarregar(formId);
    }

    function pnCarregar(formId) {
        return ajax('painel_formulario', { form: formId }).then(function (r) {
            var box = elConteudo.querySelector('.catalogoeformularios-pn[data-pn-form="' + formId + '"]');
            if (!box) { return; }
            if (!r || !r.success || !r.dados) {
                box.innerHTML = '<div class="catalogoeformularios-pn-vazio"><i class="ti ti-alert-triangle"></i> '
                    + esc((r && r.message) || 'Falha ao carregar o painel.') + '</div>';
                return;
            }
            estado.painelDados = r.dados;
            pnRender(box, r.dados);
        }).catch(function () {
            var box = elConteudo.querySelector('.catalogoeformularios-pn[data-pn-form="' + formId + '"]');
            if (box) {
                box.innerHTML = '<div class="catalogoeformularios-pn-vazio"><i class="ti ti-alert-triangle"></i> Erro ao carregar o painel.</div>';
            }
        });
    }

    /** Recarrega o painel aberto em si mesmo, sem mexer no resto da tela. */
    function pnRecarregar() {
        if (!estado.painelForm) { return Promise.resolve(); }
        return pnCarregar(estado.painelForm);
    }

    // ----- Montagem do painel -----

    function pnChipHtml(icone, rotulo, valor) {
        var tem = valor !== undefined && valor !== null && String(valor).trim() !== '';
        var txt = tem ? String(valor) : 'padrao';
        return '<span class="catalogoeformularios-pn-chip' + (tem ? '' : ' vazio') + '" title="' + esc(rotulo + ': ' + txt) + '">'
            + '<i class="' + icone + '"></i>'
            + '<b>' + esc(rotulo) + '</b>'
            + '<span>' + esc(txt) + '</span></span>';
    }

    function pnCampoPorSlug(d, slug) {
        var achado = null;
        (d.campos || []).forEach(function (c) { if (c.slug === slug) { achado = c; } });
        return achado;
    }

    function pnCampoValorTexto(c) {
        if (!c) { return ''; }
        if (c.estrategia === 'resposta') {
            return 'Resposta: ' + (c.question_label || 'pergunta do formulario');
        }
        if (c.estrategia === 'especifico' && (c.valor || 0) > 0) {
            return c.valor_label || ('#' + c.valor);
        }
        return '';
    }

    function pnResumoChips(d) {
        var acesso = d.acesso || {};
        var perfis = acesso.todos
            ? 'todos os perfis'
            : ((acesso.perfis || []).map(function (p) { return p.t; }).join(', '));
        var h = '<span class="catalogoeformularios-pn-chips">';
        h += pnChipHtml('ti ti-category',  'Categoria ITIL', pnCampoValorTexto(pnCampoPorSlug(d, 'categoria')));
        h += pnChipHtml('ti ti-clock',     'SLA atendimento', pnCampoValorTexto(pnCampoPorSlug(d, 'sla_tto')));
        h += pnChipHtml('ti ti-clock-check', 'SLA solucao',   pnCampoValorTexto(pnCampoPorSlug(d, 'sla_ttr')));
        h += pnChipHtml('ti ti-shield',    'Visualizam',      perfis);
        h += '</span>';
        return h;
    }

    function pnRender(box, d) {
        var f = d.form || {};
        var html = '';

        html += '<div class="catalogoeformularios-pn-cab">';
        html += '<span class="catalogoeformularios-pn-tit"><i class="ti ti-list-details"></i> <span>Painel de controle</span></span>';
        html += pnResumoChips(d);
        html += '<span class="catalogoeformularios-pn-cab-acoes">';
        if (podeEditar) {
            // Opcoes do formulario na propria barra: cada uma salva ao alterar
            if (f.tem_layout) {
                html += '<select class="catalogoeformularios-select catalogoeformularios-pn-layout" data-pn-geral-campo="layout" title="Exibicao do formulario">'
                     + '<option value="step_by_step"' + (f.layout !== 'single_page' ? ' selected' : '') + '>Passo a passo</option>'
                     + '<option value="single_page"' + (f.layout === 'single_page' ? ' selected' : '') + '>Pagina unica</option></select>';
            }
            html += '<label class="catalogoeformularios-pn-switch" title="Ativar ou desativar este formulario no catalogo">'
                 + '<input type="checkbox" data-pn-ativo="1"' + (f.ativo ? ' checked' : '') + '>'
                 + '<span class="trilho"></span>'
                 + '<span class="rot" data-pn-ativo-rot>' + (f.ativo ? 'Ativo' : 'Inativo') + '</span></label>';
            html += '<label class="catalogoeformularios-pn-switch" title="Visivel tambem nas entidades filhas">'
                 + '<input type="checkbox" data-pn-geral-campo="recursivo"' + (f.recursivo ? ' checked' : '') + '>'
                 + '<span class="trilho"></span><span class="rot">Subentidades</span></label>';
            if (f.tem_fixado) {
                html += '<label class="catalogoeformularios-pn-switch" title="Fixado no topo do catalogo de servicos">'
                     + '<input type="checkbox" data-pn-geral-campo="fixado"' + (f.fixado ? ' checked' : '') + '>'
                     + '<span class="trilho"></span><span class="rot">Fixado no topo</span></label>';
            }
        }
        html += '<button type="button" class="catalogoeformularios-btn-icone" data-pn-acao="recarregar" title="Recarregar o painel"><i class="ti ti-refresh"></i></button>';
        html += '<a class="catalogoeformularios-btn-icone" href="' + esc(d.url_nativo || '#') + '" target="_blank" rel="noopener" title="Abrir no editor nativo do GLPI"><i class="ti ti-external-link"></i></a>';
        html += '<button type="button" class="catalogoeformularios-btn-icone" data-pn-acao="fechar" title="Fechar o painel"><i class="ti ti-x"></i></button>';
        html += '</span></div>';

        // Abas: tudo do formulario sem sair do acordeao
        var abas = [
            { k: 'geral',     ic: 'ti ti-settings',    t: 'Geral' },
            { k: 'estrutura', ic: 'ti ti-layout-list', t: 'Estrutura', n: f.qtd_perguntas },
            { k: 'chamado',   ic: 'ti ti-ticket',      t: 'Chamado gerado', n: (d.destino || {}).total },
            { k: 'acesso',    ic: 'ti ti-shield',      t: 'Quem visualiza' }
        ];
        html += '<div class="catalogoeformularios-pn-abas" role="tablist">';
        abas.forEach(function (a) {
            html += '<button type="button" class="catalogoeformularios-pn-aba" data-pn-aba="' + a.k + '"><i class="' + a.ic + '"></i> ' + a.t
                + (a.n !== undefined ? ' <span class="catalogoeformularios-pn-aba-n">' + (a.n || 0) + '</span>' : '') + '</button>';
        });
        html += '</div>';

        html += '<div class="catalogoeformularios-pn-aba-corpo" data-pn-corpo="geral"><div data-pn-geral></div>' + pnCardEstrutura(d) + '</div>';
        html += '<div class="catalogoeformularios-pn-aba-corpo" data-pn-corpo="estrutura"><div data-pn-estrutura></div></div>';
        html += '<div class="catalogoeformularios-pn-aba-corpo" data-pn-corpo="chamado"><div class="catalogoeformularios-pn-grade">'
            + pnCardChamado(d) + pnCardAtores(d)
            + '<section class="catalogoeformularios-pn-card catalogoeformularios-pn-card-larga"><header class="catalogoeformularios-pn-card-cab"><i class="ti ti-git-branch"></i> <span>Destinos e quando cada um e criado</span>'
            + (podeEditar ? '<button type="button" class="catalogoeformularios-btn" data-pn-acao="destinos"><i class="ti ti-adjustments"></i> Criar, renomear ou excluir destinos</button>' : '')
            + '</header><div data-pn-destinos></div></section>'
            + '</div></div>';
        html += '<div class="catalogoeformularios-pn-aba-corpo" data-pn-corpo="acesso"><div class="catalogoeformularios-pn-grade">' + pnCardAcesso(d) + '</div></div>';

        if (window.catalogoeformulariosEditor) { window.catalogoeformulariosEditor.limpar(box); }
        box.innerHTML = html;
        pnLigar(box, d);
        pnMostrarAba(box, d, estado.pnAba || pnAbaSalva());
    }

    function pnAbaSalva() {
        try { return window.localStorage.getItem('catalogoeformularios_pn_aba') || 'geral'; } catch (e) { return 'geral'; }
    }

    /** Mostra uma aba do acordeao; Geral, Estrutura e Destinos carregam o editor na primeira vez. */
    function pnMostrarAba(box, d, aba) {
        var formId = (d.form || {}).id || 0;
        if (!box.querySelector('[data-pn-corpo="' + aba + '"]')) { aba = 'geral'; }
        estado.pnAba = aba;
        // Atores mudaram pela aba Geral: recarrega o painel ja nesta aba
        if (aba === 'chamado' && box._atoresSujos) { box._atoresSujos = false; pnRecarregar(); return; }
        try { window.localStorage.setItem('catalogoeformularios_pn_aba', aba); } catch (e) { /* sem armazenamento */ }
        box.querySelectorAll('[data-pn-aba]').forEach(function (b) { b.classList.toggle('ativa', b.getAttribute('data-pn-aba') === aba); });
        box.querySelectorAll('[data-pn-corpo]').forEach(function (c) { c.style.display = c.getAttribute('data-pn-corpo') === aba ? 'block' : 'none'; });
        var ed = window.catalogoeformulariosEditor;
        if (!ed) { return; }
        var alvo = null, tipo = '';
        if (aba === 'geral') { alvo = box.querySelector('[data-pn-geral]'); tipo = 'geral'; }
        if (aba === 'estrutura') { alvo = box.querySelector('[data-pn-estrutura]'); tipo = 'estrutura'; }
        if (aba === 'chamado') { alvo = box.querySelector('[data-pn-destinos]'); tipo = 'destinos'; }
        if (alvo && !alvo.getAttribute('data-montado')) {
            alvo.setAttribute('data-montado', '1');
            ed.montar(tipo, alvo, formId, function (info) {
                // Geral salvo: atualiza a linha do formulario na lista e o cabecalho do acordeao
                if (tipo === 'geral') {
                    if (info && typeof info.ativo !== 'undefined') { aplicarAtivoNaTela(formId, !!info.ativo, false); }
                    recarregarAtual({ silencioso: true });
                    return;
                }
                // Estrutura mudou: atualiza o contador da aba e as perguntas usadas pelos campos do chamado
                if (tipo === 'estrutura' && info) {
                    var n = 0;
                    (info.secoes || []).forEach(function (s) { (s.blocos || []).forEach(function (b) { if (b.bloco === 'pergunta') { n++; } }); });
                    var c = box.querySelector('[data-pn-aba="estrutura"] .catalogoeformularios-pn-aba-n');
                    if (c) { c.textContent = n; }
                    if (estado.painelDados) {
                        estado.painelDados.perguntas = [];
                        (info.origens || []).forEach(function (o) { estado.painelDados.perguntas.push({ v: o.id, t: o.nome }); });
                    }
                }
            });
        }
    }

    function pnCardChamado(d) {
        var dest = d.destino || {};
        var h = '<section class="catalogoeformularios-pn-card catalogoeformularios-pn-card-larga">';
        h += '<header class="catalogoeformularios-pn-card-cab"><i class="ti ti-ticket"></i> <span>Chamado gerado</span>'
           + '<span class="catalogoeformularios-pn-card-nota">'
           + (dest.id ? esc(dest.tipo_label || 'Destino') + ' &middot; ' + esc(dest.nome || '') : 'nenhum destino')
           + '</span></header>';

        if (!dest.id) {
            h += '<div class="catalogoeformularios-pn-alerta"><i class="ti ti-alert-triangle"></i>'
               + '<span>Este formulario ainda nao tem destino de chamado, entao nada e criado quando alguem responde.</span>'
               + (podeEditar ? '<button type="button" class="catalogoeformularios-btn catalogoeformularios-btn-primario" data-pn-acao="criar-destino"><i class="ti ti-plus"></i> Criar destino de chamado</button>' : '')
               + '</div></section>';
            return h;
        }

        h += '<div class="catalogoeformularios-pn-campos">';
        (d.campos || []).forEach(function (c) { h += pnCampoHtml(c); });
        h += '</div>';

        if (podeEditar) {
            h += '<div class="catalogoeformularios-pn-card-foot">'
               + '<button type="button" class="catalogoeformularios-btn" data-pn-acao="destinos"><i class="ti ti-adjustments"></i> Destinos avancados</button>'
               + '<button type="button" class="catalogoeformularios-btn" data-pn-acao="condicional"><i class="ti ti-eye-check"></i> Visibilidade condicional</button>'
               + '</div>';
        }
        return h + '</section>';
    }

    function pnCampoHtml(c) {
        var txt = pnCampoValorTexto(c);
        var definido = txt !== '';
        var h = '<div class="catalogoeformularios-pn-campo' + (definido ? ' definido' : '') + (c.suporte ? '' : ' sem-suporte') + '"'
              + ' data-pn-campo="' + esc(c.slug) + '"'
              + ' data-fonte="' + esc(c.fonte) + '"'
              + ' data-estrategia="' + esc(c.estrategia || 'modelo') + '"'
              + ' data-valor="' + esc(String(c.valor || 0)) + '"'
              + ' data-question="' + esc(String(c.question || 0)) + '">';
        h += '<span class="catalogoeformularios-pn-campo-rot">' + esc(c.label) + '</span>';
        if (!c.suporte) {
            h += '<span class="catalogoeformularios-pn-campo-val vazio" title="Nesta versao do GLPI este campo so pode ser ajustado no editor nativo">'
               + '<i class="ti ti-lock"></i><span>editor nativo</span></span>';
        } else if (!podeEditar) {
            h += '<span class="catalogoeformularios-pn-campo-val' + (definido ? '' : ' vazio') + '">'
               + '<span>' + esc(definido ? txt : 'padrao do GLPI') + '</span></span>';
        } else {
            h += '<button type="button" class="catalogoeformularios-pn-campo-val' + (definido ? '' : ' vazio') + '" data-pn-editar-campo="1"'
               + ' title="' + esc(definido ? txt : 'Nao definido: segue o padrao do GLPI') + '">'
               + '<span>' + esc(definido ? txt : 'padrao do GLPI') + '</span>'
               + '<i class="ti ti-edit"></i></button>';
        }
        h += '<div class="catalogoeformularios-pn-editor" style="display:none;"></div>';
        return h + '</div>';
    }

    function pnCardAtores(d) {
        var dest = d.destino || {};
        var h = '<section class="catalogoeformularios-pn-card">';
        h += '<header class="catalogoeformularios-pn-card-cab"><i class="ti ti-users"></i> <span>Atores do chamado</span>'
           + '<span class="catalogoeformularios-pn-card-nota">quem entra automaticamente</span></header>';

        if (!dest.id) {
            h += '<div class="catalogoeformularios-pn-alerta"><i class="ti ti-info-circle"></i>'
               + '<span>Crie o destino de chamado para definir os atores.</span></div></section>';
            return h;
        }

        (d.atores || []).forEach(function (a) {
            var itens = (a.usuarios || []).map(function (u) { return { t: u.t, ic: 'ti ti-user' }; })
                .concat((a.grupos || []).map(function (g) { return { t: g.t, ic: 'ti ti-users' }; }));
            h += '<div class="catalogoeformularios-pn-ator" data-pn-ator="' + esc(a.slug) + '">';
            h += '<div class="catalogoeformularios-pn-ator-cab">';
            h += '<span class="catalogoeformularios-pn-ator-rot"><i class="' + esc(a.icone || 'ti ti-user') + '"></i> <span>' + esc(a.label) + '</span></span>';
            if (podeEditar) {
                h += '<button type="button" class="catalogoeformularios-btn-icone" data-pn-editar-ator="1" title="Editar ' + esc(a.label) + '"><i class="ti ti-edit"></i></button>';
            }
            h += '</div>';
            h += '<div class="catalogoeformularios-pn-ator-val">';
            if (itens.length) {
                itens.forEach(function (it) {
                    h += '<span class="catalogoeformularios-pn-tag" title="' + esc(it.t) + '"><i class="' + it.ic + '"></i> <span>' + esc(it.t) + '</span></span>';
                });
            } else if (a.qtd_perguntas) {
                h += '<span class="catalogoeformularios-pn-tag"><i class="ti ti-help-circle"></i> <span>definido por resposta</span></span>';
            } else {
                h += '<span class="catalogoeformularios-pn-vazio-txt">padrao do GLPI</span>';
            }
            h += '</div>';
            h += '<div class="catalogoeformularios-pn-editor" style="display:none;"></div>';
            h += '</div>';
        });
        return h + '</section>';
    }

    function pnCardAcesso(d) {
        var a = d.acesso || {};
        var h = '<section class="catalogoeformularios-pn-card" data-pn-acesso="1">';
        h += '<header class="catalogoeformularios-pn-card-cab"><i class="ti ti-shield"></i> <span>Quem visualiza</span>';
        if (podeEditar) {
            h += '<button type="button" class="catalogoeformularios-btn-icone" data-pn-editar-acesso="1" title="Editar quem visualiza este formulario"><i class="ti ti-edit"></i></button>';
        }
        h += '</header>';

        h += '<div class="catalogoeformularios-pn-ator-val">';
        if (a.todos) {
            h += '<span class="catalogoeformularios-pn-tag"><i class="ti ti-world"></i> <span>todos os usuarios autenticados</span></span>';
        } else {
            var vazio = true;
            (a.perfis || []).forEach(function (p) {
                vazio = false;
                h += '<span class="catalogoeformularios-pn-tag"><i class="ti ti-shield"></i> <span>' + esc(p.t) + '</span></span>';
            });
            (a.grupos || []).forEach(function (g) {
                vazio = false;
                h += '<span class="catalogoeformularios-pn-tag"><i class="ti ti-users"></i> <span>' + esc(g.t) + '</span></span>';
            });
            (a.usuarios || []).forEach(function (u) {
                vazio = false;
                h += '<span class="catalogoeformularios-pn-tag"><i class="ti ti-user"></i> <span>' + esc(u.t) + '</span></span>';
            });
            if (vazio) {
                h += '<span class="catalogoeformularios-pn-vazio-txt">nenhuma restricao configurada (padrao do GLPI)</span>';
            }
        }
        h += '</div>';
        h += '<div class="catalogoeformularios-pn-linha-info">'
           + '<span class="catalogoeformularios-pn-marca' + (a.ativo ? ' on' : '') + '"><i class="ti ' + (a.ativo ? 'ti-lock' : 'ti-lock-open') + '"></i> '
           + (a.ativo ? 'restricao ativa' : 'restricao desligada') + '</span>';
        if (a.direto && a.direto.ativo) {
            h += '<span class="catalogoeformularios-pn-marca on"><i class="ti ti-link"></i> link direto'
               + (a.direto.allow_unauthenticated ? ' (anonimo)' : '') + '</span>';
        }
        h += '</div>';
        h += '<div class="catalogoeformularios-pn-editor" data-pn-editor-acesso="1" style="display:none;"></div>';
        return h + '</section>';
    }

    function pnCardEstrutura(d) {
        var f = d.form || {};
        var h = '<section class="catalogoeformularios-pn-card">';
        h += '<header class="catalogoeformularios-pn-card-cab"><i class="ti ti-sitemap"></i> <span>Estrutura e acoes</span></header>';
        h += '<div class="catalogoeformularios-pn-metricas">';
        h += '<span><i class="ti ti-layout-list"></i> <b>' + (f.qtd_secoes || 0) + '</b> secao(oes)</span>';
        h += '<span><i class="ti ti-help-circle"></i> <b>' + (f.qtd_perguntas || 0) + '</b> pergunta(s)</span>';
        h += '<span><i class="ti ti-target"></i> <b>' + ((d.destino || {}).total || 0) + '</b> destino(s)</span>';
        h += '<span><i class="ti ti-star"></i> <b>' + (f.usos || 0) + '</b> envio(s)</span>';
        h += '</div>';
        h += '<div class="catalogoeformularios-pn-linha-info">';
        h += '<span class="catalogoeformularios-pn-marca"><i class="ti ti-folder"></i> ' + esc(f.categoria_nome || '') + '</span>';
        h += '<span class="catalogoeformularios-pn-marca"><i class="ti ti-building"></i> ' + esc(f.entidade_nome || '') + '</span>';
        if (f.recursivo) { h += '<span class="catalogoeformularios-pn-marca"><i class="ti ti-git-merge"></i> subentidades</span>'; }
        if (f.rascunho)  { h += '<span class="catalogoeformularios-pn-marca alerta"><i class="ti ti-alert-triangle"></i> rascunho</span>'; }
        h += '</div>';

        if (podeEditar) {
            h += '<div class="catalogoeformularios-pn-card-foot">';
            h += '<button type="button" class="catalogoeformularios-btn" data-acao="editar-form" data-id="' + f.id + '"><i class="ti ti-edit"></i> Editar</button>';
            h += '<button type="button" class="catalogoeformularios-btn" data-acao="editar-secoes" data-id="' + f.id + '"><i class="ti ti-layout-list"></i> Secoes</button>';
            h += '<button type="button" class="catalogoeformularios-btn" data-acao="duplicar-form" data-id="' + f.id + '"><i class="ti ti-copy"></i> Duplicar</button>';
            h += '<button type="button" class="catalogoeformularios-btn catalogoeformularios-btn-perigo" data-acao="excluir-form" data-id="' + f.id + '"><i class="ti ti-trash"></i> Excluir</button>';
            h += '</div>';
        }
        return h + '</section>';
    }

    // ----- Eventos do painel -----

    function pnLigar(box, d) {
        var formId    = (d.form || {}).id || 0;
        var destinoId = (d.destino || {}).id || 0;

        // Cabecalho
        box.querySelectorAll('[data-pn-acao]').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                var acao = b.getAttribute('data-pn-acao');
                if (acao === 'fechar') { pnFechar(); }
                else if (acao === 'recarregar') { pnRecarregar(); }
                else if (acao === 'builder' || acao === 'condicional') { pnMostrarAba(box, d, 'estrutura'); }
                else if (acao === 'destinos') { modalConfigFormulario(formId, (d.form || {}).nome || '', { abaInicial: 'destinos' }); }
                else if (acao === 'criar-destino') {
                    var tipo = (CFG.tiposDestino && CFG.tiposDestino.length) ? CFG.tiposDestino[0].classe : '';
                    if (!tipo) { toast('Nenhum tipo de destino disponivel nesta instalacao.', false); return; }
                    ajax('destino_criar', { form: formId, classe: tipo, nome: 'Chamado' }).then(function (r) {
                        toast(r.message, !!(r && r.success));
                        if (r && r.success) { pnRecarregar(); }
                    });
                }
            });
        });

        // Abas do acordeao
        box.querySelectorAll('[data-pn-aba]').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                pnMostrarAba(box, d, b.getAttribute('data-pn-aba'));
            });
        });

        // Switch de ativar/desativar
        var sw = box.querySelector('[data-pn-ativo]');
        if (sw) {
            sw.addEventListener('click', function (e) { e.stopPropagation(); });
            sw.addEventListener('change', function () {
                var querAtivo = sw.checked;
                ajax('alternar_ativo_formulario', { id: formId, ativo: querAtivo ? 1 : 0 }).then(function (r) {
                    var real = (r && typeof r.ativo !== 'undefined') ? !!r.ativo : querAtivo;
                    toast((r && r.message) || 'Falha.', !!(r && r.success));
                    aplicarAtivoNaTela(formId, real, r && r.rascunho);
                });
            });
        }

        // Exibicao, subentidades e fixado: salvam na hora pela barra
        box.querySelectorAll('[data-pn-geral-campo]').forEach(function (el) {
            el.addEventListener('click', function (e) { e.stopPropagation(); });
            el.addEventListener('change', function () {
                var campo = el.getAttribute('data-pn-geral-campo');
                var dados = {};
                dados[campo] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
                ajax('geral_salvar', { form: formId, dados_json: JSON.stringify(dados) }).then(function (r) {
                    toast(r && r.success ? 'Formulario atualizado.' : ((r && r.message) || 'Falha.'), !!(r && r.success));
                    if (r && r.success) {
                        if (estado.painelDados && estado.painelDados.form) { estado.painelDados.form[campo] = el.type === 'checkbox' ? el.checked : el.value; }
                        if (campo !== 'layout') { recarregarArvore(); }
                    } else if (el.type === 'checkbox') {
                        el.checked = !el.checked;
                    }
                });
            });
        });

        // Atores alterados pela aba Geral: a aba Chamado gerado e redesenhada ao ser aberta
        box.addEventListener('cf-atores-mudaram', function () { box._atoresSujos = true; });

        // Campos escalares do chamado
        box.querySelectorAll('.catalogoeformularios-pn-campo[data-pn-campo]').forEach(function (row) {
            var btn = row.querySelector('[data-pn-editar-campo]');
            if (!btn) { return; }
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var editor = row.querySelector('.catalogoeformularios-pn-editor');
                var abrindo = editor.style.display === 'none';
                // Um editor de campo por vez, para o painel nao virar uma parede de selects.
                box.querySelectorAll('.catalogoeformularios-pn-campo .catalogoeformularios-pn-editor').forEach(function (ed) {
                    if (ed !== editor) { ed.style.display = 'none'; }
                });
                box.querySelectorAll('.catalogoeformularios-pn-campo').forEach(function (r2) {
                    if (r2 !== row) { r2.classList.remove('editando'); }
                });
                editor.style.display = abrindo ? 'block' : 'none';
                row.classList.toggle('editando', abrindo);
                if (abrindo) { pnMontarEditorCampo(row, formId, destinoId, d); }
            });
        });

        // Atores
        box.querySelectorAll('.catalogoeformularios-pn-ator[data-pn-ator]').forEach(function (bloco) {
            var btn = bloco.querySelector('[data-pn-editar-ator]');
            if (!btn) { return; }
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var editor = bloco.querySelector('.catalogoeformularios-pn-editor');
                var abrindo = editor.style.display === 'none';
                editor.style.display = abrindo ? 'block' : 'none';
                bloco.classList.toggle('editando', abrindo);
                if (abrindo) { pnMontarEditorAtor(bloco, formId, destinoId, d); }
            });
        });

        // Acesso
        var btnAcesso = box.querySelector('[data-pn-editar-acesso]');
        if (btnAcesso) {
            btnAcesso.addEventListener('click', function (e) {
                e.stopPropagation();
                var editor = box.querySelector('[data-pn-editor-acesso]');
                var abrindo = editor.style.display === 'none';
                editor.style.display = abrindo ? 'block' : 'none';
                if (abrindo) { pnMontarEditorAcesso(editor, formId, d); }
            });
        }
    }

    /** Editor em linha de um campo escalar do destino. */
    function pnMontarEditorCampo(row, formId, destinoId, d) {
        if (row.getAttribute('data-montado') === '1') { return; }
        row.setAttribute('data-montado', '1');

        var editor  = row.querySelector('.catalogoeformularios-pn-editor');
        var slug    = row.getAttribute('data-pn-campo');
        var fonte   = row.getAttribute('data-fonte');
        var estr    = row.getAttribute('data-estrategia') || 'modelo';
        var valSalv = row.getAttribute('data-valor') || '0';
        var qSalva  = row.getAttribute('data-question') || '0';
        var uid     = 'pnc_' + formId + '_' + slug;

        var ops = '';
        PN_ESTRATEGIAS.forEach(function (o) {
            ops += '<option value="' + o.v + '"' + (o.v === estr ? ' selected' : '') + '>' + esc(o.t) + '</option>';
        });

        editor.innerHTML = '<div class="catalogoeformularios-pn-editor-linha">'
            + '<select class="catalogoeformularios-select pn-estrat">' + ops + '</select>'
            + '<div class="catalogoeformularios-pn-editor-valor"></div>'
            + '</div>'
            + '<div class="catalogoeformularios-pn-editor-btns">'
            + '<button type="button" class="catalogoeformularios-btn catalogoeformularios-btn-primario pn-salvar"><i class="ti ti-device-floppy"></i> Salvar</button>'
            + '<button type="button" class="catalogoeformularios-btn pn-limpar" title="Remover a configuracao e voltar ao padrao do GLPI"><i class="ti ti-eraser"></i> Limpar</button>'
            + '</div>';

        var sel  = editor.querySelector('.pn-estrat');
        var area = editor.querySelector('.catalogoeformularios-pn-editor-valor');

        function montarValor() {
            var e = sel.value;
            if (e === 'modelo') {
                area.innerHTML = '<span class="catalogoeformularios-pn-vazio-txt">o GLPI decide pelo modelo / padrao</span>';
                return;
            }
            if (e === 'resposta') {
                var perg = (d.perguntas || []).slice();
                if (!perg.length) {
                    area.innerHTML = '<span class="catalogoeformularios-pn-vazio-txt">este formulario ainda nao tem perguntas</span>';
                    return;
                }
                area.innerHTML = campoSelectBusca(uid + '_q', '', perg, qSalva);
                area.querySelectorAll('.catalogoeformularios-selbusca').forEach(ativarSelectBusca);
                return;
            }
            area.innerHTML = '<span class="catalogoeformularios-pn-vazio-txt">carregando opcoes...</span>';
            carregarFonte(fonte).then(function (lista) {
                var opcoes = [{ v: 0, t: '(nao definido)' }].concat(
                    (lista || []).map(function (o) { return { v: o.v, t: o.t }; })
                );
                area.innerHTML = campoSelectBusca(uid + '_v', '', opcoes, valSalv);
                area.querySelectorAll('.catalogoeformularios-selbusca').forEach(ativarSelectBusca);
            });
        }
        sel.addEventListener('change', montarValor);
        montarValor();

        editor.querySelector('.pn-salvar').addEventListener('click', function () {
            var e = sel.value;
            var params = { form: formId, destino: destinoId, campo: slug, estrategia: e, valor: 0, question: 0 };
            if (e === 'especifico') {
                var hv = document.getElementById(uid + '_v');
                params.valor = hv ? (parseInt(hv.value, 10) || 0) : 0;
            } else if (e === 'resposta') {
                var hq = document.getElementById(uid + '_q');
                params.question = hq ? (parseInt(hq.value, 10) || 0) : 0;
            }
            ajax('painel_campo', params).then(function (r) {
                toast((r && r.message) || 'Falha.', !!(r && r.success));
                if (r && r.success) { pnRecarregar(); }
            });
        });

        editor.querySelector('.pn-limpar').addEventListener('click', function () {
            ajax('painel_campo', { form: formId, destino: destinoId, campo: slug, estrategia: 'limpar' }).then(function (r) {
                toast((r && r.message) || 'Falha.', !!(r && r.success));
                if (r && r.success) { pnRecarregar(); }
            });
        });
    }

    /** Editor em linha dos atores (usuarios + grupos) de um destino. */
    function pnMontarEditorAtor(bloco, formId, destinoId, d) {
        if (bloco.getAttribute('data-montado') === '1') { return; }
        bloco.setAttribute('data-montado', '1');

        var slug   = bloco.getAttribute('data-pn-ator');
        var editor = bloco.querySelector('.catalogoeformularios-pn-editor');
        var uid    = 'pna_' + formId + '_' + slug;
        var dados  = null;
        (d.atores || []).forEach(function (a) { if (a.slug === slug) { dados = a; } });
        dados = dados || { usuarios: [], grupos: [] };

        editor.innerHTML = '<div class="catalogoeformularios-pn-duas-colunas">'
            + '<div><span class="catalogoeformularios-pn-sub"><i class="ti ti-user"></i> Usuarios</span>' + multiBusca(uid + '_u', [], []) + '</div>'
            + '<div><span class="catalogoeformularios-pn-sub"><i class="ti ti-users"></i> Grupos</span>' + multiBusca(uid + '_g', [], []) + '</div>'
            + '</div>'
            + '<div class="catalogoeformularios-pn-editor-btns">'
            + '<button type="button" class="catalogoeformularios-btn catalogoeformularios-btn-primario pn-salvar"><i class="ti ti-device-floppy"></i> Salvar</button>'
            + '<button type="button" class="catalogoeformularios-btn pn-limpar" title="Remover e voltar ao padrao do GLPI"><i class="ti ti-eraser"></i> Limpar</button>'
            + '</div>';

        carregarFonte('atores').then(function (at) {
            var cu = editor.querySelector('#' + uid + '_u');
            var cg = editor.querySelector('#' + uid + '_g');
            if (cu) {
                preencherMultiBusca(cu, (at && at.usuarios) || [], (dados.usuarios || []).map(function (x) { return x.v; }));
                ativarMultiBusca(cu);
            }
            if (cg) {
                preencherMultiBusca(cg, (at && at.grupos) || [], (dados.grupos || []).map(function (x) { return x.v; }));
                ativarMultiBusca(cg);
            }
        });

        function salvar(limpar) {
            var cu = editor.querySelector('#' + uid + '_u');
            var cg = editor.querySelector('#' + uid + '_g');
            ajax('painel_atores', {
                form: formId,
                destino: destinoId,
                ator: slug,
                usuarios: limpar ? [] : (cu ? coletarMultiBusca(cu) : []),
                grupos:   limpar ? [] : (cg ? coletarMultiBusca(cg) : [])
            }).then(function (r) {
                toast((r && r.message) || 'Falha.', !!(r && r.success));
                if (r && r.success) { pnRecarregar(); }
            });
        }
        editor.querySelector('.pn-salvar').addEventListener('click', function () { salvar(false); });
        editor.querySelector('.pn-limpar').addEventListener('click', function () { salvar(true); });
    }

    /** Editor em linha de quem visualiza o formulario. */
    function pnMontarEditorAcesso(editor, formId, d) {
        if (editor.getAttribute('data-montado') === '1') { return; }
        editor.setAttribute('data-montado', '1');

        var a   = d.acesso || {};
        var uid = 'pnac_' + formId;

        editor.innerHTML =
              '<label class="catalogoeformularios-pn-check"><input type="checkbox" id="' + uid + '_todos"' + (a.todos ? ' checked' : '') + '> '
            + '<span>Liberar para TODOS os usuarios autenticados</span></label>'
            + '<div id="' + uid + '_listas"' + (a.todos ? ' style="display:none;"' : '') + '>'
            + '<span class="catalogoeformularios-pn-sub"><i class="ti ti-shield"></i> Perfis</span>' + multiBusca(uid + '_p', [], [])
            + '<span class="catalogoeformularios-pn-sub"><i class="ti ti-users"></i> Grupos</span>'  + multiBusca(uid + '_g', [], [])
            + '<span class="catalogoeformularios-pn-sub"><i class="ti ti-user"></i> Usuarios</span>' + multiBusca(uid + '_u', [], [])
            + '</div>'
            + '<label class="catalogoeformularios-pn-check"><input type="checkbox" id="' + uid + '_ativo"' + (a.ativo ? ' checked' : '') + '> '
            + '<span>Manter a restricao de acesso ativa</span></label>'
            + '<p class="catalogoeformularios-pn-ajuda"><i class="ti ti-info-circle"></i> Ao escolher um perfil ou grupo, todos os usuarios dentro dele passam a ver o formulario no catalogo.</p>'
            + '<div class="catalogoeformularios-pn-editor-btns">'
            + '<button type="button" class="catalogoeformularios-btn catalogoeformularios-btn-primario pn-salvar"><i class="ti ti-device-floppy"></i> Salvar acesso</button>'
            + '</div>';

        var chkTodos = editor.querySelector('#' + uid + '_todos');
        var listas   = editor.querySelector('#' + uid + '_listas');
        chkTodos.addEventListener('change', function () {
            listas.style.display = chkTodos.checked ? 'none' : 'block';
        });

        Promise.all([carregarFonte('perfis'), carregarFonte('atores')]).then(function (res) {
            var perfis = res[0] || [];
            var atores = res[1] || { usuarios: [], grupos: [] };
            var cp = editor.querySelector('#' + uid + '_p');
            var cg = editor.querySelector('#' + uid + '_g');
            var cu = editor.querySelector('#' + uid + '_u');
            if (cp) { preencherMultiBusca(cp, perfis, (a.perfis || []).map(function (x) { return x.v; })); ativarMultiBusca(cp); }
            if (cg) { preencherMultiBusca(cg, atores.grupos || [], (a.grupos || []).map(function (x) { return x.v; })); ativarMultiBusca(cg); }
            if (cu) { preencherMultiBusca(cu, atores.usuarios || [], (a.usuarios || []).map(function (x) { return x.v; })); ativarMultiBusca(cu); }
        });

        editor.querySelector('.pn-salvar').addEventListener('click', function () {
            var cp = editor.querySelector('#' + uid + '_p');
            var cg = editor.querySelector('#' + uid + '_g');
            var cu = editor.querySelector('#' + uid + '_u');
            var todos = chkTodos.checked;
            ajax('painel_acesso', {
                form: formId,
                ativo: editor.querySelector('#' + uid + '_ativo').checked ? 1 : 0,
                todos: todos ? 1 : 0,
                perfis:   todos ? [] : (cp ? coletarMultiBusca(cp) : []),
                grupos:   todos ? [] : (cg ? coletarMultiBusca(cg) : []),
                usuarios: todos ? [] : (cu ? coletarMultiBusca(cu) : [])
            }).then(function (r) {
                toast((r && r.message) || 'Falha.', !!(r && r.success));
                if (r && r.success) {
                    pnRecarregar();
                    // A lista mostra os perfis com acesso, entao ela tambem precisa atualizar.
                    recarregarAtual({ silencioso: true });
                }
            });
        });
    }

    // -----------------------------------------------------------------
    // Status ativo/inativo refletido na hora (sem recarregar a pagina)
    // -----------------------------------------------------------------
    function aplicarAtivoNaTela(formId, ativo, rascunho) {
        // Linhas da area principal
        elConteudo.querySelectorAll('.catalogoeformularios-form-linha[data-abrir-form="' + formId + '"]').forEach(function (linha) {
            linha.setAttribute('data-ativo', ativo ? '1' : '0');
            var ponto = linha.querySelector('.ponto');
            if (ponto) { ponto.className = 'ponto ' + (ativo ? 'on' : 'off'); }
            var btn = linha.querySelector('[data-acao="toggle-form"]');
            if (btn) {
                btn.title = ativo ? 'Desativar' : 'Ativar';
                var i = btn.querySelector('i');
                if (i) { i.className = ativo ? 'ti ti-eye-off' : 'ti ti-eye'; }
            }
            var badge = linha.querySelector('.catalogoeformularios-badge');
            if (badge && !rascunho) {
                badge.className = 'catalogoeformularios-badge ' + (ativo ? 'catalogoeformularios-badge-ativo' : 'catalogoeformularios-badge-inativo');
                badge.textContent = ativo ? 'Ativo' : 'Inativo';
            }
        });

        // Arvore lateral
        elArvore.querySelectorAll('[data-nav-form="' + formId + '"]').forEach(function (no) {
            var ponto = no.querySelector('.ponto');
            if (ponto) { ponto.className = 'ponto ' + (ativo ? 'on' : 'off'); }
        });

        // Painel rapido aberto
        var pnSw = elConteudo.querySelector('.catalogoeformularios-pn[data-pn-form="' + formId + '"] [data-pn-ativo]');
        if (pnSw) {
            pnSw.checked = !!ativo;
            var rot = pnSw.closest('.catalogoeformularios-pn-switch').querySelector('[data-pn-ativo-rot]');
            if (rot) { rot.textContent = ativo ? 'Ativo' : 'Inativo'; }
        }

        // Modo builder: badge do cabecalho e botao da barra de acoes
        if (estado.modo === 'builder' && estado.formId === formId) {
            if (estado.detalhe) { estado.detalhe.ativo = !!ativo; }
            var bBadge = elConteudo.querySelector('.catalogoeformularios-form-info .catalogoeformularios-badge');
            if (bBadge) {
                bBadge.className = 'catalogoeformularios-badge ' + (ativo ? 'catalogoeformularios-badge-ativo' : 'catalogoeformularios-badge-inativo');
                bBadge.textContent = ativo ? 'Ativo' : 'Inativo';
            }
            var bBtn = elAcoes.querySelector('[data-acao="toggle-form-builder"]');
            if (bBtn) {
                bBtn.innerHTML = '<i class="' + (ativo ? 'ti ti-eye-off' : 'ti ti-eye') + '"></i> ' + (ativo ? 'Desativar' : 'Ativar');
            }
        }

        // Estado em memoria da lista, para o proximo re-render nao voltar o valor antigo.
        var c = estado.conteudo || {};
        function marcar(arr) {
            (arr || []).forEach(function (f) { if (f.id === formId) { f.ativo = !!ativo; } });
        }
        function andar(cat) { marcar(cat.formularios); (cat.subcategorias || []).forEach(andar); }
        (c.categorias || []).forEach(andar);
        marcar(c.formularios);

        agendarPreview();
    }

    // -----------------------------------------------------------------
    // Navegador de itens: anda entre os formularios do catalogo atual
    // -----------------------------------------------------------------
    function navLista() {
        var out = [];
        var c = estado.conteudo || {};
        function empilhar(arr) {
            (arr || []).forEach(function (f) { out.push({ id: f.id, nome: f.nome }); });
        }
        function andar(cat) {
            empilhar(cat.formularios);
            (cat.subcategorias || []).forEach(andar);
        }
        (c.categorias || []).forEach(andar);
        empilhar(c.formularios);
        return out;
    }

    function navFormAtual() {
        if (estado.modo === 'builder') { return estado.formId || 0; }
        return estado.painelForm || 0;
    }

    function navIndice(lista, formId) {
        var idx = -1;
        lista.forEach(function (f, i) { if (f.id === formId) { idx = i; } });
        return idx;
    }

    function renderNavegador() {
        var box = document.getElementById('cat-navegador');
        if (!box) { return; }
        var lista = navLista();
        var atual = navFormAtual();
        var idx = atual ? navIndice(lista, atual) : -1;

        if (idx === -1) { box.style.display = 'none'; return; }

        box.style.display = '';
        var info = document.getElementById('cat-nav-info');
        if (info) { info.textContent = (idx + 1) + ' / ' + lista.length; }
        var nome = document.getElementById('cat-nav-nome');
        if (nome) {
            nome.textContent = lista[idx].nome || '';
            nome.title = lista[idx].nome || '';
        }
        box.querySelectorAll('[data-nav-passo]').forEach(function (b) {
            var passo = parseInt(b.getAttribute('data-nav-passo'), 10) || 0;
            var alvo = idx + passo;
            b.disabled = (alvo < 0 || alvo >= lista.length);
        });
    }

    function navegarFormulario(passo) {
        var lista = navLista();
        var atual = navFormAtual();
        var idx = navIndice(lista, atual);
        if (idx === -1) { return; }
        var alvo = idx + passo;
        if (alvo < 0 || alvo >= lista.length) { return; }
        var id = lista[alvo].id;
        if (estado.modo === 'builder') { carregarBuilder(id); }
        else { pnAbrir(id, { rolar: true }); }
    }

    // -----------------------------------------------------------------
    // Matriz do catalogo: todos os formularios do nivel numa tabela
    // -----------------------------------------------------------------
    function modalMatriz() {
        var m = abrirModal({ titulo: '<i class="ti ti-table"></i> Matriz do catalogo', grande: true });
        m.body.innerHTML =
              '<p class="catalogoeformularios-pn-ajuda"><i class="ti ti-info-circle"></i> Todos os formularios desta categoria e das categorias abaixo dela, com o que cada um aplica no chamado gerado. Use a barra abaixo para aplicar um mesmo valor em varios formularios de uma vez.</p>'
            + (podeEditar ? matrizLoteHtml() : '')
            + '<div id="cat-matriz">' + carregando() + '</div>';
        m.foot.appendChild(botaoFoot('Fechar', '', m.fechar));

        var cont = m.body.querySelector('#cat-matriz');

        function carregar() {
            cont.innerHTML = carregando();
            ajax('painel_matriz', { categoria: estado.categoria }).then(function (r) {
                if (!r || !r.success || !r.dados) {
                    cont.innerHTML = vazio('ti ti-alert-triangle', (r && r.message) || 'Falha ao carregar a matriz.');
                    return;
                }
                cont.innerHTML = matrizTabelaHtml(r.dados.linhas || []);
                cont.querySelectorAll('[data-mx-painel]').forEach(function (b) {
                    b.addEventListener('click', function () {
                        var id = parseInt(b.getAttribute('data-mx-painel'), 10);
                        m.fechar();
                        pnAbrir(id, { rolar: true });
                    });
                });
                var todos = cont.querySelector('[data-mx-todos]');
                if (todos) {
                    todos.addEventListener('change', function () {
                        cont.querySelectorAll('[data-mx-sel]').forEach(function (c) { c.checked = todos.checked; });
                    });
                }
            });
        }

        if (podeEditar) { ligarMatrizLote(m.body, cont, carregar); }
        carregar();
    }

    function matrizLoteHtml() {
        var ops = PN_LOTE.map(function (o) { return '<option value="' + o.v + '">' + esc(o.t) + '</option>'; }).join('');
        return '<div class="catalogoeformularios-mx-lote">'
            + '<span class="catalogoeformularios-pn-sub"><i class="ti ti-sum"></i> Aplicar aos marcados</span>'
            + '<select class="catalogoeformularios-select" id="mx_lote_campo">' + ops + '</select>'
            + '<div class="catalogoeformularios-mx-lote-valor" id="mx_lote_valor"></div>'
            + '<button type="button" class="catalogoeformularios-btn catalogoeformularios-btn-primario" id="mx_lote_aplicar"><i class="ti ti-check"></i> Aplicar</button>'
            + '</div>';
    }

    function ligarMatrizLote(body, cont, recarregar) {
        var selCampo = body.querySelector('#mx_lote_campo');
        var areaVal  = body.querySelector('#mx_lote_valor');
        if (!selCampo || !areaVal) { return; }

        function fonteDe(slug) {
            var f = '';
            PN_LOTE.forEach(function (o) { if (o.v === slug) { f = o.fonte; } });
            return f;
        }

        function montar() {
            areaVal.innerHTML = '<span class="catalogoeformularios-pn-vazio-txt">carregando...</span>';
            carregarFonte(fonteDe(selCampo.value)).then(function (lista) {
                var opcoes = [{ v: 0, t: '(voltar ao padrao)' }].concat(
                    (lista || []).map(function (o) { return { v: o.v, t: o.t }; })
                );
                areaVal.innerHTML = campoSelectBusca('mx_lote_v', '', opcoes, 0);
                areaVal.querySelectorAll('.catalogoeformularios-selbusca').forEach(ativarSelectBusca);
            });
        }
        selCampo.addEventListener('change', montar);
        montar();

        body.querySelector('#mx_lote_aplicar').addEventListener('click', function () {
            var ids = [];
            cont.querySelectorAll('[data-mx-sel]:checked').forEach(function (c) { ids.push(c.value); });
            if (!ids.length) { toast('Marque ao menos um formulario na tabela.', false); return; }
            var hv = document.getElementById('mx_lote_v');
            var valor = hv ? (parseInt(hv.value, 10) || 0) : 0;
            confirmar('Aplicar este valor em ' + ids.length + ' formulario(s)?', function () {
                ajax('painel_campo_lote', {
                    forms: ids,
                    campo: selCampo.value,
                    estrategia: valor > 0 ? 'especifico' : 'limpar',
                    valor: valor
                }).then(function (r) {
                    toast((r && r.message) || 'Falha.', !!(r && r.success));
                    recarregar();
                    recarregarAtual({ silencioso: true });
                });
            }, { rotulo: '<i class="ti ti-check"></i> Aplicar', classe: 'catalogoeformularios-btn-primario' });
        });
    }

    function matrizCelula(txt) {
        return txt && String(txt).trim() !== ''
            ? '<span class="catalogoeformularios-mx-val" title="' + esc(txt) + '">' + esc(txt) + '</span>'
            : '<span class="catalogoeformularios-mx-vazio">padrao</span>';
    }

    function matrizTabelaHtml(linhas) {
        if (!linhas.length) {
            return vazio('ti ti-table', 'Nenhum formulario nesta categoria.');
        }
        var h = '<div class="catalogoeformularios-mx-wrap"><table class="catalogoeformularios-mx">';
        h += '<thead><tr>';
        if (podeEditar) { h += '<th class="mini"><input type="checkbox" data-mx-todos="1" title="Marcar / desmarcar todos"></th>'; }
        h += '<th>Formulario</th><th>Categoria</th><th>Status</th><th>Categoria ITIL</th>'
           + '<th>SLA atendimento</th><th>SLA solucao</th><th>Requerente</th><th>Observador</th><th>Atribuido</th>'
           + '<th>Visualizam</th><th class="mini">Painel</th></tr></thead><tbody>';

        linhas.forEach(function (l) {
            h += '<tr' + (l.sem_destino ? ' class="sem-destino"' : '') + '>';
            if (podeEditar) { h += '<td class="mini"><input type="checkbox" data-mx-sel="1" value="' + l.id + '"></td>'; }
            h += '<td class="nome"><i class="ti ti-file-text"></i> <span>' + esc(l.nome) + '</span>'
               + (l.sem_destino ? ' <i class="ti ti-alert-triangle" title="Sem destino de chamado"></i>' : '') + '</td>';
            h += '<td>' + matrizCelula(l.categoria) + '</td>';
            h += '<td>' + (l.rascunho
                    ? '<span class="catalogoeformularios-badge catalogoeformularios-badge-rascunho">Rascunho</span>'
                    : (l.ativo ? '<span class="catalogoeformularios-badge catalogoeformularios-badge-ativo">Ativo</span>'
                               : '<span class="catalogoeformularios-badge catalogoeformularios-badge-inativo">Inativo</span>')) + '</td>';
            h += '<td>' + matrizCelula(l.itil) + '</td>';
            h += '<td>' + matrizCelula(l.sla_tto) + '</td>';
            h += '<td>' + matrizCelula(l.sla_ttr) + '</td>';
            h += '<td>' + matrizCelula(l.requerente) + '</td>';
            h += '<td>' + matrizCelula(l.observador) + '</td>';
            h += '<td>' + matrizCelula(l.atribuido) + '</td>';
            var p = l.perfis || {};
            h += '<td>' + (p.todos ? matrizCelula('todos os perfis') : matrizCelula((p.nomes || []).join(', '))) + '</td>';
            h += '<td class="mini"><button type="button" class="catalogoeformularios-btn-icone" data-mx-painel="' + l.id + '" title="Abrir o painel deste formulario"><i class="ti ti-list-details"></i></button></td>';
            h += '</tr>';
        });

        return h + '</tbody></table></div>';
    }

    // -----------------------------------------------------------------
    // Delegacao de eventos
    // -----------------------------------------------------------------
    elTrilha.addEventListener('click', function (e) {
        var b = e.target.closest('[data-nav-cat]');
        if (b) { carregarNivel(parseInt(b.getAttribute('data-nav-cat'), 10)); }
    });

    elArvore.addEventListener('click', function (e) {
        // Botoes de acao (duplicar/excluir) tem prioridade sobre a navegacao.
        var btnAcao = e.target.closest('[data-acao]');
        if (btnAcao) {
            e.stopPropagation();
            e.preventDefault();
            tratarAcaoItem(btnAcao);
            return;
        }
        var tog = e.target.closest('.catalogoeformularios-no-toggle');
        if (tog) {
            var no = tog.closest('.catalogoeformularios-no');
            var filhos = no ? no.querySelector('.catalogoeformularios-no-filhos') : null;
            if (filhos) {
                var aberto = filhos.style.display !== 'none';
                filhos.style.display = aberto ? 'none' : 'block';
                var ic = tog.querySelector('i');
                if (ic) { ic.className = aberto ? 'ti ti-chevron-right' : 'ti ti-chevron-down'; }
                // CRITICO: sem registrar no estado, qualquer re-render (excluir,
                // duplicar, criar, mover) devolvia a arvore ao estado inicial.
                marcarArvoreAberto(no.getAttribute('data-cat'), !aberto);
            }
            return;
        }
        var cab = e.target.closest('[data-nav-cat]');
        if (cab) { carregarNivel(parseInt(cab.getAttribute('data-nav-cat'), 10)); return; }
        var frm = e.target.closest('[data-nav-form]');
        if (frm) { carregarBuilder(parseInt(frm.getAttribute('data-nav-form'), 10)); }
    });

    var _btnArvoreExpandir = document.getElementById('cat-arvore-expandir');
    if (_btnArvoreExpandir) { _btnArvoreExpandir.addEventListener('click', alternarArvoreTudo); }
    document.getElementById('cat-arvore-busca').addEventListener('input', function () { aplicarFiltroArvore(this.value); });

    var _btnGridExpandir = document.getElementById('cat-grid-expandir');
    if (_btnGridExpandir) { _btnGridExpandir.addEventListener('click', alternarGruposTudo); }

    // Navegador de itens (setas para andar entre os formularios do catalogo).
    var _elNavegador = document.getElementById('cat-navegador');
    if (_elNavegador) {
        _elNavegador.addEventListener('click', function (e) {
            var b = e.target.closest('[data-nav-passo]');
            if (!b || b.disabled) { return; }
            navegarFormulario(parseInt(b.getAttribute('data-nav-passo'), 10) || 0);
        });
    }

    var _previewFrame = previewFrame();
    if (_previewFrame) {
        _previewFrame.addEventListener('load', function () {
            var st = document.getElementById('cat-preview-status');
            if (st) { st.textContent = ''; }
            limparCromoPreview(_previewFrame);
            previewRevelar();
            [150, 600, 1500].forEach(function (ms) {
                setTimeout(function () { limparCromoPreview(_previewFrame); }, ms);
            });
        });

        // Comeca a vigiar imediatamente (carga inicial da pagina).
        previewVigiar();
    }
    var _btnPreviewRec = document.getElementById('cat-preview-recarregar');
    if (_btnPreviewRec) { _btnPreviewRec.addEventListener('click', recarregarPreview); }

    // ---------- Minimizar / restaurar a previa (estado persiste entre recargas) ----------
    var CAT_PREVIEW_MIN_KEY = 'catalogoeformularios_preview_minimizado';

    function previewMinimizadoSalvo() {
        try { return window.localStorage.getItem(CAT_PREVIEW_MIN_KEY) === '1'; } catch (e) { return false; }
    }
    function previewSalvarMinimizado(min) {
        try { window.localStorage.setItem(CAT_PREVIEW_MIN_KEY, min ? '1' : '0'); } catch (e) {}
    }
    function previewEstaMinimizado() {
        var split = document.querySelector('.catalogoeformularios-split');
        return !!(split && split.classList.contains('catalogoeformularios-preview-minimizado'));
    }
    function previewAplicarMinimizado(min) {
        var split = document.querySelector('.catalogoeformularios-split');
        var btn   = document.getElementById('cat-preview-minimizar');
        var st    = document.getElementById('cat-preview-status');

        if (split) {
            if (min) { split.classList.add('catalogoeformularios-preview-minimizado'); }
            else { split.classList.remove('catalogoeformularios-preview-minimizado'); }
        }
        if (btn) {
            btn.title     = min ? 'Restaurar previa' : 'Minimizar previa';
            btn.innerHTML = '<i class="ti ' + (min ? 'ti-arrows-maximize' : 'ti-arrows-minimize') + '"></i>';
        }
        if (st && min) { st.textContent = ''; }
    }

    var _btnPreviewMin = document.getElementById('cat-preview-minimizar');
    if (_btnPreviewMin) {
        _btnPreviewMin.addEventListener('click', function () {
            var min = !previewEstaMinimizado();
            previewSalvarMinimizado(min);
            previewAplicarMinimizado(min);
            // Ao restaurar, recarrega a previa para refletir alteracoes feitas enquanto estava oculta.
            if (!min) { recarregarPreview(); }
        });
    }

    // Sincroniza icone/titulo do botao com o estado ja aplicado pelo inline da pagina.
    previewAplicarMinimizado(previewMinimizadoSalvo());

    elAcoes.addEventListener('click', function (e) {
        var b = e.target.closest('[data-acao]');
        if (!b) { return; }
        var acao = b.getAttribute('data-acao');
        if (acao === 'nova-cat') { modalCategoria(null); }
        else if (acao === 'transformar') { modalEscolherTransformar(); }
        else if (acao === 'transformar-cat') { modalTransformarCategorias(); }
        else if (acao === 'transformar-itil') { modalTransformarItil(); }
        else if (acao === 'novo-form') { modalFormulario(null); }
        else if (acao === 'voltar') { carregarNivel(estado.categoria); }
        else if (acao === 'nova-secao') { modalSecao(null); }
        else if (acao === 'editar-form-builder') { modalFormulario(estado.detalhe); }
        else if (acao === 'config-form-builder') { modalConfigFormulario(estado.formId, estado.detalhe ? estado.detalhe.nome : ''); }
        else if (acao === 'matriz') { modalMatriz(); }
        else if (acao === 'toggle-form-builder') {
            var querAtivoB = !(estado.detalhe && estado.detalhe.ativo);
            var idB = estado.formId;
            aplicarAtivoNaTela(idB, querAtivoB, false);
            ajax('alternar_ativo_formulario', { id: idB, ativo: querAtivoB ? 1 : 0 }).then(function (r) {
                var real = (r && typeof r.ativo !== 'undefined') ? !!r.ativo : querAtivoB;
                toast((r && r.message) || 'Falha.', !!(r && r.success));
                aplicarAtivoNaTela(idB, real, !!(r && r.rascunho));
            });
        }
    });

    elConteudo.addEventListener('click', function (e) {
        // Botao de ramo: precisa vir antes de tudo, senao o clique cai no acordeon do cabecalho.
        var ramo = e.target.closest('[data-expandir-ramo]');
        if (ramo) {
            e.stopPropagation();
            alternarRamo(ramo.closest('.catalogoeformularios-subcat') || ramo.closest('.catalogoeformularios-grupo'));
            return;
        }
        var btn = e.target.closest('[data-acao]');
        if (btn) { e.stopPropagation(); tratarAcaoItem(btn); return; }
        var abrirForm = e.target.closest('[data-abrir-form]');
        if (abrirForm) { pnAlternar(parseInt(abrirForm.getAttribute('data-abrir-form'), 10)); return; }
        // Navegar para dentro da categoria so acontece pelo botao dedicado (seta),
        // por isso este teste vem antes dos acordeons.
        var abrirCat = e.target.closest('[data-abrir-cat]');
        if (abrirCat) { carregarNivel(parseInt(abrirCat.getAttribute('data-abrir-cat'), 10)); return; }
        // Clique em qualquer ponto da barra de titulo da subcategoria: so alterna o acordeon.
        var acordSub = e.target.closest('[data-acordeon-sub]');
        if (acordSub) {
            e.stopPropagation();
            var sub = acordSub.closest('.catalogoeformularios-subcat');
            if (sub) {
                var corpoSub = sub.querySelector(':scope > .catalogoeformularios-subcat-corpo');
                aplicarEstadoSubcat(sub, !!(corpoSub && corpoSub.style.display === 'none'));
            }
            return;
        }
        var acord = e.target.closest('[data-acordeon]');
        if (acord) {
            var grupo = acord.closest('.catalogoeformularios-grupo');
            var corpo = grupo ? grupo.querySelector('.catalogoeformularios-grupo-corpo') : null;
            // Passa pelo aplicarEstadoGrupo para o estado ficar registrado e
            // sobreviver aos re-renders.
            if (corpo) { aplicarEstadoGrupo(grupo, corpo.style.display === 'none'); }
            return;
        }
        var card = e.target.closest('.catalogoeformularios-card.clicavel');
        if (card) {
            var id = parseInt(card.getAttribute('data-id'), 10);
            if (card.getAttribute('data-tipo') === 'cat') { carregarNivel(id); } else { carregarBuilder(id); }
        }
    });

    function tratarAcaoItem(btn) {
        var acao = btn.getAttribute('data-acao');
        var id = parseInt(btn.getAttribute('data-id'), 10);
        switch (acao) {
            case 'novo-form-cat': modalFormulario(null, id); break;
            case 'editar-cat': abrirEdicaoCategoria(id); break;
            case 'duplicar-cat':
                confirmar('Duplicar esta categoria com TODA a estrutura (subcategorias e formularios) dentro dela?', function () {
                    toast('Duplicando... aguarde.', true);
                    ajax('duplicar_categoria', { id: id }).then(function (r) { toast(r.message, !!(r && r.success)); if (r && r.success) { recarregarAtual({ silencioso: true }); } });
                }, { rotulo: '<i class="ti ti-copy"></i> Duplicar', classe: 'catalogoeformularios-btn-primario' });
                break;
            case 'excluir-cat':
                confirmar('Excluir esta categoria? ATENCAO: todas as subcategorias e todos os formularios dentro dela tambem serao excluidos. Esta acao nao pode ser desfeita.', function () {
                    // Cadeia da categoria que vai sumir e da que esta sendo exibida:
                    // se o usuario estava dentro dela (ou em algo abaixo), sobe para o
                    // pai; caso contrario permanece exatamente onde estava.
                    var cadeia     = caminhoNaArvore(id);
                    var pai        = cadeia.length > 1 ? cadeia[cadeia.length - 2] : 0;
                    var afetaAtual = (estado.categoria === id)
                                  || (caminhoNaArvore(estado.categoria).indexOf(id) !== -1);
                    toast('Excluindo... aguarde.', true);
                    ajax('excluir_categoria', { id: id }).then(function (r) {
                        toast(r.message, !!(r && r.success));
                        if (!r || !r.success) { return; }
                        delete estado.arvoreAbertos[id];
                        delete estado.gridAbertos[id];
                        if (afetaAtual) { estado.categoria = pai; }
                        recarregarAtual({ silencioso: true });
                    });
                });
                break;
            case 'config-form': modalConfigFormulario(id, nomeFormNoNivel(id)); break;
            case 'config-campos': pnAbrirAba(id, 'chamado'); break;
            case 'editar-form': pnAbrirAba(id, 'geral'); break;
            case 'painel-form': pnAlternar(id); break;
            case 'abrir-form-builder': pnAbrirAba(id, 'estrutura'); break;
            case 'toggle-form':
                var linhaForm = btn.closest('[data-ativo]');
                var cardForm  = btn.closest('.catalogoeformularios-card');
                var estaAtivo = linhaForm
                    ? linhaForm.getAttribute('data-ativo') === '1'
                    : !!(cardForm && cardForm.querySelector('.catalogoeformularios-badge-ativo'));
                var querAtivo = !estaAtivo;
                // Reflete na hora e depois confirma com o estado real que o servidor devolveu:
                // o icone nunca mais depende de recarregar a pagina.
                aplicarAtivoNaTela(id, querAtivo, false);
                ajax('alternar_ativo_formulario', { id: id, ativo: querAtivo ? 1 : 0 }).then(function (r) {
                    var real = (r && typeof r.ativo !== 'undefined') ? !!r.ativo : querAtivo;
                    toast((r && r.message) || 'Falha.', !!(r && r.success));
                    aplicarAtivoNaTela(id, real, !!(r && r.rascunho));
                }).catch(function () {
                    aplicarAtivoNaTela(id, estaAtivo, false);
                    toast('Falha ao alterar o status.', false);
                });
                break;
            case 'duplicar-form':
                confirmar('Duplicar este formulario (secoes, perguntas, destinos e acesso)?', function () {
                    toast('Duplicando... aguarde.', true);
                    ajax('duplicar_formulario', { id: id }).then(function (r) { toast(r.message, !!(r && r.success)); if (r && r.success) { recarregarAtual({ silencioso: true }); } });
                }, { rotulo: '<i class="ti ti-copy"></i> Duplicar', classe: 'catalogoeformularios-btn-primario' });
                break;
            case 'excluir-form':
                confirmar('Excluir este formulario?', function () {
                    ajax('excluir_formulario', { id: id }).then(function (r) { toast(r.message, !!(r && r.success)); if (r && r.success) { recarregarAtual({ silencioso: true }); } });
                });
                break;
            case 'menu-form': abrirMenuFormulario(btn, id); break;
            case 'editar-secoes':
            case 'adicionar-secoes':
            case 'condicional-form':
                pnAbrirAba(id, 'estrutura');
                break;
            case 'editar-destinos': pnAbrirAba(id, 'chamado'); break;
            case 'editar-acesso':   pnAbrirAba(id, 'acesso'); break;
            case 'nova-pergunta': modalPergunta(id, null); break;
            case 'editar-secao': modalSecao(acharSecao(id)); break;
            case 'excluir-secao':
                confirmar('Excluir esta secao e suas perguntas?', function () {
                    ajax('excluir_secao', { id: id }).then(function (r) { toast(r.message, !!(r && r.success)); if (r && r.success) { carregarBuilder(estado.formId); } });
                });
                break;
            case 'editar-pergunta': modalPergunta(parseInt(btn.getAttribute('data-secao'), 10), acharPergunta(id)); break;
            case 'condicao-pergunta': modalCondicao(id); break;
            case 'excluir-pergunta':
                confirmar('Excluir esta pergunta?', function () {
                    ajax('excluir_pergunta', { id: id }).then(function (r) { toast(r.message, !!(r && r.success)); if (r && r.success) { carregarBuilder(estado.formId); } });
                });
                break;
        }
    }

    function nomeFormNoNivel(id) {
        var c = estado.conteudo || {};
        var lista = (c.formularios || []).slice();
        (c.categorias || []).forEach(function (cat) { (cat.formularios || []).forEach(function (f) { lista.push(f); }); });
        var f = lista.filter(function (x) { return x.id === id; })[0];
        return f ? f.nome : '';
    }

    // -----------------------------------------------------------------
    // Menu de acoes do formulario (barra do acordeao)
    // -----------------------------------------------------------------
    function fecharMenuFormulario() {
        var ex = document.getElementById('catalogoeformularios-menu-form');
        if (ex) { ex.remove(); }
    }

    function abrirMenuFormulario(botao, formId) {
        // Se ja estava aberto para o mesmo botao, fecha (comportamento de toggle).
        var atual = document.getElementById('catalogoeformularios-menu-form');
        if (atual && atual.getAttribute('data-form') === String(formId)) { fecharMenuFormulario(); return; }
        fecharMenuFormulario();
        fecharPopoverPerfis();

        var itens = [
            { acao: 'painel-form',        icone: 'ti ti-list-details', rot: 'Abrir / fechar o painel' },
            { acao: 'editar-form',        icone: 'ti ti-settings',     rot: 'Geral (nome, icone, descricao)' },
            { acao: 'abrir-form-builder', icone: 'ti ti-layout-list',  rot: 'Estrutura (secoes, perguntas, condicoes)' },
            { acao: 'config-campos',      icone: 'ti ti-ticket',       rot: 'Chamado gerado (campos, SLA, atores)' },
            { acao: 'editar-acesso',      icone: 'ti ti-shield',       rot: 'Quem visualiza' },
            { acao: 'duplicar-form',      icone: 'ti ti-copy',         rot: 'Duplicar' },
            { sep: true },
            { acao: 'excluir-form',     icone: 'ti ti-trash',       rot: 'Excluir', perigo: true }
        ];

        var menu = document.createElement('div');
        menu.id = 'catalogoeformularios-menu-form';
        menu.className = 'catalogoeformularios-menu-form';
        menu.setAttribute('data-form', String(formId));
        var html = '';
        itens.forEach(function (it) {
            if (it.sep) { html += '<div class="catalogoeformularios-menu-form-sep"></div>'; return; }
            html += '<button type="button" class="catalogoeformularios-menu-form-item' + (it.perigo ? ' perigo' : '') + '"'
                 + ' data-acao="' + it.acao + '" data-id="' + formId + '">'
                 + '<i class="' + it.icone + '"></i> <span>' + esc(it.rot) + '</span></button>';
        });
        menu.innerHTML = html;
        document.body.appendChild(menu);

        // Ancorado ao botao; alinhado a direita para nao estourar a janela.
        var r = botao.getBoundingClientRect();
        menu.style.top = (r.bottom + window.scrollY + 6) + 'px';
        menu.style.left = (r.left + window.scrollX) + 'px';
        requestAnimationFrame(function () {
            var mw = menu.offsetWidth;
            var novoLeft = r.right + window.scrollX - mw;
            if (novoLeft < 10) { novoLeft = 10; }
            menu.style.left = novoLeft + 'px';
            var mh = menu.offsetHeight;
            if (r.bottom + 6 + mh > document.documentElement.clientHeight - 8) {
                var acima = r.top + window.scrollY - mh - 6;
                if (acima > window.scrollY + 6) { menu.style.top = acima + 'px'; }
            }
        });

        menu.addEventListener('click', function (e) {
            var it = e.target.closest('[data-acao]');
            if (!it) { return; }
            e.stopPropagation();
            fecharMenuFormulario();
            tratarAcaoItem(it);
        });
    }

    // Editor dedicado de visibilidade condicional (lista secoes/perguntas do formulario).
    function modalVisibilidadeCondicional(det) {
        estado.formId = det.id;
        estado.detalhe = det;
        var m = abrirModal({ titulo: '<i class="ti ti-eye-check"></i> Visibilidade condicional: ' + esc(det.nome || ''), grande: true });
        m.body.innerHTML =
            '<p class="catalogoeformularios-ac-ajuda"><i class="ti ti-info-circle"></i> Defina em quais condicoes cada pergunta aparece no formulario. Clique no icone ao lado da pergunta para editar a regra de visibilidade.</p>'
          + '<div id="cat-cond-lista"></div>';
        var cont = m.body.querySelector('#cat-cond-lista');

        function montar() {
            var d = estado.detalhe || det;
            var html = '';
            var temPergunta = false;
            (d.secoes || []).forEach(function (s) {
                html += '<div class="catalogoeformularios-secao">';
                html += '<div class="catalogoeformularios-secao-head"><div class="catalogoeformularios-secao-nome">'
                     + '<i class="ti ti-layout-list"></i> ' + esc(s.nome) + '</div></div>';
                html += '<div class="catalogoeformularios-secao-body">';
                if (!s.perguntas || !s.perguntas.length) {
                    html += '<div style="font-size:12px;color:#999;padding:6px 4px;">Sem perguntas nesta secao.</div>';
                }
                (s.perguntas || []).forEach(function (p) {
                    temPergunta = true;
                    var cond = (p.visibilidade && p.visibilidade.tem_regra)
                        ? '<span class="catalogoeformularios-pergunta-cond" title="Possui regra de visibilidade"><i class="ti ti-eye"></i> condicional</span>' : '';
                    html += '<div class="catalogoeformularios-pergunta" data-pergunta="' + p.id + '">'
                        + '<span class="catalogoeformularios-pergunta-nome">' + esc(p.nome)
                        + (p.obrigatoria ? '<span class="catalogoeformularios-pergunta-obrig">*</span>' : '') + '</span>'
                        + cond
                        + '<span class="catalogoeformularios-pergunta-tipo">' + esc(p.tipo_label) + '</span>'
                        + '<div class="catalogoeformularios-acoes-linha">'
                        + '<button type="button" class="catalogoeformularios-btn-icone" data-cond-pergunta="' + p.id + '" title="Editar visibilidade condicional"><i class="ti ti-eye-check"></i></button>'
                        + '</div></div>';
                });
                html += '</div></div>';
            });
            if (!temPergunta) {
                html = vazio('ti ti-help-circle', 'Este formulario ainda nao tem perguntas. Adicione perguntas nas secoes primeiro.');
            }
            cont.innerHTML = html;
        }
        montar();

        // Reaproveita o gancho do modalCondicao: ao salvar uma condicao, ele recarrega
        // o builder e chama este refresh, atualizando os badges "condicional" aqui.
        estado._refreshModalBuilder = function () {
            var c = document.getElementById('cat-cond-lista');
            if (!c) { estado._refreshModalBuilder = null; return; }
            montar();
        };

        cont.addEventListener('click', function (e) {
            var b = e.target.closest('[data-cond-pergunta]');
            if (!b) { return; }
            e.stopPropagation();
            modalCondicao(parseInt(b.getAttribute('data-cond-pergunta'), 10));
        });

        m.foot.appendChild(botaoFoot('Fechar', '', function () { estado._refreshModalBuilder = null; m.fechar(); }));
    }

    // -----------------------------------------------------------------
    // Popover de perfis que visualizam (categoria / formulario)
    // -----------------------------------------------------------------
    function fecharPopoverPerfis() {
        var ex = document.getElementById('catalogoeformularios-pop-perfis');
        if (ex) { ex.remove(); }
    }

    function urlListaPerfis(ids) {
        // Lista nativa de perfis do GLPI filtrada pelos IDs (Search Engine, campo 2 = ID).
        var base = (CFG.rootDoc || '') + '/front/profile.php';
        var qs = ['noAUTO=1'];
        ids.forEach(function (pid, i) {
            var link = (i === 0) ? 'AND' : 'OR';
            qs.push('criteria[' + i + '][link]=' + encodeURIComponent(link));
            qs.push('criteria[' + i + '][field]=2');           // 2 = ID
            qs.push('criteria[' + i + '][searchtype]=equals');
            qs.push('criteria[' + i + '][value]=' + pid);
        });
        return base + '?' + qs.join('&');
    }

    function abrirPopoverPerfis(botao, escopo, id) {
        fecharPopoverPerfis();

        var pop = document.createElement('div');
        pop.id = 'catalogoeformularios-pop-perfis';
        pop.className = 'catalogoeformularios-pop-perfis';
        pop.innerHTML = '<div class="catalogoeformularios-pop-perfis-carregando">'
            + '<i class="ti ti-loader"></i> Carregando perfis...</div>';
        document.body.appendChild(pop);

        // Posiciona ancorado ao botao (coordenadas de viewport + scroll).
        var r = botao.getBoundingClientRect();
        var top = r.bottom + window.scrollY + 6;
        var left = r.left + window.scrollX;
        pop.style.top = top + 'px';
        pop.style.left = left + 'px';
        // Evita estourar a borda direita da janela.
        requestAnimationFrame(function () {
            var pw = pop.offsetWidth;
            if (left + pw > window.scrollX + document.documentElement.clientWidth - 10) {
                pop.style.left = Math.max(10, window.scrollX + document.documentElement.clientWidth - pw - 10) + 'px';
            }
        });

        var acao = escopo === 'cat' ? 'perfis_categoria' : 'perfis_formulario';
        var params = escopo === 'cat' ? { categoria: id } : { form: id };

        ajax(acao, params).then(function (r) {
            if (!document.body.contains(pop)) { return; }
            if (!r || !r.success || !r.dados) {
                pop.innerHTML = '<div class="catalogoeformularios-pop-perfis-vazio">'
                    + '<i class="ti ti-alert-triangle"></i> ' + esc((r && r.message) || 'Falha ao carregar.') + '</div>';
                return;
            }
            var d = r.dados;
            var titulo = escopo === 'cat' ? 'Perfis com acesso a categoria' : 'Perfis com acesso ao formulario';
            var html = '<div class="catalogoeformularios-pop-perfis-cab">'
                + '<span><i class="ti ti-users"></i> ' + titulo + '</span>'
                + '<button class="catalogoeformularios-pop-perfis-x" data-pop-fechar="1" title="Fechar"><i class="ti ti-x"></i></button>'
                + '</div>';

            if (d.aberto) {
                html += '<div class="catalogoeformularios-pop-perfis-aberto">'
                    + '<i class="ti ti-world"></i> Visivel a todos os perfis (acesso aberto / publico).</div>';
            } else if (!d.perfis || !d.perfis.length) {
                html += '<div class="catalogoeformularios-pop-perfis-vazio">'
                    + '<i class="ti ti-lock"></i> Nenhum perfil com acesso configurado'
                    + (escopo === 'cat' ? ' nos formularios desta categoria.' : ' neste formulario.') + '</div>';
            } else {
                html += '<ul class="catalogoeformularios-pop-perfis-lista">';
                d.perfis.forEach(function (p) {
                    html += '<li><i class="ti ti-shield"></i> ' + esc(p.nome) + '</li>';
                });
                html += '</ul>';
                var ids = d.perfis.map(function (p) { return p.id; });
                html += '<a class="catalogoeformularios-pop-perfis-ir" href="' + esc(urlListaPerfis(ids)) + '" target="_blank" rel="noopener">'
                    + '<i class="ti ti-external-link"></i> Ver estes perfis na lista do GLPI</a>';
            }

            pop.innerHTML = html;
        }).catch(function () {
            if (document.body.contains(pop)) {
                pop.innerHTML = '<div class="catalogoeformularios-pop-perfis-vazio">'
                    + '<i class="ti ti-alert-triangle"></i> Erro ao carregar perfis.</div>';
            }
        });
    }

    // Delega o clique nos botoes de perfis (arvore lateral + pagina).
    function tratarCliquePerfis(e) {
        var bc = e.target.closest('[data-perfis-cat]');
        if (bc) {
            e.preventDefault();
            e.stopPropagation();
            abrirPopoverPerfis(bc, 'cat', parseInt(bc.getAttribute('data-perfis-cat'), 10));
            return true;
        }
        var bf = e.target.closest('[data-perfis-form]');
        if (bf) {
            e.preventDefault();
            e.stopPropagation();
            abrirPopoverPerfis(bf, 'form', parseInt(bf.getAttribute('data-perfis-form'), 10));
            return true;
        }
        return false;
    }

    elArvore.addEventListener('click', tratarCliquePerfis, true);
    elConteudo.addEventListener('click', tratarCliquePerfis, true);

    // Fecha selects de busca e o popover de perfis ao clicar fora.
    document.addEventListener('click', function (e) {
        document.querySelectorAll('.catalogoeformularios-selbusca').forEach(function (sb) {
            if (!sb.contains(e.target)) {
                var d = sb.querySelector('.catalogoeformularios-selbusca-dropdown');
                if (d) { d.style.display = 'none'; }
            }
        });

        var pop = document.getElementById('catalogoeformularios-pop-perfis');
        if (pop) {
            if (e.target.closest('[data-pop-fechar]')) { fecharPopoverPerfis(); return; }
            // Nao fecha se clicou no proprio popover ou em um botao de abrir perfis.
            if (!pop.contains(e.target) && !e.target.closest('[data-perfis-cat]') && !e.target.closest('[data-perfis-form]')) {
                fecharPopoverPerfis();
            }
        }

        // Fecha o menu de acoes do formulario ao clicar fora dele (o proprio botao "..."
        // e tratado pelo handler do conteudo, que faz stopPropagation).
        var menuF = document.getElementById('catalogoeformularios-menu-form');
        if (menuF && !menuF.contains(e.target) && !e.target.closest('[data-acao="menu-form"]')) {
            fecharMenuFormulario();
        }
    });

    // Utilitarios usados pelo editor completo do acordeao (estrutura.js)
    window.catalogoeformulariosUtil = {
        ajax: ajax, toast: toast, esc: esc, carregarFonte: carregarFonte, confirmar: confirmar,
        campoSelectBusca: campoSelectBusca, ativarSelectBusca: ativarSelectBusca,
        multiBusca: multiBusca, preencherMultiBusca: preencherMultiBusca, ativarMultiBusca: ativarMultiBusca, coletarMultiBusca: coletarMultiBusca,
        campoIlustracao: campoIlustracao, ativarSeletorIlustracao: ativarSeletorIlustracao, configRich: configRich
    };

    // -----------------------------------------------------------------
    // Inicio
    // -----------------------------------------------------------------
    recarregarArvore();
    carregarNivel(0);
})();