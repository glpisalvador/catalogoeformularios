# Histórico de versões

O arquivo para download de cada versão está em [Releases](https://github.com/glpisalvador/catalogoeformularios/releases).

## 3.0.4 — 2026-10-05

- A **entidade escolhida na aba Geral** passa a ser a entidade do chamado gerado. Todos os destinos usam a estratégia nativa "From form": ao salvar a aba Geral, ao criar ou importar formulário, ao criar destino e, uma única vez na instalação, nos formulários existentes.
- O campo "Cliente (entidade)" saiu da aba Chamado gerado para não haver duas regras concorrentes.
- Correção: gravar campos do destino (categoria ITIL, SLAs e entidade) não falha mais em destinos com ator específico (por exemplo, um grupo observador fixo).

## 3.0.3 — 2026-10-05

- A aba **Estrutura** foi incorporada à aba **Geral**: seções, perguntas, condições e botão Enviar ficam no mesmo lugar.
- Cada ator (requerentes, observadores e atribuído a) virou um **acordeão fechado** que mostra o resumo no título e abre a escolha de usuários e grupos.
- **Descrição, cabeçalho e ícone** ficam juntos num único acordeão fechado no fim da aba, antes do cartão "Estrutura e ações".

## 3.0.2 — 2026-10-05

- Aba Geral reorganizada: Nome, Entidade, **Categoria ITIL do chamado gerado** (nova, escolha rápida), Categoria do catálogo, descrição e cabeçalho fechados, **Atores do chamado** (ver e escolher rápido) e o ícone por último, na largura toda.
- **Exibição, Ativo, Subentidades e Fixado no topo** passaram para a barra de título do acordeão e salvam ao alterar.
- O salvamento da aba Geral passou a gravar só os campos enviados.

## 3.0.1 — 2026-10-05

- No acordeão, **"+ Pergunta"** passou a ser o botão em destaque (laranja). **"+ Seção"** ficou pequeno e cinza.
- **Descrições e cabeçalho** ficam num acordeão fechado em todos os lugares do formulário (aba Geral, pergunta, seção e modal de seção). O editor de texto rico só abre quando você expande o acordeão.
- **Entidade do formulário** pode ser alterada na aba Geral, entre as entidades ativas de quem edita.

## 3.0.0 — 2026-10-05

Primeira versão como Catálogo e Formulários (sucessor do "Catálogo de Serviços"), autor GLPI Salvador, GLPI 11 e 12.

- **Editor completo no acordeão do formulário**, em abas:
  - Geral;
  - Estrutura: seções, perguntas, blocos de texto, arrastar entre seções;
  - Chamado gerado: campos, SLAs, OLAs, atores, destinos e condição de criação;
  - Quem visualiza.
- **Ícone do formulário**, com o mesmo seletor das categorias, na criação, na edição e na lista.
- **Todos os tipos de pergunta nativos**, incluindo requerente, observador, atribuído, dispositivo do usuário e lista do GLPI. Antes, esses tipos viravam texto curto ao editar.
- **Opções com chave preservada**: renomear ou reordenar não quebra condições nem valor padrão.
- **Valor padrão**, **validação da resposta** e **visibilidade** de perguntas, seções, blocos de texto e botão Enviar, com os operadores vindos do próprio tipo de pergunta.
- Correções:
  - "vazio/preenchido" eram gravados com nomes que o GLPI 12 não reconhece;
  - "Item do GLPI" era criado sem o tipo de item.
- Entidade dos formulários novos agora é configuração (antes era fixa no código).
