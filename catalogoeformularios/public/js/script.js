/* ===== Catalogo de Servicos - JS da tela de configuracao ===== */

// ---------------------------------------------------------------------
// Multiselect customizado
// ---------------------------------------------------------------------

function catalogoeformulariosToggleMultiselect(uid) {
    var dropdown = document.getElementById('ms_dropdown_' + uid);
    if (!dropdown) {
        return;
    }
    var aberto = dropdown.style.display !== 'none';

    // Fecha todos os outros dropdowns antes.
    document.querySelectorAll('.catalogoeformularios-ms-dropdown').forEach(function (d) {
        d.style.display = 'none';
    });

    if (!aberto) {
        dropdown.style.display = 'block';
        var busca = dropdown.querySelector('.catalogoeformularios-ms-search');
        if (busca) {
            busca.focus();
        }
    }
}

function catalogoeformulariosFilterMultiselect(uid, termo) {
    termo = (termo || '').toLowerCase();
    var container = document.getElementById('ms_options_' + uid);
    if (!container) {
        return;
    }
    container.querySelectorAll('.catalogoeformularios-ms-option').forEach(function (opt) {
        var label = opt.getAttribute('data-label') || '';
        opt.style.display = (label.indexOf(termo) !== -1) ? 'flex' : 'none';
    });
}

function catalogoeformulariosToggleAllMultiselect(uid, marcar) {
    var container = document.getElementById('ms_options_' + uid);
    if (!container) {
        return;
    }
    // Aplica apenas nas opcoes visiveis (respeitando o filtro de busca).
    container.querySelectorAll('.catalogoeformularios-ms-option').forEach(function (opt) {
        if (opt.style.display === 'none') {
            return;
        }
        var cb = opt.querySelector('input[type="checkbox"]');
        if (cb) {
            cb.checked = marcar;
            opt.classList.toggle('selected', marcar);
        }
    });
    catalogoeformulariosReorderMultiselectOptions(uid);
    catalogoeformulariosUpdateMultiselectCount(uid);
}

function catalogoeformulariosHandleMultiselectChange(uid, checkbox) {
    var opt = checkbox.closest('.catalogoeformularios-ms-option');
    if (opt) {
        opt.classList.toggle('selected', checkbox.checked);
    }

    // Limpa o campo de busca para a proxima pesquisa e reexibe todas as opcoes.
    var dropdown = document.getElementById('ms_dropdown_' + uid);
    if (dropdown) {
        var busca = dropdown.querySelector('.catalogoeformularios-ms-search');
        if (busca) {
            busca.value = '';
            catalogoeformulariosFilterMultiselect(uid, '');
        }
    }

    catalogoeformulariosReorderMultiselectOptions(uid);
    catalogoeformulariosUpdateMultiselectCount(uid);
    catalogoeformulariosUpdateSelectAll(uid);
}

function catalogoeformulariosReorderMultiselectOptions(uid) {
    var container = document.getElementById('ms_options_' + uid);
    if (!container) {
        return;
    }
    var opcoes = Array.prototype.slice.call(container.querySelectorAll('.catalogoeformularios-ms-option'));
    opcoes.sort(function (a, b) {
        var ca = a.querySelector('input[type="checkbox"]').checked ? 0 : 1;
        var cb = b.querySelector('input[type="checkbox"]').checked ? 0 : 1;
        if (ca !== cb) {
            return ca - cb; // selecionados primeiro
        }
        var la = a.getAttribute('data-label') || '';
        var lb = b.getAttribute('data-label') || '';
        return la.localeCompare(lb);
    });
    opcoes.forEach(function (opt) {
        container.appendChild(opt);
    });
}

function catalogoeformulariosUpdateMultiselectCount(uid) {
    var container = document.getElementById('ms_options_' + uid);
    if (!container) {
        return;
    }
    var todas = container.querySelectorAll('.catalogoeformularios-ms-option input[type="checkbox"]');
    var marcadas = container.querySelectorAll('.catalogoeformularios-ms-option input[type="checkbox"]:checked');

    var label = document.getElementById('ms_label_' + uid);
    if (label) {
        label.textContent = marcadas.length > 0
            ? (marcadas.length + ' selecionado(s)')
            : 'Nenhum selecionado';
    }

    var count = document.getElementById('ms_count_' + uid);
    if (count) {
        count.textContent = marcadas.length + ' de ' + todas.length;
    }
}

function catalogoeformulariosUpdateSelectAll(uid) {
    var dropdown = document.getElementById('ms_dropdown_' + uid);
    var container = document.getElementById('ms_options_' + uid);
    if (!dropdown || !container) {
        return;
    }
    var selectAll = dropdown.querySelector('.catalogoeformularios-ms-selectall input[type="checkbox"]');
    if (!selectAll) {
        return;
    }
    var todas = container.querySelectorAll('.catalogoeformularios-ms-option input[type="checkbox"]').length;
    var marcadas = container.querySelectorAll('.catalogoeformularios-ms-option input[type="checkbox"]:checked').length;

    selectAll.checked = (todas > 0 && marcadas === todas);
    selectAll.indeterminate = (marcadas > 0 && marcadas < todas);
}

// Fecha os dropdowns ao clicar fora deles.
document.addEventListener('click', function (e) {
    document.querySelectorAll('.catalogoeformularios-multiselect').forEach(function (ms) {
        if (!ms.contains(e.target)) {
            var dropdown = ms.querySelector('.catalogoeformularios-ms-dropdown');
            if (dropdown) {
                dropdown.style.display = 'none';
            }
        }
    });
});

// ---------------------------------------------------------------------
// Acoes AJAX (Categorias ITIL)
// ---------------------------------------------------------------------

function catalogoeformulariosAjax(action) {
    var cfg = window.catalogoeformulariosConfig || {};
    var fd = new FormData();
    fd.append('action', action);
    // o token so vem preenchido no GLPI 11; no GLPI 12 o CSRF e validado por cabecalho
    if (cfg.csrf) { fd.append('_glpi_csrf_token', cfg.csrf); }

    return fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
        .then(function (r) { return r.text(); })
        .then(function (texto) {
            var data;
            try {
                data = JSON.parse(texto);
            } catch (e) {
                // Caso haja warnings antes do JSON, extrai o objeto do final da string.
                var m = texto.match(/\{[\s\S]*\}\s*$/);
                if (m) {
                    data = JSON.parse(m[0]);
                } else {
                    throw e;
                }
            }
            if (data && data.new_token) {
                window.catalogoeformulariosConfig.csrf = data.new_token;
            }
            return data;
        });
}

document.addEventListener('DOMContentLoaded', function () {

    var btnContar = document.getElementById('catalogoeformularios-btn-contar');
    if (btnContar) {
        btnContar.addEventListener('click', function () {
            var res = document.getElementById('catalogoeformularios-resultado-contar');
            btnContar.disabled = true;
            res.className = 'catalogoeformularios-resultado';
            res.innerHTML = '<i class="ti ti-loader"></i> Consultando...';

            catalogoeformulariosAjax('contar_itil').then(function (data) {
                btnContar.disabled = false;
                res.className = 'catalogoeformularios-resultado ' + (data.success ? 'sucesso' : 'erro');
                res.textContent = data.message || 'Sem resposta.';
            }).catch(function () {
                btnContar.disabled = false;
                res.className = 'catalogoeformularios-resultado erro';
                res.textContent = 'Falha na requisicao.';
            });
        });
    }

    var btnCopiar = document.getElementById('catalogoeformularios-btn-copiar');
    if (btnCopiar) {
        btnCopiar.addEventListener('click', function () {
            var res = document.getElementById('catalogoeformularios-resultado-copiar');
            btnCopiar.disabled = true;
            res.className = 'catalogoeformularios-resultado';
            res.innerHTML = '<i class="ti ti-loader"></i> Copiando...';

            catalogoeformulariosAjax('copiar_categorias').then(function (data) {
                btnCopiar.disabled = false;
                res.className = 'catalogoeformularios-resultado ' + (data.success ? 'sucesso' : 'erro');
                res.textContent = data.message || 'Sem resposta.';
            }).catch(function () {
                btnCopiar.disabled = false;
                res.className = 'catalogoeformularios-resultado erro';
                res.textContent = 'Falha na requisicao.';
            });
        });
    }
});