/* ===== Catalogo e Formularios - editor completo do formulario dentro do acordeao =====
 * Abas Geral e Estrutura (secoes, perguntas, blocos de texto, valor padrao, validacao e
 * visibilidade condicional) e as condicoes de criacao dos destinos. Usa os utilitarios
 * publicados pelo catalogo.js em window.catalogoeformulariosUtil.
 */
(function () {
    'use strict';

    var P = 'catalogoeformularios-';
    var U = function () { return window.catalogoeformulariosUtil; };
    var CFG = function () { return window.catalogoeformulariosCat || {}; };

    var URGENCIAS = [{ v: '1', t: 'Muito baixa' }, { v: '2', t: 'Baixa' }, { v: '3', t: 'Media' }, { v: '4', t: 'Alta' }, { v: '5', t: 'Muito alta' }];
    var TIPOS_CHAMADO = [{ v: '1', t: 'Incidente' }, { v: '2', t: 'Requisicao' }];
    var ICONE_TIPO = {
        'Resposta curta': 'ti ti-forms', 'Resposta longa': 'ti ti-align-left', 'Data e hora': 'ti ti-calendar',
        'Escolha': 'ti ti-list-check', 'Chamado': 'ti ti-ticket', 'Atores': 'ti ti-users', 'Itens': 'ti ti-box', 'Arquivo': 'ti ti-paperclip'
    };

    function esc(s) { return U().esc(s); }
    function tipos() { return CFG().estruturaTipos || []; }
    function tipoDef(slug) { return tipos().filter(function (t) { return t.slug === slug; })[0] || null; }
    function uid(p) { return p + '_' + Date.now().toString(36) + Math.floor(Math.random() * 1e6).toString(36); }

    // -----------------------------------------------------------------
    // Editor de texto rico (TinyMCE do GLPI) dentro do acordeao
    // -----------------------------------------------------------------
    var ricos = [];
    function richHtml(id, valor) {
        return '<textarea id="' + id + '" class="' + P + 'input" rows="4">' + esc(valor || '') + '</textarea>';
    }

    /**
     * Descricao/cabecalho dentro de um acordeao fechado: o editor rico so e criado na
     * primeira abertura. Enquanto fechado, salvar devolve o conteudo original (valor do textarea).
     */
    function richSanfona(id, rotulo, valor) {
        var tem = String(valor || '').replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim() !== '';
        return '<div class="' + P + 'ed-sanfona" data-sanfona="' + id + '">'
            + '<button type="button" class="' + P + 'ed-sanfona-cab" data-sanfona-cab><i class="ti ti-chevron-right"></i> <span>' + esc(rotulo) + '</span>'
            + (tem ? '<span class="' + P + 'ed-badge">preenchido</span>' : '<span class="' + P + 'ed-sanfona-vazio">vazio</span>') + '</button>'
            + '<div class="' + P + 'ed-sanfona-corpo" hidden>' + richHtml(id, valor) + '</div></div>';
    }
    document.addEventListener('click', function (e) {
        var cab = e.target.closest('[data-sanfona-cab]');
        if (!cab) { return; }
        e.preventDefault();
        e.stopPropagation();
        var bloco = cab.closest('[data-sanfona]');
        var corpo = bloco.querySelector('.' + P + 'ed-sanfona-corpo');
        var abrir = corpo.hidden;
        corpo.hidden = !abrir;
        bloco.classList.toggle('aberta', abrir);
        cab.querySelector('i').className = 'ti ti-chevron-' + (abrir ? 'down' : 'right');
        var id = bloco.getAttribute('data-sanfona');
        if (abrir && !bloco.getAttribute('data-iniciado')) {
            bloco.setAttribute('data-iniciado', '1');
            var ta = document.getElementById(id);
            iniciarRich(id, ta ? ta.value : '');
        }
    }, true);
    function iniciarRich(id, valor) {
        if (!window.tinymce) { return; }
        var antigo = window.tinymce.get(id);
        if (antigo) { antigo.remove(); }
        ricos.push(id);
        window.tinymce.init(U().configRich(id, valor));
    }
    function lerRich(id) {
        if (window.tinymce) { var ed = window.tinymce.get(id); if (ed) { ed.save(); return ed.getContent(); } }
        var ta = document.getElementById(id); return ta ? ta.value : '';
    }
    function removerRicosDentro(el) {
        if (!window.tinymce) { return; }
        ricos = ricos.filter(function (id) {
            var ta = document.getElementById(id);
            if (!ta || !el || el.contains(ta)) { var ed = window.tinymce.get(id); if (ed) { ed.remove(); } return false; }
            return true;
        });
    }

    // -----------------------------------------------------------------
    // Construtor de condicoes (visibilidade, criacao do destino, validacao)
    // -----------------------------------------------------------------
    /**
     * opts: { regra:{estrategia,condicoes}, origens:[...], excluir: uuid, tipo:'visibilidade'|'criacao'|'validacao',
     *         operadoresValidacao:[...], valorValidacao:'texto'|'numero' }
     * Devolve { el, ler() -> {estrategia, condicoes} }.
     */
    function construtorCondicoes(opts) {
        var regra = opts.regra || { estrategia: opts.tipo === 'validacao' ? 'sem' : 'sempre', condicoes: [] };
        var linhas = (regra.condicoes || []).map(function (c) { return { origem: c.origem, operador: c.operador, valor: c.valor, logica: c.logica || 'and' }; });
        var estrategia = regra.estrategia;
        var el = document.createElement('div');
        el.className = P + 'cb';

        var estrategias = opts.tipo === 'criacao'
            ? [{ v: 'sempre', t: 'Criar sempre' }, { v: 'se', t: 'Criar somente se...' }, { v: 'exceto', t: 'Nao criar se...' }]
            : opts.tipo === 'validacao'
                ? [{ v: 'sem', t: 'Sem validacao' }, { v: 'valido_se', t: 'Resposta valida se...' }, { v: 'invalido_se', t: 'Resposta invalida se...' }]
                : [{ v: 'sempre', t: 'Sempre visivel' }, { v: 'se', t: 'Visivel somente se...' }, { v: 'exceto', t: 'Oculto se...' }];
        var semRegra = opts.tipo === 'validacao' ? 'sem' : 'sempre';

        var origens = (opts.origens || []).filter(function (o) { return o.uuid && o.uuid !== opts.excluir && (o.operadores || []).length; });

        function origemDe(uuid) { return origens.filter(function (o) { return o.uuid === uuid; })[0] || null; }
        function operadoresDe(linha) {
            if (opts.tipo === 'validacao') { return opts.operadoresValidacao || []; }
            var o = origemDe(linha.origem);
            return o ? o.operadores : [];
        }
        function campoValor(linha) {
            var ops = operadoresDe(linha);
            var op = ops.filter(function (x) { return x.slug === linha.operador; })[0];
            if (op && op.sem_valor) { return '<span class="' + P + 'cb-semvalor">sem valor</span>'; }
            var tipo = opts.tipo === 'validacao' ? (opts.valorValidacao || 'texto') : ((origemDe(linha.origem) || {}).valor || 'texto');
            if (/regex/.test(linha.operador || '')) { tipo = 'regex'; }
            if (/^length_/.test(linha.operador || '')) { tipo = 'numero'; }
            var v = Array.isArray(linha.valor) ? (linha.valor[0] || '') : (linha.valor == null ? '' : String(linha.valor));
            var lista = null;
            if (tipo === 'opcao') { lista = ((origemDe(linha.origem) || {}).opcoes || []).map(function (o) { return { v: o.k, t: o.t }; }); }
            if (tipo === 'urgencia') { lista = URGENCIAS; }
            if (tipo === 'tipo') { lista = TIPOS_CHAMADO; }
            if (lista) {
                return '<select class="' + P + 'select" data-cb-valor><option value="">Valor...</option>' + lista.map(function (o) {
                    return '<option value="' + esc(o.v) + '"' + (String(o.v) === v ? ' selected' : '') + '>' + esc(o.t) + '</option>';
                }).join('') + '</select>';
            }
            var tipoInput = { numero: 'number', data: 'date', hora: 'time', datahora: 'datetime-local' }[tipo] || 'text';
            var ph = tipo === 'regex' ? '/^[0-9]+$/' : 'valor';
            return '<input type="' + tipoInput + '" class="' + P + 'input" data-cb-valor value="' + esc(v) + '" placeholder="' + esc(ph) + '">';
        }

        function render() {
            var h = '<select class="' + P + 'select ' + P + 'cb-estr" data-cb-estrategia>'
                + estrategias.map(function (e) { return '<option value="' + e.v + '"' + (e.v === estrategia ? ' selected' : '') + '>' + esc(e.t) + '</option>'; }).join('')
                + '</select>';
            if (estrategia !== semRegra) {
                if (opts.tipo !== 'validacao' && !origens.length) {
                    h += '<div class="' + P + 'cb-aviso"><i class="ti ti-info-circle"></i> Crie antes uma pergunta (em outra posicao do formulario) para usar como condicao.</div>';
                }
                h += '<div class="' + P + 'cb-linhas">';
                linhas.forEach(function (l, i) {
                    h += '<div class="' + P + 'cb-linha" data-i="' + i + '">';
                    h += i === 0 ? '<span class="' + P + 'cb-se">' + (opts.tipo === 'validacao' ? 'Quando' : 'Se') + '</span>'
                        : '<select class="' + P + 'select ' + P + 'cb-logica" data-cb-logica><option value="and"' + (l.logica !== 'or' ? ' selected' : '') + '>E</option><option value="or"' + (l.logica === 'or' ? ' selected' : '') + '>OU</option></select>';
                    if (opts.tipo !== 'validacao') {
                        h += '<select class="' + P + 'select ' + P + 'cb-origem" data-cb-origem><option value="">Pergunta...</option>'
                            + origens.map(function (o) { return '<option value="' + esc(o.uuid) + '"' + (o.uuid === l.origem ? ' selected' : '') + '>' + esc(o.nome) + (o.secao ? ' (' + esc(o.secao) + ')' : '') + '</option>'; }).join('')
                            + '</select>';
                    } else {
                        h += '<span class="' + P + 'cb-se">a resposta</span>';
                    }
                    var ops = operadoresDe(l);
                    h += '<select class="' + P + 'select" data-cb-operador>' + (ops.length ? '' : '<option value="">Operador...</option>')
                        + ops.map(function (o) { return '<option value="' + esc(o.slug) + '"' + (o.slug === l.operador ? ' selected' : '') + '>' + esc(o.label) + '</option>'; }).join('')
                        + '</select>';
                    h += '<span class="' + P + 'cb-valor">' + campoValor(l) + '</span>';
                    h += '<button type="button" class="' + P + 'btn-icone" data-cb-rem title="Remover condicao"><i class="ti ti-x"></i></button>';
                    h += '</div>';
                });
                h += '</div><button type="button" class="' + P + 'btn ' + P + 'btn-mini" data-cb-add><i class="ti ti-plus"></i> Condicao</button>';
            }
            el.innerHTML = h;
        }

        function sincronizarOperador(i) {
            var l = linhas[i];
            var ops = operadoresDe(l);
            if (!ops.filter(function (o) { return o.slug === l.operador; }).length) { l.operador = ops.length ? ops[0].slug : ''; l.valor = ''; }
        }

        el.addEventListener('change', function (e) {
            var t = e.target;
            if (t.hasAttribute('data-cb-estrategia')) {
                estrategia = t.value;
                if (estrategia !== semRegra && !linhas.length) { linhas.push({ origem: '', operador: '', valor: '', logica: 'and' }); if (opts.tipo === 'validacao') { sincronizarOperador(0); } }
                render(); return;
            }
            var linha = t.closest('[data-i]');
            if (!linha) { return; }
            var i = parseInt(linha.getAttribute('data-i'), 10);
            if (t.hasAttribute('data-cb-origem')) { linhas[i].origem = t.value; sincronizarOperador(i); render(); }
            else if (t.hasAttribute('data-cb-operador')) { linhas[i].operador = t.value; render(); }
            else if (t.hasAttribute('data-cb-logica')) { linhas[i].logica = t.value; }
            else if (t.hasAttribute('data-cb-valor')) { linhas[i].valor = t.value; }
        });
        el.addEventListener('input', function (e) {
            if (!e.target.hasAttribute('data-cb-valor')) { return; }
            var linha = e.target.closest('[data-i]');
            if (linha) { linhas[parseInt(linha.getAttribute('data-i'), 10)].valor = e.target.value; }
        });
        el.addEventListener('click', function (e) {
            if (e.target.closest('[data-cb-add]')) {
                linhas.push({ origem: '', operador: '', valor: '', logica: 'and' });
                if (opts.tipo === 'validacao') { sincronizarOperador(linhas.length - 1); }
                render();
            } else if (e.target.closest('[data-cb-rem]')) {
                var i = parseInt(e.target.closest('[data-i]').getAttribute('data-i'), 10);
                linhas.splice(i, 1);
                if (!linhas.length) { estrategia = semRegra; }
                render();
            }
        });
        render();

        return {
            el: el,
            ler: function () {
                return {
                    estrategia: estrategia,
                    condicoes: estrategia === semRegra ? [] : linhas.filter(function (l) { return (opts.tipo === 'validacao' || l.origem) && l.operador; })
                };
            },
            completo: function () {
                if (estrategia === semRegra) { return true; }
                return linhas.length > 0 && linhas.every(function (l) { return (opts.tipo === 'validacao' || l.origem) && l.operador; });
            }
        };
    }

    function resumoRegra(r, criacao) {
        if (!r || r.estrategia === 'sempre' || r.estrategia === 'sem' || !(r.condicoes || []).length) { return ''; }
        var n = r.condicoes.length;
        var txt = criacao ? (r.estrategia === 'se' ? 'criado se' : 'nao criado se') : (r.estrategia === 'se' ? 'visivel se' : 'oculto se');
        return txt + ' (' + n + ' condicao' + (n > 1 ? 'oes' : '') + ')';
    }

    function salvarRegra(alvo, id, regra) {
        return U().ajax('regra_salvar', { alvo: alvo, id: id, estrategia: regra.estrategia, condicoes_json: JSON.stringify(regra.condicoes || []) });
    }

    // -----------------------------------------------------------------
    // Aba Geral
    // -----------------------------------------------------------------
    function montarGeral(box, formId, est, aoSalvar) {
        var f = est.form || {};
        var u = U();
        var podeEditar = !!CFG().podeEditar;
        var idDesc = uid('ge_desc'), idHead = uid('ge_head'), idItil = uid('ge_itil');
        var optCat = [{ v: 0, t: '(Raiz do catalogo)' }].concat((CFG().categorias || []).map(function (c) { return { v: c.id, t: c.nome }; }));
        var itil = f.categoria_itil || { tem_destino: false, estrategia: 'modelo', valor: 0 };

        // 1. Nome, entidade, categoria ITIL e categoria do catalogo
        var h = '<div class="' + P + 'ed-grade">';
        h += '<div class="' + P + 'campo"><label>Nome <span class="obrig">*</span></label><input type="text" class="' + P + 'input" data-ge="nome" value="' + esc(f.nome) + '"' + (podeEditar ? '' : ' disabled') + '></div>';
        // Entidades ativas do usuario; a atual do formulario entra mesmo se estiver fora delas
        var optEnt = (CFG().entidades || []).map(function (e) { return { v: e.id, t: e.nome }; });
        if (!optEnt.some(function (o) { return o.v === f.entidade; })) { optEnt.unshift({ v: f.entidade, t: f.entidade_nome || ('#' + f.entidade) }); }
        if (podeEditar) {
            h += u.campoSelectBusca(uid('ge_ent'), 'Entidade', optEnt, f.entidade).replace('<div class="' + P + 'selbusca">', '<div class="' + P + 'selbusca" data-ge-ent>');
        } else {
            h += '<div class="' + P + 'campo"><label>Entidade</label><div class="' + P + 'ed-leitura"><i class="ti ti-building"></i> ' + esc(f.entidade_nome || '') + '</div></div>';
        }
        h += '<div class="' + P + 'campo" data-ge-itil-wrap><label>Categoria ITIL do chamado gerado</label><div data-ge-itil><span class="' + P + 'pn-vazio-txt">carregando...</span></div>'
            + (itil.estrategia === 'resposta' ? '<p class="' + P + 'pn-ajuda"><i class="ti ti-info-circle"></i> Hoje a categoria vem da resposta de uma pergunta. Escolher uma aqui fixa a categoria.</p>' : '')
            + (!itil.tem_destino ? '<p class="' + P + 'pn-ajuda"><i class="ti ti-alert-triangle"></i> Este formulario ainda nao tem destino de chamado (aba Chamado gerado).</p>' : '')
            + '</div>';
        h += u.campoSelectBusca(uid('ge_cat'), 'Categoria do catalogo', optCat, f.categoria || 0).replace('<div class="' + P + 'selbusca">', '<div class="' + P + 'selbusca" data-ge-cat>');
        h += '</div>';

        // 2. Descricao e cabecalho (fechados)
        h += richSanfona(idDesc, 'Descricao (aparece no catalogo)', f.descricao || '');
        h += richSanfona(idHead, 'Cabecalho (aparece no topo do formulario)', f.header || '');

        // 3. Atores do chamado (ver e escolher rapido; cada ator salva na hora)
        h += '<div class="' + P + 'campo"><label><i class="ti ti-users"></i> Atores do chamado gerado</label><div class="' + P + 'ge-atores" data-ge-atores><span class="' + P + 'pn-vazio-txt">carregando...</span></div></div>';

        // 4. Icone por ultimo, na largura toda
        if (f.tem_ilustracao) { h += '<div class="' + P + 'ge-icone">' + u.campoIlustracao('ge_ilustracao_' + formId, 'Icone do formulario', f.ilustracao || '') + '</div>'; }

        if (podeEditar) {
            h += '<div class="' + P + 'ed-rodape"><button type="button" class="' + P + 'btn ' + P + 'btn-primario" data-ge-salvar><i class="ti ti-device-floppy"></i> Salvar</button></div>';
        }
        removerRicosDentro(box);
        box.innerHTML = h;
        box.querySelectorAll('.' + P + 'selbusca').forEach(u.ativarSelectBusca);
        if (f.tem_ilustracao) { u.ativarSeletorIlustracao(box, 'ge_ilustracao_' + formId); }

        // Categorias ITIL (lista nativa)
        var itilInicial = itil.estrategia === 'especifico' ? (itil.valor || 0) : 0;
        u.carregarFonte('itilcategorias').then(function (lista) {
            var wrap = box.querySelector('[data-ge-itil]');
            if (!wrap) { return; }
            var ops = [{ v: 0, t: itil.estrategia === 'resposta' ? '(definida pela resposta)' : '(padrao do GLPI)' }].concat((lista || []).map(function (c) { return { v: c.v, t: c.t }; }));
            wrap.innerHTML = u.campoSelectBusca(idItil, '', ops, itilInicial);
            wrap.querySelectorAll('.' + P + 'selbusca').forEach(u.ativarSelectBusca);
        });

        montarAtoresGeral(box.querySelector('[data-ge-atores]'), formId, podeEditar);

        var btn = box.querySelector('[data-ge-salvar]');
        if (!btn) { return; }
        btn.addEventListener('click', function () {
            var nome = box.querySelector('[data-ge="nome"]').value.trim();
            if (!nome) { u.toast('Informe o nome do formulario.', false); return; }
            var cat = box.querySelector('[data-ge-cat] input[type="hidden"]');
            var dados = {
                nome: nome,
                categoria: cat ? parseInt(cat.value, 10) || 0 : f.categoria,
                descricao: lerRich(idDesc),
                header: lerRich(idHead)
            };
            var ent = box.querySelector('[data-ge-ent] input[type="hidden"]');
            if (ent) { dados.entidade = parseInt(ent.value, 10) || 0; }
            var it = document.getElementById(idItil);
            // So grava a categoria ITIL se mudou (assim a estrategia "resposta" nao e trocada sem querer)
            if (it && itil.tem_destino && (parseInt(it.value, 10) || 0) !== itilInicial) { dados.categoria_itil = parseInt(it.value, 10) || 0; }
            var il = document.getElementById('ge_ilustracao_' + formId);
            if (il) { dados.ilustracao = il.value; }
            btn.disabled = true;
            u.ajax('geral_salvar', { form: formId, dados_json: JSON.stringify(dados) }).then(function (r) {
                btn.disabled = false;
                u.toast((r && r.message) || 'Falha.', !!(r && r.success));
                if (r && r.success && aoSalvar) { aoSalvar(dados); }
            });
        });
    }

    /** Requerentes, observadores e atribuidos do chamado gerado, com edicao rapida por ator. */
    function montarAtoresGeral(area, formId, podeEditar) {
        var u = U();
        function carregar() {
            u.ajax('painel_formulario', { form: formId }).then(function (r) {
                var d = (r && r.success) ? r.dados : null;
                if (!d) { area.innerHTML = '<span class="' + P + 'pn-vazio-txt">Nao foi possivel carregar os atores.</span>'; return; }
                if (!(d.destino || {}).id) { area.innerHTML = '<span class="' + P + 'pn-vazio-txt">Crie o destino de chamado (aba Chamado gerado) para definir os atores.</span>'; return; }
                var h = '';
                (d.atores || []).forEach(function (a) {
                    var tags = (a.usuarios || []).map(function (x) { return '<span class="' + P + 'pn-tag"><i class="ti ti-user"></i> <span>' + esc(x.t) + '</span></span>'; })
                        .concat((a.grupos || []).map(function (x) { return '<span class="' + P + 'pn-tag"><i class="ti ti-users"></i> <span>' + esc(x.t) + '</span></span>'; }));
                    if (!tags.length) { tags = [a.qtd_perguntas ? '<span class="' + P + 'pn-tag"><i class="ti ti-help-circle"></i> <span>pela resposta</span></span>' : '<span class="' + P + 'pn-vazio-txt">padrao do GLPI</span>']; }
                    h += '<div class="' + P + 'ge-ator" data-ator="' + esc(a.slug) + '">'
                        + '<span class="' + P + 'ge-ator-rot"><i class="' + esc(a.icone || 'ti ti-user') + '"></i> ' + esc(a.label) + '</span>'
                        + '<span class="' + P + 'ge-ator-val">' + tags.join('') + '</span>'
                        + (podeEditar ? '<button type="button" class="' + P + 'btn-icone" data-ator-editar title="Escolher ' + esc(a.label) + '"><i class="ti ti-edit"></i></button>' : '')
                        + '<div class="' + P + 'ge-ator-editor" hidden></div></div>';
                });
                area.innerHTML = h;
                area._atores = d.atores || [];
            });
        }
        area.addEventListener('click', function (e) {
            var b = e.target.closest('[data-ator-editar]');
            if (!b) { return; }
            var linha = b.closest('[data-ator]');
            var ed = linha.querySelector('.' + P + 'ge-ator-editor');
            if (!ed.hidden) { ed.hidden = true; ed.innerHTML = ''; return; }
            var slug = linha.getAttribute('data-ator');
            var atual = (area._atores || []).filter(function (a) { return a.slug === slug; })[0] || { usuarios: [], grupos: [] };
            var idU = uid('gau'), idG = uid('gag');
            ed.hidden = false;
            ed.innerHTML = '<div class="' + P + 'pn-duas-colunas"><div><span class="' + P + 'pn-sub"><i class="ti ti-user"></i> Usuarios</span>' + u.multiBusca(idU, [], []) + '</div>'
                + '<div><span class="' + P + 'pn-sub"><i class="ti ti-users"></i> Grupos</span>' + u.multiBusca(idG, [], []) + '</div></div>'
                + '<div class="' + P + 'ed-rodape"><button type="button" class="' + P + 'btn" data-ator-limpar title="Voltar ao padrao do GLPI"><i class="ti ti-eraser"></i> Limpar</button>'
                + '<button type="button" class="' + P + 'btn ' + P + 'btn-primario" data-ator-salvar><i class="ti ti-device-floppy"></i> Salvar ' + esc((atual.label || '').toLowerCase()) + '</button></div>';
            u.carregarFonte('atores').then(function (at) {
                var cu = document.getElementById(idU), cg = document.getElementById(idG);
                if (cu) { u.preencherMultiBusca(cu, (at && at.usuarios) || [], (atual.usuarios || []).map(function (x) { return x.v; })); u.ativarMultiBusca(cu); }
                if (cg) { u.preencherMultiBusca(cg, (at && at.grupos) || [], (atual.grupos || []).map(function (x) { return x.v; })); u.ativarMultiBusca(cg); }
            });
            function salvar(limpar) {
                var cu = document.getElementById(idU), cg = document.getElementById(idG);
                u.ajax('painel_atores', {
                    form: formId, destino: 0, ator: slug,
                    usuarios: limpar ? [] : (cu ? u.coletarMultiBusca(cu) : []),
                    grupos: limpar ? [] : (cg ? u.coletarMultiBusca(cg) : [])
                }).then(function (r) {
                    u.toast((r && r.message) || 'Falha.', !!(r && r.success));
                    if (r && r.success) {
                        carregar();
                        // A aba "Chamado gerado" mostra os mesmos atores: o acordeao recarrega ao abrir aquela aba
                        area.dispatchEvent(new CustomEvent('cf-atores-mudaram', { bubbles: true }));
                    }
                });
            }
            ed.querySelector('[data-ator-salvar]').addEventListener('click', function () { salvar(false); });
            ed.querySelector('[data-ator-limpar]').addEventListener('click', function () { salvar(true); });
        });
        carregar();
    }

    function switchHtml(chave, rotulo, on) {
        return '<label class="' + P + 'ed-switch"><input type="checkbox" data-sw="' + chave + '"' + (on ? ' checked' : '') + '><span class="trilho"></span><span>' + esc(rotulo) + '</span></label>';
    }

    // -----------------------------------------------------------------
    // Aba Estrutura
    // -----------------------------------------------------------------
    function montarEstrutura(box, formId, est, recarregar) {
        var podeEditar = !!CFG().podeEditar;
        var aberto = box.getAttribute('data-ed-aberto') || '';
        removerRicosDentro(box);

        var h = '<div class="' + P + 'ed-barra">';
        h += '<span class="' + P + 'ed-dica"><i class="ti ti-info-circle"></i> Arraste pelas alcas para reordenar perguntas e blocos, inclusive entre secoes. Clique numa linha para editar.</span>';
        if (podeEditar) { h += '<button type="button" class="' + P + 'btn ' + P + 'btn-mini" data-ed="nova-secao" title="Adicionar secao"><i class="ti ti-plus"></i> Secao</button>'; }
        h += '</div>';

        if (!(est.secoes || []).length) {
            h += '<div class="' + P + 'pn-vazio-txt">Este formulario ainda nao tem secoes.</div>';
        }
        (est.secoes || []).forEach(function (s, idx) {
            var cond = resumoRegra(s.visibilidade);
            h += '<section class="' + P + 'ed-secao" data-secao="' + s.id + '">';
            h += '<header class="' + P + 'ed-secao-cab" data-ed-abrir="secao:' + s.id + '">';
            if (podeEditar) { h += '<i class="ti ti-grip-vertical ' + P + 'ed-alca-secao" title="Arrastar secao"></i>'; }
            h += '<span class="' + P + 'ed-secao-num">' + (idx + 1) + '</span>';
            h += '<span class="' + P + 'ed-secao-nome">' + esc(s.nome || '(secao sem nome)') + '</span>';
            if (cond) { h += '<span class="' + P + 'ed-badge cond"><i class="ti ti-eye-check"></i> ' + esc(cond) + '</span>'; }
            h += '<span class="' + P + 'ed-secao-qtd">' + s.blocos.length + ' item(ns)</span>';
            if (podeEditar) {
                h += '<span class="' + P + 'ed-acoes">'
                    + '<button type="button" class="' + P + 'btn ' + P + 'btn-mini ' + P + 'btn-primario" data-ed="nova-pergunta" data-secao="' + s.id + '" title="Adicionar pergunta nesta secao"><i class="ti ti-plus"></i> Pergunta</button>'
                    + (est.comentarios ? '<button type="button" class="' + P + 'btn ' + P + 'btn-mini" data-ed="novo-comentario" data-secao="' + s.id + '"><i class="ti ti-text-caption"></i> Texto</button>' : '')
                    + '<button type="button" class="' + P + 'btn-icone" data-ed="excluir-secao" data-id="' + s.id + '" title="Excluir secao"><i class="ti ti-trash"></i></button>'
                    + '</span>';
            }
            h += '</header>';
            h += '<div class="' + P + 'ed-editor" data-editor="secao:' + s.id + '"></div>';
            h += '<div class="' + P + 'ed-blocos" data-blocos="' + s.id + '">';
            if (!s.blocos.length) { h += '<div class="' + P + 'ed-vazio-secao">Sem perguntas nesta secao.</div>'; }
            s.blocos.forEach(function (b) { h += linhaBloco(b, podeEditar); });
            h += '</div></section>';
        });

        if (est.enviar) {
            var ce = resumoRegra(est.enviar);
            h += '<section class="' + P + 'ed-secao ' + P + 'ed-enviar">';
            h += '<header class="' + P + 'ed-secao-cab" data-ed-abrir="enviar:' + formId + '"><i class="ti ti-send"></i> <span class="' + P + 'ed-secao-nome">Botao Enviar</span>'
                + '<span class="' + P + 'ed-badge' + (ce ? ' cond' : '') + '"><i class="ti ti-eye-check"></i> ' + esc(ce || 'sempre visivel') + '</span></header>';
            h += '<div class="' + P + 'ed-editor" data-editor="enviar:' + formId + '"></div></section>';
        }

        box.innerHTML = h;
        ligarArrastar(box, est);
        if (aberto && box.querySelector('[data-editor="' + aberto + '"]')) { abrirEditor(aberto); }

        function acharBloco(tipo, id) {
            var achado = null;
            (est.secoes || []).forEach(function (s) {
                if (tipo === 'secao' && s.id === id) { achado = s; }
                s.blocos.forEach(function (b) { if (b.bloco === tipo && b.id === id) { achado = b; } });
            });
            return achado;
        }

        function fecharEditores() {
            removerRicosDentro(box);
            box.querySelectorAll('.' + P + 'ed-editor').forEach(function (ed) { ed.innerHTML = ''; ed.classList.remove('aberto'); });
            box.querySelectorAll('.editando').forEach(function (x) { x.classList.remove('editando'); });
        }

        function abrirEditor(chave) {
            fecharEditores();
            var ed = box.querySelector('[data-editor="' + chave + '"]');
            if (!ed) { return; }
            box.setAttribute('data-ed-aberto', chave);
            var partes = chave.split(':');
            var tipo = partes[0], id = parseInt(partes[1], 10) || 0;
            var dono = ed.previousElementSibling;
            if (dono) { dono.classList.add('editando'); }
            ed.classList.add('aberto');
            var item = tipo === 'enviar' ? true : acharBloco(tipo, id);
            if (!item) { fechar(); return; }
            if (tipo === 'secao') { editorSecao(ed, item); }
            else if (tipo === 'pergunta') { editorPergunta(ed, item, 0); }
            else if (tipo === 'comentario') { editorComentario(ed, item, 0); }
            else if (tipo === 'enviar') { editorEnviar(ed); }
            try { ed.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) { /* ok */ }
        }

        function fechar() { box.setAttribute('data-ed-aberto', ''); fecharEditores(); }
        function depoisDeSalvar(chave) { box.setAttribute('data-ed-aberto', chave || ''); recarregar(); }

        // ----- editores -----
        function editorSecao(ed, s) {
            var idDesc = uid('sec_desc');
            var cb = construtorCondicoes({ regra: s.visibilidade, origens: est.origens.filter(function (o) { return !s.blocos.some(function (b) { return b.uuid === o.uuid; }); }), tipo: 'visibilidade' });
            ed.innerHTML = '<div class="' + P + 'campo"><label>Nome da secao</label><input type="text" class="' + P + 'input" data-f="nome" value="' + esc(s.nome) + '"></div>'
                + richSanfona(idDesc, 'Descricao da secao', s.descricao)
                + '<div class="' + P + 'campo"><label><i class="ti ti-eye-check"></i> Visibilidade da secao</label><div data-cb></div></div>'
                + botoesEditor();
            ed.querySelector('[data-cb]').appendChild(cb.el);
            ligarBotoes(ed, function () {
                var nome = ed.querySelector('[data-f="nome"]').value.trim();
                if (!nome) { U().toast('Informe o nome da secao.', false); return Promise.resolve(false); }
                if (!cb.completo()) { U().toast('Complete ou remova as condicoes incompletas.', false); return Promise.resolve(false); }
                return U().ajax('editar_secao', { id: s.id, nome: nome, descricao: lerRich(idDesc) }).then(function (r) {
                    if (!r || !r.success) { U().toast((r && r.message) || 'Falha.', false); return false; }
                    return salvarRegra('secao', s.id, cb.ler()).then(function (r2) {
                        U().toast(r2 && r2.success ? 'Secao salva.' : 'Secao salva, mas a condicao nao: ' + ((r2 && r2.message) || ''), !!(r2 && r2.success));
                        return true;
                    });
                });
            }, 'secao:' + s.id);
        }

        function editorEnviar(ed) {
            var cb = construtorCondicoes({ regra: est.enviar, origens: est.origens, tipo: 'visibilidade' });
            ed.innerHTML = '<p class="' + P + 'pn-ajuda"><i class="ti ti-info-circle"></i> Esconda o botao Enviar ate que as respostas permitam o envio (por exemplo, so depois de aceitar um termo).</p><div data-cb></div>' + botoesEditor();
            ed.querySelector('[data-cb]').appendChild(cb.el);
            ligarBotoes(ed, function () {
                if (!cb.completo()) { U().toast('Complete ou remova as condicoes incompletas.', false); return Promise.resolve(false); }
                return salvarRegra('enviar', formId, cb.ler()).then(function (r) { U().toast((r && r.message) || 'Falha.', !!(r && r.success)); return !!(r && r.success); });
            }, 'enviar:' + formId);
        }

        function editorComentario(ed, c, secaoId) {
            var idDesc = uid('com_desc');
            var cb = c ? construtorCondicoes({ regra: c.visibilidade, origens: est.origens, tipo: 'visibilidade' }) : null;
            ed.innerHTML = '<div class="' + P + 'campo"><label>Titulo do bloco</label><input type="text" class="' + P + 'input" data-f="nome" value="' + esc(c ? c.nome : '') + '"></div>'
                + '<div class="' + P + 'campo"><label>Texto (orientacoes, avisos, imagens)</label>' + richHtml(idDesc, '') + '</div>'
                + (cb ? '<div class="' + P + 'campo"><label><i class="ti ti-eye-check"></i> Visibilidade</label><div data-cb></div></div>' : '<p class="' + P + 'pn-ajuda"><i class="ti ti-info-circle"></i> Depois de criar, a visibilidade condicional do bloco fica disponivel aqui.</p>')
                + botoesEditor();
            if (cb) { ed.querySelector('[data-cb]').appendChild(cb.el); }
            iniciarRich(idDesc, c ? c.descricao : '');
            ligarBotoes(ed, function () {
                if (cb && !cb.completo()) { U().toast('Complete ou remova as condicoes incompletas.', false); return Promise.resolve(false); }
                return U().ajax('comentario_salvar', { id: c ? c.id : 0, secao: secaoId, nome: ed.querySelector('[data-f="nome"]').value.trim(), descricao: lerRich(idDesc) }).then(function (r) {
                    if (!r || !r.success) { U().toast((r && r.message) || 'Falha.', false); return false; }
                    if (!cb) { U().toast(r.message, true); return 'comentario:' + r.id; }
                    return salvarRegra('comentario', c.id, cb.ler()).then(function (r2) {
                        U().toast(r2 && r2.success ? 'Bloco salvo.' : 'Bloco salvo, mas a condicao nao: ' + ((r2 && r2.message) || ''), !!(r2 && r2.success));
                        return true;
                    });
                });
            }, c ? 'comentario:' + c.id : '');
        }

        function editorPergunta(ed, p, secaoId) {
            var nova = !p;
            var idDesc = uid('perg_desc');
            p = p || { id: 0, uuid: '', nome: '', tipo: 'texto', obrigatoria: false, descricao: '', opcoes: [], config: { itemtype: '', multiplo: false, data_atual: false }, padrao: '', visibilidade: null, validacao: null };
            var estadoOpcoes = (p.opcoes || []).map(function (o) { return { k: o.k, t: o.t }; });
            if (!estadoOpcoes.length) { estadoOpcoes = [{ k: '', t: '' }, { k: '', t: '' }]; }

            var grupos = {};
            tipos().forEach(function (t) { (grupos[t.grupo] = grupos[t.grupo] || []).push(t); });
            var selTipo = '<select class="' + P + 'select" data-f="tipo">';
            if (p.tipo === '') { selTipo += '<option value="" selected>' + esc(p.tipo_label || 'Tipo de outro plugin') + ' (nao editavel aqui)</option>'; }
            Object.keys(grupos).forEach(function (g) {
                selTipo += '<optgroup label="' + esc(g) + '">' + grupos[g].map(function (t) {
                    return '<option value="' + t.slug + '"' + (t.slug === p.tipo ? ' selected' : '') + '>' + esc(t.label) + '</option>';
                }).join('') + '</optgroup>';
            });
            selTipo += '</select>';

            ed.innerHTML = '<div class="' + P + 'ed-grade">'
                + '<div class="' + P + 'campo"><label>Pergunta <span class="obrig">*</span></label><input type="text" class="' + P + 'input" data-f="nome" value="' + esc(p.nome) + '"></div>'
                + '<div class="' + P + 'campo"><label>Tipo de resposta</label>' + selTipo + '</div>'
                + '</div>'
                + '<div class="' + P + 'ed-switches">' + switchHtml('obrigatoria', 'Resposta obrigatoria', p.obrigatoria) + '<span data-sw-extra></span></div>'
                + '<div data-tipo-config></div>'
                + richSanfona(idDesc, 'Descricao / ajuda (aparece abaixo da pergunta)', p.descricao)
                + '<div class="' + P + 'campo" data-padrao-wrap><label>Valor padrao</label><div data-padrao></div></div>'
                + '<div class="' + P + 'campo" data-val-wrap><label><i class="ti ti-checks"></i> Validacao da resposta</label><div data-val></div></div>'
                + '<div class="' + P + 'campo" data-vis-wrap><label><i class="ti ti-eye-check"></i> Visibilidade da pergunta</label><div data-vis></div></div>'
                + botoesEditor();

            var selT = ed.querySelector('[data-f="tipo"]');
            var cbVis = null, cbVal = null;

            function tipoAtual() { return tipoDef(selT.value) || { slug: '', opcoes: false, dt: false, multiplo: false, itemtype: '', padrao: 'nenhum', valor: 'nenhum' }; }

            function desenharConfig() {
                var t = tipoAtual();
                var area = ed.querySelector('[data-tipo-config]');
                var extra = ed.querySelector('[data-sw-extra]');
                extra.innerHTML = (t.multiplo ? switchHtml('multiplo', 'Permitir varias respostas', p.config.multiplo) : '')
                    + (t.dt ? switchHtml('data_atual', 'Preencher com o momento atual', p.config.data_atual) : '');
                var h2 = '';
                if (t.opcoes) {
                    h2 += '<div class="' + P + 'campo"><label>Opcoes</label><div class="' + P + 'ed-opcoes" data-opcoes>';
                    estadoOpcoes.forEach(function (o, i) {
                        h2 += '<div class="' + P + 'ed-opcao" data-o="' + i + '"><i class="ti ti-grip-vertical ' + P + 'ed-alca-opcao"></i>'
                            + '<input type="text" class="' + P + 'input" data-o-t value="' + esc(o.t) + '" placeholder="Opcao ' + (i + 1) + '">'
                            + '<button type="button" class="' + P + 'btn-icone" data-o-rem title="Remover opcao"><i class="ti ti-x"></i></button></div>';
                    });
                    h2 += '</div><button type="button" class="' + P + 'btn ' + P + 'btn-mini" data-o-add><i class="ti ti-plus"></i> Opcao</button>'
                        + '<p class="' + P + 'pn-ajuda"><i class="ti ti-info-circle"></i> Renomear uma opcao mantem as condicoes que dependem dela.</p></div>';
                }
                if (t.itemtype) {
                    var lista = ((CFG().estruturaItens || {})[t.itemtype]) || [];
                    var gruposI = {};
                    lista.forEach(function (it) { (gruposI[it.g || 'Outros'] = gruposI[it.g || 'Outros'] || []).push(it); });
                    h2 += '<div class="' + P + 'campo"><label>Tipo de item <span class="obrig">*</span></label><select class="' + P + 'select" data-f="itemtype"><option value="">Escolha...</option>';
                    Object.keys(gruposI).forEach(function (g) {
                        h2 += '<optgroup label="' + esc(g) + '">' + gruposI[g].map(function (it) { return '<option value="' + esc(it.v) + '"' + (it.v === p.config.itemtype ? ' selected' : '') + '>' + esc(it.t) + '</option>'; }).join('') + '</optgroup>';
                    });
                    h2 += '</select></div>';
                }
                area.innerHTML = h2;
                desenharPadrao();
                desenharValidacao();
            }

            function lerOpcoesDaTela() {
                var linhas = ed.querySelectorAll('[data-opcoes] [data-o]');
                if (!linhas.length) { return; }
                var novo = [];
                linhas.forEach(function (l) { var i = parseInt(l.getAttribute('data-o'), 10); novo.push({ k: (estadoOpcoes[i] || {}).k || '', t: l.querySelector('[data-o-t]').value }); });
                estadoOpcoes = novo;
            }

            function desenharPadrao() {
                var t = tipoAtual();
                var wrap = ed.querySelector('[data-padrao-wrap]');
                var area = ed.querySelector('[data-padrao]');
                var mesmo = t.slug === p.tipo;
                var v = mesmo ? p.padrao : '';
                wrap.style.display = (t.padrao === 'nenhum') ? 'none' : '';
                if (t.padrao === 'opcoes') {
                    lerOpcoesDaTela();
                    var marcadas = (Array.isArray(v) ? v : []).map(String);
                    var multi = (t.slug === 'multipla' || t.slug === 'caixas');
                    area.innerHTML = '<div class="' + P + 'ed-padrao-opcoes">' + estadoOpcoes.filter(function (o) { return o.t.trim() !== ''; }).map(function (o) {
                        var marc = o.k && marcadas.indexOf(String(o.k)) !== -1;
                        return '<label class="' + P + 'pn-check"><input type="' + (multi ? 'checkbox' : 'radio') + '" name="pd_' + p.id + '" data-pd-k="' + esc(o.k) + '" data-pd-t="' + esc(o.t) + '"' + (marc ? ' checked' : '') + '> <span>' + esc(o.t) + '</span></label>';
                    }).join('') + (multi ? '' : '<label class="' + P + 'pn-check"><input type="radio" name="pd_' + p.id + '" data-pd-k=""' + (marcadas.length ? '' : ' checked') + '> <span>(nenhum)</span></label>') + '</div>'
                        + '<p class="' + P + 'pn-ajuda"><i class="ti ti-info-circle"></i> Opcoes novas so podem ser marcadas como padrao depois de salvar a pergunta.</p>';
                } else if (t.padrao === 'atores') {
                    var at = (v && !Array.isArray(v)) ? v : { usuarios: [], grupos: [] };
                    var idU = uid('pdu'), idG = uid('pdg');
                    area.innerHTML = '<div class="' + P + 'pn-duas-colunas"><div><span class="' + P + 'pn-sub"><i class="ti ti-user"></i> Usuarios</span>' + U().multiBusca(idU, [], []) + '</div>'
                        + '<div><span class="' + P + 'pn-sub"><i class="ti ti-users"></i> Grupos</span>' + U().multiBusca(idG, [], []) + '</div></div>';
                    U().carregarFonte('atores').then(function (a) {
                        var cu = document.getElementById(idU), cg = document.getElementById(idG);
                        if (cu) { U().preencherMultiBusca(cu, (a && a.usuarios) || [], at.usuarios || []); U().ativarMultiBusca(cu); cu.setAttribute('data-pd-atores', 'u'); }
                        if (cg) { U().preencherMultiBusca(cg, (a && a.grupos) || [], at.grupos || []); U().ativarMultiBusca(cg); cg.setAttribute('data-pd-atores', 'g'); }
                    });
                } else if (t.padrao === 'urgencia' || t.padrao === 'tipo') {
                    var lst = t.padrao === 'urgencia' ? URGENCIAS : TIPOS_CHAMADO;
                    area.innerHTML = '<select class="' + P + 'select" data-pd-valor><option value="">(padrao do GLPI)</option>' + lst.map(function (o) { return '<option value="' + o.v + '"' + (String(v) === o.v ? ' selected' : '') + '>' + esc(o.t) + '</option>'; }).join('') + '</select>';
                } else if (t.padrao !== 'nenhum') {
                    var tp = { numero: 'number', data: 'date', hora: 'time', datahora: 'datetime-local' }[t.padrao] || 'text';
                    var valor = String(v || '');
                    if (tp === 'datetime-local') { valor = valor.replace(' ', 'T').slice(0, 16); }
                    area.innerHTML = '<input type="' + tp + '" class="' + P + 'input" data-pd-valor value="' + esc(valor) + '">';
                }
            }

            function desenharValidacao() {
                var t = tipoAtual();
                var wrap = ed.querySelector('[data-val-wrap]');
                var area = ed.querySelector('[data-val]');
                var temValidacao = CFG().temValidacao;
                var validavel = ['texto', 'numero'].indexOf(t.valor) !== -1;
                cbVal = null;
                if (!temValidacao || !validavel) { wrap.style.display = 'none'; return; }
                // Pergunta nova ou de tipo trocado: os operadores vem do tipo ja gravado
                if (nova || t.slug !== p.tipo) {
                    wrap.style.display = '';
                    area.innerHTML = '<p class="' + P + 'pn-ajuda"><i class="ti ti-info-circle"></i> Salve a pergunta para configurar a validacao.</p>';
                    return;
                }
                var origem = est.origens.filter(function (o) { return o.uuid === p.uuid; })[0];
                var opsVal = origem ? origem.operadores.filter(function (o) { return ['visible', 'not_visible', 'empty', 'not_empty'].indexOf(o.slug) === -1; }) : [];
                if (!opsVal.length) { wrap.style.display = 'none'; return; }
                wrap.style.display = '';
                cbVal = construtorCondicoes({ regra: p.validacao, tipo: 'validacao', operadoresValidacao: opsVal, valorValidacao: t.valor === 'numero' ? 'numero' : 'texto' });
                area.innerHTML = '';
                area.appendChild(cbVal.el);
            }

            function desenharVisibilidade() {
                var wrap = ed.querySelector('[data-vis-wrap]');
                var area = ed.querySelector('[data-vis]');
                if (nova) {
                    area.innerHTML = '<p class="' + P + 'pn-ajuda"><i class="ti ti-info-circle"></i> Depois de criar a pergunta, a visibilidade condicional fica disponivel aqui.</p>';
                    return;
                }
                cbVis = construtorCondicoes({ regra: p.visibilidade, origens: est.origens, excluir: p.uuid, tipo: 'visibilidade' });
                area.innerHTML = '';
                area.appendChild(cbVis.el);
                wrap.style.display = '';
            }

            selT.addEventListener('change', function () {
                lerOpcoesDaTela();
                desenharConfig();
            });
            ed.addEventListener('click', function (e) {
                if (e.target.closest('[data-o-add]')) {
                    lerOpcoesDaTela(); estadoOpcoes.push({ k: '', t: '' }); desenharConfig();
                    var ultimos = ed.querySelectorAll('[data-o-t]'); if (ultimos.length) { ultimos[ultimos.length - 1].focus(); }
                } else if (e.target.closest('[data-o-rem]')) {
                    lerOpcoesDaTela();
                    estadoOpcoes.splice(parseInt(e.target.closest('[data-o]').getAttribute('data-o'), 10), 1);
                    desenharConfig();
                }
            });
            ed.addEventListener('change', function (e) {
                if (e.target.hasAttribute('data-o-t')) { lerOpcoesDaTela(); desenharPadrao(); }
            });
            ed.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && e.target.hasAttribute('data-o-t')) {
                    e.preventDefault(); lerOpcoesDaTela(); estadoOpcoes.push({ k: '', t: '' }); desenharConfig();
                    var ultimos = ed.querySelectorAll('[data-o-t]'); if (ultimos.length) { ultimos[ultimos.length - 1].focus(); }
                }
            });
            desenharConfig();
            desenharVisibilidade();
            if (window.Sortable) {
                ed.addEventListener('focusin', function () {
                    var lista = ed.querySelector('[data-opcoes]');
                    if (lista && !lista._sortable) {
                        lista._sortable = window.Sortable.create(lista, { handle: '.' + P + 'ed-alca-opcao', animation: 120, onEnd: function () {
                            var novo = [];
                            lista.querySelectorAll('[data-o]').forEach(function (l) { var i = parseInt(l.getAttribute('data-o'), 10); novo.push({ k: (estadoOpcoes[i] || {}).k || '', t: l.querySelector('[data-o-t]').value }); });
                            estadoOpcoes = novo; desenharConfig();
                        } });
                    }
                }, { once: false });
            }

            ligarBotoes(ed, function () {
                var t = tipoAtual();
                if (!t.slug) { U().toast('Este tipo de pergunta vem de outro plugin: edite pelo editor nativo do GLPI.', false); return Promise.resolve(false); }
                var nome = ed.querySelector('[data-f="nome"]').value.trim();
                if (!nome) { U().toast('Informe o texto da pergunta.', false); return Promise.resolve(false); }
                lerOpcoesDaTela();
                var dados = {
                    id: p.id, secao: secaoId, nome: nome, tipo: t.slug,
                    obrigatoria: ed.querySelector('[data-sw="obrigatoria"]').checked ? 1 : 0,
                    descricao: lerRich(idDesc),
                    opcoes: t.opcoes ? estadoOpcoes.filter(function (o) { return o.t.trim() !== ''; }) : [],
                    config: {
                        itemtype: (ed.querySelector('[data-f="itemtype"]') || {}).value || '',
                        multiplo: !!(ed.querySelector('[data-sw="multiplo"]') || {}).checked,
                        data_atual: !!(ed.querySelector('[data-sw="data_atual"]') || {}).checked
                    }
                };
                if (t.opcoes && !dados.opcoes.length) { U().toast('Informe ao menos uma opcao.', false); return Promise.resolve(false); }
                if (t.itemtype && !dados.config.itemtype) { U().toast('Escolha o tipo de item.', false); return Promise.resolve(false); }
                // valor padrao
                if (t.padrao === 'opcoes') {
                    dados.padrao = [];
                    ed.querySelectorAll('[data-pd-k]:checked').forEach(function (i) { if (i.getAttribute('data-pd-k')) { dados.padrao.push(i.getAttribute('data-pd-k')); } });
                } else if (t.padrao === 'atores') {
                    var cu = ed.querySelector('[data-pd-atores="u"]'), cg = ed.querySelector('[data-pd-atores="g"]');
                    dados.padrao = { usuarios: cu ? U().coletarMultiBusca(cu) : [], grupos: cg ? U().coletarMultiBusca(cg) : [] };
                } else if (t.padrao !== 'nenhum') {
                    var pv = ed.querySelector('[data-pd-valor]');
                    dados.padrao = pv ? (t.padrao === 'datahora' ? pv.value.replace('T', ' ') : pv.value) : '';
                }
                if (cbVis && !cbVis.completo()) { U().toast('Complete ou remova as condicoes de visibilidade incompletas.', false); return Promise.resolve(false); }
                if (cbVal && !cbVal.completo()) { U().toast('Complete ou remova as regras de validacao incompletas.', false); return Promise.resolve(false); }

                return U().ajax('pergunta_salvar', { dados_json: JSON.stringify(dados) }).then(function (r) {
                    if (!r || !r.success) { U().toast((r && r.message) || 'Falha.', false); return false; }
                    var id = r.id || p.id;
                    var passos = [];
                    if (cbVis && !r.tipo_mudou) { passos.push(salvarRegra('pergunta', id, cbVis.ler())); }
                    if (cbVal && !r.tipo_mudou) { var vl = cbVal.ler(); passos.push(U().ajax('validacao_salvar', { pergunta: id, estrategia: vl.estrategia, condicoes_json: JSON.stringify(vl.condicoes) })); }
                    return Promise.all(passos).then(function (rs) {
                        var falha = rs.filter(function (x) { return !x || !x.success; })[0];
                        if (falha) { U().toast('Pergunta salva, mas: ' + ((falha && falha.message) || 'regra nao salva'), false); }
                        else { U().toast(r.message + (r.tipo_mudou ? ' Revise a visibilidade e a validacao para o novo tipo.' : ''), true); }
                        return nova || r.tipo_mudou ? 'pergunta:' + id : true;
                    });
                });
            }, nova ? '' : 'pergunta:' + p.id);
        }

        // ----- botoes comuns dos editores -----
        function botoesEditor() {
            if (!podeEditar) { return '<div class="' + P + 'ed-rodape"><button type="button" class="' + P + 'btn" data-ed-fechar>Fechar</button></div>'; }
            return '<div class="' + P + 'ed-rodape"><button type="button" class="' + P + 'btn" data-ed-fechar>Cancelar</button>'
                + '<button type="button" class="' + P + 'btn ' + P + 'btn-primario" data-ed-salvar><i class="ti ti-device-floppy"></i> Salvar</button></div>';
        }
        function ligarBotoes(ed, salvar, chaveDepois) {
            ed.querySelector('[data-ed-fechar]').addEventListener('click', function (e) { e.stopPropagation(); fechar(); });
            var b = ed.querySelector('[data-ed-salvar]');
            if (!b) { return; }
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                b.disabled = true;
                salvar().then(function (ok) {
                    b.disabled = false;
                    if (ok) { depoisDeSalvar(typeof ok === 'string' ? ok : chaveDepois); }
                }).catch(function () { b.disabled = false; U().toast('Falha de comunicacao.', false); });
            });
        }

        // ----- acoes da lista (um unico ouvinte por caixa, trocado a cada montagem) -----
        if (box._edClique) { box.removeEventListener('click', box._edClique); }
        box._edClique = function (e) {
            var acao = e.target.closest('[data-ed]');
            if (acao && box.contains(acao)) {
                e.stopPropagation();
                var tipo = acao.getAttribute('data-ed');
                var id = parseInt(acao.getAttribute('data-id'), 10) || 0;
                var secao = parseInt(acao.getAttribute('data-secao'), 10) || 0;
                if (tipo === 'nova-secao') {
                    U().ajax('criar_secao', { form: formId, nome: 'Nova secao', descricao: '' }).then(function (r) {
                        U().toast((r && r.message) || 'Falha.', !!(r && r.success));
                        if (r && r.success) { depoisDeSalvar('secao:' + r.id); }
                    });
                } else if (tipo === 'nova-pergunta' || tipo === 'novo-comentario') {
                    var alvoEd = box.querySelector('[data-editor="secao:' + secao + '"]');
                    fecharEditores();
                    alvoEd.classList.add('aberto');
                    if (tipo === 'nova-pergunta') { editorPergunta(alvoEd, null, secao); } else { editorComentario(alvoEd, null, secao); }
                    var foco = alvoEd.querySelector('[data-f="nome"]'); if (foco) { foco.focus(); }
                } else if (tipo === 'excluir-secao') {
                    U().confirmar('Excluir a secao e todas as perguntas e blocos dela?', function () {
                        U().ajax('excluir_secao', { id: id }).then(function (r) { U().toast((r && r.message) || 'Falha.', !!(r && r.success)); if (r && r.success) { depoisDeSalvar(''); } });
                    });
                } else if (tipo === 'excluir-pergunta') {
                    U().confirmar('Excluir esta pergunta? As condicoes que dependem dela deixam de funcionar.', function () {
                        U().ajax('excluir_pergunta', { id: id }).then(function (r) { U().toast((r && r.message) || 'Falha.', !!(r && r.success)); if (r && r.success) { depoisDeSalvar(''); } });
                    });
                } else if (tipo === 'excluir-comentario') {
                    U().confirmar('Excluir este bloco de texto?', function () {
                        U().ajax('comentario_excluir', { id: id }).then(function (r) { U().toast((r && r.message) || 'Falha.', !!(r && r.success)); if (r && r.success) { depoisDeSalvar(''); } });
                    });
                } else if (tipo === 'duplicar-pergunta') {
                    U().ajax('pergunta_duplicar', { id: id }).then(function (r) { U().toast((r && r.message) || 'Falha.', !!(r && r.success)); if (r && r.success) { depoisDeSalvar('pergunta:' + r.id); } });
                }
                return;
            }
            var abrir = e.target.closest('[data-ed-abrir]');
            if (abrir && box.contains(abrir) && !e.target.closest('.' + P + 'ed-alca, .' + P + 'ed-alca-secao')) {
                var chave = abrir.getAttribute('data-ed-abrir');
                if (box.getAttribute('data-ed-aberto') === chave) { fechar(); } else { abrirEditor(chave); }
            }
        };
        box.addEventListener('click', box._edClique);
    }

    function linhaBloco(b, podeEditar) {
        var h = '';
        if (b.bloco === 'comentario') {
            var cc = resumoRegra(b.visibilidade);
            h += '<div class="' + P + 'ed-bloco ' + P + 'ed-bloco-texto" data-bloco="comentario:' + b.id + '" data-ed-abrir="comentario:' + b.id + '">';
            if (podeEditar) { h += '<i class="ti ti-grip-vertical ' + P + 'ed-alca"></i>'; }
            h += '<i class="ti ti-text-caption ' + P + 'ed-bloco-ic"></i><span class="' + P + 'ed-bloco-nome">' + esc(b.nome || 'Bloco de texto') + '</span>';
            if (cc) { h += '<span class="' + P + 'ed-badge cond"><i class="ti ti-eye-check"></i> ' + esc(cc) + '</span>'; }
            h += '<span class="' + P + 'ed-bloco-tipo">Bloco de texto</span>';
            if (podeEditar) { h += '<span class="' + P + 'ed-acoes"><button type="button" class="' + P + 'btn-icone" data-ed="excluir-comentario" data-id="' + b.id + '" title="Excluir"><i class="ti ti-trash"></i></button></span>'; }
            h += '</div><div class="' + P + 'ed-editor" data-editor="comentario:' + b.id + '"></div>';
            return h;
        }
        var t = tipoDef(b.tipo);
        var cond = resumoRegra(b.visibilidade);
        var val = b.validacao && b.validacao.estrategia !== 'sem' && (b.validacao.condicoes || []).length;
        var temPadrao = Array.isArray(b.padrao) ? b.padrao.length : (b.padrao && typeof b.padrao === 'object' ? ((b.padrao.usuarios || []).length + (b.padrao.grupos || []).length) : String(b.padrao || '') !== '');
        h += '<div class="' + P + 'ed-bloco" data-bloco="pergunta:' + b.id + '" data-ed-abrir="pergunta:' + b.id + '">';
        if (podeEditar) { h += '<i class="ti ti-grip-vertical ' + P + 'ed-alca"></i>'; }
        h += '<i class="' + (t ? (ICONE_TIPO[t.grupo] || 'ti ti-help-circle') : 'ti ti-puzzle') + ' ' + P + 'ed-bloco-ic"></i>';
        h += '<span class="' + P + 'ed-bloco-nome">' + esc(b.nome) + (b.obrigatoria ? '<span class="' + P + 'pergunta-obrig">*</span>' : '') + '</span>';
        if (cond) { h += '<span class="' + P + 'ed-badge cond"><i class="ti ti-eye-check"></i> ' + esc(cond) + '</span>'; }
        if (val) { h += '<span class="' + P + 'ed-badge"><i class="ti ti-checks"></i> validacao</span>'; }
        if (temPadrao) { h += '<span class="' + P + 'ed-badge"><i class="ti ti-pencil-check"></i> padrao</span>'; }
        if ((b.opcoes || []).length) { h += '<span class="' + P + 'ed-badge">' + b.opcoes.length + ' opcoes</span>'; }
        h += '<span class="' + P + 'ed-bloco-tipo">' + esc(b.tipo_label) + (b.config && b.config.multiplo ? ' (varios)' : '') + '</span>';
        if (podeEditar) {
            h += '<span class="' + P + 'ed-acoes">'
                + '<button type="button" class="' + P + 'btn-icone" data-ed="duplicar-pergunta" data-id="' + b.id + '" title="Duplicar"><i class="ti ti-copy"></i></button>'
                + '<button type="button" class="' + P + 'btn-icone" data-ed="excluir-pergunta" data-id="' + b.id + '" title="Excluir"><i class="ti ti-trash"></i></button></span>';
        }
        h += '</div><div class="' + P + 'ed-editor" data-editor="pergunta:' + b.id + '"></div>';
        return h;
    }

    // Arrastar secoes e blocos (inclusive entre secoes)
    function ligarArrastar(box, est) {
        if (!CFG().podeEditar || !window.Sortable) { return; }
        var formId = (est.form || {}).id;
        var cont = box;
        var anterior = window.Sortable.get ? window.Sortable.get(cont) : null;
        if (anterior) { anterior.destroy(); }
        window.Sortable.create(cont, {
            handle: '.' + P + 'ed-alca-secao', draggable: '.' + P + 'ed-secao:not(.' + P + 'ed-enviar)', animation: 150,
            onEnd: function () {
                var ids = Array.prototype.map.call(cont.querySelectorAll('.' + P + 'ed-secao[data-secao]'), function (s) { return s.getAttribute('data-secao'); });
                U().ajax('reordenar_secoes', { form: formId, ids: ids }).then(function (r) { if (!r || !r.success) { U().toast((r && r.message) || 'Falha ao reordenar.', false); } });
            }
        });
        box.querySelectorAll('[data-blocos]').forEach(function (lista) {
            window.Sortable.create(lista, {
                group: 'catalogoeformularios-blocos-' + formId, handle: '.' + P + 'ed-alca', draggable: '.' + P + 'ed-bloco', animation: 150,
                onStart: function () { box.querySelectorAll('.' + P + 'ed-editor.aberto').forEach(function (ed) { removerRicosDentro(ed); ed.innerHTML = ''; ed.classList.remove('aberto'); }); },
                onEnd: function (evt) {
                    // O editor (div irmao) acompanha a linha arrastada
                    var ed = box.querySelector('[data-editor="' + evt.item.getAttribute('data-bloco') + '"]');
                    if (ed) { evt.item.parentNode.insertBefore(ed, evt.item.nextSibling); }
                    var salvar = function (l) {
                        var blocos = Array.prototype.map.call(l.querySelectorAll('.' + P + 'ed-bloco'), function (b) { return b.getAttribute('data-bloco'); });
                        return U().ajax('blocos_ordenar', { secao: l.getAttribute('data-blocos'), blocos: blocos });
                    };
                    salvar(evt.to).then(function (r) {
                        if (!r || !r.success) { U().toast((r && r.message) || 'Falha ao reordenar.', false); }
                        if (evt.from !== evt.to) { salvar(evt.from).then(function () { box.dispatchEvent(new CustomEvent('ed-recarregar')); }); }
                    });
                }
            });
        });
    }

    // -----------------------------------------------------------------
    // Destinos: condicao de criacao
    // -----------------------------------------------------------------
    function montarDestinos(box, formId, est, recarregar) {
        var podeEditar = !!CFG().podeEditar;
        var h = '';
        if (!(est.destinos || []).length) {
            box.innerHTML = '<div class="' + P + 'pn-vazio-txt">Nenhum destino. Quando ninguem e criado, o envio do formulario nao gera chamado.</div>';
            return;
        }
        est.destinos.forEach(function (d) {
            var r = resumoRegra(d.criacao, true);
            h += '<div class="' + P + 'ed-destino" data-destino="' + d.id + '">'
                + '<div class="' + P + 'ed-destino-cab"><i class="ti ti-target"></i> <b>' + esc(d.nome) + '</b> <span class="' + P + 'ed-bloco-tipo">' + esc(d.tipo_label) + '</span>'
                + '<span class="' + P + 'ed-badge' + (r ? ' cond' : '') + '"><i class="ti ti-git-branch"></i> ' + esc(r || 'criado sempre') + '</span>'
                + (podeEditar ? '<button type="button" class="' + P + 'btn ' + P + 'btn-mini" data-dest-editar="' + d.id + '"><i class="ti ti-edit"></i> Condicao</button>' : '')
                + '</div><div class="' + P + 'ed-editor" data-dest-editor="' + d.id + '"></div></div>';
        });
        box.innerHTML = h;
        if (box._destClique) { box.removeEventListener('click', box._destClique); }
        box._destClique = function (e) {
            var b = e.target.closest('[data-dest-editar]');
            if (!b) { return; }
            var id = parseInt(b.getAttribute('data-dest-editar'), 10);
            var ed = box.querySelector('[data-dest-editor="' + id + '"]');
            if (ed.classList.contains('aberto')) { ed.innerHTML = ''; ed.classList.remove('aberto'); return; }
            var d = est.destinos.filter(function (x) { return x.id === id; })[0];
            var cb = construtorCondicoes({ regra: d.criacao, origens: est.origens, tipo: 'criacao' });
            ed.classList.add('aberto');
            ed.innerHTML = '<div data-cb></div><div class="' + P + 'ed-rodape"><button type="button" class="' + P + 'btn" data-x>Cancelar</button>'
                + '<button type="button" class="' + P + 'btn ' + P + 'btn-primario" data-s><i class="ti ti-device-floppy"></i> Salvar</button></div>';
            ed.querySelector('[data-cb]').appendChild(cb.el);
            ed.querySelector('[data-x]').addEventListener('click', function () { ed.innerHTML = ''; ed.classList.remove('aberto'); });
            ed.querySelector('[data-s]').addEventListener('click', function () {
                if (!cb.completo()) { U().toast('Complete ou remova as condicoes incompletas.', false); return; }
                salvarRegra('destino', id, cb.ler()).then(function (r) { U().toast((r && r.message) || 'Falha.', !!(r && r.success)); if (r && r.success) { recarregar(); } });
            });
        };
        box.addEventListener('click', box._destClique);
    }

    // -----------------------------------------------------------------
    // API usada pelo acordeao (catalogo.js)
    // -----------------------------------------------------------------
    function carregar(formId) {
        return U().ajax('estrutura', { form: formId }).then(function (r) {
            if (!r || !r.success) { throw new Error((r && r.message) || 'Falha ao carregar a estrutura.'); }
            return r.dados;
        });
    }

    window.catalogoeformulariosEditor = {
        /** Monta uma aba do acordeao: 'geral' | 'estrutura' | 'destinos'. */
        montar: function (aba, box, formId, aoMudar) {
            box.innerHTML = '<div class="' + P + 'pn-carregando"><i class="ti ti-loader"></i> Carregando...</div>';
            var recarregar = function () {
                carregar(formId).then(function (est) {
                    if (aba === 'geral') { montarGeral(box, formId, est, aoMudar); }
                    else if (aba === 'estrutura') { montarEstrutura(box, formId, est, recarregar); }
                    else { montarDestinos(box, formId, est, recarregar); }
                    if (aoMudar && aba !== 'geral') { aoMudar(est); }
                }).catch(function (e) {
                    box.innerHTML = '<div class="' + P + 'pn-vazio"><i class="ti ti-alert-triangle"></i> ' + esc(e.message) + '</div>';
                });
            };
            if (!box._edRecarregar) {
                box.addEventListener('ed-recarregar', function () { recarregar(); });
                box._edRecarregar = true;
            }
            recarregar();
        },
        limpar: function (el) { removerRicosDentro(el); }
    };
})();
