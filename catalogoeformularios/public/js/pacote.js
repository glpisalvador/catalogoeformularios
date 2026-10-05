/*
 * Catalogo de Servicos - telas de exportacao e importacao do pacote.
 * Funcoes globais prefixadas com "cdspac" para nao colidir com o GLPI.
 */

/** Filtra as linhas de um container pelo atributo data-search. */
function cdspacFiltrar(campo, idContainer) {
    var container = document.getElementById(idContainer);
    if (!container) {
        return;
    }

    var termo  = (campo.value || '').toLowerCase().trim();
    var linhas = container.querySelectorAll('.cdspac-linha');

    for (var i = 0; i < linhas.length; i++) {
        var alvo = (linhas[i].getAttribute('data-search') || '');
        linhas[i].style.display = (termo === '' || alvo.indexOf(termo) !== -1) ? '' : 'none';
    }

    var blocos = container.querySelectorAll('.cdspac-bloco');
    for (var b = 0; b < blocos.length; b++) {
        var visiveis = 0;
        var itens    = blocos[b].querySelectorAll('.cdspac-linha');
        for (var j = 0; j < itens.length; j++) {
            if (itens[j].style.display !== 'none') {
                visiveis++;
            }
        }
        blocos[b].style.display = visiveis > 0 ? '' : 'none';
    }
}

/** Marca ou desmarca todas as caixas visiveis de um container. */
function cdspacMarcarTodos(idContainer, marcar) {
    var container = document.getElementById(idContainer);
    if (!container) {
        return;
    }

    var linhas = container.querySelectorAll('.cdspac-linha');
    for (var i = 0; i < linhas.length; i++) {
        if (linhas[i].style.display === 'none') {
            continue;
        }
        var caixa = linhas[i].querySelector('input[type="checkbox"]');
        if (caixa && !caixa.disabled) {
            caixa.checked = !!marcar;
        }
    }
}

/** Preenche o seletor de destino com os itens do tipo, uma unica vez. */
function cdspacCarregarAlvos(select, tipo) {
    if (select.getAttribute('data-pronto') === '1') {
        return;
    }

    var lista = (window.cdspacAlvos && window.cdspacAlvos[tipo]) ? window.cdspacAlvos[tipo] : [];
    var atual = parseInt(select.getAttribute('data-selecionado'), 10) || 0;
    var html  = '<option value="0">-- escolha um item deste GLPI --</option>';

    for (var i = 0; i < lista.length; i++) {
        var sel = (lista[i].id === atual) ? ' selected' : '';
        html += '<option value="' + lista[i].id + '"' + sel + '>' + cdspacEscapar(lista[i].txt) + '</option>';
    }

    select.innerHTML = html;
    select.setAttribute('data-pronto', '1');
}

/** Escapa texto antes de injetar como option. */
function cdspacEscapar(txt) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(txt == null ? '' : String(txt)));
    return d.innerHTML;
}

/** Mostra apenas os campos que a acao escolhida precisa. */
function cdspacAplicarEstado(bloco) {
    var selAcao = bloco.querySelector('.cdspac-sel-acao');
    var selAlvo = bloco.querySelector('.cdspac-sel-alvo');
    var inpNome = bloco.querySelector('.cdspac-inp-nome');
    if (!selAcao) {
        return;
    }

    var acao = selAcao.value;
    var tipo = bloco.getAttribute('data-tipo') || '';

    var precisaAlvo = (acao === 'apontar' || acao === 'substituir');
    var precisaNome = (acao === 'novo');

    if (selAlvo) {
        if (precisaAlvo) {
            cdspacCarregarAlvos(selAlvo, tipo);
        }
        selAlvo.style.display = precisaAlvo ? '' : 'none';
        selAlvo.disabled = !precisaAlvo;
    }

    if (inpNome) {
        inpNome.style.display = precisaNome ? '' : 'none';
        inpNome.disabled = !precisaNome;
    }

    bloco.setAttribute('data-acao', acao);
}

/** Handler do seletor de acao de uma linha. */
function cdspacTrocarAcao(select) {
    var bloco = select.closest('.cdspac-acao');
    if (bloco) {
        cdspacAplicarEstado(bloco);
    }
}

/** Aplica a mesma acao a todas as linhas visiveis do card ou bloco. */
function cdspacAplicarLote(select) {
    var acao = select.value;
    if (!acao) {
        return;
    }

    var escopo = select.closest('.cdspac-bloco') || select.closest('.cdspac-card');
    if (!escopo) {
        return;
    }

    var linhas = escopo.querySelectorAll('.cdspac-linha-item');
    for (var i = 0; i < linhas.length; i++) {
        if (linhas[i].style.display === 'none') {
            continue;
        }
        var bloco = linhas[i].querySelector('.cdspac-acao');
        if (!bloco) {
            continue;
        }
        var selAcao = bloco.querySelector('.cdspac-sel-acao');
        if (!selAcao) {
            continue;
        }
        var existe = false;
        for (var o = 0; o < selAcao.options.length; o++) {
            if (selAcao.options[o].value === acao) {
                existe = true;
                break;
            }
        }
        if (!existe) {
            continue;
        }
        selAcao.value = acao;
        cdspacAplicarEstado(bloco);
    }

    select.value = '';
}

/** Estado inicial de cada linha de acao. */
document.addEventListener('DOMContentLoaded', function () {
    var blocos = document.querySelectorAll('.cdspac-acao');
    for (var i = 0; i < blocos.length; i++) {
        cdspacAplicarEstado(blocos[i]);
    }
});

/**
 * Filtro de entidades nos dropdowns nativos: esconde as entidades filhas,
 * deixando visiveis apenas as entidades pai e as que nao possuem filho.
 */
(function () {
    if (typeof jQuery === 'undefined' || !window.cdspacEntidadesEsconder) {
        return;
    }

    var esconder = window.cdspacEntidadesEsconder || [];
    if (!esconder.length) {
        return;
    }

    function permitido(item) {
        var id = parseInt(item && item.id, 10);
        return isNaN(id) || esconder.indexOf(id) === -1;
    }

    jQuery.ajaxPrefilter(function (options) {
        if (!options.url || options.url.indexOf('getDropdownValue') === -1) {
            return;
        }

        var successOriginal = options.success;

        options.success = function (data) {
            try {
                if (data && data.results) {
                    data.results = data.results.filter(function (item) {
                        if (item && item.children) {
                            item.children = item.children.filter(permitido);
                            return item.children.length > 0;
                        }
                        return permitido(item);
                    });
                }
            } catch (e) {
                // Falha no filtro nao deve impedir o dropdown de funcionar.
            }

            if (typeof successOriginal === 'function') {
                successOriginal.apply(this, arguments);
            }
        };
    });
})();
