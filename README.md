# Catálogo e Formulários para GLPI

> Autor: **GLPI Salvador** · Licença: **GPLv3+** · Compatível com GLPI **11.0.0 a 12.x**

Gerenciador do **catálogo de serviços** e dos **formulários nativos** do GLPI 11/12 numa única tela, em *Ferramentas → Catálogo e Formulários*. Tudo é gravado nas tabelas nativas do GLPI (`glpi_forms_*`): o que você monta aqui aparece igual no catálogo nativo e no editor nativo. Sucessor do plugin "Catálogo de Serviços".

## O que o plugin faz

### Catálogo
- **Árvore de categorias** com busca, arrastar e soltar entre categorias, contagem de formulários e popularidade.
- **Categorias** com **ícone** (as ilustrações nativas do GLPI, com busca), criar em lote a partir de entidades ou grupos, duplicar com toda a estrutura, mover e excluir.
- **Formulários** com **ícone**, ativo ou inativo, fixado no topo e duplicação completa.
- **Copiar categorias ITIL** para o catálogo e **criar formulários a partir de categorias ITIL**.
- **Matriz** comparando os campos do chamado de todos os formulários de uma categoria, com edição em lote.
- **Prévia ao vivo** do catálogo nativo na mesma tela.
- **Exportar e importar** o catálogo em arquivo (categorias, formulários e dependências), com mapeamento dos itens no destino.

### Editor completo no acordeão do formulário
Clique num formulário da lista e tudo dele se edita ali mesmo, em abas:

| Aba | O que tem |
|---|---|
| **Geral** | Nome, **ícone**, categoria, descrição e cabeçalho (texto rico), ativo, subentidades, fixado no topo, exibição (passo a passo ou página única). |
| **Estrutura** | Seções, perguntas e **blocos de texto**: criar, editar ali mesmo, duplicar, excluir e **arrastar** (também entre seções). |
| **Chamado gerado** | Categoria, **SLAs e OLAs** (atendimento e solução), tipo, urgência, status, origem, localização, modelo e entidade do chamado: fixos ou vindos de uma resposta. Atores (requerente, observador, atribuído), destinos e **quando cada destino é criado**. |
| **Quem visualiza** | Perfis, grupos e usuários (ou todos), restrição ligada ou não, link direto. |

**Perguntas** (todos os tipos nativos):
- texto curto, e-mail, número, texto longo;
- data, hora, data e hora (com "momento atual");
- lista, lista com várias escolhas, botões de opção, caixas de seleção;
- urgência, tipo do chamado;
- requerente, observador, atribuído;
- lista do GLPI (categoria, localização…), item do GLPI (ativo, chamado…), dispositivo do usuário;
- arquivo.

Cada tipo mostra as configurações que fazem sentido para ele: opções (reordenáveis, e renomear uma opção não quebra as condições), tipo de item, "permitir várias respostas", valor padrão, descrição de ajuda e obrigatoriedade.

**Condições** (o mesmo motor do GLPI):
- **visibilidade** de perguntas, seções, blocos de texto e do **botão Enviar**: sempre, visível se… ou oculto se…;
- **criação de cada destino**: sempre, criar se… ou não criar se…;
- **validação da resposta**: válida ou inválida se…, por exemplo expressão regular ou tamanho do texto.

Os operadores oferecidos vêm do próprio tipo de cada pergunta, então só aparecem combinações que o GLPI realmente avalia.

## Configuração e direitos

- Quem pode **ver** e quem pode **editar** o catálogo, por perfis e usuários. Administradores sempre podem.
- **Entidade** em que os formulários e as categorias novos são criados.
- Contagem e cópia das categorias ITIL para o catálogo.

## Requisitos extras

- A tela usa o **SortableJS** (MIT), carregado do cdnjs, para arrastar e soltar.
- Algumas opções dependem da versão do GLPI (blocos de texto, validação, condições do botão Enviar). Quando a versão não tem o recurso, a opção não aparece.

---

## Download e instalação

1. Baixe o arquivo `catalogoeformularios-X.Y.Z.zip` da **[última versão](../../releases/latest)**. Use o arquivo anexado à release, não o "Source code".
2. Descompacte dentro da pasta `plugins/` do GLPI. O resultado deve ser `plugins/catalogoeformularios/setup.php`.
3. Ajuste o dono dos arquivos para o usuário do servidor web, por exemplo:
   ```bash
   chown -R www-data:www-data /var/www/glpi/plugins/catalogoeformularios
   ```
4. No GLPI, vá em **Configurar → Plugins** e clique em **Instalar** e depois em **Ativar**. Pela linha de comando:
   ```bash
   php bin/console plugin:install catalogoeformularios -u <usuário administrador>
   php bin/console plugin:activate catalogoeformularios
   ```

A instalação cria as tabelas, as configurações padrão e as ações automáticas do plugin, e funciona num GLPI sem nada configurado antes.

### Atualização

Substitua a pasta `plugins/catalogoeformularios` pela versão nova e rode **Instalar** de novo, ou `php bin/console plugin:install catalogoeformularios -f`. Depois, ative o plugin. As tabelas e colunas novas são criadas sem perder os dados.

### Desinstalação

A desinstalação **não apaga as tabelas do plugin**: reinstalar recupera os dados.

## Versões

O histórico, com o que mudou em cada versão e o arquivo para download, está em **[Releases](../../releases)**. Cada versão entrou por um **[pull request](../../pulls?q=is%3Apr)**.

## Licença

Distribuído sob a **GNU General Public License v3.0 ou posterior**. Veja o arquivo [LICENSE](LICENSE).